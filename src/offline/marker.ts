/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Which account used Cantoria last in this browser (S N6): the hash of the
 * account (device.ts, accountKey), one value in IndexedDB, which the pages
 * and the service worker can both read. After signing out, Nextcloud's
 * login page deletes IndexedDB, localStorage and sessionStorage, but not
 * Cache Storage, where the kept pieces are (in browsers that follow the
 * header Clear-Site-Data, signing out deletes those too). A list of kept
 * pieces written while its account was remembered (Catalog.signedIn)
 * therefore says, once the value is gone, that someone signed out since:
 * the worker and the pages remove it before anything of it is shown
 * (rules.ts, staleCatalog). No imports: the worker is built from it, too.
 */

const DATABASE = 'cantoria'
const STORE = 'device'
const KEY = 'account'

/** How long the pages wait for IndexedDB before they start without it */
const TIMEOUT_MS = 2000

/** The database, created on first use */
function open(): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		let late = false
		const timer = setTimeout(() => {
			late = true
			reject(new Error('IndexedDB does not answer'))
		}, TIMEOUT_MS)
		const request = indexedDB.open(DATABASE, 1)
		request.onupgradeneeded = () => request.result.createObjectStore(STORE)
		request.onsuccess = () => {
			clearTimeout(timer)
			const database = request.result
			if (late) {
				database.close()
				return
			}
			// Nextcloud's login page deletes the database: never stand in its way
			database.onversionchange = () => database.close()
			resolve(database)
		}
		request.onerror = () => {
			clearTimeout(timer)
			reject(request.error)
		}
		request.onblocked = () => {
			clearTimeout(timer)
			reject(new Error('IndexedDB is blocked'))
		}
	})
}

/**
 * The remembered account: null when there is none (never written, or
 * deleted since), undefined when this browser cannot read it (no
 * IndexedDB, e.g. in some private windows); then it decides nothing.
 */
export async function rememberedAccount(): Promise<string | null | undefined> {
	if (typeof indexedDB === 'undefined') {
		return undefined
	}
	try {
		const database = await open()
		try {
			return await new Promise<string | null>((resolve, reject) => {
				const request = database.transaction(STORE, 'readonly').objectStore(STORE).get(KEY)
				request.onsuccess = () => resolve(typeof request.result === 'string' ? request.result : null)
				request.onerror = () => reject(request.error)
			})
		} finally {
			database.close()
		}
	} catch {
		return undefined
	}
}

/**
 * Remember the account that uses Cantoria in this browser now.
 *
 * @param account - hash of the account (accountKey)
 * @return whether it is remembered
 */
export async function rememberAccount(account: string): Promise<boolean> {
	if (typeof indexedDB === 'undefined') {
		return false
	}
	try {
		const database = await open()
		try {
			return await new Promise<boolean>((resolve, reject) => {
				const transaction = database.transaction(STORE, 'readwrite')
				transaction.objectStore(STORE).put(account, KEY)
				transaction.oncomplete = () => resolve(true)
				transaction.onerror = () => reject(transaction.error)
				transaction.onabort = () => reject(transaction.error)
			})
		} finally {
			database.close()
		}
	} catch {
		return false
	}
}

/**
 * Forget the account, with "Delete everything on this device", once nothing
 * of an account is kept any more (device.ts, removeEverything).
 */
export async function forgetAccount(): Promise<void> {
	if (typeof indexedDB === 'undefined') {
		return
	}
	await new Promise<void>((resolve) => {
		try {
			const request = indexedDB.deleteDatabase(DATABASE)
			request.onsuccess = () => resolve()
			request.onerror = () => resolve()
			request.onblocked = () => resolve()
		} catch {
			resolve()
		}
	})
}
