/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Rendering practice audio in the browser: the playback events go through
 * the same synthesizer and the same scheduling as the playback, but into an
 * OfflineAudioContext, which computes the sound faster than real time.
 * Browser only (Web Audio).
 */
import type { CountIn } from './countIn.ts'
import type { Timeline } from './timeline.ts'
import type { VowelOf } from './player.ts'
import type { Timbre } from './synth.ts'

import { scheduleClicks, scheduleEvents } from './player.ts'
import { renderedSeconds } from './practice.ts'
import { Synth } from './synth.ts'

/** Overall volume while rendering, as the default of the playback */
const EXPORT_VOLUME = 0.7

/** Peak level of a file (−1 dBFS): loud enough, and no sample clips */
const PEAK_TARGET = 0.89

/** Quiet files are raised by at most this factor (12 dB) */
const MAX_RAISE = 4

/** Seconds of audio between two progress reports */
const PROGRESS_STEP = 5

/** The user cancelled the rendering. */
export class RenderCancelled extends Error {

	constructor() {
		super('Cancelled')
		this.name = 'RenderCancelled'
	}

}

export interface RenderOptions {
	/** tempo factor (1 = as in the score) */
	rate: number
	/** samples per second */
	sampleRate: number
	signal?: AbortSignal
	/** share of the rendering done, 0 … 1 */
	onProgress?: (done: number) => void
	/** clicks before the music */
	countIn?: CountIn | null
	/** organ tone (default) or the sung voice */
	timbre?: Timbre
	/** the vowel per event for the sung voice */
	vowelOf?: VowelOf
}

/**
 * Render a timeline to mono samples.
 *
 * @param timeline - playback events
 * @param gainOf - gain per voice member key (player.voiceGains)
 * @param options - tempo, sample rate, cancel signal, progress
 */
export async function renderTimeline(timeline: Timeline, gainOf: (voice: string) => number, options: RenderOptions): Promise<Float32Array> {
	const { rate, sampleRate, signal, onProgress, countIn, timbre, vowelOf } = options
	if (signal?.aborted) {
		throw new RenderCancelled()
	}
	const lead = countIn?.lead ?? 0
	const seconds = renderedSeconds(timeline, rate, lead)
	const context = new OfflineAudioContext({ numberOfChannels: 1, length: Math.max(1, Math.ceil(seconds * sampleRate)), sampleRate })
	const synth = new Synth(EXPORT_VOLUME, context)
	synth.setTimbre(timbre ?? 'organ')
	// The whole timeline at once, with the same scheduling as the playback
	const startAudio = lead / rate
	if (countIn) {
		scheduleClicks(synth, countIn, startAudio, rate)
	}
	scheduleEvents(synth, timeline.events, 0, { startAudio, startScore: 0, rate }, Infinity, 0, gainOf, vowelOf)

	let cancelled = false
	const rendering = new Promise<AudioBuffer>((resolve, reject) => {
		const cancel = () => {
			cancelled = true
			reject(new RenderCancelled())
		}
		signal?.addEventListener('abort', cancel, { once: true })
		// Pauses for progress reports and for cancelling; browsers without
		// suspend() on offline contexts render in one go
		const pauseAt = (time: number) => {
			if (time >= seconds || typeof context.suspend !== 'function') {
				return
			}
			context.suspend(time).then(() => {
				if (cancelled) {
					// stays suspended and is dropped
					return
				}
				onProgress?.(time / seconds)
				pauseAt(time + PROGRESS_STEP)
				context.resume()
			}, () => {
				// no progress reports in this browser
			})
		}
		pauseAt(PROGRESS_STEP)
		context.startRendering().then((buffer) => {
			signal?.removeEventListener('abort', cancel)
			resolve(buffer)
		}, reject)
	})
	const buffer = await rendering
	onProgress?.(1)
	const samples = buffer.getChannelData(0)
	normalizePeak(samples, PEAK_TARGET, MAX_RAISE)
	return samples
}

/**
 * Scale samples so that the highest peak is at the target level: louder
 * files are turned down (no clipping), quieter ones up by at most a factor.
 * Silence stays silence.
 *
 * @param samples - samples, changed in place
 * @param target - peak level
 * @param maxRaise - largest factor for quiet files
 * @return the factor applied
 */
export function normalizePeak(samples: Float32Array, target: number, maxRaise: number): number {
	let peak = 0
	for (let i = 0; i < samples.length; i++) {
		const value = Math.abs(samples[i])
		if (value > peak) {
			peak = value
		}
	}
	if (peak === 0) {
		return 1
	}
	const factor = Math.min(maxRaise, target / peak)
	if (factor !== 1) {
		for (let i = 0; i < samples.length; i++) {
			samples[i] *= factor
		}
	}
	return factor
}
