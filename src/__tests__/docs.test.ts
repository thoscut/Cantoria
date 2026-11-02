/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { GuideSection } from '../docs.ts'

import { setLanguage } from '@nextcloud/l10n'
import { describe, expect, it } from 'vitest'
import info from '../../appinfo/info.xml?raw'
import pageController from '../../lib/Controller/PageController.php?raw'
import { ADMIN_GUIDE, adminGuideUrl, headingAnchor, USER_GUIDE, userGuideUrl } from '../docs.ts'

/** The Markdown files of docs/, by path relative to the repository. */
const DOCS = Object.fromEntries(Object.entries(import.meta.glob('../../docs/**/*.md', { query: '?raw', import: 'default', eager: true }))
	.map(([path, text]) => [path.replace('../../', ''), text as string]))

/** Other files the documentation may link (only their existence counts). */
const OTHER_FILES = new Set(Object.keys(import.meta.glob([
	'../../docs/screenshots/**/*.png',
	'../../*.md',
	'../../openapi*.json',
	'../../tests/README.md',
	'../../tools/*/README.md',
])).map((path) => path.replace('../../', '')))

/**
 * Screenshots the guides already show but that are still to be taken
 * (docs/screenshots/README.md, "Planned"). Remove an entry once the file
 * exists.
 */
const PLANNED = new Set(['docs/screenshots/admin-settings.png'])

/** The guides in both languages: English file and its German counterpart. */
const PAIRS = [
	['docs/user-guide.md', 'docs/de/benutzerhandbuch.md'],
	['docs/admin-guide.md', 'docs/de/administrationshandbuch.md'],
	['docs/legal-background.md', 'docs/de/rechtlicher-hintergrund.md'],
	['docs/privacy-templates.md', 'docs/de/datenschutz-vorlagen.md'],
	['docs/alongside-a-choir-system.md', 'docs/de/neben-einem-chorsystem.md'],
	['docs/accessibility.md', 'docs/de/barrierefreiheit.md'],
] as const

/** The pages whose links are checked. */
const CHECKED = ['docs/README.md', ...PAIRS.flat(), 'docs/de/schnellstart.md']

/**
 * Text of a documentation file.
 *
 * @param path - path relative to the repository
 */
function doc(path: string): string {
	const text = DOCS[path]
	if (text === undefined) {
		throw new Error('missing ' + path)
	}
	return text
}

/**
 * The headings of a Markdown text outside code blocks: level and anchor.
 * A repeated anchor gets "-1", "-2" … as on GitHub.
 *
 * @param text - Markdown
 */
function headings(text: string): { level: number, anchor: string, repeated: boolean }[] {
	const found: { level: number, anchor: string, repeated: boolean }[] = []
	const seen = new Map<string, number>()
	let code = false
	for (const line of text.split(/\r?\n/)) {
		if (/^\s*(```|~~~)/.test(line)) {
			code = !code
			continue
		}
		const match = code ? null : /^(#{1,6}) (.+)$/.exec(line)
		if (match) {
			const anchor = headingAnchor(match[2]!)
			const count = seen.get(anchor) ?? 0
			seen.set(anchor, count + 1)
			found.push({ level: match[1]!.length, anchor: count ? `${anchor}-${count}` : anchor, repeated: count > 0 })
		}
	}
	return found
}

/**
 * The number of images (`![…](…)`) per section of a Markdown text: the text
 * before the first heading, then each heading of any level up to the next.
 * Headings inside code blocks do not start a section.
 *
 * @param text - Markdown
 */
function imagesPerSection(text: string): number[] {
	const counts = [0]
	let code = false
	for (const line of text.split(/\r?\n/)) {
		if (/^\s*(```|~~~)/.test(line)) {
			code = !code
		} else if (!code && /^#{1,6} /.test(line)) {
			counts.push(0)
		} else {
			counts[counts.length - 1]! += line.match(/!\[[^\]]*\]\([^)]+\)/g)?.length ?? 0
		}
	}
	return counts
}

/**
 * The anchors of the headings of a guide.
 *
 * @param path - path relative to the repository
 */
function anchors(path: string): string[] {
	return headings(doc(path)).map((heading) => heading.anchor)
}

/**
 * A relative link resolved against the folder of the page.
 *
 * @param page - path of the page relative to the repository
 * @param target - the link without its anchor
 */
function resolve(page: string, target: string): string {
	const parts = page.split('/').slice(0, -1)
	for (const part of target.split('/')) {
		if (part === '..') {
			parts.pop()
		} else if (part !== '.' && part !== '') {
			parts.push(part)
		}
	}
	return parts.join('/')
}

describe('links into the guides', () => {
	it('computes the anchors the way GitHub does', () => {
		expect(headingAnchor('2. Roles and groups')).toBe('2-roles-and-groups')
		expect(headingAnchor('Setting up in five steps')).toBe('setting-up-in-five-steps')
		expect(headingAnchor('"The score cannot be shown"')).toBe('the-score-cannot-be-shown')
		expect(headingAnchor('Startseite „Fällig“')).toBe('startseite-fällig')
		expect(headingAnchor('Dateien, die sich nicht öffnen lassen')).toBe('dateien-die-sich-nicht-öffnen-lassen')
	})

	it.each([
		['administration guide', ADMIN_GUIDE, PAIRS[1]],
		['user guide', USER_GUIDE, PAIRS[0]],
	] as const)('point to sections that exist in the English and the German %s', (_name, sections, [english, german]) => {
		const en = anchors(english)
		const de = anchors(german)
		for (const section of Object.values(sections) as GuideSection[]) {
			expect(en, section.en).toContain(section.en)
			expect(de, section.de).toContain(section.de)
		}
	})

	it('are the same on the page "Access forbidden" of the server', () => {
		expect(pageController).toContain(`'user-guide.md#${USER_GUIDE.noAccess.en}'`)
		expect(pageController).toContain(`'de/benutzerhandbuch.md#${USER_GUIDE.noAccess.de}'`)
	})

	it('point to the documentation named in the app info', () => {
		setLanguage('en')
		expect(info).toContain('<admin>' + adminGuideUrl(ADMIN_GUIDE.setup).split('#')[0] + '</admin>')
		expect(info).toContain('<user>' + userGuideUrl(USER_GUIDE.filesNotShared).split('#')[0] + '</user>')
	})
})

describe('the guides in English and German', () => {
	it.each(PAIRS)('%s and %s have the same headings in the same order', (english, german) => {
		// A section added in only one language shows up here: change both.
		const levels = (path: string) => headings(doc(path)).map((heading) => heading.level)
		expect(levels(german)).toEqual(levels(english))
	})

	it.each(PAIRS)('%s and %s show the same number of images in each section', (english, german) => {
		// A screenshot added in only one language shows up here: add it to both.
		expect(imagesPerSection(doc(german))).toEqual(imagesPerSection(doc(english)))
	})

	it.each([...PAIRS.map(([, german]) => german), 'docs/de/schnellstart.md'])('%s shows only pictures of the German interface', (german) => {
		// tools/screenshots takes them as de/<file> (GERMAN_TWINS in shots.mjs)
		const pictures = [...doc(german).matchAll(/!\[[^\]]*\]\(([^)\s]+)\)/g)].map((match) => match[1]!)
		expect(pictures.filter((picture) => !picture.startsWith('../screenshots/de/'))).toEqual([])
	})

	it.each(PAIRS)('%s and %s link each other at the top', (english, german) => {
		expect(doc(english)).toContain(`[Deutsch](${german.replace('docs/', '')})`)
		expect(doc(german)).toContain(`[English](../${english.replace('docs/', '')})`)
	})

	it.each(CHECKED)('%s has no heading twice', (path) => {
		// A repeated heading gets the anchor "…-1", which is easily linked wrongly.
		expect(headings(doc(path)).filter((heading) => heading.repeated).map((heading) => heading.anchor)).toEqual([])
	})

	it.each(CHECKED)('%s links only files and sections that exist', (page) => {
		const broken: string[] = []
		for (const [, link] of doc(page).matchAll(/\]\(([^)\s]+)\)/g)) {
			if (/^[a-z]+:/i.test(link!)) {
				continue
			}
			const [target, anchor] = link!.split('#') as [string, string | undefined]
			const file = target === '' ? page : resolve(page, target)
			if (DOCS[file] === undefined && !OTHER_FILES.has(file) && !PLANNED.has(file)) {
				broken.push(link!)
			} else if (anchor !== undefined && DOCS[file] !== undefined && !anchors(file).includes(decodeURIComponent(anchor))) {
				broken.push(link!)
			}
		}
		expect(broken).toEqual([])
	})
})
