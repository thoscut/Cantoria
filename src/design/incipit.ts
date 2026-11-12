/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Incipits: the first bars of a piece, drawn from the Plaine & Easie Code
 * the server read from its MusicXML (`ScoreFacts::incipit`). One Verovio
 * toolkit draws them one after the other; each drawing is kept for the
 * page, so scrolling back costs nothing.
 *
 * The code is checked before Verovio reads it: only the characters of the
 * notes, durations, bars and signatures are allowed, so that the format
 * cannot carry anything else, and the SVG goes through `sanitizeSvg`.
 */
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Incipit } from '../scoreFacts.ts'

import { sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'
import { paeText } from '../scoreFacts.ts'

const drawn = new Map<string, Promise<string | null>>()
let toolkit: Promise<VerovioToolkit> | null = null
let queue: Promise<unknown> = Promise.resolve()

/**
 * Whether an incipit holds only what the Plaine & Easie Code needs here.
 *
 * @param incipit - the incipit from the server
 */
export function isSafeIncipit(incipit: Incipit): boolean {
	return /^[CFGcfg]-[1-5]$/.test(incipit.clef)
		&& /^(|[xb][A-G]{1,7})$/.test(incipit.keysig)
		&& /^(|c|c\/|\d{1,2}\/\d{1,2})$/.test(incipit.timesig)
		&& incipit.data.length <= 600
		&& /^[0-9A-G',./=+\-xbn ]*$/.test(incipit.data)
}

/**
 * The SVG of an incipit, or null when it cannot be drawn.
 *
 * @param incipit - the incipit
 */
export function drawIncipit(incipit: Incipit): Promise<string | null> {
	if (!isSafeIncipit(incipit)) {
		return Promise.resolve(null)
	}
	const text = paeText(incipit)
	const known = drawn.get(text)
	if (known) {
		return known
	}
	const result = queue.then(async () => {
		toolkit ??= createToolkit()
		const engraver = await toolkit
		engraver.setOptions({
			inputFrom: 'pae',
			scale: 32,
			pageWidth: 2400,
			pageHeight: 400,
			adjustPageWidth: true,
			adjustPageHeight: true,
			header: 'none',
			footer: 'none',
			breaks: 'none',
			pageMarginTop: 0,
			pageMarginBottom: 0,
			pageMarginLeft: 0,
			pageMarginRight: 0,
			svgHtml5: true,
		})
		if (!engraver.loadData(text)) {
			return null
		}
		return sanitizeSvg(engraver.renderToSVG(1))
	}).catch(() => null)
	queue = result
	drawn.set(text, result)
	return result
}
