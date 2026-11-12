/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ChoirEvent, EventItem } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { nextConcert, readiness } from '../nextConcert.ts'

/**
 * @param id - id of the concert
 * @param date - its date
 * @param status - its status
 */
function concert(id: number, date: string | null, status: ChoirEvent['status'] = 'draft'): ChoirEvent {
	return { id, kind: 'concert', title: `Concert ${id}`, event_date: date, status } as ChoirEvent
}

describe('next concert of the start page', () => {
	it('is the earliest planned concert from today on', () => {
		const next = nextConcert([concert(1, '2026-10-01'), concert(2, '2026-12-24'), concert(3, '2026-12-12', 'fixed'), concert(4, null)], '2026-11-12')
		expect(next?.id).toBe(3)
		expect(nextConcert([concert(5, '2026-11-12')], '2026-11-12')?.id).toBe(5)
	})

	it('leaves out sung and cancelled concerts', () => {
		expect(nextConcert([concert(1, '2026-12-01', 'sung'), concert(2, '2026-12-02', 'cancelled')], '2026-11-12')).toBeNull()
	})

	it('counts as ready what is in the archive and has no warning; struck pieces do not count', () => {
		const items = [
			{ copy_id: 1, warnings: [] },
			{ copy_id: 2 },
			{ copy_id: 3, warnings: ['Rights not clarified.'] },
			{ copy_id: null, warnings: [] },
			{ copy_id: 4, struck: true, warnings: ['Rights not clarified.'] },
		] as unknown as EventItem[]
		expect(readiness(items)).toEqual({ pieces: 4, ready: 2 })
		expect(readiness([])).toEqual({ pieces: 0, ready: 0 })
	})
})
