/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { dueTarget } from '../dueTargets.ts'

describe('dueTarget', () => {
	it('leads the task cards to the task list with the right status', () => {
		expect(dueTarget('tasks_open')).toEqual({ view: 'tasks', taskStatus: 'open' })
		expect(dueTarget('tasks_answered')).toEqual({ view: 'tasks', taskStatus: 'answered' })
	})

	it('leads the portal cards to the portal list', () => {
		expect(dueTarget('portal_remove')).toEqual({ view: 'portal', releaseStatus: 'remove' })
		expect(dueTarget('portal_add')).toEqual({ view: 'portal', releaseStatus: 'add' })
	})

	it('filters the catalog for the repertoire cards', () => {
		expect(dueTarget('confirm_current').catalog).toEqual({ repertoire: 'current', rightsStatus: 'public_domain', unconfirmed: true })
		expect(dueTarget('rule1_current').catalog).toEqual({ repertoire: 'current', preservationCopy: true })
		expect(dueTarget('valid_missing').catalog).toEqual({ repertoire: 'current', noValidVersion: true })
	})

	it('leads the license card to the ending licenses', () => {
		expect(dueTarget('licenses_expiring')).toEqual({ view: 'licenses' })
	})

	it('leads the card of the links to the view "Links"', () => {
		expect(dueTarget('links_ending')).toEqual({ view: 'links' })
	})

	it('knows every card key of docs/api.md', () => {
		const keys = ['tasks_open', 'tasks_answered', 'proposals', 'portal_remove', 'portal_add', 'confirm_current',
			'rule1_current', 'licenses_expiring', 'valid_missing', 'ambiguous_items', 'links_ending']
		for (const key of keys) {
			const target = dueTarget(key)
			expect(target.view).toBeTruthy()
			expect(target.view === 'catalog' && target.catalog === undefined).toBe(false)
		}
	})

	it('falls back to the catalog for unknown keys', () => {
		expect(dueTarget('something_new')).toEqual({ view: 'catalog' })
	})
})
