/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The programme booklet as a PDF (proposal 2, November 2026), made in the
 * browser: a cover, the programme with composers, lyricists and their life
 * dates, and the lyrics with their translation side by side. Black on
 * white, a serif typeface, A5 (folded A4) or A4. The server has decided
 * which lyrics may be printed; this module only sets the pages.
 *
 * Loaded on demand (pdf-lib is large): `await import('./booklet/build.ts')`.
 */

import type { PDFFont, PDFPage } from 'pdf-lib'

import { PDFDocument, rgb, StandardFonts } from 'pdf-lib'
import { fit, winAnsi, wrap } from '../songbook/build.ts'

export interface BookletEntry {
	title: string
	/** e.g. { label: "Text", name: "Matthias Claudius", dates: "1740–1815" }; the composer has no label */
	people: { label: string, name: string, dates: string }[]
	duration: string | null
	encore: boolean
	/** e.g. who sings or plays with the choir */
	note: string | null
	lyrics: string | null
	translation: string | null
	translationSource: string | null
}

export interface BookletLabels {
	programme: string
	encore: string
	lyrics: string
	/** heading of the column of the translation */
	translation: string
	/** heading of the column of the original */
	original: string
}

export interface BookletOptions {
	size: 'a5' | 'a4'
	title: string
	/** above the title, e.g. the name of the choir */
	overline: string
	/** under the title: date, time, place */
	lines: string[]
	/** a few words of welcome on the programme page */
	intro: string
	durations: boolean
	labels: BookletLabels
}

export interface BookletResult {
	bytes: Uint8Array
	pages: number
}

const SIZES: Record<BookletOptions['size'], [number, number]> = {
	a5: [419.53, 595.28],
	a4: [595.28, 841.89],
}

const INK = rgb(0, 0, 0)
const GREY = rgb(0.3, 0.3, 0.3)

interface Fonts {
	regular: PDFFont
	bold: PDFFont
	italic: PDFFont
}

/**
 * Pages with a running position from the top: a new page when the next
 * block does not fit.
 */
class Flow {

	page!: PDFPage
	y = 0
	readonly pages: PDFPage[] = []
	readonly size: [number, number]
	readonly margin: number
	private readonly document: PDFDocument

	constructor(document: PDFDocument, size: [number, number], margin: number) {
		this.document = document
		this.size = size
		this.margin = margin
	}

	get width(): number {
		return this.size[0] - 2 * this.margin
	}

	newPage(): void {
		this.page = this.document.addPage(this.size)
		this.pages.push(this.page)
		this.y = this.size[1] - this.margin
	}

	/**
	 * Make room for a block, on a new page when needed.
	 *
	 * @param height - points the block needs
	 */
	need(height: number): void {
		if (this.y - height < this.margin) {
			this.newPage()
		}
	}

}

/**
 * Writes a line centred.
 *
 * @param page - the page
 * @param text - the text (already WinAnsi)
 * @param font - font
 * @param size - font size
 * @param y - baseline
 * @param width - page width
 */
function centred(page: PDFPage, text: string, font: PDFFont, size: number, y: number, width: number): void {
	page.drawText(text, { x: (width - font.widthOfTextAtSize(text, size)) / 2, y, size, font, color: INK })
}

/**
 * A small ornament: a rule with a diamond in the middle.
 *
 * @param page - the page
 * @param y - its middle
 * @param width - page width
 * @param length - length of the rule
 */
function ornament(page: PDFPage, y: number, width: number, length: number): void {
	const middle = width / 2
	page.drawLine({ start: { x: middle - length / 2, y }, end: { x: middle - 7, y }, thickness: 0.6, color: INK })
	page.drawLine({ start: { x: middle + 7, y }, end: { x: middle + length / 2, y }, thickness: 0.6, color: INK })
	page.drawSvgPath('M 0 -3.5 L 3.5 0 L 0 3.5 L -3.5 0 Z', { x: middle, y, color: INK })
}

/**
 * The lines of a text with its own line breaks, wrapped to a width; empty
 * lines stay (verses).
 *
 * @param text - the text
 * @param font - font
 * @param size - font size
 * @param width - width available
 */
function textLines(text: string, font: PDFFont, size: number, width: number): string[] {
	return text.replace(/\r\n?/g, '\n').split('\n').flatMap((line) => {
		const clean = winAnsi(line).trim()
		return clean === '' ? [''] : wrap(clean, font, size, width, Number.MAX_SAFE_INTEGER)
	})
}

/**
 * The cover.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param options - title and lines
 */
function cover(flow: Flow, fonts: Fonts, options: BookletOptions): void {
	flow.newPage()
	const [width, height] = flow.size
	const scale = width / SIZES.a5[0]
	const titleSize = 30 * scale
	const titleLines = wrap(winAnsi(options.title), fonts.bold, titleSize, flow.width, 4)
	const block = titleLines.length * titleSize * 1.2
	let y = height * 0.58 + block / 2
	if (options.overline.trim() !== '') {
		centred(flow.page, winAnsi(options.overline.trim()).toUpperCase(), fonts.regular, 11 * scale, y + 34 * scale, width)
	}
	ornament(flow.page, y + 18 * scale, width, 120 * scale)
	for (const line of titleLines) {
		y -= titleSize
		centred(flow.page, line, fonts.bold, titleSize, y, width)
		y -= titleSize * 0.2
	}
	ornament(flow.page, y - 14 * scale, width, 120 * scale)
	y -= 44 * scale
	for (const line of options.lines.filter((part) => part.trim() !== '')) {
		centred(flow.page, fit(winAnsi(line), fonts.italic, 13 * scale, flow.width), fonts.italic, 13 * scale, y, width)
		y -= 19 * scale
	}
}

/**
 * The programme: title and duration, then the people.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param entries - the pieces
 * @param options - labels and switches
 */
function programme(flow: Flow, fonts: Fonts, entries: BookletEntry[], options: BookletOptions): void {
	flow.newPage()
	const width = flow.size[0]
	const scale = width / SIZES.a5[0]
	const titleSize = 12.5 * scale
	const peopleSize = 10 * scale
	flow.y -= 18 * scale
	centred(flow.page, winAnsi(options.labels.programme), fonts.bold, 17 * scale, flow.y, width)
	flow.y -= 14 * scale
	ornament(flow.page, flow.y, width, 80 * scale)
	flow.y -= 22 * scale
	if (options.intro.trim() !== '') {
		for (const line of textLines(options.intro.trim(), fonts.italic, peopleSize + 0.5, flow.width)) {
			flow.need(peopleSize * 1.4)
			flow.page.drawText(line, { x: flow.margin, y: flow.y, size: peopleSize + 0.5, font: fonts.italic, color: INK })
			flow.y -= peopleSize * 1.4
		}
		flow.y -= 12 * scale
	}
	let encoreShown = false
	for (const entry of entries) {
		const lines = wrap(winAnsi(entry.title), fonts.bold, titleSize, flow.width - (options.durations && entry.duration ? 40 * scale : 0), 3)
		const people = entry.people.map((person) => winAnsi([person.label ? `${person.label}: ` : '', person.name, person.dates ? ` (${person.dates})` : ''].join('')))
		const height = lines.length * titleSize * 1.25 + (people.length + (entry.note ? 1 : 0)) * peopleSize * 1.35 + 14 * scale
		if (entry.encore && !encoreShown) {
			encoreShown = true
			flow.need(height + 30 * scale)
			flow.y -= 6 * scale
			centred(flow.page, winAnsi(options.labels.encore), fonts.italic, 11 * scale, flow.y, width)
			flow.y -= 20 * scale
		}
		flow.need(height)
		lines.forEach((line, index) => {
			flow.y -= index === 0 ? 0 : titleSize * 1.25
			flow.page.drawText(line, { x: flow.margin, y: flow.y, size: titleSize, font: fonts.bold, color: INK })
		})
		if (options.durations && entry.duration) {
			const text = winAnsi(entry.duration)
			const size = peopleSize
			flow.page.drawText(text, { x: flow.margin + flow.width - fonts.regular.widthOfTextAtSize(text, size), y: flow.y, size, font: fonts.regular, color: GREY })
		}
		flow.y -= titleSize * 1.3
		for (const line of people) {
			flow.page.drawText(fit(line, fonts.italic, peopleSize, flow.width), { x: flow.margin, y: flow.y, size: peopleSize, font: fonts.italic, color: INK })
			flow.y -= peopleSize * 1.35
		}
		if (entry.note) {
			flow.page.drawText(fit(winAnsi(entry.note), fonts.regular, peopleSize, flow.width), { x: flow.margin, y: flow.y, size: peopleSize, font: fonts.regular, color: GREY })
			flow.y -= peopleSize * 1.35
		}
		flow.y -= 14 * scale
	}
}

/**
 * The lyrics, with the translation in a second column.
 *
 * @param flow - the pages
 * @param fonts - fonts
 * @param entries - the pieces with lyrics
 * @param options - labels
 */
function lyrics(flow: Flow, fonts: Fonts, entries: BookletEntry[], options: BookletOptions): void {
	const printed = entries.filter((entry) => entry.lyrics !== null && entry.lyrics.trim() !== '')
	if (printed.length === 0) {
		return
	}
	flow.newPage()
	const width = flow.size[0]
	const scale = width / SIZES.a5[0]
	const size = 9.5 * scale
	const leading = size * 1.35
	const gap = 16 * scale
	flow.y -= 18 * scale
	centred(flow.page, winAnsi(options.labels.lyrics), fonts.bold, 17 * scale, flow.y, width)
	flow.y -= 14 * scale
	ornament(flow.page, flow.y, width, 80 * scale)
	flow.y -= 26 * scale
	for (const entry of printed) {
		const twoColumns = entry.translation !== null && entry.translation.trim() !== ''
		const column = twoColumns ? (flow.width - gap) / 2 : flow.width
		const left = textLines(entry.lyrics ?? '', fonts.regular, size, column)
		const right = twoColumns ? textLines(entry.translation ?? '', fonts.italic, size, column) : []
		// the heading and the first lines stay together
		flow.need(12 * scale * 1.4 + leading * Math.min(4, Math.max(left.length, right.length)) + (twoColumns ? leading : 0))
		flow.page.drawText(fit(winAnsi(entry.title), fonts.bold, 12 * scale, flow.width), { x: flow.margin, y: flow.y, size: 12 * scale, font: fonts.bold, color: INK })
		flow.y -= 12 * scale * 1.5
		if (twoColumns) {
			flow.page.drawText(winAnsi(options.labels.original), { x: flow.margin, y: flow.y, size: size - 1, font: fonts.regular, color: GREY })
			flow.page.drawText(winAnsi(options.labels.translation), { x: flow.margin + column + gap, y: flow.y, size: size - 1, font: fonts.regular, color: GREY })
			flow.y -= leading
		}
		const rows = Math.max(left.length, right.length)
		for (let row = 0; row < rows; row++) {
			flow.need(leading)
			if (left[row]) {
				flow.page.drawText(left[row], { x: flow.margin, y: flow.y, size, font: fonts.regular, color: INK })
			}
			if (right[row]) {
				flow.page.drawText(right[row], { x: flow.margin + column + gap, y: flow.y, size, font: fonts.italic, color: INK })
			}
			flow.y -= leading
		}
		if (entry.translationSource && twoColumns) {
			flow.need(leading)
			const source = fit(winAnsi(entry.translationSource), fonts.italic, size - 1.5, column)
			flow.page.drawText(source, { x: flow.margin + column + gap, y: flow.y, size: size - 1.5, font: fonts.italic, color: GREY })
			flow.y -= leading
		}
		flow.y -= 16 * scale
	}
}

/**
 * Sets the booklet.
 *
 * @param entries - the pieces in their order
 * @param options - size, cover and labels
 */
export async function buildBooklet(entries: BookletEntry[], options: BookletOptions): Promise<BookletResult> {
	const document = await PDFDocument.create()
	document.setTitle(winAnsi(options.title))
	document.setCreator('Cantoria')
	const fonts: Fonts = {
		regular: await document.embedFont(StandardFonts.TimesRoman),
		bold: await document.embedFont(StandardFonts.TimesRomanBold),
		italic: await document.embedFont(StandardFonts.TimesRomanItalic),
	}
	const size = SIZES[options.size]
	const flow = new Flow(document, size, options.size === 'a5' ? 42 : 60)
	cover(flow, fonts, options)
	programme(flow, fonts, entries, options)
	lyrics(flow, fonts, entries, options)
	// Page numbers, not on the cover
	flow.pages.slice(1).forEach((page, index) => {
		const text = String(index + 2)
		page.drawText(text, { x: (size[0] - fonts.regular.widthOfTextAtSize(text, 9)) / 2, y: 22, size: 9, font: fonts.regular, color: GREY })
	})
	const bytes = await document.save()
	return { bytes, pages: flow.pages.length }
}
