/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Voice } from '../score/voices.ts'
import type { CircleOption } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { excerptTempo, voiceForServer, voiceOfLink } from '../sharing/excerpt.ts'
import { canOfferScan, initialScope, linkMeasures, measureProblem, previewRows, scopeText } from '../sharing/linkScope.ts'

const voice = (key: string, name: string | null, part: string, number = 1): Voice => ({
	key, name, part, number, members: [{ staff: Number(key.split('.')[0]), layer: Number(key.split('.')[1]) }], notes: 10,
})

const voices = [voice('1.1', 'Tenor I', 'Tenor I/II'), voice('1.2', 'Tenor II', 'Tenor I/II', 2), voice('2.1', null, 'Bass')]
const labelOf = (v: Voice): string => v.name ?? `${v.part}, voice ${v.number}`

describe('excerptTempo', () => {
	it('keeps the tempo of a link within the slider and in steps of 5', () => {
		expect(excerptTempo(null)).toBe(100)
		expect(excerptTempo(undefined)).toBe(100)
		expect(excerptTempo(80)).toBe(80)
		expect(excerptTempo(83)).toBe(85)
		expect(excerptTempo(20)).toBe(25)
		expect(excerptTempo(300)).toBe(200)
		expect(excerptTempo(0)).toBe(100)
		expect(excerptTempo(Number.NaN)).toBe(100)
	})
})

describe('voiceOfLink', () => {
	it('finds the voice by its display name, ignoring case and spaces', () => {
		expect(voiceOfLink(voices, ' tenor ii ', labelOf)?.key).toBe('1.2')
		expect(voiceOfLink(voices, 'Bass, voice 1', labelOf)?.key).toBe('2.1')
	})

	it('gives no voice for an empty or unknown name', () => {
		expect(voiceOfLink(voices, null, labelOf)).toBeNull()
		expect(voiceOfLink(voices, '', labelOf)).toBeNull()
		expect(voiceOfLink(voices, 'Sopran', labelOf)).toBeNull()
	})
})

describe('voiceForServer', () => {
	it('drops control characters and keeps at most 100 characters', () => {
		expect(voiceForServer(' Tenor\u0007 II ')).toBe('Tenor II')
		expect(voiceForServer('x'.repeat(120))).toHaveLength(100)
	})
})

const words = {
	page: (page: string) => `p. ${page}`,
	measure: (measure: string) => `m. ${measure}`,
	measures: (from: string, to: string) => `m. ${from}–${to}`,
	verse: (verse: string) => `verse ${verse}`,
}

const allowed: CircleOption = { available: true, allowed: true, reason: null, scan: true, scanReason: null, playback: true, practiceAudio: true, offline: true }

const previewWords = {
	scope: 'Shared',
	whole: 'Whole piece',
	measures: (from: string, to: string) => `Measures ${from} to ${to}`,
	missing: 'measures still missing',
	voice: 'Focus voice',
	none: 'none',
	verse: 'Verse',
	tempo: 'Tempo',
	asInScore: 'as in the score',
	validUntil: 'Valid until',
	playback: 'Playback',
	practiceFiles: 'Practice files',
	scan: 'Scan (PDF)',
	offline: 'Without a network',
	offlineNo: 'No: the rights allow no copy',
	yes: 'Yes',
	no: 'No',
}

describe('the scope of a link', () => {
	it('starts with the whole piece unless the score view shows measures', () => {
		expect(initialScope(null)).toBe('whole')
		expect(initialScope({ measureFrom: null, measureTo: null })).toBe('whole')
		expect(initialScope({ measureFrom: ' ', measureTo: '' })).toBe('whole')
		expect(initialScope({ measureFrom: '5', measureTo: '8' })).toBe('excerpt')
	})

	it('sends no measures for the whole piece, whatever is typed', () => {
		expect(linkMeasures('whole', '5', '8')).toEqual({ measure_from: null, measure_to: null })
		expect(linkMeasures('excerpt', ' 5 ', '8')).toEqual({ measure_from: '5', measure_to: '8' })
		expect(linkMeasures('excerpt', '', '')).toEqual({ measure_from: null, measure_to: null })
	})

	it('asks for both measures of an excerpt before the server is asked', () => {
		expect(measureProblem('whole', 'members', '', '')).toBeNull()
		expect(measureProblem('excerpt', 'members', '5', '')).toBe('missing')
		expect(measureProblem('excerpt', 'public', '', '')).toBe('missing')
		expect(measureProblem('excerpt', 'internal', '5', '')).toBeNull()
		expect(measureProblem('excerpt', 'internal', '', '')).toBe('missing')
		expect(measureProblem('excerpt', 'members', '5', '8<')).toBe('format')
		expect(measureProblem('excerpt', 'public', 'X1', '12a')).toBeNull()
	})

	it('offers the scan only to choir members and the public, when the link is allowed and there is a scan', () => {
		expect(canOfferScan('members', allowed, 1)).toBe(true)
		expect(canOfferScan('public', allowed, 2)).toBe(true)
		expect(canOfferScan('internal', allowed, 1)).toBe(false)
		expect(canOfferScan('members', allowed, 0)).toBe(false)
		expect(canOfferScan('members', { ...allowed, allowed: false }, 1)).toBe(false)
		expect(canOfferScan('public', { ...allowed, available: false }, 1)).toBe(false)
		expect(canOfferScan('members', null, 1)).toBe(false)
		expect(canOfferScan('members', { ...allowed, scan: false, scanReason: 'needs a license for download and printing' }, 1)).toBe(false)
	})

	it('names the whole piece or the measures in lists and text references', () => {
		expect(scopeText({ measureFrom: null, measureTo: null, voice: 'Alto', verse: 2 }, words, 'Whole piece')).toBe('Whole piece · Alto · verse 2')
		expect(scopeText({ measureFrom: null, measureTo: null }, words, 'Whole piece')).toBe('Whole piece')
		expect(scopeText({ measureFrom: '5', measureTo: '8', voice: 'Alto' }, words, 'Whole piece')).toBe('m. 5–8 · Alto')
		expect(scopeText({ measureFrom: '5', measureTo: null }, words, 'Whole piece')).toBe('m. 5')
	})
})

describe('what the recipients see', () => {
	const input = {
		circle: 'members' as const,
		scope: 'whole' as const,
		measureFrom: '5',
		measureTo: '8',
		voice: 'Alto',
		verse: '',
		tempo: '',
		validUntil: '23.04.2027',
		option: allowed,
		scan: true as boolean | null,
	}

	it('lists the whole piece, voice, playback, practice files, scan and the expiry', () => {
		expect(previewRows(input, previewWords)).toEqual([
			{ label: 'Shared', value: 'Whole piece' },
			{ label: 'Focus voice', value: 'Alto' },
			{ label: 'Tempo', value: 'as in the score' },
			{ label: 'Playback', value: 'Yes' },
			{ label: 'Practice files', value: 'Yes' },
			{ label: 'Scan (PDF)', value: 'Yes' },
			{ label: 'Without a network', value: 'Yes' },
			{ label: 'Valid until', value: '23.04.2027' },
		])
	})

	it('says whether the singers can keep it on their device, while the module is on', () => {
		const offline = (value: boolean | null) => previewRows({ ...input, option: { ...allowed, offline: value } }, previewWords)
			.find((row) => row.label === 'Without a network')?.value
		expect(offline(false)).toBe('No: the rights allow no copy')
		expect(offline(null)).toBeUndefined()
	})

	it('names the measures of an excerpt, or that they are missing', () => {
		expect(previewRows({ ...input, scope: 'excerpt' }, previewWords)[0]).toEqual({ label: 'Shared', value: 'Measures 5 to 8' })
		expect(previewRows({ ...input, scope: 'excerpt', measureTo: ' ' }, previewWords)[0].value).toBe('measures still missing')
	})

	it('has no practice files for the public and no scan row without a scan', () => {
		const rows = previewRows({ ...input, circle: 'public', scan: null, verse: '2', tempo: '80', option: { ...allowed, playback: false, practiceAudio: false } },
			previewWords)
		expect(rows.map((row) => row.label)).toEqual(['Shared', 'Focus voice', 'Verse', 'Tempo', 'Playback', 'Without a network', 'Valid until'])
		expect(rows.find((row) => row.label === 'Tempo')?.value).toBe('80 %')
		expect(rows.find((row) => row.label === 'Playback')?.value).toBe('No')
		expect(previewRows({ ...input, scan: false }, previewWords).find((row) => row.label === 'Scan (PDF)')?.value).toBe('No')
	})
})
