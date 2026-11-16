// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The playback shared by the score view of the archive and the shared
 * pages (src/score/usePlayback.ts, milestone 0.19): one rule for play,
 * pause, stop, the count-in and the settings instead of two copies. The
 * player itself is replaced, the browser has no audio here.
 */
import type { ScoreModel } from '../score/model.ts'
import type { Voice } from '../score/voices.ts'

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, nextTick, ref, shallowRef } from 'vue'

class FakePlayer {

	static all: object[] = []

	isPlaying = false
	time = 0
	rate = 1
	volume = 0.7
	loop = false
	destroyed = false
	timelines = 0
	constructor(public callbacks: { onPosition: (time: number) => void, onEnd: () => void }) {
		FakePlayer.all.push(this)
	}

	setTimeline(): void {
		this.timelines++
	}

	setRate(rate: number): void {
		this.rate = rate
	}

	setVolume(volume: number): void {
		this.volume = volume
	}

	setGains(): void {}
	setMetronome(): void {}
	setTimbre(): void {}
	setLoop(on: boolean): void {
		this.loop = on
	}

	async play(): Promise<void> {
		this.isPlaying = true
	}

	pause(): void {
		this.isPlaying = false
	}

	stop(): void {
		this.isPlaying = false
		this.time = 0
	}

	seek(time: number): void {
		this.time = time
	}

	currentTime(): number {
		return this.time
	}

	async playTones(): Promise<void> {}
	destroy(): void {
		this.destroyed = true
	}

}

const players = FakePlayer.all as FakePlayer[]

vi.mock('../score/player.ts', () => ({
	ScorePlayer: FakePlayer,
	voiceGains: () => () => 1,
}))

const { usePlayback } = await import('../score/usePlayback.ts')

function setup(canPlay = true) {
	const messages: string[] = []
	const positions: number[] = []
	let started = 0
	const model = shallowRef<ScoreModel | null>({ voices: [], measures: [], notesByMeasure: [], signatures: { fifths: 0 } } as unknown as ScoreModel)
	const volume = ref(70)
	const playback = usePlayback({
		model,
		range: shallowRef(null),
		focusKey: ref(''),
		focusVoice: computed<Voice | null>(() => null),
		pages: ref(null),
		canPlay: () => canPlay,
		labelOf: (voice) => voice.key,
		measureNumber: () => '3',
		announce: (text) => messages.push(text),
		onPosition: (time) => positions.push(time),
		resetHighlights: () => {},
		applyFocus: () => {},
		onStarted: () => {
			started++
		},
		volume,
		initial: { tempo: 80, transpose: -2 },
	})
	return { playback, messages, positions, volume, started: () => started }
}

describe('usePlayback', () => {
	beforeEach(() => {
		players.length = 0
		localStorage.clear()
	})

	it('starts with the remembered settings', () => {
		const { playback } = setup()
		expect(playback.tempoPercent.value).toBe(80)
		expect(playback.transpose.value).toBe(-2)
		expect(playback.metronome.value).toBe(false)
		expect(playback.mode.value).toBe('all')
		expect(playback.soundFifths.value).not.toBeNull()
	})

	it('plays, pauses and stops with a message for each', async () => {
		const { playback, messages, started } = setup()
		playback.countInBars.value = 0
		await playback.togglePlay()
		expect(playback.playing.value).toBe(true)
		expect(players).toHaveLength(1)
		expect(players[0].rate).toBe(0.8)
		expect(started()).toBe(1)
		expect(messages.at(-1)).toBe('Playing from measure 3.')

		await playback.togglePlay()
		expect(playback.playing.value).toBe(false)
		expect(messages.at(-1)).toBe('Paused at measure 3.')

		await playback.playFrom(4)
		expect(players[0].time).toBe(4)
		playback.stop()
		expect(playback.playing.value).toBe(false)
		expect(messages.at(-1)).toBe('Stopped.')
	})

	it('announces the count-in when there is one', async () => {
		const { playback, messages } = setup()
		playback.countInBars.value = 1
		await playback.togglePlay()
		expect(messages.at(-1)).toBe('Count-in, then playing from measure 3.')
	})

	it('does not play a piece that may not be played', async () => {
		const { playback } = setup(false)
		await playback.togglePlay()
		expect(players).toHaveLength(0)
		expect(playback.playing.value).toBe(false)
	})

	it('hands tempo, volume and loop to the player and pauses without a message', async () => {
		const { playback, messages, volume } = setup()
		await playback.togglePlay()
		playback.tempoPercent.value = 120
		volume.value = 50
		await nextTick()
		expect(players[0].rate).toBe(1.2)
		expect(players[0].volume).toBe(0.5)
		playback.setLoop(true)
		expect(players[0].loop).toBe(true)
		const count = messages.length
		playback.pausePlayback()
		expect(playback.isPlaying()).toBe(false)
		expect(messages).toHaveLength(count)
		playback.destroy()
		expect(players[0].destroyed).toBe(true)
	})

	it('moves to the next measure with the arrow keys and remembers the count-in', async () => {
		const { playback } = setup()
		playback.timeline.value = { events: [], measures: [{ start: 0 }, { start: 2 }, { start: 4 }], duration: 6 } as never
		playback.stepMeasure(1)
		expect(players[0].time).toBe(2)
		playback.stepMeasure(5)
		expect(players[0].time).toBe(4)
		playback.countInBars.value = 2
		await nextTick()
		expect(playback.countInChoice.value).toBe('2')
	})
})
