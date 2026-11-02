/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What a link shares: the whole piece or an excerpt (measures from–to), and
 * what its recipients see. Pure helpers of the dialog "Share …", the list
 * of links and the pages of the choir members and of public links; no Vue,
 * so they can be tested on their own. A link without measures is the whole
 * piece (see docs/api.md, "Sharing beyond the archive roles").
 */
import type { CircleOption, LinkCircle } from '../types.ts'
import type { ReferenceWords } from '../references.ts'

import { placeText } from '../references.ts'

export type LinkScope = 'whole' | 'excerpt'

/** Measure numbers as the server accepts them */
const MEASURE = /^[0-9A-Za-z.]{1,16}$/

/**
 * The scope the dialog starts with: the excerpt when the score view shows
 * a range of measures, otherwise the whole piece.
 *
 * @param prefill - what the score view passes
 * @param prefill.measureFrom - first shown measure, if a range is shown
 * @param prefill.measureTo - last shown measure, if a range is shown
 */
export function initialScope(prefill?: { measureFrom?: string | null, measureTo?: string | null } | null): LinkScope {
	return (prefill?.measureFrom ?? '').trim() !== '' || (prefill?.measureTo ?? '').trim() !== '' ? 'excerpt' : 'whole'
}

/**
 * The measures to send: none for the whole piece.
 *
 * @param scope - whole piece or excerpt
 * @param from - first measure as typed
 * @param to - last measure as typed
 */
export function linkMeasures(scope: LinkScope, from: string, to: string): { measure_from: string | null, measure_to: string | null } {
	if (scope === 'whole') {
		return { measure_from: null, measure_to: null }
	}
	return { measure_from: from.trim() || null, measure_to: to.trim() || null }
}

/**
 * What is wrong with the measures, before the server is asked: `missing`
 * when an excerpt for choir members or the public lacks a measure (internal
 * links may name one or none), `format` for characters the server refuses.
 *
 * @param scope - whole piece or excerpt
 * @param circle - who can open the link
 * @param from - first measure as typed
 * @param to - last measure as typed
 */
export function measureProblem(scope: LinkScope, circle: LinkCircle, from: string, to: string): 'missing' | 'format' | null {
	if (scope === 'whole') {
		return null
	}
	const measures = [from.trim(), to.trim()]
	if (measures.some((measure) => measure !== '' && !MEASURE.test(measure))) {
		return 'format'
	}
	return measures.includes('') && (circle !== 'internal' || measures.every((measure) => measure === '')) ? 'missing' : null
}

/**
 * Whether the dialog offers the scan: only for choir members and the
 * public, when the rights allow the link and the scan (the scan needs the
 * same release as the notes; for choir members of a protected version also
 * a license for download and printing) and the copy has a PDF scan.
 *
 * @param circle - who can open the link
 * @param option - what the server says about that circle
 * @param scanCount - number of PDF scans of the copy
 */
export function canOfferScan(circle: LinkCircle, option: CircleOption | null, scanCount: number): boolean {
	return circle !== 'internal' && option !== null && option.available && option.allowed && option.scan && scanCount > 0
}

/**
 * Where a link points: "Whole piece" or the measures, then voice and verse.
 *
 * @param place - measures, voice and verse of the link
 * @param place.measureFrom - first measure; null for the whole piece
 * @param place.measureTo - last measure; null for the whole piece
 * @param place.voice - focus voice
 * @param place.verse - verse
 * @param words - translated words of a text reference
 * @param whole - translated "Whole piece"
 */
export function scopeText(place: { measureFrom?: string | null, measureTo?: string | null, voice?: string | null, verse?: number | string | null },
	words: ReferenceWords, whole: string): string {
	const text = placeText(place, words)
	const hasMeasures = (place.measureFrom ?? '').trim() !== '' || (place.measureTo ?? '').trim() !== ''
	return hasMeasures ? text : [whole, text].filter((part) => part !== '').join(' · ')
}

/** Translated words of the preview "What the recipients see" */
export interface PreviewWords {
	scope: string
	whole: string
	measures: (from: string, to: string) => string
	missing: string
	voice: string
	none: string
	verse: string
	tempo: string
	asInScore: string
	validUntil: string
	playback: string
	practiceFiles: string
	scan: string
	/** "Without a network" */
	offline: string
	/** why it cannot be kept on a device */
	offlineNo: string
	yes: string
	no: string
}

export interface PreviewInput {
	circle: LinkCircle
	scope: LinkScope
	measureFrom: string
	measureTo: string
	voice: string
	verse: string
	tempo: string
	/** formatted date */
	validUntil: string
	option: CircleOption
	/** the scan is offered; null when the copy has none or the circle cannot offer it */
	scan: boolean | null
}

/**
 * The rows of "What the recipients see" for a link for choir members or the
 * public.
 *
 * @param input - the draft of the link and what the server allows
 * @param words - translated words
 */
export function previewRows(input: PreviewInput, words: PreviewWords): { label: string, value: string }[] {
	const from = input.measureFrom.trim()
	const to = input.measureTo.trim()
	const scope = input.scope === 'whole'
		? words.whole
		: (from !== '' && to !== '' ? words.measures(from, to) : words.missing)
	const rows = [
		{ label: words.scope, value: scope },
		{ label: words.voice, value: input.voice.trim() || words.none },
	]
	if (input.verse.trim() !== '') {
		rows.push({ label: words.verse, value: input.verse.trim() })
	}
	rows.push(
		{ label: words.tempo, value: input.tempo.trim() !== '' ? input.tempo.trim() + ' %' : words.asInScore },
		{ label: words.playback, value: input.option.playback ? words.yes : words.no },
	)
	if (input.circle === 'members') {
		rows.push({ label: words.practiceFiles, value: input.option.practiceAudio ? words.yes : words.no })
	}
	if (input.scan !== null) {
		rows.push({ label: words.scan, value: input.scan ? words.yes : words.no })
	}
	// whether the singers can practise it on the train (milestone 0.19)
	if (input.option.offline !== undefined && input.option.offline !== null) {
		rows.push({ label: words.offline, value: input.option.offline ? words.yes : words.offlineNo })
	}
	rows.push({ label: words.validUntil, value: input.validUntil })
	return rows
}
