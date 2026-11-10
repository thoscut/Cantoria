/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A songbook from a selection (round 3, November 2026): the scans (PDF) of
 * the pieces put together in the browser into one PDF to print, with a
 * cover, the contents with the page of each piece, and page numbers. The
 * server decides which pieces may go in (rights) and hands out their scans;
 * this module only arranges the pages. The scans keep their pages as they
 * are (size, rotation); cover and contents are A4.
 *
 * Loaded on demand (pdf-lib is large): `await import('./songbook/build.ts')`.
 */

import type { PDFFont, PDFPage } from 'pdf-lib'

import { degrees, PDFDocument, rgb, StandardFonts } from 'pdf-lib'

export interface SongbookSource {
	title: string
	composer: string | null
	arranger: string | null
	shelfmark: string | null
	/** e.g. "Encore" */
	note: string | null
	/** the scan */
	pdf: ArrayBuffer | Uint8Array
}

export interface SongbookLabels {
	/** heading of the contents */
	contents: string
	/** the line under the cover title, e.g. "12 pieces" */
	count: string
	/** "Arr." before the arranger */
	arranger: string
}

export interface SongbookOptions {
	title: string
	subtitle: string
	cover: boolean
	contents: boolean
	pageNumbers: boolean
	/** each piece starts on a right-hand (odd) page, for double-sided printing */
	rightHand: boolean
	labels: SongbookLabels
}

export interface SongbookResult {
	bytes: Uint8Array
	/** pages in all */
	pages: number
	/** the pieces in the songbook, with their first page */
	pieces: { title: string, page: number }[]
	/** titles of the scans that could not be read (damaged or encrypted), left out */
	skipped: string[]
	/** their positions in the list given */
	skippedIndexes: number[]
}

export type SongbookOrder = 'given' | 'title' | 'shelfmark'

/** A4 in PDF points */
const A4: [number, number] = [595.28, 841.89]
const MARGIN = 56
/** contents: lines of one entry (title, then composer and shelf mark) */
const ENTRY_HEIGHT = 30
const CONTENTS_TOP = A4[1] - MARGIN - 48

/** Characters of WinAnsiEncoding above Latin-1's range (the standard fonts know no others) */
const WIN_ANSI_EXTRA = new Set('€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ')
/** Letters and signs without a decomposition, and what is written instead */
const SUBSTITUTES: Record<string, string> = { Ł: 'L', ł: 'l', Đ: 'D', đ: 'd', ı: 'i', Ħ: 'H', ħ: 'h', '\u2010': '-', '\u2011': '-', '−': '-', '′': "'", '″': '"', '\u2009': ' ', '\u202f': ' ' }

/**
 * Text the standard fonts can write (WinAnsiEncoding): Latin-1 and a few
 * typographic signs. Other letters lose their accents where that helps
 * ("ő" → "o"), anything else becomes "?". Line breaks become spaces.
 *
 * @param text - any text
 */
export function winAnsi(text: string): string {
	let out = ''
	for (const char of text.normalize('NFC').replace(/[\r\n\t]+/g, ' ')) {
		const code = char.codePointAt(0) ?? 0
		if ((code >= 0x20 && code <= 0x7E) || (code >= 0xA0 && code <= 0xFF) || WIN_ANSI_EXTRA.has(char)) {
			out += char
			continue
		}
		if (code < 0x20 || (code >= 0x7F && code < 0xA0)) {
			continue
		}
		if (SUBSTITUTES[char] !== undefined) {
			out += SUBSTITUTES[char]
			continue
		}
		const base = char.normalize('NFD').replace(/[̀-ͯ]/g, '')
		out += base !== char && base !== '' && [...base].every((c) => (c.codePointAt(0) ?? 0) <= 0xFF) ? base : '?'
	}
	return out
}

/**
 * The pieces in the chosen order: as given (the programme), by title, or by
 * shelf mark (numbers as numbers: 2-10 after 2-9). A stable sort.
 *
 * @param pieces - the pieces
 * @param order - the order
 */
export function sortPieces<T extends { title: string, shelfmark: string | null }>(pieces: T[], order: SongbookOrder): T[] {
	if (order === 'given') {
		return [...pieces]
	}
	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
	const key = (piece: T): string => (order === 'title' ? piece.title : piece.shelfmark ?? '')
	return [...pieces].sort((a, b) => collator.compare(key(a), key(b)))
}

/**
 * How many pages the contents take for this many pieces.
 *
 * @param count - number of pieces
 */
export function contentsPages(count: number): number {
	const first = Math.floor((CONTENTS_TOP - 24 - MARGIN) / ENTRY_HEIGHT)
	const next = Math.floor((A4[1] - 2 * MARGIN - 24) / ENTRY_HEIGHT)
	if (count <= first) {
		return 1
	}
	return 1 + Math.ceil((count - first) / next)
}

/**
 * The text cut to a width, with "…".
 *
 * @param text - the text
 * @param font - font
 * @param size - font size
 * @param width - the width available
 */
function fit(text: string, font: PDFFont, size: number, width: number): string {
	if (font.widthOfTextAtSize(text, size) <= width) {
		return text
	}
	let cut = text
	while (cut.length > 1 && font.widthOfTextAtSize(cut + '…', size) > width) {
		cut = cut.slice(0, -1)
	}
	return cut.trimEnd() + '…'
}

/**
 * The text broken into lines of a width (at most `max` lines, the last cut).
 *
 * @param text - the text
 * @param font - font
 * @param size - font size
 * @param width - the width available
 * @param max - most lines
 */
function wrap(text: string, font: PDFFont, size: number, width: number, max: number): string[] {
	const lines: string[] = []
	let line = ''
	for (const word of text.split(' ').filter((part) => part !== '')) {
		const next = line === '' ? word : line + ' ' + word
		if (line !== '' && font.widthOfTextAtSize(next, size) > width) {
			lines.push(line)
			line = word
		} else {
			line = next
		}
	}
	if (line !== '') {
		lines.push(line)
	}
	if (lines.length > max) {
		const kept = lines.slice(0, max)
		kept[max - 1] = fit(lines.slice(max - 1).join(' '), font, size, width)
		return kept
	}
	return lines.map((part) => fit(part, font, size, width))
}

/**
 * Writes a page number at the bottom of the page as it is seen, also on
 * rotated scans.
 *
 * @param page - the page
 * @param text - the number
 * @param font - font
 */
function drawPageNumber(page: PDFPage, text: string, font: PDFFont): void {
	const size = 10
	const width = font.widthOfTextAtSize(text, size)
	const box = page.getCropBox()
	const angle = ((page.getRotation().angle % 360) + 360) % 360
	const inset = 20
	// the middle of the edge that is seen at the bottom, and the direction of the text there
	const placements: Record<number, { x: number, y: number, dx: number, dy: number }> = {
		0: { x: box.x + box.width / 2, y: box.y + inset, dx: 1, dy: 0 },
		90: { x: box.x + box.width - inset, y: box.y + box.height / 2, dx: 0, dy: 1 },
		180: { x: box.x + box.width / 2, y: box.y + box.height - inset, dx: -1, dy: 0 },
		270: { x: box.x + inset, y: box.y + box.height / 2, dx: 0, dy: -1 },
	}
	const place = placements[angle] ?? placements[0]!
	page.drawText(text, {
		x: place.x - place.dx * width / 2,
		y: place.y - place.dy * width / 2,
		size,
		font,
		color: rgb(0.25, 0.25, 0.25),
		rotate: degrees(angle),
	})
}

/**
 * Puts the songbook together.
 *
 * @param sources - the pieces in their order, each with its scan
 * @param options - title, parts and labels
 * @param onProgress - called after each piece (done, total)
 */
export async function buildSongbook(sources: SongbookSource[], options: SongbookOptions,
	onProgress?: (done: number, total: number) => void): Promise<SongbookResult> {
	const book = await PDFDocument.create()
	const regular = await book.embedFont(StandardFonts.Helvetica)
	const bold = await book.embedFont(StandardFonts.HelveticaBold)
	const title = winAnsi(options.title.trim())
	book.setTitle(options.title.trim() || options.labels.contents)
	book.setCreator('Cantoria')
	book.setProducer('Cantoria')

	// read every scan first: one that cannot be read is left out, before the contents are laid out
	const loaded: { source: SongbookSource, doc: PDFDocument }[] = []
	const skipped: string[] = []
	const skippedIndexes: number[] = []
	for (const [index, source] of sources.entries()) {
		try {
			const doc = await PDFDocument.load(source.pdf, { updateMetadata: false })
			if (doc.getPageCount() > 0) {
				loaded.push({ source, doc })
				onProgress?.(index + 1, sources.length * 2)
				continue
			}
		} catch {
			// damaged or encrypted: left out below
		}
		skipped.push(source.title)
		skippedIndexes.push(index)
	}

	/** pages that get no number: cover and blank pages */
	const unnumbered = new Set<PDFPage>()
	const blank = (): void => {
		unnumbered.add(book.addPage(A4))
	}

	if (options.cover) {
		const page = book.addPage(A4)
		unnumbered.add(page)
		const width = A4[0] - 2 * MARGIN
		let y = A4[1] * 0.62
		for (const line of wrap(title, bold, 30, width, 4)) {
			page.drawText(line, { x: (A4[0] - bold.widthOfTextAtSize(line, 30)) / 2, y, size: 30, font: bold })
			y -= 38
		}
		y -= 6
		for (const line of [...wrap(winAnsi(options.subtitle.trim()), regular, 16, width, 3), winAnsi(options.labels.count)]) {
			if (line !== '') {
				page.drawText(line, { x: (A4[0] - regular.widthOfTextAtSize(line, 16)) / 2, y, size: 16, font: regular, color: rgb(0.3, 0.3, 0.3) })
				y -= 24
			}
		}
		if (options.rightHand) {
			blank()
		}
	}

	const contents: PDFPage[] = []
	if (options.contents) {
		for (let index = 0; index < contentsPages(loaded.length); index++) {
			contents.push(book.addPage(A4))
		}
		if (options.rightHand && book.getPageCount() % 2 === 1) {
			blank()
		}
	}

	const pieces: { title: string, page: number }[] = []
	for (const [index, { source, doc }] of loaded.entries()) {
		if (options.rightHand && book.getPageCount() % 2 === 1) {
			blank()
		}
		pieces.push({ title: source.title, page: book.getPageCount() + 1 })
		const copied = await book.copyPages(doc, doc.getPageIndices())
		for (const page of copied) {
			book.addPage(page)
		}
		onProgress?.(sources.length + index + 1, sources.length * 2)
	}

	// the contents, now that the first page of every piece is known
	let entry = 0
	for (const [pageIndex, page] of contents.entries()) {
		let y = A4[1] - MARGIN - 24
		if (pageIndex === 0) {
			page.drawText(winAnsi(options.labels.contents), { x: MARGIN, y: A4[1] - MARGIN - 24, size: 20, font: bold })
			y = CONTENTS_TOP
		}
		while (entry < loaded.length && y - ENTRY_HEIGHT >= MARGIN - 4) {
			const { source } = loaded[entry]!
			const number = String(pieces[entry]!.page)
			const numberWidth = bold.widthOfTextAtSize(number, 11)
			const text = source.note ? `${source.title} (${source.note})` : source.title
			page.drawText(fit(winAnsi(text), bold, 11, A4[0] - 2 * MARGIN - numberWidth - 16), { x: MARGIN, y, size: 11, font: bold })
			page.drawText(number, { x: A4[0] - MARGIN - numberWidth, y, size: 11, font: bold })
			const people = [source.composer, source.arranger ? `${options.labels.arranger} ${source.arranger}` : null, source.shelfmark]
				.filter((part): part is string => part !== null && part.trim() !== '').join(' · ')
			if (people !== '') {
				page.drawText(fit(winAnsi(people), regular, 9, A4[0] - 2 * MARGIN), { x: MARGIN, y: y - 12, size: 9, font: regular, color: rgb(0.35, 0.35, 0.35) })
			}
			y -= ENTRY_HEIGHT
			entry++
		}
	}

	if (options.pageNumbers) {
		for (const [index, page] of book.getPages().entries()) {
			if (!unnumbered.has(page)) {
				drawPageNumber(page, String(index + 1), regular)
			}
		}
	}

	return { bytes: await book.save(), pages: book.getPageCount(), pieces, skipped, skippedIndexes }
}
