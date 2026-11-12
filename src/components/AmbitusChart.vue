<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure class="ambitus" :class="{ 'ambitus--compact': compact }">
		<svg :viewBox="`0 0 ${width} ${height}`"
			:width="width"
			:height="height"
			class="ambitus__svg"
			aria-hidden="true">
			<defs>
				<pattern :id="hatchId"
					width="5"
					height="5"
					patternUnits="userSpaceOnUse"
					patternTransform="rotate(45)">
					<line x1="0"
						y1="0"
						x2="0"
						y2="5"
						class="ambitus__hatch" />
				</pattern>
			</defs>
			<g v-for="c in octaves" :key="c">
				<line :x1="axis"
					:x2="width"
					:y1="y(c)"
					:y2="y(c)"
					class="ambitus__grid" />
				<text v-if="!compact"
					:x="axis - 4"
					:y="y(c) + 3"
					text-anchor="end"
					class="ambitus__axis">{{ toneName(c, 0, german) }}</text>
			</g>
			<g v-for="(column, index) in columns"
				:key="index"
				:class="`ambitus__voice ambitus__voice--${(column.voice.group ?? 'none').toLowerCase()}`">
				<rect v-if="column.band"
					:x="x(index) - barWidth"
					:width="barWidth * 2"
					:y="y(column.band.limitHigh)"
					:height="y(column.band.limitLow) - y(column.band.limitHigh)"
					class="ambitus__limit" />
				<rect v-if="column.band"
					:x="x(index) - barWidth"
					:width="barWidth * 2"
					:y="y(column.band.comfortHigh)"
					:height="y(column.band.comfortLow) - y(column.band.comfortHigh)"
					class="ambitus__comfort" />
				<line :x1="x(index)"
					:x2="x(index)"
					:y1="y(column.high)"
					:y2="y(column.low)"
					class="ambitus__range" />
				<rect :x="x(index) - barWidth / 2"
					:width="barWidth"
					:y="y(column.tessHigh)"
					:height="Math.max(2, y(column.tessLow) - y(column.tessHigh))"
					rx="3"
					class="ambitus__tessitura" />
				<rect v-if="column.band && column.high > column.band.comfortHigh"
					:x="x(index) - barWidth / 2"
					:width="barWidth"
					:y="y(column.high)"
					:height="y(column.band.comfortHigh) - y(column.high)"
					:fill="`url(#${hatchId})`"
					class="ambitus__outside" />
				<rect v-if="column.band && column.low < column.band.comfortLow"
					:x="x(index) - barWidth / 2"
					:width="barWidth"
					:y="y(column.band.comfortLow)"
					:height="y(column.low) - y(column.band.comfortLow)"
					:fill="`url(#${hatchId})`"
					class="ambitus__outside" />
				<circle :cx="x(index)"
					:cy="y(column.high)"
					r="3"
					class="ambitus__end" />
				<circle :cx="x(index)"
					:cy="y(column.low)"
					r="3"
					class="ambitus__end" />
				<text :x="x(index)"
					:y="height - 4"
					text-anchor="middle"
					class="ambitus__letter">{{ column.voice.group ?? '·' }}</text>
			</g>
		</svg>
		<figcaption class="ambitus__caption">
			<ul class="ambitus__list">
				<li v-for="(column, index) in columns" :key="index">
					<VoiceMark :group="column.voice.group" :label="column.voice.name ?? groupLabel(column.voice.group)" />
					<span class="ambitus__tones">{{ toneName(column.low, fifths, german) }}–{{ toneName(column.high, fifths, german) }}</span>
					<span v-if="column.fit" class="ambitus__fit" :class="`ambitus__fit--${column.fit}`">{{ fitLabel(column.fit) }}</span>
				</li>
			</ul>
		</figcaption>
	</figure>
</template>

<script setup lang="ts">
/**
 * The range of each voice of a piece next to the choir profile: a thin line
 * from the lowest to the highest tone, a thick bar where the voice mostly
 * sings, the comfortable range of the group as a band behind it and the
 * possible range as an outline; tones outside the comfortable range are
 * hatched. The same facts stand as text in the caption, so the drawing is
 * only an aid.
 */
import type { Fit, FactVoice, ProfileGroup } from '../scoreFacts.ts'

import { getLanguage, translate as t } from '@nextcloud/l10n'
import { computed, useId } from 'vue'
import VoiceMark from './VoiceMark.vue'
import { groupLabel, rangeFit, toneName } from '../scoreFacts.ts'

const props = withDefaults(defineProps<{
	voices: FactVoice[]
	profile?: ProfileGroup[] | null
	/** semitones the piece is shown transposed by */
	transpose?: number
	/** the key, for the names of the tones */
	fifths?: number | null
	compact?: boolean
}>(), { profile: null, transpose: 0, fifths: null, compact: false })

const german = getLanguage().toLowerCase().startsWith('de')
const hatchId = `ambitus-hatch-${useId()}`

const columns = computed(() => props.voices.map((voice) => {
	const band = props.profile?.find((group) => group.group === voice.group) ?? null
	const low = voice.low + props.transpose
	const high = voice.high + props.transpose
	return {
		voice,
		band,
		low,
		high,
		tessLow: voice.tessLow + props.transpose,
		tessHigh: voice.tessHigh + props.transpose,
		fit: band ? rangeFit(low, high, band).fit : null as Fit | null,
	}
}))

const lowest = computed(() => Math.min(...columns.value.flatMap((c) => [c.low, c.band?.limitLow ?? c.low])) - 2)
const highest = computed(() => Math.max(...columns.value.flatMap((c) => [c.high, c.band?.limitHigh ?? c.high])) + 2)

const step = computed(() => props.compact ? 2.2 : 3.4)
const axis = computed(() => props.compact ? 4 : 34)
const columnWidth = computed(() => props.compact ? 22 : 44)
const barWidth = computed(() => props.compact ? 5 : 9)
const width = computed(() => axis.value + columnWidth.value * Math.max(1, columns.value.length) + 6)
const height = computed(() => (highest.value - lowest.value) * step.value + 18)

const octaves = computed(() => {
	const list: number[] = []
	for (let c = Math.ceil(lowest.value / 12) * 12; c <= highest.value; c += 12) {
		list.push(c)
	}
	return list
})

const y = (pitch: number) => (highest.value - pitch) * step.value
const x = (index: number) => axis.value + columnWidth.value * index + columnWidth.value / 2

function fitLabel(fit: Fit): string {
	switch (fit) {
	case 'comfortable':
		return t('cantoria', 'comfortable')
	case 'possible':
		return t('cantoria', 'possible')
	default:
		return t('cantoria', 'out of range')
	}
}
</script>

<style scoped>
.ambitus {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px 20px;
	margin: 0;
}

.ambitus__svg {
	flex: none;
	overflow: visible;
}

.ambitus__grid {
	stroke: var(--color-border);
	stroke-dasharray: 2 3;
}

.ambitus__axis,
.ambitus__letter {
	fill: var(--color-text-maxcontrast);
	font-size: 10px;
}

.ambitus__letter {
	font-weight: 700;
}

.ambitus__voice {
	--voice: var(--cantoria-voice-none);
}

.ambitus__voice--s {
	--voice: var(--cantoria-voice-s);
}

.ambitus__voice--a {
	--voice: var(--cantoria-voice-a);
}

.ambitus__voice--t {
	--voice: var(--cantoria-voice-t);
}

.ambitus__voice--b {
	--voice: var(--cantoria-voice-b);
}

.ambitus__voice .ambitus__letter {
	fill: var(--voice);
}

.ambitus__limit {
	fill: none;
	stroke: var(--voice);
	stroke-opacity: 0.5;
	stroke-dasharray: 3 2;
}

.ambitus__comfort {
	fill: var(--voice);
	fill-opacity: 0.14;
}

.ambitus__range {
	stroke: var(--voice);
	stroke-width: 2;
}

.ambitus__tessitura {
	fill: var(--voice);
}

.ambitus__outside {
	stroke: var(--color-main-text);
	stroke-width: 1;
}

.ambitus__hatch {
	stroke: var(--color-main-background);
	stroke-width: 2;
}

.ambitus__end {
	fill: var(--color-main-background);
	stroke: var(--voice);
	stroke-width: 2;
}

.ambitus__list {
	display: grid;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.ambitus__list li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.ambitus__tones {
	font-variant-numeric: tabular-nums;
}

.ambitus__fit {
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.85em;
}

.ambitus__fit--comfortable {
	color: var(--color-success-text, var(--color-success));
}

.ambitus__fit--possible {
	color: var(--color-warning-text, var(--color-warning));
}

.ambitus__fit--out {
	border: 1px solid currentcolor;
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.ambitus--compact .ambitus__caption {
	display: none;
}

@media (forced-colors: active) {
	.ambitus__comfort {
		fill: Highlight;
	}

	.ambitus__tessitura {
		fill: CanvasText;
	}
}
</style>
