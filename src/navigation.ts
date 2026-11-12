/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Which view the app shows, kept in sync with the URL so that the browser's
 * back button works and a view can be bookmarked. Also the presets the start
 * page uses to open a filtered view.
 */
import type { ViewName } from './startParams.ts'
import type { ReleaseStatus } from './types.ts'

import { reactive, ref, watch } from 'vue'
import { dueTarget } from './dueTargets.ts'
import { applyModules, viewActive } from './modules.ts'
import { buildQuery, DEFAULT_VIEW, parseStartParams } from './startParams.ts'
import { filters, moduleStates, openEntry, requestCloseEntry, resetFilters, selectedId } from './store.ts'

export const currentView = ref<ViewName>(DEFAULT_VIEW)
export const currentEventId = ref<number | null>(null)

/** Filters of the task list. */
export const taskFilters = reactive({
	/** 'mine': tasks for my roles; 'all': all tasks */
	scope: 'mine' as 'mine' | 'all',
	/** '' = open and answered */
	status: '' as '' | 'open' | 'answered',
	/** also show closed tasks (applied, discarded) */
	closed: false,
})

/** Status shown first in a release list ('' = all). */
export const releaseFilter = ref<ReleaseStatus | ''>('')

/** Person to show in the register (opened from a contribution). */
export const selectedPersonId = ref<number | null>(null)

/**
 * Set when the URL (back button, link) led to a function that is switched
 * off; the app shows the start page and a notice, then resets it.
 */
export const switchedOffNotice = ref(false)

let listening = false

/**
 * Write the current location into the URL.
 *
 * @param replace - replace the history entry instead of adding one
 */
function writeLocation(replace: boolean): void {
	const query = buildQuery({ view: currentView.value, eventId: currentEventId.value, copyId: selectedId.value })
	const url = window.location.pathname + query + window.location.hash
	if (url === window.location.pathname + window.location.search + window.location.hash) {
		return
	}
	if (replace) {
		window.history.replaceState(null, '', url)
	} else {
		window.history.pushState(null, '', url)
	}
}

/**
 * Show a view.
 *
 * @param view - the view
 * @param options - event of the event view; replace: no new history entry
 * @param options.eventId - event id for the event view
 * @param options.replace - replace the history entry
 */
export function navigate(view: ViewName, options: { eventId?: number | null, replace?: boolean } = {}): void {
	currentView.value = view
	currentEventId.value = view === 'event' ? (options.eventId ?? null) : null
	writeLocation(options.replace === true)
}

/** Take over the URL after the back or forward button. */
function onPopState(): void {
	const { params, switchedOff } = applyModules(parseStartParams(window.location.search), moduleStates.value)
	currentView.value = params.view ?? DEFAULT_VIEW
	currentEventId.value = params.eventId
	if (switchedOff) {
		switchedOffNotice.value = true
		writeLocation(true)
	}
	if (params.copyId !== null && params.copyId !== selectedId.value) {
		openEntry(params.copyId)
	} else if (params.copyId === null && selectedId.value !== null) {
		requestCloseEntry()
	}
}

/** Start keeping view and URL in sync (once). */
export function startNavigation(): void {
	if (listening) {
		return
	}
	listening = true
	window.addEventListener('popstate', onPopState)
	// The open copy is part of the URL, without a history entry of its own
	watch(selectedId, () => writeLocation(true))
	// A module switched off in the settings: leave its view
	watch(moduleStates, (modules) => {
		if (!viewActive(currentView.value, modules)) {
			navigate(DEFAULT_VIEW, { replace: true })
		}
	})
}

/**
 * Open the catalog with filters, e.g. from a card of the start page.
 *
 * @param preset - filters to set after resetting all others
 * @param options - replace: no new history entry
 * @param options.replace - replace the history entry
 */
export function showCatalog(preset: Partial<typeof filters> = {}, options: { replace?: boolean } = {}): void {
	resetFilters()
	Object.assign(filters, preset)
	navigate('catalog', options)
}

/**
 * Open the view a card of the start page stands for, with its filters:
 * from the start page and from a link of the dashboard widget (`?due=`).
 *
 * @param key - card key from GET /api/due
 * @param options - replace: no new history entry
 * @param options.replace - replace the history entry
 */
export function openDueTarget(key: string, options: { replace?: boolean } = {}): void {
	const target = dueTarget(key)
	if (target.view === 'catalog') {
		showCatalog(target.catalog ?? {}, options)
		return
	}
	if (target.view === 'tasks') {
		taskFilters.scope = 'mine'
		taskFilters.status = target.taskStatus ?? ''
	}
	if (target.view === 'portal') {
		releaseFilter.value = target.releaseStatus ?? ''
	}
	navigate(target.view, options)
}
