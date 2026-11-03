// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A shared device (S N6): what a page removes at its start in this
 * browser, before it shows anything (src/offline/device.ts, claimDevice),
 * with the Cache Storage of the browser and the remembered account
 * (src/offline/marker.ts) stood in for; and "Delete everything on this
 * device" of the pages that keep pieces (src/sharing/RemoveFromDevice.vue).
 */
import type { Catalog } from '../offline/rules.ts'

import { generateUrl } from '@nextcloud/router'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { COLLECTIONS } from '../offline/rules.ts'
import { storeMarks, storedMarks } from '../score/marks.ts'
import RemoveFromDevice from '../sharing/RemoveFromDevice.vue'

const user = vi.hoisted(() => ({ uid: null as string | null }))
const memory = vi.hoisted(() => ({ account: null as string | null | undefined }))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => (user.uid === null ? null : { uid: user.uid, displayName: user.uid, isAdmin: false }),
	getRequestToken: () => 'token',
}))
vi.mock('../offline/marker.ts', () => ({
	rememberedAccount: async () => memory.account,
	rememberAccount: async (account: string) => {
		memory.account = account
		return true
	},
	forgetAccount: async () => {
		memory.account = null
	},
}))

const { accountKey, checkWithServer, claimDevice, readCatalog, removeEverything } = await import('../offline/device.ts')

/** Cache Storage of the browser, in memory */
class FakeCache {

	entries = new Map<string, string>()
	async match(url: string): Promise<Response | undefined> {
		const body = this.entries.get(url)
		return body === undefined ? undefined : new Response(body)
	}

	async put(url: string, response: Response): Promise<void> {
		this.entries.set(url, await response.text())
	}

	async delete(url: string): Promise<boolean> {
		return this.entries.delete(url)
	}

}

const stores = new Map<string, FakeCache>()
const fakeCaches = {
	async open(name: string) {
		if (!stores.has(name)) {
			stores.set(name, new FakeCache())
		}
		return stores.get(name)!
	},
	async has(name: string) {
		return stores.has(name)
	},
	async delete(name: string) {
		return stores.delete(name)
	},
	async keys() {
		return [...stores.keys()]
	},
}

/**
 * The address of the list of kept pieces, as device.ts computes it.
 *
 * @param name - the collection
 */
function catalogUrl(name: 'member' | 'folder' | 'public'): string {
	return new URL(COLLECTIONS[name].catalog, new URL(generateUrl('/apps/cantoria/'), window.location.href)).href
}

/**
 * Pieces of an account kept on this device.
 *
 * @param name - the collection
 * @param uid - the account, null for public links
 * @param signedIn - kept while the browser remembered the account
 */
async function keptBy(name: 'member' | 'folder' | 'public', uid: string | null, signedIn = true): Promise<Catalog> {
	user.uid = uid
	const account = uid === null ? 'public' : await accountKey(name)
	const key = name === 'folder' ? '42' : 'a'.repeat(32)
	const catalog: Catalog = {
		v: 1,
		account,
		checked: new Date().toISOString(),
		pieces: { [key]: { title: 'Abendlied', composer: 'Rheinberger', expires: null, scan: false, kept: new Date().toISOString() } },
		...(signedIn && uid !== null ? { signedIn: true as const } : {}),
	}
	await (await fakeCaches.open(COLLECTIONS[name].cache)).put(catalogUrl(name), new Response(JSON.stringify(catalog)))
	return catalog
}

/**
 * The account of a page as the browser remembers it.
 *
 * @param uid - the account
 */
async function hashOf(uid: string): Promise<string> {
	user.uid = uid
	return accountKey('member')
}

describe('a page at its start on a shared device (S N6)', () => {
	beforeEach(() => {
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true })
		Object.defineProperty(window, 'caches', { configurable: true, value: fakeCaches })
		vi.stubGlobal('caches', fakeCaches)
		Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register: vi.fn(), getRegistrations: async () => [] } })
	})

	afterEach(() => {
		stores.clear()
		memory.account = null
		user.uid = null
		window.localStorage.clear()
	})

	it('removes the pieces, passages and settings of another account and remembers the new one', async () => {
		await keptBy('member', 'anna')
		await keptBy('folder', 'anna')
		memory.account = await hashOf('anna')
		storeMarks('piece:k1', [{ name: 'Annas Stelle', from: '1', to: '4' }])
		user.uid = 'ben'
		expect(await claimDevice('member')).toBe(false)
		expect(await readCatalog('member')).toBeNull()
		expect(await readCatalog('folder')).toBeNull()
		expect(storedMarks('piece:k1')).toEqual([])
		expect(memory.account).toBe(await hashOf('ben'))
	})

	it('keeps everything of the same account', async () => {
		await keptBy('member', 'anna')
		memory.account = await hashOf('anna')
		storeMarks('piece:k1', [{ name: 'Annas Stelle', from: '1', to: '4' }])
		expect(await claimDevice('member')).toBe(false)
		expect(await readCatalog('member')).not.toBeNull()
		expect(storedMarks('piece:k1')).toHaveLength(1)
	})

	it('after signing out removes the page kept on the device and has it loaded again, without remembering its account', async () => {
		// Nextcloud's login page cleared what the browser remembered; the page comes from the device with the old account
		await keptBy('member', 'anna')
		memory.account = null
		user.uid = 'anna'
		expect(await claimDevice('member')).toBe(true)
		expect(await readCatalog('member')).toBeNull()
		expect(memory.account).toBeNull()
	})

	it('on a public link removes only what was kept before signing out, and keeps the public pieces', async () => {
		await keptBy('member', 'anna')
		await keptBy('public', null)
		memory.account = null
		user.uid = null
		expect(await claimDevice('public')).toBe(false)
		expect(await readCatalog('member')).toBeNull()
		expect(await readCatalog('public')).not.toBeNull()
		expect(memory.account).toBeNull()
	})

	it('on a public link keeps the pieces of an account whose session only ended', async () => {
		await keptBy('member', 'anna')
		memory.account = await hashOf('anna')
		user.uid = null
		expect(await claimDevice('public')).toBe(false)
		expect(await readCatalog('member')).not.toBeNull()
	})

	it('adopts pieces kept by an older version, and marks them at the next check with the server', async () => {
		await keptBy('member', 'anna', false)
		memory.account = null
		user.uid = 'anna'
		expect(await claimDevice('member')).toBe(false)
		expect(memory.account).toBe(await hashOf('anna'))
		const checked = await checkWithServer('member', [{ key: 'a'.repeat(32), title: 'Abendlied', composer: 'Rheinberger', expires: null, offline: true }])
		expect(checked?.signedIn).toBe(true)
		expect((await readCatalog('member'))?.signedIn).toBe(true)
	})

	it('forgets the account with everything on the device, but not while pieces of an account stay', async () => {
		await keptBy('member', 'anna')
		await keptBy('folder', 'anna')
		memory.account = await hashOf('anna')
		await removeEverything('folder')
		expect(memory.account).toBe(await hashOf('anna'))
		await removeEverything()
		expect(await readCatalog('member')).toBeNull()
		expect(memory.account).toBeNull()
	})

	it('decides nothing without HTTPS', async () => {
		await keptBy('member', 'anna')
		memory.account = await hashOf('anna')
		user.uid = 'ben'
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: false })
		expect(await claimDevice('member')).toBe(false)
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true })
		expect(await readCatalog('member')).not.toBeNull()
	})

	it('decides nothing where the browser cannot remember an account', async () => {
		await keptBy('member', 'anna')
		memory.account = undefined
		user.uid = null
		expect(await claimDevice('public')).toBe(false)
		expect(await readCatalog('member')).not.toBeNull()
	})
})

describe('RemoveFromDevice', () => {
	const consequence = 'In this browser, this deletes the pieces, the passages and the settings.'

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('has one name, says how many pieces are kept, and asks right below with what goes besides (U43, D36)', async () => {
		const remove = vi.fn(async () => {})
		const wrapper = mount(RemoveFromDevice, { props: { count: 3, consequence, remove }, attachTo: document.body })
		expect(wrapper.text()).toContain('3 pieces are available without a network.')
		expect(wrapper.find('button').text()).toBe('Delete everything on this device')
		await wrapper.find('button').trigger('click')
		expect(wrapper.text()).toContain(consequence)
		expect(remove).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('never loses the focus: on the confirmation, back on the button after "Cancel" (D28)', async () => {
		const wrapper = mount(RemoveFromDevice, { props: { consequence, remove: vi.fn(async () => {}) }, attachTo: document.body })
		await wrapper.find('button').trigger('click')
		await flushPromises()
		const confirm = wrapper.findAll('button').find((button) => button.text() === 'Delete kept pieces, passages and settings')
		expect(document.activeElement).toBe(confirm?.element)
		expect(confirm?.attributes('aria-describedby')).toBe(wrapper.find('.remove-from-device__confirm p').attributes('id'))
		await wrapper.findAll('button').find((button) => button.text() === 'Cancel')!.trigger('click')
		await flushPromises()
		expect(document.activeElement?.textContent?.trim()).toBe('Delete everything on this device')
		wrapper.unmount()
	})

	it('deletes after the confirmation and lets the page move the focus', async () => {
		const remove = vi.fn(async () => {})
		const wrapper = mount(RemoveFromDevice, { props: { count: 2, consequence, remove }, attachTo: document.body })
		await wrapper.find('button').trigger('click')
		await flushPromises()
		await wrapper.findAll('button').find((button) => button.text() === 'Delete kept pieces, passages and settings')!.trigger('click')
		await flushPromises()
		expect(remove).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('removed')).toHaveLength(1)
		expect(wrapper.find('.remove-from-device__confirm').exists()).toBe(false)
		wrapper.unmount()
	})
})
