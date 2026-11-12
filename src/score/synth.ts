/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A small Web Audio synthesizer for playing scores in the browser: a soft,
 * organ-like tone (a few harmonics, gentle vibrato, low-pass filter) with an
 * envelope. No sound fonts, nothing is loaded from a server.
 *
 * The same synthesizer renders practice audio files into an
 * OfflineAudioContext, so that a file sounds like the playback.
 *
 * A second timbre sings (November 2026): a source rich in harmonics, like
 * the vibrating vocal folds, through three resonances (formants) of a vowel
 * – taken from the lyrics under the note, or a neutral "o" – with a wider
 * vibrato. It is no singer, but closer to how a voice sounds in a choir.
 *
 * Clean sound: the signal path is linear (no compressor: its fast gain
 * changes distorted chords audibly); a static soft limiter only bends peaks
 * that would otherwise clip. Every gain change is a ramp, also when the
 * playback stops, pauses or jumps.
 */

interface SoundingNote {
	oscillator: OscillatorNode
	envelope: GainNode
	/** envelope value at a context time */
	level: (time: number) => number
}

const ATTACK = 0.035
const RELEASE = 0.08
/** Level the tone settles to after the attack, as a share of the note's gain */
const SUSTAIN = 0.72
/** Time constant of the settling after the attack */
const DECAY = 0.18
/** Time constant of the release */
const RELEASE_TAU = RELEASE / 3
/** Fade when the playback stops, pauses or jumps: short, but no click */
export const FADE = 0.03

/** How long a note sounds after its end (release of the envelope), in seconds. */
export const RELEASE_TAIL = RELEASE * 2.5

/** Harmonics (sine amplitudes) of a mellow reed/organ tone; the upper ones are kept low */
export const HARMONICS = [0, 1, 0.36, 0.15, 0.07, 0.03, 0.015]

/** Gain of the mix bus: four voices at full balance stay below the limiter's knee */
export const BUS_GAIN = 0.36

/** Shared vibrato: rate in Hz, depth in cent; it fades in after the attack */
const VIBRATO_RATE = 5
const VIBRATO_CENTS = 5
const VIBRATO_DELAY = 0.15
const VIBRATO_FADE = 0.3

/** The sung timbre: harmonics of the source fall off like the vocal folds' (1/n^1.2) */
export const VOICE_HARMONICS = [0, ...Array.from({ length: 40 }, (_, index) => (index + 1) ** -1.2)]

/** Wider vibrato of the sung timbre, as a factor of VIBRATO_CENTS */
const VOICE_VIBRATO = 4
const VOICE_VIBRATO_DELAY = 0.25

export type Timbre = 'organ' | 'voice'
export type Vowel = 'a' | 'e' | 'i' | 'o' | 'u' | 'neutral'

/**
 * Three formants per vowel: frequency in Hz and relative level (averages of
 * adult voices). The bandwidths are the same for every vowel.
 */
export const FORMANTS: Record<Vowel, [number, number][]> = {
	a: [[800, 1], [1150, 0.5], [2900, 0.25]],
	e: [[400, 1], [2000, 0.4], [2550, 0.3]],
	i: [[270, 1], [2300, 0.3], [3000, 0.25]],
	o: [[450, 1], [800, 0.6], [2830, 0.15]],
	u: [[325, 1], [700, 0.3], [2530, 0.1]],
	neutral: [[500, 1], [1000, 0.5], [2600, 0.2]],
}
const FORMANT_BANDWIDTHS = [160, 200, 260]
/** Levels the vowels to about the loudness of the organ tone (measured offline) */
const VOWEL_GAIN: Record<Vowel, number> = { a: 2.5, e: 1.3, i: 1, o: 1.1, u: 1.1, neutral: 1.6 }

/**
 * Low tones lose more of their energy in the formants: a boost that is
 * 1.8 at G2 and 1 from about E4 on.
 *
 * @param frequency - fundamental in Hz
 */
export function lowBoost(frequency: number): number {
	return Math.max(1, Math.min(1.8, 1.8 - (frequency - 98) / 300))
}

/**
 * Frequencies of the formants of a vowel for a tone. A singer raises the
 * first formant above a high fundamental, so that a high tone keeps its
 * strength; the synthesizer does the same.
 *
 * @param vowel - the vowel
 * @param frequency - fundamental in Hz
 */
export function formantsFor(vowel: Vowel, frequency: number): { frequency: number, q: number, gain: number }[] {
	return FORMANTS[vowel].map(([formant, gain], index) => {
		const tuned = index === 0 ? Math.max(formant, frequency * 1.1) : formant
		return { frequency: tuned, q: tuned / FORMANT_BANDWIDTHS[index], gain }
	})
}

/** The limiter passes levels up to this unchanged … */
export const LIMIT_KNEE = 0.7
/** … and maps input levels up to this onto at most 1 */
export const LIMIT_RANGE = 2

/**
 * Envelope of a note at a time: a linear attack to the gain, settling to
 * the sustain level, an exponential release before the end. The synthesizer
 * schedules exactly this curve; the function gives its value for fading out
 * from wherever the note is.
 *
 * @param time - context time
 * @param start - start of the note
 * @param end - end of the note
 * @param gain - loudness 0 … 1
 */
export function envelopeAt(time: number, start: number, end: number, gain: number): number {
	const peak = start + ATTACK
	const release = releaseStart(start, end)
	if (time <= start) {
		return 0
	}
	if (time < peak) {
		return gain * (time - start) / ATTACK
	}
	const settle = (t: number) => gain * SUSTAIN + (gain - gain * SUSTAIN) * Math.exp(-(t - peak) / DECAY)
	if (time < release) {
		return settle(time)
	}
	return settle(release) * Math.exp(-(time - release) / RELEASE_TAU)
}

/**
 * When the release of a note begins: shortly before its end, after the attack.
 *
 * @param start - start of the note
 * @param end - end of the note
 */
export function releaseStart(start: number, end: number): number {
	return Math.max(start + ATTACK, end - RELEASE / 2)
}

/**
 * Curve of the soft limiter (WaveShaperNode) over the input range
 * ±LIMIT_RANGE: linear up to the knee, above it a tanh bend that never
 * reaches 1. Below the knee the signal passes unchanged.
 *
 * @param points - number of points, odd so that 0 is a point
 */
export function limiterCurve(points = 4097): Float32Array<ArrayBuffer> {
	const curve = new Float32Array(points)
	for (let i = 0; i < points; i++) {
		const input = (i / (points - 1) * 2 - 1) * LIMIT_RANGE
		const level = Math.abs(input)
		const output = level <= LIMIT_KNEE ? level : LIMIT_KNEE + (1 - LIMIT_KNEE) * Math.tanh((level - LIMIT_KNEE) / (1 - LIMIT_KNEE))
		curve[i] = Math.sign(input) * output
	}
	return curve
}

export class Synth {

	readonly context: BaseAudioContext
	private readonly bus: GainNode
	private readonly master: GainNode
	private readonly wave: PeriodicWave
	private voiceWave: PeriodicWave | null = null
	private timbre: Timbre = 'organ'
	private readonly vibrato: OscillatorNode
	private readonly vibratoDepth: GainNode
	private readonly sounding = new Set<SoundingNote>()

	/**
	 * @param volume - overall volume 0 … 1
	 * @param target - context to play into; default: a new AudioContext for the speakers
	 */
	constructor(volume = 0.7, target?: BaseAudioContext) {
		const context = target ?? new AudioContext({ latencyHint: 'playback' })
		this.context = context

		const imag = new Float32Array(HARMONICS)
		this.wave = context.createPeriodicWave(new Float32Array(imag.length), imag)

		this.bus = context.createGain()
		this.bus.gain.value = BUS_GAIN
		const filter = context.createBiquadFilter()
		filter.type = 'lowpass'
		filter.frequency.value = 2600
		filter.Q.value = 0.4
		this.master = context.createGain()
		this.master.gain.value = volume
		// Soft limiter: the shaper's input range is ±1, so scale ±LIMIT_RANGE into it
		const range = context.createGain()
		range.gain.value = 1 / LIMIT_RANGE
		const limiter = context.createWaveShaper()
		limiter.curve = limiterCurve()
		limiter.oversample = '2x'
		this.bus.connect(filter).connect(this.master).connect(range).connect(limiter).connect(context.destination)

		this.vibrato = context.createOscillator()
		this.vibrato.frequency.value = VIBRATO_RATE
		this.vibratoDepth = context.createGain()
		this.vibratoDepth.gain.value = VIBRATO_CENTS
		this.vibrato.connect(this.vibratoDepth)
		this.vibrato.start()
	}

	/** Start audio output; browsers allow this only after a user action. */
	async resume(): Promise<void> {
		if (this.context instanceof AudioContext && this.context.state !== 'running') {
			await this.context.resume()
		}
	}

	/**
	 * Organ tone or sung vowels, for the notes scheduled from now on.
	 *
	 * @param timbre - the timbre
	 */
	setTimbre(timbre: Timbre): void {
		this.timbre = timbre
	}

	/**
	 * Overall volume.
	 *
	 * @param value - 0 … 1
	 */
	setVolume(value: number): void {
		this.master.gain.setTargetAtTime(Math.max(0, Math.min(1, value)), this.context.currentTime, 0.02)
	}

	/**
	 * Schedule one note.
	 *
	 * @param pitch - MIDI pitch
	 * @param start - start in AudioContext time
	 * @param end - end in AudioContext time
	 * @param gain - loudness 0 … 1
	 * @param vowel - the vowel to sing in the sung timbre; default the neutral one
	 */
	note(pitch: number, start: number, end: number, gain: number, vowel?: Vowel | null): void {
		const context = this.context
		const sung = this.timbre === 'voice'
		const oscillator = context.createOscillator()
		const frequency = 440 * 2 ** ((pitch - 69) / 12)
		oscillator.setPeriodicWave(sung ? this.sungWave() : this.wave)
		oscillator.frequency.value = frequency
		// The vibrato sets in after the attack: a steady pitch at the start
		const depth = context.createGain()
		const vibratoDelay = sung ? VOICE_VIBRATO_DELAY : VIBRATO_DELAY
		depth.gain.value = 0
		depth.gain.setValueAtTime(0, start + vibratoDelay)
		depth.gain.linearRampToValueAtTime(sung ? VOICE_VIBRATO : 1, start + vibratoDelay + VIBRATO_FADE)
		this.vibratoDepth.connect(depth).connect(oscillator.detune)

		const envelope = context.createGain()
		const release = releaseStart(start, end)
		envelope.gain.value = 0
		envelope.gain.setValueAtTime(0, start)
		envelope.gain.linearRampToValueAtTime(gain, start + ATTACK)
		envelope.gain.setTargetAtTime(gain * SUSTAIN, start + ATTACK, DECAY)
		envelope.gain.setTargetAtTime(0, release, RELEASE_TAU)
		// The tail ends at a level far below hearing (about −65 dB); stop there
		envelope.gain.setValueAtTime(0, release + RELEASE_TAIL)

		oscillator.connect(envelope)
		const formants: AudioNode[] = []
		if (sung) {
			const chosen: Vowel = vowel ?? 'neutral'
			const makeup = VOWEL_GAIN[chosen] * lowBoost(frequency)
			for (const formant of formantsFor(chosen, frequency)) {
				const filter = context.createBiquadFilter()
				filter.type = 'bandpass'
				filter.frequency.value = formant.frequency
				filter.Q.value = formant.q
				const level = context.createGain()
				level.gain.value = formant.gain * makeup
				envelope.connect(filter).connect(level).connect(this.bus)
				formants.push(filter, level)
			}
		} else {
			envelope.connect(this.bus)
		}
		oscillator.start(start)
		oscillator.stop(release + RELEASE_TAIL + 0.01)
		const sounding: SoundingNote = { oscillator, envelope, level: (time) => envelopeAt(time, start, end, gain) }
		this.sounding.add(sounding)
		oscillator.onended = () => {
			this.vibratoDepth.disconnect(depth)
			depth.disconnect()
			envelope.disconnect()
			formants.forEach((node) => node.disconnect())
			this.sounding.delete(sounding)
		}
	}

	/** The source of the sung timbre, made on first use */
	private sungWave(): PeriodicWave {
		if (!this.voiceWave) {
			const imag = new Float32Array(VOICE_HARMONICS)
			// Not normalized: the levels of the formants are measured against this source
			this.voiceWave = this.context.createPeriodicWave(new Float32Array(imag.length), imag, { disableNormalization: true })
		}
		return this.voiceWave
	}

	/**
	 * Schedule one click of a count-in: a short, bright tick, higher on the
	 * first beat of a bar.
	 *
	 * @param time - start in AudioContext time
	 * @param accent - first beat of a bar
	 */
	click(time: number, accent: boolean): void {
		const context = this.context
		const oscillator = context.createOscillator()
		oscillator.frequency.value = accent ? 1760 : 1320
		const envelope = context.createGain()
		const peak = accent ? 0.9 : 0.6
		const attack = 0.004
		envelope.gain.value = 0
		envelope.gain.setValueAtTime(0, time)
		envelope.gain.linearRampToValueAtTime(peak, time + attack)
		envelope.gain.setTargetAtTime(0, time + attack, 0.015)
		envelope.gain.setValueAtTime(0, time + 0.12)
		oscillator.connect(envelope).connect(this.bus)
		oscillator.start(time)
		oscillator.stop(time + 0.13)
		const level = (at: number) => at <= time ? 0 : at < time + attack ? peak * (at - time) / attack : peak * Math.exp(-(at - time - attack) / 0.015)
		const sounding: SoundingNote = { oscillator, envelope, level }
		this.sounding.add(sounding)
		oscillator.onended = () => {
			envelope.disconnect()
			this.sounding.delete(sounding)
		}
	}

	/**
	 * Fade out everything that sounds or is scheduled: from the level each
	 * note has now down to 0 within FADE; notes that have not started stay
	 * silent.
	 */
	silence(): void {
		const now = this.context.currentTime
		for (const sounding of this.sounding) {
			const { oscillator, envelope } = sounding
			const from = sounding.level(now)
			envelope.gain.cancelScheduledValues(now)
			envelope.gain.setValueAtTime(from, now)
			envelope.gain.linearRampToValueAtTime(0, now + FADE)
			// A second silence() during the fade starts from the fading level
			sounding.level = (time) => from * Math.max(0, Math.min(1, (now + FADE - time) / FADE))
			try {
				oscillator.stop(now + FADE + 0.01)
			} catch {
				// not started yet: stop() before start() throws in some browsers
				oscillator.disconnect()
			}
		}
	}

	async close(): Promise<void> {
		this.silence()
		if (this.context instanceof AudioContext) {
			// Let the fade finish: closing the context cuts the sound at once
			if (this.context.state === 'running') {
				await new Promise((resolve) => setTimeout(resolve, (FADE + 0.02) * 1000))
			}
			this.vibrato.stop()
			await this.context.close()
		} else {
			this.vibrato.stop()
		}
	}

}
