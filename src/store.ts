/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared state of the app: meta data, catalog entries, filters, selected
 * entry and open dialogs. Plain Vue refs, no store library.
 */
import type { ViewName } from './startParams.ts'
import type { Detail, Entry, FieldInfo, FilterContext, Filters, Level, Meta, PersonalView, ReleaseRow, Role, SortOrder } from './types.ts'

import { loadState } from '@nextcloud/initial-state'
import { computed, reactive, ref, shallowRef } from 'vue'
import { errorMessage, fetchEntries, fetchEntry, fetchMeta, fetchReleaseList, normalizeMeta } from './api.ts'
import { DEFAULT_FILTERS, filterEntries, hasActiveFilters, searchText, sortEntries, worksWithValidVersion } from './catalog.ts'
import { focusAfterUpdate } from './focus.ts'
import { optionLabel, translateLabel } from './format.ts'
import { cardShown, fieldActive, moduleActive, viewActive, viewShown } from './modules.ts'

/** Role keys used by the UI (see docs/api.md). */
export const ROLE_LIBRARIAN = 'librarian'

// ---- meta -----------------------------------------------------------------

export const meta = shallowRef<Meta | null>(null)
export const metaError = ref('')

export const isAdmin = computed(() => meta.value?.admin === true)

/**
 * May open the settings under Administration → Cantoria: admins and
 * the groups the administration has given the page. The server checks again.
 */
export const canChangeSettings = computed(() => meta.value?.canChangeSettings === true)

/**
 * Whether the current user holds a role.
 *
 * @param role - role key
 */
export function hasRole(role: string): boolean {
	return meta.value?.roles.includes(role) ?? false
}

export const isLibrarian = computed(() => hasRole(ROLE_LIBRARIAN))
export const isOperations = computed(() => hasRole('operations'))
export const isConductor = computed(() => hasRole('conductor'))
export const isContributor = computed(() => hasRole('contributor'))
export const isReader = computed(() => hasRole('reader'))

/**
 * Whether the user holds one of the roles.
 *
 * @param roles - role keys
 */
export function hasAnyRole(roles: Role[]): boolean {
	return roles.some((role) => hasRole(role))
}

// ---- modules and the personal view ---------------------------------------------

/** Active modules; null from an older server (everything counts as active). */
export const moduleStates = computed(() => meta.value?.modules ?? null)

/**
 * Whether a module is active (switched on by the administration together
 * with everything it requires). The server checks again.
 *
 * @param key - module key (see docs/api.md)
 */
export function isModuleActive(key: string): boolean {
	return moduleActive(moduleStates.value, key)
}

/**
 * Whether only confirmed public domain opens a way out, never a license: the
 * server says so (RightsPolicy::FREE_ONLY_WAYS), the dialogs only repeat it.
 *
 * @param way - key of the way, e.g. `public_device`
 */
export function isFreeOnly(way: string): boolean {
	return meta.value?.freeOnlyWays.includes(way) ?? false
}

const NO_PERSONAL_VIEW: PersonalView = { navigation: [], cards: [] }

/** What the account has hidden in its own view. */
export const personalView = computed<PersonalView>(() => meta.value?.personalView ?? NO_PERSONAL_VIEW)

/**
 * Whether the module of a view is active (links may open it).
 *
 * @param view - the view
 */
export function isViewActive(view: ViewName): boolean {
	return viewActive(view, moduleStates.value)
}

/**
 * Whether a view has an entry in the navigation (module active, not hidden
 * in the personal view).
 *
 * @param view - the view
 */
export function isViewShown(view: ViewName): boolean {
	return viewShown(view, moduleStates.value, personalView.value)
}

/**
 * Whether the start page shows a card or list (module active, not hidden in
 * the personal view).
 *
 * @param key - card key, `concerts` or `tasks`
 */
export function isCardShown(key: string): boolean {
	return cardShown(key, moduleStates.value, personalView.value)
}

/**
 * Whether a catalog field belongs to an active module (or to none).
 *
 * @param key - field key
 */
export function isFieldActive(key: string): boolean {
	return fieldActive(key, moduleStates.value)
}

/** Roles that may create tasks and questions (module "tasks"). */
export const canCreateTasks = computed(() => isModuleActive('tasks') && hasAnyRole(['librarian', 'conductor', 'operations', 'contributor']))
/** Roles that may create internal excerpt links (module "excerpt_links"). */
export const canCreateLinks = computed(() => isModuleActive('excerpt_links') && hasAnyRole(['librarian', 'conductor', 'operations']))
/**
 * Whether links can also be made for choir members or the public (modules
 * "member_links", "public_links"); the link dialog then asks for the circle.
 */
export const canShareOutside = computed(() => canCreateLinks.value && (isModuleActive('member_links') || isModuleActive('public_links')))
/** Roles that plan concerts and song lists. */
export const canPlanEvents = computed(() => hasAnyRole(['conductor', 'librarian']))
/** Roles that see the proposals inbox (module "proposals"). */
export const canSeeProposals = computed(() => isModuleActive('proposals') && hasAnyRole(['librarian', 'contributor', 'reader']))
/** Roles that read the folder book (module "folder_book"); never contributors or choir members. */
export const canSeeFolderBook = computed(() => isModuleActive('folder_book') && hasAnyRole(['librarian', 'operations', 'conductor', 'reader']))
/** Who syncs the mirror folder of the print station (module "print_mirror"). */
export const canSyncPrintMirror = computed(() => isModuleActive('print_mirror') && (hasRole('librarian') || isAdmin.value))
/** Who compares an export of the choir system with the singers' portal (milestone 0.12). */
export const canCompareChoirSystem = computed(() => isModuleActive('portal') && isModuleActive('proposals') && hasRole('librarian'))
/** Who syncs the hand-over folder for a choir system (module "choir_handover"). */
export const canSyncHandover = computed(() => isModuleActive('choir_handover') && (hasRole('librarian') || isAdmin.value))

export const hasArchiveFolder = computed(() => (meta.value?.archive.id ?? 0) > 0)

/**
 * The archive folder is set, but not shared with this account: the app
 * reads files only with the account's own permissions, so its files do not
 * open (scores, scans, practice files).
 */
export const archiveNotShared = computed(() => hasArchiveFolder.value && meta.value?.archiveAccessible === false)

/**
 * Whether the current user may change a field: one of the field's write
 * roles must be among the user's roles. The server checks again.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function canWrite(level: Level, key: string): boolean {
	const info = fieldInfo(level, key)
	if (!info || info.write.length === 0) {
		return false
	}
	return info.write.some((role) => hasRole(role))
}

/**
 * Field directory entry, if the server knows the field.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function fieldInfo(level: Level, key: string): FieldInfo | undefined {
	return meta.value?.fields[level]?.[key]
}

/**
 * Translated label of a field; the key if the field is unknown.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function fieldLabel(level: Level, key: string): string {
	const info = fieldInfo(level, key)
	return info ? translateLabel(info.label) : key
}

/**
 * Translated label of a choice value, e.g. a copy status.
 *
 * @param level - work, version or copy
 * @param key - field key
 * @param value - option key
 */
export function choiceLabel(level: Level, key: string, value: string | null | undefined): string {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	return optionLabel(fieldInfo(level, key), value)
}

/** Take meta from the initial state of the page, or fetch it. */
export async function initMeta(): Promise<void> {
	let initial: Meta | null = null
	try {
		initial = loadState<Meta | null>('cantoria', 'meta', null)
	} catch {
		initial = null
	}
	if (initial) {
		meta.value = normalizeMeta(initial)
		return
	}
	await reloadMeta()
}

export async function reloadMeta(): Promise<void> {
	try {
		meta.value = await fetchMeta()
		metaError.value = ''
	} catch (error) {
		metaError.value = errorMessage(error)
	}
}

// ---- catalog entries ------------------------------------------------------

export const entries = shallowRef<Entry[]>([])
export const entriesStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
export const entriesError = ref('')

/**
 * (Re)load the catalog. While reloading after a change the old list stays
 * visible; a failed reload keeps it and only sets `entriesError`.
 *
 * @return whether loading succeeded
 */
export async function loadEntries(): Promise<boolean> {
	if (entriesStatus.value !== 'ready') {
		entriesStatus.value = 'loading'
	}
	try {
		entries.value = await fetchEntries()
		entriesStatus.value = 'ready'
		entriesError.value = ''
		return true
	} catch (error) {
		entriesError.value = errorMessage(error)
		if (entriesStatus.value !== 'ready') {
			entriesStatus.value = 'error'
		}
		return false
	}
}

// ---- release information ----------------------------------------------------

/**
 * All rows of the singers' portal list (also blocked versions), for the
 * column "in the portal / paper only" of the song lists. Loaded only by the
 * views that need it.
 */
export const portalRows = shallowRef<ReleaseRow[]>([])

let portalRowsLoaded = false

export async function loadPortalRows(): Promise<void> {
	portalRowsLoaded = true
	if (!isModuleActive('portal')) {
		// The list is switched off; the song lists leave out the column
		portalRows.value = []
		return
	}
	try {
		portalRows.value = await fetchReleaseList('portal', true)
	} catch {
		// Optional information: the list still works without it
		portalRows.value = []
	}
}

/** Reload the portal rows after a change, if a view has loaded them. */
export async function refreshPortalRows(): Promise<void> {
	if (portalRowsLoaded) {
		await loadPortalRows()
	}
}

/** Versions that are in the singers' portal now. */
export const versionsOnPortal = computed(() => new Set(portalRows.value
	.filter((row) => row.status === 'ok' || row.status === 'remove')
	.map((row) => row.versionId)))

export const filterContext = computed<FilterContext>(() => ({
	worksWithValidVersion: worksWithValidVersion(entries.value),
}))

// ---- filters and sorting --------------------------------------------------

export const filters = reactive<Filters>({ ...DEFAULT_FILTERS })
export const sortOrder = ref<SortOrder>('catalog')

export const filtersActive = computed(() => hasActiveFilters(filters))

export function resetFilters(): void {
	Object.assign(filters, DEFAULT_FILTERS)
}

const searchIndex = computed(() => new Map(entries.value.map((entry) => [entry.id, searchText(entry)])))

export const visibleEntries = computed(() => sortEntries(
	filterEntries(entries.value, filters, searchIndex.value, filterContext.value),
	sortOrder.value,
))

/** Entries by id, e.g. to show shelf marks in lists of tasks or links. */
export const entriesById = computed(() => new Map(entries.value.map((entry) => [entry.id, entry])))

/** Archive binders present in the data, ascending. */
export const binderOptions = computed(() => {
	const binders = new Set<number>()
	for (const entry of entries.value) {
		if (entry.binder !== null && entry.binder !== undefined) {
			binders.add(entry.binder)
		}
	}
	return [...binders].sort((a, b) => a - b)
})

// ---- selected entry -------------------------------------------------------

export const selectedId = ref<number | null>(null)
/** Tab of the sidebar: overview, rights, tasks, files, audit */
export const sidebarTab = ref('overview')
/** Task to point out in the sidebar (opened via ?task=) */
export const highlightedTaskId = ref<number | null>(null)
export const detail = shallowRef<Detail | null>(null)
export const detailLoading = ref(false)
export const detailError = ref('')

let detailRequest = 0

/** Id of the sidebar of a copy (NcAppSidebar). */
export const SIDEBAR_ID = 'app-sidebar-vue'

/**
 * The control that had the focus when the sidebar opened (the row of the
 * list, a link in a view), to get the focus back when it closes (WCAG
 * 2.4.3). Null when the sidebar opened without one, e.g. from the address.
 */
let returnFocus: HTMLElement | null = null

/** Set when the sidebar opened from a control: the sidebar takes the focus once it shows the copy. */
export const sidebarTakesFocus = ref(false)

/**
 * Open an entry in the sidebar.
 *
 * @param id - copy id
 * @param tab - sidebar tab to show (default: overview for another copy)
 */
export async function openEntry(id: number, tab?: string): Promise<void> {
	if (tab !== undefined) {
		sidebarTab.value = tab
	} else if (selectedId.value !== id) {
		// another copy starts with the overview
		sidebarTab.value = 'overview'
	}
	if (selectedId.value !== id) {
		highlightedTaskId.value = null
	}
	if (selectedId.value === null) {
		const active = document.activeElement
		returnFocus = active instanceof HTMLElement && active !== document.body && !active.closest(`#${SIDEBAR_ID}`) ? active : null
		sidebarTakesFocus.value = returnFocus !== null
	}
	selectedId.value = id
	if (detail.value?.copy.id !== id) {
		detail.value = null
	}
	await loadDetail(true)
}

/**
 * (Re)load the selected entry.
 *
 * @param showSpinner - false keeps the current content visible while loading
 */
export async function loadDetail(showSpinner = false): Promise<void> {
	const id = selectedId.value
	if (id === null) {
		return
	}
	const request = ++detailRequest
	detailLoading.value = showSpinner
	detailError.value = ''
	try {
		const result = await fetchEntry(id)
		if (request === detailRequest) {
			detail.value = result
		}
	} catch (error) {
		if (request === detailRequest) {
			detailError.value = errorMessage(error)
		}
	} finally {
		if (request === detailRequest) {
			detailLoading.value = false
		}
	}
}

/**
 * Close the sidebar. The focus goes back to the control it was opened
 * from, if that is still in the page.
 */
export function closeEntry(): void {
	detailRequest++
	const target = returnFocus
	returnFocus = null
	sidebarTakesFocus.value = false
	if (target !== null) {
		focusAfterUpdate(() => target)
	}
	selectedId.value = null
	highlightedTaskId.value = null
	scoreRequest.value = null
	detail.value = null
	detailError.value = ''
	detailLoading.value = false
}

// ---- score view requests -----------------------------------------------------

/** What the score view should show when it opens (from a link or a task). */
export interface ScoreRequest {
	copyId: number
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	note?: string | null
	/** message to show above the score, e.g. "the score has changed" */
	notice?: string | null
	noticeType?: 'info' | 'warning'
	/** show the review of the transcription (module "transcription_review") */
	review?: boolean
}

/** Set to open the score view of a copy; CopySidebar takes it over. */
export const scoreRequest = shallowRef<ScoreRequest | null>(null)

// ---- dialogs --------------------------------------------------------------

export const archiveDialogOpen = ref(false)
export const personalViewDialogOpen = ref(false)
