/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The songbook put together in the browser (src/songbook/build.ts): cover,
 * contents with the first page of each piece, pages of the scans kept as
 * they are, right-hand starts for double-sided printing, scans that cannot
 * be read left out, and text the standard fonts can write.
 */
import { degrees, PDFDocument } from 'pdf-lib'
import { describe, expect, it } from 'vitest'
import { buildSongbook, contentsPages, sortPieces, winAnsi } from '../songbook/build.ts'
import type { SongbookOptions, SongbookSource } from '../songbook/build.ts'

/**
 * A scan of some pages.
 *
 * @param pages - number of pages
 * @param rotate - rotation of the pages
 */
async function scan(pages: number, rotate = 0): Promise<Uint8Array> {
	const doc = await PDFDocument.create()
	for (let index = 0; index < pages; index++) {
		doc.addPage([400, 600]).setRotation(degrees(rotate))
	}
	return doc.save()
}

async function piece(title: string, pages: number, extra: Partial<SongbookSource> = {}): Promise<SongbookSource> {
	return { title, composer: 'J. S. Bach', arranger: null, shelfmark: '1-1', note: null, pdf: await scan(pages), ...extra }
}

const OPTIONS: SongbookOptions = {
	title: 'Adventskonzert',
	subtitle: '13. Dezember 2026',
	cover: true,
	contents: true,
	pageNumbers: true,
	rightHand: false,
	labels: { contents: 'Inhalt', count: '3 Stücke', arranger: 'Bearb.' },
}

describe('songbook', () => {
	it('puts cover, contents and the scans together and knows the first page of each piece', async () => {
		const result = await buildSongbook([await piece('Erstes', 2), await piece('Zweites', 1), await piece('Drittes', 3)], OPTIONS)
		expect(result.pages).toBe(1 + 1 + 6)
		expect(result.pieces).toEqual([{ title: 'Erstes', page: 3 }, { title: 'Zweites', page: 5 }, { title: 'Drittes', page: 6 }])
		expect(result.skipped).toEqual([])
		const doc = await PDFDocument.load(result.bytes)
		expect(doc.getPageCount()).toBe(8)
		expect(doc.getTitle()).toBe('Adventskonzert')
		// the scans keep their size
		expect(doc.getPage(2).getSize()).toEqual({ width: 400, height: 600 })
	})

	it('starts every piece on a right-hand page for double-sided printing', async () => {
		const result = await buildSongbook([await piece('Erstes', 1), await piece('Zweites', 2), await piece('Drittes', 1)], { ...OPTIONS, rightHand: true })
		// cover 1, blank 2, contents 3, blank 4, piece 5, blank 6, piece 7–8, piece 9
		expect(result.pieces.map((entry) => entry.page)).toEqual([5, 7, 9])
		expect(result.pieces.every((entry) => entry.page % 2 === 1)).toBe(true)
		expect(result.pages).toBe(9)
	})

	it('can leave out cover and contents', async () => {
		const result = await buildSongbook([await piece('Erstes', 2), await piece('Zweites', 1)], { ...OPTIONS, cover: false, contents: false, pageNumbers: false })
		expect(result.pages).toBe(3)
		expect(result.pieces.map((entry) => entry.page)).toEqual([1, 3])
	})

	it('leaves out a scan it cannot read and says so', async () => {
		const damaged: SongbookSource = { ...(await piece('Kaputt', 1)), pdf: new TextEncoder().encode('%PDF-1.7 not really') }
		const progress: number[] = []
		const result = await buildSongbook([await piece('Gut', 1), damaged], OPTIONS, (done, total) => progress.push(done / total))
		expect(result.skipped).toEqual(['Kaputt'])
		expect(result.skippedIndexes).toEqual([1])
		expect(result.pieces.map((entry) => entry.title)).toEqual(['Gut'])
		expect(progress.at(-1)).toBeLessThanOrEqual(1)
	})

	it('numbers rotated scans, too', async () => {
		const rotated: SongbookSource = { ...(await piece('Quer', 1)), pdf: await scan(1, 90) }
		const result = await buildSongbook([rotated], { ...OPTIONS, cover: false, contents: false })
		const doc = await PDFDocument.load(result.bytes)
		expect(doc.getPage(0).getRotation().angle).toBe(90)
	})

	it('makes the contents as long as the list', async () => {
		expect(contentsPages(0)).toBe(1)
		expect(contentsPages(20)).toBe(1)
		expect(contentsPages(200)).toBeGreaterThan(5)
		const many = await Promise.all(Array.from({ length: 60 }, (_, index) => piece('Stück ' + (index + 1), 1)))
		const result = await buildSongbook(many, { ...OPTIONS, cover: false })
		expect(result.pieces[0]!.page).toBe(contentsPages(60) + 1)
	})

	it('writes only what the standard fonts know', () => {
		expect(winAnsi('Grüß Gott – „Ave“ €')).toBe('Grüß Gott – „Ave“ €')
		expect(winAnsi('Dvořák, Łódź')).toBe('Dvorák, Lódz')
		expect(winAnsi('Zeile\neins\ttab')).toBe('Zeile eins tab')
		expect(winAnsi('Ӂ 漢')).toBe('? ?')
	})

	it('sorts by title or shelf mark, numbers as numbers', () => {
		const list = [{ title: 'b', shelfmark: '2-10' }, { title: 'A', shelfmark: '2-9' }, { title: 'c', shelfmark: null }]
		expect(sortPieces(list, 'given').map((entry) => entry.title)).toEqual(['b', 'A', 'c'])
		expect(sortPieces(list, 'title').map((entry) => entry.title)).toEqual(['A', 'b', 'c'])
		expect(sortPieces(list, 'shelfmark').map((entry) => entry.shelfmark)).toEqual([null, '2-9', '2-10'])
	})
})
