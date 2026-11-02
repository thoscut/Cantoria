<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Recording oneself while practising (milestone 0.13): sing along with the
 * playback, then listen to the recording alone or with the accompaniment,
 * or save it. The microphone is asked for only on the click; the recording
 * stays in the browser (score/recording.ts).
 */
import type { RecordingProblem } from '../score/recording.ts'

import { mdiContentSaveOutline, mdiDeleteOutline, mdiMicrophone, mdiPlay, mdiRecordCircleOutline, mdiStop } from '@mdi/js'
import { t } from '../l10n.ts'
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fileNamePart } from '../score/practice.ts'
import { problemOf, recordingExtension, recordingProblem, SelfRecorder } from '../score/recording.ts'

const props = defineProps<{
	/** title of the piece, for the file name */
	title: string
	/** whether the playback is running */
	playing: boolean
	/** current place of the playback, seconds of the timeline */
	position: () => number
	/** start the playback (with the count-in) at a place */
	startPlayback: (from: number) => Promise<void>
	/** pause the playback */
	pausePlayback: () => void
}>()

const emit = defineEmits<{
	/** a text for the screen reader */
	announce: [text: string]
}>()

type State = 'idle' | 'starting' | 'recording' | 'ready'

const state = ref<State>('idle')
/**
 * For the view: a recording runs (shown in its bar, also while this tool is
 * folded) or waits unsaved (the view asks before it is lost).
 */
const shownState = defineModel<'idle' | 'recording' | 'ready'>('state', { default: 'idle' })
watch(state, (value) => {
	shownState.value = value === 'starting' ? 'idle' : value
})
const problem = ref<RecordingProblem | null>(null)
const recording = shallowRef<{ url: string, type: string, from: number } | null>(null)
const audio = ref<HTMLAudioElement | null>(null)
/** The recording plays together with the accompaniment */
const together = ref(false)
const recorder = new SelfRecorder()
/** Where the running recording started, seconds of the timeline */
let startedAt = 0

const PROBLEMS: Record<RecordingProblem, () => string> = {
	insecure: () => t('cantoria', 'Recording needs a secure connection (https).'),
	unsupported: () => t('cantoria', 'This browser cannot record.'),
	denied: () => t('cantoria', 'The microphone is not allowed. Please allow it for this page in the browser and try again.'),
	'no-microphone': () => t('cantoria', 'No microphone was found.'),
	failed: () => t('cantoria', 'The recording could not be started.'),
}

function forget(): void {
	if (recording.value) {
		URL.revokeObjectURL(recording.value.url)
		recording.value = null
	}
}

async function record(): Promise<void> {
	problem.value = recordingProblem()
	if (problem.value !== null || props.playing) {
		return
	}
	state.value = 'starting'
	try {
		await recorder.start()
	} catch (error) {
		problem.value = problemOf(error)
		state.value = recording.value ? 'ready' : 'idle'
		emit('announce', PROBLEMS[problem.value]())
		return
	}
	forget()
	startedAt = props.position()
	state.value = 'recording'
	await props.startPlayback(startedAt)
	// after the message of the playback, so that the screen reader says this one
	emit('announce', t('cantoria', 'Recording. Sing along; "Stop recording" or Stop ends it.'))
}

async function finish(): Promise<void> {
	if (state.value !== 'recording') {
		return
	}
	props.pausePlayback()
	const blob = await recorder.stop()
	if (blob) {
		recording.value = { url: URL.createObjectURL(blob), type: blob.type, from: startedAt }
		state.value = 'ready'
		emit('announce', t('cantoria', 'Recording finished. You can listen to it now.'))
	} else {
		state.value = 'idle'
		emit('announce', t('cantoria', 'Nothing was recorded.'))
	}
}

async function listenTogether(): Promise<void> {
	const element = audio.value
	if (!element || !recording.value || props.playing) {
		return
	}
	element.pause()
	element.currentTime = 0
	together.value = true
	// The recording begins with the count-in, like the playback started now
	await props.startPlayback(recording.value.from)
	try {
		await element.play()
	} catch {
		together.value = false
	}
}

function remove(): void {
	audio.value?.pause()
	forget()
	state.value = 'idle'
	emit('announce', t('cantoria', 'Recording deleted.'))
}

function fileName(): string {
	const ext = recordingExtension(recording.value?.type ?? '')
	return `${fileNamePart(props.title, 40) || 'recording'}_${fileNamePart(t('cantoria', 'recording'), 20) || 'recording'}.${ext}`
}

watch(() => props.playing, (playing) => {
	if (playing) {
		return
	}
	if (state.value === 'recording') {
		finish()
	}
	if (together.value) {
		together.value = false
		audio.value?.pause()
	}
})

onBeforeUnmount(() => {
	recorder.cancel()
	forget()
})
</script>

<template>
	<div class="practice-recorder" role="group" :aria-label="t('cantoria', 'Record myself')">
		<div class="practice-recorder__row">
			<NcButton v-if="state !== 'recording'"
				:disabled="playing || state === 'starting'"
				@click="record">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMicrophone" />
				</template>
				{{ recording ? t('cantoria', 'Record again') : t('cantoria', 'Record myself') }}
			</NcButton>
			<NcButton v-else variant="error" @click="finish">
				<template #icon>
					<NcIconSvgWrapper :path="mdiStop" />
				</template>
				{{ t('cantoria', 'Stop recording') }}
			</NcButton>
			<span v-if="state === 'recording'" class="practice-recorder__live">
				<NcIconSvgWrapper :path="mdiRecordCircleOutline" :size="20" aria-hidden="true" />
				{{ t('cantoria', 'Recording …') }}
			</span>
		</div>
		<p v-if="problem" class="practice-recorder__error" role="alert">
			{{ PROBLEMS[problem]() }}
		</p>
		<template v-if="recording && state === 'ready'">
			<!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -- one's own singing, nothing to caption -->
			<audio ref="audio"
				class="practice-recorder__audio"
				controls
				:src="recording.url"
				:aria-label="t('cantoria', 'Your recording')" />
			<div class="practice-recorder__row">
				<NcButton :disabled="playing" @click="listenTogether">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlay" />
					</template>
					{{ t('cantoria', 'Listen with the accompaniment') }}
				</NcButton>
				<NcButton variant="tertiary" :href="recording.url" :download="fileName()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentSaveOutline" />
					</template>
					{{ t('cantoria', 'Save recording') }}
				</NcButton>
				<NcButton variant="tertiary" @click="remove">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
					{{ t('cantoria', 'Delete recording') }}
				</NcButton>
			</div>
		</template>
		<p class="practice-recorder__hint">
			{{ t('cantoria', 'The playback starts with the recording. The recording stays in this browser until you leave the piece; save it to keep it. Use headphones.') }}
		</p>
	</div>
</template>

<style scoped>
.practice-recorder {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	flex-basis: 100%;
}

.practice-recorder__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
	align-items: center;
}

.practice-recorder__live {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.practice-recorder__audio {
	width: 100%;
	max-width: 28em;
}

.practice-recorder__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}

.practice-recorder__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}
</style>
