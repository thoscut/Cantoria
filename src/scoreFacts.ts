/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Facts from the scores (November 2026): what the server read from each
 * MusicXML file (`lib/Service/ScoreFacts.php`), and what the app makes of
 * it in the browser:
 *
 * - **Fits our ranges:** each voice against the choir profile of its group,
 *   comfortable, possible or out of range, and the transposition that fits
 *   best (−6 to +6 semitones).
 * - **Keys and first tones:** names of keys and pitches, and how one piece
 *   of a programme leads into the next (pitch sheet).
 *
 * Plain functions without a browser, tested in `scoreFacts.test.ts`.
 */
import type { VoiceGroup } from './score/voiceGroups.ts'

import axios from '@nextcloud/axios'
import { apiUrl } from './api.ts'
import { midiPitchName, transposedFifths } from './score/practising.ts'
import { GROUPS } from './score/voiceGroups.ts'

export { GROUPS, groupLabel } from './score/voiceGroups.ts'
export type { VoiceGroup } from './score/voiceGroups.ts'

export interface FactVoice {
	name: string | null
	group: VoiceGroup | null
	low: number
	high: number
	tessLow: number
	tessHigh: number
	first?: number
	last?: number
}

export interface FactKey {
	fifths: number
	mode: string | null
}

/** Incipit in the Plaine & Easie Code */
export interface Incipit {
	clef: string
	keysig: string
	timesig: string
	data: string
}

export interface CatalogFacts {
	voices: FactVoice[]
	keyStart: FactKey | null
	incipit: Incipit | null
	measures: number
}

export interface ProfileGroup {
	group: VoiceGroup
	comfortLow: number
	comfortHigh: number
	limitLow: number
	limitHigh: number
}

export interface ChoirProfile {
	groups: ProfileGroup[]
	/** false while the default profile applies */
	custom: boolean
}

export interface FactsIndex {
	copies: Record<string, CatalogFacts>
	profile: ChoirProfile
	summary: { files: number, read: number }
	canEditProfile: boolean
}

/** How a voice fits its group: inside the comfortable range, inside the possible one, or not */
export type Fit = 'comfortable' | 'possible' | 'out'

export interface VoiceFit {
	voice: FactVoice
	group: VoiceGroup | null
	fit: Fit | null
	/** semitones above the comfortable range (positive) or below it (negative); 0 inside */
	over: number
}

export interface PieceFit {
	/** the worst fit of all voices with a group; null when no voice has a group */
	fit: Fit | null
	voices: VoiceFit[]
	/** groups of the profile no voice of the piece sings, and groups the piece needs that the choir does not have */
	missing: VoiceGroup[]
	unsung: VoiceGroup[]
	/** best transposition in semitones (0: as written), null when no voice has a group */
	transpose: number | null
	/** the fit after that transposition */
	transposedFit: Fit | null
}

// ---- requests -------------------------------------------------------------

/**
 * The facts per copy, the choir profile and how many scores were read.
 */
export async function fetchFacts(): Promise<FactsIndex> {
	const { data } = await axios.get<FactsIndex>(apiUrl('/facts'))
	return data
}

/**
 * Saves the choir profile; an empty list goes back to the default.
 *
 * @param groups - the groups of the choir
 */
export async function saveProfile(groups: ProfileGroup[]): Promise<ChoirProfile> {
	const { data } = await axios.put<ChoirProfile>(apiUrl('/facts/profile'), { groups })
	return data
}

/**
 * Reads the next scores now.
 */
export async function runFacts(): Promise<{ read: number, summary: { files: number, read: number } }> {
	const { data } = await axios.post<{ read: number, summary: { files: number, read: number } }>(apiUrl('/facts/run'))
	return data
}

export interface SheetEvent {
	kind: string
	title: string | null
	date: string | null
	startTime: string | null
	endTime: string | null
	location: string | null
}

export interface PitchPiece {
	itemId: number
	copyId: number | null
	shelfmark: string | null
	title: string
	composer: string | null
	encore: boolean
	duration: number | null
	keySignature: string | null
	facts: {
		voices: FactVoice[]
		keyStart: FactKey | null
		keyEnd: FactKey | null
		tempo: number | null
		time: string | null
		incipit: Incipit | null
	} | null
}

/**
 * The pitch sheet of a programme or song list.
 *
 * @param eventId - the programme
 */
export async function fetchPitchSheet(eventId: number): Promise<{ event: SheetEvent, pieces: PitchPiece[] }> {
	const { data } = await axios.get<{ event: SheetEvent, pieces: PitchPiece[] }>(apiUrl('/events/{id}/pitches', { id: eventId }))
	return data
}

// ---- fitting the choir ----------------------------------------------------

/**
 * How one range fits a group of the profile.
 *
 * @param low - lowest tone
 * @param high - highest tone
 * @param group - the group of the profile
 */
export function rangeFit(low: number, high: number, group: ProfileGroup): { fit: Fit, over: number } {
	const over = high > group.comfortHigh ? high - group.comfortHigh : (low < group.comfortLow ? low - group.comfortLow : 0)
	if (low >= group.comfortLow && high <= group.comfortHigh) {
		return { fit: 'comfortable', over: 0 }
	}
	if (low >= group.limitLow && high <= group.limitHigh) {
		return { fit: 'possible', over }
	}
	return { fit: 'out', over }
}

const FIT_ORDER: Record<Fit, number> = { comfortable: 0, possible: 1, out: 2 }

/**
 * The worse of two fits.
 *
 * @param a - one fit
 * @param b - another
 */
export function worse(a: Fit | null, b: Fit | null): Fit | null {
	if (a === null) {
		return b
	}
	if (b === null) {
		return a
	}
	return FIT_ORDER[a] >= FIT_ORDER[b] ? a : b
}

/**
 * How the voices of a piece fit the choir profile, transposed by some
 * semitones. Voices without a group (an unnamed voice, an instrument the
 * server did not recognise) do not count.
 *
 * @param voices - the voices of the piece
 * @param profile - the groups of the choir
 * @param semitones - transposition
 */
export function fitAt(voices: FactVoice[], profile: ProfileGroup[], semitones = 0): { fit: Fit | null, voices: VoiceFit[], badness: number } {
	const byGroup = new Map(profile.map((group) => [group.group, group]))
	let fit: Fit | null = null
	let badness = 0
	const list = voices.map((voice): VoiceFit => {
		const group = voice.group === null ? undefined : byGroup.get(voice.group)
		if (group === undefined) {
			return { voice, group: voice.group, fit: null, over: 0 }
		}
		const result = rangeFit(voice.low + semitones, voice.high + semitones, group)
		const tessitura = rangeFit(voice.tessLow + semitones, voice.tessHigh + semitones, group)
		fit = worse(fit, result.fit)
		// Out of range weighs most, then the semitones outside the comfortable range, then a tessitura outside it.
		badness += FIT_ORDER[result.fit] * 100 + Math.abs(result.over) * 10 + Math.abs(tessitura.over)
		return { voice, group: voice.group, fit: result.fit, over: result.over }
	})
	return { fit, voices: list, badness }
}

/**
 * How a piece fits the choir, with the transposition that fits best
 * (as written wins a tie, then the smaller step).
 *
 * @param voices - the voices of the piece
 * @param profile - the groups of the choir
 */
export function pieceFit(voices: FactVoice[], profile: ProfileGroup[]): PieceFit {
	const asWritten = fitAt(voices, profile, 0)
	const sung = new Set(voices.map((voice) => voice.group).filter((group): group is VoiceGroup => group !== null))
	const choir = new Set(profile.map((group) => group.group))
	const missing = GROUPS.filter((group) => sung.has(group) && !choir.has(group))
	const unsung = GROUPS.filter((group) => choir.has(group) && !sung.has(group))
	if (asWritten.fit === null) {
		return { fit: null, voices: asWritten.voices, missing, unsung, transpose: null, transposedFit: null }
	}
	let best = { semitones: 0, badness: asWritten.badness, fit: asWritten.fit as Fit | null }
	for (let step = 1; step <= 6; step++) {
		for (const semitones of [-step, step]) {
			const result = fitAt(voices, profile, semitones)
			if (result.badness < best.badness) {
				best = { semitones, badness: result.badness, fit: result.fit }
			}
		}
	}
	return { fit: asWritten.fit, voices: asWritten.voices, missing, unsung, transpose: best.semitones, transposedFit: best.fit }
}

// ---- names of keys and tones ----------------------------------------------

const MAJOR = ['Ces', 'Ges', 'Des', 'As', 'Es', 'B', 'F', 'C', 'G', 'D', 'A', 'E', 'H', 'Fis', 'Cis']
const MINOR = ['as', 'es', 'b', 'f', 'c', 'g', 'd', 'a', 'e', 'h', 'fis', 'cis', 'gis', 'dis', 'ais']
const MAJOR_EN = ['C♭', 'G♭', 'D♭', 'A♭', 'E♭', 'B♭', 'F', 'C', 'G', 'D', 'A', 'E', 'B', 'F♯', 'C♯']
const MINOR_EN = ['A♭', 'E♭', 'B♭', 'F', 'C', 'G', 'D', 'A', 'E', 'B', 'F♯', 'C♯', 'G♯', 'D♯', 'A♯']

/**
 * Name of a key: "F-Dur", "d-Moll" (German) or "F major", "D minor".
 *
 * @param key - fifths and mode
 * @param german - German names
 * @param words - the words for major and minor in the language of the page
 * @param words.major - "major" or "Dur"
 * @param words.minor - "minor" or "Moll"
 */
export function keyName(key: FactKey, german: boolean, words: { major: string, minor: string }): string {
	const index = Math.max(-7, Math.min(7, key.fifths)) + 7
	const minor = key.mode === 'minor'
	if (german) {
		return minor ? `${MINOR[index]}-${words.minor}` : `${MAJOR[index]}-${words.major}`
	}
	return minor ? `${MINOR_EN[index]} ${words.minor}` : `${MAJOR_EN[index]} ${words.major}`
}

/**
 * The key moved by some semitones (mode kept).
 *
 * @param key - the key
 * @param semitones - transposition
 */
export function transposedKey(key: FactKey, semitones: number): FactKey {
	return { fifths: transposedFifths(key.fifths, semitones), mode: key.mode }
}

/**
 * Name of a tone, spelled for a key.
 *
 * @param pitch - MIDI pitch
 * @param fifths - the key in fifths
 * @param german - German names
 */
export function toneName(pitch: number, fifths: number | null, german: boolean): string {
	return midiPitchName(pitch, fifths, german)
}

/**
 * Steps between two keys on the circle of fifths (0 to 6).
 *
 * @param a - one key
 * @param b - another
 */
export function fifthsApart(a: FactKey, b: FactKey): number {
	// Minor keys sit three fifths below their relative major: compare the tonics.
	const tonic = (key: FactKey) => key.fifths + (key.mode === 'minor' ? 3 : 0)
	const steps = Math.abs(tonic(a) - tonic(b)) % 12
	return Math.min(steps, 12 - steps)
}

export type Transition = 'same' | 'relative' | 'near' | 'far'

/**
 * How the end of one piece leads into the next: the same key, a related one
 * (relative major or minor, the same signature), a near one (one fifth) or
 * a far one, after which the choir needs a new tone.
 *
 * @param end - key at the end of the first piece
 * @param start - key at the start of the next piece
 */
export function transition(end: FactKey, start: FactKey): Transition {
	if (end.fifths === start.fifths && (end.mode ?? 'major') === (start.mode ?? 'major')) {
		return 'same'
	}
	if (end.fifths === start.fifths) {
		return 'relative'
	}
	return fifthsApart(end, start) <= 1 ? 'near' : 'far'
}

/**
 * Semitones from one tone to another, folded into −6 … +6.
 *
 * @param from - first tone
 * @param to - second tone
 */
export function stepBetween(from: number, to: number): number {
	const diff = ((to - from) % 12 + 12) % 12
	return diff > 6 ? diff - 12 : diff
}

/**
 * The PAE text Verovio reads for an incipit.
 *
 * @param incipit - the incipit
 */
export function paeText(incipit: Incipit): string {
	return `@clef:${incipit.clef}\n@keysig:${incipit.keysig}\n@timesig:${incipit.timesig}\n@data:${incipit.data}\n`
}
