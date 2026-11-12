/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The annual report as a booklet (design proposal V7, November 2026): a PDF
 * for the board and the general meeting with a cover, the year in large
 * figures, the concerts as a timeline, and the lists of the report. Black
 * on white with greys, so it prints on any printer. Made in the browser
 * from the figures the page already shows; nothing new leaves the archive.
 *
 * Loaded on demand (pdf-lib is large): `await import('./report/build.ts')`.
 */

import type { PDFFont, PDFPage } from 'pdf-lib'

import { PDFDocument, rgb, StandardFonts } from 'pdf-lib'
import { fit, winAnsi, wrap } from '../songbook/build.ts'

export interface ReportBooklet {
	title: string
	/** above the title, e.g. the name of the choir */
	overline: string
	/** under the title, e.g. "As of 12 November 2026" */
	subtitle: string
	figuresHeading: string
	/** the year in large figures, at most six */
	figures: { value: string, label: string }[]
	concertsHeading: string
	concerts: { date: string, title: string, detail: string, sung: boolean }[]
	/** said instead of the timeline when there was no concert */
	noConcerts: string
	/** tables of two columns: rights, stock */
	tables: { heading: string, note: string, rows: { label: string, value: string }[] }[]
	/** lists: new accessions, licenses */
	lists: { heading: string, items: { title: string, detail: string }[], more: string }[]
}

const A4: [number, number] = [595.28, 841.89]
const MARGIN = 62
const WIDTH = A4[0] - 2 * MARGIN
const INK = rgb(0, 0, 0)
const GREY = rgb(0.32, 0.32, 0.32)
const LIGHT = rgb(0.9, 0.9, 0.9)

interface Fonts {
	regular: PDFFont
	bold: PDFFont
	italic: PDFFont
}

interface Flow {
	document: PDFDocument
	pages: PDFPage[]
	page: PDFPage
	y: number
}

/**
 * A new page.
 *
 * @param flow - the pages
 */
function newPage(flow: Flow): void {
	flow.page = flow.document.addPage(A4)
	flow.pages.push(flow.page)
	flow.y = A4[1] - MARGIN
}

/**
 * Room for a block, on a new page when needed.
 *
 * @param flow - the pages
 * @param height - points the block needs
 */
function need(flow: Flow, height: number): void {
	if (flow.y - height < MARGIN) {
		newPage(flow)
	}
}

/**
 * A heading of a section with a rule below.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param text - the heading
 */
function heading(flow: Flow, fonts: Fonts, text: string): void {
	need(flow, 90)
	flow.y -= 10
	flow.page.drawText(winAnsi(text), { x: MARGIN, y: flow.y, size: 17, font: fonts.bold, color: INK })
	flow.y -= 9
	flow.page.drawLine({ start: { x: MARGIN, y: flow.y }, end: { x: MARGIN + WIDTH, y: flow.y }, thickness: 0.8, color: INK })
	flow.y -= 24
}

/**
 * The cover.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param report - the report
 */
function cover(flow: Flow, fonts: Fonts, report: ReportBooklet): void {
	newPage(flow)
	const [width, height] = A4
	const centred = (text: string, font: PDFFont, size: number, y: number, color = INK) => {
		flow.page.drawText(text, { x: (width - font.widthOfTextAtSize(text, size)) / 2, y, size, font, color })
	}
	// a broad grey band behind the title
	flow.page.drawRectangle({ x: 0, y: height * 0.44, width, height: height * 0.24, color: LIGHT })
	if (report.overline.trim() !== '') {
		centred(winAnsi(report.overline.trim()).toUpperCase(), fonts.regular, 13, height * 0.72)
	}
	const lines = wrap(winAnsi(report.title), fonts.bold, 44, WIDTH, 2)
	let y = height * 0.56 + (lines.length - 1) * 26
	for (const line of lines) {
		centred(line, fonts.bold, 44, y)
		y -= 52
	}
	centred(fit(winAnsi(report.subtitle), fonts.italic, 14, WIDTH), fonts.italic, 14, height * 0.47, GREY)
}

/**
 * The year in large figures: tiles of three in a row.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param report - the report
 */
function figures(flow: Flow, fonts: Fonts, report: ReportBooklet): void {
	newPage(flow)
	heading(flow, fonts, report.figuresHeading)
	const gap = 14
	const tile = (WIDTH - 2 * gap) / 3
	const tileHeight = 104
	report.figures.slice(0, 6).forEach((figure, index) => {
		const column = index % 3
		if (column === 0) {
			need(flow, tileHeight + gap)
		}
		const x = MARGIN + column * (tile + gap)
		const top = flow.y
		flow.page.drawRectangle({ x, y: top - tileHeight, width: tile, height: tileHeight, borderColor: INK, borderWidth: 0.8 })
		const value = winAnsi(figure.value)
		const size = fonts.bold.widthOfTextAtSize(value, 46) > tile - 20 ? 32 : 46
		flow.page.drawText(value, { x: x + 12, y: top - 54, size, font: fonts.bold, color: INK })
		wrap(winAnsi(figure.label), fonts.regular, 11, tile - 24, 2).forEach((line, row) => {
			flow.page.drawText(line, { x: x + 12, y: top - 74 - row * 13, size: 11, font: fonts.regular, color: GREY })
		})
		if (column === 2 || index === Math.min(report.figures.length, 6) - 1) {
			flow.y -= tileHeight + gap
		}
	})
	flow.y -= 10
}

/**
 * The concerts along a line: a filled dot for a concert sung, a ring for a
 * planned one.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param report - the report
 */
function timeline(flow: Flow, fonts: Fonts, report: ReportBooklet): void {
	heading(flow, fonts, report.concertsHeading)
	if (report.concerts.length === 0) {
		flow.page.drawText(winAnsi(report.noConcerts), { x: MARGIN, y: flow.y, size: 11.5, font: fonts.italic, color: GREY })
		flow.y -= 28
		return
	}
	const lineX = MARGIN + 96
	for (const concert of report.concerts) {
		const titles = wrap(winAnsi(concert.title), fonts.bold, 12.5, WIDTH - 116, 2)
		const height = titles.length * 15 + (concert.detail ? 14 : 0) + 14
		need(flow, height)
		const top = flow.y
		flow.page.drawLine({ start: { x: lineX, y: top + 10 }, end: { x: lineX, y: top - height + 10 }, thickness: 1, color: GREY })
		flow.page.drawCircle({ x: lineX, y: top - 4, size: 4.5, color: concert.sung ? INK : rgb(1, 1, 1), borderColor: INK, borderWidth: 1.2 })
		const date = winAnsi(concert.date)
		flow.page.drawText(date, { x: lineX - 14 - fonts.regular.widthOfTextAtSize(date, 10.5), y: top - 8, size: 10.5, font: fonts.regular, color: INK })
		titles.forEach((line, row) => {
			flow.page.drawText(line, { x: lineX + 18, y: top - 8 - row * 15, size: 12.5, font: fonts.bold, color: INK })
		})
		if (concert.detail) {
			flow.page.drawText(fit(winAnsi(concert.detail), fonts.italic, 10.5, WIDTH - 116), { x: lineX + 18, y: top - 8 - titles.length * 15, size: 10.5, font: fonts.italic, color: GREY })
		}
		flow.y -= height
	}
	flow.y -= 14
}

/**
 * A table of labels and figures.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param table - heading, note and rows
 */
function table(flow: Flow, fonts: Fonts, table: ReportBooklet['tables'][number]): void {
	heading(flow, fonts, table.heading)
	if (table.note) {
		for (const line of wrap(winAnsi(table.note), fonts.italic, 10.5, WIDTH, 4)) {
			flow.page.drawText(line, { x: MARGIN, y: flow.y, size: 10.5, font: fonts.italic, color: GREY })
			flow.y -= 14
		}
		flow.y -= 6
	}
	for (const row of table.rows) {
		need(flow, 20)
		const value = winAnsi(row.value)
		flow.page.drawText(fit(winAnsi(row.label), fonts.regular, 11.5, WIDTH - 70), { x: MARGIN, y: flow.y, size: 11.5, font: fonts.regular, color: INK })
		flow.page.drawText(value, { x: MARGIN + WIDTH - fonts.bold.widthOfTextAtSize(value, 11.5), y: flow.y, size: 11.5, font: fonts.bold, color: INK })
		flow.page.drawLine({ start: { x: MARGIN, y: flow.y - 5 }, end: { x: MARGIN + WIDTH, y: flow.y - 5 }, thickness: 0.3, color: GREY })
		flow.y -= 20
	}
	flow.y -= 14
}

/**
 * A list of titles with a line of detail.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param list - heading and items
 */
function list(flow: Flow, fonts: Fonts, list: ReportBooklet['lists'][number]): void {
	if (list.items.length === 0) {
		return
	}
	heading(flow, fonts, list.heading)
	for (const item of list.items) {
		need(flow, 30)
		flow.page.drawText(fit(winAnsi(item.title), fonts.bold, 11.5, WIDTH), { x: MARGIN, y: flow.y, size: 11.5, font: fonts.bold, color: INK })
		flow.y -= 13.5
		if (item.detail) {
			flow.page.drawText(fit(winAnsi(item.detail), fonts.regular, 10, WIDTH), { x: MARGIN, y: flow.y, size: 10, font: fonts.regular, color: GREY })
			flow.y -= 13
		}
		flow.y -= 5
	}
	if (list.more) {
		need(flow, 16)
		flow.page.drawText(fit(winAnsi(list.more), fonts.italic, 10.5, WIDTH), { x: MARGIN, y: flow.y, size: 10.5, font: fonts.italic, color: GREY })
		flow.y -= 16
	}
	flow.y -= 10
}

/**
 * Sets the annual report as a booklet.
 *
 * @param report - the report with its texts
 */
export async function buildReport(report: ReportBooklet): Promise<{ bytes: Uint8Array, pages: number }> {
	const document = await PDFDocument.create()
	document.setTitle(winAnsi(report.title))
	document.setCreator('Cantoria')
	const fonts: Fonts = {
		regular: await document.embedFont(StandardFonts.TimesRoman),
		bold: await document.embedFont(StandardFonts.TimesRomanBold),
		italic: await document.embedFont(StandardFonts.TimesRomanItalic),
	}
	const flow = { document, pages: [] as PDFPage[], y: 0 } as Flow
	cover(flow, fonts, report)
	figures(flow, fonts, report)
	timeline(flow, fonts, report)
	report.tables.forEach((entry) => table(flow, fonts, entry))
	report.lists.forEach((entry) => list(flow, fonts, entry))
	flow.pages.slice(1).forEach((page, index) => {
		const text = winAnsi(`${report.title} · ${index + 2}`)
		page.drawText(text, { x: (A4[0] - fonts.regular.widthOfTextAtSize(text, 9)) / 2, y: 30, size: 9, font: fonts.regular, color: GREY })
	})
	return { bytes: await document.save(), pages: flow.pages.length }
}
