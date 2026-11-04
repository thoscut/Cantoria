/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { Meta } from '../types.ts'

import { afterEach, describe, expect, it } from 'vitest'
import { normalizeMeta, normalizePersonalView } from '../api.ts'
import {
	canCreateLinks,
	canCreateTasks,
	canSeeProposals,
	isCardShown,
	isFieldActive,
	isModuleActive,
	isViewShown,
	meta,
} from '../store.ts'

/**
 * Meta data of a librarian with the given modules and personal view.
 *
 * @param modules - active modules, or undefined (older server)
 * @param personalView - hidden areas
 */
function librarian(modules?: Record<string, boolean>, personalView?: unknown): Meta {
	return normalizeMeta({
		uid: 'lena',
		admin: false,
		roles: ['librarian'],
		roleLabels: {},
		fields: { work: {}, version: {}, copy: {} },
		licenseUses: {},
		freeOnlyWays: [],
		personFields: {},
		contributionRoles: {},
		archive: { id: 1, path: '/Archiv', user: 'lena' },
		modules,
		personalView,
	} as unknown as Meta)
}

afterEach(() => {
	meta.value = null
})

describe('meta from the server', () => {
	it('counts every module as active when an older server sends none', () => {
		meta.value = librarian()
		expect(meta.value.modules).toBeNull()
		expect(isModuleActive('statistics')).toBe(true)
		expect(meta.value.personalView).toEqual({ navigation: [], cards: [] })
	})

	it('keeps only boolean module states and string names', () => {
		meta.value = librarian({ rights: true, statistics: 'yes' as unknown as boolean }, { navigation: ['statistics', 3], cards: 'x' })
		expect(meta.value.modules).toEqual({ rights: true })
		expect(isModuleActive('statistics')).toBe(false)
		expect(meta.value.personalView).toEqual({ navigation: ['statistics'], cards: [] })
		expect(normalizePersonalView(null)).toEqual({ navigation: [], cards: [] })
	})
})

describe('the app follows the modules', () => {
	it('offers no inbox, tasks or links of switched-off modules', () => {
		meta.value = librarian({ proposals: false, tasks: false, excerpt_links: false })
		expect(canSeeProposals.value).toBe(false)
		expect(canCreateTasks.value).toBe(false)
		expect(canCreateLinks.value).toBe(false)
		meta.value = librarian({ proposals: true, tasks: true, excerpt_links: true })
		expect(canSeeProposals.value).toBe(true)
		expect(canCreateTasks.value).toBe(true)
		expect(canCreateLinks.value).toBe(true)
	})

	it('shows navigation and cards by module and personal view', () => {
		meta.value = librarian({ statistics: true, concerts: false, tasks: true, portal: true }, { navigation: ['statistics'], cards: ['portal_add'] })
		expect(isViewShown('statistics')).toBe(false)
		expect(isViewShown('concerts')).toBe(false)
		expect(isViewShown('tasks')).toBe(true)
		expect(isViewShown('due')).toBe(true)
		expect(isCardShown('portal_add')).toBe(false)
		expect(isCardShown('portal_remove')).toBe(true)
		expect(isCardShown('concerts')).toBe(false)
	})

	it('hides the publication fields without the module', () => {
		meta.value = librarian({ publication: false })
		expect(isFieldActive('publication_status')).toBe(false)
		expect(isFieldActive('title')).toBe(true)
	})
})
