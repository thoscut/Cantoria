/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Small pure helpers of the excerpt view for choir members and public links
 * (milestone 0.7): the tempo of a link, the voice of a link, and names as
 * the server accepts them. No Vue, so they can be tested on their own.
 */
import type { Voice } from '../score/voices.ts'

/** Tempo range of the excerpt view, in percent of the score's tempo */
export const TEMPO_MIN = 25
export const TEMPO_MAX = 200

/**
 * The tempo of a link as the slider of the excerpt view offers it: 25 to
 * 200 %, in steps of 5; 100 % when the link gives none.
 *
 * @param percent - tempo of the link (20 to 300 % are allowed in a link)
 */
export function excerptTempo(percent: number | null | undefined): number {
	if (percent === null || percent === undefined || !Number.isFinite(percent) || percent <= 0) {
		return 100
	}
	return Math.min(TEMPO_MAX, Math.max(TEMPO_MIN, Math.round(percent / 5) * 5))
}

/**
 * The voice a link names, found by its display name or by its name from
 * the part list (case does not matter), as the score view does it.
 *
 * @param voices - voices of the score
 * @param wanted - voice of the link
 * @param labelOf - display name of a voice
 */
export function voiceOfLink(voices: readonly Voice[], wanted: string | null | undefined, labelOf: (voice: Voice) => string): Voice | null {
	const name = (wanted ?? '').trim().toLowerCase()
	if (name === '') {
		return null
	}
	return voices.find((voice) => labelOf(voice).trim().toLowerCase() === name)
		?? voices.find((voice) => (voice.name ?? '').trim().toLowerCase() === name)
		?? null
}

/** Where the voice a choir member chose last is remembered, in this browser only */
export const OWN_VOICE_KEY = 'member.voice'

/**
 * The voice a choir member sings, remembered from the last piece (round 3,
 * November 2026), found in this score: the same name, or a voice whose name
 * begins with it ("Tenor" finds "Tenor 1"), or the other way round ("Alt 2"
 * finds "Alt"). Null when nothing fits; the member chooses then.
 *
 * @param voices - voices of the score
 * @param remembered - the name remembered
 * @param labelOf - display name of a voice
 */
export function ownVoice(voices: readonly Voice[], remembered: string | null | undefined, labelOf: (voice: Voice) => string): Voice | null {
	const name = (remembered ?? '').trim().toLowerCase()
	if (name === '') {
		return null
	}
	const exact = voiceOfLink(voices, remembered, labelOf)
	if (exact) {
		return exact
	}
	const label = (voice: Voice): string => labelOf(voice).trim().toLowerCase()
	const word = (text: string): RegExp => new RegExp('^' + text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b')
	return voices.find((voice) => word(name).test(label(voice)))
		?? voices.find((voice) => label(voice) !== '' && word(label(voice)).test(name))
		?? null
}

/**
 * A voice name as the server accepts it: no control characters, at most 100
 * characters.
 *
 * @param label - display name of the voice
 */
export function voiceForServer(label: string): string {
	return [...label].filter((char) => char >= ' ' && char !== '\x7f').join('').trim().slice(0, 100)
}
