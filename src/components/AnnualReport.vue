<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The annual report of one year for the board and the general meeting:
 * concerts with their dates and pieces, new accessions, licenses, open
 * rights questions and the stock. Printable on A4 in black and white (also
 * in large print) and as CSV. The figures about rights and stock are those
 * of today: the archive keeps no earlier states of them.
 */
import type { AnnualReport, ReportLicense } from '../types.ts'

import { mdiAlertCircleOutline, mdiBookOpenPageVariantOutline, mdiDownload, mdiPrinterOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { annualReportCsvUrl, errorMessage, fetchAnnualReport } from '../api.ts'
import { formatDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { licenseUseLabel } from '../labels.ts'
import { defaultReportYear, formatNumber, yearsBetween } from '../statistics.ts'
import { readStored } from '../storage.ts'

const props = defineProps<{
	/** today ("YYYY-MM-DD", from the server) */
	today: string
	/** first year with data */
	firstYear: number
}>()

const largePrint = defineModel<boolean>('largePrint', { default: false })

const emit = defineEmits<{
	print: []
}>()

const report = ref<AnnualReport | null>(null)
const loading = ref(false)
const error = ref('')
const announcement = ref('')
const year = ref(defaultReportYear(props.today))

const currentYear = computed(() => Number(props.today.slice(0, 4)))

const yearOptions = computed(() => yearsBetween(Math.min(props.firstYear, currentYear.value - 9), currentYear.value)
	.reverse()
	.map((value) => ({ id: value, label: String(value) })))

const yearOption = computed({
	get: () => yearOptions.value.find((option) => option.id === year.value) ?? null,
	set: (option: { id: number } | null) => {
		if (option) {
			year.value = option.id
		}
	},
})

/** Number of the latest request: an older answer that arrives later is dropped. */
let latest = 0

async function load(): Promise<void> {
	const request = ++latest
	loading.value = true
	error.value = ''
	try {
		const result = await fetchAnnualReport(year.value)
		if (request !== latest) {
			return
		}
		report.value = result
		announcement.value = t('cantoria', 'Annual report {year} loaded.', { year: result.year })
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

onMounted(load)
watch(year, load)

// The view reloads the report together with the statistics
defineExpose({ load })

/** The year in figures. */
const summary = computed(() => {
	const r = report.value
	if (r === null) {
		return []
	}
	const rows = [
		{ label: t('cantoria', 'Concerts sung'), value: r.totals.concerts },
		{ label: t('cantoria', 'Planned concerts'), value: r.totals.planned },
		{ label: t('cantoria', 'Pieces sung'), value: r.totals.pieces },
		{ label: t('cantoria', 'Different works'), value: r.totals.works },
		{ label: t('cantoria', 'New accessions'), value: r.additions.accession },
		{ label: t('cantoria', 'From the catalog import'), value: r.additions.import },
		{ label: t('cantoria', 'Other new copies'), value: r.additions.other },
		{ label: t('cantoria', 'Licenses valid on {date}', { date: formatDate(r.licenses.validOn) }), value: r.licenses.valid },
		{ label: t('cantoria', 'Licenses that started in {year}', { year: r.year }), value: r.licenses.started.length },
		{ label: t('cantoria', 'Licenses that end in {year}', { year: r.year }), value: r.licenses.ended.length },
	]
	// Figures that are always zero for most choirs only when they are not
	return rows.filter((row, index) => row.value > 0 || ![1, 5, 6].includes(index))
})

const openRights = computed(() => {
	const open = report.value?.openRights
	if (!open) {
		return []
	}
	return [
		{ label: t('cantoria', 'Rights status open'), value: open.open },
		{ label: t('cantoria', 'No rights status'), value: open.none },
		{ label: t('cantoria', 'Public domain, not confirmed'), value: open.publicDomainUnconfirmed },
		{ label: t('cantoria', 'Protected, not confirmed'), value: open.protectedUnconfirmed },
		{ label: t('cantoria', 'Marked protected, but public domain by the life dates'), value: open.protectionEnded },
		{ label: t('cantoria', 'Marked public domain, but the persons do not allow it'), value: open.conflicts },
		{ label: t('cantoria', 'Open tasks about rights'), value: open.rightsTasks },
	]
})

const stock = computed(() => {
	const s = report.value?.stock
	if (!s) {
		return []
	}
	return [
		{ label: t('cantoria', 'Works'), value: s.works },
		{ label: t('cantoria', 'Versions'), value: s.versions },
		{ label: t('cantoria', 'Copies'), value: s.copies },
		{ label: t('cantoria', 'Active copies'), value: s.active },
	]
})

const licenseTables = computed(() => {
	const r = report.value
	if (r === null) {
		return []
	}
	return [
		{ key: 'started', caption: t('cantoria', 'Licenses that started in {year}', { year: r.year }), rows: r.licenses.started },
		{ key: 'ended', caption: t('cantoria', 'Licenses that end in {year}', { year: r.year }), rows: r.licenses.ended },
	]
})

// ---- the report as a booklet (design proposal V7, November 2026) ----

const makingBooklet = ref(false)

/**
 * The annual report as a designed PDF: cover, the year in large figures,
 * the concerts as a timeline, rights, stock and the lists.
 */
async function makeBooklet(): Promise<void> {
	const r = report.value
	if (r === null || makingBooklet.value) {
		return
	}
	makingBooklet.value = true
	error.value = ''
	try {
		const { buildReport } = await import('../report/build.ts')
		const title = t('cantoria', 'Annual report {year}', { year: r.year })
		const built = await buildReport({
			title,
			// the line above the title of the programme booklet, e.g. the name of the choir
			overline: readStored('booklet.overline') ?? '',
			subtitle: t('cantoria', 'As of {date}', { date: formatDate(r.today) }),
			figuresHeading: t('cantoria', 'The year in figures'),
			figures: [
				{ label: t('cantoria', 'Concerts sung'), value: r.totals.concerts },
				{ label: t('cantoria', 'Pieces sung'), value: r.totals.pieces },
				{ label: t('cantoria', 'Different works'), value: r.totals.works },
				{ label: t('cantoria', 'New copies'), value: r.additions.total },
				{ label: t('cantoria', 'Active copies'), value: r.stock.active },
				{ label: t('cantoria', 'Licenses valid on {date}', { date: formatDate(r.licenses.validOn) }), value: r.licenses.valid },
			].map((figure) => ({ label: figure.label, value: formatNumber(figure.value) })),
			concertsHeading: t('cantoria', 'Concerts'),
			concerts: r.concerts.map((concert) => ({
				date: concertDate(concert.date),
				title: concert.title || t('cantoria', 'Untitled'),
				detail: [concert.location, n('cantoria', '%n piece', '%n pieces', concert.pieces), concert.status === 'sung' ? '' : t('cantoria', 'Planned')]
					.filter((part) => part).join(' · '),
				sung: concert.status === 'sung',
			})),
			noConcerts: t('cantoria', 'No concerts in this year.'),
			tables: [
				{ heading: t('cantoria', 'Open rights questions'), note: t('cantoria', 'As of today: the archive keeps no earlier states of these figures.'), rows: openRights.value },
				{ heading: t('cantoria', 'Stock'), note: '', rows: stock.value },
			].map((entry) => ({ ...entry, rows: entry.rows.map((row) => ({ label: row.label, value: formatNumber(row.value) })) })),
			lists: [
				{
					heading: t('cantoria', 'New accessions'),
					items: r.accessions.map((accession) => ({
						title: [accession.shelfmark, accession.title].filter((part) => part).join(' '),
						detail: [accession.composer, formatDate(accession.date)].filter((part) => part).join(' · '),
					})),
					more: '',
				},
				...licenseTables.value.map((entry) => ({
					heading: entry.caption,
					items: entry.rows.map((license) => ({
						title: [license.title, license.composer].filter((part) => part).join(' · ') || '–',
						detail: [license.holder, usesText(license), license.validUntil ? t('cantoria', 'until {date}', { date: formatDate(license.validUntil) }) : '']
							.filter((part) => part).join(' · '),
					})),
					more: '',
				})),
			],
		})
		const url = URL.createObjectURL(new Blob([built.bytes as Uint8Array<ArrayBuffer>], { type: 'application/pdf' }))
		const link = document.createElement('a')
		link.href = url
		link.download = `${title}.pdf`
		link.rel = 'noopener'
		document.body.appendChild(link)
		link.click()
		link.remove()
		setTimeout(() => URL.revokeObjectURL(url), 60000)
		announcement.value = n('cantoria', 'The booklet has %n page. The download has started.', 'The booklet has %n pages. The download has started.', built.pages)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		makingBooklet.value = false
	}
}

/**
 * Uses of a license as text.
 *
 * @param license - license row
 */
function usesText(license: ReportLicense): string {
	return license.uses.map(licenseUseLabel).join(', ') || '–'
}

/**
 * Date of a concert: a full date in the user's format, a year as it is.
 *
 * @param date - "YYYY-MM-DD", "YYYY" or null
 */
function concertDate(date: string | null): string {
	return date === null ? '–' : formatDate(date)
}
</script>

<template>
	<section class="annual-report" aria-labelledby="statistics-report">
		<h3 id="statistics-report" class="annual-report__heading no-print">
			{{ t('cantoria', 'Annual report') }}
		</h3>
		<p class="annual-report__intro no-print">
			{{ t('cantoria', 'A summary of one year for the board and the general meeting: concerts, pieces sung, new accessions, licenses and open rights questions. It prints on A4 in black and white.') }}
		</p>
		<div class="annual-report__controls no-print">
			<NcSelect v-model="yearOption"
				class="annual-report__year"
				:input-label="t('cantoria', 'Year of the report')"
				:options="yearOptions"
				:clearable="false" />
			<NcCheckboxRadioSwitch v-model="largePrint" type="switch">
				{{ t('cantoria', 'Large print') }}
			</NcCheckboxRadioSwitch>
			<NcButton :disabled="report === null || loading" @click="emit('print')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPrinterOutline" />
				</template>
				{{ t('cantoria', 'Print annual report') }}
			</NcButton>
			<NcButton :disabled="report === null || loading || makingBooklet" @click="makeBooklet">
				<template #icon>
					<NcLoadingIcon v-if="makingBooklet" :size="20" />
					<NcIconSvgWrapper v-else :path="mdiBookOpenPageVariantOutline" />
				</template>
				{{ t('cantoria', 'Annual report as a booklet (PDF)') }}
			</NcButton>
			<NcButton :href="annualReportCsvUrl(year)" download>
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('cantoria', 'Annual report as CSV') }}
			</NcButton>
		</div>
		<p class="hidden-visually" aria-live="polite">
			{{ announcement }}
		</p>

		<NcEmptyContent v-if="report === null && loading" :name="t('cantoria', 'Loading …')">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="report === null && error"
			:name="t('cantoria', 'The annual report could not be loaded')"
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
		<div v-else-if="report !== null" class="annual-report__body" :aria-busy="loading">
			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
			<h4 class="annual-report__title">
				{{ t('cantoria', 'Annual report {year}', { year: report.year }) }}
			</h4>
			<p class="annual-report__meta">
				{{ t('cantoria', 'Music archive, as of {date}', { date: formatDate(report.today) }) }}
			</p>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('cantoria', 'The year in figures') }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Figure') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('cantoria', 'Number') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in summary" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>

			<table class="annual-report__table">
				<caption>{{ t('cantoria', 'Concerts {year}', { year: report.year }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Date') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Concert') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Location') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Status') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('cantoria', 'Pieces') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="report.concerts.length === 0">
						<td colspan="5">
							{{ t('cantoria', 'No concerts in this year.') }}
						</td>
					</tr>
					<tr v-for="concert in report.concerts" :key="concert.id">
						<td class="annual-report__date">
							{{ concertDate(concert.date) }}
						</td>
						<th scope="row">
							{{ concert.title || t('cantoria', 'Untitled') }}
						</th>
						<td>{{ concert.location || '–' }}</td>
						<td>{{ concert.status === 'sung' ? t('cantoria', 'Sung') : t('cantoria', 'Planned') }}</td>
						<td class="annual-report__number">
							{{ formatNumber(concert.pieces) }}
						</td>
					</tr>
				</tbody>
			</table>
			<p class="annual-report__note">
				{{ t('cantoria', 'Pieces of a sung concert: the documented performances of works in the catalog; of a planned concert: the pieces on the programme.') }}
			</p>

			<table class="annual-report__table">
				<caption>{{ t('cantoria', 'New accessions {year}', { year: report.year }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Recorded on') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Shelf mark') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Title') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Composer') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="report.accessions.length === 0">
						<td colspan="4">
							{{ t('cantoria', 'No new accessions in this year.') }}
						</td>
					</tr>
					<tr v-for="accession in report.accessions" :key="accession.copyId">
						<td class="annual-report__date">
							{{ formatDate(accession.date) }}
						</td>
						<th scope="row" class="annual-report__shelfmark">
							{{ accession.shelfmark }}
						</th>
						<td>{{ accession.title || t('cantoria', 'Untitled') }}</td>
						<td>{{ accession.composer || '–' }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="report.accessionsOmitted > 0" class="annual-report__note">
				{{ n('cantoria', '%n internal preservation copy is counted but not listed.', '%n internal preservation copies are counted but not listed.', report.accessionsOmitted) }}
			</p>

			<table v-for="table in licenseTables" :key="table.key" class="annual-report__table">
				<caption>{{ table.caption }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Work') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'License holder') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Uses') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Valid from') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Valid until') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Shelf marks') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="table.rows.length === 0">
						<td colspan="6">
							{{ t('cantoria', 'None.') }}
						</td>
					</tr>
					<tr v-for="license in table.rows" :key="license.licenseId">
						<th scope="row">
							{{ license.title || t('cantoria', 'Untitled') }}
							<span v-if="license.composer" class="annual-report__muted">{{ license.composer }}</span>
						</th>
						<td>{{ license.holder || '–' }}</td>
						<td>{{ usesText(license) }}</td>
						<td class="annual-report__date">
							{{ license.validFrom ? formatDate(license.validFrom) : '–' }}
						</td>
						<td class="annual-report__date">
							{{ license.validUntil ? formatDate(license.validUntil) : t('cantoria', 'without end') }}
						</td>
						<td>{{ license.shelfmarks.join(', ') || '–' }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="report.licenses.omitted > 0" class="annual-report__note">
				{{ n('cantoria', '%n license of internal preservation copies is counted but not listed.', '%n licenses of internal preservation copies are counted but not listed.', report.licenses.omitted) }}
			</p>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('cantoria', 'Open rights questions (as of {date})', { date: formatDate(report.today) }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Question') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('cantoria', 'Versions or tasks') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in openRights" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('cantoria', 'Stock (as of {date})', { date: formatDate(report.today) }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Figure') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('cantoria', 'Number') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in stock" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>

<style scoped>
.annual-report {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.annual-report__heading {
	margin: 0;
	font-size: 1.25em;
	font-weight: bold;
}

.annual-report__intro {
	max-width: 80ch;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.annual-report__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.annual-report__year {
	width: 12em;
}

.annual-report__body {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.annual-report__title {
	margin: 0;
	font-size: 1.15em;
	font-weight: bold;
}

.annual-report__meta,
.annual-report__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.annual-report__table {
	width: 100%;
	border-collapse: collapse;
}

.annual-report__table--figures {
	max-width: 40em;
}

.annual-report__table caption {
	padding-bottom: var(--default-grid-baseline);
	font-weight: bold;
	text-align: start;
}

.annual-report__table th,
.annual-report__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	overflow-wrap: break-word;
}

.annual-report__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.annual-report__table tbody th {
	font-weight: normal;
}

.annual-report__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.annual-report__date,
.annual-report__shelfmark {
	white-space: nowrap;
}

.annual-report__muted {
	display: block;
	color: var(--color-text-maxcontrast);
}

@media print {
	.annual-report__meta,
	.annual-report__note,
	.annual-report__muted,
	.annual-report__table thead th {
		color: #000;
	}

	.annual-report__title {
		font-size: 1.6em;
	}

	.annual-report__table th,
	.annual-report__table td {
		padding: 1.2mm 2mm;
		border: 0.3mm solid #000;
	}

	.annual-report__table {
		break-inside: auto;
	}

	.annual-report__table tr {
		break-inside: avoid;
	}

	.annual-report__table thead {
		display: table-header-group;
	}

	.annual-report__table caption {
		break-after: avoid;
	}
}
</style>
