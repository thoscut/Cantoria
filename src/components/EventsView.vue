<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * List of concerts or song lists. Imported and sung concerts are history;
 * planned events can be created and edited by the conductor and the
 * catalog role. The upcoming concerts can be added to the user's Nextcloud
 * calendar or downloaded as calendar file.
 */
import type { CalendarMessage } from '../calendar.ts'
import type { ChoirEvent, EventKind } from '../types.ts'

import { mdiAlertCircleOutline, mdiCalendarExportOutline, mdiCalendarPlusOutline, mdiCalendarStarOutline, mdiFormatListNumbered, mdiPlus } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CalendarDialog from './CalendarDialog.vue'
import ReloadError from './ReloadError.vue'
import EventDialog from './EventDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { calendarFileUrl, errorMessage, fetchEvents } from '../api.ts'
import { timeText, upcomingConcerts } from '../calendar.ts'
import { focusAfterDialog, focusWhenPresent } from '../focus.ts'
import { formatDate, toIsoDate } from '../format.ts'
import { eventStatusLabel, ROLES } from '../labels.ts'
import { navigate } from '../navigation.ts'
import { canPlanEvents, hasAnyRole, isModuleActive } from '../store.ts'

const props = defineProps<{
	kind: EventKind
}>()

const events = ref<ChoirEvent[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')
const showImported = ref(true)
const creating = ref(false)

/** Counter of the loads: only the answer to the latest one counts (a filter switched quickly) */
let loadRun = 0

async function load(): Promise<void> {
	const run = ++loadRun
	loading.value = true
	error.value = ''
	try {
		const result = await fetchEvents(props.kind)
		if (run !== loadRun) {
			return
		}
		events.value = result
		loaded.value = true
	} catch (caught) {
		if (run === loadRun) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (run === loadRun) {
			loading.value = false
		}
	}
}

onMounted(load)
watch(() => props.kind, () => {
	loaded.value = false
	load()
})

const isConcert = computed(() => props.kind === 'concert')

/** Newest first; events without a date at the top (still in planning). */
const shown = computed(() => [...events.value]
	.filter((event) => showImported.value || !event.imported)
	.sort((a, b) => (b.event_date ?? '9999').localeCompare(a.event_date ?? '9999') || (b.period ?? '').localeCompare(a.period ?? '')))

/**
 * Date or period of an event.
 *
 * @param event - concert or song list
 */
function when(event: ChoirEvent): string {
	if (event.event_date) {
		const time = timeText(event)
		return time ? `${formatDate(event.event_date)}, ${time}` : formatDate(event.event_date)
	}
	return event.period ?? '–'
}

// ---- calendar ----

/** Concerts from today on that are planned in the app. */
const upcoming = computed(() => upcomingConcerts(events.value, toIsoDate(new Date())))
/** Accounts with a role may add entries to their own calendar. */
const canAddToCalendar = computed(() => hasAnyRole(ROLES))
/** Deadlines are work of the planners; for them they are added by default. */
const withDeadlines = ref(canPlanEvents.value)
const calendarDialogOpen = ref(false)
const calendarResult = ref<CalendarMessage | null>(null)

/**
 * @param message - result of adding the entries
 */
function onCalendarDone(message: CalendarMessage): void {
	calendarDialogOpen.value = false
	calendarResult.value = message
	focusAfterDialog(() => document.getElementById('events-add-to-calendar'))
}

function closeCalendarDialog(): void {
	calendarDialogOpen.value = false
	focusAfterDialog(() => document.getElementById('events-add-to-calendar'))
}

/**
 * @param event - the new event
 */
function onCreated(event: ChoirEvent): void {
	creating.value = false
	navigate('event', { eventId: event.id })
	// The new programme opens; its title gets the focus once it is loaded
	focusWhenPresent('event-detail-title')
}
</script>

<template>
	<div class="events-view">
		<ViewHeader :title="isConcert ? t('cantoria', 'Concerts') : t('cantoria', 'Song lists')"
			:description="isConcert
				? t('cantoria', 'Programmes with the version per item, warnings about rights and deadlines. A concert confirmed as sung counts as documented performance.')
				: t('cantoria', 'Song lists of a period. They are no proof of performance.')"
			printable
			reloadable
			:loading="loading"
			@reload="load">
			<template #actions>
				<NcButton v-if="canPlanEvents" variant="primary" @click="creating = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ isConcert ? t('cantoria', 'New concert') : t('cantoria', 'New song list') }}
				</NcButton>
			</template>
		</ViewHeader>

		<div class="events-view__filters no-print">
			<NcCheckboxRadioSwitch v-model="showImported" type="switch">
				{{ t('cantoria', 'Show imported history') }}
			</NcCheckboxRadioSwitch>
		</div>

		<section v-if="isConcert && upcoming.length > 0 && isModuleActive('calendar')"
			class="events-view__calendar no-print"
			aria-labelledby="events-calendar-heading">
			<h3 id="events-calendar-heading">
				{{ n('cantoria', 'Calendar: %n upcoming concert', 'Calendar: %n upcoming concerts', upcoming.length) }}
			</h3>
			<NcCheckboxRadioSwitch v-model="withDeadlines" type="switch">
				{{ t('cantoria', 'With the preparation deadlines (10, 8 and 6 weeks before)') }}
			</NcCheckboxRadioSwitch>
			<div class="events-view__calendar-actions">
				<NcButton v-if="canAddToCalendar" id="events-add-to-calendar" @click="calendarDialogOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarPlusOutline" />
					</template>
					{{ t('cantoria', 'Add upcoming concerts to my calendar') }}
				</NcButton>
				<NcButton :href="calendarFileUrl(null, withDeadlines)" download>
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarExportOutline" />
					</template>
					{{ t('cantoria', 'Calendar file of the upcoming concerts (.ics)') }}
				</NcButton>
			</div>
			<!-- Always present, so that screen readers announce the result -->
			<div role="status">
				<NcNoteCard v-if="calendarResult" :type="calendarResult.type">
					{{ calendarResult.text }}
				</NcNoteCard>
			</div>
		</section>

		<div class="events-view__content">
			<ReloadError v-if="loaded && error" :error="error" @retry="load()" />
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The list could not be loaded')"
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
			<NcEmptyContent v-else-if="shown.length === 0"
				:name="isConcert ? t('cantoria', 'No concerts yet') : t('cantoria', 'No song lists yet')"
				:description="isConcert
					? t('cantoria', 'Plan a new concert, or import earlier concerts in the archive management.')
					: t('cantoria', 'Create a new song list, or import earlier song lists in the archive management.')">
				<template #icon>
					<!-- the icons of the navigation -->
					<NcIconSvgWrapper :path="isConcert ? mdiCalendarStarOutline : mdiFormatListNumbered" />
				</template>
			</NcEmptyContent>
			<table v-else class="events-view__table">
				<caption class="hidden-visually">
					{{ n('cantoria', '%n entry', '%n entries', shown.length) }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ isConcert ? t('cantoria', 'Date') : t('cantoria', 'Period') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Title') }}
						</th>
						<th v-if="isConcert" scope="col">
							{{ t('cantoria', 'Location') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Status') }}
						</th>
						<th scope="col" class="events-view__number">
							{{ t('cantoria', 'Pieces') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="event in shown" :key="event.id">
						<td class="events-view__when">
							{{ when(event) }}
						</td>
						<td>
							<button type="button" class="events-view__open" @click="navigate('event', { eventId: event.id })">
								{{ event.title || t('cantoria', '(no title)') }}
							</button>
							<span v-if="event.imported" class="events-view__tag">{{ t('cantoria', 'imported') }}</span>
						</td>
						<td v-if="isConcert">
							{{ event.location }}
						</td>
						<td>{{ eventStatusLabel(event.status) }}</td>
						<td class="events-view__number">
							{{ event.items ?? '' }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<EventDialog v-if="creating"
			:kind="kind"
			@saved="onCreated"
			@close="creating = false" />

		<CalendarDialog v-if="calendarDialogOpen"
			:event-id="null"
			:deadlines="withDeadlines"
			@done="onCalendarDone"
			@close="closeCalendarDialog" />
	</div>
</template>

<style scoped>
.events-view__filters {
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.events-view__content {
	padding: calc(3 * var(--default-grid-baseline));
}

.events-view__calendar {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding: 0 calc(3 * var(--default-grid-baseline));
}

.events-view__calendar h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.events-view__calendar-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.events-view__table {
	width: 100%;
	border-collapse: collapse;
}

.events-view__table th,
.events-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.events-view__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.events-view__when {
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.events-view__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.events-view__open {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
}

.events-view__open:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.events-view__tag {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

@media print {
	.events-view__content {
		padding: 0;
	}

	.events-view__open {
		text-decoration: none;
	}
}
</style>
