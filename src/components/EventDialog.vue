<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create or edit a concert or a song list. Song lists have a period
 * ("2025/1", "2018-Advent") instead of a date and are no proof of
 * performance. A concert may have a start and an end time (local time at
 * the venue) for calendar entries.
 *
 * With `copyOf` (round 3, November 2026) the dialog makes a new programme
 * with the pieces of an existing one, struck pieces left out: title,
 * location and notes are taken over to change, the date is left empty.
 */
import type { ChoirEvent, EventInput, EventKind } from '../types.ts'

import { t } from '../l10n.ts'
import { reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createEvent, duplicateEvent, errorMessage, updateEvent } from '../api.ts'

const props = defineProps<{
	kind: EventKind
	/** event to edit; missing for a new one */
	event?: ChoirEvent | null
	/** a new event with the pieces of this one */
	copyOf?: ChoirEvent | null
}>()

const emit = defineEmits<{
	saved: [event: ChoirEvent]
	close: []
}>()

const base = props.event ?? props.copyOf ?? null
const copying = !props.event && !!props.copyOf
const draft = reactive({
	title: base?.title ?? '',
	date: copying ? '' : (base?.event_date ?? ''),
	startTime: copying ? '' : (base?.start_time ?? ''),
	endTime: copying ? '' : (base?.end_time ?? ''),
	location: base?.location ?? '',
	period: copying ? '' : (base?.period ?? ''),
	isPublic: base?.is_public ?? true,
	boardAgreed: copying ? false : (base?.board_agreed ?? false),
	notes: base?.notes ?? '',
})

const busy = ref(false)
const error = ref('')

async function save(): Promise<void> {
	if (draft.title.trim() === '') {
		error.value = t('cantoria', 'Please enter a title.')
		return
	}
	const input: EventInput = {
		title: draft.title.trim(),
		event_date: draft.date || null,
		location: draft.location.trim() || null,
		period: draft.period.trim() || null,
		is_public: draft.isPublic,
		board_agreed: draft.boardAgreed,
		notes: draft.notes.trim() || null,
	}
	if (props.kind === 'concert') {
		input.start_time = draft.startTime || null
		input.end_time = draft.endTime || null
	}
	busy.value = true
	error.value = ''
	try {
		const saved = props.event
			? await updateEvent(props.event.id, input)
			: (props.copyOf ? await duplicateEvent(props.copyOf.id, props.kind, input) : await createEvent(props.kind, input))
		emit('saved', saved)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="event
			? (kind === 'concert' ? t('cantoria', 'Edit concert') : t('cantoria', 'Edit song list'))
			: copying
				? (kind === 'concert' ? t('cantoria', 'New concert with these pieces') : t('cantoria', 'New song list with these pieces'))
				: (kind === 'concert' ? t('cantoria', 'New concert') : t('cantoria', 'New song list'))"
		:submit-label="t('cantoria', 'Save')"
		:busy="busy"
		:error="error"
		@submit="save"
		@close="emit('close')">
		<p v-if="copying" class="action-dialog__hint">
			{{ t('cantoria', 'The new programme gets the pieces of this one in their order, with duration, note and encore; struck pieces are left out. It starts as a draft.') }}
		</p>
		<NcTextField v-model="draft.title" :label="t('cantoria', 'Title (required)')" required />
		<div class="event-dialog__row">
			<DateField v-model="draft.date" :label="t('cantoria', 'Date')" />
			<NcTextField v-if="kind === 'song_list'"
				v-model="draft.period"
				:label="t('cantoria', 'Period, e.g. 2025/1 or 2018-Advent')" />
			<NcTextField v-else v-model="draft.location" :label="t('cantoria', 'Location')" />
		</div>
		<template v-if="kind === 'concert'">
			<div class="event-dialog__row">
				<DateField v-model="draft.startTime" type="time" :label="t('cantoria', 'Start time (optional)')" />
				<DateField v-model="draft.endTime" type="time" :label="t('cantoria', 'End time (optional)')" />
			</div>
			<p class="action-dialog__hint">
				{{ t('cantoria', 'Local time at the venue. Without a time, the concert is an all-day entry in calendars.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="draft.isPublic" type="switch">
				{{ t('cantoria', 'Public concert') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="draft.boardAgreed" type="switch">
				{{ t('cantoria', 'The board has agreed') }}
			</NcCheckboxRadioSwitch>
		</template>
		<NcTextArea v-model="draft.notes"
			:label="t('cantoria', 'Notes')"
			resize="vertical"
			rows="3" />
	</ActionDialog>
</template>

<style scoped>
.event-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.event-dialog__row > * {
	flex: 1 1 12em;
}
</style>
