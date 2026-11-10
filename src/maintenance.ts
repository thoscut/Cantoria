/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers for maintaining the catalog: shelf label sheets, lists of
 * shelf marks typed by a user and the counts of an inventory. No Vue, no
 * server access, easy to test.
 */
import type { CountInput, Entry } from './types.ts'

import { normalizeText, parseShelfmark } from './catalog.ts'

/** Avery 3653 and compatible: 2 columns × 7 rows of 105 × 42.3 mm on A4. */
export const LABELS_PER_SHEET = 14

/**
 * Distribute labels over sheets. The first `start - 1` places of the first
 * sheet stay empty, so that a partly used sheet can be printed again.
 *
 * @param labels - labels in print order
 * @param start - place of the first label on the first sheet (1 … 14)
 * @param perSheet - labels per sheet
 * @return sheets with exactly `perSheet` places each; null = empty place
 */
export function labelSheets<T>(labels: T[], start = 1, perSheet = LABELS_PER_SHEET): (T | null)[][] {
	const skip = Math.min(Math.max(Math.trunc(start) || 1, 1), perSheet) - 1
	if (labels.length === 0) {
		return []
	}
	const places: (T | null)[] = [...Array<null>(skip).fill(null), ...labels]
	const sheets: (T | null)[][] = []
	for (let offset = 0; offset < places.length; offset += perSheet) {
		const sheet = places.slice(offset, offset + perSheet)
		while (sheet.length < perSheet) {
			sheet.push(null)
		}
		sheets.push(sheet)
	}
	return sheets
}

export interface ShelfmarkList {
	/** canonical shelf marks in the order typed, without duplicates */
	shelfmarks: string[]
	/** parts that are no shelf mark or no valid range */
	invalid: string[]
}

/** Longest range that is expanded, to keep typing errors harmless. */
const MAX_RANGE = 200

/**
 * Shelf marks typed as a list: separated by commas, semicolons, spaces or
 * line breaks; "10-70..75" or "10-70..10-75" stands for a range in one
 * binder.
 *
 * @param text - typed list
 */
export function parseShelfmarkList(text: string): ShelfmarkList {
	const shelfmarks: string[] = []
	const invalid: string[] = []
	const add = (shelfmark: string) => {
		if (!shelfmarks.includes(shelfmark)) {
			shelfmarks.push(shelfmark)
		}
	}
	// "10 - 70" is one shelf mark: join the parts around a dash first
	const normalized = text.replace(/(\d)\s*([-/])\s*(\d)/g, '$1$2$3').replace(/\s*(\.\.|…)\s*/g, '..')
	for (const part of normalized.split(/[\s,;]+/).filter((item) => item !== '')) {
		const range = part.split('..')
		if (range.length === 1) {
			const shelfmark = parseShelfmark(part)
			if (shelfmark === null) {
				invalid.push(part)
			} else {
				add(shelfmark)
			}
			continue
		}
		const from = range.length === 2 ? parseShelfmark(range[0]) : null
		if (from === null) {
			invalid.push(part)
			continue
		}
		const [binder, first] = from.split('-').map((value) => Number.parseInt(value, 10))
		let last: number | null = null
		if (/^\d{1,4}$/.test(range[1])) {
			last = Number.parseInt(range[1], 10)
		} else {
			const to = parseShelfmark(range[1])
			if (to !== null && to.split('-')[0] === String(binder)) {
				last = Number.parseInt(to.split('-')[1], 10)
			}
		}
		if (last === null || last < first || last - first >= MAX_RANGE) {
			invalid.push(part)
			continue
		}
		for (let number = first; number <= last; number++) {
			add(`${binder}-${number}`)
		}
	}
	return { shelfmarks, invalid }
}

/** What a user entered for one copy of the inventory list. */
export interface CountDraft {
	/** the copy has been counted */
	counted: boolean
	/** number of originals as typed ('' = unchanged) */
	originals: string
	/** number of copies as typed ('' = unchanged) */
	copies: string
}

/** Current figures of a copy, as listed. */
export interface CountRow {
	copyId: number
	originals_count: number | null
	copies_count: number | null
}

export interface CountPayload {
	counts: CountInput[]
	/** copies with a number that is not a whole number ≥ 0 */
	invalid: number[]
}

/**
 * A typed count: whole number ≥ 0, '' for "not given", undefined if invalid.
 *
 * @param value - typed value
 */
export function parseCount(value: string): number | null | undefined {
	const text = value.trim()
	if (text === '') {
		return null
	}
	if (!/^\d{1,6}$/.test(text)) {
		return undefined
	}
	return Number.parseInt(text, 10)
}

/**
 * The counts to send: every copy marked counted, with the figures that
 * differ from the listed ones.
 *
 * @param rows - rows of the inventory list
 * @param drafts - entries per copy id
 */
export function countPayload(rows: CountRow[], drafts: Record<number, CountDraft | undefined>): CountPayload {
	const counts: CountInput[] = []
	const invalid: number[] = []
	for (const row of rows) {
		const draft = drafts[row.copyId]
		if (!draft?.counted) {
			continue
		}
		const originals = parseCount(draft.originals)
		const copies = parseCount(draft.copies)
		if (originals === undefined || copies === undefined) {
			invalid.push(row.copyId)
			continue
		}
		const count: CountInput = { copyId: row.copyId }
		if (originals !== null && originals !== row.originals_count) {
			count.originals = originals
		}
		if (copies !== null && copies !== row.copies_count) {
			count.copies = copies
		}
		counts.push(count)
	}
	return { counts, invalid }
}

/**
 * A title as compared for duplicates: without accents, case, punctuation
 * and leading articles.
 *
 * @param title - title
 */
function titleKey(title: string | null | undefined): string {
	return normalizeText(title)
		.replace(/[^\p{L}\p{N}]+/gu, ' ')
		.trim()
		.replace(/^(der|die|das|ein|eine|the|a|an|le|la|les|il|lo|el)\s+/, '')
}

/**
 * The last word of a composer's name, compared without accents and case.
 *
 * @param composer - composer
 */
function surnameKey(composer: string | null | undefined): string {
	const words = normalizeText(composer).replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ')
	return words[words.length - 1] ?? ''
}

/**
 * Copies already in the catalog that may be the piece being recorded (round
 * 3, November 2026): the same title, or one title containing the other
 * (from five letters on), with the composer's surname agreeing where both
 * are given. Only a hint; the librarian decides. At most `limit`, those
 * with the same title first.
 *
 * @param entries - the catalog
 * @param title - title typed
 * @param composer - composer typed
 * @param limit - most hits
 */
export function similarEntries(entries: Entry[], title: string, composer: string, limit = 5): Entry[] {
	const wanted = titleKey(title)
	if (wanted.length < 3) {
		return []
	}
	const surname = surnameKey(composer)
	const hits: { entry: Entry, exact: boolean }[] = []
	for (const entry of entries) {
		const key = titleKey(entry.title)
		const exact = key === wanted
		const contains = !exact && Math.min(key.length, wanted.length) >= 5 && (key.includes(wanted) || wanted.includes(key))
		if (!exact && !contains) {
			continue
		}
		const theirs = surnameKey(entry.composer)
		if (surname !== '' && theirs !== '' && theirs !== surname) {
			continue
		}
		hits.push({ entry, exact })
	}
	return hits.sort((a, b) => Number(b.exact) - Number(a.exact)).slice(0, limit).map((hit) => hit.entry)
}
