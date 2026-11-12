/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { Entry } from '../types.ts'
import { countPayload, LABELS_PER_SHEET, labelSheets, parseCount, parseShelfmarkList, similarEntries } from '../maintenance.ts'

describe('labelSheets', () => {
	it('fills one sheet with empty places after the labels', () => {
		const sheets = labelSheets(['a', 'b', 'c'])
		expect(sheets).toHaveLength(1)
		expect(sheets[0]).toHaveLength(LABELS_PER_SHEET)
		expect(sheets[0].slice(0, 4)).toEqual(['a', 'b', 'c', null])
	})

	it('skips used places on the first sheet', () => {
		const sheets = labelSheets(['a', 'b'], 13)
		expect(sheets).toHaveLength(1)
		expect(sheets[0][11]).toBeNull()
		expect(sheets[0][12]).toBe('a')
		expect(sheets[0][13]).toBe('b')
	})

	it('continues on a second sheet', () => {
		const sheets = labelSheets(['a', 'b', 'c'], 14)
		expect(sheets).toHaveLength(2)
		expect(sheets[0][13]).toBe('a')
		expect(sheets[1].slice(0, 3)).toEqual(['b', 'c', null])
	})

	it('clamps the start place', () => {
		expect(labelSheets(['a'], 0)[0][0]).toBe('a')
		expect(labelSheets(['a'], 99)[0][13]).toBe('a')
		expect(labelSheets(['a'], Number.NaN)[0][0]).toBe('a')
	})

	it('has no sheet without labels', () => {
		expect(labelSheets([], 5)).toEqual([])
	})
})

describe('parseShelfmarkList', () => {
	it('reads separated shelf marks in canonical form', () => {
		expect(parseShelfmarkList('10-75, 6-08; 5/1\n2 - 3')).toEqual({ shelfmarks: ['10-75', '6-8', '5-1', '2-3'], invalid: [] })
	})

	it('expands ranges within a binder', () => {
		expect(parseShelfmarkList('10-70..72').shelfmarks).toEqual(['10-70', '10-71', '10-72'])
		expect(parseShelfmarkList('10-70 .. 10-71').shelfmarks).toEqual(['10-70', '10-71'])
		expect(parseShelfmarkList('10-70…71').shelfmarks).toEqual(['10-70', '10-71'])
	})

	it('drops duplicates', () => {
		expect(parseShelfmarkList('1-1 1-01 1-1..2').shelfmarks).toEqual(['1-1', '1-2'])
	})

	it('reports what it cannot read', () => {
		expect(parseShelfmarkList('Tischlied 1-2 3-9..2 3-1..4-5 1-1..1000').invalid)
			.toEqual(['Tischlied', '3-9..2', '3-1..4-5', '1-1..1000'])
	})

	it('accepts an empty text', () => {
		expect(parseShelfmarkList('  ')).toEqual({ shelfmarks: [], invalid: [] })
	})
})

describe('parseCount', () => {
	it('reads whole numbers', () => {
		expect(parseCount(' 30 ')).toBe(30)
		expect(parseCount('0')).toBe(0)
	})

	it('treats empty as not given', () => {
		expect(parseCount('')).toBeNull()
	})

	it('refuses everything else', () => {
		expect(parseCount('-1')).toBeUndefined()
		expect(parseCount('2.5')).toBeUndefined()
		expect(parseCount('drei')).toBeUndefined()
	})
})

describe('countPayload', () => {
	const rows = [
		{ copyId: 1, originals_count: 30, copies_count: 0 },
		{ copyId: 2, originals_count: null, copies_count: null },
		{ copyId: 3, originals_count: 5, copies_count: 5 },
	]

	it('sends only counted copies and only changed figures', () => {
		const payload = countPayload(rows, {
			1: { counted: true, originals: '30', copies: '2' },
			2: { counted: false, originals: '4', copies: '' },
			3: { counted: true, originals: '', copies: '' },
		})
		expect(payload).toEqual({ counts: [{ copyId: 1, copies: 2 }, { copyId: 3 }], invalid: [] })
	})

	it('reports invalid figures and leaves those copies out', () => {
		const payload = countPayload(rows, {
			1: { counted: true, originals: 'x', copies: '' },
			2: { counted: true, originals: '7', copies: '1' },
		})
		expect(payload.invalid).toEqual([1])
		expect(payload.counts).toEqual([{ copyId: 2, originals: 7, copies: 1 }])
	})
})

describe('similar entries at an accession', () => {
	const row = (id: number, title: string, composer: string | null): Entry => ({
		...{ binder: 1, status: 'active', work_no: null, arranger_text: null, rights_status: null, rights_confirmed: null },
		...{ preservation_copy: false, publication: null, valid_since: null, repertoire: null, last_performed: null },
		...{ has_musicxml: false, files: 0, pdfs: 0 },
		id,
		shelfmark: `1-${id}`,
		number: id,
		title,
		composer,
		work_id: id,
		version_id: id,
	})
	const catalog = [
		row(1, 'Ave verum corpus', 'W. A. Mozart'),
		row(2, 'Das Ave verum corpus (Satz)', 'Mozart'),
		row(3, 'Ave verum corpus', 'William Byrd'),
		row(4, 'Der Mond ist aufgegangen', 'J. A. P. Schulz'),
		row(5, 'Ave', null),
	]
	const ids = (list: Entry[]): number[] => list.map((entry) => entry.id)

	it('finds the same title, also with another article, accents or punctuation', () => {
		expect(ids(similarEntries(catalog, 'Mond ist aufgegangen!', ''))).toEqual([4])
		expect(ids(similarEntries(catalog, 'Avé verum corpus', 'Mozart'))).toEqual([1, 2])
	})

	it('leaves out another composer of the same title', () => {
		expect(ids(similarEntries(catalog, 'Ave verum corpus', 'Byrd'))).toEqual([3])
		expect(ids(similarEntries(catalog, 'Ave verum corpus', ''))).toEqual([1, 3, 2])
	})

	it('says nothing for a short or empty title', () => {
		expect(similarEntries(catalog, 'Av', '')).toEqual([])
		expect(ids(similarEntries(catalog, 'Ave', ''))).toEqual([5])
	})
})
