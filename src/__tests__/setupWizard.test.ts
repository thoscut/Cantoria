// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The guided setup (src/components/settings/SetupWizard.vue), mounted with
 * its forms replaced: where it starts, how it moves between its pages, that
 * "Continue" saves what a form holds and keeps the functions as shown, that
 * it asks before input that is not saved is thrown away, and how it closes.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { normalizeSettings } from '../settings.ts'

const confirmDiscard = vi.fn(async () => true)
vi.mock('../unsaved.ts', () => ({ confirmDiscard: () => confirmDiscard(), useUnsavedGuard: vi.fn() }))
vi.mock('../api.ts', () => ({ errorMessage: (caught: unknown) => String(caught) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

/**
 * A form of the settings page, replaced by its name.
 *
 * @param name - name of the component
 */
function form(name: string) {
	return defineComponent({ name, setup: () => () => h('div', { class: 'form', 'data-form': name }) })
}

/** The roles, as a form that says whether it holds input and can save it. */
const rolesDirty = ref(false)
const saveRoles = vi.fn(async () => true)
const RoleSettings = defineComponent({
	name: 'RoleSettings',
	props: { embedded: Boolean },
	setup(props, { expose }) {
		expose({ dirty: rolesDirty, save: saveRoles })
		return () => h('div', { class: 'form', 'data-form': 'RoleSettings', 'data-embedded': String(props.embedded) })
	},
})

/** The functions: the guided setup asks them to keep what they show. */
const keepAsShown = vi.fn(async () => true)
const ModuleSettings = defineComponent({
	name: 'ModuleSettings',
	setup(_props, { expose }) {
		expose({ keepAsShown })
		return () => h('div', { class: 'form', 'data-form': 'ModuleSettings' })
	},
})

const NcDialog = defineComponent({
	name: 'NcDialog',
	emits: ['update:open'],
	setup(_props, { slots }) {
		return () => h('div', { class: 'dialog' }, [slots.default?.(), h('div', { class: 'actions' }, slots.actions?.())])
	},
})

const { default: SetupWizard } = await import('../components/settings/SetupWizard.vue')

const ROLE_LABELS = { librarian: 'Catalog (music librarian)', conductor: 'Conductor', member: 'Choir members' }
const FRESH = { archive: { id: 0 }, roles: {}, roleLabels: ROLE_LABELS, admin: true }
const READY = {
	archive: { id: 7, path: '/Demo archive', user: 'demo-admin' },
	roles: { librarian: ['archive'] },
	roleLabels: ROLE_LABELS,
	modulesChosen: true,
	admin: true,
	catalog: { copies: 4, files: 9 },
}

function wizard(raw: Record<string, unknown>) {
	return mount(SetupWizard, {
		props: { settings: normalizeSettings(raw) },
		global: {
			stubs: {
				NcDialog,
				ArchiveFolderSettings: form('ArchiveFolderSettings'),
				ModuleSettings,
				RoleSettings,
				ArchiveAccessCheck: form('ArchiveAccessCheck'),
				GuideLink: true,
			},
		},
	})
}

type Wizard = ReturnType<typeof wizard>

/**
 * Press a button of the dialog by its text.
 *
 * @param wrapper - the mounted dialog
 * @param text - text of the button
 */
async function press(wrapper: Wizard, text: string): Promise<void> {
	// the buttons of the list of pages start with their number
	const button = wrapper.findAll('button').find((candidate) => candidate.text().trim().replace(/^\d+/, '').startsWith(text))
	expect(button, `button "${text}"`).toBeDefined()
	await button!.trigger('click')
	await flushPromises()
}

const heading = (wrapper: Wizard) => wrapper.get('h3').text()
const forms = (wrapper: Wizard) => wrapper.findAll('.form').map((found) => found.attributes('data-form'))
const buttons = (wrapper: Wizard) => wrapper.findAll('.actions button').map((button) => button.text().trim())

beforeEach(() => {
	confirmDiscard.mockReset()
	confirmDiscard.mockResolvedValue(true)
	rolesDirty.value = false
	saveRoles.mockReset()
	saveRoles.mockResolvedValue(true)
	keepAsShown.mockClear()
})

describe('guided setup, mounted', () => {
	it('leads a new instance from the welcome through every page, each form once', async () => {
		const wrapper = wizard(FRESH)
		expect(heading(wrapper)).toBe('Welcome')
		expect(buttons(wrapper)).toEqual(['Later', 'Begin the setup'])
		// Welcome and overview frame the five steps and carry no number
		expect(wrapper.text()).not.toContain('Step ')
		const seen: string[] = []
		for (let page = 0; page < 6; page++) {
			await press(wrapper, page === 0 ? 'Begin the setup' : 'Continue')
			seen.push(`${wrapper.find('.wizard__counter').exists() ? wrapper.get('.wizard__counter').text() : '-'}: ${heading(wrapper)}`)
			expect(forms(wrapper).length).toBeLessThanOrEqual(1)
		}
		expect(seen).toEqual([
			'Step 1 of 5: Archive folder',
			'Step 2 of 5: Functions for your choir',
			'Step 3 of 5: Roles and groups',
			'Step 4 of 5: Access to the archive folder',
			'Step 5 of 5: Catalog and files',
			'-: Overview',
		])
		// On the last page "Finish" closes; "Later" would do the same
		expect(buttons(wrapper)).toEqual(['Back', 'Finish'])
		await press(wrapper, 'Finish')
		expect(wrapper.emitted('close')).toEqual([[true]])
	})

	it('marks the page it is on and says the state of each step in words', async () => {
		const wrapper = wizard({ ...FRESH, archive: { id: 7, path: '/Demo archive' } })
		// The archive folder is there: it starts on the first step that is not done
		expect(heading(wrapper)).toBe('Functions for your choir')
		const current = wrapper.findAll('nav button').filter((button) => button.attributes('aria-current') === 'step')
		expect(current.map((button) => button.text())).toEqual([expect.stringContaining('Functions for your choir')])
		const archive = wrapper.findAll('nav button').find((button) => button.text().includes('Archive folder'))!
		expect(archive.text()).toContain('Completed')
		expect(wrapper.get('.wizard__title .wizard__badge').text()).toBe('Still to do')
		// A step that is done shows a check mark instead of its number, an open one its number
		expect(archive.text()).not.toMatch(/^1/)
		expect(wrapper.findAll('nav button').find((button) => button.text().includes('Roles and groups'))!.text()).toMatch(/^3/)
	})

	it('"Continue" keeps the functions as shown, once: that makes the step done', async () => {
		const open = wizard({ ...FRESH, archive: { id: 7, path: '/Demo archive' } })
		expect(open.text()).toContain('"Continue" keeps the functions as they are shown here.')
		await press(open, 'Continue')
		expect(keepAsShown).toHaveBeenCalledTimes(1)
		expect(heading(open)).toBe('Roles and groups')
		// Chosen already: nothing is sent, and going back sends nothing either
		keepAsShown.mockClear()
		const chosen = wizard({ ...FRESH, archive: { id: 7, path: '/Demo archive' }, modulesChosen: true, concertFolder: true })
		await press(chosen, 'Functions for your choir')
		await press(chosen, 'Continue')
		await press(open, 'Back')
		await press(open, 'Back')
		expect(keepAsShown).not.toHaveBeenCalled()
		// A failure is shown by the form and does not hold up
		keepAsShown.mockResolvedValueOnce(false)
		await press(open, 'Continue')
		await press(open, 'Continue')
		expect(heading(open)).toBe('Roles and groups')
	})

	it('"Save and continue" saves what the form holds and stays when that fails', async () => {
		const wrapper = wizard(FRESH)
		await press(wrapper, 'Roles and groups')
		// In the guided setup the button of the form steps back behind "Save and continue"
		expect(wrapper.get('.form').attributes('data-embedded')).toBe('true')
		expect(buttons(wrapper)).toEqual(['Later', 'Back', 'Continue'])
		rolesDirty.value = true
		await flushPromises()
		expect(buttons(wrapper)).toEqual(['Later', 'Back', 'Save and continue'])
		confirmDiscard.mockClear()
		saveRoles.mockResolvedValueOnce(false)
		await press(wrapper, 'Save and continue')
		expect(heading(wrapper)).toBe('Roles and groups')
		await press(wrapper, 'Save and continue')
		expect(saveRoles).toHaveBeenCalledTimes(2)
		expect(heading(wrapper)).toBe('Access to the archive folder')
		// Saved, not thrown away: nothing was asked
		expect(confirmDiscard).not.toHaveBeenCalled()
	})

	it('without an archive folder the access check points to the archive folder', async () => {
		const wrapper = wizard(FRESH)
		await press(wrapper, 'Access to the archive folder')
		expect(forms(wrapper)).toEqual([])
		await press(wrapper, 'To the archive folder')
		expect(forms(wrapper)).toEqual(['ArchiveFolderSettings'])
	})

	it('asks before input that is not saved is thrown away, and stays when the answer is no', async () => {
		const wrapper = wizard(FRESH)
		await press(wrapper, 'Roles and groups')
		confirmDiscard.mockClear()
		confirmDiscard.mockResolvedValue(false)
		await press(wrapper, 'Continue')
		await press(wrapper, 'Back')
		await press(wrapper, 'Overview')
		await press(wrapper, 'Later')
		wrapper.getComponent(NcDialog).vm.$emit('update:open', false)
		await flushPromises()
		expect(confirmDiscard).toHaveBeenCalledTimes(5)
		expect(heading(wrapper)).toBe('Roles and groups')
		expect(wrapper.emitted('close')).toBeUndefined()
		// Escape and the close button are "Later"
		confirmDiscard.mockResolvedValue(true)
		wrapper.getComponent(NcDialog).vm.$emit('update:open', false)
		await flushPromises()
		expect(wrapper.emitted('close')).toEqual([[false]])
	})

	it('the overview leads to the steps that are open, the catalog page to the backup', async () => {
		const wrapper = wizard({ ...READY, catalog: { copies: 0, files: 0 } })
		expect(heading(wrapper)).toBe('Catalog and files')
		await press(wrapper, 'Restore a complete backup instead')
		expect(wrapper.emitted('section')).toEqual([['backup']])
		await press(wrapper, 'Continue')
		expect(heading(wrapper)).toBe('Overview')
		// archive, functions and roles are done; access (not checked), import and file sync are open
		expect(wrapper.findAll('.wizard__summary button').length).toBe(3)
		expect(wrapper.text()).not.toContain('Cantoria is set up.')
		await press(wrapper, 'Go to step Access')
		expect(heading(wrapper)).toBe('Access to the archive folder')
		expect(forms(wrapper)).toEqual(['ArchiveAccessCheck'])
	})

	it('with everything set up it opens on the overview, and only admins are offered the backup', async () => {
		const ready = wizard(READY)
		expect(heading(ready)).toBe('Overview')
		// The access check has not run on this page: one step is still open
		expect(ready.findAll('.wizard__summary button').length).toBe(1)
		const delegated = wizard({ ...READY, admin: false, catalog: { copies: 0, files: 0 } })
		expect(delegated.text()).not.toContain('Restore a complete backup instead')
	})
})
