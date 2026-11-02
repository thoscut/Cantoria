/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Entry, Filters } from '../types.ts'

import { describe, expect, it } from 'vitest'
import {
	canonicalShelfmark,
	DEFAULT_FILTERS,
	EMPTY_CONTEXT,
	filterEntries,
	activeFilterCount,
	hasActiveFilters,
	isEntryConfirmed,
	normalizeText,
	parseShelfmark,
	rightsLevel,
	searchText,
	songListCount,
	songListNames,
	sortEntries,
	worksWithValidVersion,
} from '../catalog.ts'

/**
 * A catalog row with defaults.
 *
 * @param values - fields that differ from the defaults
 */
function entry(values: Partial<Entry>): Entry {
	return {
		id: 1,
		shelfmark: '1-1',
		binder: 1,
		number: 1,
		status: 'active',
		title: 'Title',
		composer: null,
		work_no: null,
		arranger_text: null,
		rights_status: null,
		rights_confirmed: null,
		preservation_copy: false,
		publication: null,
		valid_since: null,
		repertoire: null,
		last_performed: null,
		has_musicxml: false,
		files: 0,
		pdfs: 0,
		work_id: 1,
		version_id: 1,
		...values,
	}
}

/**
 * Filters with defaults.
 *
 * @param values - filters that differ from the defaults
 */
function filters(values: Partial<Filters>): Filters {
	return { ...DEFAULT_FILTERS, ...values }
}

const ENTRIES: Entry[] = [
	entry({ id: 1, shelfmark: '5-1', binder: 5, number: 1, title: 'Freude, schöner Götterfunken', composer: 'Beethoven', rights_status: 'public_domain', work_id: 10, version_id: 100, repertoire_class: 'current', has_musicxml: true }),
	entry({ id: 2, shelfmark: '2-35', binder: 2, number: 35, title: 'Abschied vom Walde', composer: 'Mendelssohn', rights_status: 'public_domain', work_id: 20, version_id: 200, repertoire_class: 'current', valid_since: '2026-01-01' }),
	entry({ id: 3, shelfmark: '6-8', binder: 6, number: 8, title: 'Tischlied', composer: 'Zelter', rights_status: 'protected', status: 'retired', work_id: 30, version_id: 300, repertoire_class: 'earlier' }),
	entry({ id: 4, shelfmark: '1-23', binder: 1, number: 23, title: 'Tischlied', composer: 'Zelter', rights_status: 'open', work_id: 30, version_id: 301, preservation_copy: true, repertoire_class: 'dormant' }),
]

const INDEX = new Map(ENTRIES.map((item) => [item.id, searchText(item)]))

/**
 * Ids of the matching entries.
 *
 * @param values - filters
 * @param context - confirmations and valid versions
 */
function ids(values: Partial<Filters>, context = EMPTY_CONTEXT): number[] {
	return filterEntries(ENTRIES, filters(values), INDEX, context).map((item) => item.id)
}

describe('shelf marks', () => {
	it('parses typed shelf marks', () => {
		expect(parseShelfmark('5-1')).toBe('5-1')
		expect(parseShelfmark(' 5 - 01 ')).toBe('5-1')
		expect(parseShelfmark('5/1')).toBe('5-1')
		expect(parseShelfmark('Tischlied')).toBeNull()
	})

	it('canonicalizes stored shelf marks', () => {
		expect(canonicalShelfmark('6-08')).toBe('6-8')
		expect(canonicalShelfmark('X')).toBe('X')
	})
})

describe('search', () => {
	it('ignores case and diacritics', () => {
		expect(normalizeText('Götterfunken')).toBe('gotterfunken')
		expect(ids({ search: 'schoner gotterfunken' })).toEqual([1])
	})

	it('needs every word', () => {
		expect(ids({ search: 'tischlied zelter' })).toEqual([4])
	})

	it('finds a retired copy by its shelf mark', () => {
		expect(ids({ search: '6-08' })).toEqual([3])
	})
})

describe('filterEntries', () => {
	it('shows active copies by default', () => {
		expect(ids({})).toEqual([1, 2, 4])
		expect(ids({ status: 'all' })).toEqual([1, 2, 3, 4])
	})

	it('filters by repertoire class', () => {
		expect(ids({ repertoire: 'current' })).toEqual([1, 2])
		expect(ids({ repertoire: 'dormant' })).toEqual([4])
	})

	it('filters public domain versions without confirmation', () => {
		expect(ids({ unconfirmed: true })).toEqual([1, 2])
		const confirmed = ENTRIES.map((item) => (item.id === 1 ? { ...item, confirmed_at: '2026-09-01' } : item))
		expect(filterEntries(confirmed, filters({ unconfirmed: true }), INDEX).map((item) => item.id)).toEqual([2])
	})

	it('filters works without a valid version', () => {
		const context = { ...EMPTY_CONTEXT, worksWithValidVersion: worksWithValidVersion(ENTRIES) }
		expect(ids({ noValidVersion: true }, context)).toEqual([1, 4])
	})

	it('filters copies with open review points of a transcription', () => {
		const withReview = ENTRIES.map((item) => (item.id === 2 ? { ...item, review_open: 3 } : (item.id === 1 ? { ...item, review_open: 0, review_orders: 2 } : item)))
		expect(filterEntries(withReview, filters({ reviewOpen: true }), INDEX).map((item) => item.id)).toEqual([2])
		// review switched off: the server sends null
		expect(filterEntries(ENTRIES.map((item) => ({ ...item, review_open: null })), filters({ reviewOpen: true }), INDEX)).toEqual([])
	})

	it('combines filters', () => {
		expect(ids({ repertoire: 'current', musicxml: true })).toEqual([1])
		expect(ids({ preservationCopy: true, binder: 1 })).toEqual([4])
		expect(ids({ rightsStatus: 'protected', status: 'all' })).toEqual([3])
	})
})

describe('hasActiveFilters', () => {
	it('is false for the defaults', () => {
		expect(hasActiveFilters(filters({}))).toBe(false)
	})

	it('notices the new filters', () => {
		expect(hasActiveFilters(filters({ unconfirmed: true }))).toBe(true)
		expect(hasActiveFilters(filters({ noValidVersion: true }))).toBe(true)
		expect(hasActiveFilters(filters({ reviewOpen: true }))).toBe(true)
	})
})

describe('activeFilterCount', () => {
	it('counts the filters besides the search', () => {
		expect(activeFilterCount(filters({}))).toBe(0)
		expect(activeFilterCount(filters({ search: 'Mond' }))).toBe(0)
		expect(activeFilterCount(filters({ unconfirmed: true, binder: 2, musicxml: true }))).toBe(3)
	})
})

describe('sortEntries', () => {
	it('keeps the catalog order', () => {
		expect(sortEntries(ENTRIES, 'catalog')).toBe(ENTRIES)
	})

	it('sorts by binder and number', () => {
		expect(sortEntries(ENTRIES, 'shelfmark').map((item) => item.shelfmark)).toEqual(['1-23', '2-35', '5-1', '6-8'])
	})

	it('sorts by title, then shelf mark', () => {
		expect(sortEntries(ENTRIES, 'title').map((item) => item.id)).toEqual([2, 1, 4, 3])
	})
})

describe('confirmation and rights indicator', () => {
	it('takes a confirmation in the app or from the old catalog', () => {
		expect(isEntryConfirmed(ENTRIES[0])).toBe(false)
		expect(isEntryConfirmed(entry({ confirmed_at: '2026-09-01' }))).toBe(true)
		expect(isEntryConfirmed(entry({ confirmed_at: null }))).toBe(false)
		expect(isEntryConfirmed(entry({ rights_confirmed: '2025-03-01 Notenwart' }))).toBe(true)
	})

	it('derives the level', () => {
		expect(rightsLevel('public_domain', null, false)).toBe('unconfirmed')
		expect(rightsLevel('public_domain', true, false)).toBe('confirmed')
		expect(rightsLevel('protected', null, false)).toBe('protected')
		expect(rightsLevel('open', null, false)).toBe('open')
		expect(rightsLevel(null, null, false)).toBe('unknown')
		expect(rightsLevel('public_domain', true, true)).toBe('preservation')
	})
})

describe('song lists of an entry', () => {
	it('counts the names the server sends', () => {
		expect(songListCount(entry({ song_lists: ['2018-Advent', '2025/1'] }))).toBe(2)
		expect(songListNames(entry({ song_lists: ['2018-Advent', ''] }))).toEqual(['2018-Advent'])
	})

	it('treats an empty list and missing values as none', () => {
		expect(songListCount(entry({ song_lists: [] }))).toBe(0)
		expect(songListCount(entry({ song_lists: null }))).toBe(0)
		expect(songListCount(entry({}))).toBe(0)
	})

	it('accepts a count from an older server', () => {
		expect(songListCount(entry({ song_lists: 3 }))).toBe(3)
		expect(songListNames(entry({ song_lists: 3 }))).toEqual([])
	})
})
