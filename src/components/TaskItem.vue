<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * One task: where in the score, the question, the answer and what was done
 * with it. The addressed role answers; the catalog role closes the task as
 * applied or discarded (a check also by its addressed role).
 */
import type { Task } from '../types.ts'

import { mdiMusic, mdiOpenInApp } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import IncipitImage from './IncipitImage.vue'
import { answerTask, closeTask, errorMessage } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { formatDate, formatDateTime, toIsoDate } from '../format.ts'
import { referenceWords, roleLabel, taskCategoryLabel, taskKindLabel, taskStatusLabel } from '../labels.ts'
import { placeText } from '../references.ts'
import { factsOf, hasRole, isLibrarian, openEntry, scoreRequest } from '../store.ts'

const props = withDefaults(defineProps<{
	task: Task
	/** show shelf mark and title (lists across copies) */
	showCopy?: boolean
	/** draw attention to this task (opened via a notification) */
	highlighted?: boolean
	/** the copy has a score that can be shown */
	canShowScore?: boolean
}>(), {
	showCopy: false,
	highlighted: false,
	canShowScore: false,
})

const emit = defineEmits<{
	changed: []
}>()

/** The first bars of the piece, in lists across copies (design proposal V6) */
const incipit = computed(() => props.showCopy ? factsOf(props.task.copy_id)?.incipit ?? null : null)

const place = computed(() => placeText({
	page: props.task.page,
	measureFrom: props.task.measure_from,
	measureTo: props.task.measure_to,
	voice: props.task.voice,
	verse: props.task.verse,
}, referenceWords()))

const isClosed = computed(() => props.task.status === 'applied' || props.task.status === 'discarded')
const canAnswer = computed(() => props.task.status === 'open' && hasRole(props.task.addressee))
const canClose = computed(() => !isClosed.value
	&& (isLibrarian.value || (props.task.kind === 'check' && hasRole(props.task.addressee))))

const today = toIsoDate(new Date())
const overdue = computed(() => !isClosed.value && props.task.due !== null && props.task.due < today)

// ---- answer and close ----

const dialog = ref<'answer' | 'close' | null>(null)
const text = ref('')
const closeStatus = ref<'applied' | 'discarded'>('applied')
const busy = ref(false)
const error = ref('')

/**
 * @param kind - which dialog
 */
function openDialog(kind: 'answer' | 'close'): void {
	text.value = ''
	closeStatus.value = 'applied'
	error.value = ''
	dialog.value = kind
}

async function submit(): Promise<void> {
	if (dialog.value === 'answer' && text.value.trim() === '') {
		error.value = t('cantoria', 'Please enter an answer.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		if (dialog.value === 'answer') {
			await answerTask(props.task.id, text.value.trim())
			showSuccess(t('cantoria', 'The answer has been saved.'))
		} else {
			await closeTask(props.task.id, closeStatus.value, text.value.trim() === '' ? null : text.value.trim())
			showSuccess(t('cantoria', 'The task has been closed.'))
		}
		dialog.value = null
		emit('changed')
		// The button that opened the dialog is gone now (answered or
		// closed): the heading of the task gets the focus (WCAG 2.4.3).
		focusAfterDialog(() => document.getElementById(`task-${props.task.id}-title`))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

function openCopy(): void {
	openEntry(props.task.copy_id, 'tasks')
}

function showScore(): void {
	scoreRequest.value = {
		copyId: props.task.copy_id,
		measureFrom: props.task.measure_from,
		measureTo: props.task.measure_to,
		voice: props.task.voice,
		verse: props.task.verse,
	}
	if (props.showCopy) {
		openEntry(props.task.copy_id)
	}
}
</script>

<template>
	<article class="task-item"
		:class="{ 'task-item--highlighted': highlighted, 'task-item--closed': isClosed }"
		:aria-labelledby="`task-${task.id}-title`">
		<header class="task-item__head">
			<h4 :id="`task-${task.id}-title`" class="task-item__title">
				<span v-if="showCopy" class="task-item__shelfmark">{{ task.shelfmark }}</span>
				<span v-if="showCopy && task.title" class="task-item__work">{{ task.title }}</span>
				<span>{{ taskKindLabel(task.kind) }}</span>
			</h4>
			<span class="task-item__status" :class="`task-item__status--${task.status}`">
				{{ taskStatusLabel(task.status) }}
			</span>
		</header>
		<IncipitImage v-if="incipit" class="task-item__incipit no-print" :incipit="incipit" />
		<p v-if="place" class="task-item__place">
			{{ place }}
		</p>
		<p class="task-item__question">
			{{ task.question }}
		</p>
		<dl class="task-item__facts">
			<dt>{{ t('cantoria', 'For') }}</dt>
			<dd>{{ roleLabel(task.addressee) }}</dd>
			<template v-if="task.category">
				<dt>{{ t('cantoria', 'Category') }}</dt>
				<dd>{{ taskCategoryLabel(task.category) }}</dd>
			</template>
			<template v-if="task.origin">
				<dt>{{ t('cantoria', 'Origin') }}</dt>
				<dd>{{ task.origin }}</dd>
			</template>
			<template v-if="task.due">
				<dt>{{ t('cantoria', 'Due') }}</dt>
				<dd :class="{ 'task-item__overdue': overdue }">
					{{ formatDate(task.due) }}
					<template v-if="overdue">
						({{ t('cantoria', 'overdue') }})
					</template>
				</dd>
			</template>
			<dt>{{ t('cantoria', 'Created') }}</dt>
			<dd>{{ formatDateTime(task.created) }}</dd>
			<template v-if="task.answer !== null">
				<dt>{{ t('cantoria', 'Answer') }}</dt>
				<dd class="task-item__answer">
					{{ task.answer }}
					<span v-if="task.answered" class="task-item__muted">
						– {{ task.answered_by_name || task.answered_by }}, {{ formatDateTime(task.answered) }}
					</span>
				</dd>
			</template>
			<template v-if="isClosed">
				<dt>{{ t('cantoria', 'Closed') }}</dt>
				<dd>
					{{ taskStatusLabel(task.status) }}<template v-if="task.closed">
						, {{ formatDateTime(task.closed) }}
					</template>
					<template v-if="task.closing_note">
						: {{ task.closing_note }}
					</template>
				</dd>
			</template>
		</dl>
		<div class="task-item__actions no-print">
			<NcButton v-if="canAnswer" variant="primary" @click="openDialog('answer')">
				{{ t('cantoria', 'Answer') }}
			</NcButton>
			<NcButton v-if="canClose" @click="openDialog('close')">
				{{ t('cantoria', 'Close') }}
			</NcButton>
			<NcButton v-if="canShowScore && (task.measure_from || task.voice)" variant="tertiary" @click="showScore">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMusic" />
				</template>
				{{ t('cantoria', 'Show in the score') }}
			</NcButton>
			<NcButton v-if="showCopy" variant="tertiary" @click="openCopy">
				<template #icon>
					<NcIconSvgWrapper :path="mdiOpenInApp" />
				</template>
				{{ t('cantoria', 'Open {shelfmark}', { shelfmark: task.shelfmark ?? '' }) }}
			</NcButton>
		</div>

		<ActionDialog v-if="dialog !== null"
			:name="dialog === 'answer' ? t('cantoria', 'Answer the task') : t('cantoria', 'Close the task')"
			:submit-label="dialog === 'answer' ? t('cantoria', 'Save answer') : t('cantoria', 'Close task')"
			:busy="busy"
			:error="error"
			@submit="submit"
			@close="dialog = null">
			<p class="task-item__question">
				{{ task.question }}
			</p>
			<template v-if="dialog === 'answer'">
				<NcTextArea v-model="text"
					:label="t('cantoria', 'Answer (required)')"
					required
					resize="vertical"
					rows="5" />
			</template>
			<template v-else>
				<fieldset class="task-item__fieldset">
					<legend>{{ t('cantoria', 'Result') }}</legend>
					<NcCheckboxRadioSwitch v-model="closeStatus"
						type="radio"
						value="applied"
						name="task-close-status">
						{{ t('cantoria', 'Applied (the catalog or the file was corrected)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="closeStatus"
						type="radio"
						value="discarded"
						name="task-close-status">
						{{ t('cantoria', 'Discarded (nothing to change)') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<NcTextArea v-model="text"
					:label="t('cantoria', 'Note (optional)')"
					resize="vertical"
					rows="3" />
			</template>
		</ActionDialog>
	</article>
</template>

<style scoped>
.task-item {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	break-inside: avoid;
}

.task-item--highlighted {
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.task-item--closed {
	color: var(--color-text-maxcontrast);
}

.task-item__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.task-item__title {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(2 * var(--default-grid-baseline));
	margin: 0;
	font-size: 1em;
	font-weight: bold;
}

.task-item__shelfmark {
	font-variant-numeric: tabular-nums;
}

.task-item__work {
	font-weight: normal;
}

.task-item__status {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
}

.task-item__status--open {
	border-color: var(--color-warning-text, var(--color-warning));
}

.task-item__status--answered {
	border-color: var(--color-info-text, var(--color-primary-element));
}

.task-item__place {
	margin: 0;
	font-weight: bold;
}

.task-item__question {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.task-item__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(2 * var(--default-grid-baseline));
	margin: 0;
	font-size: var(--font-size-small, 13px);
}

.task-item__facts dt {
	color: var(--color-text-maxcontrast);
}

.task-item__facts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.task-item__answer {
	white-space: pre-wrap;
}

.task-item__overdue {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.task-item__muted {
	color: var(--color-text-maxcontrast);
}

.task-item__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.task-item__fieldset {
	margin: 0;
	padding: 0;
	border: none;
}

.task-item__fieldset legend {
	font-weight: bold;
}
</style>
