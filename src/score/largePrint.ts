/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Large-print parts (November 2026): one voice of a score set large for
 * singers with a visual impairment – big notes, few measures per line,
 * strong lines, the lyrics below. The part is cut from the MusicXML in the
 * browser (the staves of the chosen voices, whole parts) and engraved by
 * Verovio on A4 pages; nothing is sent anywhere.
 *
 * Voices that share a staff (soprano and alto on one staff) stay together:
 * taking one of them out would mean rewriting the measures.
 */
import type { VerovioToolkit } from './toolkit.ts'

export type LargePrintSize = 'large' | 'larger' | 'largest'

/** Size of the notes in percent of normal engraving (staff height about 7 mm) */
export const LARGE_PRINT_SCALE: Record<LargePrintSize, number> = { large: 140, larger: 180, largest: 230 }

/** A4 in tenths of a millimetre, less a strip at the top for the running line */
const PAGE_WIDTH = 2100
export const HEADER_STRIP = 120
const PAGE_HEIGHT = 2970 - HEADER_STRIP
const MARGIN = 130

/**
 * Verovio's options for a size: the page shrinks as the notes grow, so the
 * rendered page is always A4.
 *
 * @param size - the size of the notes
 */
export function largePrintOptions(size: LargePrintSize): Record<string, unknown> {
	const scale = LARGE_PRINT_SCALE[size]
	const unit = (value: number) => Math.round(value * 100 / scale)
	return {
		inputFrom: 'musicxml',
		header: 'none',
		footer: 'none',
		breaks: 'auto',
		scale,
		pageWidth: unit(PAGE_WIDTH),
		pageHeight: unit(PAGE_HEIGHT),
		pageMarginTop: unit(40),
		pageMarginBottom: unit(MARGIN),
		pageMarginLeft: unit(MARGIN),
		pageMarginRight: unit(MARGIN),
		adjustPageHeight: false,
		// strong lines and large lyrics
		staffLineWidth: 0.25,
		stemWidth: 0.3,
		barLineWidth: 0.5,
		ledgerLineThickness: 0.35,
		lyricSize: 6,
		spacingSystem: 16,
		svgHtml5: true,
		svgViewBox: true,
		smuflTextFont: 'embedded',
		xmlIdChecksum: true,
	}
}

/**
 * How many staves each part of a partwise MusicXML score has.
 *
 * @param document - the score
 */
export function stavesPerPart(document: Document): number[] {
	return Array.from(document.querySelectorAll('score-partwise > part')).map((part) => {
		const staves = Number(part.querySelector('attributes > staves')?.textContent ?? '1')
		return Number.isInteger(staves) && staves > 0 ? staves : 1
	})
}

/**
 * The score with only the parts that hold the given staves. The names of
 * the parts are not printed before the lines (they would take a third of a
 * large line); the part groups go, since they may span removed parts.
 *
 * @param xml - partwise MusicXML
 * @param staves - positions of the staves to keep, counted from 0 over all parts
 * @return MusicXML, or null when the file cannot be read or nothing is left
 */
export function extractStaves(xml: string, staves: number[]): string | null {
	const document = new DOMParser().parseFromString(xml, 'application/xml')
	if (document.querySelector('parsererror') || document.documentElement.nodeName !== 'score-partwise') {
		return null
	}
	const counts = stavesPerPart(document)
	const parts = Array.from(document.querySelectorAll('score-partwise > part'))
	const keep = new Set<string>()
	let first = 0
	parts.forEach((part, index) => {
		const count = counts[index]
		if (staves.some((staff) => staff >= first && staff < first + count)) {
			keep.add(part.getAttribute('id') ?? '')
		}
		first += count
	})
	if (keep.size === 0) {
		return null
	}
	for (const part of parts) {
		if (!keep.has(part.getAttribute('id') ?? '')) {
			part.remove()
		}
	}
	document.querySelectorAll('part-list > part-group').forEach((group) => group.remove())
	for (const scorePart of Array.from(document.querySelectorAll('part-list > score-part'))) {
		if (!keep.has(scorePart.getAttribute('id') ?? '')) {
			scorePart.remove()
			continue
		}
		for (const name of ['part-name', 'part-abbreviation']) {
			const element = scorePart.querySelector(`:scope > ${name}`)
			if (element) {
				element.textContent = ''
				element.setAttribute('print-object', 'no')
			}
		}
		scorePart.querySelectorAll(':scope > part-name-display, :scope > part-abbreviation-display').forEach((display) => display.remove())
	}
	return new XMLSerializer().serializeToString(document)
}

/**
 * Engrave the part.
 *
 * @param toolkit - a toolkit of its own (the options differ from the score view's)
 * @param xml - the MusicXML of the part (extractStaves)
 * @param size - the size of the notes
 * @return the pages as SVG, or null when Verovio cannot read the part
 */
export function renderLargePrint(toolkit: VerovioToolkit, xml: string, size: LargePrintSize): string[] | null {
	toolkit.setOptions(largePrintOptions(size))
	if (!toolkit.loadData(xml)) {
		return null
	}
	const pages: string[] = []
	const count = toolkit.getPageCount()
	for (let page = 1; page <= count; page++) {
		pages.push(toolkit.renderToSVG(page, false))
	}
	return pages
}
