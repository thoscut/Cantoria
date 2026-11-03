// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The concert folder at the music stand (U22, U29, U28, D26, U37, U39,
 * D27), mounted with a stand-in for the score view: after every change of
 * piece the focus is in the notes, so that the page-turn pedal (Page Down)
 * turns at once, at the end of a piece says what comes next and then opens
 * it, and while the next piece loads or is engraved a press skips nothing;
 * the back gesture returns to the list; the switch for a programme names
 * the pieces that stay behind; "Next" stands once.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted, ref } from 'vue'

/** The score of the server; a test may hold it back, as a slow network does */
const server = vi.hoisted(() => ({ hold: null as Promise<void> | null }))

vi.mock('../sharing/api.ts', () => ({
	fetchFolderConcerts: vi.fn(async () => []),
	fetchFolderScore: vi.fn(async (id: number) => {
		await server.hold
		return `<score-partwise id="${id}"/>`
	}),
}))

/** The engraving of the score view; a test may hold it back, as an older iPad does */
const engraving = { hold: null as Promise<void> | null }

/** The score view: two systems per piece, the focus into the notes once engraved */
const ExcerptViewer = defineComponent({
	name: 'ExcerptViewer',
	props: { xml: { type: String, default: '' }, focusOnReady: Boolean, stage: Boolean, printable: Boolean },
	setup(props, { slots, expose }) {
		const score = ref<HTMLElement | null>(null)
		const hold = engraving.hold
		const engraved = ref(hold === null)
		let systems = 1
		const turn = (direction: 1 | -1) => {
			if (!engraved.value) {
				return 'busy'
			}
			if (direction < 0) {
				systems++
				return 'turned'
			}
			if (systems === 0) {
				return 'end'
			}
			systems--
			return 'turned'
		}
		const focusScore = () => score.value?.focus()
		expose({ turn, focusScore, busy: () => !engraved.value })
		onMounted(async () => {
			if (hold !== null) {
				await hold
				engraved.value = true
				await flushPromises()
			}
			if (props.focusOnReady) {
				focusScore()
			}
		})
		const onKeydown = (event: KeyboardEvent) => {
			if (engraved.value && (event.key === 'PageDown' || event.key === 'PageUp') && turn(event.key === 'PageDown' ? 1 : -1) === 'turned') {
				event.preventDefault()
			}
		}
		return () => h('div', { class: 'excerpt-viewer', 'data-xml': props.xml, onKeydown }, [
			h('div', { class: 'bar' }, slots.bar?.()),
			engraved.value ? h('div', { ref: score, class: 'excerpt-viewer__score', tabindex: '0' }) : h('p', 'Engraving the score …'),
		])
	},
})

/**
 * Something held back until the test lets it go.
 */
function held(): { promise: Promise<void>, release: () => void } {
	let release = () => {}
	const promise = new Promise<void>((resolve) => {
		release = resolve
	})
	return { promise, release }
}

function item(id: number, title: string, extra: Record<string, unknown> = {}) {
	return {
		id,
		position: id,
		title,
		composer: 'J. Rheinberger',
		shelfmark: null,
		participation: null,
		note: null,
		encore: false,
		duration: null,
		score: true,
		scan: false,
		scanSize: null,
		canPlay: true,
		reason: null,
		offline: true,
		offlineReason: null,
		print: true,
		practiceKey: null,
		...extra,
	}
}

function setState(offline: boolean): void {
	// @nextcloud/initial-state keeps what it read on the window
	delete (window as { _nc_initial_state?: unknown })._nc_initial_state
	const concerts = [{
		id: 1,
		kind: 'concert',
		title: 'Herbstkonzert',
		date: '2026-11-15',
		startTime: null,
		location: null,
		status: 'fixed',
		items: [item(1, 'Abendlied'), item(2, 'Ave verum'), item(3, 'Ubi caritas', { offline: false, offlineReason: 'the rights allow no copy.' })],
	}]
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = 'initial-state-cantoria-folder'
	input.value = btoa(JSON.stringify({ concerts, version: '0.19.0', offline }))
	document.body.appendChild(input)
}

function pageDown(target: Element | null, key = 'PageDown'): KeyboardEvent {
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })
	;(target ?? document.body).dispatchEvent(event)
	return event
}

async function mountFolder(offline = false) {
	setState(offline)
	const { default: ConcertFolderApp } = await import('../sharing/ConcertFolderApp.vue')
	const wrapper = mount(ConcertFolderApp, { attachTo: document.body, global: { stubs: { ExcerptViewer } } })
	await flushPromises()
	return wrapper
}

describe('concert folder at the music stand', () => {
	beforeEach(() => {
		vi.resetModules()
		window.history.replaceState(null, '', '/index.php/apps/cantoria/concert-folder')
	})

	afterEach(() => {
		document.body.innerHTML = ''
		server.hold = null
		engraving.hold = null
	})

	it('puts the focus into the notes after a piece is opened, so that the pedal turns at once', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		const score = wrapper.find('.excerpt-viewer__score').element
		expect(document.activeElement).toBe(score)
		// the first press turns a system: the notes take it
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		expect(document.activeElement).toBe(score)
		wrapper.unmount()
	})

	it('at the end of a piece says what comes next, opens it with the next press, and the pedal goes on there', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		pageDown(document.activeElement)
		// no system left: the next piece is offered, the focus on "Next"
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		await flushPromises()
		expect(wrapper.find('[role="status"]').text()).toContain('Ave verum')
		expect(document.activeElement?.textContent).toContain('Next')
		// the second press opens it, and the focus is in its notes again
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		await flushPromises()
		expect(wrapper.find('.excerpt-viewer').attributes('data-xml')).toContain('id="2"')
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		expect(window.location.search).toBe('?item=2')
		wrapper.unmount()
	})

	it('turns the notes with the pedal also while the heading has the focus', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		const heading = wrapper.find('.folder-app__piece h2').element as HTMLElement
		heading.focus()
		expect(pageDown(heading).defaultPrevented).toBe(true)
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		wrapper.unmount()
	})

	it('has one "Next" per piece, in the bar of the notes', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		const next = wrapper.findAll('button').filter((button) => button.text().startsWith('Next'))
		expect(next).toHaveLength(1)
		expect(next[0].attributes('aria-label')).toBe('Next: Ave verum')
		wrapper.unmount()
	})

	it('adds the piece to the history, and the back gesture returns to the list', async () => {
		const wrapper = await mountFolder()
		const before = window.history.length
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		expect(window.history.length).toBe(before + 1)
		expect(window.location.search).toBe('?item=1')
		window.history.replaceState(null, '', '/index.php/apps/cantoria/concert-folder')
		window.dispatchEvent(new PopStateEvent('popstate'))
		await flushPromises()
		expect(wrapper.find('.folder-app__piece').exists()).toBe(false)
		expect(wrapper.find('.folder-app__list--folded').exists()).toBe(false)
		wrapper.unmount()
	})

	it('adds one entry for the pieces of a concert: back always returns to the list (U39)', async () => {
		const wrapper = await mountFolder()
		const before = window.history.length
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		// "Next" and the pedal take the place of the entry
		await wrapper.find('button[aria-label="Next: Ave verum"]').trigger('click')
		await flushPromises()
		expect(window.location.search).toBe('?item=2')
		expect(window.history.length).toBe(before + 1)
		// the back gesture closed something of the piece (the scan): the piece stays as it is
		window.dispatchEvent(new PopStateEvent('popstate'))
		await flushPromises()
		expect(wrapper.find('.excerpt-viewer').attributes('data-xml')).toContain('id="2"')
		wrapper.unmount()
	})

	it('skips no piece when the pedal is pressed while the next one loads and is engraved (U37)', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		pageDown(document.activeElement)
		// the end of "Abendlied": "Next" is offered, also on the screen
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		await flushPromises()
		expect(wrapper.find('button[aria-label="Next: Ave verum"]').text()).toBe('Next: Ave verum')
		const network = held()
		const engraver = held()
		server.hold = network.promise
		engraving.hold = engraver.promise
		pageDown(document.activeElement)
		await flushPromises()
		expect(window.location.search).toBe('?item=2')
		// two impatient presses while "Ave verum" comes from the server ...
		for (let press = 0; press < 2; press++) {
			expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
			await flushPromises()
		}
		network.release()
		await flushPromises()
		expect(wrapper.text()).toContain('Engraving the score')
		// ... and two while it is engraved: nothing is offered, nothing opens
		for (let press = 0; press < 2; press++) {
			expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
			await flushPromises()
		}
		expect(window.location.search).toBe('?item=2')
		expect(wrapper.find('[role="status"]').text()).not.toContain('Press again')
		engraver.release()
		await flushPromises()
		await flushPromises()
		// the notes of "Ave verum" are in view, with the focus: the pedal turns them
		expect(wrapper.find('.excerpt-viewer').attributes('data-xml')).toContain('id="2"')
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		expect(pageDown(document.activeElement).defaultPrevented).toBe(true)
		await flushPromises()
		expect(window.location.search).toBe('?item=2')
		wrapper.unmount()
	})

	it('withdraws the offer of the next piece once the notes turn again', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[0].trigger('click')
		await flushPromises()
		const score = wrapper.find('.excerpt-viewer__score').element
		pageDown(score)
		pageDown(score)
		await flushPromises()
		expect(wrapper.find('button[aria-label="Next: Ave verum"]').text()).toBe('Next: Ave verum')
		// back a system with the pedal: no longer at the end
		expect(pageDown(score, 'PageUp').defaultPrevented).toBe(true)
		await flushPromises()
		expect(wrapper.find('button[aria-label="Next: Ave verum"]').text()).toBe('Next')
		// on to the end again: the warning comes first, nothing is skipped
		pageDown(score)
		expect(pageDown(score).defaultPrevented).toBe(true)
		await flushPromises()
		expect(window.location.search).toBe('?item=1')
		expect(wrapper.find('[role="status"]').text()).toContain('Ave verum')
		wrapper.unmount()
	})

	it('puts the focus into the notes also when the page opens with a piece (U37)', async () => {
		window.history.replaceState(null, '', '/index.php/apps/cantoria/concert-folder?item=2')
		const wrapper = await mountFolder()
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		wrapper.unmount()
	})

	it('shows only the title, the bar and the notes in the stage view, "Previous" in the bar (D27)', async () => {
		const wrapper = await mountFolder()
		await wrapper.findAll('.folder-app__open')[1].trigger('click')
		await flushPromises()
		await wrapper.find('button[aria-pressed]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.folder-app--stage').exists()).toBe(true)
		expect(wrapper.find('.folder-app__piece--notes-on-stage').exists()).toBe(true)
		// the head, "Previous: …" below the title and the scan fold away by these classes
		expect(wrapper.find('button[aria-pressed]').classes()).toContain('folder-app__off-stage')
		expect(wrapper.find('.folder-app__turn').classes()).toContain('folder-app__off-stage')
		expect(wrapper.find('.bar button[aria-label="Previous: Abendlied"]').exists()).toBe(true)
		expect(wrapper.find('.bar button[aria-label="Leave the stage view"]').exists()).toBe(true)
		// entering it, the focus went into the notes, so that the pedal turns them
		expect(document.activeElement).toBe(wrapper.find('.excerpt-viewer__score').element)
		await wrapper.find('.bar button[aria-label="Leave the stage view"]').trigger('click')
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.find('button[aria-pressed]').element)
		wrapper.unmount()
	})
})

describe('concert folder without a network', () => {
	beforeEach(() => {
		vi.resetModules()
		// the browser can keep pieces: a secure page with a worker and a cache
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true })
		Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register: vi.fn(async () => ({})), getRegistrations: vi.fn(async () => []) } })
		Object.defineProperty(window, 'caches', { configurable: true, value: { keys: async () => [], has: async () => false, open: async () => ({ match: async () => undefined }), delete: async () => true } })
	})

	afterEach(() => {
		document.body.innerHTML = ''
		delete (window as { caches?: unknown }).caches
		delete (navigator as { serviceWorker?: unknown }).serviceWorker
	})

	it('says at the switch how many pieces come along, and which stay behind and why (U28)', async () => {
		const wrapper = await mountFolder(true)
		const text = wrapper.text()
		expect(text).toContain('2 of 3 pieces available without a network')
		expect(text).toContain('Ubi caritas: the rights allow no copy.')
		wrapper.unmount()
	})
})
