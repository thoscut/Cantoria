<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The settings of the playback and the tools for practising, the same in
 * the score view of the archive and in the view of a shared piece
 * (milestone 0.19). Three sections with visible headings, in the order of
 * the most frequent way of practising: choose one's voice, then the
 * measures and the tempo; the further tools are folded and remembered in
 * this browser. The views keep the state; this component only lays it out.
 */
import type { Mark } from '../score/marks.ts'
import type { PlayMode } from '../score/player.ts'
import type { TargetNote } from '../score/pitch.ts'
import type { Mix } from '../score/practising.ts'
import type { LyricSyllable } from '../score/lyrics.ts'

import { mdiChevronDown, mdiChevronUp } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, ref, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PracticeLyrics from './PracticeLyrics.vue'
import PracticeMarks from './PracticeMarks.vue'
import PracticePitch from './PracticePitch.vue'
import PracticeRecorder from './PracticeRecorder.vue'
import PracticeTools from './PracticeTools.vue'
import { readStored, writeStored } from '../storage.ts'

export interface VoiceOption {
	id: string
	label: string
}

export type RecorderState = 'idle' | 'recording' | 'ready'

const props = withDefaults(defineProps<{
	/** "No focus voice" first, then every voice */
	voiceOptions: VoiceOption[]
	/** whether the playback is available at all (else only voice and measures) */
	canPlay: boolean
	/** whether a range of measures other than all is shown */
	rangeActive: boolean
	rangeError: string
	/** printed measure numbers of the piece, and the pickup */
	rangeHint: string
	/** label, limits and hint of the tempo slider */
	tempoLabel: string
	tempoMin: number
	tempoMax: number
	tempoHint: string
	mixerVoices: { key: string, label: string }[]
	playing: boolean
	/** voice to read the lyrics along with, null without lyrics */
	lyricsVoiceLabel: string | null
	syllables: LyricSyllable[]
	position: number
	/** focus voice for the pitch display, null without one */
	pitchVoiceLabel: string | null
	pitchNotes: TargetNote[]
	fifths: number | null
	/** key of the passages remembered in this browser */
	marksKey: string
	shownFrom: string
	shownTo: string
	/** title of the piece, for the file name of a recording */
	title: string
	playbackPosition: () => number
	startPlayback: (from: number) => Promise<void>
	pausePlayback: () => void
}>(), {})

const emit = defineEmits<{
	applyRange: []
	showAll: []
	tones: []
	applyMark: [mark: Mark]
	announce: [text: string]
}>()

const focus = defineModel<VoiceOption>('focus', { required: true })
const mode = defineModel<PlayMode>('mode', { required: true })
const rangeFrom = defineModel<string>('rangeFrom', { required: true })
const rangeTo = defineModel<string>('rangeTo', { required: true })
const loop = defineModel<boolean>('loop', { required: true })
const tempo = defineModel<number>('tempo', { required: true })
const countIn = defineModel<'0' | '1' | '2'>('countIn', { required: true })
const repeats = defineModel<boolean>('repeats', { required: true })
const mix = defineModel<Mix>('mix', { required: true })
const transpose = defineModel<number>('transpose', { required: true })
const metronome = defineModel<boolean>('metronome', { required: true })
const lyricsShown = defineModel<boolean>('lyricsShown', { required: true })
const lyricsStep = defineModel<'0' | '1' | '2' | '3'>('lyricsStep', { required: true })
const pitchShown = defineModel<boolean>('pitchShown', { required: true })
const pitchProblem = defineModel<string>('pitchProblem', { required: true })
/** whether a recording runs or waits unsaved; shown in the bar of the view */
const recorder = defineModel<RecorderState>('recorder', { default: 'idle' })

const voiceHeadingId = useId()
const measuresHeadingId = useId()
const moreHeadingId = useId()
const moreId = useId()
const tempoId = useId()
const tempoHintId = useId()
const rangeErrorId = useId()
const rangeHintId = useId()

const hasFocus = computed(() => focus.value.id !== '')

/** The further tools: folded until opened, remembered in this browser */
const MORE_KEY = 'practice.more'
const moreShown = ref(readStored(MORE_KEY) === '1')
watch(moreShown, (shown) => writeStored(MORE_KEY, shown ? '1' : '0'))

const describedBy = computed(() => props.rangeError !== '' ? `${rangeErrorId} ${rangeHintId}` : rangeHintId)
</script>

<template>
	<div class="playback-settings">
		<section class="playback-settings__section" :aria-labelledby="voiceHeadingId">
			<h3 :id="voiceHeadingId" class="playback-settings__heading">
				{{ t('cantoria', 'My voice') }}
			</h3>
			<div class="playback-settings__row">
				<NcSelect v-model="focus"
					class="playback-settings__voice"
					:input-label="t('cantoria', 'Focus voice')"
					:options="voiceOptions"
					:clearable="false"
					:searchable="false" />
				<NcRadioGroup v-if="canPlay" v-model="mode" :label="t('cantoria', 'Voices during playback')">
					<NcRadioGroupButton value="all" :label="t('cantoria', 'All equal')" />
					<NcRadioGroupButton value="focus"
						:label="t('cantoria', 'Focus voice louder')"
						:disabled="!hasFocus" />
					<NcRadioGroupButton value="solo"
						:label="t('cantoria', 'Focus voice only')"
						:disabled="!hasFocus" />
				</NcRadioGroup>
			</div>
			<p v-if="canPlay && !hasFocus" class="playback-settings__hint">
				{{ t('cantoria', 'Choose your voice to hear it louder or alone.') }}
			</p>
		</section>

		<section class="playback-settings__section" :aria-labelledby="measuresHeadingId">
			<h3 :id="measuresHeadingId" class="playback-settings__heading">
				{{ canPlay ? t('cantoria', 'Measures and tempo') : t('cantoria', 'Measures') }}
			</h3>
			<form class="playback-settings__row" @submit.prevent="emit('applyRange')">
				<!-- NcTextField puts a class on its input, not on its box: the width is set here -->
				<div class="playback-settings__measure-field">
					<NcTextField v-model="rangeFrom"
						:label="t('cantoria', 'From measure')"
						:error="rangeError !== ''"
						:aria-describedby="describedBy" />
				</div>
				<div class="playback-settings__measure-field">
					<NcTextField v-model="rangeTo"
						:label="t('cantoria', 'To measure')"
						:error="rangeError !== ''"
						:aria-describedby="describedBy" />
				</div>
				<NcButton type="submit">
					{{ t('cantoria', 'Show') }}
				</NcButton>
				<NcButton v-if="rangeActive" variant="tertiary" @click="emit('showAll')">
					{{ t('cantoria', 'All measures') }}
				</NcButton>
				<!-- A–B loop: the measures of the two fields again and again -->
				<NcCheckboxRadioSwitch v-if="canPlay"
					v-model="loop"
					class="playback-settings__loop"
					type="switch">
					{{ t('cantoria', 'Loop') }}
				</NcCheckboxRadioSwitch>
			</form>
			<p v-if="rangeError"
				:id="rangeErrorId"
				class="playback-settings__error"
				role="alert">
				{{ rangeError }}
			</p>
			<p :id="rangeHintId" class="playback-settings__hint">
				{{ rangeHint }}
				<template v-if="canPlay">
					{{ t('cantoria', '"Loop" plays the shown measures again and again.') }}
				</template>
			</p>
			<div v-if="canPlay" class="playback-settings__row">
				<label class="playback-settings__slider" :for="tempoId">
					<span>{{ tempoLabel }}</span>
					<input :id="tempoId"
						v-model.number="tempo"
						type="range"
						:min="tempoMin"
						:max="tempoMax"
						step="5"
						:aria-describedby="tempoHintId"
						:aria-valuetext="t('cantoria', '{percent} % of the tempo', { percent: tempo })">
					<span :id="tempoHintId" class="playback-settings__hint">{{ tempoHint }}</span>
				</label>
				<NcRadioGroup v-model="countIn" :label="t('cantoria', 'Count-in')">
					<NcRadioGroupButton value="0" :label="t('cantoria', 'Off')" />
					<NcRadioGroupButton value="1" :label="t('cantoria', '1 bar')" />
					<NcRadioGroupButton value="2" :label="t('cantoria', '2 bars')" />
				</NcRadioGroup>
				<div class="playback-settings__switches">
					<NcCheckboxRadioSwitch v-model="repeats" type="switch">
						{{ t('cantoria', 'Play repeats') }}
					</NcCheckboxRadioSwitch>
					<!-- the score view adds "Scroll along" and the volume -->
					<slot name="playback" />
				</div>
			</div>
		</section>

		<section v-if="canPlay" class="playback-settings__section" :aria-labelledby="moreHeadingId">
			<h3 :id="moreHeadingId" class="playback-settings__heading">
				<NcButton variant="tertiary"
					alignment="start"
					:aria-expanded="moreShown ? 'true' : 'false'"
					:aria-controls="moreId"
					@click="moreShown = !moreShown">
					<template #icon>
						<NcIconSvgWrapper :path="moreShown ? mdiChevronUp : mdiChevronDown" />
					</template>
					{{ t('cantoria', 'More for practising') }}
				</NcButton>
			</h3>
			<p v-if="!moreShown" class="playback-settings__hint">
				{{ t('cantoria', 'Starting tones, metronome, transposing, mixer, lyrics, your pitch, remembered passages and recording yourself.') }}
			</p>
			<!-- v-show: a running recording or the microphone go on while folded -->
			<div v-show="moreShown" :id="moreId" class="playback-settings__more">
				<PracticeTools v-model:mix="mix"
					v-model:transpose="transpose"
					v-model:metronome="metronome"
					:voices="mixerVoices"
					:playing="playing"
					@tones="emit('tones')" />
				<PracticeLyrics v-if="lyricsVoiceLabel"
					v-model:shown="lyricsShown"
					v-model:step="lyricsStep"
					part="choices"
					:syllables="syllables"
					:position="position"
					:voice-label="lyricsVoiceLabel" />
				<PracticePitch v-model:shown="pitchShown"
					v-model:problem="pitchProblem"
					part="choices"
					:notes="pitchNotes"
					:position="position"
					:playing="playing"
					:voice-label="pitchVoiceLabel"
					:fifths="fifths"
					@announce="emit('announce', $event)" />
				<PracticeMarks :piece="marksKey"
					:from="shownFrom"
					:to="shownTo"
					@apply="emit('applyMark', $event)"
					@announce="emit('announce', $event)" />
				<PracticeRecorder v-model:state="recorder"
					:title="title"
					:playing="playing"
					:position="playbackPosition"
					:start-playback="startPlayback"
					:pause-playback="pausePlayback"
					@announce="emit('announce', $event)" />
			</div>
		</section>
	</div>
</template>

<style scoped>
.playback-settings {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	min-width: 0;
}

.playback-settings p {
	margin: 0;
}

.playback-settings__section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.playback-settings__heading {
	margin: 0;
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.playback-settings__row,
.playback-settings__switches {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	min-width: 0;
}

.playback-settings__more {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: calc(3 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
	min-width: 0;
}

/* wide enough for "No focus voice" and "Tenor 1, voice 2"; .nc-select sets
   min-width: 260px with three classes, one more wins */
.playback-settings__row .playback-settings__voice.nc-select {
	min-width: min(100%, 20em);
}

.playback-settings__measure-field {
	width: 8em;
}

.playback-settings__slider {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) / 2);
	min-width: 12em;
}

.playback-settings__slider input[type='range'] {
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	accent-color: var(--color-primary-element);
}

.playback-settings__slider input[type='range']:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.playback-settings__hint {
	color: var(--color-text-maxcontrast);
}

.playback-settings__error {
	color: var(--color-error-text, var(--color-error));
}

@media (max-width: 512px) {
	.playback-settings__slider,
	.playback-settings__row .playback-settings__voice.nc-select {
		min-width: 100%;
	}
}
</style>
