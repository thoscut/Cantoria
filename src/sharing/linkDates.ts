/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Days of links (milestone 0.18): how soon a link ends, until when a piece
 * may stay on a device, and which last day sharing a programme gives a link
 * that exists already. Days are ISO dates (YYYY-MM-DD) in local time.
 */

/** A link that ends within this many days is shown as ending soon */
export const SOON_DAYS = 7

/**
 * Days from today until the last day of a link: 0 on its last day,
 * negative once it has ended; null without an end.
 *
 * @param expires - last valid day
 * @param today - today
 */
export function daysLeft(expires: string | null, today: string): number | null {
	if (expires === null || expires === '') {
		return null
	}
	const end = Date.parse(expires + 'T00:00:00Z')
	const now = Date.parse(today + 'T00:00:00Z')
	if (!Number.isFinite(end) || !Number.isFinite(now)) {
		return null
	}
	return Math.round((end - now) / 86_400_000)
}

/**
 * Whether a link ends within SOON_DAYS days (and has not ended yet).
 *
 * @param expires - last valid day
 * @param today - today
 */
export function endsSoon(expires: string | null, today: string): boolean {
	const days = daysLeft(expires, today)
	return days !== null && days >= 0 && days <= SOON_DAYS
}

/**
 * The last day a piece may stay on a device: the end of the link or of the
 * license for a copy, whichever comes first; null when neither ends.
 *
 * @param expires - last valid day of the link
 * @param keepUntil - last day the license allows a copy
 */
export function deviceUntil(expires: string | null, keepUntil: string | null | undefined): string | null {
	if (keepUntil === null || keepUntil === undefined || keepUntil === '') {
		return expires
	}
	return expires === null || keepUntil < expires ? keepUntil : expires
}

/**
 * The last day sharing a programme gives a piece: the chosen day, at most
 * until its license ends, and never earlier than a link it has already.
 *
 * @param chosen - the day chosen in the dialog
 * @param until - last day of the license, null without end
 * @param existing - last day of the link it has already, null for none
 */
export function sharedUntil(chosen: string, until: string | null | undefined, existing: string | null | undefined): string {
	const limited = until && until < chosen ? until : chosen
	return existing && existing > limited ? existing : limited
}
