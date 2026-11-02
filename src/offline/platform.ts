/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * On an iPhone or iPad a page opened from the home screen has its own
 * storage and its own sign-in, apart from Safari (milestone 0.19): pieces
 * kept in Safari are not there. The pages say so before the first piece is
 * kept, and only in Safari itself.
 */

/**
 * Whether this is Safari on an iPhone or iPad, not the page opened from the
 * home screen.
 *
 * @param nav - the browser's navigator (for tests)
 * @param standalone - the page runs from the home screen (for tests)
 */
export function homeScreenAdvice(nav: Pick<Navigator, 'userAgent' | 'platform' | 'maxTouchPoints'> | undefined = globalThis.navigator,
	standalone: boolean = runsStandalone()): boolean {
	if (nav === undefined || standalone) {
		return false
	}
	// an iPad asks for desktop pages and calls itself a Mac with a touch screen
	return /iPhone|iPad|iPod/.test(nav.userAgent) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1)
}

/** Whether the page runs from the home screen. */
function runsStandalone(): boolean {
	const nav = globalThis.navigator as (Navigator & { standalone?: boolean }) | undefined
	return nav?.standalone === true || (typeof window !== 'undefined' && window.matchMedia?.('(display-mode: standalone)').matches === true)
}
