<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Root of the app: navigation, the current view (start page, catalog,
 * tasks, inbox, release lists, programmes, links, maintaining the catalog),
 * sidebar and dialogs,
 * plus the states before there is anything to show (no access, no
 * catalog, …). On start it follows the query parameters of notifications
 * and shared links.
 */
import type { StartParams, ViewName } from './startParams.ts'
import {
	mdiAccountLockOutline,
	mdiAlertCircleOutline,
	mdiArchiveOutline,
	mdiBookshelf,
	mdiCogOutline,
} from '@mdi/js'
import { t } from './l10n.ts'
import { computed, defineAsyncComponent, onMounted, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AppNotices from './components/AppNotices.vue'
import CatalogList from './components/CatalogList.vue'
import CatalogNavigation from './components/CatalogNavigation.vue'
import CopySidebar from './components/CopySidebar.vue'
import DueView from './components/DueView.vue'
import GuideLink from './components/GuideLink.vue'
import { USER_GUIDE, userGuideUrl } from './docs.ts'
import { adminSettingsUrl, errorMessage, fetchTasks, resolveLink } from './api.ts'
import { formatDate } from './format.ts'
import { applyModules } from './modules.ts'
import { currentEventId, currentView, navigate, openDueTarget, startNavigation, switchedOffNotice } from './navigation.ts'
import { startNotice } from './notices.ts'
import { DEFAULT_VIEW, isMissingCopy, parseStartParams } from './startParams.ts'
import {
	archiveDialogOpen,
	canChangeSettings,
	canSeeFolderBook,
	canSeeProposals,
	entries,
	entriesError,
	entriesStatus,
	initMeta,
	isAdmin,
	isConductor,
	isLibrarian,
	highlightedTaskId,
	loadEntries,
	meta,
	metaError,
	moduleStates,
	openEntry,
	personalViewDialogOpen,
	reloadMeta,
	scoreRequest,
	selectedId,
} from './store.ts'
import { viewTitle } from './viewTitles.ts'

// Views other than the start page and the catalog are loaded when needed
// only for the archive roles, after the dialog is opened: not in everyone's main bundle
const ArchiveManagement = defineAsyncComponent(() => import('./components/ArchiveManagement.vue'))
const TasksView = defineAsyncComponent(() => import('./components/TasksView.vue'))
const ProposalsView = defineAsyncComponent(() => import('./components/ProposalsView.vue'))
const ReleaseListView = defineAsyncComponent(() => import('./components/ReleaseListView.vue'))
const EventsView = defineAsyncComponent(() => import('./components/EventsView.vue'))
const EventDetailView = defineAsyncComponent(() => import('./components/EventDetailView.vue'))
const AmbiguousView = defineAsyncComponent(() => import('./components/AmbiguousView.vue'))
const LinksView = defineAsyncComponent(() => import('./components/LinksView.vue'))
const LicensesView = defineAsyncComponent(() => import('./components/LicensesView.vue'))
const AccessionView = defineAsyncComponent(() => import('./components/AccessionView.vue'))
const PersonsView = defineAsyncComponent(() => import('./components/PersonsView.vue'))
const ProtectionView = defineAsyncComponent(() => import('./components/ProtectionView.vue'))
const StatisticsView = defineAsyncComponent(() => import('./components/StatisticsView.vue'))
const MergesView = defineAsyncComponent(() => import('./components/MergesView.vue'))
const InventoryView = defineAsyncComponent(() => import('./components/InventoryView.vue'))
const ChecksumsView = defineAsyncComponent(() => import('./components/ChecksumsView.vue'))
const FolderBookView = defineAsyncComponent(() => import('./components/FolderBookView.vue'))
const ShelfmarkGapsView = defineAsyncComponent(() => import('./components/ShelfmarkGapsView.vue'))
const AnnualSnapshotsView = defineAsyncComponent(() => import('./components/AnnualSnapshotsView.vue'))
const ValidVersionsView = defineAsyncComponent(() => import('./components/ValidVersionsView.vue'))
const PersonalViewDialog = defineAsyncComponent(() => import('./components/PersonalViewDialog.vue'))

/** Tell that a link or the back button led to a function that is switched off. */
function noticeSwitchedOff(): void {
	startNotice.value = { text: t('cantoria', 'This function is switched off.'), type: 'warning' }
}

watch(switchedOffNotice, (value) => {
	if (value) {
		noticeSwitchedOff()
		switchedOffNotice.value = false
	}
})

/** The server hides the app from users without a role; this is a fallback. */
const hasAccess = computed(() => meta.value !== null && (meta.value.admin || meta.value.roles.length > 0))

const catalogEmpty = computed(() => entriesStatus.value === 'ready' && entries.value.length === 0)

/**
 * Title of the browser tab: the open copy, the view and the app, so that
 * tabs, the history and screen readers tell the views apart (WCAG 2.4.2).
 * Nextcloud adds the name of the instance.
 */
const pageTitle = computed(() => {
	const appName = t('cantoria', 'Cantoria')
	if (!hasAccess.value) {
		return appName
	}
	const shelfmark = selectedId.value !== null
		? entries.value.find((entry) => entry.id === selectedId.value)?.shelfmark ?? ''
		: ''
	return [shelfmark, viewTitle(currentView.value, isLibrarian.value), appName]
		.filter((part) => part !== '')
		.join(' - ')
})

/**
 * The view to show for a view from the URL: views the roles of the user do
 * not include (e.g. the inbox without a role that sees proposals) fall back
 * to the start page instead of showing an error.
 *
 * @param params - parsed query parameters
 */
function allowedView(params: StartParams): ViewName {
	if ((params.view === 'proposals' && !canSeeProposals.value) || (params.view === 'folderBook' && !canSeeFolderBook.value)
		|| (params.view === 'validVersions' && !isConductor.value)) {
		return DEFAULT_VIEW
	}
	return params.view ?? DEFAULT_VIEW
}

/**
 * Follow the parameters of a notification or shared link.
 *
 * @param params - parsed query parameters
 */
async function followStartParams(params: StartParams): Promise<void> {
	if (params.linkToken !== null) {
		await openLink(params.linkToken)
		return
	}
	let copyId = params.copyId
	if (params.taskId !== null && copyId === null) {
		try {
			copyId = (await fetchTasks(true)).find((task) => task.id === params.taskId)?.copy_id ?? null
		} catch {
			copyId = null
		}
	}
	if (copyId !== null && isMissingCopy(copyId, entriesStatus.value === 'ready' ? entries.value : null)) {
		startNotice.value = { text: t('cantoria', 'This copy does not exist or is no longer available.'), type: 'warning' }
		// Do not try again on reload; keep the view in the URL
		navigate(currentView.value, { eventId: currentEventId.value, replace: true })
		return
	}
	if (copyId !== null) {
		if (params.review) {
			// A notification about review points: the score view with the review
			scoreRequest.value = { copyId, review: true }
		} else if (params.score && params.copyId === copyId) {
			// From the Files app: the score view, which checks the rights itself
			scoreRequest.value = { copyId }
		}
		await openEntry(copyId, params.taskId !== null ? 'tasks' : undefined)
		// after opening: opening another copy resets the highlight
		highlightedTaskId.value = params.taskId
	}
}

/**
 * Open an internal excerpt link: the copy in the sidebar and the score view
 * with range and voice.
 *
 * @param token - link token
 */
async function openLink(token: string): Promise<void> {
	try {
		const link = await resolveLink(token)
		const notices: string[] = []
		if (link.changed) {
			notices.push(t('cantoria', 'The score has been changed since the link was created. Measures and voices may no longer match.'))
		}
		if (link.note) {
			notices.push(t('cantoria', 'Note: {note}', { note: link.note }))
		}
		if (link.createdByName) {
			notices.push(t('cantoria', 'Link by {name}, valid until {date}.', { name: link.createdByName, date: formatDate(link.expires ?? '') }))
		}
		scoreRequest.value = {
			copyId: link.copy_id,
			measureFrom: link.measure_from,
			measureTo: link.measure_to,
			voice: link.voice,
			verse: link.verse,
			tempo: link.tempo,
			notice: notices.join(' '),
			noticeType: link.changed ? 'warning' : 'info',
		}
		await openEntry(link.copy_id)
	} catch (error) {
		startNotice.value = { text: t('cantoria', 'The link cannot be opened: {reason}', { reason: errorMessage(error) }), type: 'error' }
	} finally {
		// Do not open the link again on reload; keep the view in the URL
		navigate(currentView.value, { eventId: currentEventId.value, replace: true })
	}
}

onMounted(async () => {
	await initMeta()
	if (hasAccess.value) {
		const parsed = parseStartParams(window.location.search)
		// Parts of the link that need a switched-off module lead to the start page
		const { params, switchedOff } = applyModules(parsed, moduleStates.value)
		if (switchedOff) {
			noticeSwitchedOff()
		}
		currentView.value = allowedView(params)
		currentEventId.value = params.eventId
		startNavigation()
		if (params.dueKey !== null && params.view === currentView.value) {
			// A card of the start page from the dashboard widget: its view with its filters
			openDueTarget(params.dueKey, { replace: true })
		} else if (switchedOff
			|| ((new URLSearchParams(window.location.search).has('view') || parsed.dueKey !== null) && params.view !== currentView.value)) {
			// An unknown view, one the roles do not include or a switched-off
			// function: show the start page in the URL, too
			navigate(currentView.value, { eventId: currentEventId.value, replace: true })
		}
		await loadEntries()
		await followStartParams(params)
	}
})

async function retry(): Promise<void> {
	if (meta.value === null) {
		await reloadMeta()
	}
	if (hasAccess.value) {
		await loadEntries()
	}
}

function openArchive(): void {
	archiveDialogOpen.value = true
}

/** The settings are a page under Administration → Cantoria. */
const settingsUrl = adminSettingsUrl()
</script>

<template>
	<NcContent app-name="cantoria">
		<CatalogNavigation v-if="hasAccess" />

		<NcAppContent :page-heading="t('cantoria', 'Cantoria')" :page-title="pageTitle">
			<!-- Meta could not be loaded -->
			<NcEmptyContent v-if="meta === null && metaError !== ''"
				:name="t('cantoria', 'Cantoria could not be loaded')"
				:description="metaError">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="retry">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Starting -->
			<NcEmptyContent v-else-if="meta === null" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>

			<!-- No role -->
			<NcEmptyContent v-else-if="!hasAccess"
				:name="t('cantoria', 'No access to the music archive')"
				:description="t('cantoria', 'Your account has no role in the music archive. Please ask the administration to add your account to the group of your role.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountLockOutline" />
				</template>
				<template #action>
					<GuideLink :href="userGuideUrl(USER_GUIDE.noAccess)" :label="t('cantoria', 'No access to the music archive (guide)')" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<!-- Without a view (loading, no catalog) the notices stand here;
					 otherwise under the heading of the view -->
				<AppNotices v-if="entriesStatus !== 'ready' || catalogEmpty" />

				<!-- Loading the catalog -->
				<NcEmptyContent v-if="entriesStatus === 'loading' || entriesStatus === 'idle'"
					:name="t('cantoria', 'Loading the catalog …')">
					<template #icon>
						<NcLoadingIcon :size="64" />
					</template>
				</NcEmptyContent>

				<!-- Loading failed -->
				<NcEmptyContent v-else-if="entriesStatus === 'error'"
					:name="t('cantoria', 'The catalog could not be loaded')"
					:description="entriesError">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
					</template>
					<template #action>
						<NcButton @click="retry">
							{{ t('cantoria', 'Try again') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<!-- Empty catalog -->
				<NcEmptyContent v-else-if="catalogEmpty"
					:name="t('cantoria', 'No catalog yet')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBookshelf" />
					</template>
					<template #description>
						<template v-if="isLibrarian">
							{{ t('cantoria', 'Import the existing catalog (CSV) in the archive management: Archive management → Import catalog. A complete backup is restored there as well.') }}
						</template>
						<template v-else-if="isAdmin">
							{{ t('cantoria', 'First choose the archive folder and assign the roles in the settings. Then a music librarian imports the catalog. A complete backup can be restored in the settings.') }}
						</template>
						<template v-else>
							{{ t('cantoria', 'The music librarian has not imported the catalog yet.') }}
						</template>
					</template>
					<template v-if="isLibrarian || canChangeSettings" #action>
						<div class="app-actions">
							<NcButton v-if="isLibrarian" variant="primary" @click="openArchive">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArchiveOutline" />
								</template>
								{{ t('cantoria', 'Open archive management') }}
							</NcButton>
							<NcButton v-if="canChangeSettings" :href="settingsUrl">
								<template #icon>
									<NcIconSvgWrapper :path="mdiCogOutline" />
								</template>
								{{ t('cantoria', 'Open settings') }}
							</NcButton>
						</div>
					</template>
				</NcEmptyContent>

				<template v-else>
					<DueView v-if="currentView === 'due'" />
					<CatalogList v-else-if="currentView === 'catalog'" />
					<TasksView v-else-if="currentView === 'tasks'" />
					<ProposalsView v-else-if="currentView === 'proposals'" />
					<ReleaseListView v-else-if="currentView === 'portal'" target="portal" />
					<ReleaseListView v-else-if="currentView === 'printStation'" target="print-station" />
					<EventsView v-else-if="currentView === 'concerts'" kind="concert" />
					<EventsView v-else-if="currentView === 'songLists'" kind="song_list" />
					<EventDetailView v-else-if="currentView === 'event' && currentEventId !== null" :event-id="currentEventId" />
					<AmbiguousView v-else-if="currentView === 'ambiguous'" />
					<LinksView v-else-if="currentView === 'links'" />
					<LicensesView v-else-if="currentView === 'licenses'" />
					<AccessionView v-else-if="currentView === 'accession'" />
					<PersonsView v-else-if="currentView === 'persons'" />
					<ProtectionView v-else-if="currentView === 'protection'" />
					<StatisticsView v-else-if="currentView === 'statistics'" />
					<MergesView v-else-if="currentView === 'merges'" />
					<InventoryView v-else-if="currentView === 'inventory'" />
					<ChecksumsView v-else-if="currentView === 'checksums'" />
					<FolderBookView v-else-if="currentView === 'folderBook' && canSeeFolderBook" />
					<ShelfmarkGapsView v-else-if="currentView === 'gaps'" />
					<AnnualSnapshotsView v-else-if="currentView === 'snapshots'" />
					<ValidVersionsView v-else-if="currentView === 'validVersions' && isConductor" />
					<DueView v-else />
				</template>
			</template>
		</NcAppContent>

		<CopySidebar v-if="hasAccess && selectedId !== null" />

		<ArchiveManagement v-if="hasAccess && isLibrarian && archiveDialogOpen" />
		<PersonalViewDialog v-if="hasAccess && personalViewDialogOpen" />
	</NcContent>
</template>

<style scoped>
.app-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>

<style>
/* Nextcloud's server.css keeps table cells on one line. The app's tables
   wrap instead, so that they fit the content area (WCAG 1.4.10); cells that
   must stay on one line say so themselves. */
#content-vue table {
	white-space: normal;
}

/* Nextcloud's server.css also lays out every definition list as a form
   (dt 130 px wide, right-aligned, on one line, 12 px padding). The app lays
   out its lists itself, in the page and in its dialogs; these are the
   neutral defaults, so that a label stays next to its value. */
#content-vue dl,
.modal-container dl {
	padding: 0;
}

#content-vue dt,
#content-vue dd,
.modal-container dt,
.modal-container dd {
	width: auto;
	padding: 0;
	text-align: start;
	white-space: normal;
}

/* Printing: A4, black on white, only the content of the current view */
@media print {
	@page {
		size: A4;
		margin: 15mm 12mm;
	}

	html,
	body,
	#content,
	#content-vue,
	#app-content-vue,
	.app-content,
	.app-content-wrapper {
		position: static !important;
		width: auto !important;
		height: auto !important;
		min-height: 0 !important;
		margin: 0 !important;
		padding: 0 !important;
		overflow: visible !important;
		background: #fff !important;
		color: #000 !important;
	}

	#header,
	#skip-actions,
	.vue-skip-actions,
	.app-navigation,
	#app-navigation-vue,
	.app-navigation-toggle-wrapper,
	.app-sidebar,
	#app-sidebar-vue,
	.toastify,
	.button-vue,
	.no-print {
		display: none !important;
	}

	#cantoria * {
		color: #000 !important;
		background-color: transparent !important;
		box-shadow: none !important;
	}

	#cantoria a,
	#cantoria button {
		text-decoration: none !important;
	}
}
</style>
