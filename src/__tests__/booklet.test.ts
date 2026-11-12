/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { BookletEntry, BookletOptions } from '../booklet/build.ts'

import { PDFDocument } from 'pdf-lib'
import { describe, expect, it } from 'vitest'
import { lifeDates, versesText } from '../booklet/text.ts'
import { buildBooklet } from '../booklet/build.ts'

const options: BookletOptions = {
	size: 'a5',
	title: 'Abendlieder',
	overline: 'Kammerchor',
	lines: ['12. Dezember 2026', '19:30', 'Stadtkirche'],
	intro: 'Herzlich willkommen.',
	durations: true,
	labels: { programme: 'Programm', encore: 'Zugabe', lyrics: 'Liedtexte', original: 'Original', translation: 'Übersetzung' },
}

/**
 * @param title - title of the piece
 * @param extra - other fields
 */
function entry(title: string, extra: Partial<BookletEntry> = {}): BookletEntry {
	return { title, people: [], duration: null, encore: false, note: null, lyrics: null, translation: null, translationSource: null, ...extra }
}

describe('programme booklet', () => {
	it('has a cover and the programme, and no lyrics page without lyrics', async () => {
		const built = await buildBooklet([entry('Der Mond ist aufgegangen', { people: [{ label: '', name: 'J. A. P. Schulz', dates: '1747–1800' }], duration: '3:20' })], options)
		expect(built.pages).toBe(2)
		const document = await PDFDocument.load(built.bytes)
		expect(document.getPageCount()).toBe(2)
		expect(document.getPage(0).getSize().width).toBeCloseTo(419.53)
	})

	it('adds the lyrics with the translation and flows over pages', async () => {
		const long = Array.from({ length: 90 }, (_, index) => `Zeile ${index + 1} des Liedes`).join('\n')
		const built = await buildBooklet([
			entry('Ubi caritas', { lyrics: 'Ubi caritas et amor,\nDeus ibi est.', translation: 'Wo Güte ist und Liebe,\nda ist Gott.', translationSource: 'Übersetzung: N. N.' }),
			entry('Langes Lied', { lyrics: long }),
			entry('Zugabe', { encore: true }),
		], { ...options, size: 'a4' })
		expect(built.pages).toBeGreaterThanOrEqual(4)
		expect((await PDFDocument.load(built.bytes)).getPage(0).getSize().height).toBeCloseTo(841.89)
	})

	it('writes letters the standard fonts do not know', async () => {
		const built = await buildBooklet([entry('Otče náš – Ave verum', { people: [{ label: 'Text', name: 'Dvořák', dates: '' }] })], options)
		expect(built.pages).toBe(2)
	})
})

describe('booklet texts', () => {
	it('writes life dates', () => {
		const bc = (year: string) => `${year} v. Chr.`
		expect(lifeDates({ born: '1740', died: '1815' }, bc)).toBe('1740–1815')
		expect(lifeDates({ born: '1962', died: null }, bc)).toBe('* 1962')
		expect(lifeDates({ born: null, died: '1815' }, bc)).toBe('† 1815')
		expect(lifeDates({ born: '-65', died: '-8' }, bc)).toBe('65 v. Chr.–8 v. Chr.')
		expect(lifeDates({ born: null, died: null }, bc)).toBe('')
	})

	it('numbers the verses when there are several', () => {
		expect(versesText([{ number: '1', text: 'A' }])).toBe('A')
		expect(versesText([{ number: '1', text: 'A' }, { number: '2', text: 'B' }])).toBe('1. A\n\n2. B')
	})
})
