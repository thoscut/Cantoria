<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Inbox of proposals: changes suggested by tools and transcription rounds.
 * Nothing changes until the catalog role accepts a proposal. Shows the
 * current value next to the proposed one; the current values are read from
 * the copies the proposal belongs to.
 */
import type { Detail, Entry, Proposal } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheck, mdiClose, mdiHomeOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import EmptyArt from './EmptyArt.vue'
import ReloadError from './ReloadError.vue'
import ViewHeader from './ViewHeader.vue'
import { acceptProposal, errorMessage, fetchEntry, fetchProposals, rejectProposal } from '../api.ts'
import { displayValue, formatDateTime } from '../format.ts'
import { navigate } from '../navigation.ts'
import { entries, fieldInfo, fieldLabel, isLibrarian, loadDetail, loadEntries, openEntry } from '../store.ts'

const proposals = ref<Proposal[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

/** Details of copies, to show current values. */
const details = reactive(new Map<number, Detail | 'loading' | 'failed'>())

/** Counter of the loads: only the answer to the latest one counts (a filter switched quickly) */
let loadRun = 0

async function load(): Promise<void> {
	const run = ++loadRun
	loading.value = true
	error.value = ''
	try {
		const result = await fetchProposals()
		if (run !== loadRun) {
			return
		}
		proposals.value = result
		loaded.value = true
		details.clear()
		await loadCurrentValues()
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

/**
 * Copies a proposal belongs to.
 *
 * @param proposal - the proposal
 */
function copiesOf(proposal: Proposal): Entry[] {
	switch (proposal.object_type) {
	case 'work':
		return entries.value.filter((entry) => entry.work_id === proposal.object_id)
	case 'version':
		return entries.value.filter((entry) => entry.version_id === proposal.object_id)
	default:
		return entries.value.filter((entry) => entry.id === proposal.object_id)
	}
}

/** Load the detail of one copy per proposed object (four at a time). */
async function loadCurrentValues(): Promise<void> {
	const copyIds = [...new Set(proposals.value.map((proposal) => copiesOf(proposal)[0]?.id).filter((id): id is number => id !== undefined))]
	const queue = [...copyIds]
	const worker = async () => {
		while (queue.length > 0) {
			const id = queue.shift() as number
			details.set(id, 'loading')
			try {
				details.set(id, await fetchEntry(id))
			} catch {
				details.set(id, 'failed')
			}
		}
	}
	await Promise.all([worker(), worker(), worker(), worker()])
}

/**
 * Current value of the proposed field.
 *
 * @param proposal - the proposal
 */
function currentValue(proposal: Proposal): string {
	const copy = copiesOf(proposal)[0]
	const detail = copy ? details.get(copy.id) : undefined
	if (detail === undefined || detail === 'failed') {
		return t('cantoria', 'unknown')
	}
	if (detail === 'loading') {
		return '…'
	}
	return displayValue(fieldInfo(proposal.object_type, proposal.field), detail[proposal.object_type]?.[proposal.field]) || t('cantoria', '(empty)')
}

/**
 * Proposed value of a proposal.
 *
 * @param proposal - the proposal
 */
function proposedValue(proposal: Proposal): string {
	let value: unknown = null
	try {
		value = proposal.new_value === null ? null : JSON.parse(proposal.new_value)
	} catch {
		value = proposal.new_value
	}
	if (proposal.object_type === 'version' && proposal.field === 'portal_since' && value === null) {
		// from the comparison with the choir system (milestone 0.12)
		return t('cantoria', 'Removed from the singers\' portal')
	}
	const shown = typeof value === 'object' && value !== null ? JSON.stringify(value) : value as string | number | boolean | null
	return displayValue(fieldInfo(proposal.object_type, proposal.field), shown) || t('cantoria', '(empty)')
}

/**
 * Level of the proposal in words.
 *
 * @param proposal - the proposal
 */
function levelLabel(proposal: Proposal): string {
	switch (proposal.object_type) {
	case 'work':
		return t('cantoria', 'Work')
	case 'version':
		return t('cantoria', 'Version')
	default:
		return t('cantoria', 'Copy')
	}
}

/** Proposals grouped by the object they change. */
const groups = computed(() => {
	const map = new Map<string, { key: string, proposals: Proposal[], copies: Entry[], title: string }>()
	for (const proposal of proposals.value) {
		const key = `${proposal.object_type}:${proposal.object_id}`
		if (!map.has(key)) {
			const copies = copiesOf(proposal)
			map.set(key, { key, proposals: [], copies, title: copies[0]?.title ?? '' })
		}
		map.get(key)?.proposals.push(proposal)
	}
	const collator = new Intl.Collator(undefined, { numeric: true })
	return [...map.values()].sort((a, b) => collator.compare(a.copies[0]?.shelfmark ?? '~', b.copies[0]?.shelfmark ?? '~'))
})

// ---- decide ----

const deciding = ref<{ proposal: Proposal, accept: boolean } | null>(null)
const reason = ref('')
const busy = ref(false)
const decideError = ref('')

/**
 * @param proposal - the proposal
 * @param accept - accept or reject
 */
function startDecision(proposal: Proposal, accept: boolean): void {
	deciding.value = { proposal, accept }
	reason.value = ''
	decideError.value = ''
}

async function decide(): Promise<void> {
	const current = deciding.value
	if (!current) {
		return
	}
	busy.value = true
	decideError.value = ''
	try {
		const text = reason.value.trim() === '' ? null : reason.value.trim()
		if (current.accept) {
			await acceptProposal(current.proposal.id, text)
			showSuccess(t('cantoria', 'The proposal has been accepted and applied.'))
		} else {
			await rejectProposal(current.proposal.id, text)
			showSuccess(t('cantoria', 'The proposal has been rejected.'))
		}
		deciding.value = null
		await Promise.all([load(), loadEntries(), loadDetail()])
	} catch (caught) {
		decideError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="proposals-view">
		<ViewHeader :title="t('cantoria', 'Inbox')"
			:description="t('cantoria', 'Changes proposed by tools and transcription rounds. Nothing changes until the catalog role accepts a proposal.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="proposals-view__content">
			<ReloadError v-if="loaded && error" :error="error" @retry="load()" />
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The inbox could not be loaded')"
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
			<NcEmptyContent v-else-if="proposals.length === 0"
				:name="t('cantoria', 'The inbox is empty')"
				:description="t('cantoria', 'There are no open proposals. Tools and transcription rounds put their proposals here; nothing else to do now.')">
				<template #icon>
					<EmptyArt kind="tray" />
				</template>
				<template #action>
					<NcButton @click="navigate('due')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiHomeOutline" />
						</template>
						{{ t('cantoria', 'Open the start page') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<p class="proposals-view__count">
					{{ n('cantoria', '%n open proposal', '%n open proposals', proposals.length) }}
				</p>
				<section v-for="group in groups"
					:key="group.key"
					class="proposals-view__group"
					:aria-label="group.copies.map((copy) => copy.shelfmark).join(', ') || group.key">
					<h3 class="proposals-view__group-title">
						<template v-if="group.copies.length > 0">
							<button v-for="copy in group.copies"
								:key="copy.id"
								type="button"
								class="proposals-view__shelfmark"
								@click="openEntry(copy.id, 'rights')">
								{{ copy.shelfmark }}
							</button>
							<span>{{ group.title }}</span>
						</template>
						<span v-else>{{ t('cantoria', 'Unknown object ({key})', { key: group.key }) }}</span>
					</h3>
					<table class="proposals-view__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('cantoria', 'Field') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Now') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Proposed') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Source and reason') }}
								</th>
								<th v-if="isLibrarian" scope="col" class="no-print">
									<span class="hidden-visually">{{ t('cantoria', 'Actions') }}</span>
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="proposal in group.proposals" :key="proposal.id">
								<td>
									<span class="proposals-view__level">{{ levelLabel(proposal) }}</span>
									{{ fieldLabel(proposal.object_type, proposal.field) }}
								</td>
								<td class="proposals-view__old" :data-label="t('cantoria', 'Now')">
									{{ currentValue(proposal) }}
								</td>
								<td class="proposals-view__new" :data-label="t('cantoria', 'Proposed')">
									{{ proposedValue(proposal) }}
								</td>
								<td>
									<span v-if="proposal.source">{{ proposal.source }}</span>
									<span v-if="proposal.reason" class="proposals-view__reason">{{ proposal.reason }}</span>
									<span class="proposals-view__meta">{{ formatDateTime(proposal.created) }}<template v-if="proposal.created_by_name || proposal.created_by">, {{ proposal.created_by_name || proposal.created_by }}</template></span>
								</td>
								<td v-if="isLibrarian" class="proposals-view__actions no-print">
									<NcButton variant="primary"
										:aria-label="t('cantoria', 'Accept: {field}', { field: fieldLabel(proposal.object_type, proposal.field) })"
										@click="startDecision(proposal, true)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiCheck" />
										</template>
										{{ t('cantoria', 'Accept') }}
									</NcButton>
									<NcButton :aria-label="t('cantoria', 'Reject: {field}', { field: fieldLabel(proposal.object_type, proposal.field) })"
										@click="startDecision(proposal, false)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiClose" />
										</template>
										{{ t('cantoria', 'Reject') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>
		</div>

		<ActionDialog v-if="deciding !== null"
			:name="deciding.accept ? t('cantoria', 'Accept proposal') : t('cantoria', 'Reject proposal')"
			:submit-label="deciding.accept ? t('cantoria', 'Accept and apply') : t('cantoria', 'Reject')"
			:busy="busy"
			:error="decideError"
			@submit="decide"
			@close="deciding = null">
			<p>
				<strong>{{ fieldLabel(deciding.proposal.object_type, deciding.proposal.field) }}</strong>:
				{{ currentValue(deciding.proposal) }} → <strong>{{ proposedValue(deciding.proposal) }}</strong>
			</p>
			<p v-if="deciding.accept" class="action-dialog__hint">
				{{ t('cantoria', 'The value is changed with your rights and recorded in the audit log.') }}
			</p>
			<NcTextArea v-model="reason"
				:label="t('cantoria', 'Reason (optional)')"
				resize="vertical"
				rows="3" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.proposals-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.proposals-view__count {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.proposals-view__group-title {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0 calc(2 * var(--default-grid-baseline));
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1.1em;
	font-weight: bold;
}

.proposals-view__shelfmark {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.proposals-view__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.proposals-view__table {
	width: 100%;
	border-collapse: collapse;
}

.proposals-view__table th,
.proposals-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	/* long words break only when they do not fit; headings stay whole */
	overflow-wrap: break-word;
}

.proposals-view__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.proposals-view__level {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.proposals-view__old {
	text-decoration: line-through;
}

.proposals-view__new {
	font-weight: bold;
}

.proposals-view__reason,
.proposals-view__meta {
	display: block;
}

.proposals-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.proposals-view__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

@media (max-width: 700px) {
	.proposals-view__table thead {
		display: none;
	}

	.proposals-view__table,
	.proposals-view__table tbody,
	.proposals-view__table tr,
	.proposals-view__table td {
		display: block;
		width: 100%;
	}

	.proposals-view__table tr {
		padding-block: calc(2 * var(--default-grid-baseline));
		border-bottom: 1px solid var(--color-border);
	}

	.proposals-view__table td {
		border-bottom: none;
	}

	.proposals-view__table td[data-label]::before {
		content: attr(data-label) ": ";
		color: var(--color-text-maxcontrast);
		font-weight: normal;
		text-decoration: none;
	}
}
</style>
