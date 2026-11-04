// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * "Shared with me" (src/sharing/MemberApp.vue), mounted with a stand-in for
 * the score view: the list names pieces and excerpts, a programme reads as
 * the heading of its pieces, "New" is part of the name of a piece's button
 * (U49, D35); one entry in the history for the pieces (U39); the stage view
 * shows only the title, the bar and the notes (U48, D27).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'anna', displayName: 'Anna', isAdmin: false }),
	getRequestToken: () => 'token',
}))

vi.mock('../sharing/api.ts', () => ({
	fetchMemberLinks: vi.fn(async () => []),
	fetchMemberScore: vi.fn(async () => '<score-partwise/>'),
	authorizeMemberPracticeAudio: vi.fn(async () => {}),
}))

const ExcerptViewer = defineComponent({
	name: 'ExcerptViewer',
	props: { stage: Boolean },
	setup(_props, { slots, expose }) {
		const score = ref<HTMLElement | null>(null)
		expose({ focusScore: () => score.value?.focus(), turn: () => 'end', busy: () => false })
		return () => h('div', { class: 'excerpt-viewer' }, [
			h('div', { class: 'bar' }, slots.bar?.()),
			h('div', { ref: score, class: 'excerpt-viewer__score', tabindex: '0' }),
		])
	},
})

const ADVENT = { key: 'p1', title: 'Adventskonzert', date: '2026-12-13', order: 0, position: 0 }

function link(token: string, title: string, programmes = [ADVENT]) {
	return {
		token: token.repeat(32),
		title,
		composer: 'J. A. P. Schulz',
		measure_from: null,
		measure_to: null,
		voice: null,
		verse: null,
		tempo: null,
		note: 'Bitte die zweite Strophe.',
		expires: '2026-12-27',
		canPlay: true,
		canExportAudio: false,
		reason: null,
		scan: true,
		credits: [{ role: 'Text', name: 'Matthias Claudius' }],
		notices: [],
		programmes: programmes.map((programme, position) => ({ ...programme, position })),
	}
}

async function mountPage(links: ReturnType<typeof link>[]) {
	delete (window as { _nc_initial_state?: unknown })._nc_initial_state
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = 'initial-state-cantoria-member'
	input.value = btoa(JSON.stringify({ links, offline: { enabled: false, version: '0.19.0' } }))
	document.body.appendChild(input)
	const { default: MemberApp } = await import('../sharing/MemberApp.vue')
	const wrapper = mount(MemberApp, { attachTo: document.body, global: { stubs: { ExcerptViewer } } })
	await flushPromises()
	return wrapper
}

describe('"Shared with me"', () => {
	beforeEach(() => {
		vi.resetModules()
		window.localStorage.clear()
		window.history.replaceState(null, '', '/index.php/apps/cantoria/')
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('names pieces and excerpts, and a programme apart from its pieces with its date muted (U49, D35)', async () => {
		const wrapper = await mountPage([link('a', 'Der Mond ist aufgegangen'), link('b', 'Großer Gott')])
		expect(wrapper.find('.member-app__list-title').text()).toBe('2 pieces and excerpts')
		const group = wrapper.find('.member-app__group-title')
		expect(group.text()).toContain('Adventskonzert')
		expect(group.find('.member-app__group-date').text()).toContain('·')
		wrapper.unmount()
	})

	it('says "New" in the name of the button of a new piece, not only beside it (U49)', async () => {
		// the first visit marks nothing; the next one marks what came since
		const first = await mountPage([link('a', 'Der Mond ist aufgegangen')])
		first.unmount()
		document.body.innerHTML = ''
		vi.resetModules()
		const wrapper = await mountPage([link('a', 'Der Mond ist aufgegangen'), link('b', 'Großer Gott')])
		const buttons = wrapper.findAll('.member-app__open')
		expect(buttons[1].text()).toBe('Großer Gott, New')
		expect(buttons[0].text()).toBe('Der Mond ist aufgegangen')
		// the visible word is not read twice
		expect(wrapper.find('.member-app__new').attributes('aria-hidden')).toBe('true')
		wrapper.unmount()
	})

	it('adds one entry to the history for the pieces: back returns to the list (U39)', async () => {
		const wrapper = await mountPage([link('a', 'Der Mond ist aufgegangen'), link('b', 'Großer Gott')])
		const before = window.history.length
		await wrapper.findAll('.member-app__open')[0].trigger('click')
		await flushPromises()
		await wrapper.findAll('.member-app__open')[1].trigger('click')
		await flushPromises()
		expect(window.history.length).toBe(before + 1)
		expect(window.location.search).toBe('?link=' + 'b'.repeat(32))
		wrapper.unmount()
	})

	it('shows only the title, the bar and the notes in the stage view, and the guide link goes (U48)', async () => {
		const wrapper = await mountPage([link('a', 'Der Mond ist aufgegangen'), link('b', 'Großer Gott')])
		await wrapper.findAll('.member-app__open')[0].trigger('click')
		await flushPromises()
		expect(wrapper.find('.member-app__help').exists()).toBe(true)
		await wrapper.find('button[aria-pressed]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.member-app__help').exists()).toBe(false)
		expect(wrapper.find('.member-app__excerpt--notes-on-stage').exists()).toBe(true)
		// note, credits, what is shared and the scan fold away by this class
		for (const selector of ['.member-app__note', '.scan-frame', 'button[aria-pressed]']) {
			expect(wrapper.find(selector).classes(), selector).toContain('member-app__off-stage')
		}
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		await wrapper.find('.bar button[aria-label="Leave the stage view"]').trigger('click')
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.find('button[aria-pressed]').element)
		wrapper.unmount()
	})
})
