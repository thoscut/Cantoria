/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The two sizes of screen the score views know (the score view of the
 * archive, the view of a shared piece, the lyrics and the pitch display in
 * their bar): one limit for a low screen and one for a narrow one, the same
 * everywhere, so that no state between two limits is left untested. The
 * styles of the components use the same media queries word for word; a
 * unit test compares them (src/__tests__/screen.test.ts).
 */

/** A phone, upright or held sideways: the settings fold away, the bar keeps to part of the height */
export const SMALL_SCREEN = '(max-width: 600px), (max-height: 560px)'

/** Wide and high enough: the settings are open, and the score has a box of its own */
export const LARGE_SCREEN = '(min-width: 1024px) and (min-height: 561px)'

/**
 * Whether a media query applies; false where the browser cannot tell.
 *
 * @param query - SMALL_SCREEN or LARGE_SCREEN
 */
export function screenIs(query: string): boolean {
	return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(query).matches
}
