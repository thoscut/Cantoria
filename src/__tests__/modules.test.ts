/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ModuleInfo, PersonalView } from '../types.ts'

import { describe, expect, it } from 'vitest'
import {
	activeView,
	applyModules,
	cardActive,
	cardShown,
	closestScenario,
	dependentsOf,
	fieldActive,
	HIDEABLE_VIEWS,
	inactiveBecause,
	moduleActive,
	moduleLabels,
	presetChanges,
	requirementsOf,
	stopsWorking,
	switchedOnWith,
	toggleHidden,
	VIEW_MODULES,
	viewActive,
	viewShown,
} from '../modules.ts'
import { parseStartParams } from '../startParams.ts'

const NONE: PersonalView = { navigation: [], cards: [] }
const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345'

/**
 * Every module of the register active, except the ones given.
 *
 * @param off - modules that are switched off
 */
function without(...off: string[]): Record<string, boolean> {
	const all = ['rights', 'valid_version', 'persons', 'protection_terms', 'releases', 'portal', 'print_station', 'publication',
		'score_viewer', 'playback', 'practice_audio', 'excerpt_links', 'tasks', 'proposals', 'concerts', 'performance_import',
		'repertoire', 'calendar', 'accession', 'merge', 'inventory', 'checksums', 'statistics', 'dashboard_widget', 'notifications',
		'transcription_review', 'corrections']
	return Object.fromEntries(all.map((key) => [key, !off.includes(key)]))
}

describe('modules of views and cards', () => {
	it('treats every module as active for an older server without modules', () => {
		expect(moduleActive(null, 'statistics')).toBe(true)
		expect(viewActive('statistics', null)).toBe(true)
	})

	it('treats a module the server does not list as switched off', () => {
		expect(moduleActive({ rights: true }, 'statistics')).toBe(false)
	})

	it('keeps the start page and the catalog: they are core', () => {
		const nothing = {}
		expect(VIEW_MODULES.due).toBeNull()
		expect(viewActive('due', nothing)).toBe(true)
		expect(viewActive('catalog', nothing)).toBe(true)
		expect(viewShown('due', nothing, { navigation: ['due', 'catalog'], cards: [] })).toBe(true)
		expect(viewShown('catalog', nothing, { navigation: ['catalog'], cards: [] })).toBe(true)
	})

	it('hides the views of switched-off modules', () => {
		const modules = without('concerts', 'statistics', 'print_station')
		expect(viewShown('concerts', modules, NONE)).toBe(false)
		expect(viewShown('songLists', modules, NONE)).toBe(false)
		expect(viewShown('event', modules, NONE)).toBe(false)
		expect(viewShown('statistics', modules, NONE)).toBe(false)
		expect(viewShown('printStation', modules, NONE)).toBe(false)
		expect(viewShown('portal', modules, NONE)).toBe(true)
		expect(viewShown('tasks', modules, NONE)).toBe(true)
	})

	it('hides the views the person has hidden, but links still open them', () => {
		const modules = without()
		const personal = { navigation: ['statistics', 'merges'], cards: [] }
		expect(viewShown('statistics', modules, personal)).toBe(false)
		expect(viewShown('merges', modules, personal)).toBe(false)
		expect(viewShown('inventory', modules, personal)).toBe(true)
		expect(viewActive('statistics', modules)).toBe(true)
		expect(applyModules(parseStartParams('?view=statistics'), modules)).toEqual({
			params: parseStartParams('?view=statistics'),
			switchedOff: false,
		})
	})

	it('maps every view that can be hidden to a module', () => {
		for (const view of HIDEABLE_VIEWS) {
			expect(VIEW_MODULES[view], view).not.toBeNull()
		}
	})

	it('hides the cards of switched-off modules and those the person has hidden', () => {
		const modules = without('proposals', 'concerts', 'performance_import')
		expect(cardActive('proposals', modules)).toBe(false)
		expect(cardActive('ambiguous_items', modules)).toBe(false)
		expect(cardActive('concerts', modules)).toBe(false)
		expect(cardActive('tasks_open', modules)).toBe(true)
		expect(cardShown('tasks_open', modules, { navigation: [], cards: ['tasks_open'] })).toBe(false)
		expect(cardShown('licenses_expiring', modules, { navigation: [], cards: ['tasks_open'] })).toBe(true)
		// unknown cards come only from active modules (the server leaves the others out)
		expect(cardActive('something_new', modules)).toBe(true)
	})

	it('hides the publication status without the module "publication"', () => {
		expect(fieldActive('publication_status', without('publication'))).toBe(false)
		expect(fieldActive('publication_reason', without('publication'))).toBe(false)
		expect(fieldActive('rights_status', without('publication', 'rights'))).toBe(true)
		expect(fieldActive('publication_status', without())).toBe(true)
	})

	it('leaves a view of a module switched off while it is shown', () => {
		expect(activeView('statistics', without('statistics'))).toBe('due')
		expect(activeView('statistics', without())).toBe('statistics')
	})

	it('adds and removes names of the personal view', () => {
		expect(toggleHidden(['a'], 'b', false)).toEqual(['a', 'b'])
		expect(toggleHidden(['a', 'b'], 'a', true)).toEqual(['b'])
		expect(toggleHidden(['a'], 'a', false)).toEqual(['a'])
	})
})

describe('links from the URL with switched-off modules', () => {
	it('follows everything while the modules are on', () => {
		const params = parseStartParams(`?copy=3&task=4&link=${TOKEN}`)
		expect(applyModules(params, without())).toEqual({ params, switchedOff: false })
	})

	it('leads ?view= of a switched-off module to the start page', () => {
		const result = applyModules(parseStartParams('?view=statistics'), without('statistics'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.view).toBeNull()
	})

	it('leads ?event= without concerts to the start page', () => {
		const result = applyModules(parseStartParams('?event=12'), without('concerts'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.view).toBeNull()
		expect(result.params.eventId).toBeNull()
	})

	it('opens the copy without the review when the review of transcriptions is off', () => {
		const on = applyModules(parseStartParams('?copy=3&review=1'), without())
		expect(on).toEqual({ params: parseStartParams('?copy=3&review=1'), switchedOff: false })
		const result = applyModules(parseStartParams('?copy=3&review=1'), without('transcription_review'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.review).toBe(false)
		expect(result.params.copyId).toBe(3)
	})

	it('ignores ?link= without excerpt links', () => {
		const result = applyModules(parseStartParams(`?link=${TOKEN}`), without('excerpt_links'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.linkToken).toBeNull()
	})

	it('ignores ?link= when the score view is off (inactive excerpt links)', () => {
		// The server reports excerpt_links inactive when score_viewer is off
		const result = applyModules(parseStartParams(`?link=${TOKEN}`), { ...without('score_viewer'), excerpt_links: false })
		expect(result.switchedOff).toBe(true)
	})

	it('leads ?due= of a card of a switched-off module to the start page', () => {
		const result = applyModules(parseStartParams('?due=proposals'), without('proposals'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.dueKey).toBeNull()
		expect(result.params.view).toBeNull()
		const portal = applyModules(parseStartParams('?due=portal_remove'), without('portal'))
		expect(portal.switchedOff).toBe(true)
		const valid = applyModules(parseStartParams('?due=valid_missing'), without('valid_version'))
		expect(valid.switchedOff).toBe(true)
		expect(valid.params.dueKey).toBeNull()
	})

	it('keeps ?due= of an active card', () => {
		const result = applyModules(parseStartParams('?due=tasks_open'), without('proposals'))
		expect(result.switchedOff).toBe(false)
		expect(result.params.dueKey).toBe('tasks_open')
		expect(result.params.view).toBe('tasks')
	})

	it('opens the copy of ?task= without tasks, but not the task', () => {
		const result = applyModules(parseStartParams('?copy=3&task=4'), without('tasks'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.copyId).toBe(3)
		expect(result.params.taskId).toBeNull()
	})

	it('opens the copy of ?score=1 without the score view, but not the score view', () => {
		const result = applyModules(parseStartParams('?copy=3&score=1'), without('score_viewer'))
		expect(result.switchedOff).toBe(true)
		expect(result.params.copyId).toBe(3)
		expect(result.params.score).toBe(false)
		expect(applyModules(parseStartParams('?copy=3&score=1'), without()).params.score).toBe(true)
	})

	it('opens a copy: the catalog is core', () => {
		const result = applyModules(parseStartParams('?copy=3'), {})
		expect(result).toEqual({ params: parseStartParams('?copy=3'), switchedOff: false })
	})
})

/**
 * A module of the settings.
 *
 * @param key - module key
 * @param requires - direct requirements
 * @param enabled - own switch
 * @param active - whether it works
 */
function module(key: string, requires: string[] = [], enabled = true, active = enabled): ModuleInfo {
	return { key, group: 'g', label: key.toUpperCase(), description: '', requires, enabled, active }
}

describe('module settings', () => {
	const modules = [
		module('score_viewer'),
		module('playback', ['score_viewer']),
		module('practice_audio', ['playback'], false),
		module('excerpt_links', ['score_viewer']),
		module('concerts'),
		module('calendar', ['concerts']),
	]

	it('resolves requirements transitively, nearest first', () => {
		expect(requirementsOf('practice_audio', modules)).toEqual(['playback', 'score_viewer'])
		expect(requirementsOf('score_viewer', modules)).toEqual([])
	})

	it('finds the modules that need a module, also indirectly', () => {
		expect(dependentsOf('score_viewer', modules).map((m) => m.key)).toEqual(['playback', 'practice_audio', 'excerpt_links'])
	})

	it('lists what stops working when a module is switched off: active dependents only', () => {
		expect(stopsWorking('score_viewer', modules).map((m) => m.key)).toEqual(['playback', 'excerpt_links'])
		expect(stopsWorking('calendar', modules)).toEqual([])
	})

	it('lists the requirements that are switched on with a module', () => {
		const off = [module('concerts', [], false), module('calendar', ['concerts'], false), module('repertoire', ['concerts'], true, false)]
		expect(switchedOnWith('calendar', off).map((m) => m.key)).toEqual(['concerts'])
		expect(switchedOnWith('concerts', off)).toEqual([])
	})

	it('explains why a module whose switch is on does not work', () => {
		const off = [module('concerts', [], false), module('repertoire', ['concerts'], true, false), module('calendar', ['concerts'], false)]
		expect(inactiveBecause(off[1], off).map((m) => m.key)).toEqual(['concerts'])
		// switched off itself: no explanation needed
		expect(inactiveBecause(off[2], off)).toEqual([])
		expect(inactiveBecause(modules[0], modules)).toEqual([])
	})

	it('compares a preset with the current switches', () => {
		const diff = presetChanges({ score_viewer: true, playback: true, practice_audio: true, excerpt_links: false, concerts: false, calendar: true }, modules)
		expect(diff.on.map((m) => m.key)).toEqual(['practice_audio'])
		expect(diff.off.map((m) => m.key)).toEqual(['excerpt_links', 'concerts'])
	})

	it('finds the scenario the switches come closest to', () => {
		const scenario = (key: string, values: Record<string, boolean>) => ({ key, label: key, description: '', rights: null, modules: values })
		const narrow = scenario('archive', { score_viewer: true, concerts: false })
		const wide = scenario('concerts', { score_viewer: true, concerts: true, calendar: true })
		const closest = closestScenario([narrow, wide], modules)
		expect(closest?.scenario.key).toBe(modules.find((m) => m.key === 'concerts')?.enabled ? 'concerts' : 'archive')
		const exact = closestScenario([scenario('now', Object.fromEntries(modules.map((m) => [m.key, m.enabled]))), wide], modules)
		expect(exact?.scenario.key).toBe('now')
		expect(exact?.differences).toEqual([])
		expect(closestScenario([], modules)).toBeNull()
	})

	it('names modules in the order of the settings', () => {
		expect(moduleLabels(['calendar', 'score_viewer'], modules)).toEqual(['SCORE_VIEWER', 'CALENDAR'])
	})
})
