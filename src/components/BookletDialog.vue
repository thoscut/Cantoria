<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Programme booklet" (proposal 2, November 2026): a PDF for the audience
 * with a cover, the programme with composers, lyricists and their life
 * dates, and the lyrics next to their translation. The server says per
 * piece whether its lyrics may be printed (confirmed public domain or a
 * license for publication) and why not; the lyrics read from the score can
 * be corrected here before printing, people decide. The booklet is made in
 * this browser and recorded in the history of the concert.
 */
import type { Booklet, BookletPerson, BookletPiece } from '../booklet/booklet.ts'
import type { BookletEntry } from '../booklet/build.ts'

import { mdiDownload, mdiTextBoxRemoveOutline } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import { errorMessage } from '../api.ts'
import { fetchBooklet, recordBooklet } from '../booklet/booklet.ts'
import { lifeDates, versesText } from '../booklet/text.ts'
import { formatDuration } from '../durations.ts'
import { formatDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { readStored, writeStored } from '../storage.ts'

const props = defineProps<{
	eventId: number
}>()

const emit = defineEmits<{
	close: []
}>()

const OVERLINE_KEY = 'booklet.overline'

const booklet = ref<Booklet | null>(null)
const loadError = ref('')
const title = ref('')
const overline = ref(readStored(OVERLINE_KEY) ?? '')
const lines = ref('')
const intro = ref('')
const size = ref<'a5' | 'a4'>('a5')
const durations = ref(false)
/** lyrics per item, as edited; printed when the switch is on */
const lyrics = ref<Record<number, string>>({})
const withLyrics = ref<number[]>([])
const busy = ref(false)
const error = ref('')
const result = ref<{ name: string, url: string, pages: number } | null>(null)

const printable = computed(() => booklet.value?.pieces.filter((piece) => piece.lyrics !== null) ?? [])

async function load(): Promise<void> {
	try {
		const data = await fetchBooklet(props.eventId)
		booklet.value = data
		title.value = data.event.title ?? ''
		lines.value = [
			data.event.date ? formatDate(data.event.date) : '',
			data.event.startTime ?? '',
			data.event.location ?? '',
		].filter((line) => line !== '').join('\n')
		for (const piece of data.pieces) {
			if (piece.lyrics !== null) {
				lyrics.value[piece.itemId] = versesText(piece.lyrics)
				withLyrics.value.push(piece.itemId)
			}
		}
	} catch (caught) {
		loadError.value = errorMessage(caught)
	}
}

/**
 * The label of a role in the booklet; the composer has none.
 *
 * @param role - the role
 */
function roleLabel(role: BookletPerson['role']): string {
	switch (role) {
	case 'lyricist':
		return t('cantoria', 'Text')
	case 'arranger':
		return t('cantoria', 'Arr.')
	case 'translator':
		return t('cantoria', 'Translation')
	default:
		return ''
	}
}

/**
 * Who made a piece, with life dates; without people in the catalog the
 * composer and arranger as written.
 *
 * @param piece - the piece
 */
function people(piece: BookletPiece): BookletEntry['people'] {
	const beforeChrist = (year: string) => t('cantoria', '{year} BC', { year })
	if (piece.persons.length > 0) {
		// The translator is named with the translation
		return piece.persons.filter((person) => person.role !== 'translator')
			.map((person) => ({ label: roleLabel(person.role), name: person.name, dates: lifeDates(person, beforeChrist) }))
	}
	return [
		...(piece.composer ? [{ label: '', name: piece.composer, dates: '' }] : []),
		...(piece.arranger ? [{ label: t('cantoria', 'Arr.'), name: piece.arranger, dates: '' }] : []),
	]
}

/**
 * The people of a piece in one line, for the list in the dialog.
 *
 * @param piece - the piece
 */
function peopleLine(piece: BookletPiece): string {
	return people(piece).map((person) => `${person.label ? person.label + ': ' : ''}${person.name}${person.dates ? ` (${person.dates})` : ''}`).join(' · ')
}

/**
 * A file name from the title.
 *
 * @param text - title of the booklet
 */
function fileName(text: string): string {
	const base = text.replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim().slice(0, 100)
	return (base !== '' ? base : t('cantoria', 'Programme booklet')) + '.pdf'
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
	const data = booklet.value
	if (!data) {
		return
	}
	busy.value = true
	error.value = ''
	writeStored(OVERLINE_KEY, overline.value.trim())
	try {
		const printed = data.pieces.filter((piece) => piece.lyrics !== null && withLyrics.value.includes(piece.itemId)
			&& (lyrics.value[piece.itemId] ?? '').trim() !== '').map((piece) => piece.itemId)
		const entries: BookletEntry[] = data.pieces.map((piece) => ({
			title: piece.title !== '' ? piece.title : t('cantoria', '(no title)'),
			people: people(piece),
			duration: piece.duration ? formatDuration(piece.duration) : null,
			encore: piece.encore,
			note: piece.participation,
			lyrics: printed.includes(piece.itemId) ? lyrics.value[piece.itemId] ?? null : null,
			translation: printed.includes(piece.itemId) ? piece.translation : null,
			translationSource: piece.translationSource ?? translatorOf(piece),
		}))
		const { buildBooklet } = await import('../booklet/build.ts')
		const built = await buildBooklet(entries, {
			size: size.value,
			title: title.value,
			overline: overline.value,
			lines: lines.value.split('\n'),
			intro: intro.value,
			durations: durations.value,
			labels: {
				programme: t('cantoria', 'Programme'),
				encore: t('cantoria', 'Encore'),
				lyrics: t('cantoria', 'Lyrics'),
				original: t('cantoria', 'Original'),
				translation: t('cantoria', 'Translation'),
			},
		})
		await recordBooklet(props.eventId, printed)
		result.value = {
			name: fileName(title.value),
			pages: built.pages,
			url: URL.createObjectURL(new Blob([built.bytes as Uint8Array<ArrayBuffer>], { type: 'application/pdf' })),
		}
		download()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

/**
 * The translator from the people of the version, when no source is written.
 *
 * @param piece - the piece
 */
function translatorOf(piece: BookletPiece): string | null {
	const translator = piece.persons.find((person) => person.role === 'translator')
	return translator ? t('cantoria', 'Translation: {name}', { name: translator.name }) : null
}

onMounted(load)
onBeforeUnmount(clearResult)
</script>

<template>
	<ActionDialog :name="t('cantoria', 'Programme booklet')"
		:submit-label="result ? t('cantoria', 'Close') : t('cantoria', 'Make the booklet (PDF)')"
		:submit-disabled="!result && booklet === null"
		:busy="busy"
		:done="result !== null"
		:error="error || loadError"
		size="large"
		@submit="submit"
		@close="emit('close')">
		<p v-if="booklet === null && !loadError" class="booklet__loading">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Checking the rights of the lyrics …') }}
		</p>
		<template v-else-if="booklet && !result">
			<p class="action-dialog__hint">
				{{ t('cantoria', 'A booklet for the audience: cover, programme with composers and lyricists, and the lyrics with their translation. Lyrics are printed only where the rights allow publishing them.') }}
			</p>
			<div class="booklet__fields">
				<NcTextField v-model="overline" :label="t('cantoria', 'Above the title, e.g. the name of the choir')" />
				<NcTextField v-model="title" :label="t('cantoria', 'Title on the cover')" />
				<NcTextArea v-model="lines"
					:label="t('cantoria', 'Lines under the title (date, time, place)')"
					resize="vertical"
					rows="3" />
				<NcTextArea v-model="intro"
					:label="t('cantoria', 'A few words of welcome (optional)')"
					resize="vertical"
					rows="2" />
			</div>
			<fieldset class="booklet__options">
				<legend>{{ t('cantoria', 'Paper') }}</legend>
				<NcCheckboxRadioSwitch v-model="size"
					type="radio"
					value="a5"
					name="booklet-size">
					{{ t('cantoria', 'A5 (A4 folded)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="size"
					type="radio"
					value="a4"
					name="booklet-size">
					{{ t('cantoria', 'A4') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="durations" type="switch">
					{{ t('cantoria', 'Show the durations') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<h3 class="booklet__heading">
				{{ n('cantoria', '%n piece', '%n pieces', booklet.pieces.length) }}
			</h3>
			<ol class="booklet__pieces">
				<li v-for="piece in booklet.pieces" :key="piece.itemId" class="booklet__piece">
					<p class="booklet__title">
						{{ piece.title || t('cantoria', '(no title)') }}
						<span v-if="piece.encore" class="booklet__tag">{{ t('cantoria', 'Encore') }}</span>
					</p>
					<p v-if="peopleLine(piece)" class="booklet__people">
						{{ peopleLine(piece) }}
					</p>
					<template v-if="piece.lyrics !== null">
						<NcCheckboxRadioSwitch v-model="withLyrics" :value="piece.itemId" type="switch">
							{{ t('cantoria', 'Print the lyrics') }}
						</NcCheckboxRadioSwitch>
						<NcTextArea v-if="withLyrics.includes(piece.itemId)"
							v-model="lyrics[piece.itemId]"
							:label="t('cantoria', 'Lyrics read from the score (check them)')"
							resize="vertical"
							rows="5" />
						<p v-if="withLyrics.includes(piece.itemId)" class="booklet__translation">
							{{ piece.translation
								? t('cantoria', 'With the translation of the version.')
								: t('cantoria', 'No translation yet: it is entered on the version in the catalog.') }}
						</p>
					</template>
					<p v-else-if="piece.lyricsReason" class="booklet__reason">
						<NcIconSvgWrapper :path="mdiTextBoxRemoveOutline" :size="18" inline />
						<span>{{ t('cantoria', 'No lyrics:') }} {{ piece.lyricsReason }}</span>
					</p>
				</li>
			</ol>
			<NcNoteCard v-if="printable.length === 0" type="info">
				{{ t('cantoria', 'No lyrics can be printed: the booklet has the cover and the programme.') }}
			</NcNoteCard>
			<p v-if="busy" class="booklet__loading" role="status">
				<NcLoadingIcon :size="20" />
				{{ t('cantoria', 'Setting the booklet …') }}
			</p>
		</template>
		<div v-else-if="result" class="booklet__result" role="status">
			<NcNoteCard type="success">
				{{ n('cantoria', 'The booklet "{name}" has %n page. The download has started.', 'The booklet "{name}" has %n pages. The download has started.', result.pages, { name: result.name }) }}
			</NcNoteCard>
			<NcButton variant="secondary" @click="download">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('cantoria', 'Download again') }}
			</NcButton>
		</div>
	</ActionDialog>
</template>

<style scoped>
.booklet__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.booklet__fields {
	display: grid;
	gap: calc(2 * var(--default-grid-baseline));
}

.booklet__options {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	border: none;
}

.booklet__options legend,
.booklet__heading {
	margin: 0;
	padding: 0;
	font-size: 1em;
	font-weight: bold;
}

.booklet__pieces {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.booklet__piece {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.booklet__piece p {
	margin: 0;
}

.booklet__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.booklet__tag {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 4px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
	font-weight: normal;
}

.booklet__people,
.booklet__translation,
.booklet__reason {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.booklet__reason {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.booklet__result {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
