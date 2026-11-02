/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The settings for practising a shared piece (milestone 0.15): tempo,
 * semitones, metronome and mixer, remembered per piece in this browser,
 * also without a network. The key is the practice key of the server (the
 * same for every link to the same version), never the token of a link.
 * Nothing about it goes to the server; the value carries a version, so that
 * a later change of the format can read or drop old values.
 */
import type { Mix } from './practising.ts'

import { readStored, removeStoredStarting, writeStored } from '../storage.ts'
import { TRANSPOSE_MAX } from './practising.ts'

/** Settings for practising one piece */
export interface PracticeSettings {
	/** tempo in percent; only used while the link still suggests linkTempo */
	tempo: number
	/** tempo of the link when the tempo was chosen (null: the score's tempo) */
	linkTempo: number | null
	transpose: number
	metronome: boolean
	mix: Mix
}

interface Stored extends PracticeSettings {
	v: 1
}

const PREFIX = 'practice.'

/**
 * The key of the passages and settings of a shared piece: the practice key
 * of the server, or title and composer for a page without one.
 *
 * @param practiceKey - practice key of the server
 * @param title - title of the piece
 * @param composer - composer
 */
export function sharedPieceKey(practiceKey: string | null | undefined, title: string, composer: string): string {
	return practiceKey ? `piece:${practiceKey}` : `shared:${title}|${composer}`
}

/**
 * The settings remembered for a piece, or null.
 *
 * @param practiceKey - practice key of the server
 * @param tempoRange - lowest and highest tempo in percent
 */
export function storedPracticeSettings(practiceKey: string, tempoRange: [number, number]): PracticeSettings | null {
	try {
		const value = JSON.parse(readStored(PREFIX + practiceKey) ?? 'null') as Partial<Stored> | null
		if (value?.v !== 1) {
			return null
		}
		const mix: Mix = {}
		if (typeof value.mix === 'object' && value.mix !== null) {
			for (const [voice, level] of Object.entries(value.mix)) {
				if (typeof level === 'number' && level >= 0 && level <= 100) {
					mix[voice] = level
				}
			}
		}
		const tempo = typeof value.tempo === 'number' && Number.isFinite(value.tempo)
			? Math.min(tempoRange[1], Math.max(tempoRange[0], Math.round(value.tempo)))
			: 100
		const transpose = Number.isInteger(value.transpose) ? Math.min(TRANSPOSE_MAX, Math.max(-TRANSPOSE_MAX, value.transpose as number)) : 0
		return {
			tempo,
			linkTempo: typeof value.linkTempo === 'number' ? value.linkTempo : null,
			transpose,
			metronome: value.metronome === true,
			mix,
		}
	} catch {
		return null
	}
}

/**
 * Remember the settings of a piece.
 *
 * @param practiceKey - practice key of the server
 * @param settings - the settings
 */
export function storePracticeSettings(practiceKey: string, settings: PracticeSettings): void {
	const value: Stored = { v: 1, ...settings, mix: { ...settings.mix } }
	writeStored(PREFIX + practiceKey, JSON.stringify(value))
}

/**
 * Forget the passages and settings for practising of every piece in this
 * browser.
 *
 * @return the number of pieces forgotten
 */
export function forgetPractising(): number {
	return removeStoredStarting(['marks.', PREFIX])
}
