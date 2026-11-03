<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The concert folder of the conductor (module "concert_folder", milestone
 * 0.15): the programmes still to be sung in their order, each item with
 * its score (to view, play and practise, as on the shared pages) and its
 * scan. With the module "offline" a whole programme can be made available
 * without a network for the rehearsal and the concert; at every start with
 * a network the kept items are checked with the server. Only reads: nothing
 * is changed from here, also not "sung" (that is confirmed in the archive
 * afterwards).
 * For the stage (milestone 0.19): the stage view fills the screen with the
 * piece and keeps the screen on; its settings fold away, and "Next" and the
 * way out stand in the bar that stays in view. After every change of piece
 * the focus goes into the notes, so that the page-turn pedal turns at once
 * and turns to the next piece after the last system. On a narrow screen the
 * programme list folds away once a piece is open; the back gesture of a
 * phone returns to it.
 */
import type { FocusTarget } from '../focus.ts'
import type { PieceForDevice } from '../offline/rules.ts'
import type { FolderConcert, FolderItem, FolderPageState } from '../types.ts'

import { mdiAlertCircleOutline, mdiArrowRight, mdiCellphoneCheck, mdiChevronDown, mdiChevronUp, mdiFullscreen, mdiFullscreenExit, mdiMusicNoteOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DiscardRecording from './DiscardRecording.vue'
import ExcerptViewer from './ExcerptViewer.vue'
import KeepOffline from './KeepOffline.vue'
import RemoveFromDevice from './RemoveFromDevice.vue'
import ScanFrame from './ScanFrame.vue'
import { focusAfterUpdate, focusElement } from '../focus.ts'
import { formatDate } from '../format.ts'
import { deviceErrorText } from '../offline/errors.ts'
import { homeScreenAdvice } from '../offline/platform.ts'
import { useKeptPieces } from '../offline/useKeptPieces.ts'
import { fetchFolderConcerts, fetchFolderScore } from './api.ts'
import { useLeaveGuard } from './leaveGuard.ts'
import { useStage } from './stage.ts'

function initialState(): FolderPageState {
	try {
		const state = loadState<FolderPageState>('cantoria', 'folder', { concerts: [], version: '' })
		return { concerts: Array.isArray(state.concerts) ? state.concerts : [], version: String(state.version ?? ''), offline: state.offline === true }
	} catch {
		return { concerts: [], version: '', offline: false }
	}
}

const state = initialState()
const concerts = state.concerts
const deviceHeadingId = useId()
const itemHeadingId = useId()
const listId = useId()
const itemHeading = ref<HTMLElement | null>(null)
const nextButton = ref<FocusTarget>(null)
const stageButton = ref<FocusTarget>(null)
const viewer = ref<InstanceType<typeof ExcerptViewer> | null>(null)
const pieceElement = ref<HTMLElement | null>(null)
const archiveUrl = generateUrl('/apps/cantoria/')
const homeScreen = homeScreenAdvice()

const selectedId = ref<number | null>(null)
const selectedConcert = computed(() => concerts.find((concert) => concert.items.some((item) => item.id === selectedId.value)) ?? null)
const selected = computed(() => selectedConcert.value?.items.find((item) => item.id === selectedId.value) ?? null)
const scoreStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
const scoreXml = ref('')
const scoreError = ref('')
/** On a narrow screen the list folds away once a piece is open */
const listOpen = ref(true)
/** A recording of oneself waits unsaved in the shown piece */
const recordingUnsaved = ref(false)
/** The piece to open once the person decided about the recording */
const pendingItem = ref<FolderItem | null>(null)
/** The pedal reached the end of the piece; the next press opens the next one */
const offerNext = ref(false)
const turnStatus = ref('')
/** After a change of piece the focus goes into the notes once they are shown, so that the pedal turns at once */
const focusScore = ref(false)
useLeaveGuard(recordingUnsaved)
const { on: stageOn, toggle: toggleStage } = useStage(() => focusElement(stageButton.value))

// ---- available without a network ------------------------------------------

const device = useKeptPieces('folder', {
	enabled: state.offline === true,
	version: state.version,
	fresh: async () => (await fetchFolderConcerts()).flatMap((concert) => concert.items.map(forDevice)),
})
const { offlineOn, storage, withoutNetwork, keptCount, storageText, checkedText } = device
/** Id of the programme being kept or removed */
const keeping = ref<number | null>(null)
/** Why keeping a programme did not work, by its id */
const keepErrors = ref<Record<number, string>>({})
/** "3 of 12 pieces ready …" while a programme is kept */
const keepProgress = ref('')
const confirmingDelete = ref(false)
/** The button that confirms deleting everything on this device */
const confirmDeleteButton = ref<FocusTarget>(null)
const deviceStatus = ref('')

/**
 * What the server says about keeping an item.
 *
 * @param item - programme item
 */
function forDevice(item: FolderItem): PieceForDevice {
	return { key: String(item.id), title: item.title, composer: item.composer, expires: null, offline: item.offline, scan: item.scan, score: item.score }
}

function isKept(item: FolderItem): boolean {
	return device.isKept(String(item.id))
}

function scanKept(item: FolderItem): boolean {
	return device.scanKept(String(item.id))
}

/**
 * The items of a programme that may be kept.
 *
 * @param concert - programme
 */
function keepable(concert: FolderConcert): FolderItem[] {
	return concert.items.filter((item) => item.offline)
}

/**
 * The items of a programme with notes or a scan that may not be kept, with
 * the reason: the switch for the programme names them (U28).
 *
 * @param concert - programme
 */
function notKeepable(concert: FolderConcert): FolderItem[] {
	return concert.items.filter((item) => !item.offline && (item.score || item.scan))
}

/**
 * Label of the switch for a programme: "All 12 pieces" or "10 of 12 pieces".
 *
 * @param concert - programme
 */
function keepLabel(concert: FolderConcert): string {
	const count = keepable(concert).length
	const missing = notKeepable(concert).length
	return missing === 0
		? n('cantoria', '%n piece available without a network', 'All %n pieces available without a network', count)
		: n('cantoria', '{count} of %n piece available without a network', '{count} of %n pieces available without a network', count + missing, { count })
}

/**
 * Whether every item of a programme that may be kept is kept.
 *
 * @param concert - programme
 */
function concertKept(concert: FolderConcert): boolean {
	const items = keepable(concert)
	return items.length > 0 && items.every(isKept)
}

/**
 * Items of a programme whose notes and scan both may be kept: the switch
 * for the scans concerns them (an item with only a scan keeps it anyway).
 *
 * @param concert - programme
 */
function withScanChoice(concert: FolderConcert): FolderItem[] {
	return keepable(concert).filter((item) => item.scan && item.score)
}

/**
 * Size of the scans of a programme in bytes, or null when unknown.
 *
 * @param concert - programme
 */
function scansSize(concert: FolderConcert): number | null {
	const sizes = withScanChoice(concert).map((item) => item.scanSize ?? null)
	return sizes.length === 0 || sizes.includes(null) ? null : sizes.reduce((sum: number, size) => sum + (size ?? 0), 0)
}

/**
 * Why an item cannot be shown without a network, or ''.
 *
 * @param item - programme item
 */
function lockText(item: FolderItem): string {
	return device.lockText(String(item.id))
}

/**
 * Make the items of a programme available without a network, or remove
 * them from this device.
 *
 * @param concert - programme
 * @param keep - keep them
 * @param withScans - with the scans
 */
async function setConcertKept(concert: FolderConcert, keep: boolean, withScans: boolean): Promise<void> {
	keeping.value = concert.id
	keepErrors.value = { ...keepErrors.value, [concert.id]: '' }
	const items = keepable(concert)
	try {
		if (keep) {
			const progress = (done: number) => {
				keepProgress.value = t('cantoria', '{done} of {total} pieces ready …', { done, total: items.length })
			}
			progress(0)
			await device.keep(items.map(forDevice), withScans, progress)
			deviceStatus.value = n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', items.length)
		} else {
			await device.forget(items.map((item) => String(item.id)))
			deviceStatus.value = t('cantoria', '"{title}" is removed from this device.', { title: concert.title })
		}
	} catch (error) {
		console.warn('Cantoria: keeping a programme on this device failed', error)
		const missing = items.filter((item) => !isKept(item)).length
		keepErrors.value = {
			...keepErrors.value,
			[concert.id]: n('cantoria', '%n piece is not available without a network yet. {reason}', '%n pieces are not available without a network yet. {reason}', missing,
				{ reason: deviceErrorText(error) }),
		}
	} finally {
		keeping.value = null
		keepProgress.value = ''
	}
}

/** Delete the kept items and everything remembered for practising, on this device only. */
async function deleteDevice(): Promise<void> {
	await device.deleteDevice()
	confirmingDelete.value = false
	deviceStatus.value = t('cantoria', 'Everything the concert folder kept on this device is deleted.')
}

/** "Remove from this device" at the top: the confirmation at the end of the page, with the focus on it (S N6). */
function startRemoving(): void {
	confirmingDelete.value = true
	focusAfterUpdate(confirmDeleteButton)
}

/**
 * Date, time and place of a programme.
 *
 * @param concert - programme
 */
function when(concert: FolderConcert): string {
	return [concert.date ? formatDate(concert.date) : '', concert.startTime ?? '', concert.location ?? ''].filter((part) => part !== '').join(' · ')
}

/**
 * Minutes and seconds of a duration.
 *
 * @param seconds - duration in seconds
 */
function duration(seconds: number): string {
	return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

/**
 * Composer, shelf mark, encore and duration of an item, in one line.
 *
 * @param item - programme item
 */
function details(item: FolderItem): string {
	return [
		item.composer,
		item.shelfmark ?? '',
		item.encore ? t('cantoria', 'Encore') : '',
		item.duration ? duration(item.duration) : '',
		item.score ? '' : (item.scan ? t('cantoria', 'Scan only') : t('cantoria', 'No score')),
	].filter((part) => part !== '').join(' · ')
}

/**
 * Title of a programme.
 *
 * @param concert - programme
 */
function concertTitle(concert: FolderConcert): string {
	return concert.title || (concert.kind === 'song_list' ? t('cantoria', 'Song list') : t('cantoria', 'Concert'))
}

// ---- the item shown --------------------------------------------------------

async function loadSelectedScore(): Promise<void> {
	const item = selected.value
	if (!item || !item.score) {
		scoreStatus.value = 'idle'
		return
	}
	if (withoutNetwork.value && lockText(item) !== '') {
		scoreStatus.value = 'error'
		scoreError.value = lockText(item)
		return
	}
	scoreStatus.value = 'loading'
	scoreError.value = ''
	try {
		const xml = await fetchFolderScore(item.id)
		if (selectedId.value === item.id) {
			scoreXml.value = xml
			scoreStatus.value = 'ready'
		}
	} catch (error) {
		if (selectedId.value === item.id) {
			scoreStatus.value = 'error'
			scoreError.value = error instanceof Error && error.message !== '' ? error.message : t('cantoria', 'The score could not be loaded.')
		}
	}
}

/**
 * The address of an item, so that it can be bookmarked.
 *
 * @param item - programme item, or null for the list
 */
function itemAddress(item: FolderItem | null): string {
	return window.location.pathname + (item ? '?item=' + item.id : '') + window.location.hash
}

/**
 * Show an item; the address names it, so that it can be bookmarked, and a
 * new entry in the history lets the back gesture of a phone return to the
 * list (U29). An unsaved recording of the shown piece is not lost without a
 * question. The focus goes to the heading and, once the notes are shown,
 * into the notes: the pedal turns at once (U22).
 *
 * @param item - programme item
 * @param moveFocus - put the focus on the piece (not when the page opens with it)
 * @param history - 'push' a new entry (from the list), 'replace' it (from the address) or 'none' (back and forward)
 */
async function select(item: FolderItem, moveFocus = true, history: 'push' | 'replace' | 'none' = 'push'): Promise<void> {
	if (recordingUnsaved.value && item.id !== selectedId.value) {
		pendingItem.value = item
		return
	}
	const changed = item.id !== selectedId.value
	selectedId.value = item.id
	scoreXml.value = ''
	offerNext.value = false
	recordingUnsaved.value = false
	listOpen.value = false
	if (history === 'push' && changed) {
		window.history.pushState({ cantoriaItem: item.id }, '', itemAddress(item))
	} else if (history !== 'none') {
		window.history.replaceState({ cantoriaItem: item.id }, '', itemAddress(item))
	}
	focusScore.value = moveFocus || stageOn.value
	if (moveFocus) {
		// said by the status line as well: the focus goes on into the notes
		turnStatus.value = t('cantoria', '{title}, piece {position} of {count}', { title: item.title, position: neighbours.value.position, count: neighbours.value.count })
		focusAfterUpdate(itemHeading)
	} else {
		turnStatus.value = ''
	}
	await loadSelectedScore()
}

/** Back to the list of programmes: the back gesture, or the first press of Back after a piece (U29). */
function showList(): void {
	if (stageOn.value) {
		toggleStage()
	}
	selectedId.value = null
	scoreStatus.value = 'idle'
	listOpen.value = true
	offerNext.value = false
	focusAfterUpdate(() => document.querySelector<HTMLElement>('.folder-app__concert-title'))
}

/**
 * Back and forward of the browser or the phone: the piece of the address,
 * or the list. With an unsaved recording the piece stays and asks first.
 */
function onPopState(): void {
	const id = Number(new URLSearchParams(window.location.search).get('item'))
	const item = concerts.flatMap((concert) => concert.items).find((entry) => entry.id === id) ?? null
	if (recordingUnsaved.value && item?.id !== selectedId.value) {
		window.history.pushState({ cantoriaItem: selectedId.value }, '', itemAddress(selected.value))
		if (item) {
			pendingItem.value = item
		}
		return
	}
	if (item) {
		select(item, true, 'none')
	} else {
		showList()
	}
}

/** The recording may go: open the piece that was asked for. */
function discardAndOpen(): void {
	const item = pendingItem.value
	pendingItem.value = null
	if (item) {
		recordingUnsaved.value = false
		select(item)
	}
}

/** The item before and after the shown one in its programme, for turning to the next piece */
const neighbours = computed(() => {
	const items = selectedConcert.value?.items ?? []
	const index = items.findIndex((item) => item.id === selectedId.value)
	return {
		previous: index > 0 ? items[index - 1] : null,
		next: index >= 0 && index < items.length - 1 ? items[index + 1] : null,
		position: index + 1,
		count: items.length,
	}
})

/**
 * Page Down of a page-turn pedal in the shown piece that the score did
 * not take (after its last system): the first press says what comes next
 * and puts the focus on "Next", the second opens it. Fields and sliders
 * keep the key. Outside the notes (the heading, or no focus at all in the
 * stage view) the pedal turns the notes all the same (U22).
 *
 * @param event - key event on the page
 */
function onPieceKeydown(event: KeyboardEvent): void {
	if (event.key !== 'PageDown' || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
		return
	}
	const target = event.target instanceof HTMLElement ? event.target : null
	const inPiece = target !== null && pieceElement.value?.contains(target) === true
	const nowhere = target === null || target === document.body || target === document.documentElement
	if (!selected.value || (!inPiece && !(nowhere && stageOn.value)) || target?.closest('input, textarea, select, [contenteditable="true"]')) {
		return
	}
	if (!offerNext.value && !target?.closest('.excerpt-viewer') && viewer.value?.turn(1)) {
		// the heading or the page had the focus: the notes turn, and keep the focus from now on
		event.preventDefault()
		viewer.value.focusScore()
		return
	}
	const next = neighbours.value.next
	if (!next) {
		return
	}
	if (offerNext.value) {
		event.preventDefault()
		select(next)
		return
	}
	event.preventDefault()
	offerNext.value = true
	turnStatus.value = t('cantoria', 'End of the piece. Press again for "{title}".', { title: next.title })
	focusElement(nextButton.value)
}

onMounted(async () => {
	// registers the worker, or removes it and what it kept when the module is off
	await device.start()
	// after the score's own handler, which takes the key while it can turn
	window.addEventListener('keydown', onPieceKeydown)
	window.addEventListener('popstate', onPopState)
	const id = Number(new URLSearchParams(window.location.search).get('item'))
	const linked = concerts.flatMap((concert) => concert.items).find((item) => item.id === id)
	if (linked) {
		select(linked, false, 'replace')
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('keydown', onPieceKeydown)
	window.removeEventListener('popstate', onPopState)
})
</script>

<template>
	<NcContent app-name="cantoria">
		<!-- The visible heading is the page heading: no hidden second one (WCAG 2.4.6) -->
		<NcAppContent :page-title="t('cantoria', 'Concert folder') + ' - ' + t('cantoria', 'Cantoria')">
			<div class="folder-app" :class="{ 'folder-app--stage': stageOn }">
				<h1 class="folder-app__title no-print">
					{{ t('cantoria', 'Concert folder') }}
				</h1>
				<p class="folder-app__intro no-print">
					{{ offlineOn
						? t('cantoria', 'The programmes still to be sung, in their order: view, play and practise each piece, and make a whole programme available without a network for the rehearsal and the concert.')
						: t('cantoria', 'The programmes still to be sung, in their order: view, play and practise each piece.') }}
					<a v-if="!withoutNetwork" :href="archiveUrl" class="folder-app__link">{{ t('cantoria', 'Back to Cantoria') }}</a>
				</p>
				<!-- Not only at the end of the page: on a shared device the way to remove them (S N6) -->
				<RemoveFromDevice v-if="offlineOn && keptCount > 0 && !stageOn" :count="keptCount" @remove="startRemoving" />

				<NcNoteCard v-if="withoutNetwork" type="info" role="status">
					{{ keptCount > 0
						? t('cantoria', 'No network: the pieces available without a network can be viewed, played and practised. {checked}', { checked: checkedText })
						: t('cantoria', 'No network, and no piece is available without a network on this device.') }}
				</NcNoteCard>

				<NcEmptyContent v-if="concerts.length === 0"
					:name="t('cantoria', 'No programme to be sung')"
					:description="t('cantoria', 'Concerts and song lists in draft or fixed appear here, the next one first.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
					</template>
				</NcEmptyContent>

				<div v-else class="folder-app__layout">
					<div class="folder-app__programmes no-print">
						<!-- On a narrow screen the list folds away once a piece is open -->
						<NcButton v-if="selected"
							variant="secondary"
							class="folder-app__list-toggle"
							:aria-expanded="listOpen ? 'true' : 'false'"
							:aria-controls="listId"
							@click="listOpen = !listOpen">
							<template #icon>
								<NcIconSvgWrapper :path="listOpen ? mdiChevronUp : mdiChevronDown" />
							</template>
							{{ listOpen
								? t('cantoria', 'Hide the programme')
								: t('cantoria', 'Programme: {title}, piece {position} of {count}', { title: selectedConcert ? concertTitle(selectedConcert) : '', position: neighbours.position, count: neighbours.count }) }}
						</NcButton>
						<div :id="listId" class="folder-app__list" :class="{ 'folder-app__list--folded': selected && !listOpen }">
							<section v-for="concert in concerts"
								:key="concert.id"
								class="folder-app__concert"
								:aria-labelledby="`folder-concert-${concert.id}`">
								<h2 :id="`folder-concert-${concert.id}`" class="folder-app__concert-title">
									{{ concertTitle(concert) }}
								</h2>
								<p class="folder-app__muted">
									{{ [when(concert), concert.status === 'fixed' ? t('cantoria', 'Programme fixed') : t('cantoria', 'Draft programme')].filter(Boolean).join(' · ') }}
								</p>
								<KeepOffline v-if="offlineOn && keepable(concert).length > 0"
									several
									place="folder"
									:label="keepLabel(concert)"
									:kept="concertKept(concert)"
									:scan-kept="withScanChoice(concert).length > 0 && withScanChoice(concert).every((item) => scanKept(item))"
									:scan-offered="withScanChoice(concert).length > 0"
									:scan-size="scansSize(concert)"
									allowed
									:without-network="withoutNetwork"
									:busy="keeping === concert.id"
									:locked="keeping !== null && keeping !== concert.id"
									:progress="keeping === concert.id ? keepProgress : ''"
									:error="keepErrors[concert.id] ?? ''"
									:first-keep="keptCount === 0"
									@change="(keep: boolean, withScans: boolean) => setConcertKept(concert, keep, withScans)" />
								<!-- which pieces stay behind, and why (U28) -->
								<div v-if="offlineOn && notKeepable(concert).length > 0" class="folder-app__missing">
									<p class="folder-app__muted">
										{{ t('cantoria', 'Not available without a network:') }}
									</p>
									<ul>
										<li v-for="item in notKeepable(concert)" :key="item.id">
											{{ t('cantoria', '{title}: {reason}', { title: item.title, reason: item.offlineReason || t('cantoria', 'the rights allow no copy on the device.') }) }}
										</li>
									</ul>
								</div>
								<ol class="folder-app__items">
									<li v-for="item in concert.items"
										:key="item.id"
										class="folder-app__item"
										:class="{ 'folder-app__item--selected': item.id === selectedId }">
										<button type="button"
											class="folder-app__open"
											:aria-current="item.id === selectedId ? 'true' : undefined"
											@click="select(item)">
											{{ item.title }}
										</button>
										<p v-if="details(item)" class="folder-app__muted">
											{{ details(item) }}
										</p>
										<p v-if="item.participation || item.note" class="folder-app__note-text">
											{{ [item.participation, item.note].filter(Boolean).join(' · ') }}
										</p>
										<p v-if="offlineOn && isKept(item) && !(withoutNetwork && lockText(item) !== '')" class="folder-app__badge">
											<NcIconSvgWrapper :path="mdiCellphoneCheck" :size="16" aria-hidden="true" />
											{{ scanKept(item) ? t('cantoria', 'Available without a network, with the scan') : t('cantoria', 'Available without a network') }}
										</p>
										<p v-else-if="withoutNetwork" class="folder-app__muted">
											{{ isKept(item) ? lockText(item) : t('cantoria', 'Needs a network') }}
										</p>
									</li>
								</ol>
							</section>
						</div>
					</div>

					<section v-if="selected"
						ref="pieceElement"
						class="folder-app__piece"
						:aria-labelledby="itemHeadingId">
						<div class="folder-app__piece-head">
							<div>
								<h2 :id="itemHeadingId" ref="itemHeading" class="folder-app__concert-title">
									{{ selected.title }}
								</h2>
								<p v-if="selected.composer" class="folder-app__muted">
									{{ selected.composer }}
								</p>
							</div>
							<NcButton ref="stageButton"
								variant="tertiary"
								class="no-print"
								:aria-pressed="stageOn ? 'true' : 'false'"
								@click="toggleStage">
								<template #icon>
									<NcIconSvgWrapper :path="stageOn ? mdiFullscreenExit : mdiFullscreen" />
								</template>
								{{ t('cantoria', 'Stage view') }}
							</NcButton>
						</div>
						<!-- "Next" stands once, in the bar of the notes (or at the end without notes) -->
						<div v-if="neighbours.previous" class="folder-app__turn no-print">
							<NcButton variant="tertiary" @click="select(neighbours.previous)">
								{{ t('cantoria', 'Previous: {title}', { title: neighbours.previous.title }) }}
							</NcButton>
						</div>
						<p v-if="offlineOn && !selected.offline && selected.offlineReason" class="folder-app__muted">
							{{ selected.offlineReason }}
						</p>
						<p v-if="selected.scan && withoutNetwork && !scanKept(selected)" class="folder-app__muted">
							{{ t('cantoria', 'The scan is not available without a network.') }}
						</p>
						<ScanFrame v-else-if="selected.scan"
							:key="selected.id"
							class="no-print"
							collection="folder"
							:piece-key="String(selected.id)"
							:title="selected.title" />

						<NcEmptyContent v-if="!selected.score"
							:name="t('cantoria', 'No score')"
							:description="selected.reason ?? t('cantoria', 'This programme item has no MusicXML score.')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
							</template>
						</NcEmptyContent>
						<NcEmptyContent v-else-if="scoreStatus === 'loading'" :name="t('cantoria', 'Loading the score …')">
							<template #icon>
								<NcLoadingIcon :size="64" />
							</template>
						</NcEmptyContent>
						<NcEmptyContent v-else-if="scoreStatus === 'error'"
							:name="t('cantoria', 'The score cannot be shown')"
							:description="scoreError">
							<template #icon>
								<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
							</template>
							<template #action>
								<NcButton @click="loadSelectedScore">
									{{ t('cantoria', 'Try again') }}
								</NcButton>
							</template>
						</NcEmptyContent>
						<ExcerptViewer v-else-if="scoreStatus === 'ready'"
							:key="selected.id"
							ref="viewer"
							v-model:unsaved-recording="recordingUnsaved"
							:xml="scoreXml"
							:title="selected.title"
							:composer="selected.composer"
							:measure-from="null"
							:measure-to="null"
							:can-play="selected.canPlay"
							:play-reason="selected.reason"
							:practice-key="selected.practiceKey"
							:offline="withoutNetwork"
							:stage="stageOn"
							:focus-on-ready="focusScore"
							:printable="selected.print === true">
							<template v-if="neighbours.next || stageOn" #bar>
								<NcButton v-if="neighbours.next"
									ref="nextButton"
									variant="secondary"
									:aria-label="t('cantoria', 'Next: {title}', { title: neighbours.next.title })"
									@click="select(neighbours.next)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiArrowRight" />
									</template>
									{{ t('cantoria', 'Next') }}
								</NcButton>
								<NcButton v-if="stageOn"
									variant="tertiary"
									:aria-label="t('cantoria', 'Leave the stage view')"
									:title="t('cantoria', 'Leave the stage view')"
									@click="toggleStage">
									<template #icon>
										<NcIconSvgWrapper :path="mdiFullscreenExit" />
									</template>
								</NcButton>
							</template>
						</ExcerptViewer>
						<!-- Without notes in view: on to the next piece at the end -->
						<div v-if="neighbours.next && scoreStatus !== 'ready'" class="folder-app__end no-print">
							<NcButton ref="nextButton"
								variant="primary"
								@click="select(neighbours.next)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowRight" />
								</template>
								{{ t('cantoria', 'Next: {title}', { title: neighbours.next.title }) }}
							</NcButton>
						</div>
						<p v-else-if="selectedConcert" class="folder-app__muted folder-app__end">
							{{ t('cantoria', 'End of the programme "{title}".', { title: concertTitle(selectedConcert) }) }}
						</p>
						<p class="hidden-visually" role="status">
							{{ turnStatus }}
						</p>
					</section>
					<p v-else class="folder-app__muted">
						{{ t('cantoria', 'Choose a piece of a programme to see and hear it.') }}
					</p>
				</div>

				<section v-if="offlineOn || state.offline" class="folder-app__device-section no-print" :aria-labelledby="deviceHeadingId">
					<h2 :id="deviceHeadingId" class="folder-app__concert-title">
						{{ t('cantoria', 'Without a network') }}
					</h2>
					<template v-if="offlineOn">
						<p>
							{{ n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', keptCount) }}
							{{ storageText }}
						</p>
						<p class="folder-app__muted">
							{{ t('cantoria', 'Cantoria keeps them in this browser on this device; another browser does not see them.') }}
							<template v-if="keptCount > 0">
								{{ checkedText }}
								{{ storage?.persisted ? t('cantoria', 'The browser keeps them even when space runs short.') : t('cantoria', 'The browser may delete them when space runs short.') }}
							</template>
						</p>
						<p v-if="homeScreen && keptCount > 0" class="folder-app__muted">
							{{ t('cantoria', 'Safari and the home screen keep pieces apart: open this page the way you want to use it without a network.') }}
						</p>
						<p class="folder-app__muted">
							{{ t('cantoria', 'Signing out of Nextcloud usually deletes all of this too, in some browsers only the next time Cantoria opens on this device.') }}
						</p>
						<div class="folder-app__device-buttons">
							<NcButton v-if="!confirmingDelete" variant="secondary" @click="confirmingDelete = true">
								{{ t('cantoria', 'Delete everything on this device') }}
							</NcButton>
							<template v-else>
								<NcButton ref="confirmDeleteButton" variant="error" @click="deleteDevice">
									{{ t('cantoria', 'Delete kept pieces, passages and settings') }}
								</NcButton>
								<NcButton variant="tertiary" @click="confirmingDelete = false">
									{{ t('cantoria', 'Cancel') }}
								</NcButton>
							</template>
						</div>
					</template>
					<p v-else class="folder-app__muted">
						{{ t('cantoria', 'This browser cannot keep pieces for practising without a network.') }}
					</p>
					<p class="hidden-visually" role="status">
						{{ deviceStatus }}
					</p>
				</section>
			</div>
			<DiscardRecording v-if="pendingItem"
				:next="pendingItem.title"
				@discard="discardAndOpen"
				@stay="pendingItem = null" />
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.folder-app {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	box-sizing: border-box;
	max-width: 100%;
	padding: calc(3 * var(--default-grid-baseline));
}

.folder-app p {
	margin: 0;
}

.folder-app__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.folder-app__intro {
	max-width: 80ch;
}

.folder-app__link {
	color: var(--color-main-text);
	text-decoration: underline;
}

.folder-app__layout {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

/* Wide screens: the programmes beside the notes */
@media (min-width: 1024px) {
	.folder-app__layout {
		display: grid;
		grid-template-columns: minmax(16rem, 24rem) minmax(0, 1fr);
		align-items: start;
	}
}

.folder-app__programmes {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.folder-app__list {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

/* Narrow screens: the list folds away once a piece is open */
@media (max-width: 1023px) {
	.folder-app__list--folded {
		display: none;
	}
}

/* Wide screens: the list stands beside the piece */
@media (min-width: 1024px) {
	.folder-app__list-toggle {
		display: none !important;
	}
}

.folder-app__list-toggle {
	align-self: flex-start;
	max-width: 100%;
}

/* Stage view: only the piece, on the whole screen where the browser can
   (the whole page is on the screen, so that dialogs stay visible) */
.folder-app--stage .folder-app__programmes,
.folder-app--stage .folder-app__device-section,
.folder-app--stage .folder-app__intro,
.folder-app--stage .folder-app__title {
	display: none;
}

.folder-app--stage .folder-app__layout {
	display: block;
}

/* larger targets at the music stand */
.folder-app--stage {
	--default-clickable-area: 48px;
}

.folder-app__missing ul {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
	color: var(--color-text-maxcontrast);
}

.folder-app__piece-head {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.folder-app__end {
	display: flex;
	justify-content: flex-end;
	padding-block: calc(2 * var(--default-grid-baseline));
}

/* "available without a network": an icon and words, not only a color */
.folder-app__badge {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) / 2);
	color: var(--color-main-text);
}

.folder-app__concert {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-bottom: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.folder-app__piece {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.folder-app__concert-title {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.folder-app__concert-title:focus-visible,
.folder-app__open:focus-visible,
.folder-app__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.folder-app__muted {
	color: var(--color-text-maxcontrast);
}

.folder-app__note-text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.folder-app__items {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: decimal;
}

.folder-app__item {
	padding: var(--default-grid-baseline) 0;
}

/* the chosen piece: a bar at the start, not only a color (WCAG 1.4.1) */
.folder-app__item--selected {
	padding-inline-start: var(--default-grid-baseline);
	border-inline-start: 4px solid var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.folder-app__open {
	min-height: var(--default-clickable-area);
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
	overflow-wrap: anywhere;
}

.folder-app__turn {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.folder-app__device-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.folder-app__device-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.folder-app {
		--default-clickable-area: 44px;
	}
}

/* Printing: the piece only, black on white */
@media print {
	.folder-app .no-print,
	.folder-app :deep(.notecard[role="status"]) {
		display: none !important;
	}

	.folder-app__layout {
		display: block;
	}

	.folder-app,
	.folder-app__muted {
		color: #000;
	}
}
</style>

<style>
/* Stage view: the header of Nextcloud goes, too */
body:has(.folder-app--stage) {
	--header-height: 0px;
}

body:has(.folder-app--stage) #header {
	display: none;
}
</style>
