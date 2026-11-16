// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The manifest for the home screen (src/offline/manifest.ts, milestone
 * 0.19, U25): the concert folder and a public link point the page to a
 * manifest of their own, so that the home screen opens them and not the
 * start page of the app.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { manifestUrl, useOwnManifest } from '../offline/manifest.ts'

const TOKEN = 'a'.repeat(32)

describe('own manifest for the home screen', () => {
	beforeEach(() => {
		document.head.innerHTML = ''
	})

	it('names the manifest of the folder and of a link, nothing for another address', () => {
		expect(manifestUrl('folder', '/apps/cantoria/concert-folder')).toMatch(/\/apps\/cantoria\/concert-folder\/manifest$/)
		expect(manifestUrl('public', `/index.php/apps/cantoria/p/${TOKEN}`)).toMatch(new RegExp(`/apps/cantoria/p/${TOKEN}/manifest$`))
		expect(manifestUrl('public', '/apps/cantoria/p/short')).toBeNull()
		expect(manifestUrl('public', `/apps/cantoria/p/${TOKEN}/scan`)).toBeNull()
	})

	it('replaces the manifest of Nextcloud', () => {
		document.head.innerHTML = '<link rel="manifest" href="/apps/theming/manifest/cantoria" crossorigin="use-credentials">'
		useOwnManifest('folder')
		const links = document.head.querySelectorAll<HTMLLinkElement>('link[rel="manifest"]')
		expect(links).toHaveLength(1)
		expect(links[0].getAttribute('href')).toMatch(/concert-folder\/manifest$/)
		expect(links[0].crossOrigin).toBe('use-credentials')
	})

	it('adds a manifest where there is none', () => {
		useOwnManifest('folder')
		expect(document.head.querySelector('link[rel="manifest"]')?.getAttribute('href')).toMatch(/concert-folder\/manifest$/)
	})
})
