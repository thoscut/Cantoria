/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where a card of the start page leads: a view and the filters to set
 * there. Pure function, tested in tests/dueTargets.test.ts.
 */
import type { ViewName } from './startParams.ts'
import type { Filters, ReleaseStatus } from './types.ts'

export interface DueTarget {
	view: ViewName
	/** catalog filters (the others are reset) */
	catalog?: Partial<Filters>
	/** task list: which status to show */
	taskStatus?: '' | 'open' | 'answered'
	/** release list: status to show first */
	releaseStatus?: ReleaseStatus
}

/**
 * @param key - card key from GET /api/due
 */
export function dueTarget(key: string): DueTarget {
	switch (key) {
	case 'tasks_open':
		return { view: 'tasks', taskStatus: 'open' }
	case 'tasks_answered':
		return { view: 'tasks', taskStatus: 'answered' }
	case 'proposals':
		return { view: 'proposals' }
	case 'portal_remove':
		return { view: 'portal', releaseStatus: 'remove' }
	case 'portal_add':
		return { view: 'portal', releaseStatus: 'add' }
	case 'ambiguous_items':
		return { view: 'ambiguous' }
	case 'confirm_current':
		return { view: 'catalog', catalog: { repertoire: 'current', rightsStatus: 'public_domain', unconfirmed: true } }
	case 'rule1_current':
		return { view: 'catalog', catalog: { repertoire: 'current', preservationCopy: true } }
	case 'valid_missing':
		return { view: 'catalog', catalog: { repertoire: 'current', noValidVersion: true } }
	case 'licenses_expiring':
		return { view: 'licenses' }
	case 'hired_return':
		return { view: 'catalog', catalog: { hiredReturn: true } }
	case 'copies_unlicensed':
		return { view: 'catalog', catalog: { repertoire: 'current', copiesUnlicensed: true } }
	default:
		return { view: 'catalog' }
	}
}
