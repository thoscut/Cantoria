/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The voice groups of a choir (S, A, T, B) and the colour of each (design
 * proposal V2): the same colour, always with the letter, in the notes, the
 * mixer, the ranges and the programme. The names are read like on the
 * server (ScoreFacts::group).
 */
import { t } from '../l10n.ts'

/** Voice groups of a choir, top to bottom */
export const GROUPS = ['S', 'A', 'T', 'B'] as const
export type VoiceGroup = typeof GROUPS[number]

const NUMERAL = '(?:IV|I{1,3}|[1-4])'
const TRAILING_NUMBER = new RegExp(`[\\s.]+${NUMERAL}$`, 'i')

const NAMES: [VoiceGroup, RegExp][] = [
	['S', /^(sopran[oei]?|soprane|s|diskant|discant|treble|superius|cantus)$/],
	['A', /^(alt|alto|alti|a|mezzo(-?soprano?)?|contralto|altus)$/],
	['T', /^(tenor|tenore|tenori|t|tenöre)$/],
	['B', /^(bass|basso|bassi|bässe|b|bariton|baritone|baritono|bar|bassus)$/],
]

/**
 * The group a voice belongs to by its name: "Sopran II" → S, "Bariton" → B;
 * null for instruments and names that do not tell.
 *
 * @param name - name of the voice
 */
export function groupOf(name: string | null | undefined): VoiceGroup | null {
	if (!name) {
		return null
	}
	const plain = name.trim().toLowerCase().replace(TRAILING_NUMBER, '').replace(/\s+/g, ' ').trim()
	return NAMES.find(([, pattern]) => pattern.test(plain))?.[0] ?? null
}

/**
 * The colour of a group as a CSS value (design/tokens.css).
 *
 * @param group - S, A, T, B or null
 */
export function voiceColor(group: VoiceGroup | null): string {
	return `var(--cantoria-voice-${(group ?? 'none').toLowerCase()})`
}

/**
 * Name of a voice group for people.
 *
 * @param group - S, A, T, B or null
 */
export function groupLabel(group: VoiceGroup | null): string {
	switch (group) {
	case 'S':
		return t('cantoria', 'Soprano')
	case 'A':
		return t('cantoria', 'Alto')
	case 'T':
		return t('cantoria', 'Tenor')
	case 'B':
		return t('cantoria', 'Bass')
	default:
		return t('cantoria', 'Voice without a group')
	}
}
