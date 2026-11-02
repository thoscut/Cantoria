/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pieces kept on this device for practising without a network (milestone
 * 0.15), from the page "Shared with me", the concert folder and the page of
 * a public link: registering the service worker, keeping and forgetting a
 * piece, the check with the server and the space used. Everything stays in
 * the browser (Cache Storage), one cache per page (rules.ts, COLLECTIONS);
 * nothing is kept without a click, and only what the server allows to keep.
 * The rules are in rules.ts, the worker in worker.ts.
 */
import type { Catalog, Collection, CollectionName, KeptPiece, PieceForDevice } from './rules.ts'

import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { preloadToolkit } from '../score/toolkit.ts'
import { CACHE_PREFIX, COLLECTIONS, parseCatalog, PUBLIC_ACCOUNT, requestKind, SHELL_PREFIX, syncCatalog } from './rules.ts'

/** Whether this browser can keep pieces: a service worker and Cache Storage, on HTTPS */
export function offlineSupported(): boolean {
	return typeof window !== 'undefined' && window.isSecureContext === true
		&& 'serviceWorker' in navigator && 'caches' in window
}

/** Absolute address of the scope: the app's path, which covers the pages */
function scopeUrl(): string {
	return new URL(generateUrl('/apps/cantoria/'), window.location.href).href
}

/**
 * Absolute address of a route of the app, without query: the key of a kept
 * answer, the same as the worker's.
 *
 * @param path - path inside the app, e.g. "concert-folder"
 */
function appUrl(path: string): string {
	return new URL(path, scopeUrl()).href
}

/** Where the page, the score and the scan of a piece of a collection come from */
const ADDRESSES: Record<CollectionName, { page: (key: string) => string, score: ((key: string) => string) | null, scan: (key: string) => string }> = {
	member: {
		page: () => scopeUrl(),
		score: (token) => appUrl(`api/member/links/${token}/score`),
		scan: (token) => appUrl(`api/member/links/${token}/scan`),
	},
	folder: {
		page: () => appUrl('concert-folder'),
		score: (id) => appUrl(`api/concert-folder/items/${id}/score`),
		scan: (id) => appUrl(`api/concert-folder/items/${id}/scan`),
	},
	public: {
		// the page of a public link is the piece: its notes come with it
		page: (token) => appUrl(`p/${token}`),
		score: null,
		scan: (token) => appUrl(`p/${token}/scan`),
	},
}

/**
 * Address of the kept scan of a piece, for showing it in the page.
 *
 * @param name - the collection
 * @param key - token of the link or id of the programme item
 */
export function scanAddress(name: CollectionName, key: string): string {
	return ADDRESSES[name].scan(key)
}

/**
 * Register the service worker when the page may keep pieces; when it may
 * not, remove what this page kept, and the worker when nothing else is kept.
 *
 * @param name - the collection of the page
 * @param enabled - the module of the page is on
 * @return whether the worker is registered
 */
export async function setUpWorker(name: CollectionName, enabled: boolean): Promise<boolean> {
	if (!offlineSupported()) {
		return false
	}
	if (!enabled) {
		await removeEverything(name)
		if ((await keptCollections()).length === 0) {
			for (const registration of await navigator.serviceWorker.getRegistrations()) {
				if (registration.scope === scopeUrl()) {
					await registration.unregister()
				}
			}
		}
		return false
	}
	try {
		await navigator.serviceWorker.register(generateUrl('/apps/cantoria/offline-worker'), { scope: generateUrl('/apps/cantoria/') })
		return true
	} catch (error) {
		console.warn('Cantoria: the service worker for practising without a network could not be registered', error)
		return false
	}
}

async function keptCollections(): Promise<CollectionName[]> {
	const names = new Set(await caches.keys())
	return Object.values(COLLECTIONS).filter((collection) => names.has(collection.cache)).map((collection) => collection.name)
}

/**
 * The hash of the account, so that the pieces of one account are not shown
 * to another one on the same browser. Public links have no account.
 *
 * @param name - the collection
 */
export async function accountKey(name: CollectionName = 'member'): Promise<string> {
	if (name === 'public') {
		return PUBLIC_ACCOUNT
	}
	const uid = getCurrentUser()?.uid ?? ''
	const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('cantoria|' + uid))
	return Array.from(new Uint8Array(digest).slice(0, 16), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * The list of kept pieces of a page on this device, or null.
 *
 * @param name - the collection
 */
export async function readCatalog(name: CollectionName): Promise<Catalog | null> {
	const collection = COLLECTIONS[name]
	if (!offlineSupported() || !(await caches.has(collection.cache))) {
		return null
	}
	const response = await (await caches.open(collection.cache)).match(appUrl(collection.catalog))
	return parseCatalog(response ? await response.text() : null, collection.key)
}

async function writeCatalog(collection: Collection, catalog: Catalog): Promise<void> {
	const cache = await caches.open(collection.cache)
	await cache.put(appUrl(collection.catalog), new Response(JSON.stringify(catalog), { headers: { 'Content-Type': 'application/json' } }))
}

/**
 * Fetch an address and keep the answer; throws with the server's message.
 *
 * @param cache - the cache
 * @param url - address, also the key in the cache
 * @param header - value of X-Cantoria-Page the answer must have (a page), or null
 */
async function keep(cache: Cache, url: string, header: string | null = null): Promise<Response> {
	const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { requesttoken: getRequestToken() ?? '' } })
	if (!response.ok || response.redirected || (header !== null && response.headers.get('X-Cantoria-Page') !== header)) {
		let message = ''
		try {
			message = ((await response.json()) as { error?: string }).error ?? ''
		} catch {
			// no JSON
		}
		throw new Error(message)
	}
	await cache.put(url, response.clone())
	return response
}

/**
 * When the server made the page of a public link, from its initial state:
 * the time of the server, so that the page can tell later whether it came
 * from the device (checkPublicPiece), whatever the clock of the device says.
 *
 * @param page - the answer with the page
 */
async function servedAt(page: Response): Promise<string> {
	const state = /id="initial-state-cantoria-excerpt" value="([^"]+)"/.exec(await page.text())?.[1]
	try {
		const served = (JSON.parse(atob(state ?? '')) as { served?: unknown }).served
		if (typeof served === 'string' && Number.isFinite(Date.parse(served))) {
			return served
		}
	} catch {
		// no state: the time of the device
	}
	return new Date().toISOString()
}

/**
 * Keep pieces of a page on this device: their notes, on request the scan,
 * the page and its scripts. Needs the network.
 *
 * @param name - the collection of the page
 * @param pieces - the pieces, as the server offers them
 * @param withScan - also keep the scans (PDF), where the server offers them; a piece without notes always keeps its scan
 * @param version - version of the app (cache of the scripts)
 * @param onProgress - called after each piece with the number kept so far
 */
export async function keepPieces(name: CollectionName, pieces: PieceForDevice[], withScan: boolean, version: string, onProgress?: (done: number) => void): Promise<Catalog> {
	const collection = COLLECTIONS[name]
	const addresses = ADDRESSES[name]
	const account = await accountKey(name)
	let catalog = await readCatalog(name)
	if (catalog !== null && catalog.account !== account) {
		await removeEverything(name)
		catalog = null
	}
	const cache = await caches.open(collection.cache)
	const now = new Date().toISOString()
	const kept: Record<string, KeptPiece> = { ...(catalog?.pieces ?? {}) }
	for (const piece of pieces) {
		let checked: string | undefined
		if (addresses.score) {
			if (piece.score !== false) {
				await keep(cache, addresses.score(piece.key))
			}
		} else {
			checked = await servedAt(await keep(cache, addresses.page(piece.key), collection.header))
		}
		// a piece with only a scan keeps the scan, or there would be nothing
		const scan = piece.scan === true && (withScan || piece.score === false)
		if (scan) {
			await keep(cache, addresses.scan(piece.key))
		} else {
			await cache.delete(addresses.scan(piece.key))
		}
		kept[piece.key] = { title: piece.title, composer: piece.composer, expires: piece.expires, scan, kept: now, ...(checked ? { checked } : {}) }
		// written after each piece, so that a failure keeps what is already on the device
		await writeCatalog(collection, { v: 1, account, checked: catalog?.checked ?? now, pieces: kept })
		onProgress?.(pieces.indexOf(piece) + 1)
	}
	if (addresses.score) {
		const page = await fetch(addresses.page(''), { credentials: 'same-origin', cache: 'no-store' })
		if (page.ok && page.headers.get('X-Cantoria-Page') === collection.header) {
			await cache.put(addresses.page(''), page)
		}
	}
	await keepShell(version)
	return { v: 1, account, checked: catalog?.checked ?? now, pieces: kept }
}

/**
 * Keep the scripts, styles, fonts and images of the page, including the
 * notation engine, which loads only with the first score.
 *
 * @param version - version of the app
 */
export async function keepShell(version: string): Promise<void> {
	try {
		await preloadToolkit()
	} catch {
		// kept with the next score shown
	}
	const scopePath = new URL(scopeUrl()).pathname
	const urls = new Set<string>()
	const add = (address: string | null | undefined) => {
		if (!address) {
			return
		}
		const url = new URL(address, window.location.href)
		if (url.origin === window.location.origin && requestKind(url.pathname, scopePath, 'script', false).kind === 'asset') {
			urls.add(url.href)
		}
	}
	for (const entry of performance.getEntriesByType('resource') as PerformanceResourceTiming[]) {
		if (['script', 'link', 'css', 'img', 'other'].includes(entry.initiatorType)) {
			add(entry.name)
		}
	}
	document.querySelectorAll<HTMLScriptElement>('script[src]').forEach((script) => add(script.src))
	document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"], link[rel="modulepreload"], link[rel="icon"]').forEach((link) => add(link.href))
	const shell = await caches.open(SHELL_PREFIX + version)
	await Promise.all([...urls].map(async (url) => {
		if (await shell.match(url)) {
			return
		}
		try {
			const response = await fetch(url, { credentials: 'same-origin' })
			if (response.ok) {
				await shell.put(url, response)
			}
		} catch {
			// kept the next time it loads
		}
	}))
}

/**
 * Remove pieces of a page from this device.
 *
 * @param name - the collection
 * @param keys - tokens of the links or ids of the programme items
 */
export async function forgetPieces(name: CollectionName, keys: string[]): Promise<Catalog | null> {
	const catalog = await readCatalog(name)
	if (catalog === null) {
		return null
	}
	const collection = COLLECTIONS[name]
	const addresses = ADDRESSES[name]
	const cache = await caches.open(collection.cache)
	const pieces = { ...catalog.pieces }
	for (const key of keys) {
		await cache.delete(addresses.score ? addresses.score(key) : addresses.page(key))
		await cache.delete(addresses.scan(key))
		delete pieces[key]
	}
	if (Object.keys(pieces).length === 0) {
		await removeEverything(name)
		return null
	}
	const next = { ...catalog, pieces }
	await writeCatalog(collection, next)
	return next
}

/**
 * Delete what a page kept on this device; without a page, every cache of
 * the app. The scripts go when no page keeps pieces any more.
 *
 * @param name - the collection, or null for everything
 */
export async function removeEverything(name: CollectionName | null = null): Promise<void> {
	if (!offlineSupported()) {
		return
	}
	if (name !== null) {
		await caches.delete(COLLECTIONS[name].cache)
		if ((await keptCollections()).length > 0) {
			return
		}
	}
	for (const key of await caches.keys()) {
		if (key.startsWith(CACHE_PREFIX)) {
			await caches.delete(key)
		}
	}
}

/**
 * The check with the server at a start with a network: expired, revoked
 * and no longer allowed pieces are removed, the rest is checked again.
 *
 * @param name - the collection of the page
 * @param fresh - what the server offers now
 */
export async function checkWithServer(name: CollectionName, fresh: PieceForDevice[]): Promise<Catalog | null> {
	const catalog = await readCatalog(name)
	if (catalog === null) {
		return null
	}
	if (catalog.account !== await accountKey(name)) {
		await removeEverything(name)
		return null
	}
	const { catalog: next, removed, scansRemoved } = syncCatalog(catalog, fresh, new Date())
	const addresses = ADDRESSES[name]
	const cache = await caches.open(COLLECTIONS[name].cache)
	for (const key of removed) {
		await cache.delete(addresses.score ? addresses.score(key) : addresses.page(key))
		await cache.delete(addresses.scan(key))
	}
	for (const key of scansRemoved) {
		await cache.delete(addresses.scan(key))
	}
	if (Object.keys(next.pieces).length === 0) {
		await removeEverything(name)
		return null
	}
	await writeCatalog(COLLECTIONS[name], next)
	return next
}

/**
 * The check of one public link, which its own page is: the page came from
 * the server when it is newer than the last check. Then the piece follows
 * the server; a piece that may no longer be kept is removed.
 *
 * @param piece - the link as the page shows it
 * @param served - when the server made the page (ISO time)
 * @return the list of kept public pieces, and whether the page came from the device
 */
export async function checkPublicPiece(piece: PieceForDevice, served: string): Promise<{ catalog: Catalog | null, fromDevice: boolean }> {
	const catalog = await readCatalog('public')
	const kept = catalog?.pieces[piece.key]
	if (!catalog || !kept) {
		return { catalog, fromDevice: false }
	}
	const servedAt = Date.parse(served)
	const checkedAt = Date.parse(kept.checked ?? catalog.checked)
	if (Number.isFinite(servedAt) && Number.isFinite(checkedAt) && servedAt <= checkedAt) {
		return { catalog, fromDevice: true }
	}
	if (piece.offline !== true) {
		return { catalog: await forgetPieces('public', [piece.key]), fromDevice: false }
	}
	const scan = kept.scan && piece.scan === true
	if (kept.scan && !scan) {
		await (await caches.open(COLLECTIONS.public.cache)).delete(ADDRESSES.public.scan(piece.key))
	}
	const next: Catalog = {
		...catalog,
		pieces: { ...catalog.pieces, [piece.key]: { ...kept, title: piece.title, composer: piece.composer, expires: piece.expires, scan, checked: served } },
	}
	await writeCatalog(COLLECTIONS.public, next)
	return { catalog: next, fromDevice: false }
}

/**
 * The scan of a piece as an address in the browser's memory, for showing it
 * in the page: from the server, and without a network from the device (the
 * worker answers for kept pieces). The caller revokes it; throws with the
 * server's message.
 *
 * @param name - the collection
 * @param key - token of the link or id of the programme item
 */
export async function scanInMemory(name: CollectionName, key: string): Promise<string> {
	const response = await fetch(ADDRESSES[name].scan(key), { credentials: 'same-origin' })
	if (!response.ok || response.headers.get('Content-Type')?.startsWith('application/pdf') !== true) {
		let message = ''
		try {
			message = ((await response.json()) as { error?: string }).error ?? ''
		} catch {
			// no JSON
		}
		throw new Error(message)
	}
	return URL.createObjectURL(new Blob([await response.arrayBuffer()], { type: 'application/pdf' }))
}

/** Space used and available, and whether the browser keeps it for good. */
export async function storageInfo(): Promise<{ usage: number | null, quota: number | null, persisted: boolean }> {
	try {
		const estimate = await navigator.storage?.estimate?.()
		const persisted = await navigator.storage?.persisted?.() ?? false
		return { usage: estimate?.usage ?? null, quota: estimate?.quota ?? null, persisted }
	} catch {
		return { usage: null, quota: null, persisted: false }
	}
}

/** Ask the browser to keep the pieces even when space runs short. */
export async function askToPersist(): Promise<boolean> {
	try {
		return await navigator.storage?.persist?.() ?? false
	} catch {
		return false
	}
}
