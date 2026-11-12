/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The next concert of the start page: which one it is, and how many of its
 * pieces are ready.
 */
import type { ChoirEvent, EventItem } from './types.ts'

/**
 * The next planned concert: a draft or fixed programme with a date today or
 * later, the earliest first.
 *
 * @param concerts - all concerts
 * @param today - YYYY-MM-DD
 */
export function nextConcert(concerts: ChoirEvent[], today: string): ChoirEvent | null {
	return concerts
		.filter((concert) => (concert.status === 'draft' || concert.status === 'fixed')
			&& typeof concert.event_date === 'string' && concert.event_date >= today)
		.sort((a, b) => (a.event_date ?? '').localeCompare(b.event_date ?? '') || a.id - b.id)[0] ?? null
}

/**
 * How many pieces of a programme are ready: in the archive (a copy is
 * assigned) and without a warning. Struck pieces do not count.
 *
 * @param items - the items of the programme
 */
export function readiness(items: EventItem[]): { pieces: number, ready: number } {
	const sung = items.filter((item) => item.struck !== true)
	return {
		pieces: sung.length,
		ready: sung.filter((item) => item.copy_id !== null && (item.warnings?.length ?? 0) === 0).length,
	}
}
