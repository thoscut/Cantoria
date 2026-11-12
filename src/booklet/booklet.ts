/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The programme booklet for the audience (proposal 2, November 2026): what
 * the server hands out for it and how the record of a booklet is made. The
 * server decides per piece whether its lyrics may be printed; the PDF is
 * made in this browser (build.ts).
 */
import type { SheetEvent } from '../scoreFacts.ts'

import axios from '@nextcloud/axios'
import { apiUrl } from '../api.ts'

export interface BookletPerson {
	role: 'composer' | 'lyricist' | 'arranger' | 'translator'
	name: string
	born: string | null
	died: string | null
}

export interface BookletPiece {
	itemId: number
	title: string
	composer: string | null
	arranger: string | null
	persons: BookletPerson[]
	/** seconds */
	duration: number | null
	encore: boolean
	participation: string | null
	language: string | null
	/** the verses read from the MusicXML, only when the rights allow printing them */
	lyrics: { number: string, text: string }[] | null
	translation: string | null
	translationSource: string | null
	/** why no lyrics are printed, or null */
	lyricsReason: string | null
}

export interface Booklet {
	event: SheetEvent
	pieces: BookletPiece[]
}

/**
 * The pieces of a concert for its booklet.
 *
 * @param eventId - the concert
 */
export async function fetchBooklet(eventId: number): Promise<Booklet> {
	const { data } = await axios.get<Booklet>(apiUrl('/events/{id}/booklet', { id: eventId }))
	return data
}

/**
 * Records in the history that a booklet was made, and with whose lyrics.
 *
 * @param eventId - the concert
 * @param withLyrics - item ids of the pieces whose lyrics are printed
 */
export async function recordBooklet(eventId: number, withLyrics: number[]): Promise<void> {
	await axios.post(apiUrl('/events/{id}/booklet/record', { id: eventId }), { withLyrics })
}
