/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The vowels the sung practice voice sings (November 2026): the vowel of the
 * syllable under each note, kept over a melisma; notes without lyrics sing
 * the neutral vowel. Only five vowels are synthesized, so umlauts and
 * diphthongs take the nearest of them (ä → e, ö → e, ü → i, au → a, ei → a,
 * eu → o), the way a choir shapes them.
 */
import type { MeiNote } from './mei.ts'
import type { ScoreModel } from './model.ts'
import type { PlayEvent, Timeline } from './timeline.ts'
import type { VowelOf } from './player.ts'
import type { Vowel } from './synth.ts'

import { slotAt } from './countIn.ts'
import { syllableFor } from './lyrics.ts'

/** Two letters sung as one vowel, checked before single letters */
const DIGRAPHS: [string, Vowel][] = [
	['ie', 'i'], ['ei', 'a'], ['ai', 'a'], ['ey', 'a'], ['ay', 'a'], ['au', 'a'],
	['eu', 'o'], ['äu', 'o'], ['oo', 'u'], ['ou', 'u'], ['ee', 'i'], ['ea', 'i'],
]

const SINGLE: Record<string, Vowel> = Object.fromEntries([
	['a', 'a'], ['e', 'e'], ['i', 'i'], ['o', 'o'], ['u', 'u'], ['y', 'i'],
	['ä', 'e'], ['ö', 'e'], ['ü', 'i'], ['æ', 'e'], ['ø', 'e'], ['å', 'o'], ['œ', 'e'],
] satisfies [string, Vowel][])

/**
 * The vowel of a syllable: the first vowel or diphthong in it, null without
 * one ("Hm", a hyphen).
 *
 * @param text - the syllable
 */
export function vowelOf(text: string): Vowel | null {
	// Accents off (é, à, ò), but the umlauts kept: they sound different
	const letters = Array.from(text.toLowerCase().normalize('NFC'))
		.map((letter) => letter in SINGLE ? letter : letter.normalize('NFD').charAt(0))
		.join('')
	for (let index = 0; index < letters.length; index++) {
		const pair = letters.slice(index, index + 2)
		const digraph = DIGRAPHS.find(([spelling]) => spelling === pair)
		if (digraph) {
			return digraph[1]
		}
		const vowel = SINGLE[letters[index]]
		if (vowel) {
			return vowel
		}
	}
	return null
}

/**
 * The vowel of every event of a timeline. A note with a syllable sings its
 * vowel (the verse of the pass of a repeat); a voice without lyrics of its
 * own takes the syllable of its staff at the same time; the notes of a
 * melisma keep the vowel of the syllable before.
 *
 * @param model - the score
 * @param timeline - playback events
 */
export function eventVowels(model: Pick<ScoreModel, 'notesByMeasure'>, timeline: Timeline): VowelOf {
	const notes = new Map<string, MeiNote>()
	for (const note of model.notesByMeasure.flat()) {
		if (note.lyrics && note.lyrics.length > 0) {
			notes.set(note.id, note)
		}
	}
	const vowels = new WeakMap<PlayEvent, Vowel | null>()
	if (notes.size === 0) {
		return () => null
	}
	const passes = new Map<number, number>()
	const passOfSlot = timeline.measures.map((slot) => {
		const pass = (passes.get(slot.index) ?? 0) + 1
		passes.set(slot.index, pass)
		return pass
	})
	const own = (event: PlayEvent): Vowel | null => {
		const note = notes.get(event.id)
		if (!note?.lyrics) {
			return null
		}
		const slot = Math.max(0, slotAt(timeline.measures, event.start))
		const syllable = syllableFor(note.lyrics, passOfSlot[slot] ?? 1)
		return syllable ? vowelOf(syllable.text) : null
	}
	// The syllables of each staff by start time, for voices without lyrics
	const staffAt = new Map<string, Vowel>()
	const sung = timeline.events.map((event) => {
		const vowel = event.sound ? own(event) : null
		if (vowel) {
			staffAt.set(`${event.voice.split('.')[0]}@${event.start.toFixed(4)}`, vowel)
		}
		return vowel
	})
	const last = new Map<string, Vowel | null>()
	timeline.events.forEach((event, index) => {
		if (!event.sound) {
			return
		}
		const vowel = sung[index]
			?? staffAt.get(`${event.voice.split('.')[0]}@${event.start.toFixed(4)}`)
			?? last.get(event.voice)
			?? null
		vowels.set(event, vowel)
		last.set(event.voice, vowel)
	})
	return (event) => vowels.get(event) ?? null
}
