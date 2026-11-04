// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The concert folder gives the device the end of the licenses for the copy
 * (R B29), as "Shared with me" does: a piece kept for the concert is
 * dropped from the device on that day, also without a network.
 */
import type { KeptPiecesOptions } from '../offline/useKeptPieces.ts'
import type { PieceForDevice } from '../offline/rules.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'

const seen: { options: KeptPiecesOptions | null } = { options: null }

function item(id: number, keepUntil: string | null) {
	return {
		id,
		position: id,
		title: 'Abendlied ' + id,
		composer: 'J. Rheinberger',
		shelfmark: null,
		participation: null,
		note: null,
		encore: false,
		duration: null,
		score: true,
		scan: true,
		scanSize: null,
		canPlay: true,
		reason: null,
		offline: true,
		offlineReason: null,
		keepUntil,
		print: true,
		practiceKey: null,
	}
}

const concerts = [{
	id: 1,
	kind: 'concert',
	title: 'Herbstkonzert',
	date: '2026-11-15',
	startTime: null,
	location: null,
	status: 'fixed',
	items: [item(1, '2026-12-31'), item(2, null)],
}]

vi.mock('../sharing/api.ts', () => ({
	fetchFolderConcerts: vi.fn(async () => concerts),
	fetchFolderScore: vi.fn(async () => '<score-partwise/>'),
}))

vi.mock('../offline/useKeptPieces.ts', () => ({
	useKeptPieces: (_name: string, options: KeptPiecesOptions) => {
		seen.options = options
		return {
			catalog: ref(null),
			reachable: ref(true),
			storage: ref(null),
			offlineOn: true,
			withoutNetwork: computed(() => false),
			keptCount: computed(() => 0),
			storageText: computed(() => ''),
			checkedText: computed(() => ''),
			isKept: () => false,
			scanKept: () => false,
			lockText: () => '',
			refreshStorage: async () => {},
			checkDevice: async () => {},
			checkPublic: async () => true,
			keep: async () => {},
			forget: async () => {},
			deleteDevice: async () => {},
			start: async () => {},
		}
	},
}))

describe('concert folder on the device', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('keeps a piece only until the licenses for the copy end (R B29)', async () => {
		const input = document.createElement('input')
		input.type = 'hidden'
		input.id = 'initial-state-cantoria-folder'
		input.value = btoa(JSON.stringify({ concerts, version: '0.19.0', offline: true }))
		document.body.appendChild(input)
		const { default: ConcertFolderApp } = await import('../sharing/ConcertFolderApp.vue')
		const wrapper = mount(ConcertFolderApp, { global: { stubs: { ExcerptViewer: true } } })
		await flushPromises()
		const pieces = (await seen.options?.fresh?.()) as PieceForDevice[]
		expect(pieces.map((piece) => [piece.key, piece.expires])).toEqual([['1', '2026-12-31'], ['2', null]])
		wrapper.unmount()
	})
})
