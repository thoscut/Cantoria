// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The stage view (src/sharing/stage.ts) and the question before leaving
 * with an unsaved recording (src/sharing/leaveGuard.ts), mounted in a
 * component with stand-ins for the whole screen and the wake lock (T21).
 */
import type { Ref } from 'vue'
import type { Stage } from '../sharing/stage.ts'

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { useLeaveGuard } from '../sharing/leaveGuard.ts'
import { useStage } from '../sharing/stage.ts'

/** The whole screen of the browser, as jsdom has none */
function fakeFullscreen() {
	let element: Element | null = null
	const claim = async (target: Element) => {
		element = target
		document.dispatchEvent(new Event('fullscreenchange'))
	}
	const request = vi.fn(function(this: Element) {
		return claim(this)
	})
	const exit = vi.fn(async () => {
		element = null
		document.dispatchEvent(new Event('fullscreenchange'))
	})
	Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => true })
	Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => element })
	Object.defineProperty(document, 'exitFullscreen', { configurable: true, value: exit })
	Object.defineProperty(Element.prototype, 'requestFullscreen', { configurable: true, value: request })
	return {
		request,
		exit,
		end: () => {
			// the browser's own way out (Escape in the whole screen)
			element = null
			document.dispatchEvent(new Event('fullscreenchange'))
		},
	}
}

function fakeWakeLock() {
	const release = vi.fn(async () => {})
	const request = vi.fn(async () => ({ release }))
	Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: { request } })
	return { request, release }
}

function mountStage(onLeave = vi.fn()) {
	let stage: Stage | null = null
	const wrapper = mount(defineComponent({
		setup() {
			stage = useStage(onLeave)
			return () => h('div', { class: stage!.on.value ? 'on' : 'off' }, [h('input', { class: 'field' })])
		},
	}), { attachTo: document.body })
	return { wrapper, stage: stage as unknown as Stage, onLeave }
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('stage view', () => {
	afterEach(() => {
		document.body.innerHTML = ''
		delete (Element.prototype as { requestFullscreen?: unknown }).requestFullscreen
	})

	it('puts the whole page on the screen, not one section, so that dialogs at the end of the page stay visible', async () => {
		const fullscreen = fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage } = mountStage()
		await stage.enter()
		expect(stage.on.value).toBe(true)
		expect(fullscreen.request).toHaveBeenCalledTimes(1)
		expect(fullscreen.request.mock.contexts[0]).toBe(document.documentElement)
		expect(document.fullscreenElement).toBe(document.documentElement)
		expect(lock.request).toHaveBeenCalledWith('screen')
	})

	it('ends with its button: the whole screen and the wake lock go, the focus goes back', async () => {
		const fullscreen = fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage, onLeave } = mountStage()
		await stage.toggle()
		await stage.toggle()
		expect(stage.on.value).toBe(false)
		expect(fullscreen.exit).toHaveBeenCalledTimes(1)
		expect(lock.release).toHaveBeenCalledTimes(1)
		expect(onLeave).toHaveBeenCalledTimes(1)
	})

	it('ends when the browser ends the whole screen (Escape there)', async () => {
		const fullscreen = fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage } = mountStage()
		await stage.enter()
		fullscreen.end()
		await flush()
		expect(stage.on.value).toBe(false)
		expect(lock.release).toHaveBeenCalledTimes(1)
	})

	it('with a dialog open ends only the whole screen: the stage view, the dialog and the focus stay (D33)', async () => {
		const fullscreen = fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage, onLeave } = mountStage()
		await stage.enter()
		const dialog = document.createElement('div')
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		document.body.appendChild(dialog)
		// the browser's Escape in the whole screen
		fullscreen.end()
		await flush()
		expect(stage.on.value).toBe(true)
		expect(onLeave).not.toHaveBeenCalled()
		expect(lock.release).not.toHaveBeenCalled()
		// the next Escape belongs to the dialog, the one after it ends the stage view
		document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(stage.on.value).toBe(true)
		dialog.remove()
		document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await flush()
		expect(stage.on.value).toBe(false)
		expect(onLeave).toHaveBeenCalledTimes(1)
	})

	it('asks for the whole screen first, while the browser still counts the tap, then keeps the screen on (U26)', async () => {
		const fullscreen = fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage } = mountStage()
		await stage.enter()
		expect(fullscreen.request.mock.invocationCallOrder[0]).toBeLessThan(lock.request.mock.invocationCallOrder[0]!)
	})

	it('ends with Escape also without the whole screen (an iPhone), but leaves Escape to fields and dialogs', async () => {
		Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => false })
		Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => null })
		fakeWakeLock()
		const { stage, wrapper } = mountStage()
		await stage.enter()
		expect(stage.on.value).toBe(true)

		wrapper.find('input').element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(stage.on.value).toBe(true)

		const dialog = document.createElement('div')
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		document.body.appendChild(dialog)
		document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(stage.on.value).toBe(true)
		dialog.remove()

		document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await flush()
		expect(stage.on.value).toBe(false)
	})

	it('stays the page without the list where the browser refuses the whole screen', async () => {
		const fullscreen = fakeFullscreen()
		fullscreen.request.mockRejectedValueOnce(new Error('not allowed'))
		const { stage } = mountStage()
		await stage.enter()
		expect(stage.on.value).toBe(true)
	})

	it('gives the wake lock back when the page goes', async () => {
		fakeFullscreen()
		const lock = fakeWakeLock()
		const { stage, wrapper } = mountStage()
		await stage.enter()
		wrapper.unmount()
		expect(lock.release).toHaveBeenCalledTimes(1)
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
	})
})

describe('leave guard', () => {
	let unsaved: Ref<boolean>

	beforeEach(() => {
		unsaved = ref(false)
	})

	function mountGuard() {
		return mount(defineComponent({
			setup() {
				useLeaveGuard(unsaved)
				return () => h('div')
			},
		}))
	}

	function leave(): boolean {
		const event = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(event)
		return event.defaultPrevented
	}

	it('asks only while a recording waits unsaved', async () => {
		const wrapper = mountGuard()
		expect(leave()).toBe(false)
		unsaved.value = true
		await nextTick()
		expect(leave()).toBe(true)
		unsaved.value = false
		expect(leave()).toBe(false)
		wrapper.unmount()
	})

	it('no longer asks once the page is gone', () => {
		const wrapper = mountGuard()
		unsaved.value = true
		wrapper.unmount()
		expect(leave()).toBe(false)
	})
})
