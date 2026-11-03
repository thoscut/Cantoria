/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { MemberLink, MemberProgramme } from '../types.ts'

import { afterEach, describe, expect, it } from 'vitest'
import { forgetVisits, groupByProgramme, linkMark, newSinceLastVisit, VISIT_MS } from '../sharing/memberList.ts'

afterEach(() => {
	window.localStorage.clear()
})

const advent = { key: 'a1', title: 'Adventskonzert', date: '2026-12-13', order: 0 }
const christmas = { key: 'c2', title: 'Weihnachtskonzert', date: '2026-12-24', order: 1 }

/**
 * A link of the page.
 *
 * @param token - its token (one letter is enough here)
 * @param programmes - where the page lists it
 */
function link(token: string, programmes?: MemberProgramme[]): MemberLink {
	return {
		token,
		title: 'Stück ' + token,
		composer: '',
		measure_from: null,
		measure_to: null,
		voice: null,
		verse: null,
		tempo: null,
		note: null,
		expires: null,
		canPlay: true,
		canExportAudio: false,
		reason: null,
		credits: [],
		notices: [],
		programmes,
	}
}

describe('"Shared with me" by programme (U24)', () => {
	it('lists the pieces of a programme in its order, the programmes in the order of the server, the others below', () => {
		const links = [
			link('e'),
			link('c', [{ ...christmas, position: 1 }]),
			link('s', [{ ...advent, position: 2 }, { ...christmas, position: 0 }]),
			link('b', [{ ...advent, position: 1 }]),
			link('a', [{ ...advent, position: 0 }]),
			link('f', []),
		]
		const { programmes, others } = groupByProgramme(links)
		expect(programmes.map((p) => [p.title, p.date, p.links.map((l) => l.token).join('')])).toEqual([
			['Adventskonzert', '2026-12-13', 'abs'],
			['Weihnachtskonzert', '2026-12-24', 'sc'],
		])
		expect(others.map((l) => l.token)).toEqual(['e', 'f'])
	})

	it('lists every link once without programmes, as before', () => {
		const { programmes, others } = groupByProgramme([link('x'), link('y')])
		expect(programmes).toEqual([])
		expect(others.map((l) => l.token)).toEqual(['x', 'y'])
	})
})

describe('new since the last visit (U24)', () => {
	const t0 = Date.UTC(2026, 10, 3, 18)

	it('marks nothing at the first visit and the pieces added since at the next', () => {
		expect(newSinceLastVisit([link('a'), link('b')], 'anna', t0)).toEqual(new Set())
		const later = t0 + 3 * 24 * 3600 * 1000
		expect(newSinceLastVisit([link('a'), link('b'), link('c')], 'anna', later)).toEqual(new Set(['c']))
		expect(newSinceLastVisit([link('a'), link('b'), link('c')], 'anna', later + 2 * VISIT_MS), 'the visit after that').toEqual(new Set())
	})

	it('keeps the marks while one visit lasts, also after a reload', () => {
		newSinceLastVisit([link('a')], 'anna', t0)
		const next = t0 + 7 * 24 * 3600 * 1000
		expect(newSinceLastVisit([link('a'), link('b')], 'anna', next)).toEqual(new Set(['b']))
		expect(newSinceLastVisit([link('a'), link('b')], 'anna', next + 10 * 60 * 1000), 'a reload ten minutes later').toEqual(new Set(['b']))
		expect(newSinceLastVisit([link('a'), link('b'), link('c')], 'anna', next + 20 * 60 * 1000)).toEqual(new Set(['b', 'c']))
		expect(newSinceLastVisit([link('a'), link('c')], 'anna', next + 30 * 60 * 1000), 'an expired link is gone').toEqual(new Set(['c']))
	})

	it('remembers marks, not the addresses of the links, and keeps two accounts apart', () => {
		const token = 'abcdefghijABCDEFGHIJ0123456789xy'
		newSinceLastVisit([link(token)], 'anna', t0)
		const stored = Object.keys(window.localStorage).map((key) => [key, window.localStorage.getItem(key) ?? ''])
		expect(stored).toHaveLength(1)
		expect(stored[0]![0]).toMatch(/^cantoria\.member\.seen\./)
		expect(stored[0]![0]).not.toContain('anna')
		expect(stored[0]![1]).not.toContain(token)
		expect(stored[0]![1]).toContain(linkMark(token))
		expect(linkMark(token)).toMatch(/^[0-9a-f]{16}$/)
		expect(linkMark(token)).not.toBe(linkMark(token.replace('xy', 'xz')))

		expect(newSinceLastVisit([link(token), link('other')], 'bert', t0 + 1), 'the first visit of another account on the device').toEqual(new Set())
		expect(newSinceLastVisit([link(token), link('other')], 'anna', t0 + 2 * VISIT_MS)).toEqual(new Set(['other']))
	})

	it('forgets everything with "Delete everything on this device", and works without storage', () => {
		newSinceLastVisit([link('a')], 'anna', t0)
		newSinceLastVisit([link('a')], 'bert', t0)
		window.localStorage.setItem('cantoria.zoom', '3')
		forgetVisits()
		expect(Object.keys(window.localStorage)).toEqual(['cantoria.zoom'])
		expect(newSinceLastVisit([link('a'), link('b')], 'anna', t0 + 2 * VISIT_MS), 'a first visit again').toEqual(new Set())

		window.localStorage.setItem(Object.keys(window.localStorage).find((key) => key.startsWith('cantoria.member.seen.'))!, '{broken')
		expect(newSinceLastVisit([link('a')], 'anna', t0 + 4 * VISIT_MS), 'a damaged entry counts as a first visit').toEqual(new Set())
	})
})
