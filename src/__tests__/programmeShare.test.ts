// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * "Share the programme with the choir" (src/components/ProgrammeShareDialog.vue),
 * mounted with the server replaced (T32): what the list says per piece,
 * including a link that ended (U40) and one whose age limits it (U41), the
 * reason of a piece that lasts shorter, and the message to the choir, which
 * can be changed and says until when, how to sign in, where the guide is
 * and what the singers may do with the pieces (U42, R B32, W B29).
 */
import type { ProgrammeShareItem, ProgrammeShareOptions, ProgrammeShareResult } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const server = vi.hoisted(() => ({
	options: null as unknown,
	result: null as unknown,
	shared: [] as unknown[],
	copied: [] as string[],
}))

vi.mock('../api.ts', () => ({
	errorMessage: (caught: unknown) => String(caught),
	fetchProgrammeShareOptions: vi.fn(async () => server.options),
	shareProgramme: vi.fn(async (_id: number, input: unknown) => {
		server.shared.push(input)
		return server.result
	}),
}))
vi.mock('../clipboard.ts', () => ({
	copyText: vi.fn(async (text: string) => {
		server.copied.push(text)
		return true
	}),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => '/index.php' + path }))

/** The dialog frame: its content and the action button */
const ActionDialog = defineComponent({
	name: 'ActionDialog',
	props: { name: String, submitLabel: String, busy: Boolean, error: String, submitDisabled: Boolean, done: Boolean, size: String },
	emits: ['submit', 'close'],
	setup(props, { slots, emit }) {
		return () => h('form', { class: 'dialog' }, [
			h('p', { class: 'dialog__error' }, props.error ?? ''),
			slots.default?.(),
			h('button', { type: 'button', class: 'dialog__submit', disabled: props.submitDisabled, onClick: () => emit('submit') }, props.submitLabel),
		])
	},
})

/** The date field: a plain input */
const DateField = defineComponent({
	name: 'DateField',
	props: { modelValue: String, min: String, max: String, label: String, required: Boolean },
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', { class: 'date', value: props.modelValue, onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value) })
	},
})

const { default: ProgrammeShareDialog } = await import('../components/ProgrammeShareDialog.vue')

function piece(id: number, title: string, extra: Partial<ProgrammeShareItem> = {}): ProgrammeShareItem {
	return { id, title, shelfmark: '1-' + id, allowed: true, reason: null, scan: false, offline: null, playback: true, existing: null, until: null, quantity: null, ...extra }
}

function options(items: ProgrammeShareItem[]): ProgrammeShareOptions {
	return { expires: '2027-01-15', latest: '2027-05-05', items }
}

async function mountDialog() {
	const wrapper = mount(ProgrammeShareDialog, {
		props: { eventId: 4, title: 'Adventskonzert' },
		global: { stubs: { ActionDialog, DateField } },
	})
	await flushPromises()
	return wrapper
}

async function share(wrapper: Awaited<ReturnType<typeof mountDialog>>, result: ProgrammeShareResult) {
	server.result = result
	await wrapper.find('.dialog__submit').trigger('click')
	await flushPromises()
}

describe('ProgrammeShareDialog', () => {
	beforeEach(() => {
		server.shared = []
		server.copied = []
	})

	it('says per piece what happens, also for a link that ended or that its age limits', async () => {
		server.options = options([
			piece(1, 'Ave verum'),
			piece(2, 'Abendlied', { existing: { id: 50, expires: '2026-10-01', limit: '2027-09-01' } }),
			piece(3, 'Locus iste', { existing: { id: 51, expires: '2026-12-01', limit: '2026-12-20' } }),
			piece(4, 'Jauchzet', { allowed: false, reason: 'Not released for choir members.' }),
		])
		const wrapper = await mountDialog()
		const text = wrapper.text()
		expect(text).toContain('A new link for the choir members.')
		expect(text).toContain('The link ended on')
		expect(text).toContain('it is extended to')
		expect(text).toContain('The link was made almost a year ago and lasts until')
		expect(text).toContain('Not shared: Not released for choir members.')
		// the blocked piece is no checkbox, the others are chosen
		expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(3)
	})

	it('names why a piece lasts shorter: the license or the age of the link', async () => {
		server.options = options([piece(1, 'Ave verum'), piece(2, 'Abendlied')])
		const wrapper = await mountDialog()
		await share(wrapper, {
			created: 1,
			extended: 1,
			kept: 0,
			failed: [],
			limited: [{ id: 1, title: 'Ave verum', expires: '2026-12-20', reason: 'license' }, { id: 2, title: 'Abendlied', expires: '2026-12-01', reason: 'age' }],
		})
		expect(server.shared).toEqual([{ items: [1, 2], expires: '2027-01-15', note: '', scan: false }])
		const text = wrapper.text()
		expect(text).toContain('Shared for a shorter time:')
		expect(text).toContain('the license ends then')
		expect(text).toContain('the link was made almost a year ago')
	})

	it('offers a message to the choir with the day, the sign-in, the guide and the limits of use, and lets it be changed', async () => {
		server.options = options([piece(1, 'Ave verum')])
		const wrapper = await mountDialog()
		await share(wrapper, { created: 1, extended: 0, kept: 0, failed: [], limited: [] })
		const area = wrapper.find('textarea')
		expect(area.attributes('readonly')).toBeUndefined()
		const message = (area.element as HTMLTextAreaElement).value
		expect(message).toContain('"Adventskonzert"')
		expect(message).toContain('until ')
		expect(message).toContain('/index.php/apps/cantoria/')
		expect(message).toContain('Sign in with your account of our Nextcloud.')
		expect(message).toContain('for-choir-members.md')
		expect(message).toContain('"Loop"')
		expect(message).toContain('please do not pass them on')

		await area.setValue('Liebe Altstimmen, hier sind die Noten.')
		const copy = wrapper.findAll('button').find((button) => button.text().includes('Copy message to the choir'))
		await copy?.trigger('click')
		await flushPromises()
		expect(server.copied).toEqual(['Liebe Altstimmen, hier sind die Noten.'])
	})

	it('promises "Loop" only when a piece can be played', async () => {
		server.options = options([piece(1, 'Ave verum', { playback: false })])
		const wrapper = await mountDialog()
		await share(wrapper, { created: 1, extended: 0, kept: 0, failed: [], limited: [] })
		const message = (wrapper.find('textarea').element as HTMLTextAreaElement).value
		expect(message).not.toContain('Loop')
		expect(message).toContain('please do not pass them on')
	})
})
