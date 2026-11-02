/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers for the catalog list: search, filters, sorting and the
 * derived rights indicator. No Vue, no server access, easy to test.
 */
import type { Entry, FilterContext, Filters, SortOrder } from './types.ts'

/**
 * Lower case, without diacritics, so "schoner" also finds "schöner".
 *
 * @param text - any text
 */
export function normalizeText(text: string | null | undefined): string {
	return (text ?? '')
		.normalize('NFD')
		.replace(/\p{Diacritic}/gu, '')
		.toLowerCase()
		.trim()
}

/**
 * Shelf mark typed by a user ("5-1", "5 - 01", "5/1") in canonical form
 * ("5-1"), or null if the input is not a shelf mark.
 *
 * @param input - search input
 */
export function parseShelfmark(input: string): string | null {
	const match = input.trim().match(/^(\d{1,3})\s*[-/.]\s*(\d{1,4})$/)
	if (!match) {
		return null
	}
	return `${Number.parseInt(match[1], 10)}-${Number.parseInt(match[2], 10)}`
}

/**
 * Canonical form of a shelf mark delivered by the server ("6-08" => "6-8").
 *
 * @param shelfmark - shelf mark as stored
 */
export function canonicalShelfmark(shelfmark: string): string {
	return parseShelfmark(shelfmark) ?? shelfmark.trim()
}

/**
 * Text that the free-text search looks at, prepared once per entry.
 *
 * @param entry - catalog entry
 */
export function searchText(entry: Entry): string {
	return normalizeText(`${entry.title ?? ''} ${entry.composer ?? ''}`)
}

export const DEFAULT_FILTERS: Readonly<Filters> = Object.freeze({
	search: '',
	binder: null,
	rightsStatus: null,
	repertoire: null,
	musicxml: false,
	preservationCopy: false,
	status: 'active',
	unconfirmed: false,
	noValidVersion: false,
	reviewOpen: false,
	copiesUnlicensed: false,
})

/**
 * Number of active filters among those under "More filters" (all but the
 * search, the rights status and the repertoire), so that the collapsed
 * section still says that it filters.
 *
 * @param filters - current filters
 */
export function moreFiltersActive(filters: Filters): number {
	return [
		filters.binder !== null,
		filters.status !== DEFAULT_FILTERS.status,
		filters.musicxml,
		filters.preservationCopy,
		filters.unconfirmed,
		filters.noValidVersion,
		filters.reviewOpen,
		filters.copiesUnlicensed,
	].filter(Boolean).length
}

/** Repertoire classes in display order. */
export const REPERTOIRE_CLASSES = ['current', 'dormant', 'earlier', 'none'] as const

/**
 * Repertoire class of an entry: derived by the server; 'none' if missing.
 *
 * @param entry - catalog entry
 */
export function repertoireClass(entry: Entry): string {
	return entry.repertoire_class ?? 'none'
}

/**
 * Ids of the works that have a version valid for the choir.
 *
 * @param entries - all entries
 */
export function worksWithValidVersion(entries: Entry[]): Set<number> {
	const works = new Set<number>()
	for (const entry of entries) {
		if (entry.work_id !== null && entry.work_id !== undefined && entry.valid_since) {
			works.add(entry.work_id)
		}
	}
	return works
}

/** A work with several versions and none valid for the choir. */
export interface OpenChoice {
	workId: number
	title: string
	composer: string
	repertoireClass: string
	/** the versions with their copies, ordered by the first shelf mark */
	versions: { versionId: number, copies: Entry[] }[]
}

/**
 * The works the conductor still has to choose a valid version for: more
 * than one version, none valid. The works of the current repertoire come
 * first, then by title.
 *
 * @param entries - all entries
 */
export function openChoices(entries: Entry[]): OpenChoice[] {
	const valid = worksWithValidVersion(entries)
	const works = new Map<number, OpenChoice>()
	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
	for (const entry of [...entries].sort((a, b) => collator.compare(a.shelfmark, b.shelfmark))) {
		if (entry.work_id === null || entry.work_id === undefined || entry.version_id === null || entry.version_id === undefined || valid.has(entry.work_id)) {
			continue
		}
		let work = works.get(entry.work_id)
		if (!work) {
			work = { workId: entry.work_id, title: entry.title ?? '', composer: entry.composer ?? '', repertoireClass: repertoireClass(entry), versions: [] }
			works.set(entry.work_id, work)
		}
		const versionId = entry.version_id
		let version = work.versions.find((candidate) => candidate.versionId === versionId)
		if (!version) {
			version = { versionId, copies: [] }
			work.versions.push(version)
		}
		version.copies.push(entry)
	}
	return [...works.values()]
		.filter((work) => work.versions.length > 1)
		.sort((a, b) => Number(b.repertoireClass === 'current') - Number(a.repertoireClass === 'current') || collator.compare(a.title, b.title))
}

/** Context without any knowledge, e.g. for tests. */
export const EMPTY_CONTEXT: FilterContext = Object.freeze({
	worksWithValidVersion: new Set<number>(),
})

/**
 * Whether the rights of the entry's version are confirmed: by a confirmation
 * in the app (confirmed_at), or by the confirmation text taken over from the
 * old catalog.
 *
 * @param entry - catalog entry
 */
export function isEntryConfirmed(entry: Entry): boolean {
	return (typeof entry.confirmed_at === 'string' && entry.confirmed_at !== '')
		|| isConfirmed(entry.rights_confirmed)
}

/**
 * Names of the song lists an entry is on. The server sends the names; a
 * count from an older server gives no names.
 *
 * @param entry - catalog entry
 */
export function songListNames(entry: Pick<Entry, 'song_lists'>): string[] {
	return Array.isArray(entry.song_lists)
		? entry.song_lists.filter((name): name is string => typeof name === 'string' && name !== '')
		: []
}

/**
 * Number of song lists an entry is on (0 when there are none).
 *
 * @param entry - catalog entry
 */
export function songListCount(entry: Pick<Entry, 'song_lists'>): number {
	if (Array.isArray(entry.song_lists)) {
		return songListNames(entry).length
	}
	return typeof entry.song_lists === 'number' && entry.song_lists > 0 ? entry.song_lists : 0
}

/**
 * Whether any filter differs from the defaults.
 *
 * @param filters - current filters
 */
export function hasActiveFilters(filters: Filters): boolean {
	return filters.search.trim() !== ''
		|| filters.binder !== null
		|| filters.rightsStatus !== null
		|| filters.repertoire !== null
		|| filters.musicxml
		|| filters.preservationCopy
		|| filters.status !== DEFAULT_FILTERS.status
		|| filters.unconfirmed
		|| filters.noValidVersion
		|| filters.reviewOpen
		|| filters.copiesUnlicensed
}

/**
 * How many filters besides the search are set: the number on the button
 * that opens the filters on a phone.
 *
 * @param filters - current filters
 */
export function activeFilterCount(filters: Filters): number {
	return [
		filters.binder !== null,
		filters.rightsStatus !== null,
		filters.repertoire !== null,
		filters.musicxml,
		filters.preservationCopy,
		filters.status !== DEFAULT_FILTERS.status,
		filters.unconfirmed,
		filters.noValidVersion,
		filters.reviewOpen,
		filters.copiesUnlicensed,
	].filter(Boolean).length
}

/**
 * Apply search and filters.
 *
 * A shelf mark in the search field matches exactly (and ignores the status
 * filter, so a retired copy can still be found by its shelf mark). Any other
 * input matches entries whose title or composer contain every word.
 *
 * @param entries - all entries in catalog order
 * @param filters - current filters
 * @param index - prepared search texts by entry id
 * @param context - confirmations and valid versions
 */
export function filterEntries(entries: Entry[], filters: Filters, index: Map<number, string>, context: FilterContext = EMPTY_CONTEXT): Entry[] {
	const shelfmark = parseShelfmark(filters.search)
	const words = shelfmark === null ? normalizeText(filters.search).split(/\s+/).filter((word) => word !== '') : []

	return entries.filter((entry) => {
		if (shelfmark !== null) {
			if (canonicalShelfmark(entry.shelfmark) !== shelfmark) {
				return false
			}
		} else {
			if (filters.status !== 'all' && entry.status !== filters.status) {
				return false
			}
			if (words.length > 0) {
				const text = index.get(entry.id) ?? searchText(entry)
				if (!words.every((word) => text.includes(word))) {
					return false
				}
			}
		}
		if (filters.binder !== null && entry.binder !== filters.binder) {
			return false
		}
		if (filters.rightsStatus !== null && entry.rights_status !== filters.rightsStatus) {
			return false
		}
		if (filters.repertoire !== null && repertoireClass(entry) !== filters.repertoire) {
			return false
		}
		if (filters.musicxml && !entry.has_musicxml) {
			return false
		}
		if (filters.preservationCopy && !entry.preservation_copy) {
			return false
		}
		if (filters.unconfirmed && (entry.rights_status !== 'public_domain' || isEntryConfirmed(entry))) {
			return false
		}
		if (filters.noValidVersion && (entry.work_id === null || entry.work_id === undefined || context.worksWithValidVersion.has(entry.work_id))) {
			return false
		}
		if (filters.reviewOpen && !((entry.review_open ?? 0) > 0)) {
			return false
		}
		if (filters.copiesUnlicensed && entry.copies_unlicensed !== true) {
			return false
		}
		return true
	})
}

/**
 * Binder and number of an entry, taken from the shelf mark if missing.
 *
 * @param entry - catalog entry
 */
function shelfmarkParts(entry: Entry): [number, number] {
	if (entry.binder !== null && entry.number !== null) {
		return [entry.binder, entry.number]
	}
	const canonical = parseShelfmark(entry.shelfmark)
	if (canonical === null) {
		return [Number.MAX_SAFE_INTEGER, Number.MAX_SAFE_INTEGER]
	}
	const [binder, number] = canonical.split('-').map((part) => Number.parseInt(part, 10))
	return [binder, number]
}

const titleCollator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true })

/**
 * Sort entries. 'catalog' keeps the order delivered by the server (the
 * order of the catalog), the others return a sorted copy.
 *
 * @param entries - filtered entries in catalog order
 * @param order - sort order
 */
export function sortEntries(entries: Entry[], order: SortOrder): Entry[] {
	if (order === 'catalog') {
		return entries
	}
	const sorted = [...entries]
	if (order === 'shelfmark') {
		sorted.sort((a, b) => {
			const [binderA, numberA] = shelfmarkParts(a)
			const [binderB, numberB] = shelfmarkParts(b)
			return binderA - binderB || numberA - numberB
		})
	} else {
		sorted.sort((a, b) => titleCollator.compare(a.title ?? '', b.title ?? '')
			|| titleCollator.compare(a.shelfmark, b.shelfmark))
	}
	return sorted
}

/**
 * rights_confirmed may be a text (who/when) or a flag; anything non-empty counts.
 *
 * @param value - rights_confirmed of the version
 */
function isConfirmed(value: unknown): boolean {
	if (typeof value === 'string') {
		return value.trim() !== ''
	}
	return value === true || (typeof value === 'number' && value !== 0)
}

/** Level of the rights indicator; decides the color. */
export type RightsLevel = 'preservation' | 'protected' | 'open' | 'unconfirmed' | 'confirmed' | 'unknown'

/**
 * Derive the rights indicator. Display only: it never decides anything.
 *
 * @param rightsStatus - rights_status key of the version
 * @param rightsConfirmed - rights_confirmed of the version (empty = not confirmed)
 * @param preservationCopy - whether the version is an internal preservation copy
 */
export function rightsLevel(rightsStatus: unknown, rightsConfirmed: unknown, preservationCopy: unknown): RightsLevel {
	if (preservationCopy === true || preservationCopy === 1 || preservationCopy === '1') {
		return 'preservation'
	}
	switch (rightsStatus) {
	case 'protected':
		return 'protected'
	case 'open':
		return 'open'
	case 'public_domain':
		return isConfirmed(rightsConfirmed) ? 'confirmed' : 'unconfirmed'
	default:
		return 'unknown'
	}
}
