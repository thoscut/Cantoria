<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Tools for practising (milestone 0.13), in the score view and in shared
 * pieces: starting tones, metronome, transposing and a mixer with a level
 * per voice. The parent plays; this only holds the choices.
 */
import type { Mix } from '../score/practising.ts'

import { mdiMetronome, mdiMusicNote, mdiTuneVertical } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, ref, useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { transposeText } from '../practiceLabels.ts'
import { mixChanged, TRANSPOSE_MAX } from '../score/practising.ts'

const props = defineProps<{
	/** voices of the score with their display names */
	voices: { key: string, label: string }[]
	/** while playing the starting tones cannot be given */
	playing: boolean
}>()

const emit = defineEmits<{
	/** play the starting tones from the current place */
	tones: []
}>()

const mix = defineModel<Mix>('mix', { required: true })
const transpose = defineModel<number>('transpose', { required: true })
const metronome = defineModel<boolean>('metronome', { required: true })

const mixerShown = ref(false)
const mixerId = useId()
const transposeId = useId()
const transposeHintId = useId()

const transposeLabel = computed(() => t('cantoria', 'Transpose: {change}', { change: transposeText(transpose.value) }))

/**
 * Level of a voice in percent.
 *
 * @param key - Voice.key
 */
function levelOf(key: string): number {
	return mix.value[key] ?? 100
}

/**
 * Set the level of a voice.
 *
 * @param key - Voice.key
 * @param event - input event of the slider
 */
function setLevel(key: string, event: Event): void {
	const value = Number((event.target as HTMLInputElement).value)
	mix.value = { ...mix.value, [key]: Math.max(0, Math.min(100, value)) }
}

/**
 * The level for people: "muted" at 0.
 *
 * @param label - name of the voice
 * @param level - percent
 */
function levelText(label: string, level: number): string {
	return level === 0
		? t('cantoria', '{voice}: muted', { voice: label })
		: t('cantoria', '{voice}: {percent} %', { voice: label, percent: level })
}

const changed = computed(() => mixChanged(mix.value))

function resetMix(): void {
	mix.value = {}
}

const idOf = (key: string) => `${mixerId}-${props.voices.findIndex((voice) => voice.key === key)}`
</script>

<template>
	<div class="practice-tools">
		<div class="practice-tools__row">
			<NcButton :disabled="playing" @click="emit('tones')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMusicNote" />
				</template>
				{{ t('cantoria', 'Starting tones') }}
			</NcButton>
			<NcCheckboxRadioSwitch v-model="metronome" type="switch">
				<span class="practice-tools__with-icon">
					<NcIconSvgWrapper :path="mdiMetronome" :size="18" />
					{{ t('cantoria', 'Metronome') }}
				</span>
			</NcCheckboxRadioSwitch>
			<NcButton variant="tertiary"
				:aria-expanded="mixerShown ? 'true' : 'false'"
				:aria-controls="mixerId"
				@click="mixerShown = !mixerShown">
				<template #icon>
					<NcIconSvgWrapper :path="mdiTuneVertical" />
				</template>
				{{ changed ? t('cantoria', 'Mixer (changed)') : t('cantoria', 'Mixer') }}
			</NcButton>
		</div>
		<label class="practice-tools__slider" :for="transposeId">
			<span>{{ transposeLabel }}</span>
			<input :id="transposeId"
				v-model.number="transpose"
				type="range"
				:min="-TRANSPOSE_MAX"
				:max="TRANSPOSE_MAX"
				step="1"
				:aria-describedby="transposeHintId"
				:aria-valuetext="transposeText(transpose)">
			<span :id="transposeHintId" class="practice-tools__hint">
				{{ t('cantoria', 'Only the sound moves; the notes stay as written.') }}
			</span>
		</label>
		<div v-show="mixerShown"
			:id="mixerId"
			class="practice-tools__mixer"
			role="group"
			:aria-label="t('cantoria', 'Mixer')">
			<p class="practice-tools__hint">
				{{ t('cantoria', 'The level of each voice, on top of "Voices during playback". 0 % mutes a voice.') }}
			</p>
			<label v-for="voice in voices"
				:key="voice.key"
				class="practice-tools__slider"
				:for="idOf(voice.key)">
				<span>{{ levelText(voice.label, levelOf(voice.key)) }}</span>
				<input :id="idOf(voice.key)"
					:value="levelOf(voice.key)"
					type="range"
					min="0"
					max="100"
					step="10"
					:aria-valuetext="levelText(voice.label, levelOf(voice.key))"
					@input="setLevel(voice.key, $event)">
			</label>
			<NcButton variant="tertiary" :disabled="!changed" @click="resetMix">
				{{ t('cantoria', 'All voices at 100 %') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.practice-tools {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	align-items: flex-end;
	flex-basis: 100%;
}

.practice-tools__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
	align-items: center;
}

.practice-tools__with-icon {
	display: inline-flex;
	gap: var(--default-grid-baseline);
	align-items: center;
}

.practice-tools__mixer {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	align-items: flex-end;
	flex-basis: 100%;
}

.practice-tools__mixer > p {
	flex-basis: 100%;
	margin: 0;
}

.practice-tools__slider {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 12em;
}

.practice-tools__slider input[type='range'] {
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	accent-color: var(--color-primary-element);
}

.practice-tools__slider input[type='range']:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.practice-tools__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}
</style>
