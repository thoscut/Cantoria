<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Resubmission of protection terms: versions marked protected that are
 * public domain by the life dates (to check), versions marked public domain
 * whose recorded persons do not allow that (conflict), and protected
 * versions with a known end. A proposal only – it never changes the rights
 * status and is no legal advice.
 */
import type { ProtectionReviewRow } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ViewHeader from './ViewHeader.vue'
import ReloadError from './ReloadError.vue'
import { errorMessage, fetchProtectionReview } from '../api.ts'
import { formatDate } from '../format.ts'
import { choiceLabel, openEntry } from '../store.ts'

const rows = ref<ProtectionReviewRow[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

/** Counter of the loads: only the answer to the latest one counts (a filter switched quickly) */
let loadRun = 0

async function load(): Promise<void> {
	const run = ++loadRun
	loading.value = true
	error.value = ''
	try {
		const result = await fetchProtectionReview()
		if (run !== loadRun) {
			return
		}
		rows.value = result
		loaded.value = true
	} catch (caught) {
		if (run === loadRun) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (run === loadRun) {
			loading.value = false
		}
	}
}

onMounted(load)

const KINDS = ['ended', 'conflict', 'upcoming'] as const

const groups = computed(() => KINDS
	.map((kind) => ({ kind, rows: rows.value.filter((row) => row.kind === kind) }))
	.filter((group) => group.rows.length > 0))

/**
 * Heading of a group.
 *
 * @param kind - ended, conflict or upcoming
 */
function kindTitle(kind: string): string {
	switch (kind) {
	case 'ended':
		return t('cantoria', 'Marked protected, but public domain by the life dates')
	case 'conflict':
		return t('cantoria', 'Marked public domain, but the persons do not allow it')
	default:
		return t('cantoria', 'Protected with a known end')
	}
}

/**
 * Date of the proposal for a row.
 *
 * @param row - review row
 */
function dateText(row: ProtectionReviewRow): string {
	if (row.protection.freeSince) {
		return t('cantoria', 'public domain since {date}', { date: formatDate(row.protection.freeSince) })
	}
	if (row.protection.until) {
		return t('cantoria', 'protected until {date}', { date: formatDate(row.protection.until) })
	}
	return '–'
}
</script>

<template>
	<div class="protection-view">
		<ViewHeader :title="t('cantoria', 'Protection terms')"
			:description="t('cantoria', 'Resubmission by the life dates of the persons involved: protection ends at the end of the 70th year after the death of the last of them. A proposal only; it never changes the rights status and is no legal advice.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />
		<div class="protection-view__content">
			<ReloadError v-if="loaded && error" :error="error" @retry="load()" />
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error" :name="t('cantoria', 'The list could not be loaded')" :description="error">
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
				:name="t('cantoria', 'Nothing to resubmit')"
				:description="t('cantoria', 'No version needs a check by the life dates.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
				</template>
			</NcEmptyContent>
			<section v-for="group in groups"
				:key="group.kind"
				class="protection-view__group"
				:aria-labelledby="`protection-${group.kind}`">
				<h3 :id="`protection-${group.kind}`">
					{{ kindTitle(group.kind) }} ({{ group.rows.length }})
				</h3>
				<table class="protection-view__table">
					<caption class="hidden-visually">
						{{ n('cantoria', '%n version', '%n versions', group.rows.length) }}
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
								{{ t('cantoria', 'Rights status') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Proposal') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Reason') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in group.rows" :key="row.versionId">
							<td class="protection-view__shelfmarks">
								<button v-for="copy in row.copies"
									:key="copy.id"
									type="button"
									class="protection-view__shelfmark"
									:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: copy.shelfmark })"
									@click="openEntry(copy.id, 'rights')">
									{{ copy.shelfmark }}
								</button>
							</td>
							<td>
								{{ row.title }}
								<span class="protection-view__muted">{{ row.composer }}</span>
							</td>
							<td>
								{{ row.rightsStatus ? choiceLabel('version', 'rights_status', row.rightsStatus) : '–' }}
								<span v-if="row.confirmed" class="protection-view__muted">{{ t('cantoria', 'confirmed') }}</span>
							</td>
							<td>{{ dateText(row) }}</td>
							<td>{{ row.protection.reasonText }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
	</div>
</template>

<style scoped>
.protection-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(5 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.protection-view__group h3 {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1.2em;
	font-weight: bold;
}

.protection-view__table {
	width: 100%;
	border-collapse: collapse;
}

.protection-view__table th,
.protection-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	/* long words break only when they do not fit; headings stay whole */
	overflow-wrap: break-word;
}

.protection-view__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.protection-view__shelfmarks {
	white-space: nowrap;
}

.protection-view__shelfmark {
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

.protection-view__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.protection-view__muted {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

@media print {
	.protection-view__content {
		padding: 0;
	}
}
</style>
