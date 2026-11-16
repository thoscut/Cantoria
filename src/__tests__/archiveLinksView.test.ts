// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The view "Links" (src/components/LinksView.vue), mounted: links that end
 * within a week come first, the soonest at the top, and are counted; the
 * others follow by shelf mark, and links that have ended are not among the
 * first (U-7). The header speaks of links to pieces and excerpts (U-13).
 */
import type { ExcerptLink } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h } from 'vue'
import { toIsoDate } from '../format.ts'

function day(offset: number): string {
	const date = new Date()
	date.setDate(date.getDate() + offset)
	return toIsoDate(date)
}

function link(id: number, copy: number, expires: string): ExcerptLink {
	return {
		id,
		token: null,
		copy_id: copy,
		file_id: null,
		measure_from: null,
		measure_to: null,
		voice: null,
		verse: null,
		tempo: null,
		note: null,
		task_id: null,
		circle: 'internal',
		own: false,
		created_by_name: null,
		created: 1761134400,
		expires,
		revoked: null,
		hits: 0,
	}
}

const fetched: { links: ExcerptLink[] } = { links: [] }

vi.mock('../api.ts', () => ({
	errorMessage: (caught: unknown) => String(caught),
	fetchLinks: async () => fetched.links,
}))
vi.mock('../store.ts', () => ({
	entriesById: computed(() => new Map([[1, { shelfmark: '2-1' }], [2, { shelfmark: '10-1' }], [3, { shelfmark: '3-1' }]])),
	isModuleActive: () => false,
}))
vi.mock('../navigation.ts', () => ({ navigate: vi.fn() }))
// The parts of the view are not under test here: the header shows its
// description, the list the ids of its links in their order
vi.mock('../components/ViewHeader.vue', () => ({
	default: defineComponent({
		props: { title: String, description: String },
		setup(props) {
			return () => h('header', props.description)
		},
	}),
}))
vi.mock('../components/LinkList.vue', () => ({
	default: defineComponent({
		props: { links: { type: Array, default: () => [] } },
		setup(props) {
			return () => h('ol', (props.links as ExcerptLink[]).map((item) => h('li', String(item.id))))
		},
	}),
}))
vi.mock('../components/ReloadError.vue', () => ({ default: defineComponent({ render: () => null }) }))

const { default: LinksView } = await import('../components/LinksView.vue')

async function mountView(links: ExcerptLink[]) {
	fetched.links = links
	const wrapper = mount(LinksView)
	await flushPromises()
	return wrapper
}

describe('LinksView', () => {
	it('puts the links that end within a week first, the soonest at the top, and counts them', async () => {
		const wrapper = await mountView([
			link(1, 2, day(40)),
			link(2, 1, day(40)),
			link(3, 2, day(6)),
			link(4, 3, day(0)),
			// ended: not "ending within a week", it stays where the shelf mark puts it
			link(5, 1, day(-3)),
			link(6, 3, day(8)),
		])
		expect(wrapper.findAll('li').map((item) => item.text())).toEqual(['4', '3', '5', '2', '6', '1'])
		expect(wrapper.findAll('.links-view__count').map((line) => line.text())).toEqual(['5 active links', '2 links end within a week'])
	})

	it('has no line about ending links when none ends within a week', async () => {
		const wrapper = await mountView([link(1, 2, day(40)), link(2, 1, day(-3))])
		expect(wrapper.findAll('li').map((item) => item.text())).toEqual(['2', '1'])
		expect(wrapper.findAll('.links-view__count').map((line) => line.text())).toEqual(['1 active link'])
	})

	it('speaks of links to pieces and excerpts in its header', async () => {
		const wrapper = await mountView([link(1, 1, day(40))])
		expect(wrapper.find('header').text()).toMatch(/^All active links to pieces and excerpts\. /)
	})
})
