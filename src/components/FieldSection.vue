<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * One section of fields of a work, version or copy, generated from the
 * field directory. Shows values; with "Edit" the fields the user may write
 * become inputs. Saving sends only the changed fields.
 */
import type { DataRecord, FieldInfo, FieldValue, Level } from '../types.ts'
import type { DraftValue } from './FieldInput.vue'

import { mdiPencilOutline } from '@mdi/js'
import { showInfo, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, reactive, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FieldInput from './FieldInput.vue'
import HelpTerm from './HelpTerm.vue'
import { errorMessage, updateFields } from '../api.ts'
import { displayValue, isEmpty, translateLabel } from '../format.ts'
import { focusAfterUpdate } from '../focus.ts'
import { FIELD_HELP, SECTION_HELP } from '../helpTerms.ts'
import { canWrite, fieldInfo } from '../store.ts'
import { showError } from '../toasts.ts'
import { useUnsavedGuard } from '../unsaved.ts'

const props = defineProps<{
	/** Unique id of the section, used for the heading id */
	sectionId: string
	level: Level
	heading: string
	record: DataRecord
	fieldKeys: string[]
}>()

const emit = defineEmits<{
	saved: []
}>()

/** The term the heading of this section explains, if any. */
const sectionHelp = computed(() => SECTION_HELP[props.sectionId])

const editing = ref(false)
const saving = ref(false)
const showEmpty = ref(false)
const reason = ref('')
const draft = reactive<Record<string, DraftValue>>({})
const errors = reactive<Record<string, string>>({})

/** The form has a value that differs from the saved one: leaving it asks first */
const dirty = computed(() => editing.value && fields.value.some(({ key, info }) => {
	if (!editableKeys.value.includes(key)) {
		return false
	}
	try {
		return comparable(info, fromDraft(info, draft[key])) !== comparable(info, props.record[key])
	} catch {
		// not valid yet, but typed
		return true
	}
}))
useUnsavedGuard(dirty)

/** "Edit" button and form, to move the focus between them (WCAG 2.4.3) */
const editButton = useTemplateRef('editButton')
const form = useTemplateRef<HTMLFormElement>('form')

/** Give the focus back to the "Edit" button after leaving the form. */
function focusEditButton(): void {
	focusAfterUpdate(() => editButton.value)
}

/**
 * Focus the first field of the form.
 *
 * @param onlyInvalid - the first field with an error instead
 */
function focusFirstField(onlyInvalid = false): void {
	focusAfterUpdate(() => {
		const selector = onlyInvalid ? '[aria-invalid="true"], .field-input--error input, .field-input--error textarea' : 'input, textarea, select'
		return form.value?.querySelector<HTMLElement>(selector) ?? form.value?.querySelector<HTMLElement>('input, textarea, select') ?? null
	})
}

const fields = computed(() => props.fieldKeys
	.map((key) => ({ key, info: fieldInfo(props.level, key) }))
	.filter((field): field is { key: string, info: FieldInfo } => field.info !== undefined))

const editableKeys = computed(() => fields.value.filter(({ key }) => canWrite(props.level, key)).map(({ key }) => key))

const filledFields = computed(() => fields.value.filter(({ key }) => !isEmpty(props.record[key])))

const emptyCount = computed(() => fields.value.length - filledFields.value.length)

const shownFields = computed(() => {
	if (editing.value) {
		// all writable fields plus read-only fields that have a value
		return fields.value.filter(({ key }) => editableKeys.value.includes(key) || !isEmpty(props.record[key]))
	}
	return showEmpty.value ? fields.value : filledFields.value
})

/** Copy status other than "active" needs a reason (checked by the server, too). */
const needsStatusReason = computed(() => props.level === 'copy'
	&& editableKeys.value.includes('status')
	&& draft.status !== props.record.status
	&& typeof draft.status === 'string'
	&& draft.status !== 'active')

/**
 * Value of the server as draft value for the input.
 *
 * @param info - field directory entry
 * @param value - raw value
 */
function toDraft(info: FieldInfo, value: FieldValue | undefined): DraftValue {
	if (info.type === 'boolean') {
		return value === true || value === 1 || value === '1' || value === 'true'
	}
	if (info.type === 'choice') {
		return isEmpty(value) ? null : String(value)
	}
	return isEmpty(value) ? '' : String(value)
}

/**
 * Draft value as value for the server; throws a translated message if the
 * input is not valid.
 *
 * @param info - field directory entry
 * @param value - draft value
 */
function fromDraft(info: FieldInfo, value: DraftValue): FieldValue {
	if (info.type === 'boolean') {
		return value === true
	}
	if (value === null || value === false || (typeof value === 'string' && value.trim() === '')) {
		return null
	}
	const text = String(value).trim()
	if (info.type === 'number') {
		if (!/^-?\d+$/.test(text)) {
			throw new Error(t('cantoria', 'Please enter a whole number.'))
		}
		return Number.parseInt(text, 10)
	}
	if (info.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(text)) {
		throw new Error(t('cantoria', 'Please enter a date.'))
	}
	return info.type === 'text' || info.type === 'longtext' ? String(value) : text
}

/**
 * Comparable form of a value, so that e.g. 3 and "3" or null and "" are equal.
 *
 * @param info - field directory entry
 * @param value - value
 */
function comparable(info: FieldInfo, value: FieldValue | undefined): string {
	if (info.type === 'boolean') {
		return toDraft(info, value) ? '1' : '0'
	}
	return isEmpty(value) ? '' : String(value).trim()
}

function startEditing(): void {
	for (const { key, info } of fields.value) {
		draft[key] = toDraft(info, props.record[key])
		errors[key] = ''
	}
	reason.value = ''
	editing.value = true
	focusFirstField()
}

function cancelEditing(): void {
	editing.value = false
	focusEditButton()
}

async function save(): Promise<void> {
	const changes: Record<string, FieldValue> = {}
	let valid = true
	for (const { key, info } of fields.value) {
		errors[key] = ''
		if (!editableKeys.value.includes(key)) {
			continue
		}
		try {
			const value = fromDraft(info, draft[key])
			if (comparable(info, value) !== comparable(info, props.record[key])) {
				changes[key] = value
			}
		} catch (error) {
			errors[key] = error instanceof Error ? error.message : String(error)
			valid = false
		}
	}

	if (needsStatusReason.value) {
		const statusReason = typeof draft.status_reason === 'string' ? draft.status_reason.trim() : ''
		if (statusReason === '') {
			errors.status_reason = t('cantoria', 'Please give a reason for this status.')
			valid = false
		} else {
			// The server expects the reason in the same request as the status
			changes.status_reason = statusReason
		}
	}

	if (!valid) {
		showError(t('cantoria', 'Please check the marked fields.'))
		focusFirstField(true)
		return
	}
	if (Object.keys(changes).length === 0) {
		showInfo(t('cantoria', 'Nothing has changed.'))
		editing.value = false
		focusEditButton()
		return
	}

	saving.value = true
	try {
		await updateFields(props.level, props.record.id, changes, reason.value)
		showSuccess(n('cantoria', '%n change saved', '%n changes saved', Object.keys(changes).length))
		editing.value = false
		focusEditButton()
		emit('saved')
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		saving.value = false
	}
}

/**
 * Hint below an input.
 *
 * @param key - field key
 */
function hintFor(key: string): string {
	if (key === 'status_reason' && needsStatusReason.value) {
		return t('cantoria', 'Required for this status.')
	}
	// Explanations from the field registry (e.g. the special cases of the protection term)
	const hint = fieldInfo(props.level, key)?.hint
	return hint ? translateLabel(hint) : ''
}
</script>

<template>
	<section class="field-section" :aria-labelledby="`field-section-${sectionId}`">
		<div class="field-section__head">
			<div class="field-section__title">
				<h3 :id="`field-section-${sectionId}`" class="field-section__heading">
					{{ heading }}
				</h3>
				<HelpTerm v-if="sectionHelp" :term="sectionHelp" />
			</div>
			<NcButton v-if="editableKeys.length > 0 && !editing"
				ref="editButton"
				variant="tertiary"
				:aria-label="t('cantoria', 'Edit {section}', { section: heading })"
				@click="startEditing">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPencilOutline" />
				</template>
				{{ t('cantoria', 'Edit') }}
			</NcButton>
		</div>

		<slot />

		<!-- Display -->
		<dl v-if="!editing" class="field-section__list">
			<div v-for="{ key, info } in shownFields" :key="key" class="field-section__item">
				<dt>
					{{ translateLabel(info.label) }}
					<HelpTerm v-if="FIELD_HELP[key]" :term="FIELD_HELP[key]" />
				</dt>
				<dd :class="{ 'field-section__value--long': info.type === 'longtext', 'field-section__value--empty': isEmpty(record[key]) }">
					{{ isEmpty(record[key]) ? '–' : displayValue(info, record[key]) }}
				</dd>
			</div>
		</dl>
		<p v-if="!editing && fields.length === 0" class="field-section__none">
			{{ t('cantoria', 'No fields.') }}
		</p>
		<NcButton v-if="!editing && emptyCount > 0"
			variant="tertiary"
			@click="showEmpty = !showEmpty">
			{{ showEmpty
				? t('cantoria', 'Hide empty fields')
				: n('cantoria', 'Show %n empty field', 'Show %n empty fields', emptyCount) }}
		</NcButton>

		<!-- Editing -->
		<form v-if="editing"
			ref="form"
			class="field-section__form"
			@submit.prevent="save">
			<template v-for="{ key, info } in shownFields" :key="key">
				<FieldInput v-if="editableKeys.includes(key)"
					v-model="draft[key]"
					:info="info"
					:label="translateLabel(info.label)"
					:error="errors[key] ?? ''"
					:hint="hintFor(key)"
					:clearable="!(level === 'copy' && key === 'status')" />
				<div v-else class="field-section__item field-section__item--readonly">
					<span class="field-section__label">{{ translateLabel(info.label) }}</span>
					<span :class="{ 'field-section__value--long': info.type === 'longtext' }">{{ displayValue(info, record[key]) }}</span>
				</div>
			</template>

			<NcTextField v-model="reason"
				:label="t('cantoria', 'Reason for the change (optional)')"
				:helper-text="t('cantoria', 'Stored in the audit log.')" />

			<div class="field-section__actions">
				<NcButton :disabled="saving" @click="cancelEditing">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="saving">
					<template v-if="saving" #icon>
						<NcLoadingIcon />
					</template>
					{{ t('cantoria', 'Save') }}
				</NcButton>
			</div>
		</form>
	</section>
</template>

<style scoped>
.field-section {
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.field-section:last-child {
	border-bottom: none;
}

.field-section__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.field-section__title {
	display: flex;
	align-items: center;
	gap: 2px;
}

.field-section__heading {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.field-section__list {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: calc(2 * var(--default-grid-baseline)) 0 var(--default-grid-baseline);
}

.field-section__item dt,
.field-section__label {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.field-section__item dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.field-section__value--long {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	line-height: 1.5;
}

.field-section__value--empty {
	color: var(--color-text-maxcontrast);
}

.field-section__none {
	color: var(--color-text-maxcontrast);
}

.field-section__form {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	margin-top: calc(2 * var(--default-grid-baseline));
}

.field-section__item--readonly span:last-child {
	display: block;
	overflow-wrap: anywhere;
}

.field-section__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
