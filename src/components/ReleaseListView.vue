<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Target/actual list for the singers' portal or the print station: which
 * versions are to be removed, to be added, not decided (no valid version
 * chosen) or in order. Optionally also blocked versions and those where
 * another version is valid. Printable.
 */
import type { ReleaseRow, ReleaseStatus } from '../types.ts'

import { mdiAlertCircleOutline, mdiBookOpenPageVariantOutline, mdiCheckCircleOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ChoirSystemCompare from './ChoirSystemCompare.vue'
import HelpTerm from './HelpTerm.vue'
import PrintMirrorSync from './PrintMirrorSync.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchReleaseList } from '../api.ts'
import { formatDate, translateLabel } from '../format.ts'
import { releaseStatusLabel } from '../labels.ts'
import { navigate, releaseFilter } from '../navigation.ts'
import { canCompareChoirSystem, canSyncHandover, canSyncPrintMirror, choiceLabel, openEntry } from '../store.ts'

const props = defineProps<{
	target: 'portal' | 'print-station'
}>()

const ORDER: ReleaseStatus[] = ['remove', 'add', 'not_decided', 'ok', 'other_version_valid', 'blocked']

const rows = ref<ReleaseRow[]>([])
const all = ref(false)
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		rows.value = await fetchReleaseList(props.target, all.value)
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(all, load)
watch(() => props.target, () => {
	loaded.value = false
	load()
})

const title = computed(() => props.target === 'portal' ? t('cantoria', 'Singers\' portal') : t('cantoria', 'Print station'))

const description = computed(() => props.target === 'portal'
	? t('cantoria', 'Target and actual state of the singers\' portal: what has to be removed or added. Only confirmed public domain versions or versions with a license may be there, and only the version valid for the choir.')
	: t('cantoria', 'Which versions may be printed at the print station: confirmed public domain or a license for the print station, and the version valid for the choir.'))

interface Option {
	id: ReleaseStatus | ''
	label: string
}

const statusOptions = computed<Option[]>(() => [
	{ id: '', label: t('cantoria', 'All statuses') },
	...ORDER.filter((status) => all.value || (status !== 'blocked' && status !== 'other_version_valid'))
		.map((status) => ({ id: status, label: releaseStatusLabel(status) })),
])

/** The filters in words, for the header of the printout */
const printFilter = computed(() => [statusOption.value?.label ?? '',
	all.value ? t('cantoria', 'Also versions that are not released or where another version is valid') : ''].filter((part) => part !== '').join(', '))

const statusOption = computed({
	get: () => statusOptions.value.find((option) => option.id === releaseFilter.value) ?? statusOptions.value[0],
	set: (option: Option | null) => {
		releaseFilter.value = option?.id ?? ''
	},
})

/** Empty list: with a status chosen, nothing has it; otherwise nothing is released. */
const emptyName = computed(() => releaseFilter.value !== '' || all.value
	? t('cantoria', 'Nothing to show')
	: t('cantoria', 'Nothing released yet'))

const emptyDescription = computed(() => {
	if (releaseFilter.value !== '' || all.value) {
		return t('cantoria', 'No version has this status.')
	}
	return props.target === 'portal'
		? t('cantoria', 'No version is released for the singers\' portal, and nothing has to be taken out. Releases are set in the "Rights" tab of a copy.')
		: t('cantoria', 'No version is released for the print station. Releases are set in the "Rights" tab of a copy.')
})

const groups = computed(() => ORDER
	.filter((status) => releaseFilter.value === '' || releaseFilter.value === status)
	.map((status) => ({ status, rows: rows.value.filter((row) => row.status === status) }))
	.filter((group) => group.rows.length > 0))

/**
 * What to do for a row, in words.
 *
 * @param status - row status
 */
function groupHint(status: ReleaseStatus): string {
	switch (status) {
	case 'remove':
		return t('cantoria', 'In the portal but no longer released: take out.')
	case 'add':
		return t('cantoria', 'Released and valid for the choir, not yet there: add.')
	case 'not_decided':
		return t('cantoria', 'Released, but no version of the work is valid for the choir yet.')
	case 'ok':
		return t('cantoria', 'In the portal and released.')
	case 'other_version_valid':
		return t('cantoria', 'Released, but another version of the work is valid for the choir.')
	case 'blocked':
		return t('cantoria', 'Not released.')
	default:
		return ''
	}
}

/**
 * Rights status of a row in words.
 *
 * @param row - list row
 */
function rightsText(row: ReleaseRow): string {
	const status = row.rightsStatus ? choiceLabel('version', 'rights_status', row.rightsStatus) : t('cantoria', 'not assessed')
	return row.confirmed ? t('cantoria', '{status}, confirmed', { status }) : status
}
</script>

<template>
	<div class="release-list">
		<ViewHeader :title="title"
			:description="description"
			printable
			:print-filter="printFilter"
			reloadable
			:loading="loading"
			@reload="load">
			<template v-if="target === 'portal'" #term>
				<HelpTerm term="singersPortal" />
			</template>
		</ViewHeader>

		<div class="release-list__filters no-print">
			<NcSelect v-model="statusOption"
				class="release-list__status"
				:input-label="t('cantoria', 'Status')"
				:options="statusOptions"
				:clearable="false"
				:searchable="false" />
			<NcCheckboxRadioSwitch v-model="all" type="switch">
				{{ t('cantoria', 'Also versions that are not released or where another version is valid') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="release-list__content">
			<section v-if="target === 'print-station' && canSyncPrintMirror"
				class="release-list__mirror no-print"
				aria-labelledby="release-print-mirror">
				<h3 id="release-print-mirror">
					{{ t('cantoria', 'Mirror folder for the print station') }}
				</h3>
				<p class="release-list__hint">
					{{ t('cantoria', 'The PDF scans of the versions this list shows (to add and not decided), copied into a folder of the print station.') }}
				</p>
				<PrintMirrorSync />
			</section>
			<section v-if="target === 'portal' && canSyncHandover"
				class="release-list__mirror no-print"
				aria-labelledby="release-choir-handover">
				<h3 id="release-choir-handover">
					{{ t('cantoria', 'Hand-over to the choir system') }}
				</h3>
				<p class="release-list__hint">
					{{ t('cantoria', 'The files of the versions released for the portal (in order, to add and not decided), with a list of the pieces and the conductor\'s notes, copied into a folder that the choir system reads.') }}
				</p>
				<PrintMirrorSync kind="handover" />
			</section>
			<section v-if="target === 'portal' && canCompareChoirSystem"
				class="release-list__mirror no-print"
				aria-labelledby="release-choir-compare">
				<h3 id="release-choir-compare">
					{{ t('cantoria', 'Compare with the choir system') }}
				</h3>
				<p class="release-list__hint">
					{{ t('cantoria', 'Choose an export of the choir system (a list with the shelf mark, or titles that start with it). The app compares it with this list; what differs becomes a proposal in the inbox instead of a change.') }}
				</p>
				<ChoirSystemCompare />
			</section>
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The list could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="groups.length === 0"
				:name="emptyName"
				:description="emptyDescription">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
				</template>
				<template v-if="releaseFilter === '' && !all" #action>
					<NcButton @click="navigate('catalog')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookOpenPageVariantOutline" />
						</template>
						{{ t('cantoria', 'Open the catalog') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<section v-for="group in groups"
				:key="group.status"
				class="release-list__group"
				:aria-labelledby="`release-${target}-${group.status}`">
				<h3 :id="`release-${target}-${group.status}`">
					{{ releaseStatusLabel(group.status) }} ({{ group.rows.length }})
				</h3>
				<p class="release-list__hint">
					{{ groupHint(group.status) }}
				</p>
				<table class="release-list__table">
					<caption class="hidden-visually">
						{{ n('cantoria', '%n version: {status}', '%n versions: {status}', group.rows.length, { status: releaseStatusLabel(group.status) }) }}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('cantoria', 'Shelf mark') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Title') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Rights') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Valid since') }}
							</th>
							<th v-if="target === 'portal'" scope="col">
								{{ t('cantoria', 'In the portal since') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Reason') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in group.rows" :key="row.versionId">
							<td class="release-list__shelfmarks">
								<button v-for="copy in row.copies"
									:key="copy.id"
									type="button"
									class="release-list__shelfmark"
									:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: copy.shelfmark })"
									@click="openEntry(copy.id, 'rights')">
									{{ copy.shelfmark }}
								</button>
							</td>
							<td>
								{{ row.title }}
								<span v-if="row.composer" class="release-list__muted">{{ row.composer }}</span>
							</td>
							<td>{{ rightsText(row) }}</td>
							<td>{{ row.validSince ? formatDate(row.validSince) : '–' }}</td>
							<td v-if="target === 'portal'">
								{{ row.portalSince ? formatDate(row.portalSince) : '–' }}
							</td>
							<td>{{ row.reason ? translateLabel(row.reason) : '' }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
	</div>
</template>

<style scoped>
.release-list__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.release-list__status {
	min-width: 16em;
}

.release-list__content {
	display: flex;
	flex-direction: column;
	gap: calc(5 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.release-list__mirror {
	padding-bottom: calc(3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.release-list__mirror h3,
.release-list__group h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.release-list__hint {
	margin: 0 0 var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.release-list__table {
	width: 100%;
	border-collapse: collapse;
}

.release-list__table th,
.release-list__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	/* long words break only when they do not fit; headings stay whole */
	overflow-wrap: break-word;
}

.release-list__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.release-list__shelfmarks {
	white-space: nowrap;
}

.release-list__shelfmark {
	margin: 0 var(--default-grid-baseline) 0 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-decoration: underline;
	cursor: pointer;
}

.release-list__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.release-list__muted {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

@media print {
	.release-list__content {
		padding: 0;
	}

	.release-list__shelfmark {
		text-decoration: none;
	}
}
</style>
