/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ReportBooklet } from '../report/build.ts'

import { PDFDocument } from 'pdf-lib'
import { describe, expect, it } from 'vitest'
import { buildReport } from '../report/build.ts'

const report: ReportBooklet = {
	title: 'Jahresbericht 2026',
	overline: 'Kammerchor',
	subtitle: 'Stand 12.11.2026',
	figuresHeading: 'Das Jahr in Zahlen',
	figures: [{ value: '7', label: 'Gesungene Konzerte' }, { value: '86', label: 'Gesungene Stücke' }],
	concertsHeading: 'Konzerte',
	concerts: [],
	noConcerts: 'Keine Konzerte in diesem Jahr.',
	tables: [{ heading: 'Bestand', note: '', rows: [{ label: 'Werke', value: '812' }] }],
	lists: [{ heading: 'Neuzugänge', items: [], more: '' }],
}

describe('annual report as a booklet', () => {
	it('has a cover and one page for a small report on A4', async () => {
		const built = await buildReport(report)
		expect(built.pages).toBe(2)
		const document = await PDFDocument.load(built.bytes)
		expect(document.getPage(0).getSize().width).toBeCloseTo(595.28)
		expect(document.getTitle()).toBe('Jahresbericht 2026')
	})

	it('flows long lists over further pages', async () => {
		const concerts = Array.from({ length: 30 }, (_, index) => ({ date: `0${index % 9 + 1}.05.2026`, title: `Konzert ${index + 1}`, detail: 'Stadtkirche · 12 Stücke', sung: index % 2 === 0 }))
		const items = Array.from({ length: 80 }, (_, index) => ({ title: `12-${index} Stück – „Otče náš“`, detail: 'Komponist · 03.02.2026' }))
		const built = await buildReport({ ...report, concerts, lists: [{ heading: 'Neuzugänge', items, more: 'und weitere' }] })
		expect(built.pages).toBeGreaterThan(4)
	})
})
