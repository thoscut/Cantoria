/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Small texts of the programme booklet: life dates and the verses as one
 * editable text.
 */

/**
 * Life dates for the booklet: "1740–1815", "* 1962", "† 1815" or nothing.
 *
 * @param person - the person
 * @param beforeChrist - a year before Christ, e.g. "{year} BC"
 */
export function lifeDates(person: { born: string | null, died: string | null }, beforeChrist: (year: string) => string): string {
	const year = (value: string) => value.startsWith('-') ? beforeChrist(value.slice(1)) : value
	if (person.born && person.died) {
		return `${year(person.born)}–${year(person.died)}`
	}
	if (person.born) {
		return `* ${year(person.born)}`
	}
	return person.died ? `† ${year(person.died)}` : ''
}

/**
 * The verses as one editable text: verses apart by an empty line, with
 * their number when there is more than one.
 *
 * @param verses - the verses
 */
export function versesText(verses: { number: string, text: string }[]): string {
	return verses.map((verse) => verses.length > 1 ? `${verse.number}. ${verse.text}` : verse.text).join('\n\n')
}
