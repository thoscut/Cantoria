<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Report of a catalog import (dry run or real import), in plain words.
 */
import type { ImportReport } from '../types.ts'

import { n, t } from '../l10n.ts'
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ReportList from './ReportList.vue'
import { choiceLabel } from '../store.ts'

const props = defineProps<{
	report: ImportReport
}>()

const counts = computed(() => [
	{ label: t('cantoria', 'Rows in the file'), value: props.report.rows },
	{ label: props.report.dryRun ? t('cantoria', 'New copies (would be created)') : t('cantoria', 'New copies (created)'), value: props.report.created },
	{ label: props.report.dryRun ? t('cantoria', 'Copies with changes (would be updated)') : t('cantoria', 'Copies with changes (updated)'), value: props.report.changed },
	{ label: t('cantoria', 'Unchanged copies'), value: props.report.unchanged },
	{ label: t('cantoria', 'Changed fields in total'), value: props.report.fieldsChanged },
	{ label: t('cantoria', 'With MusicXML'), value: props.report.withMusicxml },
	{ label: t('cantoria', 'Internal preservation copies'), value: props.report.preservationCopies },
])

type Distribution = { label: string, rows: { label: string, count: number }[] }

/**
 * Value => count as sorted rows.
 *
 * @param values - distribution from the report
 * @param label - how to name a value
 */
function rows(values: Record<string, number> | unknown[] | undefined, label: (key: string) => string): { label: string, count: number }[] {
	if (!values || Array.isArray(values)) {
		return []
	}
	return Object.entries(values)
		.map(([key, count]) => ({ label: key === '' ? t('cantoria', '(empty)') : label(key), count }))
		.sort((a, b) => b.count - a.count)
}

const distributions = computed<Distribution[]>(() => [
	{
		label: t('cantoria', 'Rights status'),
		rows: rows(props.report.distribution?.rightsStatus, (key) => choiceLabel('version', 'rights_status', key)),
	},
	{
		label: t('cantoria', 'Repertoire'),
		rows: rows(props.report.distribution?.repertoire, (key) => key),
	},
	{
		label: t('cantoria', 'Publication'),
		rows: rows(props.report.distribution?.publication, (key) => key),
	},
].filter((distribution) => distribution.rows.length > 0))
</script>

<template>
	<div class="import-report">
		<NcNoteCard v-if="report.dryRun"
			type="info"
			:heading="t('cantoria', 'Dry run – nothing has been changed yet')">
			{{ t('cantoria', 'Please check the report. The catalog only changes when you click "Import now".') }}
		</NcNoteCard>
		<NcNoteCard v-else
			type="success"
			:heading="t('cantoria', 'Import completed')">
			<template v-if="report.snapshot">
				{{ t('cantoria', 'The previous state was saved as a snapshot: {path}', { path: report.snapshot }) }}
			</template>
			<template v-else>
				{{ t('cantoria', 'Every change is recorded in the audit log.') }}
			</template>
		</NcNoteCard>

		<p>
			<strong>{{ report.restore ? t('cantoria', 'Restore: yes') : t('cantoria', 'Restore: no') }}</strong>
			–
			<template v-if="report.restore">
				{{ t('cantoria', 'The catalog is still empty, so all columns of the file are taken over, including read-only ones.') }}
			</template>
			<template v-else>
				{{ t('cantoria', 'The catalog already contains data. Copies are matched by their shelf mark and updated; read-only fields are kept.') }}
			</template>
		</p>

		<dl class="import-report__counts">
			<div v-for="count in counts" :key="count.label">
				<dt>{{ count.label }}</dt>
				<dd>{{ count.value }}</dd>
			</div>
		</dl>

		<div v-if="distributions.length > 0" class="import-report__distributions">
			<table v-for="distribution in distributions" :key="distribution.label" class="import-report__table">
				<caption>{{ distribution.label }}</caption>
				<tbody>
					<tr v-for="row in distribution.rows" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td>{{ row.count }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<NcNoteCard v-if="report.warnings.length > 0" type="warning">
			<ReportList :summary="n('cantoria', '%n warning', '%n warnings', report.warnings.length)"
				:items="report.warnings" />
		</NcNoteCard>

		<div v-if="report.notInCsv.length > 0">
			<ReportList :summary="n('cantoria', '%n copy is no longer in the import file', '%n copies are no longer in the import file', report.notInCsv.length)"
				:items="report.notInCsv"
				inline
				:open-up-to="40" />
			<p class="import-report__muted">
				{{ t('cantoria', 'They stay in the catalog – nothing is deleted. If a copy is really gone, change its status.') }}
			</p>
		</div>

		<p class="import-report__muted import-report__source">
			{{ t('cantoria', 'File: {source}', { source: report.source }) }}
			<br>
			{{ t('cantoria', 'Checksum (SHA-256): {sha256}', { sha256: report.sha256 }) }}
		</p>
	</div>
</template>

<style scoped>
.import-report {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.import-report__counts {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
}

.import-report__counts div {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
}

.import-report__counts dt {
	/* the label above its number: the core styles set dt and dd inline */
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.import-report__counts dd {
	display: block;
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.import-report__distributions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(4 * var(--default-grid-baseline));
}

.import-report__table caption {
	text-align: start;
	font-weight: bold;
}

.import-report__table th,
.import-report__table td {
	padding: 2px calc(2 * var(--default-grid-baseline)) 2px 0;
	text-align: start;
	font-weight: normal;
}

.import-report__table td {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.import-report__muted {
	color: var(--color-text-maxcontrast);
}

.import-report__source {
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}
</style>
