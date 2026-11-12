/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// @vitest-environment jsdom

import type { FactVoice, Incipit, ProfileGroup } from '../scoreFacts.ts'

import { describe, expect, it } from 'vitest'
import { isSafeIncipit } from '../design/incipit.ts'
import { groupOf, voiceColor } from '../score/voiceGroups.ts'
import { fifthsApart, fitAt, keyName, paeText, pieceFit, rangeFit, stepBetween, toneName, transition, transposedKey, worse } from '../scoreFacts.ts'

const PROFILE: ProfileGroup[] = [
	{ group: 'S', comfortLow: 62, comfortHigh: 77, limitLow: 60, limitHigh: 81 },
	{ group: 'A', comfortLow: 55, comfortHigh: 72, limitLow: 53, limitHigh: 74 },
	{ group: 'T', comfortLow: 50, comfortHigh: 65, limitLow: 48, limitHigh: 69 },
	{ group: 'B', comfortLow: 43, comfortHigh: 60, limitLow: 40, limitHigh: 64 },
]

/**
 * A voice with a range; the tessitura is the range by default.
 *
 * @param group - voice group
 * @param low - lowest tone
 * @param high - highest tone
 */
function voice(group: FactVoice['group'], low: number, high: number): FactVoice {
	return { name: group, group, low, high, tessLow: low, tessHigh: high, first: low, last: low }
}

describe('how a piece fits the choir', () => {
	it('tells comfortable, possible and out of range with the semitones outside', () => {
		expect(rangeFit(62, 77, PROFILE[0])).toEqual({ fit: 'comfortable', over: 0 })
		expect(rangeFit(62, 79, PROFILE[0])).toEqual({ fit: 'possible', over: 2 })
		expect(rangeFit(60, 70, PROFILE[0])).toEqual({ fit: 'possible', over: -2 })
		expect(rangeFit(62, 84, PROFILE[0])).toEqual({ fit: 'out', over: 7 })
		expect(rangeFit(57, 70, PROFILE[0]).fit).toBe('out')
	})

	it('takes the worst voice for the piece', () => {
		expect(worse('comfortable', 'possible')).toBe('possible')
		expect(worse('out', 'possible')).toBe('out')
		expect(worse(null, 'comfortable')).toBe('comfortable')
		expect(worse(null, null)).toBeNull()
		const result = fitAt([voice('S', 62, 76), voice('A', 55, 70), voice('T', 50, 67), voice('B', 43, 58)], PROFILE)
		expect(result.fit).toBe('possible')
		expect(result.voices.map((entry) => entry.fit)).toEqual(['comfortable', 'comfortable', 'possible', 'comfortable'])
		expect(result.voices[2].over).toBe(2)
	})

	it('leaves voices without a group out: an organ does not make a piece too high', () => {
		const result = pieceFit([voice('S', 62, 76), voice(null, 24, 96)], PROFILE)
		expect(result.fit).toBe('comfortable')
		expect(result.voices[1].fit).toBeNull()
	})

	it('gives no verdict for a piece without choir voices', () => {
		const result = pieceFit([voice(null, 40, 80)], PROFILE)
		expect(result).toMatchObject({ fit: null, transpose: null, transposedFit: null })
	})

	it('proposes the transposition that fits best and keeps the piece as written on a tie', () => {
		// Everything a major third too high: four semitones down bring every voice home
		const high = [voice('S', 66, 81), voice('A', 59, 76), voice('T', 54, 69), voice('B', 47, 64)]
		const result = pieceFit(high, PROFILE)
		expect(result.fit).toBe('out')
		expect(result.transpose).toBe(-4)
		expect(result.transposedFit).toBe('comfortable')

		const fine = pieceFit([voice('S', 64, 74), voice('B', 45, 57)], PROFILE)
		expect(fine.transpose).toBe(0)
		expect(fine.transposedFit).toBe('comfortable')
	})

	it('names the groups the choir lacks and the groups that do not sing', () => {
		const women: ProfileGroup[] = PROFILE.slice(0, 2)
		const result = pieceFit([voice('S', 62, 76), voice('T', 50, 64)], women)
		expect(result.missing).toEqual(['T'])
		expect(result.unsung).toEqual(['A'])
	})
})

describe('keys and tones', () => {
	const words = { major: 'major', minor: 'minor' }
	const german = { major: 'Dur', minor: 'Moll' }

	it('names keys in German and English', () => {
		expect(keyName({ fifths: -1, mode: 'major' }, true, german)).toBe('F-Dur')
		expect(keyName({ fifths: -1, mode: 'minor' }, true, german)).toBe('d-Moll')
		expect(keyName({ fifths: 5, mode: 'major' }, true, german)).toBe('H-Dur')
		expect(keyName({ fifths: -2, mode: null }, true, german)).toBe('B-Dur')
		expect(keyName({ fifths: -2, mode: 'major' }, false, words)).toBe('B♭ major')
		expect(keyName({ fifths: 3, mode: 'minor' }, false, words)).toBe('F♯ minor')
		expect(keyName({ fifths: 12, mode: 'major' }, false, words)).toBe('C♯ major')
	})

	it('names tones for the key', () => {
		expect(toneName(60, 0, true)).toMatch(/^c/i)
		expect(toneName(70, -2, true)).toMatch(/^b/i)
		expect(toneName(70, -2, false)).toMatch(/^B♭|^Bb/)
	})

	it('moves a key by semitones and keeps the mode', () => {
		expect(transposedKey({ fifths: 0, mode: 'minor' }, 2)).toEqual({ fifths: 2, mode: 'minor' })
		expect(transposedKey({ fifths: 1, mode: 'major' }, -2)).toEqual({ fifths: -1, mode: 'major' })
	})

	it('measures the way between two keys on the circle of fifths', () => {
		expect(fifthsApart({ fifths: 0, mode: 'major' }, { fifths: 1, mode: 'major' })).toBe(1)
		expect(fifthsApart({ fifths: 0, mode: 'major' }, { fifths: 0, mode: 'minor' })).toBe(3)
		expect(fifthsApart({ fifths: -3, mode: 'major' }, { fifths: 4, mode: 'major' })).toBe(5)
		expect(fifthsApart({ fifths: -6, mode: 'major' }, { fifths: 6, mode: 'major' })).toBe(0)
	})

	it('tells how one piece leads into the next', () => {
		const g = { fifths: 1, mode: 'major' }
		expect(transition(g, { fifths: 1, mode: 'major' })).toBe('same')
		expect(transition(g, { fifths: 1, mode: null })).toBe('same')
		expect(transition(g, { fifths: 1, mode: 'minor' })).toBe('relative')
		expect(transition(g, { fifths: 2, mode: 'major' })).toBe('near')
		expect(transition(g, { fifths: 0, mode: 'major' })).toBe('near')
		expect(transition(g, { fifths: -3, mode: 'major' })).toBe('far')
	})

	it('folds the step between two tones into a tritone either way', () => {
		expect(stepBetween(60, 67)).toBe(-5)
		expect(stepBetween(60, 62)).toBe(2)
		expect(stepBetween(60, 66)).toBe(6)
		expect(stepBetween(67, 60)).toBe(5)
		expect(stepBetween(60, 72)).toBe(0)
	})
})

describe('voice groups by name', () => {
	it('reads the group from German, English, Italian and Latin names', () => {
		expect(groupOf('Sopran II')).toBe('S')
		expect(groupOf('Soprano 1')).toBe('S')
		expect(groupOf('Mezzosopran')).toBe('A')
		expect(groupOf('Mezzo-soprano')).toBe('A')
		expect(groupOf('Alt')).toBe('A')
		expect(groupOf(' Tenor  I ')).toBe('T')
		expect(groupOf('Bariton')).toBe('B')
		expect(groupOf('Bassus')).toBe('B')
		expect(groupOf('B. II')).toBe('B')
	})

	it('gives none for instruments and unnamed voices', () => {
		for (const name of ['Orgel', 'Violine I', 'Basso continuo', 'Klavier', '', null, undefined]) {
			expect(groupOf(name)).toBeNull()
		}
	})

	it('has one colour per group and one for the rest', () => {
		expect(voiceColor('S')).toBe('var(--cantoria-voice-s)')
		expect(voiceColor('B')).toBe('var(--cantoria-voice-b)')
		expect(voiceColor(null)).toBe('var(--cantoria-voice-none)')
	})
})

describe('incipits from the server', () => {
	const incipit: Incipit = { clef: 'G-2', keysig: 'bBE', timesig: '4/4', data: "4''C8DE/2F-" }

	it('writes the text Verovio reads', () => {
		expect(paeText(incipit)).toBe("@clef:G-2\n@keysig:bBE\n@timesig:4/4\n@data:4''C8DE/2F-\n")
	})

	it('accepts what the Plaine & Easie Code needs', () => {
		expect(isSafeIncipit(incipit)).toBe(true)
		expect(isSafeIncipit({ ...incipit, keysig: '', timesig: 'c' })).toBe(true)
	})

	it('refuses anything that could start another line or carry markup', () => {
		expect(isSafeIncipit({ ...incipit, data: "4''C\n@data:<svg>" })).toBe(false)
		expect(isSafeIncipit({ ...incipit, data: '<script>' })).toBe(false)
		expect(isSafeIncipit({ ...incipit, clef: 'G-2\n@keysig:x' })).toBe(false)
		expect(isSafeIncipit({ ...incipit, keysig: 'x<' })).toBe(false)
		expect(isSafeIncipit({ ...incipit, timesig: '4/4@' })).toBe(false)
		expect(isSafeIncipit({ ...incipit, data: 'C'.repeat(601) })).toBe(false)
	})
})
