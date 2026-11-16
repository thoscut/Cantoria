/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The playback of a score, shared by the score view of the archive
 * (ScoreViewer) and the shared pages (ExcerptViewer): the player, play,
 * pause and stop with the count-in, a click or a tap into the score, the
 * arrow keys, the voices, the tempo and the tools for practising (milestone
 * 0.19, A: one rule instead of two copies). The view keeps what is its own:
 * following the playback in the score (onPosition, resetHighlights), the
 * messages for screen readers (announce) and where its settings are
 * remembered.
 */
import type { ComputedRef, Ref, ShallowRef } from 'vue'
import type { Click, CountInBars } from './countIn.ts'
import type { MeasureRange } from './range.ts'
import type { ScoreModel } from './model.ts'
import type { PlayMode, ScorePlayer as ScorePlayerType } from './player.ts'
import type { Mix } from './practising.ts'
import type { Timeline } from './timeline.ts'
import type { Voice } from './voices.ts'

import { computed, ref, shallowRef, watch } from 'vue'
import { t } from '../l10n.ts'
import { startingTonesText } from '../practiceLabels.ts'
import { countInFor, metronomeClicks, slotAt, storeCountIn, storedCountIn } from './countIn.ts'
import { scoreTarget } from './hit.ts'
import { playbackTimeline } from './model.ts'
import { ScorePlayer, voiceGains } from './player.ts'
import { mixedGains, startingTones, transposedFifths, transposeTimeline } from './practising.ts'
import { timbre } from './sungVoice.ts'
import { measureStarts, nextPass, noteStarts } from './timeline.ts'
import { eventVowels } from './vowels.ts'

export interface PlaybackOptions {
	/** the loaded score */
	model: ShallowRef<ScoreModel | null> | Ref<ScoreModel | null>
	/** the shown measures, null for the whole piece */
	range: ShallowRef<MeasureRange | null> | Ref<MeasureRange | null>
	/** key of the highlighted voice, '' for none */
	focusKey: Ref<string>
	focusVoice: ComputedRef<Voice | null>
	/** the element with the pages of the score, for clicks into it */
	pages: Ref<HTMLElement | null>
	/** whether the piece may be played and the score is ready */
	canPlay: () => boolean
	/** the name of a voice in messages */
	labelOf: (voice: Voice) => string
	/** the printed number of the current measure, for messages */
	measureNumber: () => string
	/** a message for screen readers */
	announce: (text: string) => void
	/** the playback moved: follow it in the score */
	onPosition: (time: number) => void
	/** the playback starts anew: no measure or note is marked */
	resetHighlights: () => void
	/** the highlighted voice changed: mark it in the score */
	applyFocus: () => void
	/** the playback started or moved; the view waits before it announces the next measure */
	onStarted: () => void
	/** the volume in per cent, where the view offers one */
	volume?: Ref<number>
	/** settings remembered for this piece */
	initial?: { tempo?: number, mix?: Mix, transpose?: number, metronome?: boolean }
}

/**
 * The playback of a score in a view.
 *
 * @param options - what the playback needs of the view
 */
export function usePlayback(options: PlaybackOptions) {
	const { model, range, focusKey, focusVoice } = options
	let player: ScorePlayerType | null = null

	const playing = ref(false)
	const mode = ref<PlayMode>('all')
	const repeats = ref(true)
	/** Play the shown measures again and again */
	const loop = ref(false)
	/** Bars of count-in, remembered in this browser */
	const countInBars = ref<CountInBars>(storedCountIn())
	const countInChoice = computed<'0' | '1' | '2'>({
		get: () => String(countInBars.value) as '0' | '1' | '2',
		set: (value) => {
			countInBars.value = Number(value) as CountInBars
		},
	})
	const tempoPercent = ref(options.initial?.tempo ?? 100)
	/** Tools for practising (milestone 0.13): level per voice, semitones, metronome */
	const mix = ref<Mix>(options.initial?.mix ?? {})
	const transpose = ref(options.initial?.transpose ?? 0)
	const metronome = ref(options.initial?.metronome ?? false)
	const position = ref(0)
	const timeline = shallowRef<Timeline>({ events: [], measures: [], duration: 0 })
	/** The vowel of each note, for the sung practice voice */
	const vowels = computed(() => model.value ? eventVowels(model.value, timeline.value) : undefined)
	/** Key signature of the sound, after transposing */
	const soundFifths = computed(() => {
		const fifths = model.value?.signatures.fifths ?? null
		return fifths === null ? null : transposedFifths(fifths, transpose.value)
	})

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

	function isPlaying(): boolean {
		return player?.isPlaying ?? false
	}

	/** Pause without a message: at the end of a recording, before a dialog or an export */
	function pausePlayback(): void {
		if (player?.isPlaying) {
			player.pause()
			playing.value = false
		}
	}

	/** The first tone of each voice that sounds, from the current place */
	async function giveStartingTones(): Promise<void> {
		const loaded = model.value
		if (!loaded || playing.value) {
			return
		}
		const voices = mode.value === 'solo' && focusVoice.value
			? [focusVoice.value]
			: loaded.voices.filter((voice) => (mix.value[voice.key] ?? 100) > 0)
		const tones = startingTones(timeline.value, voices, player?.currentTime() ?? 0)
		options.announce(startingTonesText(tones, options.labelOf, soundFifths.value))
		await ensurePlayer().playTones(tones.map((tone) => tone.pitch))
	}

	function ensurePlayer(): ScorePlayerType {
		if (!player) {
			player = new ScorePlayer({
				onPosition: options.onPosition,
				onEnd: () => {
					playing.value = false
					position.value = 0
					options.resetHighlights()
					options.announce(t('cantoria', 'End of playback.'))
				},
			})
			player.setTimeline(timeline.value)
			player.setRate(tempoPercent.value / 100)
			if (options.volume) {
				player.setVolume(options.volume.value / 100)
			}
			player.setGains(gains())
			player.setMetronome(beats())
			player.setLoop(loop.value)
			player.setTimbre(timbre.value, vowels.value)
		}
		return player
	}

	/** Play or pause, with a message */
	async function togglePlay(): Promise<void> {
		if (!options.canPlay()) {
			return
		}
		const current = ensurePlayer()
		if (current.isPlaying) {
			current.pause()
			playing.value = false
			options.announce(t('cantoria', 'Paused at measure {number}.', { number: options.measureNumber() }))
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
		options.resetHighlights()
		const loaded = model.value
		const bars = countInBars.value
		await current.play(loaded && bars > 0 ? (time) => countInFor(loaded, timeline.value, time, bars) : undefined)
		playing.value = current.isPlaying
		if (playing.value) {
			// The start message comes first; measures are announced 3 s later at the earliest
			options.onStarted()
			options.onPosition(current.currentTime())
			const number = options.measureNumber()
			options.announce(bars > 0
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
		options.resetHighlights()
		options.onPosition(time)
		options.onStarted()
		const number = options.measureNumber()
		options.announce(current.isPlaying
			? t('cantoria', 'Playing from measure {number}.', { number })
			: t('cantoria', 'Start at measure {number}.', { number }))
	}

	/**
	 * Play from a place, with the count-in: a clicked note or measure, a
	 * point of the review, the start of a recording.
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

	/**
	 * A click or a tap into the score: on a note or a measure (see
	 * scoreTarget), the playback starts there (at its next pass from the
	 * current position). One handler on the container; the SVG itself
	 * carries no handlers.
	 *
	 * @param event - click event
	 */
	function onScoreClick(event: MouseEvent): void {
		const target = scoreTarget(event, options.pages.value)
		const loaded = model.value
		if (!target || !loaded || !options.canPlay()) {
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
		if (time !== null) {
			playFrom(time)
		}
	}

	/**
	 * The keyboard alternative to clicking a note: arrow left and right in
	 * the score go to the previous or next measure in playing order.
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
		options.resetHighlights()
		if (wasActive) {
			options.announce(t('cantoria', 'Stopped.'))
		}
	}

	/**
	 * Loop on or off; the view applies the measures first.
	 *
	 * @param on - whether the shown measures play again and again
	 */
	function setLoop(on: boolean): void {
		player?.setLoop(on)
	}

	/** When the view closes */
	function destroy(): void {
		player?.destroy()
		player = null
	}

	watch([focusKey, mode], () => {
		options.applyFocus()
		player?.setGains(gains())
	})
	watch(mix, () => player?.setGains(gains()))
	watch(transpose, () => updateTimeline())
	watch(metronome, () => player?.setMetronome(beats()))
	watch(tempoPercent, (value) => player?.setRate(value / 100))
	if (options.volume) {
		watch(options.volume, (value) => player?.setVolume(value / 100))
	}
	watch(repeats, () => updateTimeline())
	watch(countInBars, (bars) => storeCountIn(bars))
	watch([timbre, vowels], () => player?.setTimbre(timbre.value, vowels.value))

	return {
		playing,
		mode,
		repeats,
		loop,
		countInBars,
		countInChoice,
		tempoPercent,
		mix,
		transpose,
		metronome,
		position,
		timeline,
		vowels,
		soundFifths,
		updateTimeline,
		playbackPosition,
		isPlaying,
		pausePlayback,
		giveStartingTones,
		togglePlay,
		moveTo,
		playFrom,
		onScoreClick,
		stepMeasure,
		stop,
		setLoop,
		destroy,
	}
}
