/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The list of "Shared with me" (milestone 0.20, U24): the pieces of a
 * programme shared with the choir under its title and date, in programme
 * order, the programmes in the order the server gives them (the next
 * concert first); the other links below, newest first. And which pieces
 * are new since the last visit: remembered in this browser only, as marks
 * that do not reveal the links, and never sent to the server.
 */
import type { MemberLink } from '../types.ts'

import { readStored, removeStoredStarting, writeStored } from '../storage.ts'

export interface ProgrammeGroup {
	key: string
	title: string
	/** date of the concert (YYYY-MM-DD), null without one */
	date: string | null
	links: MemberLink[]
}

export interface MemberGroups {
	programmes: ProgrammeGroup[]
	/** links shared on their own, in the order of the server */
	others: MemberLink[]
}

/**
 * The links by programme. A link of two programmes stands in both.
 *
 * @param links - the links of the page, in the order of the server
 */
export function groupByProgramme(links: readonly MemberLink[]): MemberGroups {
	const groups = new Map<string, { group: ProgrammeGroup, order: number, places: Map<MemberLink, number> }>()
	const others: MemberLink[] = []
	for (const link of links) {
		const programmes = Array.isArray(link.programmes) ? link.programmes : []
		if (programmes.length === 0) {
			others.push(link)
			continue
		}
		for (const programme of programmes) {
			let entry = groups.get(programme.key)
			if (!entry) {
				entry = { group: { key: programme.key, title: programme.title, date: programme.date, links: [] }, order: programme.order, places: new Map() }
				groups.set(programme.key, entry)
			}
			if (!entry.places.has(link)) {
				entry.group.links.push(link)
				entry.places.set(link, programme.position)
			}
		}
	}
	const programmes = [...groups.values()]
		.sort((a, b) => a.order - b.order)
		.map(({ group, places }) => ({ ...group, links: [...group.links].sort((a, b) => (places.get(a) ?? 0) - (places.get(b) ?? 0)) }))
	return { programmes, others }
}

/** Key of what this browser remembers of the visits, without the prefix of the app */
const VISITS = 'member.seen'
/** Page loads closer together than this belong to one visit: a new piece stays marked while one practises */
export const VISIT_MS = 60 * 60 * 1000

interface Visits {
	/** marks of the links listed at the last load */
	seen: string[]
	/** marks of the links new in the current visit */
	fresh: string[]
	/** time of the last load (ms) */
	at: number
}

/**
 * FNV-1a, 32 bits, as hexadecimal text.
 *
 * @param text - the text
 */
function fnv(text: string): string {
	let hash = 0x811c9dc5
	for (let i = 0; i < text.length; i++) {
		hash ^= text.charCodeAt(i)
		hash = Math.imul(hash, 0x01000193) >>> 0
	}
	return hash.toString(16).padStart(8, '0')
}

/**
 * A mark of a link for this browser: the same for the same link, without
 * its address.
 *
 * @param token - token of the link
 */
export function linkMark(token: string): string {
	return fnv('cantoria|' + token) + fnv(token + '|cantoria')
}

/**
 * What this browser remembers, or null.
 *
 * @param stored - the stored text
 */
function parseVisits(stored: string | null): Visits | null {
	if (stored === null) {
		return null
	}
	try {
		const value = JSON.parse(stored) as Partial<Visits> | null
		const marks = (list: unknown): string[] => (Array.isArray(list) ? list.filter((mark): mark is string => typeof mark === 'string') : [])
		return value !== null && typeof value === 'object' && typeof value.at === 'number'
			? { seen: marks(value.seen), fresh: marks(value.fresh), at: value.at }
			: null
	} catch {
		return null
	}
}

/**
 * The links that came since the last visit in this browser, and remember
 * the links of this one for the next. At the first visit nothing is new;
 * loads within VISIT_MS of each other are one visit, so that a reload keeps
 * the marks. Each account on the device has its own.
 *
 * @param links - the links of the page
 * @param owner - the account, so that two people on one device are told apart
 * @param now - the time (ms)
 * @return the tokens of the new links
 */
export function newSinceLastVisit(links: readonly MemberLink[], owner: string, now = Date.now()): Set<string> {
	const key = VISITS + '.' + fnv('cantoria|' + owner)
	const marks = new Map(links.map((link) => [linkMark(link.token), link.token]))
	const visits = parseVisits(readStored(key))
	let fresh: string[] = []
	if (visits !== null) {
		const seen = new Set(visits.seen)
		const added = [...marks.keys()].filter((mark) => !seen.has(mark))
		const sameVisit = now >= visits.at && now - visits.at < VISIT_MS
		fresh = [...new Set([...(sameVisit ? visits.fresh.filter((mark) => marks.has(mark)) : []), ...added])]
	}
	writeStored(key, JSON.stringify({ seen: [...marks.keys()], fresh, at: now } satisfies Visits))
	return new Set(fresh.map((mark) => marks.get(mark)).filter((token): token is string => token !== undefined))
}

/** Forget the visits of every account ("Delete everything on this device"). */
export function forgetVisits(): void {
	removeStoredStarting([VISITS])
}
