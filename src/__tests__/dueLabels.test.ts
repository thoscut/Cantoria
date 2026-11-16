/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { register, setLanguage, unregister } from '@nextcloud/l10n'
import { afterEach, describe, expect, it } from 'vitest'
import { dueCardLabel } from '../dueLabels.ts'

/** Card keys of GET /api/due (docs/api.md). */
const KEYS = ['tasks_open', 'tasks_answered', 'ambiguous_items', 'proposals', 'portal_remove', 'portal_add',
	'confirm_current', 'rule1_current', 'licenses_expiring', 'valid_missing', 'links_ending']

/**
 * Use a generated translation of the app.
 *
 * @param language - 'de' (informal) or 'de_DE' (formal)
 */
async function useTranslation(language: 'de' | 'de_DE'): Promise<void> {
	const bundle = await import(`../../l10n/${language}.json`)
	// Nextcloud writes the language in BCP 47 form into <html lang>: "de-DE"
	setLanguage(language.replace('_', '-'))
	register('cantoria', bundle.translations)
}

afterEach(() => {
	unregister('cantoria')
	setLanguage('en')
})

describe('dueCardLabel', () => {
	it('uses the singular for one and the plural for other numbers', () => {
		expect(dueCardLabel('proposals', 1, 'Proposals in the inbox')).toBe('Proposal in the inbox')
		expect(dueCardLabel('proposals', 2, 'Proposals in the inbox')).toBe('Proposals in the inbox')
		expect(dueCardLabel('portal_remove', 1, '')).toBe('Version to remove from the singers\' portal')
		expect(dueCardLabel('licenses_expiring', 12, '')).toBe('Licenses ending or ended')
		expect(dueCardLabel('links_ending', 1, '')).toBe('Link ending or ended')
		expect(dueCardLabel('links_ending', 2, '')).toBe('Links ending or ended')
	})

	it('has its own singular and plural for every card', () => {
		for (const key of KEYS) {
			const one = dueCardLabel(key, 1, 'fallback')
			const many = dueCardLabel(key, 3, 'fallback')
			expect(one, key).not.toBe('fallback')
			expect(one, key).not.toBe(many)
		}
	})

	it('keeps the label of the server for cards it does not know', () => {
		expect(dueCardLabel('something_new', 1, 'Etwas Neues')).toBe('Etwas Neues')
	})

	it('is translated with singular and plural in both German forms of address', async () => {
		const english = KEYS.map((key) => [dueCardLabel(key, 1, ''), dueCardLabel(key, 3, '')])
		for (const language of ['de', 'de_DE'] as const) {
			await useTranslation(language)
			KEYS.forEach((key, index) => {
				const one = dueCardLabel(key, 1, '')
				const many = dueCardLabel(key, 3, '')
				expect(one, `${language} ${key}`).not.toBe(english[index][0])
				expect(many, `${language} ${key}`).not.toBe(english[index][1])
				expect(one, `${language} ${key}`).not.toBe(many)
			})
			expect(dueCardLabel('proposals', 1, '')).toBe('Vorschlag im Eingang')
			expect(dueCardLabel('proposals', 4, '')).toBe('Vorschläge im Eingang')
			unregister('cantoria')
		}
		await useTranslation('de')
		expect(dueCardLabel('tasks_open', 1, '')).toBe('Offene Aufgabe für deine Rolle')
		await useTranslation('de_DE')
		expect(dueCardLabel('tasks_open', 2, '')).toBe('Offene Aufgaben für Ihre Rolle')
	})
})
