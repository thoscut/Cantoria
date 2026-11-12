/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Texts about the facts from the scores: transpositions, transitions
 * between pieces, fits.
 */
import type { Transition } from './scoreFacts.ts'

import { n, t } from './l10n.ts'

/**
 * A transposition for people: "2 semitones higher", "1 semitone lower",
 * "as written".
 *
 * @param semitones - transposition
 */
export function transposeLabel(semitones: number): string {
	if (semitones === 0) {
		return t('cantoria', 'as written')
	}
	return semitones > 0
		? n('cantoria', '%n semitone higher', '%n semitones higher', semitones)
		: n('cantoria', '%n semitone lower', '%n semitones lower', -semitones)
}

/**
 * How one piece leads into the next.
 *
 * @param transition - same key, relative key, one fifth apart or far
 */
export function transitionLabel(transition: Transition): string {
	switch (transition) {
	case 'same':
		return t('cantoria', 'Same key: sing on')
	case 'relative':
		return t('cantoria', 'Same signature: sing on')
	case 'near':
		return t('cantoria', 'Related key: give the new tone')
	default:
		return t('cantoria', 'Distant key: give the new tone')
	}
}

/**
 * Words for major and minor in the language of the page.
 */
export function modeWords(): { major: string, minor: string } {
	return { major: t('cantoria', 'major'), minor: t('cantoria', 'minor') }
}
