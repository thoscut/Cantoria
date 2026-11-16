<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Import of the research list of persons (CSV): always a dry run first with
 * a report, then the import. A repeated import only fills empty values and
 * adds missing contributions; checked persons are never changed.
 *
 * Below it, the derivation of contributors from the composer and arranger
 * texts of the catalog, with the same two steps (dry run, then derive).
 */
import type { ContributorDerivationReport, PersonImportReport } from '../types.ts'

import { mdiFileTableOutline, mdiImport, mdiMagnify } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ReportList from './ReportList.vue'
import { deriveContributors, errorMessage, importPersons } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { contributionRoleLabel, personOptionLabel } from '../labels.ts'
import { loadDetail } from '../store.ts'
import { showError } from '../toasts.ts'

const emit = defineEmits<{
	busy: [busy: boolean]
}>()

const file = ref<{ id: number, name: string } | null>(null)
const report = ref<PersonImportReport | null>(null)
const busy = ref<'' | 'dry' | 'import'>('')
const error = ref('')

/** The derivation of contributors from the catalog texts */
const derivation = ref<ContributorDerivationReport | null>(null)
const deriveBusy = ref<'' | 'dry' | 'derive'>('')
const deriveError = ref('')

/** Where the focus goes after the file picker and after a run (WCAG 2.4.3) */
const chooseButton = useTemplateRef('chooseButton')
const reportNote = useTemplateRef('reportNote')
const deriveButton = useTemplateRef('deriveButton')
const derivationNote = useTemplateRef('derivationNote')

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose the list of persons (CSV)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['text/csv'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (caught) {
		if (!(caught instanceof FilePickerClosed)) {
			showError(errorMessage(caught))
		}
		await focusAfterDialog(chooseButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('cantoria', 'The chosen file cannot be used.'))
		await focusAfterDialog(chooseButton)
		return
	}
	file.value = { id: node.fileid, name: node.displayname || node.basename }
	report.value = null
	await run(true)
}

/**
 * @param dryRun - only check and report
 */
async function run(dryRun: boolean): Promise<void> {
	if (!file.value) {
		return
	}
	busy.value = dryRun ? 'dry' : 'import'
	emit('busy', true)
	error.value = ''
	try {
		report.value = await importPersons(file.value.id, dryRun)
		if (!dryRun) {
			showSuccess(t('cantoria', 'The persons have been imported.'))
			await loadDetail()
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = ''
		emit('busy', false)
	}
	// The buttons were disabled while running: the report (or, after an
	// error, the button to choose a file) gets the focus.
	await focusAfterUpdate(() => (error.value === '' ? reportNote.value : chooseButton.value))
}

function reset(): void {
	file.value = null
	report.value = null
	error.value = ''
}

/**
 * Derives contributors from the catalog texts: a dry run with a report, or
 * the derivation itself.
 *
 * @param dryRun - only check and report
 */
async function derive(dryRun: boolean): Promise<void> {
	deriveBusy.value = dryRun ? 'dry' : 'derive'
	emit('busy', true)
	deriveError.value = ''
	try {
		derivation.value = await deriveContributors(dryRun)
		if (!dryRun) {
			showSuccess(t('cantoria', 'The contributors have been derived.'))
			await loadDetail()
		}
	} catch (caught) {
		deriveError.value = errorMessage(caught)
	} finally {
		deriveBusy.value = ''
		emit('busy', false)
	}
	await focusAfterUpdate(() => (deriveError.value === '' ? derivationNote.value : deriveButton.value))
}

function resetDerivation(): void {
	derivation.value = null
	deriveError.value = ''
}

/**
 * Counts per key as "label: count" list.
 *
 * @param field - person field of the keys
 * @param counts - count per key
 */
function distribution(field: string, counts: Record<string, number>): string {
	return Object.entries(counts)
		.map(([key, count]) => `${personOptionLabel(field, key) || key}: ${count}`)
		.join(', ') || '–'
}

/**
 * Derived roles as "label: count" list.
 *
 * @param counts - count per role
 */
function roleDistribution(counts: Record<string, number>): string {
	return Object.entries(counts)
		.map(([role, count]) => `${contributionRoleLabel(role)}: ${count}`)
		.join(', ') || '–'
}

/**
 * A segment without a role phrase, with its shelf mark.
 *
 * @param item - segment of the report
 * @param item.shelfmark - shelf mark of the version
 * @param item.text - the segment as in the catalog
 */
function unassignedText(item: { shelfmark: string, text: string }): string {
	return `${item.shelfmark}: ${item.text}`
}
</script>

<template>
	<div class="person-import">
		<div class="person-import__actions">
			<NcButton ref="chooseButton" :disabled="busy !== ''" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileTableOutline" />
				</template>
				{{ file ? t('cantoria', 'Choose another file …') : t('cantoria', 'Choose CSV file …') }}
			</NcButton>
			<NcButton v-if="file && report?.dryRun"
				variant="primary"
				:disabled="busy !== ''"
				@click="run(false)">
				<template #icon>
					<NcLoadingIcon v-if="busy === 'import'" />
					<NcIconSvgWrapper v-else :path="mdiImport" />
				</template>
				{{ t('cantoria', 'Import now') }}
			</NcButton>
			<NcButton v-if="file && report && !report.dryRun" variant="tertiary" @click="reset">
				{{ t('cantoria', 'Done') }}
			</NcButton>
		</div>
		<p v-if="file" class="person-import__muted">
			{{ t('cantoria', 'File: {name}', { name: file.name }) }}
		</p>
		<p v-if="busy !== ''" class="person-import__busy">
			<NcLoadingIcon :size="20" />
			{{ busy === 'dry' ? t('cantoria', 'Checking the file (dry run) …') : t('cantoria', 'Importing …') }}
		</p>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<section v-if="report && busy === ''" class="person-import__report" :aria-label="t('cantoria', 'Import report')">
			<NcNoteCard ref="reportNote" :type="report.dryRun ? 'info' : 'success'">
				{{ report.dryRun
					? t('cantoria', 'Dry run – nothing has been changed yet')
					: t('cantoria', 'Import completed') }}
			</NcNoteCard>
			<dl class="person-import__figures">
				<dt>{{ t('cantoria', 'Rows') }}</dt>
				<dd>{{ report.rows }}</dd>
				<dt>{{ t('cantoria', 'New persons') }}</dt>
				<dd>{{ report.created }}</dd>
				<dt>{{ t('cantoria', 'Already in the register') }}</dt>
				<dd>{{ report.existing }}</dd>
				<dt>{{ t('cantoria', 'Supplemented') }}</dt>
				<dd>{{ report.updated }}</dd>
				<dt>{{ t('cantoria', 'Skipped') }}</dt>
				<dd>{{ report.skipped }}</dd>
				<dt>{{ t('cantoria', 'New contributions') }}</dt>
				<dd>{{ report.contributions }}</dd>
				<dt>{{ t('cantoria', 'Contributions already recorded') }}</dt>
				<dd>{{ report.contributionsExisting }}</dd>
				<dt>{{ t('cantoria', 'Date of death') }}</dt>
				<dd>{{ distribution('died_precision', report.precision) }}</dd>
				<dt>{{ t('cantoria', 'Identification') }}</dt>
				<dd>{{ distribution('identification', report.identification) }}</dd>
			</dl>
			<p class="person-import__muted">
				{{ t('cantoria', 'Imported persons are at most "probable"; please check them in the person register. Every shelf mark becomes a contribution with the role "unspecified".') }}
			</p>

			<template v-if="report.renamed.length > 0">
				<h4>{{ n('cantoria', '%n name has been cleaned up', '%n names have been cleaned up', report.renamed.length) }}</h4>
				<ul class="person-import__list">
					<li v-for="item in report.renamed" :key="item.from">
						{{ item.from }} → {{ item.to }}
					</li>
				</ul>
			</template>
			<template v-if="report.unknownShelfmarks.length > 0">
				<h4>{{ n('cantoria', '%n shelf mark is not in the catalog', '%n shelf marks are not in the catalog', report.unknownShelfmarks.length) }}</h4>
				<p class="person-import__marks">
					{{ report.unknownShelfmarks.join(', ') }}
				</p>
			</template>
			<template v-if="report.protectionDiffers.length > 0">
				<h4>{{ n('cantoria', 'Protection end differs for %n person', 'Protection end differs for %n persons', report.protectionDiffers.length) }}</h4>
				<table class="person-import__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('cantoria', 'Name') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'In the list') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'By the life dates') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in report.protectionDiffers" :key="item.name">
							<td>{{ item.name }}</td>
							<td>{{ item.list }}</td>
							<td>{{ item.computed }}</td>
						</tr>
					</tbody>
				</table>
			</template>
			<template v-if="report.warnings.length > 0">
				<h4>{{ t('cantoria', 'Notes') }}</h4>
				<ul class="person-import__list">
					<li v-for="(warning, index) in report.warnings" :key="index">
						{{ warning }}
					</li>
				</ul>
			</template>
			<p v-if="report.snapshot" class="person-import__muted">
				{{ t('cantoria', 'The previous state was saved as a snapshot: {path}', { path: report.snapshot }) }}
			</p>
			<p class="person-import__muted">
				{{ t('cantoria', 'Checksum (SHA-256): {sha256}', { sha256: report.sha256 }) }}
			</p>
		</section>

		<section class="person-import__derive" aria-labelledby="person-import-derive">
			<h4 id="person-import-derive">
				{{ t('cantoria', 'Derive contributors from the catalog') }}
			</h4>
			<p>
				{{ t('cantoria', 'Reads the texts "Komponist" and "Bearbeiter/Text" of the catalog: "Text:", "Satz:", "Bearbeitung:" or "dt. Text:" name the role, the bracket the life dates.') }}
				{{ t('cantoria', 'A name without a phrase in "Komponist" is the composer; "Volkslied" marks the melody as traditional.') }}
				{{ t('cantoria', 'A contribution with the role "unspecified" gets its role; checked persons are never changed. A dry run first shows what would be derived.') }}
			</p>
			<div class="person-import__actions">
				<NcButton ref="deriveButton" :disabled="busy !== '' || deriveBusy !== ''" @click="derive(true)">
					<template #icon>
						<NcLoadingIcon v-if="deriveBusy === 'dry'" />
						<NcIconSvgWrapper v-else :path="mdiMagnify" />
					</template>
					{{ t('cantoria', 'Check (dry run)') }}
				</NcButton>
				<NcButton v-if="derivation?.dryRun"
					variant="primary"
					:disabled="busy !== '' || deriveBusy !== ''"
					@click="derive(false)">
					<template #icon>
						<NcLoadingIcon v-if="deriveBusy === 'derive'" />
						<NcIconSvgWrapper v-else :path="mdiImport" />
					</template>
					{{ t('cantoria', 'Derive now') }}
				</NcButton>
				<NcButton v-if="derivation && !derivation.dryRun" variant="tertiary" @click="resetDerivation">
					{{ t('cantoria', 'Done') }}
				</NcButton>
			</div>
			<p v-if="deriveBusy !== ''" class="person-import__busy">
				<NcLoadingIcon :size="20" />
				{{ deriveBusy === 'dry' ? t('cantoria', 'Checking the catalog (dry run) …') : t('cantoria', 'Deriving …') }}
			</p>
			<NcNoteCard v-if="deriveError" type="error" role="alert">
				{{ deriveError }}
			</NcNoteCard>

			<section v-if="derivation && deriveBusy === ''" class="person-import__report" :aria-label="t('cantoria', 'Derivation report')">
				<NcNoteCard ref="derivationNote" :type="derivation.dryRun ? 'info' : 'success'">
					{{ derivation.dryRun
						? t('cantoria', 'Dry run – nothing has been changed yet')
						: t('cantoria', 'Derivation completed') }}
				</NcNoteCard>
				<dl class="person-import__figures">
					<dt>{{ t('cantoria', 'Versions with texts') }}</dt>
					<dd>{{ derivation.versions }}</dd>
					<dt>{{ t('cantoria', 'Segments') }}</dt>
					<dd>{{ derivation.segments }}</dd>
					<dt>{{ t('cantoria', 'New persons') }}</dt>
					<dd>{{ derivation.personsCreated }}</dd>
					<dt>{{ t('cantoria', 'Already in the register') }}</dt>
					<dd>{{ derivation.personsExisting }}</dd>
					<dt>{{ t('cantoria', 'Supplemented') }}</dt>
					<dd>{{ derivation.personsUpdated }}</dd>
					<dt>{{ t('cantoria', 'New contributions') }}</dt>
					<dd>{{ derivation.contributionsCreated }}</dd>
					<dt>{{ t('cantoria', 'Roles set on existing contributions') }}</dt>
					<dd>{{ derivation.rolesSet }}</dd>
					<dt>{{ t('cantoria', 'Traditional melodies') }}</dt>
					<dd>{{ derivation.traditionalMelodies }}</dd>
					<dt>{{ t('cantoria', 'Uncertain attributions') }}</dt>
					<dd>{{ derivation.uncertain }}</dd>
					<dt>{{ t('cantoria', 'Roles derived') }}</dt>
					<dd>{{ roleDistribution(derivation.roles) }}</dd>
				</dl>
				<p class="person-import__muted">
					{{ t('cantoria', 'Derived persons are at most "probable"; please check them in the person register. Segments without a role phrase are listed below and need to be assigned by hand.') }}
				</p>

				<template v-if="derivation.differs.length > 0">
					<h4>{{ n('cantoria', 'Life dates differ for %n person', 'Life dates differ for %n persons', derivation.differs.length) }}</h4>
					<table class="person-import__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('cantoria', 'Name') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'In the register') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'In the catalog') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Shelf marks') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="item in derivation.differs" :key="item.name">
								<td>{{ item.name }}</td>
								<td>{{ item.person }}</td>
								<td>{{ item.catalog }}</td>
								<td>{{ item.versions.join(', ') }}</td>
							</tr>
						</tbody>
					</table>
				</template>
				<ReportList v-if="derivation.unassigned.length > 0"
					:summary="n('cantoria', '%n segment without a role phrase', '%n segments without a role phrase', derivation.unassigned.length)"
					:items="derivation.unassigned.map(unassignedText)" />
				<ReportList v-if="derivation.warnings.length > 0"
					:summary="n('cantoria', '%n note', '%n notes', derivation.warnings.length)"
					:items="derivation.warnings" />
			</section>
		</section>
	</div>
</template>

<style scoped>
.person-import {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__report,
.person-import__derive {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__derive {
	margin-top: calc(2 * var(--default-grid-baseline));
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.person-import__derive > h4 {
	margin: 0;
	font-weight: bold;
}

.person-import__derive > p {
	margin: 0;
}

.person-import__figures {
	display: grid;
	grid-template-columns: max-content auto;
	gap: 2px calc(4 * var(--default-grid-baseline));
	margin: 0;
}

.person-import__figures dt {
	color: var(--color-text-maxcontrast);
}

.person-import__figures dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.person-import__report h4 {
	margin: 0;
	font-weight: bold;
}

.person-import__list {
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
	overflow-wrap: anywhere;
}

.person-import__table {
	border-collapse: collapse;
	/* Nextcloud keeps table cells on one line; names wrap here */
	white-space: normal;
}

.person-import__table th,
.person-import__table td {
	padding: 2px calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.person-import__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.person-import__marks {
	margin: 0;
	overflow-wrap: anywhere;
}

.person-import__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>
