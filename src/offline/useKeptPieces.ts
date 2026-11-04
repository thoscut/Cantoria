/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One way to keep pieces on this device for the three pages that do it
 * ("Shared with me", the concert folder, the page of a public link): the
 * list of kept pieces, the check with the server at a start with a network,
 * keeping and removing, the space used and the texts about it. The pages
 * pass their collection and say what the server offers now; everything a
 * rule of "without a network" changes stands here once, also keeping the
 * scripts of the page again after an update of the app (keepShell at every
 * start with a network while something is kept).
 */
import type { ComputedRef, Ref } from 'vue'
import type { Catalog, CollectionName, PieceForDevice } from './rules.ts'

import { isAxiosError } from '@nextcloud/axios'
import { computed, onBeforeUnmount, ref } from 'vue'
import { formatDateTime, formatSize } from '../format.ts'
import { n, t } from '../l10n.ts'
import { forgetPractising } from '../score/practiceSettings.ts'
import { askToPersist, checkPublicPiece, checkWithServer, forgetPieces, keepPieces, keepShell, offlineSupported, readCatalog, removeEverything, setUpWorker, storageInfo } from './device.ts'
import { MAX_DAYS_UNCHECKED, usable } from './rules.ts'

export interface StorageInfo {
	usage: number | null
	quota: number | null
	persisted: boolean
}

export interface KeptPieces {
	/** the kept pieces of this page on this device, or null */
	catalog: Ref<Catalog | null>
	/** the server answered the last check; false: shown from the device */
	reachable: Ref<boolean>
	storage: Ref<StorageInfo | null>
	/** the page can keep pieces: module on, and the browser can */
	offlineOn: boolean
	withoutNetwork: ComputedRef<boolean>
	keptCount: ComputedRef<number>
	storageText: ComputedRef<string>
	checkedText: ComputedRef<string>
	isKept: (key: string) => boolean
	scanKept: (key: string) => boolean
	lockText: (key: string) => string
	refreshStorage: () => Promise<void>
	checkDevice: () => Promise<void>
	checkPublic: (piece: PieceForDevice, served: string) => Promise<boolean>
	keep: (pieces: PieceForDevice[], withScan: boolean, onProgress?: (done: number) => void) => Promise<void>
	forget: (keys: string[]) => Promise<void>
	deleteDevice: () => Promise<void>
	start: () => Promise<void>
}

export interface KeptPiecesOptions {
	/** the module "offline" is on for this page */
	enabled: boolean
	/** version of the app: names the cache of the scripts */
	version: string
	/** what the server offers now; throws without a network. Not for public links */
	fresh?: () => Promise<PieceForDevice[]>
	/** what "delete everything on this device" removes: this collection, or null for every collection of the app */
	deletes?: CollectionName | null
}

/**
 * The kept pieces of a page.
 *
 * @param name - the collection of the page
 * @param options - module, version and the server's list
 */
export function useKeptPieces(name: CollectionName, options: KeptPiecesOptions): KeptPieces {
	const offlineOn = options.enabled && offlineSupported()
	const catalog = ref<Catalog | null>(null)
	const reachable = ref(true)
	const storage = ref<StorageInfo | null>(null)
	const withoutNetwork = computed(() => offlineOn && !reachable.value)
	const keptCount = computed(() => Object.keys(catalog.value?.pieces ?? {}).length)

	const isKept = (key: string) => catalog.value?.pieces[key] !== undefined
	const scanKept = (key: string) => catalog.value?.pieces[key]?.scan === true

	/**
	 * Why a piece cannot be shown without a network, or ''.
	 *
	 * @param key - token of the link or id of the programme item
	 */
	function lockText(key: string): string {
		switch (usable(catalog.value, key, new Date())) {
		case 'ok':
			return ''
		case 'expired':
			return t('cantoria', 'This link has expired. It is removed from this device at the next start with a network.')
		case 'unchecked':
			return n('cantoria', 'Locked: not checked with the server for more than %n day. Please open this page once with a network.',
				'Locked: not checked with the server for more than %n days. Please open this page once with a network.', MAX_DAYS_UNCHECKED)
		default:
			return t('cantoria', 'There is no network, and this piece is not available without a network.')
		}
	}

	async function refreshStorage(): Promise<void> {
		storage.value = await storageInfo()
	}

	/**
	 * The scripts of the page again, so that it still opens without a
	 * network after an update of the app; only while something is kept.
	 */
	function keepScripts(): void {
		if (keptCount.value > 0) {
			keepShell(options.version).catch((error) => console.warn('Cantoria: the page could not be kept for use without a network', error))
		}
	}

	/**
	 * Check the kept pieces with the server; without an answer the page
	 * shows what is kept on the device.
	 */
	async function checkDevice(): Promise<void> {
		if (!offlineOn || !options.fresh) {
			return
		}
		catalog.value = await readCatalog(name)
		try {
			const fresh = await options.fresh()
			reachable.value = true
			catalog.value = await checkWithServer(name, fresh)
			keepScripts()
		} catch (error) {
			// an answer of the server (e.g. not logged in) is no missing network
			reachable.value = isAxiosError(error) && error.response !== undefined
		}
		await refreshStorage()
	}

	/**
	 * The check of a public link, which its own page is.
	 *
	 * @param piece - the link as the page shows it
	 * @param served - when the server made the page (ISO time)
	 * @return whether the page came from the device
	 */
	async function checkPublic(piece: PieceForDevice, served: string): Promise<boolean> {
		const checked = await checkPublicPiece(piece, served)
		catalog.value = checked.catalog
		reachable.value = !checked.fromDevice
		if (!checked.fromDevice) {
			keepScripts()
		}
		return checked.fromDevice
	}

	async function keep(pieces: PieceForDevice[], withScan: boolean, onProgress?: (done: number) => void): Promise<void> {
		try {
			catalog.value = await keepPieces(name, pieces, withScan, options.version, onProgress)
			await askToPersist()
		} catch (error) {
			// what is already on the device stays
			catalog.value = await readCatalog(name)
			throw error
		} finally {
			await refreshStorage()
		}
	}

	async function forget(keys: string[]): Promise<void> {
		try {
			catalog.value = await forgetPieces(name, keys)
		} finally {
			await refreshStorage()
		}
	}

	/** Delete the kept pieces and everything remembered for practising, on this device only. */
	async function deleteDevice(): Promise<void> {
		await removeEverything(options.deletes === undefined ? name : options.deletes)
		forgetPractising()
		catalog.value = null
		await refreshStorage()
	}

	const storageText = computed(() => {
		const info = storage.value
		if (!info || info.usage === null) {
			return ''
		}
		return info.quota === null
			? t('cantoria', '{used} used on this device.', { used: formatSize(info.usage) })
			: t('cantoria', '{used} of {available} used on this device.', { used: formatSize(info.usage), available: formatSize(info.quota) })
	})

	const checkedText = computed(() => {
		const checked = catalog.value ? Date.parse(catalog.value.checked) : NaN
		return Number.isFinite(checked)
			? t('cantoria', 'Last checked with the server: {time}.', { time: formatDateTime(Math.floor(checked / 1000)) })
			: ''
	})

	function onOnline(): void {
		checkDevice()
	}

	/**
	 * At the start of the page: register the worker (or remove it and what
	 * the page kept when the module is off) and check with the server.
	 */
	async function start(): Promise<void> {
		if (!offlineSupported()) {
			return
		}
		await setUpWorker(name, options.enabled)
		if (options.fresh) {
			await checkDevice()
			window.addEventListener('online', onOnline)
		}
	}

	onBeforeUnmount(() => window.removeEventListener('online', onOnline))

	return {
		catalog,
		reachable,
		storage,
		offlineOn,
		withoutNetwork,
		keptCount,
		storageText,
		checkedText,
		isKept,
		scanKept,
		lockText,
		refreshStorage,
		checkDevice,
		checkPublic,
		keep,
		forget,
		deleteDevice,
		start,
	}
}
