// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Input typed but not saved (src/unsaved.ts): a form registers while it is
 * shown, whatever would throw its changes away asks first, and the browser
 * asks before the page is left.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { confirmDiscard, hasUnsavedInput, useUnsavedGuard } from '../unsaved.ts'

const dirty = ref(false)
const Form = defineComponent({
	setup() {
		useUnsavedGuard(dirty)
		return () => h('form')
	},
})

describe('unsaved input', () => {
	it('asks only while a shown form has changes', async () => {
		const ask = vi.fn(async () => false)
		expect(await confirmDiscard(ask)).toBe(true)

		const wrapper = mount(Form)
		expect(await confirmDiscard(ask)).toBe(true)
		expect(ask).not.toHaveBeenCalled()

		dirty.value = true
		expect(hasUnsavedInput()).toBe(true)
		expect(await confirmDiscard(ask)).toBe(false)
		ask.mockResolvedValueOnce(true)
		expect(await confirmDiscard(ask)).toBe(true)
		expect(ask).toHaveBeenCalledTimes(2)

		wrapper.unmount()
		expect(hasUnsavedInput()).toBe(false)
		dirty.value = false
	})

	it('lets the browser ask before the page is left', () => {
		const wrapper = mount(Form)
		const event = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(event)
		expect(event.defaultPrevented).toBe(false)

		dirty.value = true
		const second = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(second)
		expect(second.defaultPrevented).toBe(true)
		wrapper.unmount()
		dirty.value = false
	})
})
