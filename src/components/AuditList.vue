<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Audit log of one copy with its version and work: who, when, which field,
 * old -> new, reason. Newest first.
 */
import type { AuditEntry, Level } from '../types.ts'

import { mdiHistory } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { displayAuditValue, formatDateTime } from '../format.ts'
import { reviewStatusLabel } from '../review.ts'
import { fieldInfo, fieldLabel } from '../store.ts'
import { LEVELS } from '../types.ts'

const PAGE = 50

const props = defineProps<{
	audit: AuditEntry[]
}>()

const limit = ref(PAGE)

const sorted = computed(() => [...props.audit].sort((a, b) => b.time - a.time || b.id - a.id))
const shown = computed(() => sorted.value.slice(0, limit.value))

function isLevel(value: string): value is Level {
	return (LEVELS as string[]).includes(value)
}

/**
 * Translated name of the changed object type.
 *
 * @param type - object type from the log
 */
function objectLabel(type: string): string {
	switch (type) {
	case 'work':
		return t('cantoria', 'Work')
	case 'version':
		return t('cantoria', 'Version')
	case 'copy':
		return t('cantoria', 'Copy')
	case 'file':
		return t('cantoria', 'File')
	case 'review_point':
		return t('cantoria', 'Review point')
	case 'review_report':
		return t('cantoria', 'Transcription report')
	case 'folder':
		return t('cantoria', 'Folder')
	case 'folder_loan':
		return t('cantoria', 'Loan of a folder')
	case 'print_mirror':
		return t('cantoria', 'Print station')
	case 'choir_handover':
		return t('cantoria', 'Hand-over to the choir system')
	case 'export':
		return t('cantoria', 'Export')
	case 'event':
		return t('cantoria', 'Concert')
	case 'songbook':
		return t('cantoria', 'Songbook')
	default:
		return type
	}
}

/**
 * Translated name of a logged action.
 *
 * @param action - action key from the log
 */
function actionLabel(action: string): string {
	switch (action) {
	case 'created':
		return t('cantoria', 'Created')
	case 'changed':
		return t('cantoria', 'Changed')
	case 'import':
		return t('cantoria', 'Import')
	case 'restore':
		return t('cantoria', 'Restore')
	case 'not_found':
		return t('cantoria', 'File not found')
	case 'file_sync':
		return t('cantoria', 'File sync')
	case 'practice_audio':
		return t('cantoria', 'Practice files made')
	case 'imported':
		return t('cantoria', 'Report read')
	case 'decided':
		return t('cantoria', 'Decided')
	case 'corrected':
		return t('cantoria', 'Corrected')
	case 'folder_issued':
		return t('cantoria', 'Issue')
	case 'folder_taken_back':
		return t('cantoria', 'Take back')
	case 'anonymized':
		return t('cantoria', 'Name removed')
	case 'erased':
		return t('cantoria', 'Personal data erased on request')
	case 'download_backup':
		return t('cantoria', 'Complete backup downloaded')
	case 'pseudonymised':
		return t('cantoria', 'Accounts removed after the retention period')
	case 'mirror_copied':
		return t('cantoria', 'Copied')
	case 'mirror_replaced':
		return t('cantoria', 'Replaced')
	case 'mirror_removed':
		return t('cantoria', 'Removed')
	case 'state_recorded':
		return t('cantoria', 'Revision recorded')
	case 'state_current':
		return t('cantoria', 'Current revision set')
	case 'songbook_made':
		return t('cantoria', 'Songbook made')
	default:
		return action
	}
}

/**
 * What a practice audio export contained, from its JSON record:
 * "2-2 · Measures 1 to 4 · Tenor 1 · Focus voice louder · WAV · 90 % · with repeats".
 *
 * @param value - new_value of the log entry
 */
function practiceAudioText(value: string): string {
	let details: Record<string, unknown>
	try {
		details = JSON.parse(value)
	} catch {
		return value
	}
	if (details === null || typeof details !== 'object') {
		return value
	}
	const modes: Record<string, string> = {
		all: t('cantoria', 'All equal'),
		focus: t('cantoria', 'Focus voice louder'),
		solo: t('cantoria', 'Focus voice only'),
	}
	const [from, to] = String(details.measures ?? '').split('-')
	const voice = details.voice === '*'
		? t('cantoria', 'Every voice and a tutti (ZIP)')
		: (typeof details.voice === 'string' && details.voice !== '' ? details.voice : t('cantoria', 'Tutti'))
	return [
		String(details.copy ?? ''),
		t('cantoria', 'Measures {from} to {to}', { from: from ?? '', to: to ?? '' }),
		voice,
		modes[String(details.mode)] ?? String(details.mode ?? ''),
		String(details.format ?? '').toUpperCase(),
		typeof details.tempo === 'number' ? `${details.tempo} %` : '',
		details.repeats === false ? t('cantoria', 'without repeats') : t('cantoria', 'with repeats'),
	].filter((part) => part !== '').join(' · ')
}

/**
 * What was changed: "Version: Rights status" or the action.
 *
 * @param entry - log entry
 */
function subject(entry: AuditEntry): string {
	const object = objectLabel(entry.object_type)
	if (entry.field && isLevel(entry.object_type)) {
		return `${object}: ${fieldLabel(entry.object_type, entry.field)}`
	}
	if (entry.field) {
		return `${object}: ${entry.field}`
	}
	return `${object}: ${actionLabel(entry.action)}`
}

/**
 * A logged value for display.
 *
 * @param entry - log entry
 * @param value - old or new value
 */
function value(entry: AuditEntry, value: string | null): string {
	if (entry.object_type === 'review_point' && entry.field === 'status' && value !== null) {
		return reviewStatusLabel(value)
	}
	const info = entry.field && isLevel(entry.object_type) ? fieldInfo(entry.object_type, entry.field) : undefined
	return displayAuditValue(info, value)
}

function who(entry: AuditEntry): string {
	if (!entry.user_name && !entry.user_id && entry.roles === 'member') {
		// Choir members are recorded without account or name (milestone 0.11)
		return t('cantoria', 'A choir member')
	}
	return entry.user_name || entry.user_id || t('cantoria', 'System')
}
</script>

<template>
	<div class="audit-list">
		<NcEmptyContent v-if="audit.length === 0"
			:name="t('cantoria', 'No changes yet')"
			:description="t('cantoria', 'Every change is recorded here: who, when, old and new value, reason.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiHistory" />
			</template>
		</NcEmptyContent>

		<ol v-else class="audit-list__items">
			<li v-for="entry in shown" :key="entry.id" class="audit-list__item">
				<div class="audit-list__head">
					<strong :title="entry.user_id ?? undefined">{{ who(entry) }}</strong>
					<time :datetime="new Date(entry.time * 1000).toISOString()">{{ formatDateTime(entry.time) }}</time>
				</div>
				<div class="audit-list__subject">
					{{ subject(entry) }}
				</div>
				<div v-if="entry.field && (entry.old_value !== null || entry.new_value !== null)" class="audit-list__change">
					<span class="audit-list__old">{{ value(entry, entry.old_value) || t('cantoria', '(empty)') }}</span>
					<span aria-hidden="true"> → </span>
					<span class="hidden-visually">{{ t('cantoria', 'changed to') }}</span>
					<span class="audit-list__new">{{ value(entry, entry.new_value) || t('cantoria', '(empty)') }}</span>
				</div>
				<div v-else-if="entry.new_value" class="audit-list__change">
					{{ entry.action === 'practice_audio' ? practiceAudioText(entry.new_value) : entry.new_value }}
				</div>
				<div v-if="entry.reason" class="audit-list__reason">
					{{ t('cantoria', 'Reason: {reason}', { reason: entry.reason }) }}
				</div>
			</li>
		</ol>

		<NcButton v-if="sorted.length > limit" variant="tertiary" @click="limit += PAGE">
			{{ n('cantoria', 'Show %n more entry', 'Show %n more entries', Math.min(PAGE, sorted.length - limit)) }}
		</NcButton>
	</div>
</template>

<style scoped>
.audit-list {
	padding-block: calc(2 * var(--default-grid-baseline));
}

.audit-list__items {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.audit-list__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.audit-list__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.audit-list__head time {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.audit-list__change,
.audit-list__reason {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.audit-list__old {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.audit-list__reason {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
