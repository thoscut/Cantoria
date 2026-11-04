// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The scan of a piece in the page (src/sharing/ScanFrame.vue): on a phone a
 * view of its own that the back gesture closes (U39), and closed by the
 * person, the focus goes back to "Show the scan (PDF)" (D28), also for the
 * frame on a larger screen.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../offline/device.ts', () => ({
	scanInMemory: vi.fn(async () => 'blob:scan'),
}))

/**
 * A phone or a larger screen, as the media query of the view asks.
 *
 * @param phone - the screen is a phone's
 */
function screen(phone: boolean): void {
	Object.defineProperty(window, 'matchMedia', {
		configurable: true,
		value: (query: string) => ({ matches: phone, media: query, addEventListener: () => {}, removeEventListener: () => {} }),
	})
}

/** Back, as the browser or the back gesture of a phone does it (jsdom fires popstate late) */
async function back(): Promise<void> {
	window.history.back()
	await new Promise((resolve) => setTimeout(resolve, 20))
	await flushPromises()
}

async function mountScan() {
	const { default: ScanFrame } = await import('../sharing/ScanFrame.vue')
	return mount(ScanFrame, { props: { collection: 'member', pieceKey: 'a'.repeat(32), title: 'Abendlied' }, attachTo: document.body })
}

function button(text: string): HTMLElement | undefined {
	return [...document.querySelectorAll<HTMLElement>('button')].find((element) => element.textContent?.trim() === text)
}

describe('the scan in the page', () => {
	beforeEach(() => {
		URL.revokeObjectURL = vi.fn()
		window.history.replaceState({ cantoriaLink: 'a'.repeat(32) }, '', '/index.php/apps/cantoria/?link=' + 'a'.repeat(32))
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('on a phone adds an entry to the history; "Back to the notes" takes it and puts the focus back', async () => {
		screen(true)
		const wrapper = await mountScan()
		const before = window.history.length
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(document.querySelector('iframe')?.getAttribute('src')).toBe('blob:scan')
		expect(window.history.length).toBe(before + 1)
		// the same address: the page keeps its piece
		expect(window.location.search).toBe('?link=' + 'a'.repeat(32))
		button('Back to the notes')!.click()
		await back()
		expect(document.querySelector('iframe')).toBeNull()
		expect(document.activeElement).toBe(button('Show the scan (PDF)'))
		wrapper.unmount()
	})

	it('on a phone closes with the back gesture, and the focus is back on the button (U39, D28)', async () => {
		screen(true)
		const wrapper = await mountScan()
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(document.querySelector('iframe')).not.toBeNull()
		await back()
		expect(document.querySelector('iframe')).toBeNull()
		expect(document.activeElement).toBe(button('Show the scan (PDF)'))
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:scan')
		wrapper.unmount()
	})

	it('on a larger screen closes the frame without an entry, and the focus is back on the button', async () => {
		screen(false)
		const wrapper = await mountScan()
		const before = window.history.length
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(window.history.length).toBe(before)
		button('Close the scan')!.click()
		await flushPromises()
		expect(document.querySelector('iframe')).toBeNull()
		expect(document.activeElement).toBe(button('Show the scan (PDF)'))
		wrapper.unmount()
	})
})
