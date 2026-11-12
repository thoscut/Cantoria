<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Practice audio files from the score view: the measures, tempo and repeats
 * chosen in the view, a focus voice or every voice, WAV or MIDI. The server
 * checks the rights and records the export before anything is made; the
 * files are made in the browser from the same events and with the same
 * synthesizer as the playback. The app gives nothing to choir members: the
 * librarian or conductor uploads the files to the choir's practice system.
 *
 * Loaded lazily (own chunk) when the dialog is opened.
 */
import type { CountInBars } from '../score/countIn.ts'
import type { ScoreModel } from '../score/model.ts'
import type { PlayMode } from '../score/player.ts'
import type { PracticeFormat, PracticePart } from '../score/practice.ts'
import type { MeasureRange } from '../score/range.ts'
import type { Voice } from '../score/voices.ts'
import type { ZipEntry } from '../score/zip.ts'
import type { Detail, PracticeAudioInput } from '../types.ts'

import { mdiDownload } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onBeforeUnmount, ref, shallowRef, useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { authorizePracticeAudio, errorMessage } from '../api.ts'
import { formatDuration } from '../durations.ts'
import { focusAfterUpdate } from '../focus.ts'
import { formatSize } from '../format.ts'
import { countInFor } from '../score/countIn.ts'
import { playbackTimeline } from '../score/model.ts'
import {
	asciiText,
	estimateBytes,
	LONG_EXPORT_SECONDS,
	partGains,
	practiceFileName,
	practiceMidi,
	practiceParts,
	renderedSeconds,
	SAMPLE_RATE,
} from '../score/practice.ts'
import { transposeText } from '../practiceLabels.ts'
import { transposeTimeline } from '../score/practising.ts'
import { RenderCancelled, renderTimeline } from '../score/render.ts'
import { timbre } from '../score/sungVoice.ts'
import { eventVowels } from '../score/vowels.ts'
import { encodeWav } from '../score/wav.ts'
import { zipStore } from '../score/zip.ts'

const props = defineProps<{
	detail: Detail
	model: ScoreModel
	/** range shown in the score view; null for the whole piece */
	range: MeasureRange | null
	repeats: boolean
	/** tempo in percent of the score's tempo */
	tempoPercent: number
	/** the tempo as text, e.g. "Tempo: 80 % (♩ = 64)" */
	tempoText: string
	/** focus voice of the score view ('' for none) */
	focusKey: string
	mode: PlayMode
	/** count-in of the score view, the first choice here */
	countInBars: CountInBars
	/** display name of a voice */
	voiceName: (voice: Voice) => string
	/** the version line of the score (module "version_states"), stored in the files; '' for none */
	versionLine?: string
	/** semitones the playback is transposed by (milestone 0.13) */
	transpose?: number
}>()

const emit = defineEmits<{
	close: []
}>()

const statusId = useId()

const MIME_TYPES: Record<PracticeFormat | 'zip', string> = {
	wav: 'audio/wav',
	midi: 'audio/midi',
	zip: 'application/zip',
}

// ---- what is exported ---------------------------------------------------------

const shelfmark = computed(() => String(props.detail.copy.shelfmark ?? ''))
const title = computed(() => String(props.detail.work.title ?? ''))
const rate = computed(() => props.tempoPercent / 100)
/** The same events as the playback in the score view */
const timeline = computed(() => transposeTimeline(playbackTimeline(props.model, props.range, props.repeats), props.transpose ?? 0))
/** The vowels of the sung voice, when it is chosen */
const vowels = computed(() => eventVowels(props.model, timeline.value))
const from = computed(() => props.model.measures[props.range?.start ?? 0]?.number ?? '')
const to = computed(() => props.model.measures[props.range?.end ?? props.model.measures.length - 1]?.number ?? '')
/** Count-in at the start of every file: "0", "1" or "2" bars */
const countInChoice = ref<'0' | '1' | '2'>(String(props.countInBars) as '0' | '1' | '2')
const countIn = computed(() => countInFor(props.model, timeline.value, 0, Number(countInChoice.value)))
const playingSeconds = computed(() => ((countIn.value?.lead ?? 0) + timeline.value.duration) / rate.value)

const scope = ref<'one' | 'all'>('one')
const voiceKey = ref(props.mode === 'all' ? '' : props.focusKey)
const singleMode = ref<PlayMode>(props.mode === 'all' || props.focusKey === '' ? 'focus' : props.mode)
const allMode = ref<PlayMode>(props.mode === 'solo' ? 'solo' : 'focus')
const format = ref<PracticeFormat>('wav')
const channels = ref<'1' | '2'>('1')

interface VoiceOption {
	id: string
	label: string
}

const voiceOptions = computed<VoiceOption[]>(() => [
	{ id: '', label: t('cantoria', 'All voices equal (tutti)') },
	...props.model.voices.map((voice) => ({ id: voice.key, label: props.voiceName(voice) })),
])

const voiceOption = computed<VoiceOption>({
	get: () => voiceOptions.value.find((option) => option.id === voiceKey.value) ?? voiceOptions.value[0],
	set: (option) => {
		voiceKey.value = option?.id ?? ''
	},
})

const voice = computed(() => props.model.voices.find((candidate) => candidate.key === voiceKey.value) ?? null)

const tutti = computed(() => t('cantoria', 'Tutti'))

const parts = computed<PracticePart[]>(() => practiceParts(props.model.voices, props.voiceName, {
	allVoices: scope.value === 'all',
	voice: voice.value,
	mode: scope.value === 'all' ? allMode.value : singleMode.value,
	tutti: tutti.value,
}))

const extension = computed(() => (format.value === 'wav' ? 'wav' : 'mid'))

/**
 * File name of one part.
 *
 * @param part - voice and mode of the file
 */
function fileNameOf(part: PracticePart): string {
	return practiceFileName({ shelfmark: shelfmark.value, title: title.value, label: part.label, from: from.value, to: to.value, ext: extension.value })
}

const downloadName = computed(() => (parts.value.length > 1
	? practiceFileName({ shelfmark: shelfmark.value, title: title.value, label: t('cantoria', 'All voices'), from: from.value, to: to.value, ext: 'zip' })
	: fileNameOf(parts.value[0])))

const estimate = computed(() => estimateBytes(format.value, renderedSeconds(timeline.value, rate.value, countIn.value?.lead ?? 0), Number(channels.value),
	timeline.value.events.filter((event) => event.sound).length, parts.value.length))

const long = computed(() => playingSeconds.value > LONG_EXPORT_SECONDS)

const sizeText = computed(() => (format.value === 'wav'
	? t('cantoria', 'About {size}; making it needs about {memory} of memory in the browser.', { size: formatSize(estimate.value.files), memory: formatSize(estimate.value.memory) })
	: t('cantoria', 'About {size}.', { size: formatSize(estimate.value.files) })))

/**
 * A printed measure number as the server accepts it (digits, letters, dots);
 * the position in the score if the file has an unusual one.
 *
 * @param number - printed number
 * @param index - measure index
 */
function measureForServer(number: string, index: number): string {
	return number.replace(/[^0-9A-Za-z.]/g, '').slice(0, 16) || String(index + 1)
}

/**
 * A voice name as the server accepts it: no control characters, at most 100
 * characters.
 *
 * @param label - display name of the voice
 */
function voiceForServer(label: string): string {
	return [...label].filter((char) => char >= ' ' && char !== '\x7f').join('').trim().slice(0, 100)
}

const request = computed<PracticeAudioInput>(() => {
	const part = parts.value[0]
	return {
		copy_id: props.detail.copy.id,
		measure_from: measureForServer(from.value, props.range?.start ?? 0),
		measure_to: measureForServer(to.value, props.range?.end ?? props.model.measures.length - 1),
		voice: scope.value === 'one' && part.voice ? voiceForServer(part.label) : null,
		mode: scope.value === 'all' ? (allMode.value === 'solo' ? 'solo' : 'focus') : part.mode,
		format: format.value,
		all_voices: scope.value === 'all',
		tempo: props.tempoPercent,
		repeats: props.repeats,
	}
})

// ---- making the files -----------------------------------------------------------

const phase = ref<'choose' | 'checking' | 'working'>('choose')
const busy = computed(() => phase.value !== 'choose')
/** share done, 0 … 1 */
const progress = ref(0)
const statusText = ref('')
const error = ref('')
const result = shallowRef<{ name: string, url: string, size: number } | null>(null)

const createButton = ref(null)
const stopButton = ref(null)
const resultElement = ref<HTMLElement | null>(null)

let controller: AbortController | null = null
let lastAnnounced = -1

/** Let the browser paint (progress, status) between two files. */
function nextFrame(): Promise<void> {
	return new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

/**
 * Progress of the whole export; the status text changes in steps of 10 %
 * only, so that screen readers are not flooded.
 *
 * @param index - number of the file (from 0)
 * @param count - number of files
 * @param done - share of this file done
 */
function report(index: number, count: number, done: number): void {
	progress.value = Math.min(1, (index + done) / count)
	const step = Math.floor(progress.value * 10)
	if (step !== lastAnnounced) {
		lastAnnounced = step
		statusText.value = count > 1
			? t('cantoria', 'Making file {number} of {count} … {percent} %', { number: index + 1, count, percent: step * 10 })
			: t('cantoria', 'Making the file … {percent} %', { percent: step * 10 })
	}
}

/**
 * One file.
 *
 * @param part - voice and mode
 * @param onProgress - share of this file done
 * @param signal - cancels the rendering
 */
async function makeFile(part: PracticePart, onProgress: (done: number) => void, signal: AbortSignal): Promise<Uint8Array> {
	if (format.value === 'midi') {
		const data = practiceMidi(props.model, timeline.value, part, {
			rate: rate.value,
			title: [shelfmark.value, title.value].filter((text) => text !== '').join(' '),
			labelOf: props.voiceName,
			countIn: countIn.value,
			text: props.versionLine || undefined,
			transpose: props.transpose ?? 0,
		})
		onProgress(1)
		return data
	}
	const samples = await renderTimeline(timeline.value, partGains(part), {
		rate: rate.value,
		sampleRate: SAMPLE_RATE,
		signal,
		onProgress,
		countIn: countIn.value,
		timbre: timbre.value,
		vowelOf: vowels.value,
	})
	return encodeWav(samples, SAMPLE_RATE, channels.value === '2' ? 2 : 1, props.versionLine ? asciiText(props.versionLine) : '')
}

/**
 * Offer the result as a download.
 *
 * @param data - file content
 * @param name - file name
 * @param type - MIME type
 */
function offer(data: Uint8Array, name: string, type: string): void {
	clearResult()
	const url = URL.createObjectURL(new Blob([data as Uint8Array<ArrayBuffer>], { type }))
	result.value = { name, url, size: data.length }
	const link = document.createElement('a')
	link.href = url
	link.download = name
	link.rel = 'noopener'
	document.body.appendChild(link)
	link.click()
	link.remove()
}

function clearResult(): void {
	if (result.value) {
		URL.revokeObjectURL(result.value.url)
		result.value = null
	}
}

async function create(): Promise<void> {
	if (busy.value) {
		return
	}
	error.value = ''
	clearResult()
	const files = parts.value
	const zipped = files.length > 1
	const name = downloadName.value
	const type = MIME_TYPES[zipped ? 'zip' : format.value]

	// The server checks the rights and records the export first
	controller = new AbortController()
	const signal = controller.signal
	phase.value = 'checking'
	progress.value = 0
	lastAnnounced = -1
	statusText.value = t('cantoria', 'Checking the rights …')
	focusAfterUpdate(stopButton)
	try {
		await authorizePracticeAudio(props.detail.version.id, request.value)
	} catch (caught) {
		finish(signal.aborted ? '' : errorMessage(caught))
		return
	}
	if (signal.aborted) {
		finish('')
		return
	}

	phase.value = 'working'
	try {
		const entries: ZipEntry[] = []
		for (const [index, part] of files.entries()) {
			report(index, files.length, 0)
			await nextFrame()
			const data = await makeFile(part, (done) => report(index, files.length, done), signal)
			if (signal.aborted) {
				throw new RenderCancelled()
			}
			entries.push({ name: fileNameOf(part), data })
		}
		offer(zipped ? zipStore(entries) : entries[0].data, name, type)
		phase.value = 'choose'
		statusText.value = ''
		await focusAfterUpdate(resultElement)
	} catch (caught) {
		if (caught instanceof RenderCancelled || signal.aborted) {
			finish('')
		} else {
			console.error('Practice audio: the file could not be made', caught)
			finish(caught instanceof RangeError
				? t('cantoria', 'The files are too large. Please choose fewer measures.')
				: t('cantoria', 'The file could not be made in this browser.'))
		}
	} finally {
		controller = null
	}
}

/**
 * Back to the choice after a cancel or an error; the focus goes back to the
 * button that starts the export.
 *
 * @param message - error to show, '' after a cancel
 */
function finish(message: string): void {
	const cancelled = message === ''
	phase.value = 'choose'
	error.value = message
	statusText.value = cancelled ? t('cantoria', 'Cancelled. No file was made.') : ''
	controller = null
	focusAfterUpdate(createButton)
}

function stop(): void {
	controller?.abort()
}

/**
 * The dialog asks to close (Escape, close button); not while working.
 *
 * @param open - new open state
 */
function onUpdateOpen(open: boolean): void {
	if (!open && !busy.value) {
		emit('close')
	}
}

onBeforeUnmount(() => {
	controller?.abort()
	clearResult()
})
</script>

<template>
	<NcDialog :name="t('cantoria', 'Practice files')"
		:open="true"
		size="normal"
		:no-close="busy"
		@update:open="onUpdateOpen">
		<div class="practice-dialog">
			<NcNoteCard type="info">
				{{ t('cantoria', 'Playback and practice files come from a simple built-in synthesizer; they are no substitute for a recording. The files are only for practising in the choir, as far as the rights or the license allow. Upload them to the practice system of the choir yourself: Cantoria gives nothing to choir members. Every export is recorded in the audit log.') }}
			</NcNoteCard>

			<dl class="practice-dialog__summary">
				<div>
					<dt>{{ t('cantoria', 'Measures') }}</dt>
					<dd>{{ t('cantoria', '{from} to {to}', { from, to }) }}</dd>
				</div>
				<div>
					<dt>{{ t('cantoria', 'Tempo') }}</dt>
					<dd>{{ tempoText }}</dd>
				</div>
				<div v-if="transpose">
					<dt>{{ t('cantoria', 'Pitch') }}</dt>
					<dd>{{ transposeText(transpose) }}</dd>
				</div>
				<div>
					<dt>{{ t('cantoria', 'Repeats') }}</dt>
					<dd>{{ repeats ? t('cantoria', 'Yes') : t('cantoria', 'No') }}</dd>
				</div>
				<div>
					<dt>{{ t('cantoria', 'Length') }}</dt>
					<dd>{{ formatDuration(playingSeconds) }}</dd>
				</div>
			</dl>
			<p class="practice-dialog__hint">
				{{ t('cantoria', 'Measures, tempo, pitch and repeats are taken from the score view; change them there.') }}
			</p>

			<fieldset class="practice-dialog__choices" :disabled="busy">
				<NcRadioGroup v-model="scope" :label="t('cantoria', 'Files')">
					<NcRadioGroupButton value="one" :label="t('cantoria', 'One file')" />
					<NcRadioGroupButton value="all" :label="t('cantoria', 'Every voice and a tutti (ZIP)')" />
				</NcRadioGroup>

				<template v-if="scope === 'one'">
					<NcSelect v-model="voiceOption"
						class="practice-dialog__voice"
						:input-label="t('cantoria', 'Voice')"
						:options="voiceOptions"
						:clearable="false"
						:searchable="false"
						:disabled="busy" />
					<NcRadioGroup v-if="voice" v-model="singleMode" :label="t('cantoria', 'Other voices')">
						<NcRadioGroupButton value="focus" :label="t('cantoria', 'Focus voice louder')" />
						<NcRadioGroupButton value="solo" :label="t('cantoria', 'Focus voice only')" />
					</NcRadioGroup>
				</template>
				<NcRadioGroup v-else v-model="allMode" :label="t('cantoria', 'In the file of each voice')">
					<NcRadioGroupButton value="focus" :label="t('cantoria', 'Focus voice louder')" />
					<NcRadioGroupButton value="solo" :label="t('cantoria', 'Focus voice only')" />
				</NcRadioGroup>

				<NcRadioGroup v-model="format" :label="t('cantoria', 'Format')">
					<NcRadioGroupButton value="wav" :label="t('cantoria', 'WAV (audio)')" />
					<NcRadioGroupButton value="midi" :label="t('cantoria', 'MIDI (one track per voice)')" />
				</NcRadioGroup>
				<NcRadioGroup v-if="format === 'wav'" v-model="channels" :label="t('cantoria', 'Channels')">
					<NcRadioGroupButton value="1" :label="t('cantoria', 'Mono')" />
					<NcRadioGroupButton value="2" :label="t('cantoria', 'Stereo')" />
				</NcRadioGroup>
				<NcRadioGroup v-model="countInChoice" :label="t('cantoria', 'Count-in')">
					<NcRadioGroupButton value="0" :label="t('cantoria', 'Off')" />
					<NcRadioGroupButton value="1" :label="t('cantoria', '1 bar')" />
					<NcRadioGroupButton value="2" :label="t('cantoria', '2 bars')" />
				</NcRadioGroup>
			</fieldset>

			<div class="practice-dialog__outcome">
				<p class="practice-dialog__file">
					{{ parts.length > 1
						? n('cantoria', 'ZIP archive with %n file: {name}', 'ZIP archive with %n files: {name}', parts.length, { name: downloadName })
						: t('cantoria', 'File: {name}', { name: downloadName }) }}
				</p>
				<p class="practice-dialog__hint">
					{{ sizeText }}
				</p>
			</div>

			<NcNoteCard v-if="long" type="warning">
				{{ t('cantoria', 'At this tempo the excerpt lasts {length}, more than 10 minutes. Making the files takes a while and needs about {memory} of memory; a shorter range may be easier to practise anyway.', { length: formatDuration(playingSeconds), memory: formatSize(estimate.memory) }) }}
			</NcNoteCard>

			<div v-if="busy" class="practice-dialog__progress">
				<progress :aria-labelledby="statusId"
					max="100"
					:value="phase === 'working' ? Math.round(progress * 100) : undefined" />
			</div>
			<p :id="statusId" class="practice-dialog__status" role="status">
				{{ statusText }}
			</p>

			<NcNoteCard v-if="error !== ''" type="error" role="alert">
				{{ error }}
			</NcNoteCard>

			<div v-if="result" class="practice-dialog__result">
				<p ref="resultElement" class="practice-dialog__result-text">
					{{ t('cantoria', 'Made: {name} ({size}). The browser saves the file in your downloads.', { name: result.name, size: formatSize(result.size) }) }}
				</p>
				<NcButton variant="tertiary" :href="result.url" :download="result.name">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('cantoria', 'Save again') }}
				</NcButton>
			</div>
		</div>
		<template #actions>
			<template v-if="busy">
				<NcButton ref="stopButton" @click="stop">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton @click="emit('close')">
					{{ t('cantoria', 'Close') }}
				</NcButton>
				<NcButton ref="createButton" variant="primary" @click="create">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ parts.length > 1 ? t('cantoria', 'Make ZIP archive') : t('cantoria', 'Make file') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<style scoped>
.practice-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
}

.practice-dialog__summary {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(6 * var(--default-grid-baseline));
	margin: 0;
}

.practice-dialog__summary > div {
	/* label above its value (the core styles set dt and dd inline) */
	display: flex;
	flex-direction: column;
}

.practice-dialog__summary dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.practice-dialog__summary dd {
	margin: 0;
}

.practice-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.practice-dialog__choices {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	min-width: 0;
	margin: 0;
	padding: 0;
	border: none;
}

.practice-dialog__voice {
	min-width: min(100%, 16em);
}

.practice-dialog__outcome p,
.practice-dialog__status,
.practice-dialog__result-text {
	margin: 0;
	overflow-wrap: anywhere;
}

.practice-dialog__progress progress {
	width: 100%;
	height: 1em;
	accent-color: var(--color-primary-element);
}

.practice-dialog__result {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}
</style>
