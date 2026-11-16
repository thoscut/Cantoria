<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Statistics for the board (annual report, general meeting), the archive
 * management (state of maintenance) and the conductor (repertoire): stock,
 * rights, repertoire and performances, maintenance, and the annual report.
 *
 * Every figure stands as a number in a table with a caption; the bars
 * beside the numbers are decoration for a quick comparison. The overview
 * and the annual report print separately on A4 in black and white.
 */
import type { Period } from '../statistics.ts'
import type { Statistics } from '../types.ts'

import { mdiAlertCircleOutline, mdiDownload, mdiPrinterOutline } from '@mdi/js'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AnnualReport from './AnnualReport.vue'
import StatisticsBar from './StatisticsBar.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchStatistics, statisticsCsvUrl } from '../api.ts'
import { focusElement } from '../focus.ts'
import { formatDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { releaseStatusLabel, repertoireClassLabel, roleLabel } from '../labels.ts'
import {
	choosePeriodYear,
	figure,
	formatNumber,
	formatShare,
	maxOf,
	periodYears,
	share,
	sumOf,
	TASK_AGE_KEYS,
} from '../statistics.ts'
import { choiceLabel, isModuleActive, openEntry } from '../store.ts'

const data = ref<Statistics | null>(null)
const loading = ref(false)
const error = ref('')
const announcement = ref('')
/** The chosen period; null until the server has sent its default. */
const period = ref<Period | null>(null)

/** Number of the latest request: an older answer that arrives later is dropped. */
let latest = 0

async function load(): Promise<void> {
	const request = ++latest
	loading.value = true
	error.value = ''
	try {
		const result = await fetchStatistics(period.value?.from ?? null, period.value?.to ?? null)
		if (request !== latest) {
			return
		}
		data.value = result
		period.value = { ...result.period }
		announcement.value = t('cantoria', 'Statistics for {from} to {to} loaded.', { from: result.period.from, to: result.period.to })
	} catch (caught) {
		if (request === latest) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (request === latest) {
			loading.value = false
		}
	}
}

const reportView = useTemplateRef<InstanceType<typeof AnnualReport>>('annualReport')

/** Reload the statistics and the annual report. */
function reload(): void {
	load()
	reportView.value?.load()
}

// ---- period ----

const currentYear = computed(() => (data.value ? Number(data.value.today.slice(0, 4)) : new Date().getFullYear()))

const yearOptions = computed(() => periodYears(data.value?.range.first ?? currentYear.value, currentYear.value)
	.map((year) => ({ id: year, label: String(year) })))

/**
 * Choose the first or the last year of the period and reload.
 *
 * @param changed - which year
 * @param option - the chosen year
 */
function chooseYear(changed: 'from' | 'to', option: { id: number } | null): void {
	if (option === null || period.value === null) {
		return
	}
	const next = choosePeriodYear(period.value, changed, option.id)
	if (next.from !== period.value.from || next.to !== period.value.to) {
		period.value = next
		load()
	}
}

const fromOption = computed({
	get: () => yearOptions.value.find((option) => option.id === period.value?.from) ?? null,
	set: (option: { id: number } | null) => chooseYear('from', option),
})

const toOption = computed({
	get: () => yearOptions.value.find((option) => option.id === period.value?.to) ?? null,
	set: (option: { id: number } | null) => chooseYear('to', option),
})

const periodText = computed(() => (period.value ? `${period.value.from}–${period.value.to}` : ''))

const csvUrl = computed(() => statisticsCsvUrl(period.value?.from ?? null, period.value?.to ?? null))

// ---- printing ----

/** What a print shows: the overview or the annual report. */
const printMode = ref<'overview' | 'report'>('overview')
const largePrint = ref(false)

/**
 * Print the overview or the annual report.
 *
 * @param mode - what to print
 */
async function print(mode: 'overview' | 'report'): Promise<void> {
	printMode.value = mode
	await nextTick()
	window.print()
}

/** After printing, the browser's own print command prints the overview again. */
function afterPrint(): void {
	printMode.value = 'overview'
}

onMounted(() => {
	window.addEventListener('afterprint', afterPrint)
	load()
})
onBeforeUnmount(() => window.removeEventListener('afterprint', afterPrint))

// ---- sections ----

/** Figures of switched-off modules are left out (the data stay). */
const shows = computed(() => ({
	validVersion: isModuleActive('valid_version'),
	licenses: isModuleActive('rights'),
	portal: isModuleActive('portal'),
	printStation: isModuleActive('print_station'),
	publication: isModuleActive('publication'),
	concerts: isModuleActive('concerts'),
	tasks: isModuleActive('tasks'),
	proposals: isModuleActive('proposals'),
	checksums: isModuleActive('checksums'),
	protection: isModuleActive('protection_terms'),
}))

const sections = computed(() => [
	{ id: 'statistics-stock', label: t('cantoria', 'Stock') },
	{ id: 'statistics-rights', label: t('cantoria', 'Rights') },
	...(shows.value.concerts ? [{ id: 'statistics-repertoire', label: t('cantoria', 'Repertoire and performances') }] : []),
	{ id: 'statistics-maintenance', label: t('cantoria', 'Maintenance') },
	{ id: 'statistics-report', label: t('cantoria', 'Annual report') },
])

/**
 * Move to a section: its heading gets the focus, so that keyboard and
 * screen reader users continue there.
 *
 * @param event - click on the link
 * @param id - id of the heading
 */
function goTo(event: MouseEvent, id: string): void {
	const heading = document.getElementById(id)
	if (heading !== null) {
		event.preventDefault()
		focusElement(heading)
	}
}

// ---- stock ----

const keyFigures = computed(() => {
	const stock = data.value?.stock
	if (!stock) {
		return []
	}
	const ofCopies = t('cantoria', 'of the copies')
	const ofActive = t('cantoria', 'of the active copies')
	return [
		{ label: t('cantoria', 'Works'), value: stock.works, share: null, of: '' },
		{ label: t('cantoria', 'Versions'), value: stock.versions, share: null, of: '' },
		{ label: t('cantoria', 'Copies'), value: stock.copies, share: null, of: '' },
		{ label: t('cantoria', 'Active copies'), value: stock.active, share: share(stock.active, stock.copies), of: ofCopies },
		{ label: t('cantoria', 'Active copies with a file'), value: stock.activeWithFile, share: share(stock.activeWithFile, stock.active), of: ofActive },
		{ label: t('cantoria', 'Active copies with MusicXML'), value: stock.activeWithMusicxml, share: share(stock.activeWithMusicxml, stock.active), of: ofActive },
		{ label: t('cantoria', 'Internal preservation copies'), value: stock.preservationCopies, share: share(stock.preservationCopies, stock.copies), of: ofCopies },
	]
})

const COPY_STATUSES = ['active', 'retired', 'missing', 'lent'] as const

const binders = computed(() => data.value?.stock.binders ?? [])
const bindersMax = computed(() => maxOf(binders.value, (row) => row.active))
const hasOtherStatus = computed(() => binders.value.some((row) => row.other > 0))

const additions = computed(() => data.value?.stock.additions ?? [])
const additionsMax = computed(() => maxOf(additions.value, (row) => row.total))

// ---- rights ----

const rightsRows = computed(() => (data.value?.rights.status ?? []).map((row) => ({
	...row,
	label: row.status === 'none' ? t('cantoria', 'No rights status') : choiceLabel('version', 'rights_status', row.status),
})))
const rightsMax = computed(() => maxOf(rightsRows.value, (row) => row.total))

const validRows = computed(() => {
	const valid = data.value?.rights.validVersions
	if (!valid) {
		return []
	}
	return [
		{ label: t('cantoria', 'Works'), value: valid.works },
		{ label: t('cantoria', 'With a valid version'), value: valid.withValid },
		{ label: t('cantoria', 'Without a valid version'), value: valid.withoutValid },
		{ label: t('cantoria', 'Without a valid version, with several versions'), value: valid.withoutValidSeveral },
		{ label: t('cantoria', 'Current repertoire without a valid version'), value: valid.currentWithoutValid },
	]
})

const licenseRows = computed(() => {
	const licenses = data.value?.rights.licenses
	if (!licenses) {
		return []
	}
	return [
		{ label: t('cantoria', 'Valid'), value: licenses.active },
		{ label: n('cantoria', 'Ending within %n day', 'Ending within %n days', licenses.endingDays), value: licenses.ending },
		{ label: t('cantoria', 'Ended'), value: licenses.ended },
		{ label: t('cantoria', 'Not valid yet'), value: licenses.future },
	]
})
const licensesMax = computed(() => maxOf(licenseRows.value, (row) => row.value))

const RELEASE_STATUSES = ['ok', 'remove', 'add', 'not_decided', 'other_version_valid', 'blocked'] as const

const releaseRows = computed(() => RELEASE_STATUSES.map((status) => ({
	status,
	label: releaseStatusLabel(status),
	portal: figure(data.value?.rights.portal, status),
	// the print station has no "present" state
	printStation: status === 'ok' || status === 'remove' ? null : figure(data.value?.rights.printStation, status),
})))

const PUBLICATION_STATUSES = ['free', 'blocked', 'internal', 'to_clarify', 'none'] as const

const publicationRows = computed(() => PUBLICATION_STATUSES.map((status) => ({
	status,
	label: status === 'none' ? t('cantoria', 'Not set') : choiceLabel('version', 'publication_status', status),
	value: figure(data.value?.rights.publication, status),
})))
const publicationMax = computed(() => maxOf(publicationRows.value, (row) => row.value))

// ---- repertoire ----

const years = computed(() => data.value?.repertoire.years ?? [])
const piecesMax = computed(() => maxOf(years.value, (row) => row.pieces))
const topWorks = computed(() => data.value?.repertoire.topWorks ?? [])
const topWorksMax = computed(() => maxOf(topWorks.value, (row) => row.performances))
const topComposers = computed(() => data.value?.repertoire.topComposers ?? [])
const topComposersMax = computed(() => maxOf(topComposers.value, (row) => row.performances))

const REPERTOIRE_CLASSES = ['current', 'dormant', 'earlier', 'none'] as const

const classRows = computed(() => REPERTOIRE_CLASSES.map((value) => ({
	value,
	label: repertoireClassLabel(value),
	works: figure(data.value?.repertoire.classes, value),
})))
const classesMax = computed(() => maxOf(classRows.value, (row) => row.works))

const longNotSung = computed(() => data.value?.repertoire.longNotSung ?? [])

// ---- maintenance ----

const tasks = computed(() => data.value?.maintenance.tasks ?? [])
const taskTotals = computed(() => ({
	open: sumOf(tasks.value, (row) => row.open),
	answered: sumOf(tasks.value, (row) => row.answered),
	ages: TASK_AGE_KEYS.map((key) => sumOf(tasks.value, (row) => row[key])),
}))

const inboxRows = computed(() => {
	const maintenance = data.value?.maintenance
	if (!maintenance) {
		return []
	}
	return [
		...(shows.value.proposals
			? [
				{ label: t('cantoria', 'Open proposals'), value: maintenance.proposals.open },
				{ label: t('cantoria', 'Open proposals older than 30 days'), value: maintenance.proposals.olderThan30Days },
			]
			: []),
		{ label: t('cantoria', 'Files in the archive folder'), value: maintenance.checksums.files },
		...(shows.value.checksums
			? [
				{ label: t('cantoria', 'Files with a checksum'), value: maintenance.checksums.hashed },
				{ label: t('cantoria', 'Files without a checksum yet'), value: maintenance.checksums.pending },
				{ label: t('cantoria', 'Files changed without modification'), value: maintenance.checksums.mismatches },
			]
			: []),
	]
})

const protection = computed(() => data.value?.maintenance.protection ?? null)
const protectionYears = computed(() => (protection.value?.years ?? []).filter((row) => row.versions > 0))
const protectionMax = computed(() => maxOf(protectionYears.value, (row) => row.versions))

/**
 * Rights status of a protection row as text.
 *
 * @param status - rights status key or null
 * @param confirmed - whether it is confirmed
 */
function rightsText(status: string | null, confirmed: boolean): string {
	const label = status ? choiceLabel('version', 'rights_status', status) : t('cantoria', 'No rights status')
	return confirmed ? t('cantoria', '{status}, confirmed', { status: label }) : label
}

/**
 * Age group heading of the task table.
 *
 * @param key - age group
 */
function ageLabel(key: typeof TASK_AGE_KEYS[number]): string {
	switch (key) {
	case 'days30':
		return t('cantoria', 'Up to 30 days old')
	case 'days90':
		return t('cantoria', '31 to 90 days old')
	case 'days365':
		return t('cantoria', '91 to 365 days old')
	default:
		return t('cantoria', 'Older')
	}
}
</script>

<template>
	<div class="statistics-view"
		:class="{ 'statistics-view--large': largePrint, 'statistics-view--print-report': printMode === 'report' }">
		<ViewHeader :title="t('cantoria', 'Statistics')"
			:description="t('cantoria', 'Figures for the board, the archive management and the conductor: stock, rights, repertoire and the state of maintenance. They contain no personal data of choir members.')"
			reloadable
			:loading="loading"
			@reload="reload">
			<template #actions>
				<NcButton :href="csvUrl" download>
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('cantoria', 'Statistics as CSV') }}
				</NcButton>
				<NcButton variant="secondary" :disabled="data === null" @click="print('overview')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPrinterOutline" />
					</template>
					{{ t('cantoria', 'Print') }}
				</NcButton>
			</template>
		</ViewHeader>

		<div class="statistics-view__content">
			<p class="hidden-visually" aria-live="polite">
				{{ announcement }}
			</p>

			<NcEmptyContent v-if="data === null && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="data === null && error"
				:name="t('cantoria', 'The statistics could not be loaded')"
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

			<template v-else-if="data !== null">
				<div class="statistics-view__overview" :aria-busy="loading">
					<NcNoteCard v-if="error" type="error" role="alert">
						{{ error }}
					</NcNoteCard>

					<div class="statistics-view__controls no-print">
						<NcSelect v-model="fromOption"
							class="statistics-view__year"
							:input-label="t('cantoria', 'From year')"
							:options="yearOptions"
							:clearable="false" />
						<NcSelect v-model="toOption"
							class="statistics-view__year"
							:input-label="t('cantoria', 'To year')"
							:options="yearOptions"
							:clearable="false" />
						<NcCheckboxRadioSwitch v-model="largePrint" type="switch">
							{{ t('cantoria', 'Large print') }}
						</NcCheckboxRadioSwitch>
					</div>

					<p class="statistics-view__period">
						{{ t('cantoria', 'Period {period}, as of {date}. Figures without a year are those of today.', { period: periodText, date: formatDate(data.today) }) }}
					</p>

					<nav class="statistics-view__nav no-print" :aria-label="t('cantoria', 'Sections of the statistics')">
						<ul>
							<li v-for="section in sections" :key="section.id">
								<a :href="'#' + section.id" @click="goTo($event, section.id)">{{ section.label }}</a>
							</li>
						</ul>
					</nav>

					<!-- Stock -->
					<section class="statistics-view__section" aria-labelledby="statistics-stock">
						<h3 id="statistics-stock">
							{{ t('cantoria', 'Stock') }}
						</h3>
						<table class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Key figures') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Figure') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Number') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Share') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in keyFigures" :key="row.label">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(row.value) }}
									</td>
									<td>
										<template v-if="row.share !== null">
											{{ formatShare(row.share) }} {{ row.of }}
										</template>
									</td>
								</tr>
							</tbody>
						</table>

						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Copies per binder') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Binder') }}
									</th>
									<th v-for="status in COPY_STATUSES" :key="status" scope="col">
										{{ choiceLabel('copy', 'status', status) }}
									</th>
									<th v-if="hasOtherStatus" scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Other status') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Total') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in binders" :key="row.binder">
									<th scope="row">
										{{ row.binder }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.active" :max="bindersMax" />
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.retired) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.missing) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.lent) }}
									</td>
									<td v-if="hasOtherStatus" class="statistics-table__number">
										{{ formatNumber(row.other) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.total) }}
									</td>
								</tr>
							</tbody>
							<tfoot>
								<tr>
									<th scope="row">
										{{ t('cantoria', 'Total') }}
									</th>
									<td v-for="status in COPY_STATUSES" :key="status" class="statistics-table__number">
										{{ formatNumber(figure(data.stock.status, status)) }}
									</td>
									<td v-if="hasOtherStatus" class="statistics-table__number">
										{{ formatNumber(figure(data.stock.status, 'other')) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(data.stock.copies) }}
									</td>
								</tr>
							</tfoot>
						</table>

						<table class="statistics-table">
							<caption>{{ t('cantoria', 'New copies per year') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Year') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Total') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'New accessions') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'From the catalog import') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Other') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in additions" :key="row.year">
									<th scope="row">
										{{ row.year }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.total" :max="additionsMax" />
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.accession) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.import) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.other) }}
									</td>
								</tr>
							</tbody>
						</table>
						<p class="statistics-view__note">
							{{ t('cantoria', 'A copy counts in the year it was recorded: by the accession assistant, by the catalog import or, without an entry in the audit log, when it was created.') }}
						</p>
					</section>

					<!-- Rights -->
					<section class="statistics-view__section" aria-labelledby="statistics-rights">
						<h3 id="statistics-rights">
							{{ t('cantoria', 'Rights') }}
						</h3>
						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Rights status of the versions') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Rights status') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Total') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Confirmed') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Not confirmed') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in rightsRows" :key="row.status">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.total" :max="rightsMax" />
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.confirmed) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.unconfirmed) }}
									</td>
								</tr>
							</tbody>
						</table>

						<table v-if="shows.validVersion" class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Valid version (chosen by the conductor)') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Works') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Number') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in validRows" :key="row.label">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(row.value) }}
									</td>
								</tr>
							</tbody>
						</table>

						<table v-if="shows.licenses" class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Licenses') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Validity') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Licenses') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in licenseRows" :key="row.label">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.value" :max="licensesMax" />
									</td>
								</tr>
							</tbody>
							<tfoot>
								<tr>
									<th scope="row">
										{{ t('cantoria', 'Total') }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(data.rights.licenses.total) }}
									</td>
								</tr>
							</tfoot>
						</table>

						<table v-if="shows.portal || shows.printStation" class="statistics-table">
							<caption>{{ t('cantoria', 'Releases: versions per state of the lists') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'State') }}
									</th>
									<th v-if="shows.portal" scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Singers\' portal') }}
									</th>
									<th v-if="shows.printStation" scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Print station') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in releaseRows" :key="row.status">
									<th scope="row">
										{{ row.label }}
									</th>
									<td v-if="shows.portal" class="statistics-table__number">
										{{ formatNumber(row.portal) }}
									</td>
									<td v-if="shows.printStation" class="statistics-table__number">
										{{ row.printStation === null ? '–' : formatNumber(row.printStation) }}
									</td>
								</tr>
							</tbody>
						</table>

						<table v-if="shows.publication" class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Publication status') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Publication status') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Versions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in publicationRows" :key="row.status">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.value" :max="publicationMax" />
									</td>
								</tr>
							</tbody>
						</table>
					</section>

					<!-- Repertoire and performances -->
					<section v-if="shows.concerts" class="statistics-view__section" aria-labelledby="statistics-repertoire">
						<h3 id="statistics-repertoire">
							{{ t('cantoria', 'Repertoire and performances') }}
						</h3>
						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Concerts and pieces per year') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Year') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Concerts sung') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Planned concerts') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Pieces sung') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Different works') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in years" :key="row.year">
									<th scope="row">
										{{ row.year }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(row.concerts) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.planned) }}
									</td>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.pieces" :max="piecesMax" />
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.works) }}
									</td>
								</tr>
							</tbody>
						</table>
						<p class="statistics-view__note">
							{{ t('cantoria', 'Pieces sung are the documented performances: programme lines of concerts confirmed as sung that are assigned to a work of the catalog.') }}
							<template v-if="data.repertoire.undated > 0">
								{{ n('cantoria', '%n sung concert without a date is not counted in any year.', '%n sung concerts without a date are not counted in any year.', data.repertoire.undated) }}
							</template>
						</p>

						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Most performed works {period}', { period: periodText }) }}</caption>
							<thead>
								<tr>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Rank') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Work') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Performances') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Last performed') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-if="topWorks.length === 0">
									<td colspan="4">
										{{ t('cantoria', 'No documented performances in this period.') }}
									</td>
								</tr>
								<tr v-for="(row, index) in topWorks" :key="row.workId">
									<td class="statistics-table__number">
										{{ index + 1 }}
									</td>
									<th scope="row">
										{{ row.title || t('cantoria', 'Untitled') }}
										<span v-if="row.composer" class="statistics-table__muted">{{ row.composer }}</span>
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.performances" :max="topWorksMax" />
									</td>
									<td class="statistics-table__date">
										{{ formatDate(row.last) }}
									</td>
								</tr>
							</tbody>
						</table>

						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Most performed composers {period}', { period: periodText }) }}</caption>
							<thead>
								<tr>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Rank') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Composer') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Performances') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Works') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-if="topComposers.length === 0">
									<td colspan="4">
										{{ t('cantoria', 'No documented performances in this period.') }}
									</td>
								</tr>
								<tr v-for="(row, index) in topComposers" :key="row.composer">
									<td class="statistics-table__number">
										{{ index + 1 }}
									</td>
									<th scope="row">
										{{ row.composer }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.performances" :max="topComposersMax" />
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.works) }}
									</td>
								</tr>
							</tbody>
						</table>
						<p class="statistics-view__note">
							{{ t('cantoria', 'Performances are performance days. The composer is the one recorded for the work.') }}
						</p>

						<table class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Repertoire classes of the works') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Repertoire') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Works') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in classRows" :key="row.value">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.works" :max="classesMax" />
									</td>
								</tr>
							</tbody>
						</table>
						<p class="statistics-view__note">
							{{ t('cantoria', 'Current: sung within the last {current} years; dormant: within the last {dormant} years; earlier: before that. {fromPerformances} works by documented performances, {fromImport} by the imported catalog.', {
								current: data.repertoire.thresholds.current,
								dormant: data.repertoire.thresholds.dormant,
								fromPerformances: formatNumber(data.repertoire.sources.performances),
								fromImport: formatNumber(data.repertoire.sources.import),
							}) }}
							<template v-if="data.repertoire.thresholds.referenceYear">
								{{ t('cantoria', 'The years are counted back from the fixed reference year {year}.', { year: data.repertoire.thresholds.referenceYear }) }}
							</template>
						</p>

						<table class="statistics-table">
							<caption>{{ t('cantoria', 'Not sung for a long time') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Shelf mark') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Work') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Repertoire') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Performances') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Last performed') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-if="longNotSung.length === 0">
									<td colspan="5">
										{{ t('cantoria', 'No such works.') }}
									</td>
								</tr>
								<tr v-for="row in longNotSung" :key="row.workId">
									<td class="statistics-table__shelfmark">
										<button type="button"
											class="statistics-table__link"
											:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: row.shelfmark })"
											@click="openEntry(row.copyId)">
											{{ row.shelfmark }}
										</button>
									</td>
									<th scope="row">
										{{ row.title || t('cantoria', 'Untitled') }}
										<span v-if="row.composer" class="statistics-table__muted">{{ row.composer }}</span>
									</th>
									<td>{{ repertoireClassLabel(row.class) }}</td>
									<td class="statistics-table__number">
										{{ row.performances === null ? '–' : formatNumber(row.performances) }}
									</td>
									<td class="statistics-table__date">
										{{ row.last ? formatDate(row.last) : '–' }}
									</td>
								</tr>
							</tbody>
						</table>
						<p class="statistics-view__note">
							{{ t('cantoria', 'Works sung before, but not within the current repertoire, that can be sung from the archive; the most often sung first.') }}
							<template v-if="data.repertoire.longNotSungTotal > longNotSung.length">
								{{ t('cantoria', 'Showing {shown} of {total}.', { shown: longNotSung.length, total: data.repertoire.longNotSungTotal }) }}
							</template>
						</p>
					</section>

					<!-- Maintenance -->
					<section class="statistics-view__section" aria-labelledby="statistics-maintenance">
						<h3 id="statistics-maintenance">
							{{ t('cantoria', 'Maintenance') }}
						</h3>
						<table v-if="shows.tasks" class="statistics-table">
							<caption>{{ t('cantoria', 'Open tasks by role') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Role') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Open') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Answered') }}
									</th>
									<th v-for="key in TASK_AGE_KEYS"
										:key="key"
										scope="col"
										class="statistics-table__number">
										{{ ageLabel(key) }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in tasks" :key="row.addressee">
									<th scope="row">
										{{ roleLabel(row.addressee) }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(row.open) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(row.answered) }}
									</td>
									<td v-for="key in TASK_AGE_KEYS" :key="key" class="statistics-table__number">
										{{ formatNumber(row[key]) }}
									</td>
								</tr>
							</tbody>
							<tfoot>
								<tr>
									<th scope="row">
										{{ t('cantoria', 'Total') }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(taskTotals.open) }}
									</td>
									<td class="statistics-table__number">
										{{ formatNumber(taskTotals.answered) }}
									</td>
									<td v-for="(value, index) in taskTotals.ages"
										:key="TASK_AGE_KEYS[index]"
										class="statistics-table__number">
										{{ formatNumber(value) }}
									</td>
								</tr>
							</tfoot>
						</table>

						<table class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Inbox and checksums') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Figure') }}
									</th>
									<th scope="col" class="statistics-table__number">
										{{ t('cantoria', 'Number') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in inboxRows" :key="row.label">
									<th scope="row">
										{{ row.label }}
									</th>
									<td class="statistics-table__number">
										{{ formatNumber(row.value) }}
									</td>
								</tr>
							</tbody>
						</table>

						<table v-if="protection && shows.protection" class="statistics-table">
							<caption>{{ t('cantoria', 'Protection terms ending {period}', { period: periodText }) }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Protected until') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Shelf marks') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Work') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Rights status') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-if="protection.rows.length === 0">
									<td colspan="4">
										{{ t('cantoria', 'No protection term ends in this period.') }}
									</td>
								</tr>
								<tr v-for="row in protection.rows" :key="row.versionId">
									<td class="statistics-table__date">
										{{ formatDate(row.until) }}
									</td>
									<td class="statistics-table__shelfmark">
										{{ row.copies.map((copy) => copy.shelfmark).join(', ') }}
									</td>
									<th scope="row">
										{{ row.title || t('cantoria', 'Untitled') }}
										<span v-if="row.composer" class="statistics-table__muted">{{ row.composer }}</span>
									</th>
									<td>{{ rightsText(row.rightsStatus, row.confirmed) }}</td>
								</tr>
							</tbody>
						</table>
						<table v-if="protectionYears.length > 1" class="statistics-table statistics-table--narrow">
							<caption>{{ t('cantoria', 'Protection terms ending per year') }}</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('cantoria', 'Year') }}
									</th>
									<th scope="col">
										{{ t('cantoria', 'Versions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in protectionYears" :key="row.year">
									<th scope="row">
										{{ row.year }}
									</th>
									<td class="statistics-table__bar">
										<StatisticsBar :value="row.versions" :max="protectionMax" />
									</td>
								</tr>
							</tbody>
						</table>
						<p v-if="protection" class="statistics-view__note">
							{{ t('cantoria', 'By the life dates of the persons involved, a proposal only and no legal advice.') }}
							<template v-if="protection.total - protection.omitted > protection.rows.length">
								{{ t('cantoria', 'Showing {shown} of {total}.', { shown: protection.rows.length, total: protection.total - protection.omitted }) }}
							</template>
							<template v-if="protection.omitted > 0">
								{{ n('cantoria', '%n internal preservation copy is counted but not listed.', '%n internal preservation copies are counted but not listed.', protection.omitted) }}
							</template>
						</p>
					</section>
				</div>

				<div class="statistics-view__report">
					<AnnualReport ref="annualReport"
						v-model:large-print="largePrint"
						:today="data.today"
						:first-year="data.range.first"
						@print="print('report')" />
				</div>
			</template>
		</div>
	</div>
</template>

<style scoped>
.statistics-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.statistics-view__overview {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
}

.statistics-view__controls {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.statistics-view__year {
	width: 10em;
}

.statistics-view__period,
.statistics-view__note {
	max-width: 80ch;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.statistics-view__nav ul {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(4 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.statistics-view__nav a {
	display: inline-block;
	min-height: var(--default-clickable-area);
	line-height: var(--default-clickable-area);
	color: var(--color-main-text);
	text-decoration: underline;
}

.statistics-view__nav a:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.statistics-view__section {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.statistics-view__section h3 {
	margin: calc(2 * var(--default-grid-baseline)) 0 0;
	padding-bottom: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	font-size: 1.25em;
	font-weight: bold;
}

.statistics-table {
	width: 100%;
	max-width: 60em;
	border-collapse: collapse;
}

.statistics-table--narrow {
	max-width: 40em;
}

.statistics-table caption {
	padding-bottom: var(--default-grid-baseline);
	font-weight: bold;
	text-align: start;
}

.statistics-table th,
.statistics-table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	overflow-wrap: break-word;
}

.statistics-table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.statistics-table tbody th {
	font-weight: normal;
}

.statistics-table tfoot th,
.statistics-table tfoot td {
	font-weight: bold;
}

.statistics-table__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.statistics-table__bar {
	min-width: 8em;
}

.statistics-table__date,
.statistics-table__shelfmark {
	white-space: nowrap;
}

.statistics-table__muted {
	display: block;
	color: var(--color-text-maxcontrast);
}

.statistics-table__link {
	min-height: auto;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-decoration: underline;
	cursor: pointer;
}

.statistics-table__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

@media print {
	.statistics-view__content {
		gap: 4mm;
		padding: 0;
		font-size: 11pt;
	}

	.statistics-view--large .statistics-view__content {
		font-size: 15pt;
	}

	.statistics-view__period,
	.statistics-view__note,
	.statistics-table thead th,
	.statistics-table__muted {
		color: #000;
	}

	.statistics-table {
		max-width: none;
		break-inside: auto;
	}

	.statistics-table th,
	.statistics-table td {
		padding: 1.2mm 2mm;
		border: 0.3mm solid #000;
	}

	.statistics-table tr {
		break-inside: avoid;
	}

	.statistics-table thead {
		display: table-header-group;
	}

	.statistics-table caption,
	.statistics-view__section h3 {
		break-after: avoid;
	}

	.statistics-table__link {
		text-decoration: none;
	}

	/* The overview and the annual report print separately */
	.statistics-view:not(.statistics-view--print-report) .statistics-view__report,
	.statistics-view--print-report .statistics-view__overview,
	.statistics-view--print-report :deep(.view-header) {
		display: none !important;
	}
}
</style>
