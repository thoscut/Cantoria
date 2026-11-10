<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Sidebar with everything about one copy: overview (work, version, copy,
 * merges), rights with confirmation, releases, licenses, persons involved
 * and the protection proposal, tasks and links, files with the revisions of
 * the version, and audit log. The score view opens from here.
 */
import type { SectionDef } from '../fieldGroups.ts'
import type { ScoreRequest } from '../store.ts'
import type { Sibling } from '../types.ts'

import {
	mdiAlertCircleOutline,
	mdiClipboardTextOutline,
	mdiFileMultipleOutline,
	mdiHistory,
	mdiInformationOutline,
	mdiMusic,
	mdiScaleBalance,
	mdiTextBoxCheckOutline,
} from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AuditList from './AuditList.vue'
import ContributionsPanel from './ContributionsPanel.vue'
import CopyTasksTab from './CopyTasksTab.vue'
import FieldSection from './FieldSection.vue'
import FileList from './FileList.vue'
import MergeList from './MergeList.vue'
import RightsBadge from './RightsBadge.vue'
import RightsPanel from './RightsPanel.vue'
import StatesPanel from './StatesPanel.vue'
import { songListCount, songListNames } from '../catalog.ts'
import { OVERVIEW_SECTIONS, RIGHTS_SECTIONS, sectionFields } from '../fieldGroups.ts'
import { focusElement } from '../focus.ts'
import { formatDate } from '../format.ts'
import { repertoireClassLabel, repertoireSourceLabel } from '../labels.ts'
import { showError } from '../toasts.ts'
import {
	choiceLabel,
	requestCloseEntry,
	detail,
	detailError,
	detailLoading,
	entries,
	isFieldActive,
	isModuleActive,
	loadDetail,
	loadEntries,
	refreshPortalRows,
	meta,
	openEntry,
	scoreRequest,
	selectedId,
	SIDEBAR_ID,
	sidebarTab,
	sidebarTakesFocus,
} from '../store.ts'

// The score view (with Verovio) is loaded only when it is opened
const ScoreViewer = defineAsyncComponent(() => import('./ScoreViewer.vue'))

const scoreOpen = ref(false)
/** What the score view shows first (from a link or a task) */
const scoreStart = ref<ScoreRequest | null>(null)

// Another copy closes the score view
watch(selectedId, () => {
	scoreOpen.value = false
	scoreStart.value = null
})

// Open the score view when a link or a task asks for it
watch([scoreRequest, detail], ([request, current]) => {
	if (!request || !current || current.copy.id !== request.copyId) {
		return
	}
	scoreRequest.value = null
	if (current.score?.canView) {
		scoreStart.value = request
		scoreOpen.value = true
	} else {
		showError(current.score?.reason || t('cantoria', 'There is no score to show for this copy.'))
	}
}, { immediate: true })

/**
 * Open the score view.
 *
 * @param review - with the review of the transcription
 */
function openScore(review = false): void {
	scoreStart.value = review && selectedId.value !== null ? { copyId: selectedId.value, review: true } : null
	scoreOpen.value = true
}

/** Review points of the copy that wait for a decision (module "transcription_review"). */
const openReviewPoints = computed(() => (isModuleActive('transcription_review') ? (listEntry.value?.review_open ?? 0) : 0))

const activeTab = computed({
	get: () => sidebarTab.value,
	set: (value: string) => {
		sidebarTab.value = value
	},
})

/** Row of the list, used for the header while the detail is loading. */
const listEntry = computed(() => entries.value.find((entry) => entry.id === selectedId.value))

const shelfmark = computed(() => String(detail.value?.copy.shelfmark ?? listEntry.value?.shelfmark ?? ''))
const title = computed(() => String(detail.value?.work.title ?? listEntry.value?.title ?? ''))
const composer = computed(() => String(detail.value?.work.composer ?? listEntry.value?.composer ?? ''))

const sidebarName = computed(() => [shelfmark.value, title.value].filter((part) => part !== '').join(' – ')
	|| t('cantoria', 'Copy'))

/**
 * NcAppSidebar (@nextcloud/vue 9) names its landmark with aria-labelledby,
 * but never renders an element with that id. Give its heading the id, so
 * that screen readers announce the sidebar with the copy's name.
 */
function labelSidebar(): void {
	const aside = document.getElementById(SIDEBAR_ID)
	const id = aside?.getAttribute('aria-labelledby')
	if (aside && id && document.getElementById(id) === null) {
		aside.querySelector('.app-sidebar-header__mainname')?.setAttribute('id', id)
	}
}

onMounted(labelSidebar)
watch([sidebarName, detail, detailLoading], labelSidebar, { flush: 'post' })

/**
 * Opened from a row or link: the focus moves to the name of the copy in the
 * sidebar, so that keyboard and screen reader users are where the content
 * is. requestCloseEntry() takes it back to the row.
 */
function takeFocus(): void {
	if (!sidebarTakesFocus.value || detailLoading.value) {
		return
	}
	const heading = document.querySelector<HTMLElement>(`#${SIDEBAR_ID} .app-sidebar-header__mainname`)
	if (heading !== null && focusElement(heading)) {
		sidebarTakesFocus.value = false
	}
}

onMounted(takeFocus)
watch(detailLoading, takeFocus, { flush: 'post' })

const status = computed(() => String(detail.value?.copy.status ?? listEntry.value?.status ?? 'active'))

const preservationCopy = computed(() => detail.value?.version.preservation_copy === true)

/** Confirmation of the rights: in the app (confirmed_at) or from the old catalog. */
const rightsConfirmed = computed(() => detail.value?.version.confirmed_at || detail.value?.version.rights_confirmed || null)

/** Repertoire figures from the catalog row (derived by the server). */
const repertoire = computed(() => {
	const entry = listEntry.value
	if (!entry) {
		return null
	}
	return {
		label: repertoireClassLabel(entry.repertoire_class),
		source: repertoireSourceLabel(entry.repertoire_source),
		performances: entry.performances ?? null,
		lastPerformed: entry.last_performed_on ?? null,
		songLists: songListNames(entry),
		songListCount: songListCount(entry),
	}
})

/**
 * Heading of a section.
 *
 * @param section - section definition
 */
function sectionHeading(section: SectionDef): string {
	switch (section.id) {
	case 'work':
		return t('cantoria', 'Work')
	case 'version':
		return t('cantoria', 'Version')
	case 'copy':
		return t('cantoria', 'Copy')
	case 'rights':
		return t('cantoria', 'Rights')
	case 'musicxml':
		return t('cantoria', 'MusicXML')
	}
	return ''
}

/**
 * Field keys of a section.
 *
 * @param section - section definition
 */
function keysOf(section: SectionDef): string[] {
	// Fields of switched-off modules (e.g. the publication status) are not shown
	return sectionFields(section.id, meta.value?.fields[section.level] ?? {}).filter((key) => isFieldActive(key))
}

/** Tab with the questions and tasks and the internal links of the copy. */
const tasksTabShown = computed(() => isModuleActive('tasks') || isModuleActive('excerpt_links'))

// Short names, so that the five tabs are not cut off in a narrow sidebar;
// the tab itself lists the open questions and tasks
const tasksTabName = computed(() => (isModuleActive('tasks') ? t('cantoria', 'Queries') : t('cantoria', 'Links')))

/**
 * Siblings of the same version first, then by shelf mark.
 */
const siblings = computed<Sibling[]>(() => {
	const versionId = detail.value?.version.id
	const collator = new Intl.Collator(undefined, { numeric: true })
	return [...(detail.value?.siblings ?? [])].sort((a, b) => Number(b.version_id === versionId) - Number(a.version_id === versionId)
		|| collator.compare(a.shelfmark, b.shelfmark))
})

/**
 * Label of a sibling button: shelf mark plus hints.
 *
 * @param sibling - other copy of the same work
 */
function siblingHint(sibling: Sibling): string {
	const hints = [sibling.version_id === detail.value?.version.id
		? t('cantoria', 'same version')
		: t('cantoria', 'other version')]
	if (sibling.status !== 'active') {
		hints.push(choiceLabel('copy', 'status', sibling.status))
	}
	return hints.join(', ')
}

async function onSaved(): Promise<void> {
	await Promise.all([loadDetail(), loadEntries(), refreshPortalRows()])
}
</script>

<template>
	<NcAppSidebar :name="sidebarName"
		:title="sidebarName"
		:subname="composer"
		:active="activeTab"
		:loading="detailLoading && !detail"
		:empty="!detail && !detailLoading && detailError !== ''"
		force-tabs
		@update:active="activeTab = $event"
		@close="requestCloseEntry">
		<template #description>
			<div v-if="detail" class="copy-sidebar__description">
				<RightsBadge :rights-status="detail.version.rights_status"
					:rights-confirmed="rightsConfirmed"
					:preservation-copy="detail.version.preservation_copy" />
				<span v-if="status !== 'active'" class="copy-sidebar__status">
					{{ t('cantoria', 'Status: {status}', { status: choiceLabel('copy', 'status', status) }) }}
				</span>
				<NcButton v-if="detail.score?.canView && isModuleActive('score_viewer')"
					variant="secondary"
					:aria-label="t('cantoria', 'Show score of {shelfmark}', { shelfmark })"
					@click="openScore()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusic" />
					</template>
					{{ t('cantoria', 'Score') }}
				</NcButton>
				<NcButton v-if="detail.score?.canView && isModuleActive('score_viewer') && openReviewPoints > 0"
					variant="tertiary"
					@click="openScore(true)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiTextBoxCheckOutline" />
					</template>
					{{ n('cantoria', '%n open review point', '%n open review points', openReviewPoints) }}
				</NcButton>
				<ScoreViewer v-if="scoreOpen && detail.score"
					:detail="detail"
					:start="scoreStart"
					@changed="onSaved"
					@close="scoreOpen = false" />
			</div>
		</template>

		<NcEmptyContent v-if="!detail && detailError !== ''"
			:name="t('cantoria', 'Could not load the entry')"
			:description="detailError">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
			<template #action>
				<NcButton @click="loadDetail(true)">
					{{ t('cantoria', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-if="detail">
			<NcAppSidebarTab id="overview" :name="t('cantoria', 'Details')" :order="1">
				<template #icon>
					<NcIconSvgWrapper :path="mdiInformationOutline" />
				</template>
				<div :key="detail.copy.id" class="copy-sidebar__tab">
					<FieldSection v-for="section in OVERVIEW_SECTIONS"
						:key="section.id"
						:section-id="section.id"
						:level="section.level"
						:heading="sectionHeading(section)"
						:record="detail[section.level]"
						:field-keys="keysOf(section)"
						@saved="onSaved">
						<RightsBadge v-if="section.id === 'version'"
							class="copy-sidebar__section-badge"
							:rights-status="detail.version.rights_status"
							:rights-confirmed="rightsConfirmed"
							:preservation-copy="detail.version.preservation_copy" />
					</FieldSection>

					<section v-if="repertoire" class="copy-sidebar__siblings" aria-labelledby="copy-sidebar-repertoire">
						<h3 id="copy-sidebar-repertoire">
							{{ t('cantoria', 'Repertoire') }}
						</h3>
						<dl class="copy-sidebar__facts">
							<dt>{{ t('cantoria', 'Class') }}</dt>
							<dd>
								{{ repertoire.label }}
								<span v-if="repertoire.source" class="copy-sidebar__muted">({{ repertoire.source }})</span>
							</dd>
							<template v-if="repertoire.performances !== null">
								<dt>{{ t('cantoria', 'Documented performances') }}</dt>
								<dd>{{ repertoire.performances }}</dd>
							</template>
							<template v-if="repertoire.lastPerformed">
								<dt>{{ t('cantoria', 'Last performed') }}</dt>
								<dd>{{ formatDate(repertoire.lastPerformed) }}</dd>
							</template>
							<template v-if="repertoire.songListCount > 0">
								<dt>{{ t('cantoria', 'Song lists') }}</dt>
								<dd>
									{{ n('cantoria', 'on %n song list', 'on %n song lists', repertoire.songListCount) }}<template v-if="repertoire.songLists.length > 0">
										({{ repertoire.songLists.join(', ') }})
									</template>
								</dd>
							</template>
						</dl>
					</section>

					<section v-if="detail.merges.length > 0" class="copy-sidebar__siblings" aria-labelledby="copy-sidebar-merges">
						<h3 id="copy-sidebar-merges">
							{{ t('cantoria', 'Merges') }}
						</h3>
						<MergeList :merges="detail.merges" compact @changed="onSaved" />
					</section>

					<section class="copy-sidebar__siblings" aria-labelledby="copy-sidebar-siblings">
						<h3 id="copy-sidebar-siblings">
							{{ t('cantoria', 'Other copies of this work') }}
						</h3>
						<p v-if="siblings.length === 0" class="copy-sidebar__muted">
							{{ t('cantoria', 'None.') }}
						</p>
						<ul v-else class="copy-sidebar__sibling-list">
							<li v-for="sibling in siblings" :key="sibling.id">
								<NcButton variant="secondary"
									:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: sibling.shelfmark })"
									@click="openEntry(sibling.id)">
									<span class="copy-sidebar__sibling-shelfmark">{{ sibling.shelfmark }}</span>
									<span class="copy-sidebar__sibling-hint">{{ siblingHint(sibling) }}</span>
								</NcButton>
							</li>
						</ul>
					</section>
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="rights" :name="t('cantoria', 'Rights')" :order="2">
				<template #icon>
					<NcIconSvgWrapper :path="mdiScaleBalance" />
				</template>
				<div :key="detail.copy.id" class="copy-sidebar__tab">
					<RightsPanel :detail="detail" @saved="onSaved" />
					<ContributionsPanel v-if="isModuleActive('persons')" :detail="detail" @saved="onSaved" />
					<FieldSection v-for="section in RIGHTS_SECTIONS"
						:key="section.id"
						:section-id="section.id"
						:level="section.level"
						:heading="sectionHeading(section)"
						:record="detail[section.level]"
						:field-keys="keysOf(section)"
						@saved="onSaved">
						<RightsBadge v-if="section.id === 'rights'"
							class="copy-sidebar__section-badge"
							:rights-status="detail.version.rights_status"
							:rights-confirmed="rightsConfirmed"
							:preservation-copy="detail.version.preservation_copy" />
					</FieldSection>
					<p class="copy-sidebar__muted">
						{{ t('cantoria', 'Rights belong to the version, not to the work. The rights indicator is derived from the data; it is not legal advice.') }}
					</p>
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab v-if="tasksTabShown"
				id="tasks"
				:name="tasksTabName"
				:order="3">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClipboardTextOutline" />
				</template>
				<CopyTasksTab :key="detail.copy.id" :detail="detail" @changed="onSaved" />
			</NcAppSidebarTab>

			<NcAppSidebarTab id="files" :name="t('cantoria', 'Files')" :order="4">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileMultipleOutline" />
				</template>
				<div :key="detail.copy.id" class="copy-sidebar__tab">
					<FileList :files="detail.files"
						:preservation-copy="preservationCopy"
						:copy-id="detail.copy.id"
						@changed="onSaved" />
					<StatesPanel v-if="isModuleActive('version_states')" :detail="detail" @changed="onSaved" />
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="audit" :name="t('cantoria', 'History')" :order="5">
				<template #icon>
					<NcIconSvgWrapper :path="mdiHistory" />
				</template>
				<AuditList :key="detail.copy.id" :audit="detail.audit" />
			</NcAppSidebarTab>
		</template>
	</NcAppSidebar>
</template>

<style scoped>
.copy-sidebar__description {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
}

.copy-sidebar__status {
	font-weight: bold;
}

.copy-sidebar__tab {
	padding-bottom: calc(4 * var(--default-grid-baseline));
}

.copy-sidebar__section-badge {
	margin-top: var(--default-grid-baseline);
}

.copy-sidebar__siblings {
	padding-block: calc(2 * var(--default-grid-baseline));
}

.copy-sidebar__siblings h3 {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1.1em;
	font-weight: bold;
}

.copy-sidebar__sibling-list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.copy-sidebar__sibling-shelfmark {
	font-weight: bold;
}

.copy-sidebar__sibling-hint {
	margin-inline-start: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.copy-sidebar__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(2 * var(--default-grid-baseline));
	margin: 0;
}

.copy-sidebar__facts dt {
	color: var(--color-text-maxcontrast);
}

.copy-sidebar__facts dd {
	margin: 0;
}

.copy-sidebar__muted {
	color: var(--color-text-maxcontrast);
}
</style>
