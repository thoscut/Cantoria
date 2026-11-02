<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Inventory per archive binder: the copies in shelf order as a printable A4
 * list with columns to tick, and – for the librarian and operations – the
 * recording of the count (date counted, number of originals and copies).
 */
import type { CountDraft } from '../maintenance.ts'
import type { Inventory } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheckAll, mdiContentSaveOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchInventory, recordCounts } from '../api.ts'
import { focusAfterUpdate } from '../focus.ts'
import { formatDate, parseIsoDate, toIsoDate } from '../format.ts'
import { countPayload } from '../maintenance.ts'
import { choiceLabel, hasAnyRole, loadDetail, loadEntries, openEntry } from '../store.ts'

const canCount = computed(() => hasAnyRole(['librarian', 'operations']))

const inventory = ref<Inventory | null>(null)
const binder = ref<number | null>(null)
const showAll = ref(false)
/** The filter in words, for the header of the printout */
const printFilter = computed(() => showAll.value ? t('cantoria', 'Also copies that are not active') : '')
const loading = ref(false)
const error = ref('')

const drafts = reactive<Record<number, CountDraft>>({})

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		inventory.value = await fetchInventory(binder.value, showAll.value)
		binder.value = inventory.value.binder
		for (const key of Object.keys(drafts)) {
			delete drafts[Number(key)]
		}
		for (const row of inventory.value.rows) {
			drafts[row.copyId] = { counted: false, originals: '', copies: '' }
		}
		saveError.value = ''
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const binderOptions = computed(() => (inventory.value?.binders ?? []).map((entry) => ({
	id: entry.binder,
	label: entry.uncounted > 0
		? t('cantoria', 'Binder {binder} ({active} active, {uncounted} never counted)', { binder: entry.binder, active: entry.active, uncounted: entry.uncounted })
		: t('cantoria', 'Binder {binder} ({active} active)', { binder: entry.binder, active: entry.active }),
})))

const binderOption = computed({
	get: () => binderOptions.value.find((option) => option.id === binder.value) ?? null,
	set: (option: { id: number } | null) => {
		if (option && option.id !== binder.value) {
			binder.value = option.id
			load()
		}
	},
})

const binderFacts = computed(() => inventory.value?.binders.find((entry) => entry.binder === binder.value) ?? null)

const printedOn = formatDate(toIsoDate(new Date()))

// ---- counting ----

const countDate = ref<Date | null>(new Date())
const saving = ref(false)
const saveError = ref('')

const payload = computed(() => countPayload(inventory.value?.rows ?? [], drafts))

const countedCount = computed(() => Object.values(drafts).filter((draft) => draft.counted).length)

function markAll(): void {
	for (const draft of Object.values(drafts)) {
		draft.counted = true
	}
}

/**
 * The figure for a copy was typed: typing means the copy was counted.
 *
 * @param copyId - copy id
 * @param key - originals or copies
 * @param value - typed value
 */
function onFigure(copyId: number, key: 'originals' | 'copies', value: string): void {
	const draft = drafts[copyId]
	if (!draft) {
		return
	}
	draft[key] = value
	if (value.trim() !== '') {
		draft.counted = true
	}
}

/**
 * @param copyId - copy id
 */
function isInvalid(copyId: number): boolean {
	return payload.value.invalid.includes(copyId)
}

async function save(): Promise<void> {
	saveError.value = ''
	if (payload.value.invalid.length > 0) {
		// Name the copies: the red frame alone is not enough (and not seen by everyone)
		const shelfmarks = (inventory.value?.rows ?? []).filter((row) => isInvalid(row.copyId)).map((row) => row.shelfmark)
		saveError.value = n('cantoria', 'Please enter whole numbers (0 or more) for %n copy: {shelfmarks}.', 'Please enter whole numbers (0 or more) for %n copies: {shelfmarks}.',
			shelfmarks.length, { shelfmarks: shelfmarks.join(', ') })
		return
	}
	if (payload.value.counts.length === 0) {
		saveError.value = t('cantoria', 'Please tick the copies that have been counted.')
		return
	}
	const date = countDate.value ? toIsoDate(countDate.value) : null
	if (date !== null && date > toIsoDate(new Date())) {
		saveError.value = t('cantoria', 'The date of the count must not lie in the future.')
		return
	}
	saving.value = true
	try {
		const recorded = await recordCounts(payload.value.counts, date)
		showSuccess(n('cantoria', 'The count of %n copy has been recorded.', 'The count of %n copies has been recorded.', recorded))
		await Promise.all([load(), loadEntries(), loadDetail()])
		// The button is disabled now (nothing ticked): on to the next binder
		await focusAfterUpdate(() => document.querySelector('.inventory-view input[role="combobox"], #app-content-vue input[role="combobox"]'))
	} catch (caught) {
		saveError.value = errorMessage(caught)
	} finally {
		saving.value = false
	}
}

/**
 * Last count as text.
 *
 * @param value - ISO date or null
 */
function lastCounted(value: string | null): string {
	return value ? formatDate(value) : t('cantoria', 'never')
}

/**
 * Count of a copy as listed, or a dash.
 *
 * @param value - number or null
 */
function figure(value: number | null): string {
	return value === null ? '–' : String(value)
}

const today = parseIsoDate(toIsoDate(new Date()))
</script>

<template>
	<div class="inventory-view">
		<ViewHeader :title="binder !== null ? t('cantoria', 'Inventory of binder {binder}', { binder }) : t('cantoria', 'Inventory')"
			:description="t('cantoria', 'The copies of one archive binder in shelf order, to tick off on paper. As of {date}.', { date: printedOn })"
			printable
			:print-filter="printFilter"
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="inventory-view__content">
			<NcEmptyContent v-if="!inventory && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!inventory && error" :name="t('cantoria', 'The list could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="inventory">
				<div class="inventory-view__controls no-print">
					<NcSelect v-model="binderOption"
						class="inventory-view__binder"
						:input-label="t('cantoria', 'Archive binder')"
						:options="binderOptions"
						:clearable="false" />
					<NcCheckboxRadioSwitch v-model="showAll" type="switch" @update:model-value="load">
						{{ t('cantoria', 'Also copies that are not active') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcNoteCard v-if="error"
					type="error"
					role="alert"
					class="no-print">
					{{ error }}
				</NcNoteCard>
				<p v-if="binderFacts" class="inventory-view__facts">
					{{ n('cantoria', '%n active copy', '%n active copies', binderFacts.active) }}
					<template v-if="binderFacts.oldestCount">
						· {{ t('cantoria', 'oldest count: {date}', { date: formatDate(binderFacts.oldestCount) }) }}
					</template>
					<template v-if="binderFacts.uncounted > 0">
						· {{ n('cantoria', '%n copy never counted', '%n copies never counted', binderFacts.uncounted) }}
					</template>
				</p>

				<p v-if="inventory.rows.length === 0" class="inventory-view__muted">
					{{ t('cantoria', 'This binder has no copies to list.') }}
				</p>
				<form v-else class="inventory-view__form" @submit.prevent="save">
					<table class="inventory-view__table">
						<caption class="hidden-visually">
							{{ t('cantoria', 'Inventory of binder {binder}', { binder: inventory.binder }) }}
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
									{{ t('cantoria', 'Kind') }}
								</th>
								<th scope="col" class="inventory-view__number">
									{{ t('cantoria', 'Number of originals') }}
								</th>
								<th scope="col" class="inventory-view__number">
									{{ t('cantoria', 'Number of copies') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Last counted') }}
								</th>
								<template v-if="canCount">
									<th scope="col" class="no-print">
										{{ t('cantoria', 'Counted') }}
									</th>
									<th scope="col" class="no-print">
										{{ t('cantoria', 'Originals now') }}
									</th>
									<th scope="col" class="no-print">
										{{ t('cantoria', 'Copies now') }}
									</th>
								</template>
								<th scope="col" class="print-only inventory-view__tick">
									{{ t('cantoria', 'There') }}
								</th>
								<th scope="col" class="print-only inventory-view__write">
									{{ t('cantoria', 'Originals now') }}
								</th>
								<th scope="col" class="print-only inventory-view__write">
									{{ t('cantoria', 'Copies now') }}
								</th>
								<th scope="col" class="print-only inventory-view__note">
									{{ t('cantoria', 'Note') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in inventory.rows"
								:key="row.copyId"
								:class="{ 'inventory-view__row--inactive': row.status !== 'active', 'inventory-view__row--invalid': isInvalid(row.copyId) }">
								<th scope="row">
									<button type="button"
										class="inventory-view__shelfmark"
										:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: row.shelfmark })"
										@click="openEntry(row.copyId)">
										{{ row.shelfmark }}
									</button>
								</th>
								<td>
									<span class="inventory-view__title">{{ row.title }}</span>
									<span v-if="row.composer || row.arranger_text" class="inventory-view__muted">
										{{ [row.composer, row.arranger_text].filter(Boolean).join(' · ') }}
									</span>
									<span v-if="row.status !== 'active'" class="inventory-view__status">
										{{ choiceLabel('copy', 'status', row.status) }}<template v-if="row.status_reason">: {{ row.status_reason }}</template>
									</span>
									<span v-if="row.preservation_copy" class="inventory-view__status">
										{{ t('cantoria', 'Internal preservation copy') }}
									</span>
								</td>
								<td>
									{{ row.kind ? choiceLabel('copy', 'kind', row.kind) : '' }}
									<span v-if="row.pages" class="inventory-view__muted inventory-view__pages">{{ t('cantoria', '{pages} p.', { pages: row.pages }) }}</span>
								</td>
								<td class="inventory-view__number">
									{{ figure(row.originals_count) }}
								</td>
								<td class="inventory-view__number">
									{{ figure(row.copies_count) }}
								</td>
								<td>{{ lastCounted(row.last_counted) }}</td>
								<template v-if="canCount && drafts[row.copyId]">
									<td class="no-print">
										<input v-model="drafts[row.copyId].counted"
											type="checkbox"
											class="inventory-view__check"
											:aria-label="t('cantoria', '{shelfmark} counted', { shelfmark: row.shelfmark })">
									</td>
									<td class="no-print">
										<input :value="drafts[row.copyId].originals"
											class="inventory-view__input"
											inputmode="numeric"
											:placeholder="figure(row.originals_count)"
											:aria-label="t('cantoria', 'Originals of {shelfmark} now', { shelfmark: row.shelfmark })"
											:aria-invalid="isInvalid(row.copyId) ? 'true' : undefined"
											@input="onFigure(row.copyId, 'originals', ($event.target as HTMLInputElement).value)">
									</td>
									<td class="no-print">
										<input :value="drafts[row.copyId].copies"
											class="inventory-view__input"
											inputmode="numeric"
											:placeholder="figure(row.copies_count)"
											:aria-label="t('cantoria', 'Copies of {shelfmark} now', { shelfmark: row.shelfmark })"
											:aria-invalid="isInvalid(row.copyId) ? 'true' : undefined"
											@input="onFigure(row.copyId, 'copies', ($event.target as HTMLInputElement).value)">
									</td>
								</template>
								<td class="print-only inventory-view__tick">
									☐
								</td>
								<td class="print-only" />
								<td class="print-only" />
								<td class="print-only" />
							</tr>
						</tbody>
					</table>

					<div v-if="canCount" class="inventory-view__save no-print">
						<p class="inventory-view__muted">
							{{ t('cantoria', 'Tick the copies that are there. Enter a figure only when it differs; an empty field keeps the listed one.') }}
						</p>
						<div class="inventory-view__save-row">
							<NcButton variant="tertiary" @click="markAll">
								<template #icon>
									<NcIconSvgWrapper :path="mdiCheckAll" />
								</template>
								{{ t('cantoria', 'Tick all') }}
							</NcButton>
							<NcDateTimePickerNative v-model="countDate"
								class="inventory-view__date"
								type="date"
								:max="today ?? undefined"
								:label="t('cantoria', 'Date of the count')" />
							<NcButton type="submit" variant="primary" :disabled="saving || countedCount === 0">
								<template #icon>
									<NcLoadingIcon v-if="saving" />
									<NcIconSvgWrapper v-else :path="mdiContentSaveOutline" />
								</template>
								{{ n('cantoria', 'Record count of %n copy', 'Record count of %n copies', countedCount) }}
							</NcButton>
						</div>
						<NcNoteCard v-if="saveError" type="error" role="alert">
							{{ saveError }}
						</NcNoteCard>
					</div>
				</form>
			</template>
		</div>
	</div>
</template>

<style scoped>
.inventory-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.inventory-view__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
}

/* room for "Binder 12 (40 active, 40 never counted)" without cutting it;
   .nc-select sets min-width: 260px with three classes, one more wins */
.inventory-view__controls .inventory-view__binder.nc-select {
	min-width: min(100%, 27em);
}

.inventory-view__facts {
	margin: 0;
}

.inventory-view__form {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	overflow-x: auto;
}

.inventory-view__table {
	width: 100%;
	border-collapse: collapse;
}

.inventory-view__table th,
.inventory-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.inventory-view__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	white-space: nowrap;
}

.inventory-view__table td:nth-child(2) {
	min-width: 12em;
	overflow-wrap: anywhere;
}

.inventory-view__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.inventory-view__shelfmark {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-decoration: underline;
	white-space: nowrap;
	cursor: pointer;
}

.inventory-view__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.inventory-view__title {
	display: block;
	font-weight: bold;
}

.inventory-view__muted {
	display: block;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.inventory-view__pages {
	white-space: nowrap;
}

.inventory-view__status {
	display: block;
	font-size: var(--font-size-small, 13px);
	font-style: italic;
}

.inventory-view__row--inactive .inventory-view__title {
	text-decoration: line-through;
}

.inventory-view__check {
	width: 24px;
	height: 24px;
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	cursor: pointer;
}

.inventory-view__input {
	width: 5em;
	min-height: var(--default-clickable-area);
	margin: 0;
}

.inventory-view__row--invalid .inventory-view__input {
	border-color: var(--color-error);
	outline: 2px solid var(--color-error);
}

.inventory-view__save {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.inventory-view__save-row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.inventory-view__date {
	max-width: 14em;
}

.print-only {
	display: none;
}

@media print {
	.inventory-view__content {
		padding: 0;
	}

	.inventory-view__form {
		overflow: visible;
	}

	.print-only {
		display: table-cell;
	}

	.inventory-view__table {
		font-size: 9.5pt;
	}

	.inventory-view__table th,
	.inventory-view__table td {
		padding: 1.2mm 1mm;
		border: 1px solid #000;
	}

	/* All columns fit the width of A4: the headings may wrap and the title
	   column may get narrower than on screen. A wider table loses its last
	   columns in print (Firefox cuts off two, Chromium squeezes the note). */
	.inventory-view__table thead th {
		white-space: normal;
	}

	.inventory-view__table td:nth-child(2) {
		min-width: 0;
	}

	.inventory-view__table tr {
		break-inside: avoid;
	}

	.inventory-view__table thead {
		display: table-header-group;
	}

	.inventory-view__tick {
		width: 9mm;
		text-align: center !important;
	}

	/* the box to tick is larger; the heading keeps the size of the others */
	td.inventory-view__tick {
		font-size: 13pt;
	}

	.inventory-view__write {
		width: 15mm;
	}

	.inventory-view__note {
		width: 25mm;
	}

	.inventory-view__shelfmark {
		text-decoration: none;
	}
}
</style>
