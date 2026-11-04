// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One limit for a small and one for a large screen in the score views
 * (D23, U2): every media query on the height in their styles is one of the
 * two of src/score/screen.ts, word for word; and the bar that stays at the
 * top keeps the focus visible below it (D20, WCAG 2.4.11).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { LARGE_SCREEN, SMALL_SCREEN } from '../score/screen.ts'
import { STICKY_OFFSET, useStickyHeader } from '../stickyHeader.ts'

const SOURCES = import.meta.glob(['../components/ScoreViewer.vue', '../sharing/ExcerptViewer.vue', '../components/PracticeLyrics.vue', '../components/PracticePitch.vue'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>

describe('screen sizes of the score views', () => {
	it('uses the two shared limits only', () => {
		expect(Object.keys(SOURCES)).toHaveLength(4)
		for (const [file, source] of Object.entries(SOURCES)) {
			const queries = [...source.matchAll(/@media ([^{]*height[^{]*)\{/g)].map((match) => match[1].trim())
			expect(queries.length, file).toBeGreaterThan(0)
			for (const query of queries) {
				expect([SMALL_SCREEN, LARGE_SCREEN], `${file}: ${query}`).toContain(query)
			}
			// and no other limit in the script
			expect(source, file).not.toMatch(/matchMedia\(\s*'\(/)
		}
	})

	it('a small screen is a phone upright or sideways, a large one wider and higher, with no gap between', () => {
		expect(SMALL_SCREEN).toContain('(max-height: 560px)')
		expect(LARGE_SCREEN).toContain('(min-height: 561px)')
		expect(SMALL_SCREEN).toContain('(max-width: 600px)')
	})
})

describe('useStickyHeader', () => {
	it('keeps the focus below a bar that appears later, in the container named', async () => {
		const shown = ref(false)
		const wrapper = mount(defineComponent({
			setup() {
				const bar = ref<HTMLElement | null>(null)
				useStickyHeader(bar, '.score-viewer')
				return () => h('div', { class: 'score-viewer' }, shown.value ? [h('div', { ref: bar, class: 'bar' })] : [])
			},
		}), { attachTo: document.body })
		const container = wrapper.find('.score-viewer').element as HTMLElement
		expect(container.style.scrollPaddingTop).toBe('')
		shown.value = true
		await nextTick()
		await nextTick()
		// jsdom has no layout: the bar is 0 pixels high, plus the gap
		expect(container.style.scrollPaddingTop).toBe('8px')
		wrapper.unmount()
		expect(container.style.scrollPaddingTop).toBe('')
	})

	it('keeps the room only within the part where the bar sticks, not for the whole page (D34)', async () => {
		const wrapper = mount(defineComponent({
			setup() {
				const bar = ref<HTMLElement | null>(null)
				useStickyHeader(bar, '.app-content', { within: true })
				return () => h('div', { class: 'app-content' }, [h('h2', 'List'), h('div', { class: 'excerpt-viewer' }, [h('div', { ref: bar })])])
			},
		}), { attachTo: document.body })
		await nextTick()
		const page = wrapper.find('.app-content').element as HTMLElement
		const view = wrapper.find('.excerpt-viewer').element as HTMLElement
		expect(page.style.scrollPaddingTop).toBe('')
		expect(view.style.getPropertyValue(STICKY_OFFSET)).toBe('8px')
		wrapper.unmount()
		expect(view.style.getPropertyValue(STICKY_OFFSET)).toBe('')
	})

	it('finds the scrolling content of the app pages by default', async () => {
		const wrapper = mount(defineComponent({
			setup() {
				const bar = ref<HTMLElement | null>(null)
				useStickyHeader(bar)
				return () => h('div', { id: 'app-content-vue' }, [h('div', { ref: bar })])
			},
		}), { attachTo: document.body })
		await nextTick()
		expect((document.getElementById('app-content-vue') as HTMLElement).style.scrollPaddingTop).toBe('8px')
		wrapper.unmount()
	})
})
