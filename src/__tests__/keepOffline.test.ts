// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The switch "Available without a network" of the three pages
 * (src/sharing/KeepOffline.vue), mounted (T21): what it sends, when it is
 * locked, and the hint for an iPhone or iPad with the text of each page
 * (U27, U25).
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { homeScreenHint } from '../offline/hints.ts'
import KeepOffline from '../sharing/KeepOffline.vue'

const BASE = { kept: false, scanKept: false, scanOffered: true, allowed: true, withoutNetwork: false, busy: false }

function setUserAgent(agent: string): void {
	Object.defineProperty(navigator, 'userAgent', { configurable: true, get: () => agent })
}

describe('KeepOffline', () => {
	afterEach(() => setUserAgent('Mozilla/5.0 (X11; Linux x86_64) jsdom'))

	it('keeps a piece with the scan chosen before, and removes it again', async () => {
		const wrapper = mount(KeepOffline, { props: BASE })
		const switches = wrapper.findAll('input[type="checkbox"]')
		expect(switches).toHaveLength(2)
		// the scan first: only remembered while the piece is not kept
		await switches[1].setValue(true)
		expect(wrapper.emitted('change')).toBeUndefined()
		await switches[0].setValue(true)
		expect(wrapper.emitted('change')).toEqual([[true, true]])

		await wrapper.setProps({ kept: true, scanKept: true })
		await wrapper.findAll('input[type="checkbox"]')[0].setValue(false)
		expect(wrapper.emitted('change')?.at(-1)).toEqual([false, false])
	})

	it('changes the scan of a kept piece at once', async () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, kept: true } })
		await wrapper.findAll('input[type="checkbox"]')[1].setValue(true)
		expect(wrapper.emitted('change')).toEqual([[true, true]])
	})

	it('changes nothing without a network, and says so', () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, withoutNetwork: true } })
		for (const input of wrapper.findAll('input[type="checkbox"]')) {
			expect((input.element as HTMLInputElement).disabled).toBe(true)
		}
		expect(wrapper.text()).toContain('Needs a network to change.')
	})

	it('names the reason when the rights do not allow it, and offers no switch', () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, allowed: false, denial: 'The rights allow no copy.' } })
		expect(wrapper.text()).toContain('The rights allow no copy.')
		expect(wrapper.findAll('input')).toHaveLength(0)
	})

	it('shows an error under the switch as an alert', () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, error: 'There is not enough space on this device.' } })
		expect(wrapper.find('[role="alert"]').text()).toContain('There is not enough space on this device.')
	})

	it('names the size of the scans of a programme', () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, several: true, scanSize: 2 * 1024 * 1024 } })
		expect(wrapper.text()).toMatch(/With the scans \(PDF, 2(\.0)? MB\)/)
	})

	it('gives the hint for an iPhone before the first piece only, with the text of the page', () => {
		setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)')
		const first = mount(KeepOffline, { props: { ...BASE, firstKeep: true, place: 'public' } })
		expect(first.text()).toContain(homeScreenHint('public'))
		expect(homeScreenHint('public')).not.toContain('sign in')
		expect(mount(KeepOffline, { props: { ...BASE, firstKeep: true, place: 'folder' } }).text()).toContain('bookmark')
		expect(mount(KeepOffline, { props: { ...BASE, firstKeep: true } }).text()).toContain('sign in')
		expect(mount(KeepOffline, { props: { ...BASE, firstKeep: false } }).text()).not.toContain('iPhone')
	})

	it('gives no such hint on other devices', () => {
		const wrapper = mount(KeepOffline, { props: { ...BASE, firstKeep: true } })
		expect(wrapper.text()).not.toContain('iPhone')
	})
})
