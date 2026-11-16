/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The manifest for the home screen (milestone 0.19, U25). Nextcloud links
 * every page to the manifest of the theming app, which opens the start
 * page of the app: for the conductor the archive, for a visitor of a
 * public link the login. The concert folder and a public link point the
 * link to a manifest of their own (OfflineController::manifestResponse),
 * so that an entry on the home screen opens that page. "Shared with me"
 * is the start page for choir members and keeps Nextcloud's.
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The address of the own manifest of a page, or null for a page that has
 * none.
 *
 * @param page - the page that keeps pieces
 * @param path - the path of the page (window.location.pathname)
 */
export function manifestUrl(page: 'folder' | 'public', path: string): string | null {
	if (page === 'folder') {
		return generateUrl('/apps/cantoria/concert-folder/manifest')
	}
	const token = /\/p\/([A-Za-z0-9]{32})\/?$/.exec(path)?.[1]
	return token ? generateUrl(`/apps/cantoria/p/${token}/manifest`) : null
}

/**
 * Points the page to its own manifest: the link of Nextcloud, or a new one
 * where there is none.
 *
 * @param page - the page that keeps pieces
 * @param doc - the document (for the tests)
 */
export function useOwnManifest(page: 'folder' | 'public', doc: Document = document): void {
	const href = manifestUrl(page, doc.location?.pathname ?? '')
	if (href === null) {
		return
	}
	let link = doc.querySelector<HTMLLinkElement>('link[rel="manifest"]')
	if (!link) {
		link = doc.createElement('link')
		link.rel = 'manifest'
		doc.head.appendChild(link)
	}
	// the folder needs the session; a public link has none, the attribute does no harm there
	link.crossOrigin = 'use-credentials'
	link.href = href
}
