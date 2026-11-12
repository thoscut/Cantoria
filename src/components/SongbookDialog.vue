<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Make a songbook" (round 3, November 2026): the scans (PDF) of a
 * programme, a song list or the catalog as filtered, put together into one
 * PDF to print, with a cover, the contents with the page of each piece and
 * page numbers. The list says per piece whether it may go in and why not:
 * a songbook is a printed copy for the choir, so it needs confirmed public
 * domain or a license for copies for the binders or for the print station,
 * and a scan. The PDF is made in this browser; the server hands out only the
 * scans of the pieces allowed and records the songbook in the audit log.
 */
import type { SongbookOrder, SongbookResult } from '../songbook/build.ts'
import type { SongbookPiece, SongbookPlan } from '../types.ts'

import { mdiCancel, mdiDownload } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, fetchCopiesSongbook, fetchEventSongbook, fetchSongbookScan, recordSongbook } from '../api.ts'
import { formatDate } from '../format.ts'

const props = defineProps<{
	/** the programme or song list, or null for a list of copies */
	eventId?: number | null
	/** the copies, in their order (the catalog as filtered) */
	copyIds?: number[]
}>()

const emit = defineEmits<{
	close: []
}>()

const plan = ref<SongbookPlan | null>(null)
const loadError = ref('')
/** chosen pieces, by their position in the plan (a piece may come twice, as an encore) */
const chosen = ref<string[]>([])
const title = ref('')
const subtitle = ref('')
const cover = ref(true)
const contents = ref(true)
const pageNumbers = ref(true)
const rightHand = ref(false)
const order = ref<SongbookOrder>('given')
const busy = ref(false)
const progress = ref(0)
const error = ref('')
const result = ref<(SongbookResult & { name: string, url: string }) | null>(null)

const fromEvent = computed(() => props.eventId !== null && props.eventId !== undefined)
const allowedCount = computed(() => plan.value?.pieces.filter((piece) => piece.allowed).length ?? 0)
const submitLabel = computed(() => n('cantoria', 'Make a songbook of %n piece', 'Make a songbook of %n pieces', chosen.value.length))

/**
 * Title, composer and shelf mark of a piece in one line.
 *
 * @param piece - piece of the plan
 */
function line(piece: SongbookPiece): string {
	const title = piece.title !== '' ? piece.title : t('cantoria', '(no title)')
	return [piece.note ? `${title} (${piece.note})` : title, piece.composer, piece.shelfmark]
		.filter((part): part is string => part !== null && part !== '').join(' · ')
}

/**
 * A file name from the title, without the characters file systems refuse.
 *
 * @param text - title of the songbook
 */
function fileName(text: string): string {
	const base = text.replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim().slice(0, 100)
	return (base !== '' ? base : t('cantoria', 'Songbook')) + '.pdf'
}

async function load(): Promise<void> {
	try {
		plan.value = fromEvent.value
			? await fetchEventSongbook(props.eventId as number)
			: await fetchCopiesSongbook(props.copyIds ?? [])
		title.value = plan.value.title !== '' ? plan.value.title : t('cantoria', 'Songbook')
		subtitle.value = plan.value.date ? formatDate(plan.value.date) : ''
		order.value = fromEvent.value ? 'given' : 'title'
		chosen.value = plan.value.pieces.flatMap((piece, index) => (piece.allowed ? [String(index)] : []))
	} catch (caught) {
		loadError.value = errorMessage(caught)
	}
}

/**
 * The scans of the chosen pieces, four at a time; a scan used twice is
 * fetched once.
 *
 * @param pieces - the chosen pieces
 */
async function fetchScans(pieces: SongbookPiece[]): Promise<Map<number, ArrayBuffer>> {
	const ids = [...new Set(pieces.map((piece) => piece.scanFileId).filter((id): id is number => id !== null))]
	const scans = new Map<number, ArrayBuffer>()
	let next = 0
	const worker = async (): Promise<void> => {
		while (next < ids.length) {
			const id = ids[next++]!
			scans.set(id, await fetchSongbookScan(id))
			progress.value = scans.size / ids.length / 2
		}
	}
	await Promise.all(Array.from({ length: Math.min(4, ids.length) }, worker))
	return scans
}

function clearResult(): void {
	if (result.value) {
		URL.revokeObjectURL(result.value.url)
		result.value = null
	}
}

function download(): void {
	if (!result.value) {
		return
	}
	const link = document.createElement('a')
	link.href = result.value.url
	link.download = result.value.name
	link.rel = 'noopener'
	document.body.appendChild(link)
	link.click()
	link.remove()
}

async function submit(): Promise<void> {
	if (result.value) {
		emit('close')
		return
	}
	if (!plan.value || chosen.value.length === 0) {
		error.value = t('cantoria', 'Please choose at least one piece.')
		return
	}
	busy.value = true
	error.value = ''
	progress.value = 0
	try {
		const all = plan.value.pieces
		const pieces = chosen.value.map(Number).sort((a, b) => a - b).map((index) => all[index]!).filter((piece) => piece.allowed)
		const scans = await fetchScans(pieces)
		const { buildSongbook, sortPieces } = await import('../songbook/build.ts')
		const sorted = sortPieces(pieces, order.value)
		const built = await buildSongbook(sorted.map((piece) => ({
			title: piece.title,
			composer: piece.composer,
			arranger: piece.arranger,
			shelfmark: piece.shelfmark,
			note: piece.note,
			pdf: scans.get(piece.scanFileId ?? 0) ?? new ArrayBuffer(0),
		})), {
			title: title.value,
			subtitle: subtitle.value,
			cover: cover.value,
			contents: contents.value,
			pageNumbers: pageNumbers.value,
			rightHand: rightHand.value,
			labels: {
				contents: t('cantoria', 'Contents'),
				count: n('cantoria', '%n piece', '%n pieces', sorted.length),
				arranger: t('cantoria', 'Arr.'),
			},
		}, (done, total) => {
			progress.value = 0.5 + done / total / 2
		})
		const copyIds = sorted.filter((piece, index) => !built.skippedIndexes.includes(index))
			.map((piece) => piece.copyId).filter((id): id is number => id !== null)
		if (copyIds.length > 0) {
			await recordSongbook(copyIds, props.eventId ?? null, title.value)
		}
		const name = fileName(title.value)
		result.value = { ...built, name, url: URL.createObjectURL(new Blob([built.bytes as Uint8Array<ArrayBuffer>], { type: 'application/pdf' })) }
		if (built.pieces.length > 0) {
			download()
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

onMounted(load)
onBeforeUnmount(clearResult)
</script>

<template>
	<ActionDialog :name="t('cantoria', 'Make a songbook')"
		:submit-label="result ? t('cantoria', 'Close') : submitLabel"
		:submit-disabled="!result && (plan === null || chosen.length === 0)"
		:busy="busy"
		:done="result !== null"
		:error="error || loadError"
		size="normal"
		@submit="submit"
		@close="emit('close')">
		<p v-if="plan === null && !loadError" class="songbook__loading">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Checking the rights of each piece …') }}
		</p>
		<template v-else-if="plan && !result">
			<p class="action-dialog__hint">
				{{ t('cantoria', 'The scans (PDF) of the chosen pieces become one PDF to print, made in this browser. Only pieces whose rights allow printed copies for the choir go in.') }}
			</p>
			<NcNoteCard v-if="allowedCount === 0" type="warning">
				{{ t('cantoria', 'None of these pieces can go into a songbook. The reasons are listed with each piece.') }}
			</NcNoteCard>
			<fieldset class="songbook__pieces">
				<legend>{{ t('cantoria', 'Pieces') }}</legend>
				<template v-for="(piece, index) in plan.pieces" :key="index">
					<NcCheckboxRadioSwitch v-if="piece.allowed"
						v-model="chosen"
						:value="String(index)"
						type="checkbox">
						<span class="songbook__title">{{ line(piece) }}</span>
					</NcCheckboxRadioSwitch>
					<!-- not a disabled checkbox: the reason must stay readable -->
					<div v-else class="songbook__blocked">
						<NcIconSvgWrapper :path="mdiCancel" :size="20" />
						<div>
							<span class="songbook__title">{{ line(piece) }}</span>
							<span class="songbook__reason">{{ t('cantoria', 'Not included:') }} {{ piece.reason }}</span>
						</div>
					</div>
				</template>
			</fieldset>
			<NcTextField v-model="title" :label="t('cantoria', 'Title on the cover')" />
			<NcTextField v-model="subtitle" :label="t('cantoria', 'Line under the title (optional)')" />
			<fieldset class="songbook__options">
				<legend>{{ t('cantoria', 'Order') }}</legend>
				<NcCheckboxRadioSwitch v-if="fromEvent"
					v-model="order"
					type="radio"
					value="given"
					name="songbook-order">
					{{ t('cantoria', 'As in the programme') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-else
					v-model="order"
					type="radio"
					value="given"
					name="songbook-order">
					{{ t('cantoria', 'As in the list') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="order"
					type="radio"
					value="title"
					name="songbook-order">
					{{ t('cantoria', 'By title') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="order"
					type="radio"
					value="shelfmark"
					name="songbook-order">
					{{ t('cantoria', 'By shelf mark') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<fieldset class="songbook__options">
				<legend>{{ t('cantoria', 'Parts') }}</legend>
				<NcCheckboxRadioSwitch v-model="cover" type="switch">
					{{ t('cantoria', 'Cover') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="contents" type="switch">
					{{ t('cantoria', 'Contents with page numbers') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="pageNumbers" type="switch">
					{{ t('cantoria', 'Page numbers at the bottom') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="rightHand" type="switch">
					{{ t('cantoria', 'Each piece on a right-hand page (double-sided printing)') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<div v-if="busy" class="songbook__progress" role="status">
				<span>{{ t('cantoria', 'Putting the songbook together …') }}</span>
				<NcProgressBar :value="Math.round(progress * 100)" />
			</div>
		</template>
		<div v-else-if="result" class="songbook__result" role="status">
			<NcNoteCard v-if="result.pieces.length > 0" type="success">
				{{ t('cantoria', 'The songbook "{name}" has {pieces} pieces on {pages} pages. The download has started.', { name: result.name, pieces: result.pieces.length, pages: result.pages }) }}
			</NcNoteCard>
			<NcButton v-if="result.pieces.length > 0" variant="secondary" @click="download">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('cantoria', 'Download again') }}
			</NcButton>
			<template v-if="result.skipped.length > 0">
				<NcNoteCard type="warning">
					{{ n('cantoria', 'The scan of %n piece could not be read (damaged or protected by a password) and was left out:', 'The scans of %n pieces could not be read (damaged or protected by a password) and were left out:', result.skipped.length) }}
				</NcNoteCard>
				<ul class="songbook__skipped">
					<li v-for="(skipped, index) in result.skipped" :key="index">
						{{ skipped }}
					</li>
				</ul>
			</template>
		</div>
	</ActionDialog>
</template>

<style scoped>
.songbook__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.songbook__pieces,
.songbook__options {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	border: none;
}

.songbook__pieces legend,
.songbook__options legend {
	padding: 0;
	font-weight: bold;
}

.songbook__title {
	overflow-wrap: anywhere;
}

/* aligned with the label of a checkbox */
.songbook__blocked {
	display: flex;
	align-items: flex-start;
	gap: calc(1.5 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.songbook__blocked .songbook__title {
	display: block;
	color: var(--color-main-text);
}

.songbook__reason {
	display: block;
	overflow-wrap: anywhere;
}

.songbook__progress,
.songbook__result {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
}

.songbook__progress :deep(.progress-bar) {
	width: 100%;
}

.songbook__result :deep(.notecard) {
	margin: 0;
}

.songbook__skipped {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
	overflow-wrap: anywhere;
}
</style>
