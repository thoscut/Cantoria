<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * New accessions and shelf labels. The librarian records a new piece in one
 * step (work, version and copy) under the proposed shelf mark – numbers are
 * never reused – and prints its shelf label. Everybody can print labels for
 * existing copies.
 */
import type { DraftValue } from './FieldInput.vue'
import type { AccessionInput, AccessionProposal, AccessionResult, FieldInfo, Level, ShelfLabel } from '../types.ts'

import { mdiCheckCircleOutline, mdiClose, mdiFileLinkOutline, mdiLabelOutline, mdiPlus } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, reactive, ref, useTemplateRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FieldInput from './FieldInput.vue'
import LabelSheet from './LabelSheet.vue'
import ViewHeader from './ViewHeader.vue'
import { createAccession, errorMessage, fetchLabels, fetchNextShelfmark, saveOpenBinders } from '../api.ts'
import { canonicalShelfmark } from '../catalog.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { toIsoDate, translateLabel } from '../format.ts'
import { parseShelfmarkList, similarEntries } from '../maintenance.ts'
import { entries, fieldInfo, isLibrarian, loadEntries, openEntry } from '../store.ts'
import { showError } from '../toasts.ts'

// ---- shelf mark proposal and open binders ----

const proposal = ref<AccessionProposal | null>(null)
const proposalError = ref('')
const proposalLoading = ref(false)

/**
 * Load the proposal for a binder.
 *
 * @param binder - binder, or null for the default
 */
async function loadProposal(binder: number | null): Promise<void> {
	proposalLoading.value = true
	proposalError.value = ''
	try {
		proposal.value = await fetchNextShelfmark(binder)
	} catch (caught) {
		proposalError.value = errorMessage(caught)
	} finally {
		proposalLoading.value = false
	}
}

/** Binders that can be opened: the existing ones and the next new one. */
const binderChoices = computed(() => {
	const known = (proposal.value?.binders ?? []).map((binder) => binder.binder)
	const highest = Math.max(0, ...known, ...(proposal.value?.openBinders ?? []))
	return [...new Set([...known, ...(proposal.value?.openBinders ?? []), highest + 1])].sort((a, b) => a - b)
})

/** Open binders as chosen (strings: the checkboxes work on strings). */
const openDraft = ref<string[]>([])
const openBusy = ref(false)
const openError = ref('')

watch(() => proposal.value?.openBinders, (open) => {
	openDraft.value = (open ?? []).map(String)
}, { immediate: true })

const openChosen = computed(() => openDraft.value.map((value) => Number.parseInt(value, 10)).sort((a, b) => a - b))

const openChanged = computed(() => openChosen.value.join(',')
	!== [...(proposal.value?.openBinders ?? [])].sort((a, b) => a - b).join(','))

/**
 * Next number of a binder (one above the highest ever used).
 *
 * @param binder - binder number
 */
function nextNumber(binder: number): number {
	return proposal.value?.binders.find((entry) => entry.binder === binder)?.next ?? 1
}

async function saveOpen(): Promise<void> {
	if (openDraft.value.length === 0) {
		openError.value = t('cantoria', 'At least one binder has to stay open.')
		return
	}
	openBusy.value = true
	openError.value = ''
	try {
		await saveOpenBinders(openChosen.value)
		showSuccess(t('cantoria', 'The open binders have been saved.'))
		await loadProposal(null)
		resetShelfmark()
	} catch (caught) {
		openError.value = errorMessage(caught)
	} finally {
		openBusy.value = false
	}
}

// ---- the form ----

const today = toIsoDate(new Date())

interface FormField {
	key: keyof AccessionInput
	level: Level
	/** key in the field directory, if it differs */
	fieldKey?: string
	required?: boolean
	/** label if the field directory has none */
	label?: string
	type?: FieldInfo['type']
	hint?: string
}

const MAIN_FIELDS: FormField[] = [
	{ key: 'title', level: 'work', required: true },
	{ key: 'composer', level: 'work', required: true },
	{ key: 'title_source', level: 'copy', required: true },
	{ key: 'accession_date', level: 'copy', required: true, label: 'Accession date', type: 'date' },
	{ key: 'origin', level: 'copy', required: true, label: 'Origin', type: 'text' },
]

const MORE_FIELDS: FormField[] = [
	{ key: 'arranger_text', level: 'version' },
	{ key: 'publisher', level: 'version' },
	{ key: 'rights_status', level: 'version' },
	{ key: 'version_kind', level: 'version', fieldKey: 'kind' },
	{ key: 'kind', level: 'copy' },
	{ key: 'pages', level: 'copy' },
	{ key: 'originals_count', level: 'copy' },
	{ key: 'copies_count', level: 'copy' },
	{ key: 'notes', level: 'version', label: 'Notes', type: 'longtext' },
]

/**
 * Field info of a form field: from the field directory, or built here.
 *
 * @param field - form field
 */
function infoOf(field: FormField): FieldInfo {
	const known = field.type === undefined ? fieldInfo(field.level, field.fieldKey ?? field.key) : undefined
	return known ?? { type: field.type ?? 'text', label: field.label ?? field.key, write: ['librarian'], max: field.type === 'longtext' ? 10000 : 1000 }
}

/**
 * Translated label of a form field, marked when required.
 *
 * @param field - form field
 */
function labelOf(field: FormField): string {
	const label = translateLabel(infoOf(field).label)
	return field.required ? t('cantoria', '{label} (required)', { label }) : label
}

/**
 * Hint below a form field.
 *
 * @param field - form field
 */
function hintOf(field: FormField): string {
	switch (field.key) {
	case 'title_source':
		return t('cantoria', 'Where the title comes from, e.g. title page or first page of music.')
	case 'origin':
		return t('cantoria', 'From whom or where the piece comes, e.g. donation of a choir member or bought from a publisher.')
	case 'notes':
		return t('cantoria', 'Accession date and origin are added to the notes of the version automatically.')
	default:
		return ''
	}
}

/**
 * Empty form; rights status "open" until confirmed.
 */
function emptyDraft(): Record<string, DraftValue> {
	const values: Record<string, DraftValue> = {}
	for (const field of [...MAIN_FIELDS, ...MORE_FIELDS]) {
		values[field.key] = infoOf(field).type === 'choice' ? null : ''
	}
	values.accession_date = today
	values.rights_status = 'open'
	return values
}

const draft = reactive<Record<string, DraftValue>>(emptyDraft())
const errors = reactive<Record<string, string>>({})
const binder = ref<number | null>(null)
const numberText = ref('')
const file = ref<{ id: number, name: string } | null>(null)
const busy = ref(false)
const error = ref('')
const result = ref<AccessionResult | null>(null)
const showMore = ref(false)

/** Copies that may be this piece already (a hint against recording it twice) */
const similar = computed(() => similarEntries(entries.value,
	typeof draft.title === 'string' ? draft.title : '', typeof draft.composer === 'string' ? draft.composer : ''))

/** Binder and number of the proposal. */
function resetShelfmark(): void {
	binder.value = proposal.value?.proposal.binder ?? null
	numberText.value = proposal.value ? String(proposal.value.proposal.number) : ''
}

const binderOptions = computed(() => (proposal.value?.openBinders ?? []).map((value) => ({
	id: value,
	label: t('cantoria', 'Binder {binder}', { binder: value }),
})))

const binderOption = computed({
	get: () => binderOptions.value.find((option) => option.id === binder.value) ?? null,
	set: (option: { id: number } | null) => {
		binder.value = option?.id ?? null
		numberText.value = option ? String(nextNumber(option.id)) : ''
	},
})

const shelfmarkPreview = computed(() => (binder.value !== null && /^\d{1,4}$/.test(numberText.value.trim())
	? `${binder.value}-${Number.parseInt(numberText.value, 10)}`
	: ''))

/** Check the form before sending; marks the fields with errors. */
function validate(): boolean {
	for (const key of Object.keys(errors)) {
		delete errors[key]
	}
	for (const field of MAIN_FIELDS) {
		const value = draft[field.key]
		if (typeof value !== 'string' || value.trim() === '') {
			errors[field.key] = t('cantoria', 'Please fill in this field.')
		}
	}
	if (typeof draft.accession_date === 'string' && draft.accession_date > today) {
		errors.accession_date = t('cantoria', 'The accession date must not lie in the future.')
	}
	if (binder.value === null) {
		errors.binder = t('cantoria', 'Please choose a binder.')
	}
	const number = numberText.value.trim()
	if (!/^\d{1,4}$/.test(number) || Number.parseInt(number, 10) < 1) {
		errors.number = t('cantoria', 'Please enter a whole number.')
	} else if (binder.value !== null && Number.parseInt(number, 10) < nextNumber(binder.value)) {
		errors.number = t('cantoria', 'Numbers are never reused: the next free number in binder {binder} is {number}.', { binder: binder.value, number: nextNumber(binder.value) })
	}
	for (const key of ['originals_count', 'copies_count']) {
		const value = draft[key]
		if (typeof value === 'string' && value.trim() !== '' && !/^\d{1,6}$/.test(value.trim())) {
			errors[key] = t('cantoria', 'Please enter a whole number.')
		}
	}
	return Object.keys(errors).length === 0
}

/** The form as request body: empty values are left out. */
function input(): AccessionInput {
	const text = (key: string) => {
		const value = draft[key]
		return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
	}
	const count = (key: string) => {
		const value = text(key)
		return value === null ? null : Number.parseInt(value, 10)
	}
	const body: AccessionInput = {
		title: text('title') ?? '',
		composer: text('composer') ?? '',
		title_source: text('title_source') ?? '',
		accession_date: text('accession_date') ?? '',
		origin: text('origin') ?? '',
		binder: binder.value,
		number: Number.parseInt(numberText.value, 10),
	}
	const optional: Partial<AccessionInput> = {
		arranger_text: text('arranger_text'),
		publisher: text('publisher'),
		rights_status: text('rights_status'),
		version_kind: text('version_kind'),
		kind: text('kind'),
		pages: text('pages'),
		originals_count: count('originals_count'),
		copies_count: count('copies_count'),
		notes: text('notes'),
	}
	for (const [key, value] of Object.entries(optional)) {
		if (value !== null && value !== undefined) {
			(body as unknown as Record<string, unknown>)[key] = value
		}
	}
	return body
}

/** Where the focus goes after actions (WCAG 2.4.3, 3.3.1) */
const form = useTemplateRef<HTMLFormElement>('form')
const fileButton = useTemplateRef('fileButton')
const resultNote = useTemplateRef('resultNote')

/** Focus the first field with an error. */
function focusFirstError(): void {
	focusAfterUpdate(() => form.value?.querySelector('[aria-invalid="true"], .input-field--error input'))
}

/** Remove the chosen file; the button to choose one gets the focus. */
function unlinkFile(): void {
	file.value = null
	focusAfterUpdate(fileButton)
}

async function chooseFile(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose a file in the archive folder'))
			.setMultiSelect(false)
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (caught) {
		if (!(caught instanceof FilePickerClosed)) {
			showError(errorMessage(caught))
		}
		await focusAfterDialog(fileButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('cantoria', 'The chosen file cannot be used.'))
	} else {
		file.value = { id: node.fileid, name: node.displayname || node.basename }
	}
	await focusAfterDialog(fileButton)
}

async function submit(): Promise<void> {
	error.value = ''
	if (!validate()) {
		error.value = t('cantoria', 'Please check the marked fields.')
		focusFirstError()
		return
	}
	busy.value = true
	try {
		result.value = await createAccession(input(), file.value?.id ?? null)
		addLabels([result.value.label])
		showSuccess(t('cantoria', '{shelfmark} has been recorded.', { shelfmark: result.value.shelfmark }))
		// The form is replaced by the result: its message gets the focus
		await focusAfterUpdate(resultNote)
		await loadEntries()
		await loadProposal(binder.value)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

/** Empty form for the next piece; binder stays, number is proposed again. */
function another(): void {
	result.value = null
	Object.assign(draft, emptyDraft())
	file.value = null
	error.value = ''
	const keep = binder.value
	resetShelfmark()
	if (keep !== null && proposal.value?.openBinders.includes(keep)) {
		binder.value = keep
		numberText.value = String(nextNumber(keep))
	}
	// The form is back: its first field gets the focus
	focusAfterUpdate(() => form.value?.querySelector('input'))
}

// ---- labels ----

const labels = ref<ShelfLabel[]>([])
const labelInput = ref('')
const labelBusy = ref(false)
const labelError = ref('')

/**
 * Add labels to the sheet (each copy once).
 *
 * @param added - label data
 */
function addLabels(added: ShelfLabel[]): void {
	const known = new Set(labels.value.map((label) => label.copyId))
	labels.value = [...labels.value, ...added.filter((label) => !known.has(label.copyId))]
}

async function addTypedLabels(): Promise<void> {
	labelError.value = ''
	const { shelfmarks, invalid } = parseShelfmarkList(labelInput.value)
	const byShelfmark = new Map(entries.value.map((entry) => [canonicalShelfmark(entry.shelfmark), entry.id]))
	const ids: number[] = []
	const unknown: string[] = [...invalid]
	for (const shelfmark of shelfmarks) {
		const id = byShelfmark.get(shelfmark)
		if (id === undefined) {
			unknown.push(shelfmark)
		} else {
			ids.push(id)
		}
	}
	if (ids.length === 0) {
		labelError.value = unknown.length > 0
			? t('cantoria', 'Not in the catalog: {list}', { list: unknown.join(', ') })
			: t('cantoria', 'Please enter shelf marks.')
		return
	}
	labelBusy.value = true
	try {
		const fetched = await fetchLabels(ids)
		// keep the order typed
		const order = new Map(ids.map((id, index) => [id, index]))
		addLabels(fetched.sort((a, b) => (order.get(a.copyId) ?? 0) - (order.get(b.copyId) ?? 0)))
		labelInput.value = ''
		if (unknown.length > 0) {
			labelError.value = t('cantoria', 'Not in the catalog: {list}', { list: unknown.join(', ') })
		}
	} catch (caught) {
		labelError.value = errorMessage(caught)
	} finally {
		labelBusy.value = false
	}
}

/**
 * @param copyId - copy whose label is removed from the sheet
 */
function removeLabel(copyId: number): void {
	labels.value = labels.value.filter((label) => label.copyId !== copyId)
}

onMounted(async () => {
	await loadProposal(null)
	resetShelfmark()
})
</script>

<template>
	<div class="accession-view">
		<ViewHeader :title="t('cantoria', 'New accession and labels')"
			:description="t('cantoria', 'A new piece gets the next free shelf mark; numbers are never reused, not even those of retired copies. Shelf labels can be printed for any copy.')" />

		<div class="accession-view__content">
			<NcNoteCard v-if="proposalError" type="error" role="alert">
				{{ proposalError }}
			</NcNoteCard>

			<!-- Form (librarian) -->
			<section v-if="isLibrarian" class="accession-view__section" aria-labelledby="accession-form">
				<h3 id="accession-form">
					{{ t('cantoria', 'Record a new piece') }}
				</h3>

				<div v-if="result" class="accession-view__result">
					<NcNoteCard ref="resultNote" type="success">
						<p>{{ t('cantoria', '{shelfmark} has been recorded: work, version and copy.', { shelfmark: result.shelfmark }) }}</p>
						<p>{{ t('cantoria', 'Its label is on the sheet below. Next: confirm the rights and add the persons involved in the rights tab.') }}</p>
					</NcNoteCard>
					<div class="accession-view__actions">
						<NcButton variant="primary" @click="openEntry(result.copyId, 'rights')">
							{{ t('cantoria', 'Open {shelfmark}', { shelfmark: result.shelfmark }) }}
						</NcButton>
						<NcButton @click="another">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPlus" />
							</template>
							{{ t('cantoria', 'Record another piece') }}
						</NcButton>
					</div>
				</div>

				<form v-else
					ref="form"
					class="accession-view__form"
					novalidate
					@submit.prevent="submit">
					<div class="accession-view__shelfmark">
						<NcSelect v-model="binderOption"
							:input-label="t('cantoria', 'Binder (required)')"
							:options="binderOptions"
							:clearable="false"
							:loading="proposalLoading"
							:error="!!errors.binder"
							:helper-text="errors.binder ?? ''" />
						<NcTextField v-model="numberText"
							:label="t('cantoria', 'Number (required)')"
							inputmode="numeric"
							:aria-invalid="errors.number ? 'true' : undefined"
							:error="!!errors.number"
							:helper-text="errors.number ?? (binder !== null ? t('cantoria', 'Next free number: {number}', { number: nextNumber(binder) }) : '')" />
						<p class="accession-view__preview" aria-live="polite">
							<span class="accession-view__muted">{{ t('cantoria', 'Shelf mark') }}</span>
							<strong>{{ shelfmarkPreview || '–' }}</strong>
						</p>
					</div>

					<div class="accession-view__fields">
						<FieldInput v-for="field in MAIN_FIELDS"
							:key="field.key"
							v-model="draft[field.key]"
							:class="{ 'accession-view__wide': field.key === 'title' || field.key === 'origin' }"
							:info="infoOf(field)"
							:label="labelOf(field)"
							:error="errors[field.key] ?? ''"
							:hint="hintOf(field)" />
					</div>

					<NcNoteCard v-if="similar.length > 0" type="info" class="accession-view__similar">
						<p>{{ t('cantoria', 'Possibly in the catalog already. If it is the same piece, a further copy or version may be the better way:') }}</p>
						<ul>
							<li v-for="entry in similar" :key="entry.id">
								<NcButton variant="tertiary-no-background" @click="openEntry(entry.id)">
									{{ entry.shelfmark }} · {{ entry.title }}<template v-if="entry.composer">
										· {{ entry.composer }}
									</template>
								</NcButton>
							</li>
						</ul>
					</NcNoteCard>

					<NcCheckboxRadioSwitch v-model="showMore" type="switch">
						{{ t('cantoria', 'More details (arranger, publisher, rights, counts)') }}
					</NcCheckboxRadioSwitch>
					<div v-if="showMore" class="accession-view__fields">
						<FieldInput v-for="field in MORE_FIELDS"
							:key="field.key"
							v-model="draft[field.key]"
							:class="{ 'accession-view__wide': field.key === 'notes' }"
							:info="infoOf(field)"
							:label="labelOf(field)"
							:error="errors[field.key] ?? ''"
							:hint="hintOf(field)"
							:clearable="field.key !== 'rights_status'" />
					</div>

					<div class="accession-view__file">
						<NcButton ref="fileButton" @click="chooseFile">
							<template #icon>
								<NcIconSvgWrapper :path="mdiFileLinkOutline" />
							</template>
							{{ file ? t('cantoria', 'Choose another file …') : t('cantoria', 'Link a file (optional) …') }}
						</NcButton>
						<span v-if="file" class="accession-view__file-name">
							{{ file.name }}
							<NcButton variant="tertiary"
								:aria-label="t('cantoria', 'Do not link {name}', { name: file.name })"
								@click="unlinkFile">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</span>
						<p class="accession-view__muted">
							{{ t('cantoria', 'Only a file inside the archive folder can be linked. It stays linked to this copy, whatever its name.') }}
						</p>
					</div>

					<NcNoteCard v-if="error" type="error" role="alert">
						{{ error }}
					</NcNoteCard>
					<div class="accession-view__actions">
						<NcButton type="submit" variant="primary" :disabled="busy || proposal === null">
							<template #icon>
								<NcLoadingIcon v-if="busy" />
								<NcIconSvgWrapper v-else :path="mdiCheckCircleOutline" />
							</template>
							{{ t('cantoria', 'Record accession') }}
						</NcButton>
					</div>
				</form>
			</section>

			<!-- Open binders (librarian) -->
			<section v-if="isLibrarian && proposal" class="accession-view__section" aria-labelledby="accession-binders">
				<h3 id="accession-binders">
					{{ t('cantoria', 'Binders open for new copies') }}
				</h3>
				<p class="accession-view__muted">
					{{ t('cantoria', 'New copies go only into open binders. Open a new binder when the current one is full.') }}
				</p>
				<fieldset class="accession-view__binders">
					<legend class="hidden-visually">
						{{ t('cantoria', 'Binders open for new copies') }}
					</legend>
					<NcCheckboxRadioSwitch v-for="value in binderChoices"
						:key="value"
						v-model="openDraft"
						:value="String(value)">
						{{ nextNumber(value) > 1
							? t('cantoria', 'Binder {binder} (next number {number})', { binder: value, number: nextNumber(value) })
							: t('cantoria', 'Binder {binder} (new)', { binder: value }) }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<NcNoteCard v-if="openError" type="error" role="alert">
					{{ openError }}
				</NcNoteCard>
				<div class="accession-view__actions">
					<NcButton :disabled="openBusy || !openChanged" @click="saveOpen">
						<template v-if="openBusy" #icon>
							<NcLoadingIcon />
						</template>
						{{ t('cantoria', 'Save open binders') }}
					</NcButton>
				</div>
			</section>

			<!-- Labels -->
			<section class="accession-view__section" aria-labelledby="accession-labels">
				<h3 id="accession-labels">
					{{ t('cantoria', 'Shelf labels') }}
				</h3>
				<form class="accession-view__label-form" @submit.prevent="addTypedLabels">
					<NcTextArea v-model="labelInput"
						:label="t('cantoria', 'Shelf marks')"
						:helper-text="t('cantoria', 'Separated by commas or line breaks; 10-70..75 stands for 10-70 to 10-75.')"
						rows="2"
						resize="vertical" />
					<NcButton type="submit" :disabled="labelBusy || labelInput.trim() === ''">
						<template #icon>
							<NcLoadingIcon v-if="labelBusy" />
							<NcIconSvgWrapper v-else :path="mdiLabelOutline" />
						</template>
						{{ t('cantoria', 'Add labels') }}
					</NcButton>
				</form>
				<NcNoteCard v-if="labelError" type="warning" role="alert">
					{{ labelError }}
				</NcNoteCard>

				<template v-if="labels.length > 0">
					<ul class="accession-view__labels" :aria-label="n('cantoria', '%n label on the sheet', '%n labels on the sheet', labels.length)">
						<li v-for="label in labels" :key="label.copyId">
							<span class="accession-view__label-text">{{ label.label }} – {{ label.title }}</span>
							<NcButton variant="tertiary"
								:aria-label="t('cantoria', 'Remove the label of {shelfmark}', { shelfmark: label.shelfmark })"
								@click="removeLabel(label.copyId)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</li>
					</ul>
					<LabelSheet :labels="labels" />
				</template>
				<p v-else class="accession-view__muted">
					{{ t('cantoria', 'No labels yet. Recorded pieces are added automatically.') }}
				</p>
			</section>
		</div>
	</div>
</template>

<style scoped>
.accession-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	max-width: 60em;
	padding: calc(3 * var(--default-grid-baseline));
}

.accession-view__section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.accession-view__section + .accession-view__section {
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.accession-view__section h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.accession-view__form,
.accession-view__result {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.accession-view__shelfmark {
	display: grid;
	/* NcSelect is at least 260 px wide; narrower columns let it overlap the number */
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
	align-items: start;
	gap: calc(2 * var(--default-grid-baseline));
}

.accession-view__preview {
	display: flex;
	flex-direction: column;
	margin: 0;
	font-size: 1.4em;
}

.accession-view__preview .accession-view__muted {
	font-size: var(--font-size-small, 13px);
}

.accession-view__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 18em), 1fr));
	gap: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.accession-view__wide {
	grid-column: 1 / -1;
}

.accession-view__file {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
}

.accession-view__file p {
	flex-basis: 100%;
	margin: 0;
}

.accession-view__file-name {
	display: inline-flex;
	align-items: center;
	overflow-wrap: anywhere;
	font-weight: bold;
}

.accession-view__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.accession-view__binders {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(4 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	border: none;
}

.accession-view__label-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.accession-view__label-form > :first-child {
	flex: 1 1 20em;
}

.accession-view__labels {
	display: flex;
	flex-direction: column;
}

.accession-view__labels li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.accession-view__label-text {
	overflow-wrap: anywhere;
}

.accession-view__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.accession-view__similar ul {
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
