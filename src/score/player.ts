/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Plays a timeline with the synthesizer: look-ahead scheduling on the
 * AudioContext clock, pause/resume, tempo and voice balance changes while
 * playing, a count-in, a metronome, a loop over the whole timeline, jumps
 * to another place and the starting tones of the voices. Browser only (Web
 * Audio).
 */
import type { Click, CountIn } from './countIn.ts'
import type { PlayEvent, Timeline } from './timeline.ts'
import type { VoiceMember } from './voices.ts'
import type { Timbre, Vowel } from './synth.ts'

import { toneTimes } from './practising.ts'
import { Synth } from './synth.ts'
import { memberKey } from './voices.ts'

export type PlayMode = 'all' | 'focus' | 'solo'

export interface PlayerCallbacks {
	/** current position in seconds of the timeline (at 100 % tempo), every frame */
	onPosition: (time: number) => void
	/** the end of the timeline was reached */
	onEnd: () => void
}

const LOOK_AHEAD = 0.25
const START_DELAY = 0.06
/** A loop shorter than this (seconds) is not repeated */
const MIN_LOOP = 0.2

/**
 * Loudness of a voice in a playback mode.
 *
 * @param mode - playback mode
 * @param inFocus - whether the voice is the focus voice
 * @param hasFocus - whether a focus voice is chosen at all
 */
export function voiceGain(mode: PlayMode, inFocus: boolean, hasFocus: boolean): number {
	if (!hasFocus || mode === 'all') {
		return 0.55
	}
	if (mode === 'solo') {
		return inFocus ? 0.8 : 0
	}
	return inFocus ? 0.9 : 0.16
}

/**
 * Loudness of every voice member key (staff.layer) for a focus voice and a
 * playback mode, as in the score view.
 *
 * @param members - staff/layer pairs of the focus voice; empty for none
 * @param mode - playback mode
 */
export function voiceGains(members: VoiceMember[], mode: PlayMode): (voice: string) => number {
	const keys = new Set(members.map((member) => memberKey(member.staff, member.layer)))
	const hasFocus = keys.size > 0
	return (voice: string) => voiceGain(mode, keys.has(voice), hasFocus)
}

/**
 * Sum of the gains of notes sounding at once that the mix takes at full
 * level: four voices at full balance (4 × 0.55) and a little more.
 */
export const MIX_HEADROOM = 2.4

/**
 * Factor for the gain of every note, so that dense scores keep their
 * headroom: 1 while the largest sum of the gains of notes sounding at once
 * stays within MIX_HEADROOM, otherwise that sum scaled down to it. One factor
 * for the whole timeline: no pumping, the balance of the voices stays.
 *
 * @param events - playback events
 * @param gainOf - gain 0 … 1 per voice member key
 */
export function mixLevel(events: PlayEvent[], gainOf: (voice: string) => number): number {
	const edges: [number, number][] = []
	for (const event of events) {
		const gain = event.sound ? gainOf(event.voice) : 0
		if (gain > 0 && event.soundEnd > event.start) {
			// whole milliseconds: a note that ends where the next starts does not overlap it
			edges.push([Math.round(event.start * 1000), gain], [Math.round(event.soundEnd * 1000), -gain])
		}
	}
	// at the same time a note ends before the next one starts
	edges.sort((a, b) => a[0] - b[0] || a[1] - b[1])
	let sum = 0
	let peak = 0
	for (const [, change] of edges) {
		sum += change
		peak = Math.max(peak, sum)
	}
	return peak > MIX_HEADROOM ? MIX_HEADROOM / peak : 1
}

const mixLevels = new WeakMap<PlayEvent[], { gainOf: (voice: string) => number, level: number }>()

/**
 * mixLevel, computed once per timeline and balance.
 *
 * @param events - playback events
 * @param gainOf - gain 0 … 1 per voice member key
 */
function cachedMixLevel(events: PlayEvent[], gainOf: (voice: string) => number): number {
	const cached = mixLevels.get(events)
	if (cached?.gainOf === gainOf) {
		return cached.level
	}
	const level = mixLevel(events, gainOf)
	mixLevels.set(events, { gainOf, level })
	return level
}

/** Whatever plays notes: the synthesizer, or a recorder in tests. */
export interface NoteTarget {
	note(pitch: number, start: number, end: number, gain: number, vowel?: Vowel | null): void
}

/** The vowel to sing for an event in the sung timbre, or null for the neutral one */
export type VowelOf = (event: PlayEvent) => Vowel | null

/** Whatever plays the clicks of a count-in. */
export interface ClickTarget {
	click(time: number, accent: boolean): void
}

/**
 * Hand the clicks of a count-in to the synthesizer.
 *
 * @param target - synthesizer
 * @param countIn - the count-in
 * @param startAudio - context time at which the music starts
 * @param rate - tempo factor
 */
export function scheduleClicks(target: ClickTarget, countIn: CountIn, startAudio: number, rate: number): void {
	for (const click of countIn.clicks) {
		target.click(startAudio + click.time / rate, click.accent)
	}
}

/** Where the timeline lies on the clock of an audio context. */
export interface Clock {
	/** context time at which `startScore` sounds */
	startAudio: number
	/** position in the timeline (seconds at 100 % tempo) */
	startScore: number
	/** tempo factor */
	rate: number
}

/**
 * Hand the clicks of the metronome that start up to a horizon to the
 * synthesizer, like scheduleEvents for the notes.
 *
 * @param target - synthesizer
 * @param clicks - clicks sorted by time, in seconds of the timeline
 * @param next - index of the first click not yet scheduled
 * @param clock - where the timeline lies on the context's clock
 * @param horizon - schedule clicks up to this context time
 * @param now - current context time; clicks before it are left out
 * @return the index of the first click not yet scheduled
 */
export function scheduleBeats(target: ClickTarget, clicks: Click[], next: number, clock: Clock, horizon: number, now: number): number {
	let index = next
	while (index < clicks.length) {
		const at = clock.startAudio + (clicks[index].time - clock.startScore) / clock.rate
		if (at > horizon) {
			break
		}
		if (at >= now - 0.005) {
			target.click(Math.max(at, now), clicks[index].accent)
		}
		index++
	}
	return index
}

/**
 * Hand the events that start up to a horizon to the synthesizer, as the
 * playback and the practice audio files both do: tied continuations are
 * not struck again, voices with gain 0 stay silent, notes that are already
 * over (a late timer) are left out, and dense scores are turned down
 * (mixLevel).
 *
 * @param target - synthesizer
 * @param events - events sorted by start
 * @param next - index of the first event not yet scheduled
 * @param clock - where the timeline lies on the context's clock
 * @param horizon - schedule events that start up to this context time
 * @param now - current context time; nothing starts earlier
 * @param gainOf - gain 0 … 1 per voice member key
 * @param vowelOf - the vowel of an event for the sung timbre
 * @return the index of the first event not yet scheduled
 */
export function scheduleEvents(target: NoteTarget, events: PlayEvent[], next: number, clock: Clock, horizon: number, now: number, gainOf: (voice: string) => number, vowelOf?: VowelOf): number {
	let index = next
	const level = cachedMixLevel(events, gainOf)
	while (index < events.length) {
		const event = events[index]
		const at = clock.startAudio + (event.start - clock.startScore) / clock.rate
		if (at > horizon) {
			break
		}
		index++
		if (!event.sound) {
			continue
		}
		const gain = gainOf(event.voice)
		if (gain <= 0) {
			continue
		}
		const end = clock.startAudio + (event.soundEnd - clock.startScore) / clock.rate
		if (end <= now) {
			continue
		}
		target.note(event.pitch, Math.max(at, now), end, gain * level, vowelOf?.(event) ?? null)
	}
	return index
}

/**
 * Index of the first event that starts at or after a time.
 *
 * @param events - events sorted by start
 * @param time - seconds
 */
export function firstEventAt(events: PlayEvent[], time: number): number {
	let low = 0
	let high = events.length
	while (low < high) {
		const middle = (low + high) >> 1
		if (events[middle].start < time - 1e-6) {
			low = middle + 1
		} else {
			high = middle
		}
	}
	return low
}

/**
 * Index of the first click at or after a time.
 *
 * @param clicks - clicks sorted by time
 * @param time - seconds
 */
function firstClickAt(clicks: Click[], time: number): number {
	const index = clicks.findIndex((click) => click.time >= time - 1e-6)
	return index === -1 ? clicks.length : index
}

export class ScorePlayer {

	private synth: Synth | null = null
	private timeline: Timeline = { events: [], measures: [], duration: 0 }
	private rate = 1
	private volume = 0.7
	private gainOf: (voice: string) => number = () => 0.55
	private timbre: Timbre = 'organ'
	private vowelOf: VowelOf | undefined = undefined
	private playing = false
	private loop = false
	private startAudio = 0
	private startScore = 0
	/** the clock of the pass before a loop started again, while it still sounds */
	private previous: Clock | null = null
	private position = 0
	private next = 0
	/** clicks of the metronome; empty: off */
	private beats: Click[] = []
	private nextBeat = 0
	private timer: ReturnType<typeof setInterval> | null = null
	private frame = 0
	private readonly callbacks: PlayerCallbacks

	constructor(callbacks: PlayerCallbacks) {
		this.callbacks = callbacks
	}

	get isPlaying(): boolean {
		return this.playing
	}

	get duration(): number {
		return this.timeline.duration
	}

	/** Current position in seconds of the timeline. */
	currentTime(): number {
		if (!this.playing || !this.synth) {
			return this.position
		}
		const now = this.synth.context.currentTime
		const clock = this.previous && now < this.startAudio ? this.previous : this.clock()
		return Math.max(clock.startScore, clock.startScore + (now - clock.startAudio) * this.rate)
	}

	/**
	 * Use a new timeline (range, repeats changed); stops playback.
	 *
	 * @param timeline - events to play
	 */
	setTimeline(timeline: Timeline): void {
		this.stop()
		this.timeline = timeline
	}

	/**
	 * Tempo as a factor of the score's tempo.
	 *
	 * @param rate - e.g. 0.8 for 80 %
	 */
	setRate(rate: number): void {
		this.restartAfter(() => {
			this.rate = Math.max(0.1, rate)
		})
	}

	/**
	 * Loudness per voice member key (staff.layer).
	 *
	 * @param gainOf - gain 0 … 1 for a voice
	 */
	setGains(gainOf: (voice: string) => number): void {
		this.restartAfter(() => {
			this.gainOf = gainOf
		})
	}

	/**
	 * Organ tone or sung voice, with the vowels of the lyrics.
	 *
	 * @param timbre - the timbre
	 * @param vowelOf - the vowel per event; without it the neutral vowel
	 */
	setTimbre(timbre: Timbre, vowelOf?: VowelOf): void {
		this.restartAfter(() => {
			this.timbre = timbre
			this.vowelOf = vowelOf
			this.synth?.setTimbre(timbre)
		})
	}

	/**
	 * The metronome: clicks on the beats while playing.
	 *
	 * @param clicks - clicks in seconds of the timeline (countIn.metronomeClicks); empty: off
	 */
	setMetronome(clicks: Click[]): void {
		this.restartAfter(() => {
			this.beats = clicks
		})
	}

	/**
	 * Play the starting tones of the voices, one after the other from the
	 * lowest and then together. Stops nothing: call it while paused.
	 *
	 * @param pitches - MIDI pitches, lowest first
	 * @return seconds until the chord ends
	 */
	async playTones(pitches: number[]): Promise<number> {
		if (this.playing || pitches.length === 0) {
			return 0
		}
		if (!this.synth) {
			this.synth = new Synth(this.volume)
			this.synth.setTimbre(this.timbre)
		}
		await this.synth.resume()
		this.synth.silence()
		const { starts, end } = toneTimes(pitches.length)
		const now = this.synth.context.currentTime + START_DELAY
		pitches.forEach((pitch, index) => {
			this.synth?.note(pitch, now + starts[index], now + end, 0.6)
		})
		return end
	}

	/**
	 * Overall volume.
	 *
	 * @param volume - 0 … 1
	 */
	setVolume(volume: number): void {
		this.volume = volume
		this.synth?.setVolume(volume)
	}

	/**
	 * Play the timeline again and again (the A–B loop: the timeline holds
	 * the chosen measures).
	 *
	 * @param loop - true: start again at the end
	 */
	setLoop(loop: boolean): void {
		this.loop = loop
	}

	/**
	 * Go to another place: while playing, the playback continues there.
	 *
	 * @param time - position in seconds of the timeline
	 */
	seek(time: number): void {
		const target = Math.max(0, Math.min(time, this.timeline.duration))
		if (!this.playing) {
			this.position = target
			return
		}
		this.synth?.silence()
		this.startFrom(target)
		this.schedule()
	}

	/**
	 * Start or resume.
	 *
	 * @param countIn - clicks before the music, for the position it starts at
	 */
	async play(countIn?: (start: number) => CountIn | null): Promise<void> {
		if (this.playing || this.timeline.events.length === 0) {
			return
		}
		if (!this.synth) {
			this.synth = new Synth(this.volume)
			this.synth.setTimbre(this.timbre)
		}
		await this.synth.resume()
		if (this.position >= this.timeline.duration) {
			this.position = 0
		}
		this.startFrom(this.position)
		const clicks = countIn?.(this.position) ?? null
		if (clicks) {
			this.startAudio += clicks.lead / this.rate
			scheduleClicks(this.synth, clicks, this.startAudio, this.rate)
		}
		this.playing = true
		this.timer = setInterval(() => this.schedule(), 25)
		this.schedule()
		const tick = () => {
			if (!this.playing) {
				return
			}
			this.callbacks.onPosition(this.currentTime())
			this.frame = requestAnimationFrame(tick)
		}
		this.frame = requestAnimationFrame(tick)
	}

	pause(): void {
		if (!this.playing) {
			return
		}
		this.position = Math.min(this.currentTime(), this.timeline.duration)
		this.halt()
	}

	stop(): void {
		this.halt()
		this.position = 0
	}

	async destroy(): Promise<void> {
		this.halt()
		const synth = this.synth
		this.synth = null
		await synth?.close()
	}

	private halt(): void {
		this.playing = false
		if (this.timer !== null) {
			clearInterval(this.timer)
			this.timer = null
		}
		cancelAnimationFrame(this.frame)
		this.synth?.silence()
	}

	private startFrom(time: number): void {
		const synth = this.synth
		if (!synth) {
			return
		}
		this.startScore = time
		this.startAudio = synth.context.currentTime + START_DELAY
		this.previous = null
		this.next = firstEventAt(this.timeline.events, time)
		this.nextBeat = firstClickAt(this.beats, time)
	}

	private clock(): Clock {
		return { startAudio: this.startAudio, startScore: this.startScore, rate: this.rate }
	}

	/**
	 * Apply a change while playing: silence and schedule again from now.
	 *
	 * @param change - the change to apply
	 */
	private restartAfter(change: () => void): void {
		if (!this.playing) {
			change()
			return
		}
		const time = this.currentTime()
		this.synth?.silence()
		change()
		this.startFrom(time)
		this.schedule()
	}

	private schedule(): void {
		const synth = this.synth
		if (!synth || !this.playing) {
			return
		}
		const now = synth.context.currentTime
		const horizon = now + LOOK_AHEAD
		const events = this.timeline.events
		this.next = scheduleEvents(synth, events, this.next, this.clock(), horizon, now, this.gainOf, this.vowelOf)
		this.nextBeat = scheduleBeats(synth, this.beats, this.nextBeat, this.clock(), horizon, now)
		if (this.next >= events.length && this.loop && this.timeline.duration >= MIN_LOOP) {
			// Start again seamlessly where the timeline ends
			const end = this.startAudio + (this.timeline.duration - this.startScore) / this.rate
			if (end <= horizon) {
				this.previous = this.clock()
				this.startAudio = end
				this.startScore = 0
				this.next = scheduleEvents(synth, events, 0, this.clock(), horizon, now, this.gainOf, this.vowelOf)
				this.nextBeat = scheduleBeats(synth, this.beats, 0, this.clock(), horizon, now)
			}
			return
		}
		if (this.next >= events.length && this.currentTime() >= this.timeline.duration) {
			this.halt()
			this.position = 0
			this.callbacks.onEnd()
		}
	}

}
