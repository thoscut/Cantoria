/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	accessRows,
	accessStatus,
	accountsWithoutAccess,
	catalogRoleEmpty,
	needsAction,
	normalizeAccessReport,
	normalizeGroups,
	normalizeSettings,
	parseThresholds,
	neededRoles,
	roleChanges,
	setupSteps,
	WIZARD_STEPS,
	wizardOpensByItself,
	wizardStartStep,
	wizardStepKeys,
	wizardStepOf,
} from '../settings.ts'

/** An access check as the server sends it (invented accounts and groups). */
const REPORT = {
	archive: { id: 4711, path: '/Demo archive', user: 'demo-librarian' },
	self: { uid: 'demo-admin', displayName: 'Demo Administrator', canOpen: false },
	roles: [
		{
			role: 'librarian',
			groups: [{ id: 'archive', displayName: 'Archive team', exists: true, members: 2, checked: 2, canOpen: 2, cannotOpen: [], notChecked: 0 }],
		},
		{ role: 'operations', groups: [] },
		{
			role: 'conductor',
			groups: [
				{
					id: 'conductors',
					displayName: 'Conductors',
					exists: true,
					members: 2,
					checked: 2,
					canOpen: 1,
					cannotOpen: [{ uid: 'demo-conductor', displayName: 'Demo Conductor' }],
					notChecked: 0,
				},
				{ id: 'old', displayName: 'old', exists: false, members: 0, checked: 0, canOpen: 0, cannotOpen: [], notChecked: 0 },
			],
		},
		{ role: 'contributor', groups: [] },
		{
			role: 'reader',
			groups: [
				{
					id: 'board',
					displayName: 'Board',
					exists: true,
					members: 3,
					checked: 2,
					canOpen: 0,
					cannotOpen: [{ uid: 'demo-reader', displayName: 'Demo Reader' }, { uid: 'demo-conductor', displayName: 'Demo Conductor' }],
					notChecked: 1,
				},
				{ id: 'new', displayName: 'New members', exists: true, members: 1, checked: 0, canOpen: 0, cannotOpen: [], notChecked: 1 },
				{ id: 'nobody', displayName: 'Nobody', exists: true, members: 0, checked: 0, canOpen: 0, cannotOpen: [], notChecked: 0 },
			],
		},
	],
	adminsWithoutRole: { members: 1, checked: 1, canOpen: 0, cannotOpen: [{ uid: 'demo-admin', displayName: 'Demo Administrator' }], notChecked: 0 },
	checked: 5,
	limit: 500,
	complete: true,
}

describe('settings of the app', () => {
	it('takes the settings in a predictable shape', () => {
		expect(normalizeSettings({
			roles: { librarian: ['archive', 7], reader: [] },
			groupNames: { archive: 'Archive team', 7: 7 },
			groupsMissing: ['old', 3],
			roleLabels: { librarian: 'Catalog (music librarian)', reader: null },
			archive: { id: 4711, path: '/Demo archive', user: 'demo-librarian' },
			backups: { path: 'Music archive backups' },
			repertoire: { current: 3, dormant: 10 },
			catalog: { copies: 23, files: 30 },
			modulesChosen: 'yes',
			concertFolder: true,
			memberLinks: 'yes',
			folderBook: true,
			printMirror: 'yes',
			admin: true,
			catalogEmpty: false,
		})).toEqual({
			roles: { librarian: ['archive'], reader: [] },
			groupNames: { archive: 'Archive team' },
			groupsMissing: ['old'],
			roleLabels: { librarian: 'Catalog (music librarian)' },
			archive: { id: 4711, path: '/Demo archive', user: 'demo-librarian' },
			backups: { path: 'Music archive backups' },
			repertoire: { current: 3, dormant: 10 },
			catalog: { copies: 23, files: 30 },
			modulesChosen: false,
			concertFolder: true,
			memberLinks: false,
			folderBook: true,
			printMirror: false,
			choirHandover: false,
			admin: true,
			catalogEmpty: false,
		})
	})

	it('copes with empty maps sent as lists and missing parts', () => {
		// PHP sends an empty map as []; a delegated person gets no catalogEmpty
		expect(normalizeSettings({ roles: [], groupNames: [], roleLabels: [], archive: [], repertoire: null, admin: false, catalogEmpty: null })).toEqual({
			roles: {},
			groupNames: {},
			groupsMissing: [],
			roleLabels: {},
			archive: { id: 0, path: '', user: '' },
			backups: { path: null },
			repertoire: null,
			catalog: { copies: 0, files: 0 },
			modulesChosen: false,
			concertFolder: false,
			memberLinks: false,
			folderBook: false,
			printMirror: false,
			choirHandover: false,
			admin: false,
			catalogEmpty: null,
		})
		expect(normalizeSettings(null).archive.id).toBe(0)
	})

	it('takes the groups found for a role', () => {
		expect(normalizeGroups([{ id: 'board', displayName: 'Board' }, { id: 'singers' }, { displayName: 'no id' }, 'x'])).toEqual([
			{ id: 'board', displayName: 'Board' },
			{ id: 'singers', displayName: 'singers' },
		])
		expect(normalizeGroups({ error: 'no' })).toEqual([])
	})
})

describe('access check of the archive folder', () => {
	const report = normalizeAccessReport(REPORT)

	it('keeps the report as sent', () => {
		expect(report.self).toEqual({ uid: 'demo-admin', displayName: 'Demo Administrator', canOpen: false })
		expect(report.roles.map((role) => role.role)).toEqual(['librarian', 'operations', 'conductor', 'contributor', 'reader'])
		expect(report.adminsWithoutRole.cannotOpen).toEqual([{ uid: 'demo-admin', displayName: 'Demo Administrator' }])
		expect(report).toMatchObject({ checked: 5, limit: 500, complete: true })
	})

	it('gives every group of a role a status that does not depend on colour', () => {
		expect(accessRows(report).map((row) => [row.role, row.id, row.status])).toEqual([
			['librarian', 'archive', 'ok'],
			['conductor', 'conductors', 'missing'],
			['conductor', 'old', 'gone'],
			['reader', 'board', 'missing'],
			['reader', 'new', 'unchecked'],
			['reader', 'nobody', 'empty'],
		])
	})

	it('names every account without access once, sorted by name', () => {
		expect(accountsWithoutAccess(report)).toEqual([
			{ uid: 'demo-conductor', displayName: 'Demo Conductor' },
			{ uid: 'demo-reader', displayName: 'Demo Reader' },
		])
	})

	it('asks for action when someone cannot open the folder or a group is gone', () => {
		expect(needsAction(report)).toBe(true)
		const fine = normalizeAccessReport({ ...REPORT, roles: [REPORT.roles[0]] })
		expect(needsAction(fine)).toBe(false)
		const gone = normalizeAccessReport({ ...REPORT, roles: [REPORT.roles[2]] })
		expect(accessRows(gone).map((row) => row.status)).toEqual(['missing', 'gone'])
	})

	it('rates the admins without a role like a group', () => {
		expect(accessStatus(report.adminsWithoutRole)).toBe('missing')
		expect(accessStatus({ members: 0, checked: 0, canOpen: 0, cannotOpen: [], notChecked: 0 })).toBe('empty')
		expect(accessStatus({ members: 1, checked: 1, canOpen: 1, cannotOpen: [], notChecked: 0 })).toBe('ok')
	})

	it('copes with a broken answer', () => {
		const empty = normalizeAccessReport({ roles: [{ role: 'reader', groups: [{ id: 'x', cannotOpen: [{ displayName: 'no uid' }] }] }] })
		expect(empty.self).toEqual({ uid: '', displayName: '', canOpen: false })
		expect(empty.roles[0]!.groups[0]).toEqual({ id: 'x', displayName: 'x', exists: true, members: 0, checked: 0, canOpen: 0, cannotOpen: [], notChecked: 0 })
		expect(empty.complete).toBe(true)
		expect(normalizeAccessReport({ complete: false }).complete).toBe(false)
	})
})

describe('repertoire thresholds', () => {
	it('accepts whole years with "dormant" at least as long as "current"', () => {
		expect(parseThresholds('3', '10')).toEqual({ current: 3, dormant: 10 })
		expect(parseThresholds(' 5 ', '5')).toEqual({ current: 5, dormant: 5 })
	})

	it('refuses everything else', () => {
		for (const [current, dormant] of [['0', '10'], ['3', '2'], ['2.5', '10'], ['', '10'], ['3', ''], ['51', '60'], ['3', '101'], ['-1', '3'], ['3a', '10']]) {
			expect(parseThresholds(current!, dormant!)).toBeNull()
		}
	})
})

describe('setup in six steps', () => {
	const fresh = normalizeSettings({ roles: {}, archive: { id: 0 } })

	/**
	 * Status per step.
	 *
	 * @param settings - raw settings
	 * @param report - access check, if it ran
	 */
	function statuses(settings: Record<string, unknown>, report: unknown = null): Record<string, string> {
		const steps = setupSteps(normalizeSettings(settings), report === null ? null : normalizeAccessReport(report))
		return Object.fromEntries(steps.map((step) => [step.key, step.status]))
	}

	it('starts with everything missing, in the order of the setup', () => {
		// The functions come before the roles: they decide which roles need a group (U31)
		expect(setupSteps(fresh, null).map((step) => step.key)).toEqual(['archive', 'modules', 'roles', 'access', 'import', 'fileSync'])
		expect(statuses({})).toEqual({ archive: 'missing', roles: 'missing', access: 'missing', modules: 'missing', import: 'missing', fileSync: 'missing' })
	})

	it('derives every step from what the server reports', () => {
		const settings = { archive: { id: 4711 }, roles: { librarian: ['archive'] }, catalog: { copies: 23, files: 30 }, modulesChosen: true }
		expect(statuses(settings)).toEqual({ archive: 'done', roles: 'done', access: 'unchecked', modules: 'done', import: 'done', fileSync: 'done' })
		expect(statuses(settings, { ...REPORT, roles: [REPORT.roles[0]] }).access).toBe('done')
		expect(statuses(settings, REPORT).access).toBe('problem')
	})

	it('needs a group for the catalog role and flags groups that no longer exist', () => {
		expect(statuses({ archive: { id: 1 }, roles: { reader: ['board'] } }).roles).toBe('missing')
		expect(statuses({ archive: { id: 1 }, roles: { librarian: ['archive', 'old'] }, groupsMissing: ['old'] }).roles).toBe('problem')
	})

	it('needs a group for the roles of the chosen functions', () => {
		const roles = (settings: Record<string, unknown>) => setupSteps(normalizeSettings({ archive: { id: 1 }, ...settings }), null)
			.find((step) => step.key === 'roles')!
		// Only the archive: the catalog role is enough
		expect(roles({ roles: { librarian: ['archive'] } })).toMatchObject({ status: 'done', rolesWithoutGroup: [] })
		// The concert folder needs the conductor, the sharing with the choir the choir members
		expect(roles({ roles: { librarian: ['archive'] }, concertFolder: true })).toMatchObject({ status: 'missing', rolesWithoutGroup: ['conductor'] })
		expect(roles({ roles: { librarian: ['archive'], conductor: ['conductors'] }, concertFolder: true, memberLinks: true }))
			.toMatchObject({ status: 'missing', rolesWithoutGroup: ['member'] })
		expect(roles({ roles: { conductor: ['conductors'] }, memberLinks: true })).toMatchObject({ status: 'missing', rolesWithoutGroup: ['librarian', 'member'] })
		expect(roles({ roles: { librarian: ['archive'], conductor: ['conductors'], member: ['singers'] }, concertFolder: true, memberLinks: true }))
			.toMatchObject({ status: 'done', rolesWithoutGroup: [] })
		expect(neededRoles(normalizeSettings({ memberLinks: true }))).toEqual(['librarian', 'member'])
	})
})

describe('guided setup', () => {
	/**
	 * Page the guided setup starts on.
	 *
	 * @param raw - raw settings
	 * @param report - access check, if it ran
	 */
	function start(raw: Record<string, unknown>, report: unknown = null): string {
		const settings = normalizeSettings(raw)
		return wizardStartStep(settings, setupSteps(settings, report === null ? null : normalizeAccessReport(report)))
	}

	const ready = { archive: { id: 4711 }, roles: { librarian: ['archive'] }, modulesChosen: true }

	it('opens by itself only on an untouched instance that was not put off in this browser', () => {
		const fresh = normalizeSettings({ archive: { id: 0 } })
		expect(wizardOpensByItself(fresh, null, false)).toBe(true)
		expect(wizardOpensByItself(fresh, 'later', false)).toBe(false)
		expect(wizardOpensByItself(fresh, 'done', false)).toBe(false)
		// Something was set up already: the list "Setting up" is enough
		expect(wizardOpensByItself(normalizeSettings({ archive: { id: 1 } }), null, false)).toBe(false)
		expect(wizardOpensByItself(normalizeSettings({ modulesChosen: true }), null, false)).toBe(false)
		expect(wizardOpensByItself(normalizeSettings({ catalog: { copies: 3 } }), null, false)).toBe(false)
		// The link from the app always opens it
		expect(wizardOpensByItself(normalizeSettings(ready), 'done', true)).toBe(true)
	})

	it('starts with the welcome, then on the first step that is not done', () => {
		expect(start({})).toBe('welcome')
		expect(start({ modulesChosen: true })).toBe('archive')
		expect(start({ archive: { id: 1 } })).toBe('modules')
		expect(start({ archive: { id: 1 }, modulesChosen: true, concertFolder: true, roles: { librarian: ['archive'] } })).toBe('roles')
		// The access check is unknown until it runs: no reason to start there, a problem is
		expect(start(ready)).toBe('catalog')
		expect(start(ready, REPORT)).toBe('access')
		expect(start({ ...ready, catalog: { copies: 2, files: 0 } })).toBe('catalog')
		expect(start({ ...ready, catalog: { copies: 2, files: 5 } })).toBe('done')
	})

	it('has a page for every setup step', () => {
		expect(WIZARD_STEPS).toEqual(['welcome', 'archive', 'modules', 'roles', 'access', 'catalog', 'done'])
		const covered = WIZARD_STEPS.flatMap((page) => wizardStepKeys(page))
		expect(covered).toEqual(setupSteps(normalizeSettings({}), null).map((step) => step.key))
		for (const key of covered) {
			expect(wizardStepKeys(wizardStepOf(key))).toContain(key)
		}
	})
})

describe('changing the roles', () => {
	it('says which groups get or lose a role before saving', () => {
		expect(roleChanges(
			{ librarian: ['archive'], reader: ['board', 'singers'], conductor: [] },
			{ librarian: ['archive'], reader: ['board'], conductor: ['conductors'] },
		)).toEqual([
			{ role: 'reader', added: [], removed: ['singers'] },
			{ role: 'conductor', added: ['conductors'], removed: [] },
		])
		expect(roleChanges({ librarian: ['archive'] }, { librarian: ['archive'] })).toEqual([])
	})

	it('warns when nobody would keep the catalog', () => {
		expect(catalogRoleEmpty({ librarian: [], reader: ['board'] })).toBe(true)
		expect(catalogRoleEmpty({ reader: ['board'] })).toBe(true)
		expect(catalogRoleEmpty({ librarian: ['archive'] })).toBe(false)
	})
})
