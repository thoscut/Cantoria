/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure logic of the settings page (Administration → Cantoria): the
 * answers of the server in a predictable shape, the steps of the setup, the
 * rows of the access check, what a change of the roles does and the check of
 * the repertoire thresholds. No requests, no texts.
 */
import type { AccessAccount, AccessGroup, AccessMembers, AccessReport, ArchiveInfo, GroupOption, RepertoireThresholds, Settings } from './types.ts'

/**
 * Ids of the sections of the settings page; the app links to them, e.g.
 * `adminSettingsUrl(SETTINGS_SECTIONS.access)` opens the access check.
 */
export const SETTINGS_SECTIONS = {
	setup: 'cantoria-setup',
	archive: 'cantoria-archive-folder',
	roles: 'cantoria-roles',
	access: 'cantoria-access',
	modules: 'cantoria-modules',
	repertoire: 'cantoria-repertoire',
	folderBook: 'cantoria-folder-book',
	printMirror: 'cantoria-print-mirror',
	choirHandover: 'cantoria-choir-handover',
	backup: 'cantoria-backup',
} as const

/** The role that imports and keeps the catalog (see docs/api.md). */
const CATALOG_ROLE = 'librarian'

type Raw = Record<string, unknown>

/**
 * An object, or an empty one.
 *
 * @param value - anything
 */
function object(value: unknown): Raw {
	return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Raw : {}
}

/**
 * A list, or an empty one.
 *
 * @param value - anything
 */
function list(value: unknown): unknown[] {
	return Array.isArray(value) ? value : []
}

/**
 * A whole number of at least 0.
 *
 * @param value - anything
 */
function count(value: unknown): number {
	return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : 0
}

/**
 * A string, or ''.
 *
 * @param value - anything
 */
function text(value: unknown): string {
	return typeof value === 'string' ? value : ''
}

/**
 * A map of strings; other values are left out.
 *
 * @param value - anything
 */
function strings(value: unknown): Record<string, string> {
	return Object.fromEntries(Object.entries(object(value)).filter((entry): entry is [string, string] => typeof entry[1] === 'string'))
}

/**
 * Groups found by `GET /api/settings/groups`.
 *
 * @param data - response of the server
 */
export function normalizeGroups(data: unknown): GroupOption[] {
	return list(data).map(object)
		.filter((group) => typeof group.id === 'string' && group.id !== '')
		.map((group) => ({ id: text(group.id), displayName: text(group.displayName) || text(group.id) }))
}

/**
 * The archive folder as sent by the server.
 *
 * @param value - `archive` of a response
 */
function archive(value: unknown): ArchiveInfo {
	const raw = object(value)
	return { id: count(raw.id), path: text(raw.path), user: text(raw.user) }
}

/**
 * `GET /api/settings` in a predictable shape (PHP sends empty maps as `[]`).
 *
 * @param data - response of the server
 */
export function normalizeSettings(data: unknown): Settings {
	const raw = object(data)
	const roles: Record<string, string[]> = {}
	for (const [role, groups] of Object.entries(object(raw.roles))) {
		roles[role] = list(groups).filter((group): group is string => typeof group === 'string')
	}
	const roleLabels = strings(raw.roleLabels)
	const thresholds = object(raw.repertoire)
	const catalog = object(raw.catalog)
	const backups = object(raw.backups)
	return {
		roles,
		groupNames: strings(raw.groupNames),
		groupsMissing: list(raw.groupsMissing).filter((group): group is string => typeof group === 'string'),
		roleLabels,
		archive: archive(raw.archive),
		backups: { path: typeof backups.path === 'string' ? backups.path : null },
		repertoire: raw.repertoire === null || raw.repertoire === undefined
			? null
			: { current: count(thresholds.current), dormant: count(thresholds.dormant) },
		catalog: { copies: count(catalog.copies), files: count(catalog.files) },
		modulesChosen: raw.modulesChosen === true,
		folderBook: raw.folderBook === true,
		printMirror: raw.printMirror === true,
		choirHandover: raw.choirHandover === true,
		admin: raw.admin === true,
		catalogEmpty: typeof raw.catalogEmpty === 'boolean' ? raw.catalogEmpty : null,
	}
}

/**
 * Accounts of the access check.
 *
 * @param value - list from the server
 */
function accounts(value: unknown): AccessAccount[] {
	return list(value).map(object)
		.filter((account) => typeof account.uid === 'string')
		.map((account) => ({ uid: text(account.uid), displayName: text(account.displayName) || text(account.uid) }))
}

/**
 * Figures of a group or of the admins without a role.
 *
 * @param value - object from the server
 */
function members(value: unknown): AccessMembers {
	const raw = object(value)
	return {
		members: count(raw.members),
		checked: count(raw.checked),
		canOpen: count(raw.canOpen),
		cannotOpen: accounts(raw.cannotOpen),
		notChecked: count(raw.notChecked),
	}
}

/**
 * `GET /api/settings/access` in a predictable shape.
 *
 * @param data - response of the server
 */
export function normalizeAccessReport(data: unknown): AccessReport {
	const raw = object(data)
	const self = object(raw.self)
	return {
		archive: archive(raw.archive),
		self: { uid: text(self.uid), displayName: text(self.displayName) || text(self.uid), canOpen: self.canOpen === true },
		roles: list(raw.roles).map(object).map((role) => ({
			role: text(role.role),
			groups: list(role.groups).map(object).map((group): AccessGroup => ({
				id: text(group.id),
				displayName: text(group.displayName) || text(group.id),
				exists: group.exists !== false,
				...members(group),
			})),
		})),
		adminsWithoutRole: members(raw.adminsWithoutRole),
		checked: count(raw.checked),
		limit: count(raw.limit),
		complete: raw.complete !== false,
	}
}

/**
 * How a group stands, for its row: every checked member can open the
 * folder (`ok`), some cannot (`missing`), nobody could be checked
 * (`unchecked`), the group has no members (`empty`) or no longer exists
 * (`gone`). The table shows it as text, never by colour alone.
 */
export type AccessStatus = 'ok' | 'missing' | 'unchecked' | 'empty' | 'gone'

/** One row of the access check: a group of a role. */
export interface AccessRow extends AccessGroup {
	/** role key */
	role: string
	status: AccessStatus
}

/**
 * The status of a group or of the admins without a role.
 *
 * @param figures - figures of the group
 * @param exists - whether the group exists
 */
export function accessStatus(figures: AccessMembers, exists = true): AccessStatus {
	if (!exists) {
		return 'gone'
	}
	if (figures.cannotOpen.length > 0) {
		return 'missing'
	}
	if (figures.members === 0) {
		return 'empty'
	}
	return figures.checked === 0 ? 'unchecked' : 'ok'
}

/**
 * The rows of the access check: one per group of a role, in the order of
 * the roles. A group with several roles appears once per role.
 *
 * @param report - the access check
 */
export function accessRows(report: AccessReport): AccessRow[] {
	return report.roles.flatMap((role) => role.groups.map((group) => ({ ...group, role: role.role, status: accessStatus(group, group.exists) })))
}

/**
 * Accounts of the role groups that cannot open the archive folder, each
 * once (an account may be in several groups), sorted by name.
 *
 * @param report - the access check
 */
export function accountsWithoutAccess(report: AccessReport): AccessAccount[] {
	const byUid = new Map<string, AccessAccount>()
	for (const role of report.roles) {
		for (const group of role.groups) {
			for (const account of group.cannotOpen) {
				byUid.set(account.uid, account)
			}
		}
	}
	return [...byUid.values()].sort((a, b) => a.displayName.localeCompare(b.displayName, undefined, { sensitivity: 'base' }) || a.uid.localeCompare(b.uid))
}

/**
 * Whether the role groups need attention: someone cannot open the folder or
 * a group no longer exists.
 *
 * @param report - the access check
 */
export function needsAction(report: AccessReport): boolean {
	return accessRows(report).some((row) => row.status === 'missing' || row.status === 'gone')
}

/**
 * Problems of the repertoire thresholds the form shows before saving:
 * whole years, "current" at least 1 and at most 50, "dormant" at least as
 * long as "current" and at most 100 (the server clamps to the same).
 *
 * @param current - years for "current", as typed
 * @param dormant - years for "dormant", as typed
 * @return the thresholds, or null when they are not valid
 */
export function parseThresholds(current: string, dormant: string): RepertoireThresholds | null {
	const values = [current, dormant].map((value) => (/^\s*\d+\s*$/.test(value) ? Number(value) : Number.NaN))
	const [currentYears, dormantYears] = values as [number, number]
	if (!Number.isInteger(currentYears) || !Number.isInteger(dormantYears)
		|| currentYears < 1 || currentYears > 50 || dormantYears < currentYears || dormantYears > 100) {
		return null
	}
	return { current: currentYears, dormant: dormantYears }
}

/** State of a setup step, shown as text and icon (never by colour alone). */
export type StepStatus = 'done' | 'missing' | 'problem' | 'unchecked'

/** The steps of the setup, in the order in which they are done. */
export type StepKey = 'archive' | 'roles' | 'access' | 'modules' | 'import' | 'fileSync'

export interface SetupStep {
	key: StepKey
	status: StepStatus
}

/**
 * The setup in six steps, derived from what the server already reports:
 * archive folder, the groups of the catalog role, the access check (only
 * known after it ran on this page), whether the functions for the choir
 * were chosen, imported copies and files found by the file sync. Only the
 * choice of the functions is stored, as a flag.
 *
 * @param settings - settings of the server
 * @param report - result of the access check of the saved archive folder, if it ran
 */
export function setupSteps(settings: Settings, report: AccessReport | null): SetupStep[] {
	const hasArchive = settings.archive.id > 0
	let roles: StepStatus = (settings.roles[CATALOG_ROLE] ?? []).length > 0 ? 'done' : 'missing'
	if (settings.groupsMissing.length > 0) {
		roles = 'problem'
	}
	let access: StepStatus = 'unchecked'
	if (!hasArchive) {
		access = 'missing'
	} else if (report !== null) {
		access = needsAction(report) ? 'problem' : 'done'
	}
	return [
		{ key: 'archive', status: hasArchive ? 'done' : 'missing' },
		{ key: 'roles', status: roles },
		{ key: 'access', status: access },
		{ key: 'modules', status: settings.modulesChosen === true ? 'done' : 'missing' },
		{ key: 'import', status: settings.catalog.copies > 0 ? 'done' : 'missing' },
		{ key: 'fileSync', status: settings.catalog.files > 0 ? 'done' : 'missing' },
	]
}

/** What saving the roles would change for one role. */
export interface RoleChange {
	role: string
	/** group ids that get the role */
	added: string[]
	/** group ids that lose it */
	removed: string[]
}

/**
 * The difference between the saved role groups and the form, in the order
 * of the roles; roles without a change are left out.
 *
 * @param saved - role => group ids as saved
 * @param draft - role => group ids in the form
 */
export function roleChanges(saved: Record<string, string[]>, draft: Record<string, string[]>): RoleChange[] {
	const roles = [...new Set([...Object.keys(saved), ...Object.keys(draft)])]
	return roles.map((role) => {
		const before = saved[role] ?? []
		const after = draft[role] ?? []
		return {
			role,
			added: after.filter((group) => !before.includes(group)),
			removed: before.filter((group) => !after.includes(group)),
		}
	}).filter((change) => change.added.length > 0 || change.removed.length > 0)
}

/**
 * Whether the form leaves the catalog role without a group: then nobody can
 * import or keep the catalog.
 *
 * @param draft - role => group ids in the form
 */
export function catalogRoleEmpty(draft: Record<string, string[]>): boolean {
	return (draft[CATALOG_ROLE] ?? []).length === 0
}
