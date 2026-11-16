// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The page of a public link and the pieces kept on the device (A24): a
 * piece that may not be kept removes only itself from the device, not the
 * public pieces kept there for a concert; only the module switched off
 * removes them all. The functions of the device are stood in for
 * (src/offline/device.ts, tested on their own in sharedDevice.test.ts).
 * The page says until when the link is valid, and a page without its score
 * offers to reload (U-9).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const TOKEN = 'a'.repeat(32)

const device = vi.hoisted(() => ({
	setUpWorker: vi.fn(async () => true),
	removeEverything: vi.fn(async () => {}),
	forgetPieces: vi.fn(async () => null),
	checkPublicPiece: vi.fn(async () => ({ catalog: null, fromDevice: false })),
}))

vi.mock('../offline/device.ts', () => ({
	...device,
	offlineSupported: () => true,
	askToPersist: vi.fn(async () => {}),
	checkWithServer: vi.fn(async () => null),
	keepPieces: vi.fn(async () => null),
	keepShell: vi.fn(async () => {}),
	readCatalog: vi.fn(async () => null),
	storageInfo: vi.fn(async () => null),
	scanInMemory: vi.fn(async () => ''),
}))

const ExcerptViewer = defineComponent({ name: 'ExcerptViewer', setup: () => () => h('div', { class: 'excerpt-viewer' }) })

/**
 * The page as the server sends it.
 *
 * @param excerpt - what the server says about keeping the piece
 * @param excerpt.offline - this piece may be kept
 * @param excerpt.offlineModule - the module is on
 * @param excerpt.expires - last valid day of the link
 * @param excerpt.xml - the score; '' for a page that came without it
 */
async function mountPage(excerpt: { offline: boolean, offlineModule?: boolean, expires?: string | null, xml?: string }) {
	delete (window as { _nc_initial_state?: unknown })._nc_initial_state
	for (const [key, value] of Object.entries({
		excerpt: { title: 'Ave verum', composer: 'W. A. Mozart', measureFrom: null, measureTo: null, voice: null, verse: null, tempo: null, canPlay: true, xml: '<score-partwise/>', credits: [], notices: [], ...excerpt },
		version: '0.19.0',
	})) {
		const input = document.createElement('input')
		input.type = 'hidden'
		input.id = 'initial-state-cantoria-' + key
		input.value = btoa(JSON.stringify(value))
		document.body.appendChild(input)
	}
	const { default: PublicApp } = await import('../sharing/PublicApp.vue')
	const wrapper = mount(PublicApp, { attachTo: document.body, global: { stubs: { ExcerptViewer } } })
	await flushPromises()
	return wrapper
}

describe('the page of a public link and the device', () => {
	beforeEach(() => {
		vi.resetModules()
		Object.values(device).forEach((fn) => fn.mockClear())
		window.history.replaceState(null, '', '/index.php/apps/cantoria/p/' + TOKEN)
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('removes only its own piece when that may not be kept, and keeps the worker for the others (A24)', async () => {
		const wrapper = await mountPage({ offline: false, offlineModule: true })
		expect(device.setUpWorker).toHaveBeenCalledWith('public', true)
		expect(device.removeEverything).not.toHaveBeenCalled()
		expect(device.forgetPieces).toHaveBeenCalledWith('public', [TOKEN])
		// and offers nothing to keep
		expect(wrapper.text()).not.toContain('Without a network')
		wrapper.unmount()
	})

	it('removes every public piece only when the module is off', async () => {
		const wrapper = await mountPage({ offline: false, offlineModule: false })
		expect(device.setUpWorker).toHaveBeenCalledWith('public', false)
		expect(device.forgetPieces).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('checks a piece that may be kept with the server, and removes nothing', async () => {
		const wrapper = await mountPage({ offline: true, offlineModule: true })
		expect(device.setUpWorker).toHaveBeenCalledWith('public', true)
		expect(device.checkPublicPiece).toHaveBeenCalledTimes(1)
		expect(device.forgetPieces).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Without a network')
		wrapper.unmount()
	})

	it('takes a page kept from before the server named the module as one with the module on', async () => {
		const wrapper = await mountPage({ offline: true })
		expect(device.setUpWorker).toHaveBeenCalledWith('public', true)
		wrapper.unmount()
	})

	it('says until when the link is valid, below the composer (U-9)', async () => {
		const wrapper = await mountPage({ offline: false, offlineModule: false, expires: '2026-12-27' })
		const line = wrapper.find('.public-excerpt__expires')
		expect(line.text()).toMatch(/^Valid until .*2026/)
		expect(line.element.previousElementSibling?.textContent).toContain('W. A. Mozart')
		wrapper.unmount()
	})

	it('says nothing about an end for a link without one (U-9)', async () => {
		const wrapper = await mountPage({ offline: false, offlineModule: false, expires: null })
		expect(wrapper.text()).not.toContain('Valid until')
		wrapper.unmount()
	})

	it('offers to reload a page that came without its score (U-9)', async () => {
		const wrapper = await mountPage({ offline: false, offlineModule: false, xml: '' })
		expect(wrapper.text()).toContain('Please reload the page')
		const reload = wrapper.findAll('button').filter((button) => button.text() === 'Reload')
		expect(reload).toHaveLength(1)
		wrapper.unmount()
	})
})
