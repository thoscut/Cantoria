<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Singing along with a pitch display (milestone 0.14): the tones of the
 * focus voice as bars, the sung pitch as a line, and in words what is sung
 * and how far off it is. The viewer shows the switch in its settings and
 * the display in the bar that stays at the top. The microphone is asked
 * for when the switch goes on and released when it goes off; nothing is
 * kept or scored (score/pitch.ts).
 */
import type { SungPoint, TargetNote, Tuning } from '../score/pitch.ts'

import { getLanguage } from '@nextcloud/l10n'
import { t } from '../l10n.ts'
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { midiPitchName } from '../score/practising.ts'
import { listeningProblem, nearOctave, pitchRange, PitchListener, targetAt, tuningOf } from '../score/pitch.ts'
import { problemOf } from '../score/recording.ts'

const props = defineProps<{
	/** tones of the focus voice; empty without a focus voice */
	notes: TargetNote[]
	/** current place of the playback, seconds of the timeline */
	position: number
	/** whether the playback is running */
	playing: boolean
	/** name of the focus voice, null without one */
	voiceLabel: string | null
	/** key signature of the sound, for the names of the tones */
	fifths: number | null
	/** 'choices': the switch (in the settings); 'display': the pitch display (in the bar that stays at the top) */
	part: 'choices' | 'display'
}>()

const emit = defineEmits<{
	/** a text for the screen reader */
	announce: [text: string]
}>()

const shown = defineModel<boolean>('shown', { required: true })
/** Why listening did not work, '' when it did */
const problem = defineModel<string>('problem', { required: true })

/** Seconds shown before and after the current place */
const PAST = 4
const AHEAD = 2
/** How often the pitch is read, in milliseconds */
const READ_EVERY = 40
const WIDTH = 600
const HEIGHT = 120

const listener = props.part === 'display' ? new PitchListener() : null
const sung = ref<number | null>(null)
const history = shallowRef<SungPoint[]>([])
const listening = ref(false)
let frame = 0
let lastRead = 0

const PROBLEMS: Record<string, () => string> = {
	insecure: () => t('cantoria', 'The pitch display needs a secure connection (https).'),
	unsupported: () => t('cantoria', 'This browser cannot listen to the microphone.'),
	denied: () => t('cantoria', 'The microphone is not allowed. Please allow it for this page in the browser and try again.'),
	'no-microphone': () => t('cantoria', 'No microphone was found.'),
	failed: () => t('cantoria', 'The microphone could not be started.'),
}

/**
 * Stop with a reason; the switch goes off.
 *
 * @param reason - key of PROBLEMS
 */
function fail(reason: string): void {
	problem.value = PROBLEMS[reason]()
	shown.value = false
	emit('announce', problem.value)
}

/**
 * Read the pitch now and then, and keep it while the music plays.
 *
 * @param now - time of the frame in milliseconds
 */
function tick(now: number): void {
	frame = requestAnimationFrame(tick)
	if (!listener || now - lastRead < READ_EVERY) {
		return
	}
	lastRead = now
	sung.value = listener.read()
	if (!props.playing) {
		return
	}
	const kept = history.value.filter((point) => point.time >= props.position - PAST && point.time <= props.position)
	history.value = [...kept, { time: props.position, pitch: sung.value }]
}

/** Ask for the microphone and start reading the pitch. */
async function listen(): Promise<void> {
	const reason = listeningProblem()
	if (!listener || reason !== null) {
		fail(reason ?? 'unsupported')
		return
	}
	try {
		await listener.start()
	} catch (error) {
		fail(problemOf(error))
		return
	}
	if (!shown.value) {
		// switched off again while the browser asked
		listener.stop()
		return
	}
	problem.value = ''
	listening.value = true
	history.value = []
	frame = requestAnimationFrame(tick)
	emit('announce', t('cantoria', 'The microphone is on. Sing along; your pitch is shown as a line next to the tones of {voice}.', { voice: props.voiceLabel ?? '' }))
}

/**
 * Stop reading and release the microphone.
 *
 * @param quiet - without a message (when the page goes)
 */
function silence(quiet = false): void {
	cancelAnimationFrame(frame)
	sung.value = null
	if (listener && listening.value) {
		listener.stop()
		listening.value = false
		if (!quiet) {
			emit('announce', t('cantoria', 'The microphone is off.'))
		}
	}
}

// Only the display listens: from switching on until switching off
if (listener) {
	watch(shown, (on) => (on ? listen() : silence()), { immediate: true })
	onBeforeUnmount(() => silence(true))
}

// A new start of the playback begins a new line
watch(() => props.position, (time, before) => {
	if (time < before - 0.05) {
		history.value = []
	}
})

const range = computed(() => pitchRange(props.notes))
const from = computed(() => props.position - PAST)
const x = (time: number) => (time - from.value) / (PAST + AHEAD) * WIDTH
const y = (pitch: number) => (range.value.high - pitch) / (range.value.high - range.value.low) * HEIGHT
const step = computed(() => HEIGHT / (range.value.high - range.value.low))

const bars = computed(() => props.notes
	.filter((note) => note.end > from.value && note.start < props.position + AHEAD)
	.map((note) => ({ x: x(note.start), y: y(note.pitch) - step.value / 2, width: Math.max(1, x(note.end) - x(note.start) - 1) })))

/**
 * Where a sung pitch is drawn: in the octave of the tone to sing then.
 *
 * @param pitch - sung MIDI pitch with fractions
 * @param time - seconds of the timeline
 */
function drawnPitch(pitch: number, time: number): number {
	const target = targetAt(props.notes, time)
	return target === null ? pitch : nearOctave(pitch, target)
}

/** The sung line, broken where nobody sang */
const line = computed(() => {
	let path = ''
	let open = false
	for (const point of history.value) {
		if (point.pitch === null) {
			open = false
			continue
		}
		path += `${open ? 'L' : 'M'}${x(point.time).toFixed(1)} ${y(drawnPitch(point.pitch, point.time)).toFixed(1)}`
		open = true
	}
	return path
})

const target = computed(() => targetAt(props.notes, props.position))
const dot = computed(() => sung.value === null ? null : y(drawnPitch(sung.value, props.position)))

const german = getLanguage().toLowerCase().startsWith('de')
const nameOf = (pitch: number) => midiPitchName(Math.round(pitch), props.fifths, german)

const TUNINGS: Record<Tuning, () => string> = {
	'in-tune': () => t('cantoria', 'in tune'),
	'bit-high': () => t('cantoria', 'a little high'),
	'bit-low': () => t('cantoria', 'a little low'),
	high: () => t('cantoria', 'too high'),
	low: () => t('cantoria', 'too low'),
}

/** In words: the tone to sing, the sung one and how far off it is */
const words = computed(() => {
	const voice = props.voiceLabel ?? ''
	const goal = target.value === null
		? t('cantoria', '{voice}: rest', { voice })
		: t('cantoria', '{voice}: {tone}', { voice, tone: nameOf(target.value) })
	if (sung.value === null) {
		return `${goal} · ${t('cantoria', 'You: –')}`
	}
	const you = t('cantoria', 'You: {tone}', { tone: nameOf(sung.value) })
	if (target.value === null) {
		return `${goal} · ${you}`
	}
	const { cents, tuning } = tuningOf(sung.value, target.value)
	return `${goal} · ${you}, ${TUNINGS[tuning]()} (${cents > 0 ? '+' : ''}${cents} ct)`
})
</script>

<template>
	<div v-if="part === 'choices'" class="practice-pitch__choices">
		<NcCheckboxRadioSwitch v-model="shown" type="switch" :disabled="!voiceLabel">
			{{ voiceLabel
				? t('cantoria', 'Show my pitch ({voice})', { voice: voiceLabel })
				: t('cantoria', 'Show my pitch') }}
		</NcCheckboxRadioSwitch>
		<p class="practice-pitch__hint">
			{{ voiceLabel
				? t('cantoria', 'Uses the microphone while it is on. The pitch is found in this browser; nothing is recorded or kept. Use headphones.')
				: t('cantoria', 'Choose a focus voice to compare your pitch with it.') }}
		</p>
		<p v-if="problem" class="practice-pitch__hint">
			{{ problem }}
		</p>
	</div>
	<div v-else-if="shown" class="practice-pitch">
		<svg class="practice-pitch__display"
			:viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
			preserveAspectRatio="none"
			role="img"
			:aria-label="t('cantoria', 'Pitch display: the tones of {voice} as bars, your singing as a line', { voice: voiceLabel ?? '' })">
			<rect v-for="(bar, index) in bars"
				:key="index"
				class="practice-pitch__tone"
				:x="bar.x"
				:y="bar.y"
				:width="bar.width"
				:height="step" />
			<line class="practice-pitch__now"
				:x1="x(position)"
				:x2="x(position)"
				y1="0"
				:y2="HEIGHT" />
			<path class="practice-pitch__sung" :d="line" />
			<!-- A round dot that the stretched view box does not distort -->
			<line v-if="dot !== null"
				class="practice-pitch__dot"
				:x1="x(position)"
				:x2="x(position)"
				:y1="dot"
				:y2="dot" />
		</svg>
		<!-- Not a live region: it changes many times a second -->
		<p class="practice-pitch__words">
			{{ words }}
		</p>
	</div>
</template>

<style scoped>
.practice-pitch,
.practice-pitch__choices {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	flex-basis: 100%;
}

.practice-pitch__choices p,
.practice-pitch__words {
	margin: 0;
}

.practice-pitch__display {
	width: 100%;
	height: 96px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
}

.practice-pitch__tone {
	fill: var(--color-background-darker);
	stroke: var(--color-text-maxcontrast);
	stroke-width: 1px;
	vector-effect: non-scaling-stroke;
}

.practice-pitch__now {
	stroke: var(--color-text-maxcontrast);
	stroke-dasharray: 4 4;
	vector-effect: non-scaling-stroke;
}

/* The sung line: thick and in the main color, the tones are boxes, so it is not color alone */
.practice-pitch__sung {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 3px;
	stroke-linejoin: round;
	vector-effect: non-scaling-stroke;
}

.practice-pitch__dot {
	stroke: var(--color-primary-element);
	stroke-width: 10px;
	stroke-linecap: round;
	vector-effect: non-scaling-stroke;
}

.practice-pitch__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

/* Windows contrast themes: the tones as frames, the sung line in the
   highlight colour */
@media (forced-colors: active) {
	.practice-pitch__tone {
		fill: Canvas;
		stroke: CanvasText;
	}

	.practice-pitch__now {
		stroke: GrayText;
	}

	.practice-pitch__sung,
	.practice-pitch__dot {
		stroke: Highlight;
	}
}

/* A low screen (a phone held sideways): a flatter display */
@media (max-height: 560px) {
	.practice-pitch__display {
		height: 48px;
	}
}
</style>
