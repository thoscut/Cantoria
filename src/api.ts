/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * All calls to the server API (see docs/api.md) in one place.
 */
import type {
	AccessReport,
	AccessionInput,
	AccessionProposal,
	AccessionResult,
	AnnualReport,
	CalendarAddResult,
	CalendarUpdate,
	ChecksumStatus,
	ChoirEvent,
	ChoirSystemReport,
	Contribution,
	CorrectionInput,
	CorrectionOrder,
	CorrectionResult,
	CountInput,
	Detail,
	DueResult,
	EndingLicense,
	Entry,
	EventDetail,
	EventInput,
	EventItem,
	EventKind,
	ExcerptLink,
	FieldMap,
	FieldValue,
	FileSyncReport,
	FileSyncStatus,
	GroupOption,
	ImportReport,
	Inventory,
	Level,
	License,
	LicenseInput,
	LinkInput,
	LinkOptions,
	Merge,
	MergeCandidateGroup,
	Meta,
	ModuleSettings,
	ModuleSettingsResult,
	PerformanceImportReport,
	Person,
	PersonDetail,
	PersonImportReport,
	PersonalView,
	PracticeAudioInput,
	ProgrammeShareOptions,
	ProgrammeShareResult,
	Proposal,
	ProtectionReviewRow,
	ReleaseRow,
	RepertoireThresholds,
	ReportIngestResult,
	ReportPerson,
	ResolvedLink,
	ReviewCounts,
	ReviewDecisionInput,
	ReviewPoint,
	Settings,
	SettingsUpdate,
	ShelfLabel,
	StateList,
	Statistics,
	Task,
	TaskInput,
	TranscriptionReport,
	UserCalendar,
	VersionReview,
	VersionState,
} from './types.ts'

import axios, { isAxiosError } from '@nextcloud/axios'
import { t } from './l10n.ts'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { davUrl } from './calendar.ts'
import { normalizeAccessReport, normalizeGroups, normalizeSettings } from './settings.ts'
import { statusMessage } from './serverErrors.ts'
import { LEVELS } from './types.ts'

const APP = 'cantoria'

/**
 * URL of an API endpoint.
 *
 * @param path - path below /api, e.g. `/entries/{id}`
 * @param params - placeholder values for the path
 */
export function apiUrl(path: string, params?: Record<string, string | number>): string {
	return generateUrl(`/apps/${APP}/api${path}`, params)
}

/**
 * A user-facing message for a failed request: the server's `{ error }` text
 * if there is one, otherwise a generic hint with the HTTP status.
 *
 * @param error - whatever was thrown
 */
export function errorMessage(error: unknown): string {
	if (isAxiosError(error)) {
		const data = error.response?.data as { error?: unknown, message?: unknown } | undefined
		if (data && typeof data.error === 'string' && data.error !== '') {
			return data.error
		}
		if (data && typeof data.message === 'string' && data.message !== '') {
			// Nextcloud's own checks before the app (e.g. "must be an admin")
			return data.message
		}
		if (error.response?.status === 429) {
			// rate limit of Nextcloud: no `{ error }` in the response
			return t('cantoria', 'Too many requests. Please wait a moment and try again.')
		}
		if (error.response) {
			return statusMessage(error.response.status, String(error.response.headers?.['x-request-id'] ?? ''))
		}
		return t('cantoria', 'The server cannot be reached. Please check your connection.')
	}
	if (error instanceof Error && error.message) {
		return error.message
	}
	return t('cantoria', 'An unexpected error occurred.')
}

/** `reason` of an error response: the archive folder is not shared with the account. */
export const REASON_NOT_SHARED = 'not_shared'

/**
 * A failed request: the message for the person, for some errors a machine
 * readable `reason` (e.g. `not_shared`), and the HTTP status (0: no response).
 */
export class ApiError extends Error {

	readonly reason: string | null
	readonly status: number

	/**
	 * @param message - message for the user
	 * @param reason - `reason` of the response, if any
	 * @param status - HTTP status, 0 without a response
	 */
	constructor(message: string, reason: string | null = null, status = 0) {
		super(message)
		this.name = 'ApiError'
		this.reason = reason
		this.status = status
	}

}

/**
 * The `reason` of a failed request, if the server sent one.
 *
 * @param error - whatever was thrown
 */
export function errorReason(error: unknown): string | null {
	if (error instanceof ApiError) {
		return error.reason
	}
	if (isAxiosError(error)) {
		const data = error.response?.data as { reason?: unknown } | undefined
		return data && typeof data.reason === 'string' ? data.reason : null
	}
	return null
}

/**
 * Page of the settings under Administration → Cantoria.
 *
 * @param section - id of a section to jump to (SETTINGS_SECTIONS of settings.ts)
 */
export function adminSettingsUrl(section?: string): string {
	return generateUrl('/settings/admin/{section}', { section: APP }) + (section ? '#' + section : '')
}

/**
 * Whether trying the same request again cannot help: no access (403) or
 * the file is gone (404). Network errors and server errors may pass.
 *
 * @param error - whatever was thrown
 */
export function isPermanentHttpError(error: unknown): boolean {
	const status = error instanceof ApiError
		? error.status
		: (isAxiosError(error) ? (error.response?.status ?? 0) : 0)
	return status === 403 || status === 404
}

/**
 * PHP serializes empty associative arrays as `[]`. Bring the field
 * directory into a predictable shape.
 *
 * @param meta - meta as delivered by the server
 */
export function normalizeMeta(meta: Meta): Meta {
	const fields = {} as Record<Level, FieldMap>
	for (const level of LEVELS) {
		fields[level] = normalizeFieldMap(meta.fields?.[level])
	}
	return {
		uid: meta.uid ?? '',
		admin: meta.admin === true,
		// An older server sends no flag: the settings were for admins only
		canChangeSettings: typeof meta.canChangeSettings === 'boolean' ? meta.canChangeSettings : meta.admin === true,
		roles: Array.isArray(meta.roles) ? meta.roles : [],
		roleLabels: meta.roleLabels && !Array.isArray(meta.roleLabels) ? meta.roleLabels : {},
		fields,
		licenseUses: meta.licenseUses && !Array.isArray(meta.licenseUses) ? meta.licenseUses : {},
		personFields: normalizeFieldMap(meta.personFields),
		contributionRoles: meta.contributionRoles && !Array.isArray(meta.contributionRoles) ? meta.contributionRoles : {},
		archive: meta.archive ?? { id: 0, path: '', user: '' },
		archiveAccessible: typeof meta.archiveAccessible === 'boolean' ? meta.archiveAccessible : null,
		modules: normalizeModules(meta.modules),
		personalView: normalizePersonalView(meta.personalView),
		siteName: typeof meta.siteName === 'string' ? meta.siteName : '',
	}
}

/**
 * Active modules: only boolean values; null when the server sends none (an
 * older server, every module counts as active).
 *
 * @param raw - `meta.modules` as delivered by the server
 */
function normalizeModules(raw: unknown): Record<string, boolean> | null {
	if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
		return null
	}
	return Object.fromEntries(Object.entries(raw as Record<string, unknown>).filter(([, value]) => typeof value === 'boolean')) as Record<string, boolean>
}

/**
 * The personal view with two lists of names; empty lists when it is missing.
 *
 * @param raw - `meta.personalView` or the answer of `PUT /api/personal-view`
 */
export function normalizePersonalView(raw: unknown): PersonalView {
	const value = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw as Record<string, unknown> : {}
	const names = (entry: unknown): string[] => (Array.isArray(entry) ? entry.filter((name): name is string => typeof name === 'string') : [])
	return { navigation: names(value.navigation), cards: names(value.cards) }
}

/**
 * One field directory in a predictable shape (options as map, write list).
 *
 * @param raw - field directory as delivered by the server
 */
function normalizeFieldMap(raw: FieldMap | undefined): FieldMap {
	const map: FieldMap = {}
	if (raw && !Array.isArray(raw)) {
		for (const [key, info] of Object.entries(raw)) {
			let options = info.options
			if (Array.isArray(options)) {
				options = Object.fromEntries((options as string[]).map((value) => [value, value]))
			}
			map[key] = {
				...info,
				write: Array.isArray(info.write) ? info.write : [],
				options,
			}
		}
	}
	return map
}

/**
 * An array from the server, or [] if the value is missing or no array.
 *
 * @param value - value from the server
 */
function list<T>(value: unknown): T[] {
	return Array.isArray(value) ? value as T[] : []
}

export async function fetchMeta(): Promise<Meta> {
	const { data } = await axios.get<Meta>(apiUrl('/meta'))
	return normalizeMeta(data)
}

export async function fetchEntries(): Promise<Entry[]> {
	const { data } = await axios.get<Entry[]>(apiUrl('/entries'))
	return Array.isArray(data) ? data : []
}

export async function fetchEntry(id: number): Promise<Detail> {
	const { data } = await axios.get<Detail>(apiUrl('/entries/{id}', { id }))
	const releases = data.releases && typeof data.releases === 'object' && !Array.isArray(data.releases)
		? data.releases
		: { portal: null, onPortal: false, printStation: null }
	return {
		...data,
		files: list(data.files),
		siblings: list(data.siblings),
		audit: list(data.audit),
		score: data.score && typeof data.score === 'object' && typeof data.score.fileId === 'number' ? data.score : null,
		licenses: list(data.licenses),
		licenseWarnings: list(data.licenseWarnings),
		releases,
		proposals: list(data.proposals),
		contributions: list(data.contributions),
		protection: data.protection && typeof data.protection === 'object' && !Array.isArray(data.protection) ? data.protection : null,
		merges: list(data.merges),
		tasks: list(data.tasks),
		links: list(data.links),
	}
}

/**
 * Raw content of a linked file (e.g. the MusicXML of the score view). The
 * server checks the rights policy and the user's access to the file. An
 * error is an ApiError; its `reason` is `not_shared` when the archive folder
 * is not shared with the account.
 *
 * @param id - id from `files[]` (not the Nextcloud file id)
 */
export async function fetchFileContent(id: number): Promise<ArrayBuffer> {
	try {
		const { data } = await axios.get<ArrayBuffer>(apiUrl('/files/{id}/content', { id }), { responseType: 'arraybuffer' })
		return data
	} catch (error) {
		// With responseType arraybuffer the `{ error }` body arrives as bytes
		const status = isAxiosError(error) ? (error.response?.status ?? 0) : 0
		const body = isAxiosError(error) ? error.response?.data : undefined
		if (body instanceof ArrayBuffer) {
			try {
				const parsed = JSON.parse(new TextDecoder().decode(body)) as { error?: unknown, reason?: unknown }
				if (typeof parsed.error === 'string' && parsed.error !== '') {
					throw new ApiError(parsed.error, typeof parsed.reason === 'string' ? parsed.reason : null, status)
				}
			} catch (decoded) {
				if (decoded instanceof Error && !(decoded instanceof SyntaxError)) {
					throw decoded
				}
			}
		}
		throw new ApiError(errorMessage(error), null, status)
	}
}

/**
 * Change fields of a work, version or copy. Only send changed fields.
 *
 * @param level - work, version or copy
 * @param id - id of the object on that level
 * @param fields - field key => new value
 * @param reason - optional reason for the audit log
 */
export async function updateFields(level: Level, id: number, fields: Record<string, FieldValue>, reason: string): Promise<void> {
	await axios.patch(apiUrl('/{level}/{id}', { level, id }), {
		fields,
		reason: reason.trim() === '' ? null : reason.trim(),
	})
}

export async function runImport(fileId: number, dryRun: boolean): Promise<ImportReport> {
	const { data } = await axios.post<ImportReport>(apiUrl('/import'), { fileId, dryRun })
	return data
}

export async function runFileSync(): Promise<FileSyncReport> {
	const { data } = await axios.post<FileSyncReport>(apiUrl('/file-sync'))
	return data
}

/**
 * Whether a file sync is running, and the counts of the last one.
 */
export async function fetchFileSyncStatus(): Promise<FileSyncStatus> {
	const { data } = await axios.get<FileSyncStatus>(apiUrl('/file-sync'))
	return data
}

/**
 * Download URL of the CSV export.
 *
 * @param extended - false: legacy catalog columns, true: all fields
 */
export function exportUrl(extended: boolean): string {
	return apiUrl('/export') + '?extended=' + (extended ? '1' : '0')
}

export async function storeExport(): Promise<string> {
	const { data } = await axios.post<{ path: string }>(apiUrl('/export/store'))
	return data.path
}

/** Settings of the app (admins and the groups given the settings page). */
export async function fetchSettings(): Promise<Settings> {
	const { data } = await axios.get<unknown>(apiUrl('/settings'))
	return normalizeSettings(data)
}

/**
 * Change the role groups and/or the archive folder.
 *
 * @param update - the parts to change
 */
export async function saveSettings(update: SettingsUpdate): Promise<Settings> {
	const { data } = await axios.put<unknown>(apiUrl('/settings'), update)
	return normalizeSettings(data)
}

/**
 * Groups to choose for a role, at most 25.
 *
 * @param search - part of the id or the display name; '' for the first ones
 */
export async function searchGroups(search: string): Promise<GroupOption[]> {
	const { data } = await axios.get<unknown>(apiUrl('/settings/groups'), { params: { search } })
	return normalizeGroups(data)
}

/**
 * Who of the role groups can open the archive folder (only reads).
 *
 * @param folderId - a folder just chosen as the new archive folder, checked before it is saved
 */
export async function fetchArchiveAccess(folderId?: number): Promise<AccessReport> {
	const { data } = await axios.get<unknown>(apiUrl('/settings/access'), { params: folderId === undefined ? {} : { folderId } })
	return normalizeAccessReport(data)
}

// ---- milestone 0.5.1: modules and the personal view -------------------------

/**
 * Lists of the module settings in a predictable shape.
 *
 * @param data - answer of the server
 */
function normalizeModuleSettings(data: ModuleSettings): ModuleSettings {
	return {
		modules: list<ModuleSettings['modules'][number]>(data.modules).map((module) => ({ ...module, requires: list<string>(module.requires) })),
		groups: list(data.groups),
		presets: list<ModuleSettings['presets'][number]>(data.presets)
			.map((preset) => ({ ...preset, modules: preset.modules && !Array.isArray(preset.modules) ? preset.modules : {} })),
		scenarios: list<ModuleSettings['scenarios'][number]>(data.scenarios)
			.map((scenario) => ({ ...scenario, modules: scenario.modules && !Array.isArray(scenario.modules) ? scenario.modules : {} })),
		core: list(data.core),
		publicModules: list<string>(data.publicModules),
		legalPages: data.legalPages,
	}
}

/** Modules, groups, presets and the core (admins only). */
export async function fetchModuleSettings(): Promise<ModuleSettings> {
	const { data } = await axios.get<ModuleSettings>(apiUrl('/settings/modules'))
	return normalizeModuleSettings(data)
}

/**
 * Switch modules on or off, or apply a preset or a scenario (admins only).
 * Switching on also switches on what a module requires.
 *
 * @param change - `{ modules: { key: on } }`, `{ preset: name }` or `{ scenario: name }`
 */
export async function saveModules(change: { modules: Record<string, boolean> } | { preset: string } | { scenario: string }): Promise<ModuleSettingsResult> {
	const { data } = await axios.put<ModuleSettingsResult>(apiUrl('/settings/modules'), change)
	const changes = data.changes ?? {}
	return {
		...normalizeModuleSettings(data),
		changes: {
			enabled: list(changes.enabled),
			alsoEnabled: list(changes.alsoEnabled),
			disabled: list(changes.disabled),
			inactive: list(changes.inactive),
			activated: list(changes.activated),
		},
	}
}

/**
 * Save what the account hides in its own view. Changes no permission.
 *
 * @param view - hidden navigation areas and start page cards
 */
export async function savePersonalView(view: PersonalView): Promise<PersonalView> {
	const { data } = await axios.put<PersonalView>(apiUrl('/personal-view'), view)
	return normalizePersonalView(data)
}

// ---- milestone 0.2: rights, releases, valid version, proposals -------------

export async function confirmRights(versionId: number, status: string, reason: string, evidence: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/confirm', { id: versionId }), { status, reason, evidence })
}

export async function setRightsOpen(versionId: number, reason: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/set-open', { id: versionId }), { reason })
}

/**
 * Record that the version is in the singers' portal.
 *
 * @param versionId - version id
 * @param since - date (YYYY-MM-DD)
 */
export async function addToPortal(versionId: number, since: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/portal', { id: versionId }), { since })
}

export async function removeFromPortal(versionId: number, reason: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/portal-remove', { id: versionId }), { reason })
}

export async function setValidVersion(versionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/valid', { id: versionId }), { reason })
}

export async function clearValidVersion(versionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/valid-clear', { id: versionId }), { reason })
}

export async function createLicense(versionId: number, license: LicenseInput): Promise<License> {
	const { data } = await axios.post<License>(apiUrl('/versions/{id}/licenses', { id: versionId }), { license })
	return data
}

export async function updateLicense(licenseId: number, license: Partial<LicenseInput>): Promise<License> {
	const { data } = await axios.patch<License>(apiUrl('/licenses/{id}', { id: licenseId }), { license })
	return data
}

export async function fetchProposals(): Promise<Proposal[]> {
	const { data } = await axios.get<Proposal[]>(apiUrl('/proposals'))
	return list(data)
}

export async function acceptProposal(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/proposals/{id}/accept', { id }), { reason })
}

export async function rejectProposal(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/proposals/{id}/reject', { id }), { reason })
}

/**
 * Target/actual list of the singers' portal or the print station.
 *
 * @param target - which list
 * @param all - also blocked versions and those where another version is valid
 */
export async function fetchReleaseList(target: 'portal' | 'print-station', all: boolean): Promise<ReleaseRow[]> {
	const { data } = await axios.get<ReleaseRow[]>(apiUrl('/lists/{target}', { target }) + '?all=' + (all ? '1' : '0'))
	return list(data)
}

/** Store a complete backup (CSV, ODS and JSON) in the backup folder, outside the archive folder. */
export async function storeBackup(): Promise<{ paths: string[], account: string }> {
	const { data } = await axios.post<{ paths?: string[], account?: string }>(apiUrl('/backup'))
	return { paths: list(data.paths), account: data.account ?? '' }
}

// ---- milestone 0.3: tasks, links, start page --------------------------------

export async function fetchDue(): Promise<DueResult> {
	const { data } = await axios.get<DueResult>(apiUrl('/due'))
	return dueResult(data)
}

/**
 * The answer of GET /api/due with lists that are always arrays; keeps every
 * part (cards, tasks and the concerts with a deadline now).
 *
 * @param data - the answer as sent by the server
 */
export function dueResult(data: Partial<DueResult> | null | undefined): DueResult {
	return { cards: list(data?.cards), tasks: list(data?.tasks), concerts: list(data?.concerts) }
}

/**
 * Tasks of all copies.
 *
 * @param closed - also closed tasks (applied, discarded)
 */
export async function fetchTasks(closed: boolean): Promise<Task[]> {
	const { data } = await axios.get<Task[]>(apiUrl('/tasks') + '?closed=' + (closed ? '1' : '0'))
	return list(data)
}

export async function createTask(copyId: number, task: TaskInput): Promise<Task> {
	const { data } = await axios.post<Task>(apiUrl('/copies/{id}/tasks', { id: copyId }), { task })
	return data
}

export async function answerTask(id: number, answer: string): Promise<void> {
	await axios.post(apiUrl('/tasks/{id}/answer', { id }), { answer })
}

export async function closeTask(id: number, status: 'applied' | 'discarded', note: string | null): Promise<void> {
	await axios.post(apiUrl('/tasks/{id}/close', { id }), { status, note })
}

export async function createLink(copyId: number, link: LinkInput): Promise<ExcerptLink> {
	const { data } = await axios.post<ExcerptLink>(apiUrl('/copies/{id}/links', { id: copyId }), { link })
	return data
}

export async function fetchLinks(): Promise<ExcerptLink[]> {
	const { data } = await axios.get<ExcerptLink[]>(apiUrl('/links'))
	return list(data)
}

export async function resolveLink(token: string): Promise<ResolvedLink> {
	const { data } = await axios.get<ResolvedLink>(apiUrl('/links/{token}', { token }))
	return data
}

export async function revokeLink(id: number): Promise<void> {
	await axios.post(apiUrl('/links/{id}/revoke', { id }))
}

/**
 * Extend a link to a new last day; its token stays (milestone 0.19).
 *
 * @param id - link id
 * @param expires - new last day (YYYY-MM-DD); empty: the latest
 */
export async function extendLink(id: number, expires: string): Promise<ExcerptLink> {
	const { data } = await axios.post<ExcerptLink>(apiUrl('/links/{id}/extend', { id }), { expires })
	return data
}

/**
 * What sharing a programme with the choir members would do, per piece.
 *
 * @param eventId - concert or song list
 */
export async function fetchProgrammeShareOptions(eventId: number): Promise<ProgrammeShareOptions> {
	const { data } = await axios.get<ProgrammeShareOptions>(apiUrl('/events/{id}/share-options', { id: eventId }))
	return { ...data, items: list(data?.items) }
}

/**
 * Share the chosen pieces of a programme with the choir members.
 *
 * @param eventId - concert or song list
 * @param input - what to share
 * @param input.items - ids of the programme items
 * @param input.expires - last day (YYYY-MM-DD)
 * @param input.note - note for the choir members
 * @param input.scan - offer the scans where allowed
 */
export async function shareProgramme(eventId: number, input: { items: number[], expires: string, note: string, scan: boolean }): Promise<ProgrammeShareResult> {
	const { data } = await axios.post<ProgrammeShareResult>(apiUrl('/events/{id}/share', { id: eventId }), input)
	return data
}

/**
 * Which circles a link for this copy can be made for, and what the
 * recipients may do (milestone 0.7).
 *
 * @param copyId - copy id
 */
export async function fetchLinkOptions(copyId: number): Promise<LinkOptions> {
	const { data } = await axios.get<LinkOptions>(apiUrl('/copies/{id}/link-options', { id: copyId }))
	return data
}

/**
 * Absolute URL of an excerpt link: links for the archive roles and for
 * choir members open in the app, public links on their own page.
 *
 * @param token - link token
 * @param circle - who can open the link
 */
export function linkUrl(token: string, circle: string = 'internal'): string {
	if (circle === 'public') {
		return window.location.origin + generateUrl('/apps/{app}/p/{token}', { app: APP, token })
	}
	return window.location.origin + generateUrl('/apps/{app}/', { app: APP }) + '?link=' + encodeURIComponent(token)
}

/**
 * Ask the server before making practice audio files: it checks the rights
 * and records the export. Throws with the server's reason when refused.
 *
 * @param versionId - id of the version
 * @param request - measures, voice, mode and format
 */
export async function authorizePracticeAudio(versionId: number, request: PracticeAudioInput): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/practice-audio', { id: versionId }), { export: request })
}

// ---- milestone 0.4: concerts, song lists, repertoire ------------------------

export async function fetchEvents(kind: EventKind): Promise<ChoirEvent[]> {
	const { data } = await axios.get<ChoirEvent[]>(apiUrl('/events') + '?kind=' + kind)
	return list(data)
}

export async function fetchEvent(id: number): Promise<EventDetail> {
	const { data } = await axios.get<EventDetail>(apiUrl('/events/{id}', { id }))
	return { ...data, items: list(data.items) }
}

export async function createEvent(kind: EventKind, event: EventInput): Promise<ChoirEvent> {
	const { data } = await axios.post<ChoirEvent>(apiUrl('/events'), { kind, event })
	return data
}

export async function updateEvent(id: number, event: EventInput): Promise<ChoirEvent> {
	const { data } = await axios.patch<ChoirEvent>(apiUrl('/events/{id}', { id }), { event })
	return data
}

export async function addEventItem(eventId: number, copyId: number): Promise<EventItem> {
	const { data } = await axios.post<EventItem>(apiUrl('/events/{id}/items', { id: eventId }), { copyId })
	return data
}

export async function updateEventItem(id: number, item: Partial<Pick<EventItem, 'duration' | 'note' | 'encore' | 'struck'>>): Promise<EventItem> {
	const { data } = await axios.patch<EventItem>(apiUrl('/event-items/{id}', { id }), { item })
	return data
}

export async function removeEventItem(id: number): Promise<void> {
	await axios.delete(apiUrl('/event-items/{id}', { id }))
}

export async function reorderEventItems(eventId: number, order: number[]): Promise<void> {
	await axios.post(apiUrl('/events/{id}/order', { id: eventId }), { order })
}

export async function fixEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/fix', { id }))
}

/**
 * Confirm a concert as sung.
 *
 * @param id - event id
 * @param struck - ids of the items that were not sung
 */
export async function confirmSung(id: number, struck: number[]): Promise<void> {
	await axios.post(apiUrl('/events/{id}/sung', { id }), { struck })
}

export async function fetchAmbiguousItems(): Promise<EventItem[]> {
	const { data } = await axios.get<EventItem[]>(apiUrl('/event-items/ambiguous'))
	return list(data)
}

/**
 * Resolve an unclear programme line.
 *
 * @param id - event item id
 * @param copyId - the chosen copy, or null: not in the archive
 */
export async function resolveEventItem(id: number, copyId: number | null): Promise<void> {
	await axios.post(apiUrl('/event-items/{id}/resolve', { id }), { copyId })
}

/**
 * Download URL of the song order (CSV) of an event.
 *
 * @param id - event id
 */
export function songOrderUrl(id: number): string {
	return apiUrl('/events/{id}/song-order', { id })
}

/**
 * Cancel a planned concert.
 *
 * @param id - event id
 */
export async function cancelEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/cancel', { id }))
}

/**
 * Take back the cancellation of a concert (it becomes a draft again).
 *
 * @param id - event id
 */
export async function reopenEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/reopen', { id }))
}

// ---- concerts in the calendar ------------------------------------------------

/**
 * Download URL of the calendar file (iCalendar) of a concert, or of all
 * upcoming concerts.
 *
 * @param id - event id, or null for all upcoming concerts
 * @param deadlines - add the preparation deadlines as all-day entries
 */
export function calendarFileUrl(id: number | null, deadlines: boolean): string {
	const url = id === null ? apiUrl('/events/upcoming/ics') : apiUrl('/events/{id}/ics', { id })
	return deadlines ? url + '?deadlines=1' : url
}

/** The account's calendars that can take new entries. */
export async function fetchCalendars(): Promise<UserCalendar[]> {
	const { data } = await axios.get<UserCalendar[]>(apiUrl('/calendars'))
	return list(data)
}

/**
 * Add a concert, or all upcoming concerts, to one of the account's
 * calendars. Entries that are already in a calendar come back as updates
 * for writeCalendarObject().
 *
 * @param id - event id, or null for all upcoming concerts
 * @param calendar - URI of the calendar
 * @param deadlines - add the preparation deadlines as all-day entries
 */
export async function addToCalendar(id: number | null, calendar: string, deadlines: boolean): Promise<CalendarAddResult> {
	const url = id === null ? apiUrl('/events/upcoming/calendar') : apiUrl('/events/{id}/calendar', { id })
	const { data } = await axios.post<CalendarAddResult>(url, { calendar, deadlines })
	return { ...data, updates: list<CalendarUpdate>(data.updates) }
}

/**
 * Update an entry in the account's own calendar (CalDAV PUT, with the
 * session of the logged-in account). Nextcloud's calendar interface for
 * apps can only create entries, so the server leaves updates to this call.
 *
 * @param path - CalDAV path below remote.php/dav, as sent by the server
 * @param ics - new content
 */
export async function writeCalendarObject(path: string, ics: string): Promise<void> {
	await axios.put(davUrl(generateRemoteUrl('dav'), path), ics, {
		headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
	})
}

/**
 * Change the thresholds of the repertoire classes (a setting; read them
 * with `fetchSettings`).
 *
 * @param thresholds - years for "current" and "dormant"
 */
export async function saveRepertoireThresholds(thresholds: RepertoireThresholds): Promise<RepertoireThresholds> {
	const { data } = await axios.put<RepertoireThresholds>(apiUrl('/repertoire/thresholds'), thresholds)
	return data
}

/**
 * Import performances and song lists (.xlsx or .csv). Always run a dry run first.
 *
 * @param fileId - Nextcloud file id
 * @param dryRun - only check and report
 * @param ownChoir - name of the own choir in the file, if the detection is wrong
 */
export async function importPerformances(fileId: number, dryRun: boolean, ownChoir: string | null): Promise<PerformanceImportReport> {
	const { data } = await axios.post<PerformanceImportReport>(apiUrl('/performances/import'), { fileId, dryRun, ownChoir })
	return { ...data, unknownShelfmarks: list(data.unknownShelfmarks), sheets: list(data.sheets) }
}

/**
 * Compare an export of the choir system (.csv or .xlsx) with the list of
 * the singers' portal; without `dryRun` the differences become proposals.
 *
 * @param fileId - Nextcloud file id
 * @param dryRun - only compare
 */
export async function compareChoirSystem(fileId: number, dryRun: boolean): Promise<ChoirSystemReport> {
	const { data } = await axios.post<ChoirSystemReport>(apiUrl('/lists/portal/compare'), { fileId, dryRun })
	return {
		...data,
		added: list(data.added),
		removed: list(data.removed),
		removeThere: list(data.removeThere),
		unknown: list(data.unknown),
	}
}

// ---- milestone 0.5: maintaining the catalog ---------------------------------

/**
 * Proposal for the next shelf mark.
 *
 * @param binder - wanted binder, or null for the default
 */
export async function fetchNextShelfmark(binder: number | null): Promise<AccessionProposal> {
	const { data } = await axios.get<AccessionProposal>(apiUrl('/accession/next') + (binder === null ? '' : '?binder=' + binder))
	return { ...data, binders: list(data.binders), openBinders: list(data.openBinders) }
}

export async function saveOpenBinders(binders: number[]): Promise<number[]> {
	const { data } = await axios.put<{ openBinders: number[] }>(apiUrl('/accession/open-binders'), { binders })
	return list(data.openBinders)
}

/**
 * Create work, version and copy of a new accession.
 *
 * @param copy - fields of the accession
 * @param fileId - Nextcloud id of a file in the archive folder to link, or null
 */
export async function createAccession(copy: AccessionInput, fileId: number | null): Promise<AccessionResult> {
	const { data } = await axios.post<AccessionResult>(apiUrl('/accession'), { copy, fileId })
	return data
}

export async function fetchLabels(copyIds: number[]): Promise<ShelfLabel[]> {
	const { data } = await axios.get<ShelfLabel[]>(apiUrl('/labels') + '?ids=' + copyIds.join(','))
	return list(data)
}

/**
 * Link a file of the archive folder to a copy by hand.
 *
 * @param copyId - copy id
 * @param fileId - Nextcloud file id
 */
export async function linkFile(copyId: number, fileId: number): Promise<void> {
	await axios.post(apiUrl('/copies/{id}/files', { id: copyId }), { fileId })
}

/**
 * Remove a manual file link; afterwards the file name decides again.
 *
 * @param fileRowId - id from files[] (not the Nextcloud file id)
 */
export async function unlinkFile(fileRowId: number): Promise<void> {
	await axios.delete(apiUrl('/files/{id}/link', { id: fileRowId }))
}

export async function fetchPersons(query: string): Promise<Person[]> {
	const { data } = await axios.get<Person[]>(apiUrl('/persons') + (query.trim() === '' ? '' : '?q=' + encodeURIComponent(query.trim())))
	return list(data)
}

export async function fetchPerson(id: number): Promise<PersonDetail> {
	const { data } = await axios.get<PersonDetail>(apiUrl('/persons/{id}', { id }))
	return { ...data, contributions: list(data.contributions) }
}

export async function createPerson(person: Record<string, FieldValue>): Promise<Person> {
	const { data } = await axios.post<Person>(apiUrl('/persons'), { person })
	return data
}

export async function updatePerson(id: number, person: Record<string, FieldValue>, reason: string | null): Promise<Person> {
	const { data } = await axios.patch<Person>(apiUrl('/persons/{id}', { id }), { person, reason })
	return data
}

export async function markPersonChecked(id: number): Promise<Person> {
	const { data } = await axios.post<Person>(apiUrl('/persons/{id}/checked', { id }))
	return data
}

export async function deletePerson(id: number): Promise<void> {
	await axios.delete(apiUrl('/persons/{id}', { id }))
}

export async function addContribution(versionId: number, personId: number, role: string, textForComposition: boolean): Promise<Contribution> {
	const { data } = await axios.post<Contribution>(apiUrl('/versions/{id}/contributions', { id: versionId }), { personId, role, textForComposition })
	return data
}

export async function updateContribution(id: number, contribution: { role?: string, text_for_composition?: boolean }): Promise<Contribution> {
	const { data } = await axios.patch<Contribution>(apiUrl('/contributions/{id}', { id }), { contribution })
	return data
}

export async function removeContribution(id: number): Promise<void> {
	await axios.delete(apiUrl('/contributions/{id}', { id }))
}

export async function fetchProtectionReview(): Promise<ProtectionReviewRow[]> {
	const { data } = await axios.get<ProtectionReviewRow[]>(apiUrl('/protection/review'))
	return list(data)
}

export async function importPersons(fileId: number, dryRun: boolean): Promise<PersonImportReport> {
	const { data } = await axios.post<PersonImportReport>(apiUrl('/persons/import'), { fileId, dryRun })
	return {
		...data,
		renamed: list(data.renamed),
		unknownShelfmarks: list(data.unknownShelfmarks),
		protectionDiffers: list(data.protectionDiffers),
		warnings: list(data.warnings),
	}
}

export async function fetchMergeCandidates(): Promise<MergeCandidateGroup[]> {
	const { data } = await axios.get<MergeCandidateGroup[]>(apiUrl('/merges/candidates'))
	return list(data)
}

/**
 * Merges, newest first.
 *
 * @param all - also undone merges
 */
export async function fetchMerges(all: boolean): Promise<Merge[]> {
	const { data } = await axios.get<Merge[]>(apiUrl('/merges') + '?all=' + (all ? '1' : '0'))
	return list(data)
}

export async function mergeWorks(sourceWorkId: number, targetWorkId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/works'), { sourceWorkId, targetWorkId, reason })
}

export async function moveCopy(copyId: number, targetVersionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/copies'), { copyId, targetVersionId, reason })
}

export async function undoMerge(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/{id}/undo', { id }), { reason })
}

/**
 * Inventory list of a binder.
 *
 * @param binder - binder number, or null for the lowest
 * @param all - also copies that are not active
 */
export async function fetchInventory(binder: number | null, all: boolean): Promise<Inventory> {
	const params = new URLSearchParams({ all: all ? '1' : '0' })
	if (binder !== null) {
		params.set('binder', String(binder))
	}
	const { data } = await axios.get<Inventory>(apiUrl('/inventory') + '?' + params.toString())
	return { ...data, binders: list(data.binders), rows: list(data.rows) }
}

export async function recordCounts(counts: CountInput[], date: string | null): Promise<number> {
	const { data } = await axios.post<{ recorded: number }>(apiUrl('/inventory/count'), { counts, date })
	return data.recorded
}

export async function fetchChecksums(): Promise<ChecksumStatus> {
	const { data } = await axios.get<ChecksumStatus>(apiUrl('/checksums'))
	return { ...data, mismatches: list(data.mismatches) }
}

export async function acceptChecksum(fileRowId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/files/{id}/checksum-accept', { id: fileRowId }), { reason })
}

/**
 * Licenses that end within the given days or have ended, the soonest first.
 *
 * @param days - 1 … 366
 */
export async function fetchEndingLicenses(days: number): Promise<EndingLicense[]> {
	const { data } = await axios.get<EndingLicense[]>(apiUrl('/licenses/ending') + '?days=' + days)
	return list<EndingLicense>(data).map((license) => ({ ...license, copies: list(license.copies), uses: list(license.uses) }))
}

/**
 * Statistics for a period of years (default on the server: the last ten).
 *
 * @param from - first year
 * @param to - last year
 */
export async function fetchStatistics(from: number | null, to: number | null): Promise<Statistics> {
	const { data } = await axios.get<Statistics>(apiUrl('/statistics') + periodQuery(from, to))
	return data
}

/**
 * The annual report of a year.
 *
 * @param year - the year
 */
export async function fetchAnnualReport(year: number): Promise<AnnualReport> {
	const { data } = await axios.get<AnnualReport>(apiUrl('/statistics/report') + '?year=' + year)
	return data
}

/**
 * Download URL of the statistics as CSV.
 *
 * @param from - first year
 * @param to - last year
 */
export function statisticsCsvUrl(from: number | null, to: number | null): string {
	return apiUrl('/statistics/csv') + periodQuery(from, to)
}

/**
 * Download URL of the annual report as CSV.
 *
 * @param year - the year
 */
export function annualReportCsvUrl(year: number): string {
	return apiUrl('/statistics/report/csv') + '?year=' + year
}

/**
 * Query string of a period ("" when both years are open).
 *
 * @param from - first year
 * @param to - last year
 */
function periodQuery(from: number | null, to: number | null): string {
	const params = new URLSearchParams()
	if (from !== null) {
		params.set('from', String(from))
	}
	if (to !== null) {
		params.set('to', String(to))
	}
	const query = params.toString()
	return query === '' ? '' : '?' + query
}

/** Download URL of the complete backup (JSON). */
export function fullBackupUrl(): string {
	return apiUrl('/backup/full')
}

/**
 * Restore a complete backup into an empty catalog.
 *
 * @param fileId - Nextcloud id of the backup file (JSON)
 * @return restored rows per table
 */
export async function restoreBackup(fileId: number): Promise<Record<string, number>> {
	const { data } = await axios.post<{ restored: Record<string, number> }>(apiUrl('/backup/restore'), { fileId })
	return data.restored ?? {}
}

// ---- milestone 0.6: reviewing transcriptions and corrections -------------------

const NO_REVIEW_COUNTS: ReviewCounts = { open: 0, question: 0, correct: 0, confirmed: 0, corrected: 0, dismissed: 0 }

/**
 * A report as delivered by the server: PHP sends an empty summary or field
 * map as `[]`.
 *
 * @param report - report row
 */
function normalizeReport(report: TranscriptionReport): TranscriptionReport {
	const summary = report.summary && typeof report.summary === 'object' && !Array.isArray(report.summary) ? report.summary : null
	const fields = summary?.fields && typeof summary.fields === 'object' && !Array.isArray(summary.fields) ? summary.fields : null
	return {
		...report,
		summary: summary === null
			? null
			: { ...summary, fields, persons: list<ReportPerson>(summary.persons), warnings: list<string>(summary.warnings) },
	}
}

/**
 * Reports, review points and permissions of a version.
 *
 * @param versionId - version id
 */
export async function fetchReview(versionId: number): Promise<VersionReview> {
	const { data } = await axios.get<VersionReview>(apiUrl('/versions/{id}/review', { id: versionId }))
	const counts = data.counts && typeof data.counts === 'object' && !Array.isArray(data.counts) ? data.counts : {}
	return {
		version_id: data.version_id ?? versionId,
		reports: list<TranscriptionReport>(data.reports).map(normalizeReport),
		points: list(data.points),
		counts: { ...NO_REVIEW_COUNTS, ...counts },
		may: {
			decideMusical: data.may?.decideMusical === true,
			decideMaterial: data.may?.decideMaterial === true,
			ingest: data.may?.ingest === true,
			correct: data.may?.correct === true,
		},
	}
}

/**
 * Decide a review point.
 *
 * @param pointId - review point id
 * @param decision - new status with correction text, reason and addressee
 */
export async function decideReviewPoint(pointId: number, decision: ReviewDecisionInput): Promise<ReviewPoint> {
	const { data } = await axios.post<ReviewPoint>(apiUrl('/review-points/{id}/decision', { id: pointId }), decision)
	return data
}

/**
 * Read a transcription report (bericht/1) for a version.
 *
 * @param versionId - version id
 * @param content - JSON text of the report
 */
export async function ingestReport(versionId: number, content: string): Promise<ReportIngestResult> {
	const { data } = await axios.post<ReportIngestResult>(apiUrl('/versions/{id}/transcription-reports', { id: versionId }), { content })
	return { ...data, report: normalizeReport(data.report), warnings: list(data.warnings) }
}

/** The open correction orders for the tool chain, oldest first. */
export async function fetchCorrectionOrders(): Promise<CorrectionOrder[]> {
	const { data } = await axios.get<CorrectionOrder[]>(apiUrl('/review/orders'))
	return list(data)
}

/**
 * Save a small correction of a score as a new file.
 *
 * @param versionId - version id
 * @param correction - score file, changed MusicXML, review points to close, reason
 */
export async function saveCorrection(versionId: number, correction: CorrectionInput): Promise<CorrectionResult> {
	const { data } = await axios.post<CorrectionResult>(apiUrl('/versions/{id}/corrections', { id: versionId }), correction)
	return { ...data, changes: list(data.changes), points: list(data.points) }
}

/**
 * The revisions of a version, oldest first (module "version_states").
 *
 * @param versionId - version id
 */
export async function fetchStates(versionId: number): Promise<StateList> {
	const { data } = await axios.get<StateList>(apiUrl('/versions/{id}/states', { id: versionId }))
	return {
		version_id: data.version_id,
		states: list(data.states),
		current_id: typeof data.current_id === 'number' ? data.current_id : null,
		may: { setCurrent: data.may?.setCurrent === true },
	}
}

/**
 * Make a revision the current one of its version (catalog librarian).
 *
 * @param stateId - revision id
 * @param reason - optional reason for the audit log
 */
export async function setCurrentState(stateId: number, reason: string | null): Promise<VersionState> {
	const { data } = await axios.put<VersionState>(apiUrl('/states/{id}/current', { id: stateId }), { reason })
	return data
}

/**
 * Address of a PDF scan for the browser's own PDF viewer, optionally at a
 * page (`#page=`).
 *
 * @param fileRowId - `files[].id` of the PDF
 * @param page - page to open, or null
 */
export function scanUrl(fileRowId: number, page: number | null = null): string {
	const url = apiUrl('/files/{id}/scan', { id: fileRowId })
	return page !== null && Number.isInteger(page) && page > 0 ? `${url}#page=${page}` : url
}
