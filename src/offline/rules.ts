/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Practising without a network (milestone 0.15): the rules that the pages
 * ("Shared with me", the concert folder, public links) and their service
 * worker share. What a request is, which pieces kept on the device may still
 * be shown, and how the list of kept pieces follows the server. No browser API here, so that the
 * rules are tested in Node; the service worker is built from them on its own
 * (vite.config.ts).
 */

/** Every cache of the app starts with this, so that "delete" finds them all */
export const CACHE_PREFIX = 'cantoria-'
/** Scripts, styles, fonts and images of the pages, one cache per app version */
export const SHELL_PREFIX = 'cantoria-shell-'
/** Kept pieces are locked after this many days without a check with the server */
export const MAX_DAYS_UNCHECKED = 30

/**
 * The pages that keep pieces, each with a cache of its own: "Shared with
 * me" (links of the choir members), the concert folder of the conductor
 * (items of a programme) and the page of a public link (the page is the
 * piece). Forgetting one never touches the others.
 */
export type CollectionName = 'member' | 'folder' | 'public'

export interface Collection {
	name: CollectionName
	/** the cache with the pieces, the page and the list of kept pieces */
	cache: string
	/** the list of kept pieces inside the cache, relative to the scope */
	catalog: string
	/** value of the header X-Cantoria-Page of the page */
	header: string
	/** what the key of a piece looks like: token of a link, id of a programme item */
	key: RegExp
}

const TOKEN = /^[A-Za-z0-9]{32}$/

export const COLLECTIONS: Record<CollectionName, Collection> = {
	member: { name: 'member', cache: 'cantoria-offline', catalog: 'offline-catalog.json', header: 'member', key: TOKEN },
	folder: { name: 'folder', cache: 'cantoria-folder', catalog: 'folder-catalog.json', header: 'concert-folder', key: /^\d{1,12}$/ },
	public: { name: 'public', cache: 'cantoria-public', catalog: 'public-catalog.json', header: 'public', key: TOKEN },
}

/** The account of the pieces kept from public links: there is none */
export const PUBLIC_ACCOUNT = 'public'

/** A piece kept on the device */
export interface KeptPiece {
	title: string
	composer: string
	/** last valid day of the link (YYYY-MM-DD), null without end */
	expires: string | null
	/** the scan (PDF) is kept too */
	scan: boolean
	/** when it was put on the device (ISO time) */
	kept: string
	/** last check of this piece alone (public links: each page is its own check) */
	checked?: string
}

/** The list of kept pieces of one account on this device */
export interface Catalog {
	v: 1
	/** hash of the account, so that another account on the same browser starts empty */
	account: string
	/** last check with the server (ISO time) */
	checked: string
	pieces: Record<string, KeptPiece>
}

/** What the server says about keeping a piece (a link of the page, a programme item) */
export interface PieceForDevice {
	/** token of the link or id of the programme item */
	key: string
	title: string
	composer: string
	expires: string | null
	offline?: boolean
	scan?: boolean
	/** false: there are no notes, only the scan (an item of the concert folder) */
	score?: boolean
}

export type RequestKind = 'page' | 'score' | 'scan' | 'asset' | 'other'

/** What a request is; collection and key for the pages and the kept pieces */
export interface WorkerRequest {
	kind: RequestKind
	collection?: CollectionName
	key?: string
}

/** Paths that never come from the device: login, the session, the APIs of Nextcloud, avatars */
const NEVER_KEPT = ['/ocs/', '/remote.php', '/avatar/', '/login', '/logout', '/csrftoken', '/heartbeat', '/status.php']

/** Types of requests that make up the page */
const ASSET_DESTINATIONS = ['script', 'style', 'font', 'image', 'manifest']

const MEMBER_DATA = /^api\/member\/links\/([A-Za-z0-9]{32})\/(score|scan)$/
const FOLDER_DATA = /^api\/concert-folder\/items\/(\d{1,12})\/(score|scan)$/
const PUBLIC_PAGE = /^p\/([A-Za-z0-9]{32})$/
const PUBLIC_SCAN = /^p\/([A-Za-z0-9]{32})\/scan$/

/**
 * What a request of a page in the scope of the worker is.
 *
 * @param path - pathname of the request
 * @param scopePath - pathname of the scope, ending in "/" (e.g. /index.php/apps/cantoria/)
 * @param destination - Request.destination ('' for fetch())
 * @param navigate - the request opens a page (Request.mode === 'navigate')
 */
export function requestKind(path: string, scopePath: string, destination: string, navigate: boolean): WorkerRequest {
	if (NEVER_KEPT.some((part) => path.includes(part))) {
		return { kind: 'other' }
	}
	if (path.startsWith(scopePath)) {
		const inner = path.slice(scopePath.length)
		if (navigate && inner === '') {
			return { kind: 'page', collection: 'member' }
		}
		if (navigate && inner === 'concert-folder') {
			return { kind: 'page', collection: 'folder' }
		}
		const publicPage = navigate ? PUBLIC_PAGE.exec(inner) : null
		if (publicPage) {
			return { kind: 'page', collection: 'public', key: publicPage[1] }
		}
		const publicScan = PUBLIC_SCAN.exec(inner)
		if (publicScan) {
			return { kind: 'scan', collection: 'public', key: publicScan[1] }
		}
		const member = MEMBER_DATA.exec(inner)
		if (member) {
			return { kind: member[2] as 'score' | 'scan', collection: 'member', key: member[1] }
		}
		const folder = FOLDER_DATA.exec(inner)
		if (folder) {
			return { kind: folder[2] as 'score' | 'scan', collection: 'folder', key: folder[1] }
		}
		if (inner.startsWith('api/') || navigate) {
			return { kind: 'other' }
		}
	}
	return !navigate && ASSET_DESTINATIONS.includes(destination) ? { kind: 'asset' } : { kind: 'other' }
}

/**
 * The collection whose page an address is, or null: the scripts of a page
 * are kept only for these pages.
 *
 * @param path - pathname of the page
 * @param scopePath - pathname of the scope
 */
export function pageCollection(path: string, scopePath: string): CollectionName | null {
	const request = requestKind(path, scopePath, 'document', true)
	return request.kind === 'page' ? request.collection ?? null : null
}

/**
 * The day of a time in the time zone of the device (YYYY-MM-DD), as links
 * expire at the end of a day.
 *
 * @param time - the time
 */
export function localDay(time: Date): string {
	const pad = (value: number) => String(value).padStart(2, '0')
	return `${time.getFullYear()}-${pad(time.getMonth() + 1)}-${pad(time.getDate())}`
}

/**
 * Whether a kept piece may be shown: 'ok', 'expired' when its link ended,
 * 'unchecked' after MAX_DAYS_UNCHECKED days without a check with the
 * server, 'unknown' when it is not kept.
 *
 * @param catalog - the list of kept pieces, null when there is none
 * @param key - token of the link or id of the programme item
 * @param now - the time now
 */
export function usable(catalog: Catalog | null, key: string, now: Date): 'ok' | 'expired' | 'unchecked' | 'unknown' {
	const piece = catalog?.pieces[key]
	if (!catalog || !piece) {
		return 'unknown'
	}
	if (piece.expires !== null && piece.expires < localDay(now)) {
		return 'expired'
	}
	const checked = Date.parse(piece.checked ?? catalog.checked)
	if (!Number.isFinite(checked) || now.getTime() - checked > MAX_DAYS_UNCHECKED * 24 * 60 * 60 * 1000 || checked > now.getTime() + 60 * 60 * 1000) {
		return 'unchecked'
	}
	return 'ok'
}

/**
 * The list of kept pieces from its JSON, or null when it is missing or
 * broken (then nothing counts as kept).
 *
 * @param text - JSON of the list
 * @param key - what the key of a piece looks like (Collection.key)
 */
export function parseCatalog(text: string | null, key: RegExp = TOKEN): Catalog | null {
	if (text === null) {
		return null
	}
	try {
		const parsed = JSON.parse(text) as Partial<Catalog>
		if (parsed?.v !== 1 || typeof parsed.account !== 'string' || typeof parsed.checked !== 'string'
			|| typeof parsed.pieces !== 'object' || parsed.pieces === null) {
			return null
		}
		const pieces: Record<string, KeptPiece> = {}
		for (const [name, piece] of Object.entries(parsed.pieces)) {
			if (key.test(name) && typeof piece?.title === 'string' && typeof piece.kept === 'string') {
				pieces[name] = {
					title: piece.title,
					composer: typeof piece.composer === 'string' ? piece.composer : '',
					expires: typeof piece.expires === 'string' ? piece.expires : null,
					scan: piece.scan === true,
					kept: piece.kept,
					...(typeof piece.checked === 'string' ? { checked: piece.checked } : {}),
				}
			}
		}
		return { v: 1, account: parsed.account, checked: parsed.checked, pieces }
	} catch {
		return null
	}
}

/**
 * The list of kept pieces after a check with the server: only pieces that
 * are still shared and may still be kept stay, with the title and end date
 * of the server; a scan stays only while the server still offers it.
 *
 * @param catalog - the list so far
 * @param fresh - what the server offers now (links of the account, items of the programmes)
 * @param now - the time of the check
 */
export function syncCatalog(catalog: Catalog, fresh: PieceForDevice[], now: Date): { catalog: Catalog, removed: string[], scansRemoved: string[] } {
	const byKey = new Map(fresh.map((piece) => [piece.key, piece]))
	const pieces: Record<string, KeptPiece> = {}
	const removed: string[] = []
	const scansRemoved: string[] = []
	for (const [key, piece] of Object.entries(catalog.pieces)) {
		const server = byKey.get(key)
		if (!server || server.offline !== true) {
			removed.push(key)
			continue
		}
		const scan = piece.scan && server.scan === true
		if (piece.scan && !scan) {
			scansRemoved.push(key)
		}
		pieces[key] = { title: server.title, composer: server.composer, expires: server.expires, scan, kept: piece.kept }
	}
	return { catalog: { ...catalog, checked: now.toISOString(), pieces }, removed, scansRemoved }
}
