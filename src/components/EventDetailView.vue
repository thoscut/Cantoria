<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * One concert or song list: the items with version, duration, encore and
 * note, warnings per item (rights, preservation copy, valid version), the
 * total duration. Planned events can be edited and fixed; a concert is
 * confirmed as sung on or after its date, or cancelled (and reopened).
 * A concert with a date can be added to the user's Nextcloud calendar or
 * downloaded as calendar file. Print views: concert binder list (with
 * columns to tick off) and song list (singers' portal or paper only).
 */
import type { CalendarMessage } from '../calendar.ts'
import type { EventDetail, EventItem, Entry } from '../types.ts'

import {
	mdiAlertCircleOutline,
	mdiAlertOutline,
	mdiArrowDown,
	mdiArrowLeft,
	mdiArrowUp,
	mdiCalendarExportOutline,
	mdiCalendarPlusOutline,
	mdiCalendarRemoveOutline,
	mdiCheckAll,
	mdiDeleteOutline,
	mdiDownload,
	mdiLockOutline,
	mdiMusicBoxMultipleOutline,
	mdiPencilOutline,
	mdiPrinterOutline,
	mdiShareVariantOutline,
} from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '../l10n.ts'
import { computed, nextTick, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import AppNotices from './AppNotices.vue'
import CalendarDialog from './CalendarDialog.vue'
import EventDialog from './EventDialog.vue'
import ProgrammeShareDialog from './ProgrammeShareDialog.vue'
import {
	addEventItem,
	calendarFileUrl,
	cancelEvent,
	confirmSung,
	errorMessage,
	fetchEvent,
	fixEvent,
	removeEventItem,
	reopenEvent,
	reorderEventItems,
	songOrderUrl,
	updateEventItem,
} from '../api.ts'
import { isCalendarConcert, timeText } from '../calendar.ts'
import { formatDuration, parseDuration } from '../durations.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { formatDate, toIsoDate } from '../format.ts'
import { eventStatusLabel, ROLES } from '../labels.ts'
import { navigate } from '../navigation.ts'
import { canPlanEvents, entries, hasAnyRole, isModuleActive, loadEntries, loadPortalRows, openEntry, versionsOnPortal } from '../store.ts'

const props = defineProps<{
	eventId: number
}>()

const data = ref<EventDetail | null>(null)
const loading = ref(false)
const error = ref('')
const busy = ref(false)
const announcement = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		data.value = await fetchEvent(props.eventId)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

watch(() => props.eventId, () => {
	data.value = null
	load()
}, { immediate: true })

// for the column "singers' portal / paper only" of the song list
loadPortalRows()

const event = computed(() => data.value?.event ?? null)
const items = computed(() => data.value?.items ?? [])
const isConcert = computed(() => event.value?.kind === 'concert')
const today = toIsoDate(new Date())

/** Planned events can be changed; imported and sung ones are history, cancelled ones are reopened first. */
const editable = computed(() => canPlanEvents.value && event.value !== null && !event.value.imported
	&& event.value.status !== 'sung' && event.value.status !== 'cancelled')

const isCancelled = computed(() => event.value?.status === 'cancelled')
/** Still to be sung: draft or fixed */
const upcoming = computed(() => event.value !== null && ['draft', 'fixed'].includes(event.value.status))
/** The whole programme can be shared with the choir members (milestone 0.19) */
const canShareProgramme = computed(() => upcoming.value && items.value.length > 0 && isModuleActive('excerpt_links') && isModuleActive('member_links')
	&& hasAnyRole(['librarian', 'conductor', 'operations']))
/** The programme opens in the concert folder, at its first piece (milestone 0.19) */
const concertFolderUrl = computed(() => {
	if (!upcoming.value || !isModuleActive('concert_folder') || !hasAnyRole(['librarian', 'conductor'])) {
		return null
	}
	const first = items.value.find((item) => item.struck !== true)
	return generateUrl('/apps/cantoria/concert-folder') + (first ? '?item=' + first.id : '')
})
const sharing = ref(false)

const canConfirmSung = computed(() => editable.value && isConcert.value
	&& event.value?.event_date !== null && event.value?.event_date !== undefined && event.value.event_date <= today)

const sungTooEarly = computed(() => editable.value && isConcert.value
	&& (!event.value?.event_date || event.value.event_date > today))

/**
 * Run an action, then reload.
 *
 * @param action - the server call
 * @param success - message on success
 */
async function act(action: () => Promise<unknown>, success = ''): Promise<boolean> {
	busy.value = true
	error.value = ''
	try {
		await action()
		if (success) {
			showSuccess(success)
		}
		await load()
		return true
	} catch (caught) {
		error.value = errorMessage(caught)
		return false
	} finally {
		busy.value = false
	}
}

// ---- items ----------------------------------------------------------------------

interface CopyOption {
	id: number
	label: string
}

const addOption = ref<CopyOption | null>(null)

const copyOptions = computed<CopyOption[]>(() => entries.value
	.filter((entry: Entry) => entry.status === 'active')
	.map((entry) => ({ id: entry.id, label: `${entry.shelfmark} – ${entry.title ?? ''}${entry.composer ? ` (${entry.composer})` : ''}` })))

async function addItem(): Promise<void> {
	const option = addOption.value
	if (!option) {
		return
	}
	if (await act(() => addEventItem(props.eventId, option.id), t('cantoria', '{piece} has been added.', { piece: option.label }))) {
		addOption.value = null
	}
	// The field was disabled while saving: back to it for the next piece
	await focusAfterUpdate(() => document.querySelector('.event-detail__add-select input'))
}

/**
 * Move an item one place up or down.
 *
 * @param index - position in the list (0-based)
 * @param delta - -1 up, +1 down
 */
async function move(index: number, delta: number): Promise<void> {
	const order = items.value.map((item) => item.id)
	const target = index + delta
	if (target < 0 || target >= order.length) {
		return
	}
	[order[index], order[target]] = [order[target], order[index]]
	const moved = items.value[index]
	if (await act(() => reorderEventItems(props.eventId, order))) {
		announcement.value = t('cantoria', '{piece} is now at position {position}.', { piece: itemTitle(moved), position: target + 1 })
		await nextTick()
		document.getElementById(`event-item-${moved.id}-${delta < 0 ? 'up' : 'down'}`)?.focus()
	}
}

const removing = ref<EventItem | null>(null)

async function remove(): Promise<void> {
	const item = removing.value
	if (item && await act(() => removeEventItem(item.id), t('cantoria', 'The piece has been removed from the list.'))) {
		removing.value = null
		await focusAfterDialog(() => document.getElementById('event-detail-items'))
	}
}

/**
 * Title of an item for display.
 *
 * @param item - event item
 */
function itemTitle(item: EventItem): string {
	return item.title || item.title_text || t('cantoria', '(no title)')
}

/**
 * Composer and arranger of an item.
 *
 * @param item - event item
 */
function itemPeople(item: EventItem): string {
	return [item.composer || item.composer_text, item.arranger_text].filter((part) => part).join(' · ')
}

// ---- item dialog ----

const editing = ref<EventItem | null>(null)
const itemDraft = ref({ duration: '', encore: false, note: '' })
const itemError = ref('')

/**
 * @param item - item to edit
 */
function editItem(item: EventItem): void {
	editing.value = item
	itemDraft.value = { duration: formatDuration(item.duration), encore: item.encore === true, note: item.note ?? '' }
	itemError.value = ''
}

async function saveItem(): Promise<void> {
	const item = editing.value
	if (!item) {
		return
	}
	const duration = parseDuration(itemDraft.value.duration)
	if (duration === undefined) {
		itemError.value = t('cantoria', 'Please enter the duration as minutes:seconds, e.g. 3:05.')
		return
	}
	busy.value = true
	itemError.value = ''
	try {
		await updateEventItem(item.id, {
			duration,
			encore: itemDraft.value.encore,
			note: itemDraft.value.note.trim() === '' ? null : itemDraft.value.note.trim(),
		})
		editing.value = null
		await load()
	} catch (caught) {
		itemError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

// ---- event actions ----

const editingEvent = ref(false)
const fixing = ref(false)
const confirmingSung = ref(false)
const struck = ref<number[]>([])

function startSung(): void {
	struck.value = items.value.filter((item) => item.struck === true).map((item) => item.id)
	confirmingSung.value = true
}

async function submitSung(): Promise<void> {
	if (await act(() => confirmSung(props.eventId, struck.value), t('cantoria', 'The concert has been confirmed as sung.'))) {
		confirmingSung.value = false
		focusTitle()
		await loadEntries()
	}
}

async function submitFix(): Promise<void> {
	if (await act(() => fixEvent(props.eventId), t('cantoria', 'The programme has been fixed.'))) {
		fixing.value = false
		focusTitle()
	}
}

async function onEventSaved(): Promise<void> {
	editingEvent.value = false
	await load()
	focusTitle()
}

const cancelling = ref(false)

/** A planned concert (draft or fixed) can be cancelled. */
const canCancel = computed(() => editable.value && isConcert.value)

async function submitCancel(): Promise<void> {
	if (await act(() => cancelEvent(props.eventId), t('cantoria', 'The concert has been cancelled.'))) {
		cancelling.value = false
		focusTitle()
	}
}

async function reopen(): Promise<void> {
	if (await act(() => reopenEvent(props.eventId), t('cantoria', 'The concert has been reopened as a draft.'))) {
		focusTitle()
	}
}

// ---- calendar ----

/** Accounts with a role may add entries to their own calendar. */
const canAddToCalendar = computed(() => hasAnyRole(ROLES))
const showCalendar = computed(() => event.value !== null && isCalendarConcert(event.value) && isModuleActive('calendar'))

/** Column "singers' portal / paper only" of the printed song list (module "portal"). */
const showPortalColumn = computed(() => isModuleActive('portal'))
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
	focusAfterDialog(() => document.getElementById('event-detail-add-to-calendar'))
}

function closeCalendarDialog(): void {
	calendarDialogOpen.value = false
	focusAfterDialog(() => document.getElementById('event-detail-add-to-calendar'))
}

/**
 * Focus the title of the programme: after fixing, confirming or editing the
 * button that was used is gone or the dialog has closed (WCAG 2.4.3).
 */
function focusTitle(): void {
	focusAfterDialog(() => document.getElementById('event-detail-title'))
}

// ---- totals ----

const totalText = computed(() => formatDuration(data.value?.totalDuration ?? 0) || '0:00')

// ---- printing ----

const printMode = ref<'programme' | 'binders' | 'songlist'>('programme')
const largePrint = ref(false)

/**
 * Print one of the lists.
 *
 * @param mode - which list
 */
async function print(mode: 'programme' | 'binders' | 'songlist'): Promise<void> {
	printMode.value = mode
	await nextTick()
	window.print()
}

/**
 * Whether the version of an item is in the singers' portal.
 *
 * @param item - event item
 */
function inPortal(item: EventItem): boolean {
	return item.version_id !== null && versionsOnPortal.value.has(item.version_id)
}

const printedItems = computed(() => items.value.filter((item) => item.struck !== true))

/** Column "Revision" of the printed lists (module "version_states"), when an item has one. */
const showRevisionColumn = computed(() => printedItems.value.some((item) => typeof item.revision === 'number'))

/**
 * The revision of an item as text: the revision sung, or the current one
 * with the day since when it is valid.
 *
 * @param item - event item with its revision
 */
function revisionText(item: EventItem): string {
	const number = item.revision
	if (typeof number !== 'number') {
		return ''
	}
	if (item.revision_sung) {
		return t('cantoria', 'Revision {number} sung', { number })
	}
	return item.revision_valid_since
		? t('cantoria', 'Revision {number}, valid since {date}', { number, date: formatDate(item.revision_valid_since) })
		: t('cantoria', 'Revision {number}', { number })
}
</script>

<template>
	<div class="event-detail" :class="{ 'event-detail--large': largePrint }">
		<header class="event-detail__header no-print">
			<NcButton variant="tertiary" @click="navigate(isConcert ? 'concerts' : 'songLists')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
				{{ isConcert ? t('cantoria', 'All concerts') : t('cantoria', 'All song lists') }}
			</NcButton>
		</header>
		<AppNotices />

		<NcEmptyContent v-if="!data && loading" :name="t('cantoria', 'Loading …')">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!data && error"
			:name="t('cantoria', 'The programme could not be loaded')"
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

		<template v-else-if="data && event">
			<!-- Screen view -->
			<div class="event-detail__screen no-print">
				<section class="event-detail__summary" aria-labelledby="event-detail-title">
					<h2 id="event-detail-title" class="event-detail__title">
						{{ event.title || t('cantoria', '(no title)') }}
					</h2>
					<p class="event-detail__meta">
						<span>{{ isConcert ? t('cantoria', 'Concert') : t('cantoria', 'Song list') }}</span>
						<span v-if="event.event_date">{{ formatDate(event.event_date) }}</span>
						<span v-if="timeText(event)">{{ t('cantoria', '{time} (local time)', { time: timeText(event) }) }}</span>
						<span v-if="event.period">{{ event.period }}</span>
						<span v-if="event.location">{{ event.location }}</span>
						<span class="event-detail__status">{{ eventStatusLabel(event.status) }}</span>
						<span v-if="event.imported">{{ t('cantoria', 'imported') }}</span>
					</p>
					<p v-if="event.notes" class="event-detail__notes">
						{{ event.notes }}
					</p>
					<NcNoteCard v-if="!editable && (event.imported || event.status === 'sung')" type="info">
						<template #icon>
							<NcIconSvgWrapper :path="mdiLockOutline" />
						</template>
						{{ t('cantoria', 'This programme is history (performed or imported) and cannot be changed.') }}
					</NcNoteCard>
					<NcNoteCard v-if="isCancelled" type="warning">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarRemoveOutline" />
						</template>
						{{ t('cantoria', 'This concert is cancelled. It stays in the list with its programme; there are no more deadline notifications. Add it to your calendar again to mark the entry there as cancelled.') }}
					</NcNoteCard>
					<NcNoteCard v-if="error" type="error" role="alert">
						{{ error }}
					</NcNoteCard>

					<div class="event-detail__actions">
						<NcButton v-if="editable" :disabled="busy" @click="editingEvent = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencilOutline" />
							</template>
							{{ t('cantoria', 'Edit') }}
						</NcButton>
						<NcButton v-if="editable && event.status === 'draft'" :disabled="busy" @click="fixing = true">
							{{ t('cantoria', 'Fix programme') }}
						</NcButton>
						<NcButton v-if="canConfirmSung"
							variant="primary"
							:disabled="busy"
							@click="startSung">
							<template #icon>
								<NcIconSvgWrapper :path="mdiCheckAll" />
							</template>
							{{ t('cantoria', 'Confirm as sung') }}
						</NcButton>
						<NcButton v-if="canShareProgramme" :disabled="busy" @click="sharing = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiShareVariantOutline" />
							</template>
							{{ t('cantoria', 'Share with the choir …') }}
						</NcButton>
						<NcButton v-if="concertFolderUrl" :href="concertFolderUrl">
							<template #icon>
								<NcIconSvgWrapper :path="mdiMusicBoxMultipleOutline" />
							</template>
							{{ t('cantoria', 'Open in the concert folder') }}
						</NcButton>
						<NcButton :href="songOrderUrl(event.id)" download>
							<template #icon>
								<NcIconSvgWrapper :path="mdiDownload" />
							</template>
							{{ t('cantoria', 'Song order (CSV)') }}
						</NcButton>
						<NcButton v-if="canCancel" :disabled="busy" @click="cancelling = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiCalendarRemoveOutline" />
							</template>
							{{ t('cantoria', 'Cancel concert') }}
						</NcButton>
						<NcButton v-if="isCancelled && canPlanEvents" :disabled="busy" @click="reopen">
							{{ t('cantoria', 'Reopen concert') }}
						</NcButton>
					</div>
					<p v-if="sungTooEarly" class="event-detail__hint">
						{{ t('cantoria', 'A concert can be confirmed as sung on or after its date.') }}
					</p>
				</section>

				<section class="event-detail__items" aria-labelledby="event-detail-items">
					<h3 id="event-detail-items">
						{{ n('cantoria', '%n piece', '%n pieces', items.length) }}
					</h3>

					<form v-if="editable" class="event-detail__add" @submit.prevent="addItem">
						<NcSelect v-model="addOption"
							class="event-detail__add-select"
							:input-label="t('cantoria', 'Add a piece (search by shelf mark or title)')"
							:options="copyOptions"
							:disabled="busy" />
						<NcButton type="submit" :disabled="busy || !addOption">
							{{ t('cantoria', 'Add') }}
						</NcButton>
					</form>

					<p v-if="items.length === 0" class="event-detail__hint">
						{{ t('cantoria', 'No pieces yet.') }}
					</p>
					<ol v-else class="event-detail__list">
						<li v-for="(item, index) in items"
							:key="item.id"
							class="event-item"
							:class="{ 'event-item--struck': item.struck }">
							<span class="event-item__position" aria-hidden="true">{{ index + 1 }}</span>
							<div class="event-item__body">
								<p class="event-item__title">
									<button v-if="item.copy_id"
										type="button"
										class="event-item__shelfmark"
										:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: item.shelfmark ?? '' })"
										@click="openEntry(item.copy_id)">
										{{ item.shelfmark }}
									</button>
									<span v-else class="event-item__no-shelfmark">{{ item.assignment === 'ambiguous' ? t('cantoria', 'shelf mark unclear') : t('cantoria', 'not in the archive') }}</span>
									<span>{{ itemTitle(item) }}</span>
									<span v-if="item.encore" class="event-item__tag">{{ t('cantoria', 'Encore') }}</span>
									<span v-if="item.struck" class="event-item__tag">{{ t('cantoria', 'not sung') }}</span>
								</p>
								<p v-if="itemPeople(item)" class="event-item__people">
									{{ itemPeople(item) }}
								</p>
								<p class="event-item__facts">
									<span>{{ t('cantoria', 'Duration: {duration}', { duration: formatDuration(item.duration) || '–' }) }}</span>
									<span v-if="typeof item.revision === 'number'">{{ revisionText(item) }}</span>
									<span v-if="item.note">{{ item.note }}</span>
								</p>
								<ul v-if="item.warnings && item.warnings.length > 0" class="event-item__warnings">
									<li v-for="warning in item.warnings" :key="warning">
										<NcIconSvgWrapper :path="mdiAlertOutline" :size="18" inline />
										<span><span class="hidden-visually">{{ t('cantoria', 'Warning:') }}</span> {{ warning }}</span>
									</li>
								</ul>
							</div>
							<div v-if="editable" class="event-item__actions">
								<NcButton :id="`event-item-${item.id}-up`"
									variant="tertiary"
									:disabled="busy || index === 0"
									:aria-label="t('cantoria', 'Move up: {piece}', { piece: itemTitle(item) })"
									@click="move(index, -1)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiArrowUp" />
									</template>
								</NcButton>
								<NcButton :id="`event-item-${item.id}-down`"
									variant="tertiary"
									:disabled="busy || index === items.length - 1"
									:aria-label="t('cantoria', 'Move down: {piece}', { piece: itemTitle(item) })"
									@click="move(index, 1)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiArrowDown" />
									</template>
								</NcButton>
								<NcButton variant="tertiary"
									:disabled="busy"
									:aria-label="t('cantoria', 'Duration, encore and note: {piece}', { piece: itemTitle(item) })"
									@click="editItem(item)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencilOutline" />
									</template>
								</NcButton>
								<NcButton variant="tertiary"
									:disabled="busy"
									:aria-label="t('cantoria', 'Remove: {piece}', { piece: itemTitle(item) })"
									@click="removing = item">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDeleteOutline" />
									</template>
								</NcButton>
							</div>
						</li>
					</ol>

					<p class="event-detail__total">
						{{ t('cantoria', 'Total duration: {duration}', { duration: totalText }) }}
						<template v-if="data.missingDurations > 0">
							– {{ n('cantoria', '%n piece has no duration yet', '%n pieces have no duration yet', data.missingDurations) }}
						</template>
					</p>
				</section>

				<section v-if="showCalendar" class="event-detail__calendar" aria-labelledby="event-detail-calendar">
					<h3 id="event-detail-calendar">
						{{ t('cantoria', 'Calendar') }}
					</h3>
					<NcCheckboxRadioSwitch v-if="!event.imported" v-model="withDeadlines" type="switch">
						{{ t('cantoria', 'With the preparation deadlines (10, 8 and 6 weeks before)') }}
					</NcCheckboxRadioSwitch>
					<div class="event-detail__actions">
						<NcButton v-if="canAddToCalendar" id="event-detail-add-to-calendar" @click="calendarDialogOpen = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiCalendarPlusOutline" />
							</template>
							{{ t('cantoria', 'Add to my calendar') }}
						</NcButton>
						<NcButton :href="calendarFileUrl(event.id, withDeadlines && !event.imported)" download>
							<template #icon>
								<NcIconSvgWrapper :path="mdiCalendarExportOutline" />
							</template>
							{{ t('cantoria', 'Calendar file (.ics)') }}
						</NcButton>
					</div>
					<!-- Always present, so that screen readers announce the result -->
					<div role="status" class="event-detail__calendar-status">
						<NcNoteCard v-if="calendarResult" :type="calendarResult.type">
							{{ calendarResult.text }}
						</NcNoteCard>
					</div>
				</section>

				<section class="event-detail__print-options" aria-labelledby="event-detail-print">
					<h3 id="event-detail-print">
						{{ t('cantoria', 'Print') }}
					</h3>
					<NcCheckboxRadioSwitch v-model="largePrint" type="switch">
						{{ t('cantoria', 'Large print') }}
					</NcCheckboxRadioSwitch>
					<div class="event-detail__actions">
						<NcButton @click="print('programme')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPrinterOutline" />
							</template>
							{{ t('cantoria', 'Programme') }}
						</NcButton>
						<NcButton @click="print('binders')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPrinterOutline" />
							</template>
							{{ t('cantoria', 'Concert binder list') }}
						</NcButton>
						<NcButton @click="print('songlist')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPrinterOutline" />
							</template>
							{{ t('cantoria', 'Song list') }}
						</NcButton>
					</div>
				</section>
				<p class="hidden-visually" aria-live="polite">
					{{ announcement }}
				</p>
			</div>

			<!-- Print view (A4, black and white) -->
			<section class="event-print print-only" :aria-hidden="true">
				<h1 class="event-print__title">
					{{ event.title }}
				</h1>
				<p class="event-print__meta">
					<template v-if="printMode === 'binders'">
						{{ t('cantoria', 'Concert binder list') }} ·
					</template>
					<template v-else-if="printMode === 'songlist'">
						{{ t('cantoria', 'Song list') }} ·
					</template>
					{{ event.event_date ? formatDate(event.event_date) : event.period }}
					<template v-if="event.location">
						· {{ event.location }}
					</template>
				</p>
				<table class="event-print__table">
					<thead>
						<tr>
							<th>{{ t('cantoria', 'No.') }}</th>
							<th>{{ t('cantoria', 'Shelf mark') }}</th>
							<th>{{ t('cantoria', 'Title') }}</th>
							<th>{{ t('cantoria', 'Composer / arrangement') }}</th>
							<th v-if="showRevisionColumn">
								{{ t('cantoria', 'Revision') }}
							</th>
							<th v-if="printMode === 'programme'">
								{{ t('cantoria', 'Duration') }}
							</th>
							<template v-if="printMode === 'binders'">
								<th class="event-print__tick">
									{{ t('cantoria', 'Issued') }}
								</th>
								<th class="event-print__tick">
									{{ t('cantoria', 'Returned') }}
								</th>
							</template>
							<th v-if="printMode === 'songlist' && showPortalColumn">
								{{ t('cantoria', 'Singers\' portal / paper only') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(item, index) in printedItems" :key="item.id">
							<td>{{ index + 1 }}</td>
							<td class="event-print__shelfmark">
								{{ item.shelfmark ?? '' }}
							</td>
							<td>
								{{ itemTitle(item) }}<template v-if="item.encore">
									({{ t('cantoria', 'Encore') }})
								</template>
							</td>
							<td>{{ itemPeople(item) }}</td>
							<td v-if="showRevisionColumn">
								{{ revisionText(item) }}
							</td>
							<td v-if="printMode === 'programme'">
								{{ formatDuration(item.duration) }}
							</td>
							<template v-if="printMode === 'binders'">
								<td class="event-print__tick">
									☐
								</td>
								<td class="event-print__tick">
									☐
								</td>
							</template>
							<td v-if="printMode === 'songlist' && showPortalColumn">
								{{ inPortal(item) ? t('cantoria', 'Singers\' portal') : t('cantoria', 'paper only') }}
							</td>
						</tr>
					</tbody>
				</table>
				<p v-if="printMode === 'programme'" class="event-print__total">
					{{ t('cantoria', 'Total duration: {duration}', { duration: totalText }) }}
				</p>
			</section>
		</template>

		<EventDialog v-if="editingEvent && event"
			:kind="event.kind"
			:event="event"
			@saved="onEventSaved"
			@close="editingEvent = false" />

		<ActionDialog v-if="editing !== null"
			:name="t('cantoria', 'Piece: {piece}', { piece: itemTitle(editing) })"
			:submit-label="t('cantoria', 'Save')"
			:busy="busy"
			:error="itemError"
			@submit="saveItem"
			@close="editing = null">
			<NcTextField v-model="itemDraft.duration"
				:label="t('cantoria', 'Duration (minutes:seconds)')"
				inputmode="numeric"
				placeholder="3:05" />
			<NcCheckboxRadioSwitch v-model="itemDraft.encore" type="switch">
				{{ t('cantoria', 'Encore') }}
			</NcCheckboxRadioSwitch>
			<NcTextArea v-model="itemDraft.note"
				:label="t('cantoria', 'Note')"
				resize="vertical"
				rows="2" />
		</ActionDialog>

		<ActionDialog v-if="removing !== null"
			:name="t('cantoria', 'Remove piece')"
			:submit-label="t('cantoria', 'Remove')"
			variant="error"
			:busy="busy"
			:error="error"
			@submit="remove"
			@close="removing = null">
			<p>{{ t('cantoria', 'Remove {piece} from this programme?', { piece: itemTitle(removing) }) }}</p>
		</ActionDialog>

		<ProgrammeShareDialog v-if="sharing && event"
			:event-id="event.id"
			:title="event.title || t('cantoria', '(no title)')"
			@close="sharing = false" />
		<CalendarDialog v-if="calendarDialogOpen && event"
			:event-id="event.id"
			:deadlines="withDeadlines && !event.imported"
			@done="onCalendarDone"
			@close="closeCalendarDialog" />

		<ActionDialog v-if="cancelling"
			:name="t('cantoria', 'Cancel concert')"
			:submit-label="t('cantoria', 'Cancel concert')"
			variant="error"
			:busy="busy"
			:error="error"
			@submit="submitCancel"
			@close="cancelling = false">
			<p>{{ t('cantoria', 'The concert is marked as cancelled. It stays in the list with its programme, and the notifications about its deadlines stop. It can be reopened later.') }}</p>
		</ActionDialog>

		<ActionDialog v-if="fixing"
			:name="t('cantoria', 'Fix programme')"
			:submit-label="t('cantoria', 'Fix')"
			:busy="busy"
			:error="error"
			@submit="submitFix"
			@close="fixing = false">
			<p>{{ t('cantoria', 'The programme becomes fixed. It can still be changed until the concert is confirmed as sung.') }}</p>
		</ActionDialog>

		<ActionDialog v-if="confirmingSung"
			:name="t('cantoria', 'Confirm as sung')"
			:submit-label="t('cantoria', 'Confirm')"
			:busy="busy"
			:error="error"
			size="large"
			@submit="submitSung"
			@close="confirmingSung = false">
			<p>{{ t('cantoria', 'Tick the pieces that were not sung. All other pieces count as documented performances. After that the programme can no longer be changed.') }}</p>
			<fieldset class="event-detail__struck">
				<legend>{{ t('cantoria', 'Not sung') }}</legend>
				<NcCheckboxRadioSwitch v-for="item in items"
					:key="item.id"
					v-model="struck"
					:value="item.id"
					name="event-struck">
					{{ item.shelfmark ? `${item.shelfmark} – ` : '' }}{{ itemTitle(item) }}
				</NcCheckboxRadioSwitch>
			</fieldset>
		</ActionDialog>
	</div>
</template>

<style scoped>
.event-detail__header {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-inline-start: calc(var(--default-clickable-area) + 3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.event-detail__screen {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.event-detail__summary,
.event-detail__items,
.event-detail__calendar,
.event-detail__print-options {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.event-detail__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.event-detail__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.event-detail__status {
	font-weight: bold;
	color: var(--color-main-text);
}

.event-detail__notes {
	margin: 0;
	white-space: pre-wrap;
}

.event-detail__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.event-detail__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.event-detail__items h3,
.event-detail__calendar h3,
.event-detail__print-options h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.event-detail__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.event-detail__add-select {
	flex: 1 1 24em;
}

.event-detail__list {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.event-item {
	display: flex;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.event-item__position {
	min-width: 2em;
	font-weight: bold;
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.event-item__body {
	flex: 1 1 auto;
	min-width: 0;
}

.event-item__body p {
	margin: 0;
}

.event-item__title {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0 calc(2 * var(--default-grid-baseline));
	font-weight: bold;
}

.event-item--struck .event-item__title > span:not(.event-item__tag) {
	text-decoration: line-through;
}

.event-item__shelfmark {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.event-item__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.event-item__no-shelfmark {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: normal;
}

.event-item__tag {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
	font-weight: normal;
}

.event-item__people,
.event-item__facts {
	color: var(--color-text-maxcontrast);
}

.event-item__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
	font-size: var(--font-size-small, 13px);
}

.event-item__warnings {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-top: var(--default-grid-baseline);
}

.event-item__warnings li {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	color: var(--color-main-text);
}

.event-item__warnings :deep(.icon-vue) {
	flex-shrink: 0;
	color: var(--color-warning-text, var(--color-warning));
}

.event-item__actions {
	display: flex;
	flex-shrink: 0;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: flex-end;
	max-width: 50%;
}

@media (max-width: 600px) {
	.event-item {
		flex-wrap: wrap;
	}

	.event-item__body {
		flex-basis: calc(100% - 3em);
	}

	.event-item__actions {
		max-width: none;
		margin-inline-start: 2em;
		justify-content: flex-start;
	}
}

.event-detail__total {
	margin: 0;
	font-weight: bold;
}

.event-detail__struck {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	border: none;
}

.event-detail__struck legend {
	font-weight: bold;
}

/* ---- print ---- */

.print-only {
	display: none;
}

@media print {
	.print-only {
		display: block;
	}

	.event-print {
		color: #000;
		font-size: 11pt;
	}

	.event-detail--large .event-print {
		font-size: 15pt;
	}

	.event-print__title {
		margin: 0 0 2mm;
		font-size: 1.6em;
	}

	.event-print__meta {
		margin: 0 0 4mm;
	}

	.event-print__table {
		width: 100%;
		border-collapse: collapse;
	}

	.event-print__table th,
	.event-print__table td {
		padding: 1.5mm 2mm;
		border: 0.3mm solid #000;
		text-align: start;
		vertical-align: top;
	}

	.event-print__table tr {
		break-inside: avoid;
	}

	.event-print__shelfmark {
		white-space: nowrap;
		font-weight: bold;
	}

	.event-print__tick {
		width: 18mm;
		text-align: center !important;
	}

	.event-print__total {
		margin-top: 4mm;
		font-weight: bold;
	}
}
</style>
