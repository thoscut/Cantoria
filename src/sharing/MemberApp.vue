<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page "Shared with me" for choir members (role "member", module
 * "member_links", milestone 0.7). Choir members see nothing of the catalog:
 * only the links that the conductor or the music librarians made for them,
 * and the score view of the chosen link: the whole piece or the measures
 * the server cuts, and the scan (PDF) when the link offers it. The list comes
 * first (milestone 0.12): on a phone it is above the notes, on a wide screen
 * beside them. Each entry is short (milestone 0.19): title, composer and
 * place, validity and whether it is available without a network; the rest
 * stands with the chosen piece, which has a way back to the list.
 * The server sends the list with the page and checks the role, the link and
 * the rights again on every call.
 * With the module "offline" (milestone 0.15) a piece can be made available
 * without a network, where its rights allow downloading it, and then be
 * viewed, played and practised without a network (src/offline/). At every
 * start with a network the kept pieces are checked with the server.
 */
import type { Catalog, PieceForDevice } from '../offline/rules.ts'
import type { MemberLink, MemberPageState, MemberPracticeAudioInput } from '../types.ts'

import { mdiAlertCircleOutline, mdiArrowUp, mdiCellphoneCheck, mdiMusicNoteOutline, mdiOpenInNew } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { isAxiosError } from '@nextcloud/axios'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Attribution from './Attribution.vue'
import DiscardRecording from './DiscardRecording.vue'
import ExcerptViewer from './ExcerptViewer.vue'
import KeepOffline from './KeepOffline.vue'
import ScanFrame from './ScanFrame.vue'
import { membersGuideUrl } from '../docs.ts'
import { focusAfterUpdate, focusElement } from '../focus.ts'
import { formatDate, formatDateTime, formatSize } from '../format.ts'
import { askToPersist, checkWithServer, forgetPieces, keepPieces, keepShell, offlineSupported, readCatalog, removeEverything, setUpWorker, storageInfo } from '../offline/device.ts'
import { deviceErrorText } from '../offline/errors.ts'
import { homeScreenAdvice } from '../offline/platform.ts'
import { MAX_DAYS_UNCHECKED, usable } from '../offline/rules.ts'
import { forgetPractising } from '../score/practiceSettings.ts'
import { authorizeMemberPracticeAudio, fetchMemberLinks, fetchMemberScore } from './api.ts'
import { useLeaveGuard } from './leaveGuard.ts'
import { scopeText } from './linkScope.ts'

/**
 * The links from the page; an empty list if the state is missing.
 */
function initialLinks(): MemberLink[] {
	try {
		const state = loadState<MemberPageState>('cantoria', 'member', { links: [] })
		return Array.isArray(state.links) ? state.links : []
	} catch {
		return []
	}
}

/**
 * Practising without a network from the page (module "offline").
 */
function initialOffline(): { enabled: boolean, version: string } {
	try {
		const state = loadState<MemberPageState>('cantoria', 'member', { links: [] })
		return { enabled: state.offline?.enabled === true, version: String(state.offline?.version ?? '') }
	} catch {
		return { enabled: false, version: '' }
	}
}

const links = initialLinks()
const offlineConfig = initialOffline()
/** Pieces can be kept on this device: module on, and the browser can */
const offlineOn = offlineConfig.enabled && offlineSupported()
const deviceHeadingId = useId()
const listHeadingId = useId()
const excerptHeadingId = useId()
const excerptHeading = ref<HTMLElement | null>(null)
const listHeading = ref<HTMLElement | null>(null)
const guideUrl = membersGuideUrl()

const selectedToken = ref<string | null>(null)
const selected = computed(() => links.find((link) => link.token === selectedToken.value) ?? null)
const scoreStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
const scoreXml = ref('')
const scoreError = ref('')
/** A link from the address that is not (or no longer) in the list. */
const notice = ref('')
/** A recording of oneself waits unsaved in the shown piece */
const recordingUnsaved = ref(false)
/** The piece to open once the person decided about the recording */
const pendingLink = ref<MemberLink | null>(null)
useLeaveGuard(recordingUnsaved)

// ---- available without a network (milestones 0.15 and 0.19) ----------------

/** The kept pieces of this account on this device */
const catalog = ref<Catalog | null>(null)
/** The server answered the last check; false: shown from the device */
const reachable = ref(true)
/** Token of the piece being kept or removed, or 'all' */
const keeping = ref<string | null>(null)
/** Why keeping a piece did not work, by token; 'all' for the button for all */
const keepErrors = ref<Record<string, string>>({})
/** "3 of 12 pieces ready …" while all are kept */
const keepProgress = ref('')
const storage = ref<{ usage: number | null, quota: number | null, persisted: boolean } | null>(null)
const confirmingDelete = ref(false)
const deviceStatus = ref('')
const withoutNetwork = computed(() => offlineOn && !reachable.value)
const keptCount = computed(() => Object.keys(catalog.value?.pieces ?? {}).length)
/** Links that may be kept and are not yet */
const notKept = computed(() => links.filter((link) => link.offline && !isKept(link)))
const homeScreen = homeScreenAdvice()

/**
 * What the server says about keeping a link.
 *
 * @param link - member link
 */
function forDevice(link: MemberLink): PieceForDevice {
	return { key: link.token, title: link.title, composer: link.composer, expires: link.expires, offline: link.offline, scan: link.scan }
}

/**
 * Whether a link is kept on this device.
 *
 * @param link - member link
 */
function isKept(link: MemberLink): boolean {
	return catalog.value?.pieces[link.token] !== undefined
}

/**
 * Whether the scan of a kept link is kept, too.
 *
 * @param link - member link
 */
function scanKept(link: MemberLink): boolean {
	return catalog.value?.pieces[link.token]?.scan === true
}

/**
 * Why a piece cannot be shown without a network, or ''.
 *
 * @param link - member link
 */
function lockText(link: MemberLink): string {
	switch (usable(catalog.value, link.token, new Date())) {
	case 'ok':
		return ''
	case 'expired':
		return t('cantoria', 'This link has expired. It is removed from this device at the next start with a network.')
	case 'unchecked':
		return n('cantoria', 'Locked: not checked with the server for more than %n day. Please open this page once with a network.',
			'Locked: not checked with the server for more than %n days. Please open this page once with a network.', MAX_DAYS_UNCHECKED)
	default:
		return t('cantoria', 'There is no network, and this piece is not available without a network.')
	}
}

async function refreshStorage(): Promise<void> {
	storage.value = await storageInfo()
}

/**
 * Check the kept pieces with the server; without an answer the page shows
 * what is kept on the device. With an answer the scripts of the page are
 * kept again, so that the page still opens without a network after an
 * update of the app.
 */
async function checkDevice(): Promise<void> {
	if (!offlineOn) {
		return
	}
	catalog.value = await readCatalog('member')
	try {
		const fresh = await fetchMemberLinks()
		reachable.value = true
		catalog.value = await checkWithServer('member', fresh.map(forDevice))
		if (keptCount.value > 0) {
			keepShell(offlineConfig.version).catch((error) => console.warn('Cantoria: the page could not be kept for use without a network', error))
		}
	} catch (error) {
		// an answer of the server (e.g. not logged in) is no missing network
		reachable.value = isAxiosError(error) && error.response !== undefined
	}
	await refreshStorage()
}

/**
 * Make a piece available without a network or remove it from this device.
 *
 * @param link - member link
 * @param keep - keep it
 * @param withScan - with the scan (PDF)
 */
async function setKept(link: MemberLink, keep: boolean, withScan: boolean): Promise<void> {
	keeping.value = link.token
	keepErrors.value = { ...keepErrors.value, [link.token]: '' }
	try {
		if (keep) {
			catalog.value = await keepPieces('member', [forDevice(link)], withScan, offlineConfig.version)
			await askToPersist()
			deviceStatus.value = t('cantoria', '"{title}" is available without a network.', { title: link.title })
		} else {
			catalog.value = await forgetPieces('member', [link.token])
			deviceStatus.value = t('cantoria', '"{title}" is removed from this device.', { title: link.title })
		}
	} catch (error) {
		console.warn('Cantoria: keeping a piece on this device failed', error)
		keepErrors.value = { ...keepErrors.value, [link.token]: deviceErrorText(error) }
		catalog.value = await readCatalog('member')
	} finally {
		keeping.value = null
		await refreshStorage()
	}
}

/** Make every piece available without a network that may be, without the scans. */
async function keepAll(): Promise<void> {
	const pieces = notKept.value
	keeping.value = 'all'
	keepErrors.value = { ...keepErrors.value, all: '' }
	const progress = (done: number) => {
		keepProgress.value = t('cantoria', '{done} of {total} pieces ready …', { done, total: pieces.length })
	}
	progress(0)
	try {
		catalog.value = await keepPieces('member', pieces.map(forDevice), false, offlineConfig.version, progress)
		await askToPersist()
		deviceStatus.value = n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', pieces.length)
	} catch (error) {
		console.warn('Cantoria: keeping the pieces on this device failed', error)
		catalog.value = await readCatalog('member')
		const missing = pieces.filter((link) => !isKept(link)).length
		keepErrors.value = {
			...keepErrors.value,
			all: n('cantoria', '%n piece is not available without a network yet. {reason}', '%n pieces are not available without a network yet. {reason}', missing,
				{ reason: deviceErrorText(error) }),
		}
	} finally {
		keeping.value = null
		keepProgress.value = ''
		await refreshStorage()
	}
}

/** Delete the kept pieces and everything remembered for practising, on this device only. */
async function deleteDevice(): Promise<void> {
	await removeEverything(null)
	forgetPractising()
	catalog.value = null
	confirmingDelete.value = false
	deviceStatus.value = t('cantoria', 'Everything Cantoria kept on this device is deleted.')
	await refreshStorage()
}

/** Space text: used of available */
const storageText = computed(() => {
	const info = storage.value
	if (!info || info.usage === null) {
		return ''
	}
	return info.quota === null
		? t('cantoria', '{used} used on this device.', { used: formatSize(info.usage) })
		: t('cantoria', '{used} of {available} used on this device.', { used: formatSize(info.usage), available: formatSize(info.quota) })
})

const checkedText = computed(() => {
	const checked = catalog.value ? Date.parse(catalog.value.checked) : NaN
	return Number.isFinite(checked)
		? t('cantoria', 'Last checked with the server: {time}.', { time: formatDateTime(Math.floor(checked / 1000)) })
		: ''
})

function onOnline(): void {
	checkDevice()
}

/** Words of a text reference (the same texts as in the archive). */
const WORDS = {
	page: (page: string) => t('cantoria', 'p. {page}', { page }),
	measure: (measure: string) => t('cantoria', 'm. {measure}', { measure }),
	measures: (from: string, to: string) => t('cantoria', 'm. {from}–{to}', { from, to }),
	verse: (verse: string) => t('cantoria', 'verse {verse}', { verse }),
}

/**
 * Whole piece or measures, voice and verse of a link.
 *
 * @param link - member link
 */
function place(link: MemberLink): string {
	return scopeText({ measureFrom: link.measure_from, measureTo: link.measure_to, voice: link.voice, verse: link.verse }, WORDS,
		t('cantoria', 'Whole piece'))
}

/**
 * What the recipients may do with the chosen piece, in one line.
 *
 * @param link - member link
 */
function abilities(link: MemberLink): string {
	return [
		link.canPlay ? t('cantoria', 'Playback: yes') : t('cantoria', 'Playback: no'),
		link.canExportAudio ? t('cantoria', 'Practice files: yes') : '',
		link.scan ? t('cantoria', 'with scan (PDF)') : '',
	].filter((part) => part !== '').join(' · ')
}

async function loadSelectedScore(): Promise<void> {
	const link = selected.value
	if (!link) {
		return
	}
	if (withoutNetwork.value && lockText(link) !== '') {
		scoreStatus.value = 'error'
		scoreError.value = lockText(link)
		return
	}
	scoreStatus.value = 'loading'
	scoreError.value = ''
	try {
		const xml = await fetchMemberScore(link.token)
		if (selectedToken.value === link.token) {
			scoreXml.value = xml
			scoreStatus.value = 'ready'
		}
	} catch (error) {
		if (selectedToken.value === link.token) {
			scoreStatus.value = 'error'
			scoreError.value = error instanceof Error && error.message !== '' ? error.message : t('cantoria', 'The score could not be loaded.')
		}
	}
}

/**
 * Show an excerpt next to or below the list; the address names it, so
 * that it can be bookmarked. An unsaved recording of the shown piece is not
 * lost without a question.
 *
 * @param link - member link
 * @param moveFocus - put the focus on its heading (after a click in the list)
 */
async function select(link: MemberLink, moveFocus = true): Promise<void> {
	if (recordingUnsaved.value && link.token !== selectedToken.value) {
		pendingLink.value = link
		return
	}
	notice.value = ''
	selectedToken.value = link.token
	scoreXml.value = ''
	recordingUnsaved.value = false
	const url = window.location.pathname + '?link=' + encodeURIComponent(link.token) + window.location.hash
	window.history.replaceState(null, '', url)
	if (moveFocus) {
		focusAfterUpdate(excerptHeading)
	}
	await loadSelectedScore()
}

/** The recording may go: open the piece that was asked for. */
function discardAndOpen(): void {
	const link = pendingLink.value
	pendingLink.value = null
	if (link) {
		recordingUnsaved.value = false
		select(link)
	}
}

/** Back to the list (on a phone it is above the notes). */
function toList(): void {
	listHeading.value?.scrollIntoView?.({ block: 'start' })
	focusElement(listHeading.value)
}

/**
 * Ask the server before a practice file of the shown excerpt is made.
 *
 * @param input - voice, mode, format, tempo, repeats
 */
function exportAudio(input: MemberPracticeAudioInput): Promise<void> {
	const link = selected.value
	if (!link) {
		return Promise.reject(new Error(t('cantoria', 'Please choose a piece from the list first.')))
	}
	return authorizeMemberPracticeAudio(link.token, input)
}

onMounted(async () => {
	if (offlineSupported()) {
		// registers the worker, or removes it and what it kept when the module is off
		await setUpWorker('member', offlineConfig.enabled)
		await checkDevice()
		window.addEventListener('online', onOnline)
	}
	const token = new URLSearchParams(window.location.search).get('link')
	const linked = token === null ? null : links.find((link) => link.token === token)
	if (linked) {
		// from a bookmark or a message: straight to the notes, also on a phone
		select(linked, false)
		nextTick(() => excerptHeading.value?.scrollIntoView?.({ block: 'start' }))
	} else if (token !== null) {
		notice.value = t('cantoria', 'This link is not available to you, or no longer: it may have expired or been revoked. Please ask the conductor or the music librarians for a new link. Everything else shared with you is listed below.')
	} else if (links.length === 1) {
		select(links[0], false)
	}
})

onBeforeUnmount(() => window.removeEventListener('online', onOnline))
</script>

<template>
	<NcContent app-name="cantoria">
		<!-- The visible heading is the page heading: no hidden second one (WCAG 2.4.6) -->
		<NcAppContent :page-title="t('cantoria', 'Shared with me') + ' - ' + t('cantoria', 'Cantoria')">
			<div class="member-app">
				<h1 class="member-app__title no-print">
					{{ t('cantoria', 'Shared with me') }}
				</h1>
				<p class="member-app__intro no-print">
					{{ t('cantoria', 'Pieces and excerpts that the conductor or the music librarians share with the choir members: view, listen and practise your voice. Each link is valid for a limited time.') }}
				</p>

				<NcNoteCard v-if="notice" type="warning" role="status">
					{{ notice }}
				</NcNoteCard>
				<NcNoteCard v-if="withoutNetwork" type="info" role="status">
					{{ keptCount > 0
						? t('cantoria', 'No network: the pieces available without a network can be viewed, played and practised. {checked}', { checked: checkedText })
						: t('cantoria', 'No network, and no piece is available without a network on this device.') }}
				</NcNoteCard>

				<NcEmptyContent v-if="links.length === 0"
					:name="t('cantoria', 'Nothing has been shared with you yet')"
					:description="t('cantoria', 'When the conductor or the music librarians share a piece or an excerpt with the choir members, it appears here. Each link is valid for at most six months.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
					</template>
					<template #action>
						<NcButton :href="guideUrl"
							target="_blank"
							rel="noopener noreferrer"
							:aria-label="t('cantoria', 'Guide for choir members (opens in a new tab)')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiOpenInNew" />
							</template>
							{{ t('cantoria', 'Guide for choir members') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<template v-else>
					<div class="member-app__layout">
						<section class="member-app__list no-print" :aria-labelledby="listHeadingId">
							<h2 :id="listHeadingId" ref="listHeading" class="member-app__list-title">
								{{ n('cantoria', '%n shared link', '%n shared links', links.length) }}
							</h2>
							<div v-if="offlineOn && !withoutNetwork && notKept.length > 1" class="member-app__keep-all">
								<NcButton variant="secondary"
									:disabled="keeping !== null"
									:aria-describedby="keepErrors.all ? `${listHeadingId}-error` : undefined"
									@click="keepAll">
									{{ n('cantoria', 'Make %n available without a network', 'Make all %n available without a network', notKept.length) }}
								</NcButton>
								<p class="member-app__muted">
									{{ t('cantoria', 'Without the scans; each piece can take its scan along.') }}
								</p>
								<p v-if="keepProgress" class="member-app__muted">
									{{ keepProgress }}
								</p>
								<p v-if="keepErrors.all"
									:id="`${listHeadingId}-error`"
									class="member-app__error"
									role="alert">
									<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="20" aria-hidden="true" />
									<span>{{ keepErrors.all }}</span>
								</p>
							</div>
							<ul class="member-app__items">
								<li v-for="link in links"
									:key="link.token"
									class="member-app__item"
									:class="{ 'member-app__item--selected': link.token === selectedToken }">
									<button type="button"
										class="member-app__open"
										:aria-current="link.token === selectedToken ? 'true' : undefined"
										@click="select(link)">
										{{ link.title }}
									</button>
									<p class="member-app__muted">
										{{ [link.composer, place(link)].filter(Boolean).join(' · ') }}
									</p>
									<p v-if="link.expires || (offlineOn && (isKept(link) || withoutNetwork))" class="member-app__meta">
										<span v-if="link.expires" class="member-app__muted">
											{{ t('cantoria', 'Valid until {date}', { date: formatDate(link.expires) }) }}
										</span>
										<span v-if="offlineOn && isKept(link) && !(withoutNetwork && lockText(link) !== '')" class="member-app__badge">
											<NcIconSvgWrapper :path="mdiCellphoneCheck" :size="16" aria-hidden="true" />
											{{ t('cantoria', 'Available without a network') }}
										</span>
										<span v-else-if="withoutNetwork" class="member-app__muted">
											{{ isKept(link) ? lockText(link) : t('cantoria', 'Needs a network') }}
										</span>
									</p>
								</li>
							</ul>
						</section>
						<section v-if="selected" class="member-app__excerpt" :aria-labelledby="excerptHeadingId">
							<h2 :id="excerptHeadingId" ref="excerptHeading" class="member-app__excerpt-title">
								{{ selected.title }}
							</h2>
							<p v-if="selected.composer" class="member-app__muted">
								{{ selected.composer }}
							</p>
							<NcButton v-if="links.length > 1"
								variant="tertiary"
								class="member-app__to-list no-print"
								@click="toList">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowUp" />
								</template>
								{{ t('cantoria', 'Other pieces ({count})', { count: links.length - 1 }) }}
							</NcButton>
							<Attribution :credits="selected.credits ?? []" :notices="selected.notices ?? []" />
							<NcNoteCard v-if="selected.note" type="info" class="member-app__note">
								<p class="member-app__note-text">
									{{ t('cantoria', 'Note: {note}', { note: selected.note }) }}
								</p>
							</NcNoteCard>
							<p class="member-app__muted">
								{{ place(selected) }}<template v-if="selected.expires">
									· {{ t('cantoria', 'Valid until {date}', { date: formatDate(selected.expires) }) }}
								</template>
								· {{ abilities(selected) }}
							</p>
							<KeepOffline v-if="offlineOn"
								:key="selected.token"
								class="no-print"
								:kept="isKept(selected)"
								:scan-kept="scanKept(selected)"
								:scan-offered="selected.scan === true"
								:scan-size="selected.scanSize ?? null"
								:allowed="selected.offline === true"
								:without-network="withoutNetwork"
								:busy="keeping === selected.token"
								:locked="keeping !== null && keeping !== selected.token"
								:progress="keeping === selected.token ? t('cantoria', 'Making it available without a network …') : ''"
								:error="keepErrors[selected.token] ?? ''"
								:lock="isKept(selected) ? lockText(selected) : ''"
								:first-keep="keptCount === 0"
								@change="(keep: boolean, withScan: boolean) => setKept(selected!, keep, withScan)" />
							<p v-if="selected.scan && withoutNetwork && !scanKept(selected)" class="member-app__muted no-print">
								{{ t('cantoria', 'The scan is not available without a network.') }}
							</p>
							<ScanFrame v-else-if="selected.scan"
								:key="selected.token"
								class="no-print"
								collection="member"
								:piece-key="selected.token"
								:title="selected.title" />

							<NcEmptyContent v-if="scoreStatus === 'loading'" :name="t('cantoria', 'Loading the score …')">
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
								:key="selected.token"
								v-model:unsaved-recording="recordingUnsaved"
								:xml="scoreXml"
								:title="selected.title"
								:composer="selected.composer"
								:measure-from="selected.measure_from"
								:measure-to="selected.measure_to"
								:voice="selected.voice"
								:verse="selected.verse"
								:tempo="selected.tempo"
								:can-play="selected.canPlay"
								:play-reason="selected.reason"
								:export-audio="selected.canExportAudio ? exportAudio : null"
								:practice-key="selected.practiceKey ?? null"
								:offline="withoutNetwork" />
						</section>
						<p v-else class="member-app__muted">
							{{ t('cantoria', 'Choose a piece from the list to see and hear it.') }}
						</p>
					</div>
				</template>

				<section class="member-app__device-section no-print" :aria-labelledby="deviceHeadingId">
					<h2 :id="deviceHeadingId" class="member-app__list-title">
						{{ t('cantoria', 'Without a network') }}
					</h2>
					<template v-if="offlineOn">
						<p>
							{{ n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', keptCount) }}
							{{ storageText }}
						</p>
						<p class="member-app__muted">
							{{ t('cantoria', 'Cantoria keeps them in this browser on this device; another browser does not see them.') }}
							<template v-if="keptCount > 0">
								{{ checkedText }}
								{{ storage?.persisted ? t('cantoria', 'The browser keeps them even when space runs short.') : t('cantoria', 'The browser may delete them when space runs short.') }}
							</template>
						</p>
						<p v-if="homeScreen && keptCount > 0" class="member-app__muted">
							{{ t('cantoria', 'Safari and the home screen keep pieces apart: open this page the way you want to use it without a network.') }}
						</p>
					</template>
					<p v-else-if="offlineConfig.enabled" class="member-app__muted">
						{{ t('cantoria', 'This browser cannot keep pieces for practising without a network.') }}
					</p>
					<p class="member-app__muted">
						{{ t('cantoria', 'Passages and settings for practising are remembered in this browser only. On a shared device, delete them when you are done.') }}
					</p>
					<div class="member-app__device-buttons">
						<NcButton v-if="!confirmingDelete" variant="secondary" @click="confirmingDelete = true">
							{{ t('cantoria', 'Delete everything on this device') }}
						</NcButton>
						<template v-else>
							<NcButton variant="error" @click="deleteDevice">
								{{ t('cantoria', 'Delete kept pieces, passages and settings') }}
							</NcButton>
							<NcButton variant="tertiary" @click="confirmingDelete = false">
								{{ t('cantoria', 'Cancel') }}
							</NcButton>
						</template>
					</div>
					<p class="hidden-visually" role="status">
						{{ deviceStatus }}
					</p>
				</section>
			</div>
			<DiscardRecording v-if="pendingLink"
				:next="pendingLink.title"
				@discard="discardAndOpen"
				@stay="pendingLink = null" />
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.member-app {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	box-sizing: border-box;
	max-width: 100%;
	padding: calc(3 * var(--default-grid-baseline));
}

.member-app p {
	margin: 0;
}

.member-app__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.member-app__intro {
	max-width: 80ch;
}

.member-app__layout {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.member-app__excerpt {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.member-app__list {
	padding-bottom: calc(3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

/* Wide screens: the list beside the notes */
@media (min-width: 1024px) {
	.member-app__layout {
		display: grid;
		grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
		align-items: start;
	}

	.member-app__list {
		padding-bottom: 0;
		border-bottom: none;
	}

	/* the list stands beside the notes */
	.member-app__to-list {
		display: none !important;
	}
}

.member-app__excerpt-title,
.member-app__list-title {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.member-app__excerpt-title:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.member-app__muted {
	color: var(--color-text-maxcontrast);
}

.member-app__note-text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.member-app__items {
	display: flex;
	flex-direction: column;
}

.member-app__item {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.member-app__item:last-child {
	border-bottom: none;
}

/* the chosen excerpt: a bar at the start, not only a color (WCAG 1.4.1) */
.member-app__item--selected {
	border-inline-start: 4px solid var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.member-app__open {
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

.member-app__open:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.member-app__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(2 * var(--default-grid-baseline));
}

/* "available without a network": an icon and words, not only a color */
.member-app__badge {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) / 2);
	color: var(--color-main-text);
}

.member-app__error {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	color: var(--color-error-text);
	font-weight: bold;
}

.member-app__to-list {
	align-self: flex-start;
}

.member-app__keep-all {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.member-app__device-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.member-app__device-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.member-app {
		--default-clickable-area: 44px;
	}
}

/* Printing: the piece only (title, composer, note, what is shared and the
   score), black on white */
@media print {
	.member-app .no-print,
	.member-app :deep(.notecard[role="status"]) {
		display: none !important;
	}

	.member-app__layout {
		display: block;
	}

	.member-app,
	.member-app__muted {
		color: #000;
	}
}
</style>
