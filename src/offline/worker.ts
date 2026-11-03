/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/// <reference lib="webworker" />

/**
 * The service worker of the pages that keep pieces on a device (milestone
 * 0.15): "Shared with me", the concert folder and the page of a public link.
 * Served by OfflineController::worker, only with the module "offline". It
 * keeps nothing on its own account: only while a page has put pieces on the
 * device (the cache of its collection exists) does it keep the scripts and
 * styles of that page and answer from the device when the network does not
 * answer. With the network, the network always wins and refreshes what is
 * kept, but only while the server's answer says it may stay (header
 * X-Cantoria-Keep, S N5); an answer without it removes the piece from the
 * device. An expired, revoked or locked piece is never answered from the
 * device. It never caches requests that change something, the APIs of
 * Nextcloud, or the pages of the archive.
 * After an update of the app the new worker first loads the scripts of the
 * new version (the list of the build, A1), and only then takes over and
 * drops the scripts of the old one: when the network fails in between, the
 * old worker and the old scripts stay, and the pages still open.
 *
 * Built as a classic script of its own (vite.config.ts), without imports.
 */
import type { Collection, CollectionName } from './rules.ts'

import { CACHE_PREFIX, COLLECTIONS, mayStay, pageCollection, parseCatalog, pieceAddresses, requestKind, SHELL_PREFIX, usable } from './rules.ts'

declare const self: ServiceWorkerGlobalScope
/** The scripts and styles of the pages that keep pieces, relative to the app's path; from the build (vite.config.ts) */
declare const __CANTORIA_SHELL_FILES__: string[]

/** Replaced by the server with the version of the app */
const VERSION = '__CANTORIA_VERSION__'
/** Replaced by the server with the path of the app's files, e.g. /apps/cantoria/ */
const APP_PATH = '__CANTORIA_APP_PATH__' as string
const SHELL_CACHE = SHELL_PREFIX + VERSION
/** How long to wait for the network before answering from the device */
const TIMEOUT_MS = 4000

self.addEventListener('install', (event) => {
	event.waitUntil((async () => {
		// a failure keeps the old worker and the old scripts, and the browser tries again later
		await keepScripts()
		await self.skipWaiting()
	})())
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		// the scripts of an older version of the app are no longer needed
		for (const key of await caches.keys()) {
			if (key.startsWith(SHELL_PREFIX) && key !== SHELL_CACHE) {
				await caches.delete(key)
			}
		}
		await self.clients.claim()
	})())
})

self.addEventListener('fetch', (event) => {
	const request = event.request
	if (request.method !== 'GET' || request.headers.has('range')) {
		return
	}
	const url = new URL(request.url)
	if (url.origin !== self.location.origin) {
		return
	}
	const scope = new URL(self.registration.scope)
	const asked = requestKind(url.pathname, scope.pathname, request.destination, request.mode === 'navigate')
	if (asked.kind === 'other') {
		return
	}
	event.respondWith(answer(event, asked.kind, asked.collection, asked.key ?? '', url, scope))
})

/**
 * The scripts of this version of the app, before the worker takes over:
 * only while a page keeps pieces on this device. The pages add what else
 * they load (keepShell).
 */
async function keepScripts(): Promise<void> {
	const files = typeof __CANTORIA_SHELL_FILES__ === 'undefined' ? [] : __CANTORIA_SHELL_FILES__
	// the placeholder stays, or the server found no path of its own: nothing to keep
	if (!APP_PATH.startsWith('/') || files.length === 0 || (await keptCollections()).length === 0) {
		return
	}
	const shell = await caches.open(SHELL_CACHE)
	await Promise.all(files.map(async (file) => {
		const url = new URL(APP_PATH + file, self.location.origin).href
		if (await shell.match(url, { ignoreSearch: true })) {
			return
		}
		const response = await fetch(url, { credentials: 'same-origin', cache: 'no-cache' })
		if (!response.ok) {
			throw new Error(`Cantoria: ${file} could not be loaded (${response.status})`)
		}
		await shell.put(url, response)
	}))
}

/** The collections with pieces on this device */
async function keptCollections(): Promise<CollectionName[]> {
	const names = new Set(await caches.keys())
	return Object.values(COLLECTIONS).filter((collection) => names.has(collection.cache)).map((collection) => collection.name)
}

/**
 * The answer to a request of a page, its scripts or a kept piece.
 *
 * @param event - the fetch event
 * @param kind - what the request is
 * @param name - the collection of a page, score or scan
 * @param key - token of the link or id of the programme item
 * @param url - address of the request
 * @param scope - scope of the worker
 */
async function answer(event: FetchEvent, kind: 'page' | 'score' | 'scan' | 'asset', name: CollectionName | undefined, key: string,
	url: URL, scope: URL): Promise<Response> {
	const kept = await keptCollections()
	if (kept.length === 0) {
		// nothing kept on this device: as without the worker
		return fetch(event.request)
	}
	if (kind === 'asset') {
		const shell = await caches.open(SHELL_CACHE)
		const forKeptPage = await isForKeptPage(event, scope, kept)
		// the scripts of one version of the app: their version parameter does not matter
		return networkFirst(event, await shell.match(event.request, { ignoreSearch: true }), (response) => {
			// only the scripts of a page that keeps pieces, not those of the archive
			if (forKeptPage && response.ok && response.type === 'basic') {
				return shell.put(event.request, response)
			}
		})
	}
	if (name === undefined || !kept.includes(name)) {
		return fetch(event.request)
	}
	const collection = COLLECTIONS[name]
	const data = await caches.open(collection.cache)
	const catalogUrl = new URL(collection.catalog, scope).href
	const catalog = parseCatalog(await (await data.match(catalogUrl))?.text() ?? null, collection.key)
	const cacheKey = url.origin + url.pathname
	if (kind === 'page' && name !== 'public') {
		// The page of the list ("Shared with me", the concert folder): it shows itself what is locked
		return networkFirst(event, await data.match(cacheKey), async (response) => {
			if (response.ok && response.headers.get('X-Cantoria-Page') === collection.header) {
				await data.put(cacheKey, response)
			} else if (!response.redirected && (response.ok || response.status === 403 || response.status === 404)) {
				// The archive, another account or no access any more: nothing of this page stays on the device
				await removeCollection(collection)
			}
			// a redirect to the login keeps everything for the next start with a network
		})
	}
	// a score or scan, or the page of a public link (which is the piece)
	if (catalog?.pieces[key] === undefined) {
		return fetch(event.request)
	}
	const stored = await data.match(cacheKey)
	const shown = stored && usable(catalog, key, new Date()) === 'ok' ? stored : undefined
	return networkFirst(event, shown, async (response) => {
		const page = kind !== 'page' || response.headers.get('X-Cantoria-Page') === collection.header
		if (response.ok && !response.redirected && page && mayStay(response.headers)) {
			await data.put(cacheKey, response)
		} else if ((response.ok && !response.redirected && page) || response.status === 403 || response.status === 404) {
			// the server no longer allows it on the device (a license ended), or it is revoked or expired: removed
			await forgetPiece(collection, data, catalogUrl, key, scope)
		}
	})
}

/**
 * Whether a script or style is asked for by a page whose pieces are kept.
 *
 * @param event - the fetch event
 * @param scope - scope of the worker
 * @param kept - the collections with pieces on this device
 */
async function isForKeptPage(event: FetchEvent, scope: URL, kept: CollectionName[]): Promise<boolean> {
	const client = event.clientId ? await self.clients.get(event.clientId) : undefined
	if (!client) {
		return false
	}
	const name = pageCollection(new URL(client.url).pathname, scope.pathname)
	return name !== null && kept.includes(name)
}

/**
 * The network's answer; the kept one when the network fails or takes
 * longer than TIMEOUT_MS. The network's answer refreshes what is kept, also
 * when it comes late.
 *
 * @param event - the fetch event, kept alive until the cache is written
 * @param kept - the kept answer, undefined when there is none or it may not be shown
 * @param store - writes the network's answer (a copy) to the device
 */
function networkFirst(event: FetchEvent, kept: Response | undefined, store: (response: Response) => Promise<void> | void): Promise<Response> {
	const network = fetch(event.request).then((response) => {
		event.waitUntil(Promise.resolve(store(response.clone())).catch(() => undefined))
		return response
	})
	if (!kept) {
		return network
	}
	const late = new Promise<Response>((resolve) => {
		setTimeout(() => resolve(kept), TIMEOUT_MS)
	})
	event.waitUntil(network.then(() => undefined, () => undefined))
	return Promise.race([network.catch(() => kept), late])
}

/**
 * Remove a piece the server no longer allows on the device from the list
 * of kept pieces of its page, with its notes and its scan.
 *
 * @param collection - the collection
 * @param data - its cache
 * @param catalogUrl - address of the list
 * @param key - token of the link or id of the programme item
 * @param scope - scope of the worker
 */
async function forgetPiece(collection: Collection, data: Cache, catalogUrl: string, key: string, scope: URL): Promise<void> {
	const addresses = pieceAddresses(collection.name, key)
	await data.delete(new URL(addresses.notes, scope).href)
	await data.delete(new URL(addresses.scan, scope).href)
	const catalog = parseCatalog(await (await data.match(catalogUrl))?.text() ?? null, collection.key)
	if (catalog === null || catalog.pieces[key] === undefined) {
		return
	}
	delete catalog.pieces[key]
	if (Object.keys(catalog.pieces).length === 0) {
		await removeCollection(collection)
		return
	}
	await data.put(catalogUrl, new Response(JSON.stringify(catalog), { headers: { 'Content-Type': 'application/json' } }))
}

/**
 * Delete what one page kept on the device; the scripts too when no other
 * page keeps pieces.
 *
 * @param collection - the collection
 */
async function removeCollection(collection: Collection): Promise<void> {
	await caches.delete(collection.cache)
	if ((await keptCollections()).length > 0) {
		return
	}
	for (const key of await caches.keys()) {
		if (key.startsWith(CACHE_PREFIX)) {
			await caches.delete(key)
		}
	}
}
