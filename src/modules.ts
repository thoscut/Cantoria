/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Modules that the administration can switch off (see docs/api.md, "Modules
 * and the personal view") and the personal view of an account: which views
 * and start page cards belong to which module, what a link from the URL may
 * still open, and what the module settings must say before a switch changes.
 * Pure functions, no Vue, easy to test. The server checks everything again.
 */
import type { StartParams, ViewName } from './startParams.ts'
import type { ModuleInfo, ModuleScenario, PersonalView } from './types.ts'

import { dueTarget } from './dueTargets.ts'
import { DEFAULT_VIEW } from './startParams.ts'

/**
 * Active modules (`meta.modules`), or null when the server sends none (an
 * older server: every module counts as active).
 */
export type ModuleStates = Readonly<Record<string, boolean>> | null

/** Name of the concert list of the start page in the personal view. */
export const DUE_CONCERTS = 'concerts'
/** Name of the task list of the start page in the personal view. */
export const DUE_TASKS = 'tasks'

/** The module of each view; null for the core. */
export const VIEW_MODULES: Readonly<Record<ViewName, string | null>> = {
	due: null,
	catalog: null,
	tasks: 'tasks',
	proposals: 'proposals',
	portal: 'portal',
	printStation: 'print_station',
	licenses: 'rights',
	links: 'excerpt_links',
	concerts: 'concerts',
	songLists: 'concerts',
	event: 'concerts',
	ambiguous: 'performance_import',
	persons: 'persons',
	protection: 'protection_terms',
	statistics: 'statistics',
	accession: 'accession',
	merges: 'merge',
	inventory: 'inventory',
	gaps: 'inventory',
	checksums: 'checksums',
	folderBook: 'folder_book',
	snapshots: 'annual_snapshot',
	validVersions: 'valid_version',
}

/** The module of each card and list of the start page. */
export const CARD_MODULES: Readonly<Record<string, string>> = {
	tasks_open: 'tasks',
	tasks_answered: 'tasks',
	ambiguous_items: 'performance_import',
	proposals: 'proposals',
	portal_remove: 'portal',
	portal_add: 'portal',
	confirm_current: 'rights',
	rule1_current: 'rights',
	licenses_expiring: 'rights',
	copies_unlicensed: 'rights',
	valid_missing: 'valid_version',
	[DUE_CONCERTS]: 'concerts',
	[DUE_TASKS]: 'tasks',
}

/** Catalog fields whose maintenance belongs to a module. */
export const FIELD_MODULES: Readonly<Record<string, string>> = {
	publication_status: 'publication',
	publication_reason: 'publication',
	// Work fields of the public repertoire page (milestone 0.7)
	public_repertoire: 'public_repertoire',
	cpdl_url: 'public_repertoire',
	imslp_url: 'public_repertoire',
	society_work_no: 'concerts',
	melody_origin: 'protection_terms',
	text_origin: 'protection_terms',
	scientific_edition: 'protection_terms',
	posthumous_work: 'protection_terms',
	first_published_year: 'protection_terms',
}

/** Views that cannot be hidden in the personal view. */
const ALWAYS_SHOWN: readonly ViewName[] = ['due', 'catalog']

/** Views that the personal view can hide, in the order of the navigation. */
export const HIDEABLE_VIEWS: readonly ViewName[] = [
	'tasks', 'proposals', 'persons', 'protection', 'statistics', 'snapshots', 'portal', 'printStation', 'licenses', 'links',
	'concerts', 'songLists', 'ambiguous', 'accession', 'merges', 'inventory', 'gaps', 'folderBook', 'checksums',
]

/**
 * Cards and lists of the start page that the personal view can hide, in the
 * order of the start page, with the roles the server shows them to (null:
 * every role).
 */
export const HIDEABLE_CARDS: readonly { key: string, roles: readonly string[] | null }[] = [
	{ key: DUE_CONCERTS, roles: ['librarian', 'conductor'] },
	{ key: 'tasks_open', roles: null },
	{ key: 'tasks_answered', roles: ['librarian'] },
	{ key: 'ambiguous_items', roles: ['librarian', 'conductor'] },
	{ key: 'proposals', roles: ['librarian'] },
	{ key: 'portal_remove', roles: ['librarian'] },
	{ key: 'portal_add', roles: ['librarian'] },
	{ key: 'confirm_current', roles: ['librarian'] },
	{ key: 'rule1_current', roles: ['librarian'] },
	{ key: 'licenses_expiring', roles: ['librarian'] },
	{ key: 'copies_unlicensed', roles: ['librarian'] },
	{ key: 'valid_missing', roles: ['conductor'] },
	{ key: DUE_TASKS, roles: null },
]

/**
 * A hidden list with one name added or removed.
 *
 * @param hidden - hidden names
 * @param name - name to change
 * @param shown - whether it is shown now (removed from the list)
 */
export function toggleHidden(hidden: readonly string[], name: string, shown: boolean): string[] {
	const rest = hidden.filter((entry) => entry !== name)
	return shown ? rest : [...rest, name]
}

/**
 * Whether a module is active; null (the core) always is.
 *
 * @param modules - active modules from meta
 * @param key - module key, or null for the core
 */
export function moduleActive(modules: ModuleStates, key: string | null): boolean {
	if (key === null || modules === null) {
		return true
	}
	return modules[key] === true
}

/**
 * Whether the module of a view is active.
 *
 * @param view - the view
 * @param modules - active modules
 */
export function viewActive(view: ViewName, modules: ModuleStates): boolean {
	return moduleActive(modules, VIEW_MODULES[view] ?? null)
}

/**
 * Whether the module of a start page card (or list) is active. Cards this
 * version of the app does not know are shown: the server sends only cards
 * of active modules.
 *
 * @param key - card key, `concerts` or `tasks`
 * @param modules - active modules
 */
export function cardActive(key: string, modules: ModuleStates): boolean {
	return moduleActive(modules, CARD_MODULES[key] ?? null)
}

/**
 * Whether a view has an entry in the navigation: its module is active and
 * the account has not hidden it. The start page and the catalog always do.
 *
 * @param view - the view
 * @param modules - active modules
 * @param personal - the personal view
 */
export function viewShown(view: ViewName, modules: ModuleStates, personal: PersonalView): boolean {
	return viewActive(view, modules) && (ALWAYS_SHOWN.includes(view) || !personal.navigation.includes(view))
}

/**
 * Whether the start page shows a card or list.
 *
 * @param key - card key, `concerts` or `tasks`
 * @param modules - active modules
 * @param personal - the personal view
 */
export function cardShown(key: string, modules: ModuleStates, personal: PersonalView): boolean {
	return cardActive(key, modules) && !personal.cards.includes(key)
}

/**
 * Whether a field of the catalog belongs to an active module (or to none).
 *
 * @param key - field key
 * @param modules - active modules
 */
export function fieldActive(key: string, modules: ModuleStates): boolean {
	return moduleActive(modules, FIELD_MODULES[key] ?? null)
}

/**
 * What a link from the URL may still open. Parts that need a switched-off
 * module are dropped: a view, an event, a card of the dashboard widget lead
 * to the start page, an excerpt link, a task and the review of a
 * transcription are ignored (the copy still opens; the catalog is core), and
 * so is the score view of `?score=1` without the module "score_viewer". The
 * personal view does not count: it only hides navigation entries.
 *
 * @param params - parsed query parameters
 * @param modules - active modules
 * @return the parameters to follow and whether something was switched off
 */
export function applyModules(params: StartParams, modules: ModuleStates): { params: StartParams, switchedOff: boolean } {
	const result: StartParams = { ...params }
	let switchedOff = false
	if (result.dueKey !== null && !cardActive(result.dueKey, modules)) {
		result.dueKey = null
		result.view = null
		switchedOff = true
	} else if (result.dueKey !== null && !viewActive(dueTarget(result.dueKey).view, modules)) {
		result.dueKey = null
		result.view = null
		switchedOff = true
	}
	if (result.view !== null && !viewActive(result.view, modules)) {
		result.view = null
		switchedOff = true
	}
	if (result.view !== 'event') {
		result.eventId = null
	}
	if (result.linkToken !== null && !moduleActive(modules, 'excerpt_links')) {
		result.linkToken = null
		switchedOff = true
	}
	if (result.taskId !== null && !moduleActive(modules, 'tasks')) {
		result.taskId = null
		switchedOff = true
	}
	if (result.review && !moduleActive(modules, 'transcription_review')) {
		result.review = false
		switchedOff = true
	}
	if (result.score && !moduleActive(modules, 'score_viewer')) {
		result.score = false
		switchedOff = true
	}
	return { params: result, switchedOff }
}

/**
 * The view to show for a view that may belong to a switched-off module.
 *
 * @param view - wanted view
 * @param modules - active modules
 */
export function activeView(view: ViewName, modules: ModuleStates): ViewName {
	return viewActive(view, modules) ? view : DEFAULT_VIEW
}

// ---- module settings (admins) --------------------------------------------------

/**
 * Modules a module needs, directly and indirectly, nearest first.
 *
 * @param key - module key
 * @param modules - all modules of the settings
 */
export function requirementsOf(key: string, modules: readonly ModuleInfo[]): string[] {
	const byKey = new Map(modules.map((module) => [module.key, module]))
	const result: string[] = []
	const queue = [...(byKey.get(key)?.requires ?? [])]
	while (queue.length > 0) {
		const next = queue.shift() as string
		if (!result.includes(next)) {
			result.push(next)
			queue.push(...(byKey.get(next)?.requires ?? []))
		}
	}
	return result
}

/**
 * Modules that need a module, directly or indirectly, in the order of the
 * settings.
 *
 * @param key - module key
 * @param modules - all modules of the settings
 */
export function dependentsOf(key: string, modules: readonly ModuleInfo[]): ModuleInfo[] {
	return modules.filter((module) => requirementsOf(module.key, modules).includes(key))
}

/**
 * What stops working when a module is switched off: the active modules
 * that need it. Their own switches stay as they are.
 *
 * @param key - module to switch off
 * @param modules - all modules of the settings
 */
export function stopsWorking(key: string, modules: readonly ModuleInfo[]): ModuleInfo[] {
	return dependentsOf(key, modules).filter((module) => module.active)
}

/**
 * What the server switches on together with a module: its requirements
 * whose switch is off.
 *
 * @param key - module to switch on
 * @param modules - all modules of the settings
 */
export function switchedOnWith(key: string, modules: readonly ModuleInfo[]): ModuleInfo[] {
	const needed = requirementsOf(key, modules)
	return modules.filter((module) => needed.includes(module.key) && !module.enabled)
}

/**
 * Why a module whose switch is on does not work: the modules it needs whose
 * switch is off.
 *
 * @param module - the module
 * @param modules - all modules of the settings
 */
export function inactiveBecause(module: ModuleInfo, modules: readonly ModuleInfo[]): ModuleInfo[] {
	if (!module.enabled || module.active) {
		return []
	}
	return switchedOnWith(module.key, modules)
}

/**
 * The modules a preset switches on and off compared with the current
 * switches.
 *
 * @param preset - module key => on, as the preset sets it
 * @param modules - all modules of the settings
 */
export function presetChanges(preset: Readonly<Record<string, boolean>>, modules: readonly ModuleInfo[]): { on: ModuleInfo[], off: ModuleInfo[] } {
	return {
		on: modules.filter((module) => preset[module.key] === true && !module.enabled),
		off: modules.filter((module) => preset[module.key] === false && module.enabled),
	}
}

/**
 * The scenario the switches match, or come closest to (milestone 0.19):
 * the one with the fewest differences, the first of equals. The
 * differences are the modules whose switch is not as the scenario sets it.
 *
 * @param scenarios - the scenarios, from the narrowest to the widest
 * @param modules - all modules of the settings
 */
export function closestScenario(scenarios: readonly ModuleScenario[], modules: readonly ModuleInfo[]): { scenario: ModuleScenario, differences: ModuleInfo[] } | null {
	let best: { scenario: ModuleScenario, differences: ModuleInfo[] } | null = null
	for (const scenario of scenarios) {
		const { on, off } = presetChanges(scenario.modules, modules)
		const differences = modules.filter((module) => on.includes(module) || off.includes(module))
		if (best === null || differences.length < best.differences.length) {
			best = { scenario, differences }
		}
	}
	return best
}

/**
 * Labels of modules, in the order of the settings.
 *
 * @param keys - module keys
 * @param modules - all modules of the settings
 */
export function moduleLabels(keys: readonly string[], modules: readonly ModuleInfo[]): string[] {
	return modules.filter((module) => keys.includes(module.key)).map((module) => module.label)
}
