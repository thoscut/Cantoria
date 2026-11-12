/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PlayEvent, Timeline } from '../score/timeline.ts'

import { describe, expect, it } from 'vitest'
import { formantsFor, lowBoost } from '../score/synth.ts'
import { eventVowels, vowelOf } from '../score/vowels.ts'

/**
 * @param id - note id
 * @param voice - staff.layer
 * @param start - seconds
 * @param sound - false for a tied continuation
 */
function event(id: string, voice: string, start: number, sound = true): PlayEvent {
	return { id, voice, pitch: 60, start, end: start + 1, sound, soundEnd: start + 1, measure: 0 }
}

describe('vowelOf', () => {
	it('takes the first vowel of a syllable', () => {
		expect(vowelOf('Mond')).toBe('o')
		expect(vowelOf('ist')).toBe('i')
		expect(vowelOf('ge')).toBe('e')
		expect(vowelOf('Lamm')).toBe('a')
		expect(vowelOf('Blut')).toBe('u')
	})

	it('sings umlauts and diphthongs as the nearest of five vowels', () => {
		expect(vowelOf('schön')).toBe('e')
		expect(vowelOf('Glück')).toBe('i')
		expect(vowelOf('Bäume')).toBe('o')
		expect(vowelOf('Haus')).toBe('a')
		expect(vowelOf('mein')).toBe('a')
		expect(vowelOf('Lie')).toBe('i')
		expect(vowelOf('Freu')).toBe('o')
	})

	it('drops accents but not umlauts', () => {
		expect(vowelOf('Glòria')).toBe('o')
		expect(vowelOf('é')).toBe('e')
		expect(vowelOf('Ü')).toBe('i')
	})

	it('has no vowel for a hum or a hyphen', () => {
		expect(vowelOf('Hm')).toBeNull()
		expect(vowelOf('–')).toBeNull()
		expect(vowelOf('')).toBeNull()
	})
})

describe('eventVowels', () => {
	const lyric = (text: string) => [{ verse: 1, text, wordpos: 's' }]
	const timeline = (events: PlayEvent[]): Timeline => ({ events, measures: [{ index: 0, start: 0, end: 10 }], duration: 10 })

	it('keeps the vowel over a melisma and gives a voice without lyrics those of its staff', () => {
		const events = [event('n1', '1.1', 0), event('n2', '1.2', 0), event('n3', '1.1', 1), event('n4', '1.1', 2), event('n5', '2.1', 0)]
		const model = {
			notesByMeasure: [[
				{ id: 'n1', lyrics: lyric('Mond') },
				{ id: 'n3', lyrics: [] },
				{ id: 'n4', lyrics: lyric('ist') },
			]],
		} as unknown as Parameters<typeof eventVowels>[0]
		const vowelOfEvent = eventVowels(model, timeline(events))
		expect(events.map(vowelOfEvent)).toEqual(['o', 'o', 'o', 'i', null])
	})

	it('sings the neutral vowel without any lyrics', () => {
		const events = [event('n1', '1.1', 0)]
		const vowelOfEvent = eventVowels({ notesByMeasure: [[]] } as unknown as Parameters<typeof eventVowels>[0], timeline(events))
		expect(vowelOfEvent(events[0])).toBeNull()
	})
})

describe('formants of the sung voice', () => {
	it('raises the first formant above a high tone', () => {
		const high = formantsFor('i', 880)
		expect(high[0].frequency).toBeCloseTo(968)
		expect(formantsFor('i', 110)[0].frequency).toBe(270)
	})

	it('boosts low tones, not high ones', () => {
		expect(lowBoost(98)).toBeCloseTo(1.8)
		expect(lowBoost(440)).toBe(1)
	})
})
