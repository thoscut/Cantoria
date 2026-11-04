/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The hint for an iPhone or iPad before the first piece is kept (milestones
 * 0.19 and 0.18): Safari and a page on the home screen keep pieces apart.
 * One text per page, the same at every switch and button that keeps pieces
 * (also "Make all … available", U27):
 * - "Shared with me": the home screen first, then sign in there.
 * - a public link: the home screen first; nobody signs in there.
 * - the concert folder: Nextcloud's home screen entry opens the start page
 *   of the app (the manifest of the theming app, which Cantoria cannot set
 *   per page; U25), and for the conductor that is the archive, which does
 *   not open without a network. So the folder stays in Safari, from a
 *   bookmark, and Safari may delete what a page keeps after some days
 *   without a visit: the hint asks to open the folder once with a network
 *   the day before the concert, which also checks the pieces (U38). The
 *   guides say the same.
 * The hint stands once per page, not at every programme (U38).
 */
import { t } from '../l10n.ts'

/** The page that keeps pieces */
export type KeepingPage = 'member' | 'public' | 'folder'

/**
 * The hint for an iPhone or iPad before the first piece is kept.
 *
 * @param page - the page
 */
export function homeScreenHint(page: KeepingPage): string {
	switch (page) {
	case 'public':
		return t('cantoria', 'On an iPhone or iPad: first add this page to the home screen, open it from there and then make the piece available. Safari and the home screen keep them apart.')
	case 'folder':
		return t('cantoria', 'On an iPhone or iPad: keep the pieces in Safari and open the concert folder there from a bookmark, not from the home screen. Open it once with a network the day before the concert.')
	default:
		return t('cantoria', 'On an iPhone or iPad: first add this page to the home screen, open it from there, sign in and then make the pieces available. Safari and the home screen keep them apart.')
	}
}
