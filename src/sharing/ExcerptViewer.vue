<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The slim score view of a shared piece (milestone 0.7): for choir members
 * on the page "Shared with me" and for public links. The server sends the
 * measures of the link already cut, or the whole piece with its full
 * playing order for a link without measures; all pages of it are shown, as
 * in the score view of the archive. The voice of the link is the focus
 * voice; it can be played louder or alone. Choir members may also make a
 * practice file, when the parent passes `exportAudio` (the server checks
 * and records each one).
 *
 * Built from the same modules as the score view of the archive (src/score);
 * the text reference and the text description are the alternatives to the
 * notation (WCAG 1.1.1). As there: a count-in, showing and looping a range
 * of measures (From measure, To measure) and starting at a clicked note, or
 * with the arrow keys.
 */
import type { Click, CountInBars } from '../score/countIn.ts'
import type { RecorderState, VoiceOption } from '../components/PlaybackSettings.vue'
import type { MemberPracticeAudioInput } from '../types.ts'
import type { PlayMode, ScorePlayer as ScorePlayerType } from '../score/player.ts'
import type { PracticePart } from '../score/practice.ts'
import type { Mark } from '../score/marks.ts'
import type { Mix } from '../score/practising.ts'
import type { MeasureRange } from '../score/range.ts'
import type { ScoreModel } from '../score/model.ts'
import type { Timeline } from '../score/timeline.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'

import { mdiAlertCircleOutline, mdiChevronUp, mdiDownload, mdiMagnifyMinusOutline, mdiMagnifyPlusOutline, mdiPause, mdiPlay, mdiRecordCircleOutline, mdiStop, mdiTuneVariant } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import PlaybackSettings from '../components/PlaybackSettings.vue'
import PracticeLyrics from '../components/PracticeLyrics.vue'
import PracticePitch from '../components/PracticePitch.vue'
import { startingTonesText, transposeText } from '../practiceLabels.ts'
import { countInFor, metronomeClicks, slotAt, storeCountIn, storedCountIn } from '../score/countIn.ts'
import { markFocus } from '../score/emphasis.ts'
import { followMeasure, turnSystem } from '../score/follow.ts'
import { scoreTarget } from '../score/hit.ts'
import { loadScore, playbackTimeline, renderPages, selectMeasures } from '../score/model.ts'
import { lyricSyllables } from '../score/lyrics.ts'
import { targetNotes } from '../score/pitch.ts'
import { ScorePlayer, voiceGains } from '../score/player.ts'
import { partGains, practiceFileName, practiceMidi, SAMPLE_RATE } from '../score/practice.ts'
import { mixChanged, mixedGains, startingTones, transposedFifths, transposeTimeline } from '../score/practising.ts'
import { sharedPieceKey, storedPracticeSettings, storePracticeSettings } from '../score/practiceSettings.ts'
import { RenderCancelled, renderTimeline } from '../score/render.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { measureStarts, nextPass, noteStarts } from '../score/timeline.ts'
import { createToolkit } from '../score/toolkit.ts'
import { voiceLabel } from '../score/voices.ts'
import { encodeWav } from '../score/wav.ts'
import { nextZoom, storedZoom, storeZoom, ZOOM_DEFAULT, ZOOM_STEPS } from '../score/zoom.ts'
import { resolveRange } from '../score/range.ts'
import { excerptTempo, TEMPO_MAX, TEMPO_MIN, voiceForServer, voiceOfLink } from './excerpt.ts'
import { scopeText } from './linkScope.ts'

const props = withDefaults(defineProps<{
	/** MusicXML of the excerpt, already cut by the server, or of the whole piece */
	xml: string
	title: string
	composer?: string
	/** measures of the link; null for the whole piece */
	measureFrom: string | null
	measureTo: string | null
	/** voice of the link: the first focus voice */
	voice?: string | null
	verse?: number | null
	/** tempo of the link in percent */
	tempo?: number | null
	canPlay: boolean
	/** translated reason why the excerpt cannot be played */
	playReason?: string | null
	/** asks the server before a practice file is made; without it there is no practice file */
	exportAudio?: ((input: MemberPracticeAudioInput) => Promise<void>) | null
	/** key of the piece for the passages and settings remembered in this browser (milestone 0.15) */
	practiceKey?: string | null
	/** shown from the device without a network: practice files need the server (milestone 0.15) */
	offline?: boolean
}>(), {
	composer: '',
	voice: null,
	verse: null,
	tempo: null,
	playReason: null,
	exportAudio: null,
	practiceKey: null,
	offline: false,
})

const summaryId = useId()
const exportStatusId = useId()
const scoreHintId = useId()
const settingsId = useId()

/**
 * The settings below the bar: shown on a large screen, folded on a phone
 * and on a low screen (a phone held sideways), so that the score starts
 * high up (the bar with play and size stays).
 */
const settingsShown = ref(window.matchMedia?.('(min-width: 1024px) and (min-height: 640px)').matches ?? true)

// ---- loading ----------------------------------------------------------------

const status = ref<'loading' | 'ready' | 'error'>('loading')
const errorText = ref('')
const model = shallowRef<ScoreModel | null>(null)
const pages = shallowRef<string[]>([])
const scoreElement = ref<HTMLElement | null>(null)
const pagesElement = ref<HTMLElement | null>(null)
const barElement = ref<HTMLElement | null>(null)

let toolkit: VerovioToolkit | null = null
let player: ScorePlayerType | null = null
let disposed = false
let resizeObserver: ResizeObserver | null = null
let lastWidth = 0

/** Let the browser paint (e.g. the loading indicator) before heavy work. */
function nextFrame(): Promise<void> {
	return new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

async function load(): Promise<void> {
	status.value = 'loading'
	errorText.value = ''
	try {
		const created = toolkit ?? await createToolkit()
		if (disposed) {
			created.destroy()
			return
		}
		toolkit = created
		await nextFrame()
		const loaded = loadScore(created, props.xml)
		model.value = loaded
		range.value = null
		rangeFrom.value = numberSpan.value.first
		rangeTo.value = numberSpan.value.last
		focusKey.value = voiceOfLink(loaded.voices, props.voice, labelOf)?.key ?? ''
		mode.value = focusKey.value !== '' ? 'focus' : 'all'
		status.value = 'ready'
		await nextTick()
		layout()
		updateTimeline()
	} catch (error) {
		console.error('Excerpt view: the score could not be shown', error)
		status.value = 'error'
		errorText.value = t('cantoria', 'The excerpt could not be shown in this browser. Please try another browser; the text reference above names the measures.')
	}
}

// ---- layout and rendering ---------------------------------------------------

const zoomIndex = ref(storedZoom())
const zoomPercent = computed(() => Math.round(ZOOM_STEPS[zoomIndex.value] / ZOOM_STEPS[ZOOM_DEFAULT] * 100))

function layout(): void {
	const container = scoreElement.value
	const loaded = model.value
	if (!toolkit || !container || !loaded) {
		return
	}
	const scale = ZOOM_STEPS[zoomIndex.value]
	const width = Math.max(280, container.clientWidth - 16)
	lastWidth = container.clientWidth
	toolkit.setOptions({ scale, pageWidth: Math.min(100000, Math.max(100, Math.round(width * 100 / scale))) })
	selectMeasures(toolkit, loaded, range.value)
	// cleaned before it goes into the page with v-html
	pages.value = renderPages(toolkit).map(sanitizeSvg)
	nextTick(() => {
		indexElements()
		applyFocus()
		resetHighlights()
	})
}

// ---- measures shown and played ------------------------------------------------

const rangeFrom = ref('')
const rangeTo = ref('')
/** The shown measures; null for all measures that were sent */
const range = shallowRef<MeasureRange | null>(null)
const rangeError = ref('')

const numberSpan = computed(() => {
	const measures = model.value?.measures ?? []
	return { first: measures[0]?.number ?? '', last: measures[measures.length - 1]?.number ?? '' }
})

/** Printed numbers of the shown measures */
const shownNumbers = computed(() => {
	const measures = model.value?.measures ?? []
	const shown = range.value
	return shown === null
		? numberSpan.value
		: { first: measures[shown.start]?.number ?? '', last: measures[shown.end]?.number ?? '' }
})

/** Show (and play, and loop) only the measures of the two fields, as in the score view. */
function applyRange(): void {
	const loaded = model.value
	if (!loaded) {
		return
	}
	const from = rangeFrom.value.trim() || numberSpan.value.first
	const to = rangeTo.value.trim() || numberSpan.value.last
	const result = resolveRange(loaded.measures, from, to)
	if ('error' in result) {
		rangeError.value = result.error === 'order'
			? t('cantoria', 'Measure {to} does not come after measure {from}.', { from, to })
			: t('cantoria', 'There is no measure {number} in this score.', { number: result.error === 'from' ? from : to })
		return
	}
	rangeError.value = ''
	range.value = result.start === 0 && result.end === loaded.measures.length - 1 ? null : result
	layout()
	updateTimeline()
	announce(t('cantoria', 'Measures {from} to {to}', { from: shownNumbers.value.first, to: shownNumbers.value.last }))
}

function showAllMeasures(): void {
	rangeFrom.value = numberSpan.value.first
	rangeTo.value = numberSpan.value.last
	rangeError.value = ''
	range.value = null
	layout()
	updateTimeline()
}

/**
 * @param step - +1 bigger, -1 smaller
 */
function zoomBy(step: number): void {
	const next = nextZoom(zoomIndex.value, step)
	if (next !== zoomIndex.value) {
		zoomIndex.value = next
		storeZoom(next)
		layout()
	}
}

let resizeTimer: ReturnType<typeof setTimeout> | null = null

function onResize(): void {
	if (resizeTimer !== null) {
		clearTimeout(resizeTimer)
	}
	resizeTimer = setTimeout(() => {
		const width = scoreElement.value?.clientWidth ?? 0
		if (Math.abs(width - lastWidth) > 40) {
			layout()
		}
	}, 250)
}

const noteElements = new Map<string, Element>()
const measureElements = new Map<string, SVGGraphicsElement>()

function indexElements(): void {
	noteElements.clear()
	measureElements.clear()
	for (const element of pagesElement.value?.querySelectorAll('g.note[data-id]') ?? []) {
		noteElements.set(element.getAttribute('data-id') ?? '', element)
	}
	for (const element of pagesElement.value?.querySelectorAll<SVGGraphicsElement>('g.measure[data-id]') ?? []) {
		measureElements.set(element.getAttribute('data-id') ?? '', element)
	}
}

// ---- voices -----------------------------------------------------------------

const focusKey = ref('')
/** A recording of oneself runs, or waits unsaved (milestone 0.19) */
const recorderState = ref<RecorderState>('idle')
/** For the page: a recording would be lost when another piece is opened */
const unsavedRecording = defineModel<boolean>('unsavedRecording', { default: false })
watch(recorderState, (state) => {
	unsavedRecording.value = state !== 'idle'
})

/**
 * Name of a voice for display.
 *
 * @param voice - the voice
 */
function labelOf(voice: Voice): string {
	return voiceLabel(voice, (part, number, staff) => part !== ''
		? t('cantoria', '{part}, voice {number}', { part, number })
		: t('cantoria', 'Staff {staff}, voice {number}', { staff, number }))
}

const voiceOptions = computed<VoiceOption[]>(() => [
	{ id: '', label: t('cantoria', 'No focus voice') },
	...(model.value?.voices ?? []).map((voice) => ({ id: voice.key, label: labelOf(voice) })),
])

const focusOption = computed<VoiceOption>({
	get: () => voiceOptions.value.find((option) => option.id === focusKey.value) ?? voiceOptions.value[0],
	set: (option) => {
		focusKey.value = option?.id ?? ''
	},
})

const focusVoice = computed(() => model.value?.voices.find((voice) => voice.key === focusKey.value) ?? null)

function applyFocus(): void {
	if (pagesElement.value) {
		markFocus(pagesElement.value, focusVoice.value?.members ?? [])
	}
}

// ---- playback ---------------------------------------------------------------

const playing = ref(false)
const mode = ref<PlayMode>('all')
const repeats = ref(true)
/** Play the shown measures again and again */
const loop = ref(false)
/** Bars of count-in, remembered in this browser (as in the score view) */
const countInBars = ref<CountInBars>(storedCountIn())
const countInChoice = computed<'0' | '1' | '2'>({
	get: () => String(countInBars.value) as '0' | '1' | '2',
	set: (value) => {
		countInBars.value = Number(value) as CountInBars
	},
})
// Passages and settings remembered per piece in this browser (milestone 0.15)
const marksKey = sharedPieceKey(props.practiceKey, props.title, props.composer)
const remembered = props.practiceKey ? storedPracticeSettings(props.practiceKey, [TEMPO_MIN, TEMPO_MAX]) : null
// the own tempo, unless the link now suggests another one
const tempoPercent = ref(remembered && remembered.linkTempo === props.tempo ? remembered.tempo : excerptTempo(props.tempo))
/** Tools for practising (milestone 0.13): level per voice, semitones, metronome */
const mix = ref<Mix>(remembered?.mix ?? {})
const transpose = ref(remembered?.transpose ?? 0)
const metronome = ref(remembered?.metronome ?? false)
const position = ref(0)
const timeline = shallowRef<Timeline>({ events: [], measures: [], duration: 0 })

const tempoText = computed(() => t('cantoria', 'Tempo: {percent} %', { percent: tempoPercent.value }))

function updateTimeline(): void {
	const loaded = model.value
	if (!loaded) {
		return
	}
	stop()
	timeline.value = transposeTimeline(playbackTimeline(loaded, range.value, repeats.value), transpose.value)
	player?.setTimeline(timeline.value)
	player?.setMetronome(beats())
}

function gains(): (voice: string) => number {
	return mixedGains(voiceGains(focusVoice.value?.members ?? [], mode.value), model.value?.voices ?? [], mix.value)
}

/** Clicks of the metronome, none while it is off. */
function beats(): Click[] {
	return metronome.value && model.value ? metronomeClicks(model.value, timeline.value) : []
}

/** Current place of the playback, for a recording */
function playbackPosition(): number {
	return player?.currentTime() ?? 0
}

/**
 * Play from a place, with the count-in (a recording starts so).
 *
 * @param time - position in seconds of the timeline
 */
async function playFrom(time: number): Promise<void> {
	const current = ensurePlayer()
	if (current.isPlaying) {
		moveTo(time)
		return
	}
	current.seek(time)
	await startPlaying(current)
}

/** Pause without a message, at the end of a recording */
function pausePlayback(): void {
	if (player?.isPlaying) {
		player.pause()
		playing.value = false
	}
}

/**
 * Show a remembered passage and play it in a loop.
 *
 * @param mark - the passage
 */
function applyMark(mark: Mark): void {
	rangeFrom.value = mark.from
	rangeTo.value = mark.to
	applyRange()
	if (rangeError.value === '') {
		loop.value = true
	}
}

/** The voice to read the lyrics along with: the focus voice, else the first with lyrics */
const lyricsVoice = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return null
	}
	if (focusVoice.value) {
		return focusVoice.value
	}
	const withLyrics = new Set(loaded.notesByMeasure.flat().filter((note) => note.lyrics).map((note) => note.staff))
	return loaded.voices.find((voice) => voice.members.some((member) => withLyrics.has(member.staff))) ?? null
})

/** Reading the lyrics along, and the step of the learning mode */
const lyricsShown = ref(false)
const lyricsStep = ref<'0' | '1' | '2' | '3'>('0')

/** Syllables of that voice along the playback (milestone 0.14) */
const syllables = computed(() => model.value && lyricsVoice.value ? lyricSyllables(model.value, timeline.value, lyricsVoice.value) : [])

/** Singing along with the pitch display, and why the microphone did not work */
const pitchShown = ref(false)
const pitchProblem = ref('')

/** Tones of the focus voice for the pitch display (milestone 0.14) */
const pitchNotes = computed(() => focusVoice.value ? targetNotes(timeline.value, focusVoice.value) : [])

/** Key signature of the sound, after transposing */
const soundFifths = computed(() => {
	const fifths = model.value?.signatures.fifths ?? null
	return fifths === null ? null : transposedFifths(fifths, transpose.value)
})

// Without a focus voice there is nothing to compare with
watch(focusVoice, (voice) => {
	if (!voice) {
		pitchShown.value = false
	}
})

/** Display names of the voices for the mixer */
const mixerVoices = computed(() => (model.value?.voices ?? []).map((voice) => ({ key: voice.key, label: labelOf(voice) })))

/**
 * Give the starting tones from the current place: of the focus voice when
 * it plays alone, otherwise of every voice the mixer does not mute.
 */
async function giveStartingTones(): Promise<void> {
	const loaded = model.value
	if (!loaded || playing.value) {
		return
	}
	const voices = mode.value === 'solo' && focusVoice.value
		? [focusVoice.value]
		: loaded.voices.filter((voice) => (mix.value[voice.key] ?? 100) > 0)
	const tones = startingTones(timeline.value, voices, player?.currentTime() ?? 0)
	announce(startingTonesText(tones, labelOf, soundFifths.value))
	await ensurePlayer().playTones(tones.map((tone) => tone.pitch))
}

function ensurePlayer(): ScorePlayerType {
	if (!player) {
		player = new ScorePlayer({
			onPosition: updatePosition,
			onEnd: () => {
				playing.value = false
				position.value = 0
				resetHighlights()
				announce(t('cantoria', 'End of playback.'))
			},
		})
		player.setTimeline(timeline.value)
		player.setRate(tempoPercent.value / 100)
		player.setGains(gains())
		player.setMetronome(beats())
		player.setLoop(loop.value)
	}
	return player
}

async function togglePlay(): Promise<void> {
	if (!props.canPlay || status.value !== 'ready') {
		return
	}
	const current = ensurePlayer()
	if (current.isPlaying) {
		current.pause()
		playing.value = false
		announce(t('cantoria', 'Paused at measure {number}.', { number: currentMeasureNumber.value }))
		return
	}
	await startPlaying(current)
}

/**
 * Start playing from the player's position, with the count-in.
 *
 * @param current - the player
 */
async function startPlaying(current: ScorePlayerType): Promise<void> {
	resetHighlights()
	const loaded = model.value
	const bars = countInBars.value
	await current.play(loaded && bars > 0 ? (time) => countInFor(loaded, timeline.value, time, bars) : undefined)
	playing.value = current.isPlaying
	if (playing.value) {
		lastMeasureAnnouncement = Date.now()
		updatePosition(current.currentTime())
		const number = currentMeasureNumber.value
		announce(bars > 0
			? t('cantoria', 'Count-in, then playing from measure {number}.', { number })
			: t('cantoria', 'Playing from measure {number}.', { number }))
	}
}

/**
 * Go to a place of the playback: while playing, it continues there;
 * otherwise the next start begins there.
 *
 * @param time - position in seconds of the timeline
 */
function moveTo(time: number): void {
	const current = ensurePlayer()
	current.seek(time)
	resetHighlights()
	updatePosition(time)
	lastMeasureAnnouncement = Date.now()
	const number = currentMeasureNumber.value
	announce(current.isPlaying
		? t('cantoria', 'Playing from measure {number}.', { number })
		: t('cantoria', 'Start at measure {number}.', { number }))
}

/**
 * A click or a tap into the score: on a note or a measure (see
 * scoreTarget), the playback starts there (at its next pass from the
 * current position). One handler on the
 * container; the SVG itself carries no handlers.
 *
 * @param event - click event
 */
function onScoreClick(event: MouseEvent): void {
	const target = scoreTarget(event, pagesElement.value)
	const loaded = model.value
	if (!target || !loaded || !props.canPlay || status.value !== 'ready') {
		return
	}
	const after = player?.currentTime() ?? 0
	const note = target.closest('g.note[data-id]')
	let time = note ? nextPass(noteStarts(timeline.value, note.getAttribute('data-id') ?? ''), after) : null
	if (time === null) {
		const measure = target.closest('g.measure[data-id]')
		const index = measure ? loaded.measures.findIndex((candidate) => candidate.id === measure.getAttribute('data-id')) : -1
		time = index === -1 ? null : nextPass(measureStarts(timeline.value, index), after)
	}
	if (time === null) {
		return
	}
	const current = ensurePlayer()
	if (current.isPlaying) {
		moveTo(time)
	} else {
		current.seek(time)
		startPlaying(current)
	}
}

/**
 * The keyboard alternative to clicking a note: arrow left and right in the
 * score go to the previous or next measure in playing order.
 *
 * @param step - -1 back, +1 forward
 */
function stepMeasure(step: number): void {
	const slots = timeline.value.measures
	if (slots.length === 0) {
		return
	}
	const current = Math.max(0, slotAt(slots, player?.currentTime() ?? 0))
	moveTo(slots[Math.min(slots.length - 1, Math.max(0, current + step))].start)
}

function stop(): void {
	if (!player) {
		return
	}
	const wasActive = player.isPlaying || player.currentTime() > 0
	player.stop()
	playing.value = false
	position.value = 0
	resetHighlights()
	if (wasActive) {
		announce(t('cantoria', 'Stopped.'))
	}
}

watch([focusKey, mode], () => {
	applyFocus()
	player?.setGains(gains())
})
watch(mix, () => player?.setGains(gains()))
watch(transpose, () => updateTimeline())
watch(metronome, () => player?.setMetronome(beats()))
watch(focusKey, (key) => {
	if (key === '') {
		mode.value = 'all'
	}
})
watch(tempoPercent, (value) => player?.setRate(value / 100))
watch(repeats, () => updateTimeline())
watch(countInBars, (bars) => storeCountIn(bars))
watch([tempoPercent, transpose, metronome, mix], () => {
	if (props.practiceKey) {
		storePracticeSettings(props.practiceKey, {
			tempo: tempoPercent.value,
			linkTempo: props.tempo,
			transpose: transpose.value,
			metronome: metronome.value,
			mix: mix.value,
		})
	}
}, { deep: true })
watch(loop, (on) => {
	// The loop takes the measures of the fields "From measure" and "To measure"
	const shown = shownNumbers.value
	if (on && (rangeFrom.value.trim() !== shown.first || rangeTo.value.trim() !== shown.last)) {
		applyRange()
	}
	player?.setLoop(on)
	announce(on
		? t('cantoria', 'Loop on: measures {from} to {to} play again and again.', { from: shownNumbers.value.first, to: shownNumbers.value.last })
		: t('cantoria', 'Loop off.'))
})

// ---- following the playback -------------------------------------------------

let active: { id: string, end: number }[] = []
let nextHighlight = 0
let currentSlot = -1
const currentMeasure = ref(-1)

const currentMeasureNumber = computed(() => {
	const index = currentMeasure.value >= 0 ? currentMeasure.value : (timeline.value.measures[0]?.index ?? 0)
	return model.value?.measures[index]?.number ?? ''
})

function resetHighlights(): void {
	for (const element of pagesElement.value?.querySelectorAll('.score-playing') ?? []) {
		element.classList.remove('score-playing')
	}
	active = []
	nextHighlight = 0
	currentSlot = -1
	currentMeasure.value = -1
}

/**
 * Highlight what sounds at a time and follow the measure.
 *
 * @param time - position in seconds of the timeline
 */
function updatePosition(time: number): void {
	if (time < position.value - 0.05) {
		// The loop started again
		resetHighlights()
	}
	position.value = time
	const events = timeline.value.events
	while (nextHighlight < events.length && events[nextHighlight].start <= time) {
		const event = events[nextHighlight++]
		if (event.end > time) {
			noteElements.get(event.id)?.classList.add('score-playing')
			active.push({ id: event.id, end: event.end })
		}
	}
	active = active.filter((item) => {
		if (item.end > time) {
			return true
		}
		if (!active.some((other) => other !== item && other.id === item.id && other.end > time)) {
			noteElements.get(item.id)?.classList.remove('score-playing')
		}
		return false
	})
	const slots = timeline.value.measures
	let slot = currentSlot >= 0 && currentSlot < slots.length && slots[currentSlot].start <= time ? currentSlot : 0
	while (slot + 1 < slots.length && slots[slot + 1].start <= time) {
		slot++
	}
	if (slot !== currentSlot && slots[slot]) {
		currentSlot = slot
		onMeasureChange(slots[slot].index)
	}
}

/**
 * @param index - measure index
 */
function onMeasureChange(index: number): void {
	const measure = model.value?.measures[index]
	if (!measure) {
		return
	}
	const previous = currentMeasure.value
	currentMeasure.value = index
	const element = measureElements.get(measure.id)
	if (element) {
		scrollToMeasure(element)
	}
	if (!measure.implicit && previous !== index) {
		announceMeasure(measure.number)
	}
}

function scrollToMeasure(element: Element): void {
	// The score scrolls in its box on a large screen, with the page on a
	// phone; the bar at the top stays and covers part of the view
	if (scoreElement.value) {
		followMeasure(element, scoreElement.value, barElement.value)
	}
}

// ---- announcements for screen readers --------------------------------------

const announcement = ref('')
let lastMeasureAnnouncement = 0

/**
 * @param text - message for the live region
 */
function announce(text: string): void {
	announcement.value = announcement.value === text ? text + ' ' : text
}

/**
 * @param number - printed measure number
 */
function announceMeasure(number: string): void {
	const now = Date.now()
	if (now - lastMeasureAnnouncement < 3000) {
		return
	}
	lastMeasureAnnouncement = now
	announce(t('cantoria', 'Measure {number}', { number }))
}

// ---- text reference and description -----------------------------------------

/** Words of a text reference (the same texts as in the archive). */
const WORDS = {
	page: (page: string) => t('cantoria', 'p. {page}', { page }),
	measure: (measure: string) => t('cantoria', 'm. {measure}', { measure }),
	measures: (from: string, to: string) => t('cantoria', 'm. {from}–{to}', { from, to }),
	verse: (verse: string) => t('cantoria', 'verse {verse}', { verse }),
}

/** A link without measures: the whole piece */
const whole = computed(() => props.measureFrom === null || props.measureTo === null)

/** Text reference of the link: title, measures or "Whole piece", voice of the link, verse. */
const reference = computed(() => [props.title, scopeText({
	measureFrom: props.measureFrom,
	measureTo: props.measureTo,
	voice: props.voice,
	verse: props.verse,
}, WORDS, t('cantoria', 'Whole piece'))].filter((part) => part.trim() !== '').join(' · '))

/** The shown measures as text */
const rangeText = computed(() => (whole.value && range.value === null
	? t('cantoria', 'Whole piece')
	: t('cantoria', 'Measures {from} to {to}', { from: shownNumbers.value.first, to: shownNumbers.value.last })))

const rangeHint = computed(() => {
	const parts = [t('cantoria', 'Printed measure numbers {first} to {last}.', numberSpan.value)]
	if (model.value?.pickup) {
		parts.push(t('cantoria', 'The pickup is measure 0.'))
	}
	return parts.join(' ')
})

const keyText = computed(() => {
	const signatures = model.value?.signatures
	if (!signatures || signatures.fifths === null) {
		return ''
	}
	const fifths = signatures.fifths
	let text = fifths === 0
		? t('cantoria', 'no sharps or flats')
		: (fifths > 0 ? n('cantoria', '%n sharp', '%n sharps', fifths) : n('cantoria', '%n flat', '%n flats', -fifths))
	if (signatures.mode === 'major') {
		text += ', ' + t('cantoria', 'major')
	} else if (signatures.mode === 'minor') {
		text += ', ' + t('cantoria', 'minor')
	}
	return t('cantoria', 'Key signature: {key}', { key: text })
})

const summary = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	return [
		rangeText.value,
		n('cantoria', '%n voice', '%n voices', loaded.voices.length),
		focusVoice.value ? t('cantoria', 'Focus voice: {voice}', { voice: labelOf(focusVoice.value) }) : t('cantoria', 'No focus voice'),
	].join(' · ')
})

const description = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	const sentences = [
		whole.value ? t('cantoria', 'Score of {title}', { title: props.title }) : t('cantoria', 'Excerpt of {title}', { title: props.title }),
		rangeText.value,
		n('cantoria', '%n voice: {voices}', '%n voices: {voices}', loaded.voices.length, { voices: loaded.voices.map(labelOf).join(', ') }),
		focusVoice.value
			? t('cantoria', 'The focus voice {voice} is shown in color with bold note heads and stems, the other voices in a lighter gray.', { voice: labelOf(focusVoice.value) })
			: t('cantoria', 'No focus voice'),
	]
	if (props.verse) {
		sentences.push(t('cantoria', 'Verse {verse}', { verse: props.verse }))
	}
	if (keyText.value !== '') {
		sentences.push(keyText.value)
	}
	if (loaded.signatures.time !== '') {
		sentences.push(t('cantoria', 'Time signature: {time}', { time: loaded.signatures.time }))
	}
	return sentences.join('. ') + '.'
})

// ---- practice file (choir members) -------------------------------------------------

const canExport = computed(() => props.exportAudio !== null && props.canPlay && !props.offline)
const format = ref<'wav' | 'midi'>('wav')
const exporting = ref(false)
const exportStatus = ref('')
const exportError = ref('')
const download = shallowRef<{ name: string, url: string } | null>(null)
let exportController: AbortController | null = null

/** One file: the focus voice louder or alone, or all voices equal. */
const exportPart = computed<PracticePart>(() => {
	const voice = focusVoice.value
	return voice && mode.value !== 'all'
		? { voice, mode: mode.value, label: labelOf(voice) }
		: { voice: null, mode: 'all', label: t('cantoria', 'Tutti') }
})

const exportChoice = computed(() => {
	const part = exportPart.value
	// the count-in of the playback is at the start of the file, too
	const timing = countInBars.value > 0
		? t('cantoria', '{tempo}, count-in {bars}', { tempo: tempoText.value, bars: countInBars.value === 1 ? t('cantoria', '1 bar') : t('cantoria', '2 bars') })
		: tempoText.value
	// pitch and mixer of the playback, too (milestone 0.13)
	const mixed = mixChanged(mix.value)
	const tempo = [timing, transpose.value !== 0 ? transposeText(transpose.value) : '', mixed ? t('cantoria', 'mixer as set above') : '']
		.filter((text) => text !== '').join(', ')
	if (part.voice === null) {
		return mixed
			? t('cantoria', 'All voices, {tempo}', { tempo })
			: t('cantoria', 'All voices equal, {tempo}', { tempo })
	}
	return part.mode === 'solo'
		? t('cantoria', '{voice} alone, {tempo}', { voice: part.label, tempo })
		: t('cantoria', '{voice} louder, {tempo}', { voice: part.label, tempo })
})

function clearDownload(): void {
	if (download.value) {
		URL.revokeObjectURL(download.value.url)
		download.value = null
	}
}

/**
 * Offer a made file as a download.
 *
 * @param data - file content
 * @param name - file name
 * @param type - MIME type
 */
function offer(data: Uint8Array, name: string, type: string): void {
	clearDownload()
	const url = URL.createObjectURL(new Blob([data as Uint8Array<ArrayBuffer>], { type }))
	download.value = { name, url }
	const link = document.createElement('a')
	link.href = url
	link.download = name
	link.rel = 'noopener'
	document.body.appendChild(link)
	link.click()
	link.remove()
}

async function makePracticeFile(): Promise<void> {
	const loaded = model.value
	const authorize = props.exportAudio
	if (!loaded || !authorize || exporting.value) {
		return
	}
	if (player?.isPlaying) {
		player.pause()
		playing.value = false
	}
	const part = exportPart.value
	const rate = tempoPercent.value / 100
	const ext = format.value === 'wav' ? 'wav' : 'mid'
	const shown = range.value
	const { first, last } = shownNumbers.value
	const name = practiceFileName({ shelfmark: '', title: props.title, label: part.label, from: first, to: last, ext })
	exporting.value = true
	exportError.value = ''
	exportStatus.value = t('cantoria', 'Checking the rights …')
	exportController = new AbortController()
	const signal = exportController.signal
	try {
		await authorize({
			voice: part.voice ? voiceForServer(part.label) : null,
			mode: part.mode,
			format: format.value,
			tempo: tempoPercent.value,
			repeats: repeats.value,
			measure_from: first,
			measure_to: last,
		})
		exportStatus.value = t('cantoria', 'Making the file …')
		await nextFrame()
		const events = transposeTimeline(playbackTimeline(loaded, shown, repeats.value), transpose.value)
		const countIn = countInFor(loaded, events, 0, countInBars.value)
		// The file sounds like the playback above, with the mixer
		const gainOf = mixedGains(partGains(part), loaded.voices, mix.value)
		let data: Uint8Array
		if (format.value === 'midi') {
			data = practiceMidi(loaded, events, part, { rate, title: props.title, labelOf, countIn, transpose: transpose.value, gainOf })
		} else {
			const samples = await renderTimeline(events, gainOf, {
				rate,
				sampleRate: SAMPLE_RATE,
				signal,
				countIn,
				onProgress: (done) => {
					exportStatus.value = t('cantoria', 'Making the file … {percent} %', { percent: Math.floor(done * 10) * 10 })
				},
			})
			data = encodeWav(samples, SAMPLE_RATE, 1)
		}
		offer(data, name, format.value === 'wav' ? 'audio/wav' : 'audio/midi')
		exportStatus.value = t('cantoria', 'The practice file {name} is ready.', { name })
	} catch (error) {
		if (error instanceof RenderCancelled || signal.aborted) {
			exportStatus.value = t('cantoria', 'Cancelled. No file was made.')
		} else {
			exportStatus.value = ''
			exportError.value = error instanceof RangeError
				? t('cantoria', 'The file is too large for this browser. Please try MIDI instead of WAV.')
				: (error instanceof Error && error.message !== '' ? error.message : t('cantoria', 'The file could not be made in this browser.'))
		}
	} finally {
		exporting.value = false
		exportController = null
	}
}

function cancelExport(): void {
	exportController?.abort()
}

// ---- keyboard ---------------------------------------------------------------

const INTERACTIVE = 'input, textarea, select, button, a, summary, [role="button"], [role="combobox"], [role="option"], [role="radio"], [role="checkbox"], [role="switch"], [contenteditable="true"]'
/** Controls that use Page Down and Page Up themselves */
const TEXT_INPUT = 'input, textarea, select, [contenteditable="true"], [role="combobox"], [role="listbox"]'

/**
 * Space plays and pauses unless a control has the focus. While the score
 * has the focus, + and − zoom and the arrow keys left and right go to the
 * previous or next measure. Page Down and Page Up turn by one system.
 *
 * @param event - key event inside the view
 */
function onKeydown(event: KeyboardEvent): void {
	const target = event.target instanceof HTMLElement ? event.target : null
	const plain = !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
	// Page-turn pedals send Page Down and Page Up: one system on or back
	// (milestone 0.13), also after a click on Play; fields and sliders keep the keys
	if (plain && (event.key === 'PageDown' || event.key === 'PageUp') && scoreElement.value && !target?.closest(TEXT_INPUT)) {
		if (turnSystem(scoreElement.value, barElement.value, event.key === 'PageDown' ? 1 : -1)) {
			event.preventDefault()
		}
		return
	}
	if (target?.closest(INTERACTIVE)) {
		return
	}
	const inScore = target === scoreElement.value
	if (event.key === ' ' && props.canPlay && status.value === 'ready') {
		event.preventDefault()
		togglePlay()
	} else if (inScore && (event.key === '+' || event.key === '-')) {
		event.preventDefault()
		zoomBy(event.key === '+' ? 1 : -1)
	} else if (inScore && plain && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && props.canPlay && status.value === 'ready') {
		event.preventDefault()
		stepMeasure(event.key === 'ArrowLeft' ? -1 : 1)
	}
}

// ---- lifecycle --------------------------------------------------------------

onMounted(load)

watch(scoreElement, (element) => {
	resizeObserver?.disconnect()
	resizeObserver = null
	if (element && typeof ResizeObserver !== 'undefined') {
		resizeObserver = new ResizeObserver(onResize)
		resizeObserver.observe(element)
	}
})

onBeforeUnmount(() => {
	disposed = true
	resizeObserver?.disconnect()
	if (resizeTimer !== null) {
		clearTimeout(resizeTimer)
	}
	exportController?.abort()
	clearDownload()
	player?.destroy()
	player = null
	toolkit?.destroy()
	toolkit = null
})
</script>

<template>
	<!-- Key handling for the whole view: Space plays/pauses (see onKeydown) -->
	<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -->
	<div class="excerpt-viewer" @keydown="onKeydown">
		<p class="excerpt-viewer__reference">
			<span class="excerpt-viewer__label">{{ t('cantoria', 'Text reference') }}:</span>
			{{ reference }}
		</p>

		<NcEmptyContent v-if="status === 'loading'" :name="t('cantoria', 'Engraving the score …')">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="status === 'error'"
			:name="t('cantoria', 'The score cannot be shown')"
			:description="errorText">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
			<template #action>
				<NcButton @click="load">
					{{ t('cantoria', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- The bar stays at the top while the score scrolls past (position: sticky) -->
			<div ref="barElement" class="excerpt-viewer__bar">
				<div v-if="canPlay"
					class="excerpt-viewer__buttons"
					role="group"
					:aria-label="t('cantoria', 'Playback')">
					<NcButton variant="primary" @click="togglePlay">
						<template #icon>
							<NcIconSvgWrapper :path="playing ? mdiPause : mdiPlay" />
						</template>
						{{ playing ? t('cantoria', 'Pause') : t('cantoria', 'Play') }}
					</NcButton>
					<NcButton :disabled="!playing && position === 0" @click="stop">
						<template #icon>
							<NcIconSvgWrapper :path="mdiStop" />
						</template>
						{{ t('cantoria', 'Stop') }}
					</NcButton>
				</div>
				<div class="excerpt-viewer__buttons" role="group" :aria-label="t('cantoria', 'Size of the score')">
					<NcButton variant="tertiary"
						:aria-label="t('cantoria', 'Smaller')"
						:disabled="zoomIndex === 0"
						@click="zoomBy(-1)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMagnifyMinusOutline" />
						</template>
					</NcButton>
					<span class="excerpt-viewer__zoom-value">{{ zoomPercent }} %</span>
					<NcButton variant="tertiary"
						:aria-label="t('cantoria', 'Larger')"
						:disabled="zoomIndex === ZOOM_STEPS.length - 1"
						@click="zoomBy(1)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMagnifyPlusOutline" />
						</template>
					</NcButton>
				</div>
				<NcButton variant="tertiary"
					:aria-expanded="settingsShown ? 'true' : 'false'"
					:aria-controls="settingsId"
					@click="settingsShown = !settingsShown">
					<template #icon>
						<NcIconSvgWrapper :path="settingsShown ? mdiChevronUp : mdiTuneVariant" />
					</template>
					<span class="excerpt-viewer__settings-label">{{ t('cantoria', 'Voice, measures and practising') }}</span>
				</NcButton>
				<!-- e.g. "Next: …" of the concert folder, in reach while the score scrolls -->
				<slot name="bar" />
				<!-- A running recording stays in view while its tool is folded; Stop ends it -->
				<span v-if="recorderState === 'recording'" class="excerpt-viewer__recording">
					<NcIconSvgWrapper :path="mdiRecordCircleOutline" :size="20" aria-hidden="true" />
					{{ t('cantoria', 'Recording …') }}
				</span>
				<!-- The lyrics stay in view with the bar while the score scrolls -->
				<PracticeLyrics v-if="canPlay && lyricsVoice"
					v-model:shown="lyricsShown"
					v-model:step="lyricsStep"
					part="text"
					:syllables="syllables"
					:position="position"
					:voice-label="labelOf(lyricsVoice)" />
				<PracticePitch v-if="canPlay"
					v-model:shown="pitchShown"
					v-model:problem="pitchProblem"
					part="display"
					:notes="pitchNotes"
					:position="position"
					:playing="playing"
					:voice-label="focusVoice ? labelOf(focusVoice) : null"
					:fifths="soundFifths"
					@announce="announce" />
			</div>
			<NcNoteCard v-if="!canPlay" type="info" class="excerpt-viewer__note">
				{{ playReason || t('cantoria', 'Playback is not available for this excerpt.') }}
			</NcNoteCard>

			<div v-show="settingsShown" :id="settingsId" class="excerpt-viewer__controls">
				<PlaybackSettings v-model:focus="focusOption"
					v-model:mode="mode"
					v-model:range-from="rangeFrom"
					v-model:range-to="rangeTo"
					v-model:loop="loop"
					v-model:tempo="tempoPercent"
					v-model:count-in="countInChoice"
					v-model:repeats="repeats"
					v-model:mix="mix"
					v-model:transpose="transpose"
					v-model:metronome="metronome"
					v-model:lyrics-shown="lyricsShown"
					v-model:lyrics-step="lyricsStep"
					v-model:pitch-shown="pitchShown"
					v-model:pitch-problem="pitchProblem"
					v-model:recorder="recorderState"
					:voice-options="voiceOptions"
					:can-play="canPlay"
					:range-active="range !== null"
					:range-error="rangeError"
					:range-hint="rangeHint"
					:tempo-label="tempoText"
					:tempo-min="TEMPO_MIN"
					:tempo-max="TEMPO_MAX"
					:tempo-hint="t('cantoria', 'Percent of the tempo in the score.')"
					:mixer-voices="mixerVoices"
					:playing="playing"
					:lyrics-voice-label="lyricsVoice ? labelOf(lyricsVoice) : null"
					:syllables="syllables"
					:position="position"
					:pitch-voice-label="focusVoice ? labelOf(focusVoice) : null"
					:pitch-notes="pitchNotes"
					:fifths="soundFifths"
					:marks-key="marksKey"
					:shown-from="shownNumbers.first"
					:shown-to="shownNumbers.last"
					:title="title"
					:playback-position="playbackPosition"
					:start-playback="playFrom"
					:pause-playback="pausePlayback"
					@apply-range="applyRange"
					@show-all="showAllMeasures"
					@tones="giveStartingTones"
					@apply-mark="applyMark"
					@announce="announce" />
			</div>
			<p v-if="!settingsShown && rangeError" class="excerpt-viewer__error" role="alert">
				{{ rangeError }}
			</p>

			<p :id="summaryId" class="excerpt-viewer__summary">
				{{ summary }}
				<template v-if="canPlay">
					· {{ t('cantoria', 'Tap or click a note to play from there.') }}
				</template>
			</p>
			<p v-if="canPlay" :id="scoreHintId" class="hidden-visually">
				{{ t('cantoria', 'Space plays and pauses. The arrow keys left and right go to the previous or next measure.') }}
			</p>
			<!-- Scrollable score; focusable so it can be scrolled with the keyboard.
			     A click on a note plays from there; the keyboard alternative are the
			     arrow keys in the score (onKeydown on the whole view) -->
			<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions, vuejs-accessibility/click-events-have-key-events -->
			<div ref="scoreElement"
				class="excerpt-viewer__score"
				tabindex="0"
				role="region"
				:aria-label="t('cantoria', 'Score')"
				:aria-describedby="canPlay ? `${summaryId} ${scoreHintId}` : summaryId"
				@click="onScoreClick">
				<div ref="pagesElement"
					class="excerpt-viewer__pages"
					:class="{ 'excerpt-viewer__pages--playable': canPlay }"
					role="img"
					:aria-label="description">
					<!-- SVG rendered by Verovio in the browser from the MusicXML and cleaned by sanitizeSvg() -->
					<!-- eslint-disable vue/no-v-html -->
					<div v-for="(svg, index) in pages"
						:key="index"
						class="excerpt-viewer__page"
						v-html="svg" />
					<!-- eslint-enable vue/no-v-html -->
				</div>
			</div>
			<details class="excerpt-viewer__description">
				<summary>{{ whole ? t('cantoria', 'Description of the piece') : t('cantoria', 'Description of the excerpt') }}</summary>
				<p>{{ description }}</p>
			</details>

			<section v-if="canExport" class="excerpt-viewer__export" :aria-label="t('cantoria', 'Practice file')">
				<h3 class="excerpt-viewer__export-title">
					{{ t('cantoria', 'Practice file') }}
				</h3>
				<p class="excerpt-viewer__hint">
					{{ t('cantoria', 'A practice file of the shown measures ({measures}), made in your browser from the playback: {choice}. The voice and how it sounds are set under "Voice, measures and practising". Every practice file is recorded in the audit log of the music archive.', { measures: rangeText, choice: exportChoice }) }}
				</p>
				<div class="excerpt-viewer__buttons">
					<NcRadioGroup v-model="format" :label="t('cantoria', 'Format')">
						<NcRadioGroupButton value="wav" :label="t('cantoria', 'WAV (audio)')" />
						<NcRadioGroupButton value="midi" :label="t('cantoria', 'MIDI')" />
					</NcRadioGroup>
					<NcButton :disabled="exporting" :aria-describedby="exportStatusId" @click="makePracticeFile">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<NcIconSvgWrapper v-else :path="mdiDownload" />
						</template>
						{{ t('cantoria', 'Make practice file') }}
					</NcButton>
					<NcButton v-if="exporting" variant="tertiary" @click="cancelExport">
						{{ t('cantoria', 'Cancel') }}
					</NcButton>
				</div>
				<p :id="exportStatusId" class="excerpt-viewer__hint" role="status">
					{{ exportStatus }}
					<a v-if="download && !exporting" :href="download.url" :download="download.name">{{ t('cantoria', 'Download again') }}</a>
				</p>
				<NcNoteCard v-if="exportError" type="error" role="alert">
					{{ exportError }}
				</NcNoteCard>
			</section>
			<p v-else-if="offline && exportAudio !== null && canPlay" class="excerpt-viewer__hint">
				{{ t('cantoria', 'Practice files need a network: the server checks the rights and records each file first. Playing and practising work without one.') }}
			</p>

			<p class="hidden-visually" role="status">
				{{ announcement }}
			</p>
		</template>
	</div>
</template>

<style scoped>
.excerpt-viewer {
	/* as in the score view of the archive (ScoreViewer.vue) */
	--score-focus: color-mix(in srgb, var(--color-info-text, #00679e) 75%, var(--color-main-text, #222));
	--score-muted: var(--color-text-maxcontrast, #6b6b6b);
	--score-playing: var(--color-error-text, #c9302c);
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.excerpt-viewer p {
	margin: 0;
}

.excerpt-viewer__reference {
	overflow-wrap: anywhere;
}

.excerpt-viewer__label {
	font-weight: bold;
}

/* Play, size and settings: stays at the top of the scrolling page (or
   box) while the score scrolls past, so that pausing is always at hand */
.excerpt-viewer__bar {
	position: sticky;
	z-index: 1;
	top: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.excerpt-viewer__controls {
	padding-bottom: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.excerpt-viewer__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.excerpt-viewer__recording {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.excerpt-viewer__note {
	flex: 1 1 20em;
}

.excerpt-viewer__score:focus-visible,
.excerpt-viewer__description summary:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.excerpt-viewer__hint,
.excerpt-viewer__summary {
	color: var(--color-text-maxcontrast);
}

.excerpt-viewer__error {
	color: var(--color-error-text, var(--color-error));
}

.excerpt-viewer__zoom-value {
	min-width: 3em;
	align-self: center;
	text-align: center;
}

/* As tall as the score: a swipe on it moves the page, no box inside the
   page catches the finger. A large screen: a box of its own, see below */
.excerpt-viewer__score {
	box-sizing: border-box;
	min-width: 0;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.excerpt-viewer__description summary {
	/* a target of at least 24 pixels (WCAG 2.5.8) */
	padding-block: var(--default-grid-baseline);
	cursor: pointer;
	font-weight: bold;
}

.excerpt-viewer__description p {
	max-width: 80ch;
	margin-top: var(--default-grid-baseline);
}

.excerpt-viewer__export {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-top: calc(2 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.excerpt-viewer__export-title {
	margin: 0;
	font-size: 1.1em;
}

.excerpt-viewer__page :deep(svg) {
	display: block;
	max-width: none;
	margin: 0 auto;
}

/* Ink follows the theme (Verovio writes color="black") */
.excerpt-viewer__page :deep(svg.definition-scale) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* Focus voice: color plus bolder note heads and stems, the other voices
   lighter, so that it does not stand out by its hue alone (WCAG 1.4.1) */
.excerpt-viewer__page :deep(g.layer.score-focus) {
	color: var(--score-focus);
	fill: var(--score-focus);
}

.excerpt-viewer__page :deep(g.layer.score-focus g.notehead) {
	stroke: currentColor;
	stroke-width: 36px;
}

.excerpt-viewer__page :deep(g.layer.score-focus g.stem path) {
	stroke-width: 30px;
}

.excerpt-viewer__page :deep(.score-muted) {
	color: var(--score-muted);
	fill: var(--score-muted);
}

.excerpt-viewer__page :deep(.score-muted .score-shared) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* A click on a note plays from there */
.excerpt-viewer__pages--playable :deep(g.note) {
	cursor: pointer;
}

/* Sounding notes: color, outline and larger note heads */
.excerpt-viewer__page :deep(g.note.score-playing) {
	color: var(--score-playing);
	fill: var(--score-playing);
}

.excerpt-viewer__page :deep(g.note.score-playing g.notehead) {
	stroke: currentColor;
	stroke-width: 50px;
	transform: scale(1.3);
	transform-box: fill-box;
	transform-origin: center;
}

/* Windows contrast themes: system colours for the score (see ScoreViewer.vue) */
@media (forced-colors: active) {
	.excerpt-viewer__page :deep(svg.definition-scale),
	.excerpt-viewer__page :deep(.score-muted .score-shared) {
		color: CanvasText;
		fill: CanvasText;
	}

	.excerpt-viewer__page :deep(g.layer.score-focus) {
		color: Highlight;
		fill: Highlight;
	}

	.excerpt-viewer__page :deep(.score-muted) {
		color: GrayText;
		fill: GrayText;
	}

	.excerpt-viewer__page :deep(g.note.score-playing) {
		color: LinkText;
		fill: LinkText;
	}
}

/* Printing: the score only, black on white, the focus voice bold */
@media print {
	.excerpt-viewer > :not(.excerpt-viewer__score):not(.excerpt-viewer__reference) {
		display: none !important;
	}

	.excerpt-viewer__score {
		height: auto !important;
		overflow: visible !important;
		border: none;
	}

	.excerpt-viewer__page {
		break-inside: avoid;
	}

	.excerpt-viewer__page :deep(svg.definition-scale),
	.excerpt-viewer__page :deep(g.layer.score-focus),
	.excerpt-viewer__page :deep(.score-muted),
	.excerpt-viewer__page :deep(g.note.score-playing) {
		color: #000;
		fill: #000;
	}

	.excerpt-viewer__page :deep(g.note.score-playing g.notehead) {
		stroke: none;
		transform: none;
	}
}

@media (min-width: 1024px) and (min-height: 640px) {
	.excerpt-viewer__score {
		height: max(16em, min(65vh, 40em));
	}
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.excerpt-viewer {
		--default-clickable-area: 44px;
	}
}

/* The narrowest phones: the bar in two lines, "Settings" named by its icon
   (the word stays for screen readers) */
@media (max-width: 359px) {
	.excerpt-viewer__settings-label {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
}

/* A low screen, e.g. a phone held sideways: the bar never takes more
   than part of the height, so that the score stays in view */
@media (max-height: 560px) {
	.excerpt-viewer__bar {
		max-height: 45vh;
		overflow-y: auto;
	}
}
</style>
