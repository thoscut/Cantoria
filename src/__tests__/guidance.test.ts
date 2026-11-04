/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { Entry, FieldInfo } from '../types.ts'

import { getLanguage, setLanguage } from '@nextcloud/l10n'
import { afterEach, describe, expect, it } from 'vitest'
import { DEFAULT_FILTERS, moreFiltersActive, openChoices } from '../catalog.ts'
import { USER_GUIDE, userGuideUrl } from '../docs.ts'
import { displayValue, formatDate } from '../format.ts'
import { helpTerm } from '../helpTerms.ts'
import { VIEW_MODULES } from '../modules.ts'
import { confirmConsequences } from '../rightsHints.ts'
import { buildQuery, parseStartParams } from '../startParams.ts'

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

describe('help at the place', () => {
	const before = getLanguage()

	afterEach(() => {
		setLanguage(before)
	})

	it('explains every term with a sentence and a section of the guide', () => {
		for (const term of ['version', 'copy', 'shelfmark', 'rightsStatus', 'release', 'validVersion', 'preservationCopy', 'revision'] as const) {
			const help = helpTerm(term)
			expect(help.name, term).not.toBe('')
			expect(help.text.length, term).toBeGreaterThan(20)
			expect(Object.values(USER_GUIDE), term).toContain(help.section)
		}
	})

	it('links the German user guide for German and the English one otherwise', () => {
		setLanguage('de')
		expect(userGuideUrl(USER_GUIDE.termRightsStatus)).toBe('https://github.com/thoscut/Cantoria/blob/main/docs/de/benutzerhandbuch.md#rechtestatus-und-best%C3%A4tigung')
		setLanguage('en')
		expect(userGuideUrl(USER_GUIDE.termRightsStatus)).toBe('https://github.com/thoscut/Cantoria/blob/main/docs/user-guide.md#rights-status-and-confirmation')
	})
})

describe('more filters', () => {
	it('counts only the folded filters', () => {
		expect(moreFiltersActive(DEFAULT_FILTERS)).toBe(0)
		expect(moreFiltersActive({ ...DEFAULT_FILTERS, search: 'Tafel', rightsStatus: 'protected', repertoire: 'current' })).toBe(0)
		expect(moreFiltersActive({ ...DEFAULT_FILTERS, binder: 5, status: 'all', noValidVersion: true })).toBe(3)
	})
})

describe('choosing valid versions', () => {
	const entries = [
		// two versions, none valid, earlier repertoire
		entry({ id: 1, shelfmark: '6-8', work_id: 30, version_id: 300, title: 'Tischlied', repertoire_class: 'earlier' }),
		entry({ id: 2, shelfmark: '1-23', work_id: 30, version_id: 301, title: 'Tischlied', repertoire_class: 'earlier' }),
		entry({ id: 3, shelfmark: '1-24', work_id: 30, version_id: 301, title: 'Tischlied', repertoire_class: 'earlier' }),
		// two versions, one valid
		entry({ id: 4, shelfmark: '2-1', work_id: 40, version_id: 400, valid_since: '2026-01-01' }),
		entry({ id: 5, shelfmark: '2-2', work_id: 40, version_id: 401 }),
		// only one version
		entry({ id: 6, shelfmark: '3-1', work_id: 50, version_id: 500 }),
		// two versions, current repertoire
		entry({ id: 7, shelfmark: '4-1', work_id: 60, version_id: 600, title: 'Abendlied', repertoire_class: 'current' }),
		entry({ id: 8, shelfmark: '4-2', work_id: 60, version_id: 601, title: 'Abendlied', repertoire_class: 'current' }),
	]

	it('lists the works with several versions and none valid, the current repertoire first', () => {
		const choices = openChoices(entries)
		expect(choices.map((work) => work.workId)).toEqual([60, 30])
		expect(choices[1]!.versions).toEqual([
			{ versionId: 301, copies: [entries[1], entries[2]] },
			{ versionId: 300, copies: [entries[0]] },
		])
	})

	it('is a view of its own for the conductor, with the module of the valid version', () => {
		expect(VIEW_MODULES.validVersions).toBe('valid_version')
		expect(buildQuery({ view: 'validVersions' })).toBe('?view=valid-versions')
		expect(parseStartParams('?view=valid-versions').view).toBe('validVersions')
	})
})

describe('consequences of confirming the rights', () => {
	const all = (): boolean => true
	const none = (): boolean => false
	/**
	 * What the server names as opened only by confirmed public domain (RightsPolicy::FREE_ONLY_WAYS).
	 *
	 * @param way - key of the way
	 */
	const freeOnly = (way: string): boolean => ['public_device', 'public_print', 'public_repertoire'].includes(way)

	it('names what becomes possible for public domain', () => {
		const [sentence] = confirmConsequences('public_domain', false, all, freeOnly)
		expect(sentence).toContain('singers\' portal')
		expect(sentence).toContain('practice files')
		expect(sentence).toContain('public links')
	})

	it('names only switched-on functions', () => {
		const [sentence] = confirmConsequences('public_domain', false, (module) => module === 'portal', freeOnly)
		expect(sentence).toBe('After the confirmation this becomes possible: singers\' portal.')
		expect(confirmConsequences('public_domain', false, none, freeOnly)).toEqual([])
	})

	it('names every way out, also the newer ones (R B14)', () => {
		const [sentence] = confirmConsequences('public_domain', false, all, freeOnly)
		for (const way of ['concert folder', 'on the device without a network', 'sharing a programme with the choir', 'playback and the scan for choir members',
			'devices of choir members', 'hand-over folder for the choir system (recordings only with a license for passing them on)', 'playback and the scan of public links', 'public links on a device',
			'public repertoire page']) {
			expect(sentence).toContain(way)
		}
	})

	it('names a newer way only with its modules', () => {
		const only = (...modules: string[]) => (module: string) => modules.includes(module)
		expect(confirmConsequences('public_domain', false, only('concert_folder'), freeOnly)[0]).toBe('After the confirmation this becomes possible: printing from the concert folder.')
		expect(confirmConsequences('public_domain', false, only('offline'), freeOnly)).toEqual([])
		expect(confirmConsequences('public_domain', false, only('member_links', 'offline'), freeOnly)[0]).toContain('devices of choir members')
		expect(confirmConsequences('public_domain', false, only('member_links'), freeOnly)[0]).not.toContain('devices')
		expect(confirmConsequences('public_domain', false, only('member_links'), freeOnly)[0]).not.toContain('programme')
		expect(confirmConsequences('public_domain', false, only('choir_handover'), freeOnly)[0]).toContain('hand-over folder')
	})

	it('says that a protected version needs licenses', () => {
		expect(confirmConsequences('protected', false, all, freeOnly)[0]).toContain('valid license')
		expect(confirmConsequences('protected', false, all, freeOnly)[0]).toContain('playback and the scan of public links')
	})

	it('names no license for what only confirmed public domain opens', () => {
		// no license lets a visitor keep a public link, and the repertoire page lists only confirmed public domain
		const [sentence] = confirmConsequences('protected', false, all, freeOnly)
		expect(sentence).not.toContain('public links on a device')
		expect(sentence).not.toContain('printing from public links')
		expect(sentence).not.toContain('repertoire')
		expect(confirmConsequences('protected', false, (module) => module === 'public_repertoire', freeOnly)).toEqual([])
	})

	it('takes from the server which ways only confirmed public domain opens (A23)', () => {
		expect(confirmConsequences('public_domain', false, all, freeOnly)[0]).toContain('printing from public links')
		// a way the server does not name is one a license can open
		expect(confirmConsequences('protected', false, all, none)[0]).toContain('public links on a device')
		expect(confirmConsequences('protected', false, all, (way) => way === 'public_device')[0]).toContain('printing from public links')
	})

	it('says that a preservation copy stays internal', () => {
		expect(confirmConsequences('public_domain', true, all, freeOnly)[0]).toContain('internal preservation copy')
	})
})

describe('dates', () => {
	it('formats a month without a day like the other dates', () => {
		expect(formatDate('2025-12')).not.toBe('2025-12')
		expect(formatDate('2025-12')).toContain('2025')
		expect(formatDate('2025-13')).toBe('2025-13')
		expect(formatDate('around 1990')).toBe('around 1990')
	})

	it('formats a date in a text field like every other date', () => {
		const text: FieldInfo = { type: 'text', label: 'Last performed', write: [] }
		expect(displayValue(text, '2025-12-07')).toBe(formatDate('2025-12-07'))
		expect(displayValue(text, 'op. 50/2')).toBe('op. 50/2')
	})
})
