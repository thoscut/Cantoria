/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { Meta } from '../types.ts'

import { afterEach, describe, expect, it } from 'vitest'
import { adminSettingsUrl, ApiError, errorReason, normalizeMeta, REASON_NOT_SHARED } from '../api.ts'
import { dueTarget } from '../dueTargets.ts'
import { archiveNotShared, canChangeSettings, canSeeProposals, isAdmin, meta } from '../store.ts'

/**
 * Meta data of a user with the given roles.
 *
 * @param admin - Nextcloud admin
 * @param roles - roles in the music archive
 */
function user(admin: boolean, roles: string[]): Meta {
	return {
		uid: 'u',
		admin,
		roles,
		roleLabels: {},
		fields: { work: {}, version: {}, copy: {} },
		licenseUses: {},
		freeOnlyWays: [],
		personFields: {},
		contributionRoles: {},
		archive: { id: 0, path: '' },
	} as unknown as Meta
}

afterEach(() => {
	meta.value = null
})

describe('proposals inbox', () => {
	it('is not offered to an admin without a role (the server refuses it)', () => {
		meta.value = user(true, [])
		expect(isAdmin.value).toBe(true)
		expect(canSeeProposals.value).toBe(false)
	})

	it('is offered to the roles that may read proposals, also to admins with such a role', () => {
		for (const role of ['librarian', 'contributor', 'reader']) {
			meta.value = user(false, [role])
			expect(canSeeProposals.value, role).toBe(true)
		}
		meta.value = user(true, ['reader'])
		expect(canSeeProposals.value).toBe(true)
	})

	it('is not offered to roles without proposals', () => {
		for (const role of ['operations', 'conductor']) {
			meta.value = user(false, [role])
			expect(canSeeProposals.value, role).toBe(false)
		}
	})

	it('is where the start page card leads (hidden together with the inbox)', () => {
		expect(dueTarget('proposals').view).toBe('proposals')
	})
})

describe('settings and the archive folder', () => {
	it('offers the settings to whom the server allows them, also without admin rights', () => {
		meta.value = { ...user(false, ['librarian']), canChangeSettings: true }
		expect(canChangeSettings.value).toBe(true)
		meta.value = { ...user(true, []), canChangeSettings: false }
		expect(canChangeSettings.value).toBe(false)
	})

	it('takes the flags from the server, and from an older server the admin flag', () => {
		const older = normalizeMeta(user(true, []))
		expect(older.canChangeSettings).toBe(true)
		expect(older.archiveAccessible).toBeNull()
		const current = normalizeMeta({ ...user(false, ['reader']), canChangeSettings: true, archiveAccessible: false })
		expect(current.canChangeSettings).toBe(true)
		expect(current.archiveAccessible).toBe(false)
	})

	it('says that the archive folder is not shared only when there is one', () => {
		const archive = { id: 7, path: '/Archive', user: 'demo-librarian' }
		meta.value = { ...user(false, ['reader']), archive, archiveAccessible: false }
		expect(archiveNotShared.value).toBe(true)
		meta.value = { ...user(false, ['reader']), archive, archiveAccessible: true }
		expect(archiveNotShared.value).toBe(false)
		meta.value = { ...user(false, ['reader']), archive: { id: 0, path: '', user: '' }, archiveAccessible: null }
		expect(archiveNotShared.value).toBe(false)
	})

	it('links to the access check under Administration', () => {
		expect(adminSettingsUrl('cantoria-access')).toMatch(/\/settings\/admin\/cantoria#cantoria-access$/)
		expect(adminSettingsUrl()).toMatch(/\/settings\/admin\/cantoria$/)
	})

	it('reads the reason of a refused score', () => {
		expect(errorReason(new ApiError('not shared', REASON_NOT_SHARED))).toBe('not_shared')
		expect(errorReason(new ApiError('other'))).toBeNull()
		expect(errorReason(new Error('plain'))).toBeNull()
	})
})
