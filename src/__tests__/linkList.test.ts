// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The list of links (src/components/LinkList.vue), mounted: links that
 * ended in the last 30 days stand under a heading of their own and can be
 * extended (U40); the dialog suggests the latest day the server allows and
 * says what sets it (U41).
 */
import type { ExcerptLink } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, ref } from 'vue'
import { toIsoDate } from '../format.ts'

vi.mock('../api.ts', () => ({
	errorMessage: (caught: unknown) => String(caught),
	extendLink: vi.fn(),
	linkUrl: (token: string) => '/s/' + token,
	revokeLink: vi.fn(),
}))
vi.mock('../store.ts', () => ({
	entriesById: computed(() => new Map()),
	isLibrarian: ref(false),
	openEntry: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const ActionDialog = defineComponent({
	name: 'ActionDialog',
	props: { name: String, submitLabel: String, busy: Boolean, error: String, variant: String },
	setup(props, { slots }) {
		return () => h('div', { class: 'dialog', 'data-name': props.name }, slots.default?.())
	},
})
const DateField = defineComponent({
	name: 'DateField',
	props: { modelValue: String, min: String, max: String, label: String, required: Boolean },
	setup(props) {
		return () => h('input', { class: 'date', value: props.modelValue, 'data-max': props.max, 'aria-label': props.label })
	},
})

const { default: LinkList } = await import('../components/LinkList.vue')

function day(offset: number): string {
	const date = new Date()
	date.setDate(date.getDate() + offset)
	return toIsoDate(date)
}

function link(id: number, expires: string, extra: Partial<ExcerptLink> = {}): ExcerptLink {
	return {
		id,
		token: 'abc',
		copy_id: 1,
		file_id: null,
		measure_from: null,
		measure_to: null,
		voice: null,
		verse: null,
		tempo: null,
		note: null,
		task_id: null,
		circle: 'members',
		own: true,
		created_by_name: 'Lena',
		created: 1761134400,
		expires,
		revoked: null,
		hits: 3,
		...extra,
	}
}

function mountList(links: ExcerptLink[]) {
	return mount(LinkList, { props: { links }, global: { stubs: { ActionDialog, DateField } } })
}

describe('LinkList', () => {
	it('lists the links that ended under a heading of their own, to be extended', () => {
		const wrapper = mountList([link(1, day(40)), link(2, day(-5), { extend_until: day(150), extend_limit: 'six_months' })])
		const heading = wrapper.find('.link-list__heading')
		expect(heading.text()).toBe('Ended in the last 30 days')
		expect(heading.element.tagName).toBe('H3')
		const items = wrapper.findAll('li')
		expect(items).toHaveLength(2)
		expect(items[1].text()).toContain('Ended on')
		expect(items[1].text()).not.toContain('Valid until')
		expect(items[1].findAll('button').map((button) => button.text())).toContain('Extend')
		expect(items[1].find('[aria-label="Copy link"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('No active links.')
	})

	it('suggests the latest day the server allows and says what sets it', async () => {
		const until = day(60)
		const wrapper = mountList([link(1, day(3), { extend_until: until, extend_limit: 'license' })])
		await wrapper.findAll('button').find((button) => button.text() === 'Extend')?.trigger('click')
		await flushPromises()
		const field = wrapper.find('input.date')
		expect((field.element as HTMLInputElement).value).toBe(until)
		expect(field.attributes('data-max')).toBe(until)
		expect(wrapper.find('.dialog').text()).toContain('The license that releases the piece ends on')
	})

	it('says when a link is too old to be extended, and offers no button', () => {
		const wrapper = mountList([link(1, day(3), { extend_until: null, extend_limit: 'age' })])
		expect(wrapper.text()).toContain('Made more than a year ago: it cannot be extended.')
		expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('Extend')
	})

	it('gives the heading the level below the list', () => {
		const wrapper = mount(LinkList, { props: { links: [link(2, day(-1))], headingLevel: 4 }, global: { stubs: { ActionDialog, DateField } } })
		expect(wrapper.find('.link-list__heading').element.tagName).toBe('H4')
		expect(wrapper.text()).toContain('No active links.')
	})
})
