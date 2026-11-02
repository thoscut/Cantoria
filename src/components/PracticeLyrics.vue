<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Reading the lyrics along (milestone 0.14): the line of the voice that is
 * sung now, large, the sung syllable marked, the next line below; and a
 * learning mode that hides letters step by step (score/lyrics.ts). The
 * viewer shows the choices in its settings and the text in the bar that
 * stays at the top, so the text stays in view while the score scrolls.
 */
import type { LyricSyllable } from '../score/lyrics.ts'

import { t } from '../l10n.ts'
import { computed } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { currentSyllable, lyricLines, maskSyllable } from '../score/lyrics.ts'

const props = defineProps<{
	/** syllables of the voice in playing order */
	syllables: LyricSyllable[]
	/** current place of the playback, seconds of the timeline */
	position: number
	/** name of the voice the lyrics belong to */
	voiceLabel: string
	/** 'choices': the switch and the learning mode (in the settings); 'text': the lyrics (in the bar that stays at the top) */
	part: 'choices' | 'text'
}>()

const shown = defineModel<boolean>('shown', { required: true })
const step = defineModel<'0' | '1' | '2' | '3'>('step', { required: true })

const lines = computed(() => lyricLines(props.syllables))
const current = computed(() => currentSyllable(props.syllables, props.position))
/** The line with the sung syllable, or the first before the start */
const lineIndex = computed(() => {
	const syllable = props.syllables[current.value]
	return syllable ? lines.value.findIndex((line) => line.includes(syllable)) : 0
})

interface Shown {
	text: string
	sung: boolean
	space: boolean
}

/**
 * A line as shown: masked syllables, the sung one marked.
 *
 * @param line - syllables of the line
 */
function shownLine(line: LyricSyllable[] | undefined): Shown[] {
	if (!line) {
		return []
	}
	const sung = props.syllables[current.value]
	return line.map((syllable) => {
		const index = props.syllables.indexOf(syllable)
		const wordStart = index <= 0 || props.syllables[index - 1].wordEnd
		return { text: maskSyllable(syllable.text, Number(step.value), wordStart), sung: syllable === sung, space: syllable.wordEnd }
	})
}

const now = computed(() => shownLine(lines.value[lineIndex.value]))
const next = computed(() => shownLine(lines.value[lineIndex.value + 1]))
</script>

<template>
	<div v-if="syllables.length > 0 && (part === 'choices' || shown)" class="practice-lyrics">
		<div v-if="part === 'choices'" class="practice-lyrics__choices">
			<NcCheckboxRadioSwitch v-model="shown" type="switch">
				{{ t('cantoria', 'Read the lyrics along ({voice})', { voice: voiceLabel }) }}
			</NcCheckboxRadioSwitch>
			<NcRadioGroup v-if="shown" v-model="step" :label="t('cantoria', 'Learn the lyrics')">
				<NcRadioGroupButton value="0" :label="t('cantoria', 'All letters')" />
				<NcRadioGroupButton value="1" :label="t('cantoria', 'Some hidden')" />
				<NcRadioGroupButton value="2" :label="t('cantoria', 'Most hidden')" />
				<NcRadioGroupButton value="3" :label="t('cantoria', 'First letters')" />
			</NcRadioGroup>
		</div>
		<!-- Not a live region: the screen reader reads the lyrics when the person goes there -->
		<div v-else class="practice-lyrics__text">
			<p class="practice-lyrics__now">
				<template v-for="(syllable, index) in now" :key="index">
					<mark v-if="syllable.sung" class="practice-lyrics__sung">{{ syllable.text }}</mark><span v-else>{{ syllable.text }}</span>{{ syllable.space ? ' ' : '' }}
				</template>
			</p>
			<p class="practice-lyrics__next">
				<template v-for="(syllable, index) in next" :key="index">
					{{ syllable.text }}{{ syllable.space ? ' ' : '' }}
				</template>
			</p>
		</div>
	</div>
</template>

<style scoped>
.practice-lyrics {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	flex-basis: 100%;
}

.practice-lyrics__choices {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	align-items: center;
}

.practice-lyrics__text p {
	margin: 0;
}

.practice-lyrics__now {
	font-size: 1.5em;
	line-height: 1.4;
	min-height: 1.4em;
}

.practice-lyrics__next {
	color: var(--color-text-maxcontrast);
	min-height: 1.4em;
}

/* The sung syllable: bold and underlined, not only in color (WCAG 1.4.1) */
.practice-lyrics__sung {
	background: var(--color-primary-element-light, transparent);
	color: inherit;
	font-weight: bold;
	text-decoration: underline;
	text-decoration-thickness: 2px;
}

/* A low screen (a phone held sideways): one line, smaller */
@media (max-height: 560px) {
	.practice-lyrics__now {
		font-size: 1.2em;
	}

	.practice-lyrics__next {
		display: none;
	}
}
</style>
