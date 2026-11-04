// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The settings for playing and practising of both score views
 * (src/components/PlaybackSettings.vue), mounted (T21): three sections with
 * headings of the same kind, the third one folded until opened, and inside
 * it a small heading for each tool (D4, D25); the measures go to the view.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import PlaybackSettings from '../components/PlaybackSettings.vue'
import { readStored } from '../storage.ts'

function props(canPlay = true) {
	return {
		voiceOptions: [{ id: '', label: 'No focus voice' }, { id: 'a', label: 'Alto' }],
		canPlay,
		rangeActive: false,
		rangeError: '',
		rangeHint: 'Measures 1 to 12.',
		tempoLabel: 'Tempo: 100 %',
		tempoMin: 50,
		tempoMax: 150,
		tempoHint: 'Percent of the tempo in the score.',
		mixerVoices: [{ key: 'a', label: 'Alto' }],
		playing: false,
		lyricsVoiceLabel: 'Alto',
		syllables: [{ text: 'Der', start: 0, slot: 0, wordEnd: true }],
		position: 0,
		pitchVoiceLabel: 'Alto',
		pitchNotes: [],
		fifths: 0,
		marksKey: 'piece-1',
		shownFrom: '1',
		shownTo: '12',
		title: 'Abendlied',
		playbackPosition: () => 0,
		startPlayback: async () => {},
		pausePlayback: () => {},
		focus: { id: '', label: 'No focus voice' },
		mode: 'all' as const,
		rangeFrom: '1',
		rangeTo: '12',
		loop: false,
		tempo: 100,
		countIn: '0' as const,
		repeats: true,
		mix: {},
		transpose: 0,
		metronome: false,
		lyricsShown: false,
		lyricsStep: '0' as const,
		pitchShown: false,
		pitchProblem: '',
	}
}

describe('PlaybackSettings', () => {
	beforeEach(() => localStorage.clear())

	it('has three sections under headings of the same kind', () => {
		const wrapper = mount(PlaybackSettings, { props: props() })
		const headings = wrapper.findAll('h3')
		expect(headings.map((heading) => heading.text())).toEqual(['My voice', 'Measures and tempo', 'More for practising'])
		// the third is a heading, not a button: the button to open it stands beside it
		expect(headings[2].find('button').exists()).toBe(false)
		for (const heading of headings) {
			expect(heading.classes()).toContain('playback-settings__heading')
		}
	})

	it('opens "More for practising" with its button, each tool under a heading of its own', async () => {
		const wrapper = mount(PlaybackSettings, { props: props() })
		const toggle = wrapper.find('button[aria-expanded]')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')
		const more = wrapper.find(`#${toggle.attributes('aria-controls')}`)
		expect(more.findAll('h4').map((heading) => heading.text())).toEqual(['Tones and mixer', 'Lyrics', 'Your pitch while singing', 'Remembered passages', 'Record myself'])
		for (const section of more.findAll('section')) {
			const id = section.attributes('aria-labelledby')
			expect(section.find(`h4#${CSS.escape(id ?? '')}`).exists()).toBe(true)
		}
		// remembered in this browser
		expect(readStored('practice.more')).toBe('1')
	})

	it('leaves out the heading of the lyrics when the piece has none', async () => {
		const wrapper = mount(PlaybackSettings, { props: { ...props(), lyricsVoiceLabel: null, syllables: [] } })
		expect(wrapper.findAll('h4').map((heading) => heading.text())).not.toContain('Lyrics')
	})

	it('without playback offers only voice and measures', () => {
		const wrapper = mount(PlaybackSettings, { props: props(false) })
		expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['My voice', 'Measures'])
		expect(wrapper.find('input[type="range"]').exists()).toBe(false)
	})

	it('shows the measures typed in', async () => {
		const wrapper = mount(PlaybackSettings, { props: props() })
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('applyRange')).toHaveLength(1)
	})
})
