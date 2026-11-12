// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { extractStaves, LARGE_PRINT_SCALE, largePrintOptions, stavesPerPart } from '../score/largePrint.ts'

const score = `<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0">
  <part-list>
    <part-group type="start" number="1"><group-symbol>bracket</group-symbol></part-group>
    <score-part id="P1"><part-name>Sopran</part-name><part-abbreviation>S</part-abbreviation></score-part>
    <score-part id="P2"><part-name>Alt</part-name></score-part>
    <part-group type="stop" number="1"/>
    <score-part id="P3"><part-name>Klavier</part-name></score-part>
  </part-list>
  <part id="P1"><measure number="1"><attributes><divisions>1</divisions></attributes><note><pitch><step>C</step><octave>5</octave></pitch><duration>4</duration></note></measure></part>
  <part id="P2"><measure number="1"><attributes><divisions>1</divisions></attributes><note><pitch><step>A</step><octave>4</octave></pitch><duration>4</duration></note></measure></part>
  <part id="P3"><measure number="1"><attributes><divisions>1</divisions><staves>2</staves></attributes><note><pitch><step>C</step><octave>3</octave></pitch><duration>4</duration><staff>2</staff></note></measure></part>
</score-partwise>`

/**
 * @param xml - MusicXML
 */
function parse(xml: string): Document {
	return new DOMParser().parseFromString(xml, 'application/xml')
}

describe('large-print part', () => {
	it('counts the staves of each part', () => {
		expect(stavesPerPart(parse(score))).toEqual([1, 1, 2])
	})

	it('keeps only the part of the chosen staff, without part groups and names before the lines', () => {
		const part = parse(extractStaves(score, [1]) ?? '')
		expect(Array.from(part.querySelectorAll('score-partwise > part')).map((element) => element.getAttribute('id'))).toEqual(['P2'])
		expect(part.querySelectorAll('part-list > score-part')).toHaveLength(1)
		expect(part.querySelectorAll('part-group')).toHaveLength(0)
		expect(part.querySelector('part-name')?.textContent).toBe('')
		expect(part.querySelector('part-name')?.getAttribute('print-object')).toBe('no')
	})

	it('keeps a part of two staves whole, and a second voice with it', () => {
		const part = parse(extractStaves(score, [0, 3]) ?? '')
		expect(Array.from(part.querySelectorAll('score-partwise > part')).map((element) => element.getAttribute('id'))).toEqual(['P1', 'P3'])
		expect(part.querySelector('part[id="P3"] staves')?.textContent).toBe('2')
	})

	it('gives nothing for a staff that does not exist or a file that is no score', () => {
		expect(extractStaves(score, [9])).toBeNull()
		expect(extractStaves('<html/>', [0])).toBeNull()
		expect(extractStaves('not xml', [0])).toBeNull()
	})

	it('cuts a real score', () => {
		const xml = Object.values(import.meta.glob('../../tests/fixtures/demo/1-01_*.musicxml', { query: '?raw', import: 'default', eager: true }))[0] as string
		const part = parse(extractStaves(xml, [1]) ?? '')
		expect(part.querySelectorAll('score-partwise > part')).toHaveLength(1)
		expect(part.querySelectorAll('measure').length).toBeGreaterThan(4)
	})

	it('shrinks the page as the notes grow, so that the sheet stays A4', () => {
		for (const size of ['large', 'larger', 'largest'] as const) {
			const options = largePrintOptions(size)
			expect(Math.round(Number(options.pageWidth) * LARGE_PRINT_SCALE[size] / 100)).toBeCloseTo(2100, -1)
			expect(options.header).toBe('none')
		}
	})
})
