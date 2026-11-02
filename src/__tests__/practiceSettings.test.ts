/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { storeMarks } from '../score/marks.ts'
import { forgetPractising, sharedPieceKey, storedPracticeSettings, storePracticeSettings } from '../score/practiceSettings.ts'
import { readStored, removeStoredStarting, writeStored } from '../storage.ts'

describe('settings for practising a shared piece', () => {
	afterEach(() => window.localStorage.clear())

	it('are remembered per piece with a version', () => {
		storePracticeSettings('k1', { tempo: 80, linkTempo: 90, transpose: -2, metronome: true, mix: { s: 50 } })
		expect(JSON.parse(window.localStorage.getItem('cantoria.practice.k1') ?? '')).toMatchObject({ v: 1, tempo: 80 })
		expect(storedPracticeSettings('k1', [25, 200])).toEqual({ tempo: 80, linkTempo: 90, transpose: -2, metronome: true, mix: { s: 50 } })
		expect(storedPracticeSettings('k2', [25, 200])).toBeNull()
	})

	it('ignore other versions and values out of range', () => {
		window.localStorage.setItem('cantoria.practice.k1', JSON.stringify({ v: 2, tempo: 80 }))
		expect(storedPracticeSettings('k1', [25, 200])).toBeNull()
		window.localStorage.setItem('cantoria.practice.k1', JSON.stringify({ v: 1, tempo: 900, transpose: 40, metronome: 'yes', mix: { s: 500, a: 20, b: 'x' } }))
		expect(storedPracticeSettings('k1', [25, 200])).toEqual({ tempo: 200, linkTempo: null, transpose: 6, metronome: false, mix: { a: 20 } })
		window.localStorage.setItem('cantoria.practice.k1', '{broken')
		expect(storedPracticeSettings('k1', [25, 200])).toBeNull()
	})

	it('use the practice key of the server', () => {
		expect(sharedPieceKey('k1', 'Abendlied', 'Rheinberger')).toBe('piece:k1')
		expect(sharedPieceKey(null, 'Abendlied', 'Rheinberger')).toBe('shared:Abendlied|Rheinberger')
	})

	it('are forgotten with the passages, and nothing else', () => {
		storePracticeSettings('k1', { tempo: 80, linkTempo: null, transpose: 0, metronome: false, mix: {} })
		storeMarks('copy:3', [{ name: 'A', from: '1', to: '2' }])
		writeStored('zoom', '4')
		window.localStorage.setItem('other.app', 'stays')
		expect(forgetPractising()).toBe(2)
		expect(readStored('zoom')).toBe('4')
		expect(removeStoredStarting()).toBe(1)
		expect(window.localStorage.getItem('other.app')).toBe('stays')
	})
})
