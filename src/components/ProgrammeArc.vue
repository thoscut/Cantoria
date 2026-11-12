<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="arc cantoria-appear" aria-labelledby="programme-arc-title">
		<h3 id="programme-arc-title" class="arc__title">
			{{ t('cantoria', 'The programme at a glance') }}
		</h3>
		<ol class="arc__blocks" :class="{ 'arc__blocks--editable': editable }">
			<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- dragging is a pointer shortcut; the arrows in the list below do the same for the keyboard -->
			<li v-for="(block, index) in blocks"
				:key="block.item.id"
				class="arc__piece"
				:class="{
					'arc__piece--encore': block.item.encore,
					'arc__piece--dragging': dragged === index,
					'arc__piece--target': target === index && dragged !== index,
				}"
				:style="{ flexGrow: block.weight }"
				:draggable="editable"
				@dragstart="onDragStart($event, index)"
				@dragover.prevent="target = index"
				@dragleave="target = target === index ? null : target"
				@drop.prevent="onDrop(index)"
				@dragend="dragged = null; target = null">
				<span v-if="block.transition"
					class="arc__transition"
					:class="`arc__transition--${block.transition}`"
					:title="transitionLabel(block.transition)">
					<span aria-hidden="true">{{ transitionSign(block.transition) }}</span>
					<span class="hidden-visually">{{ transitionLabel(block.transition) }}</span>
				</span>
				<button type="button"
					class="arc__block"
					:aria-label="blockLabel(block, index)"
					@click="emit('jump', block.item.id)">
					<span class="arc__time" aria-hidden="true">
						{{ block.start ?? `${index + 1}.` }}
					</span>
					<span class="arc__name" aria-hidden="true">{{ block.title }}</span>
					<span v-if="block.key" class="arc__key" aria-hidden="true">{{ block.key }}</span>
					<span v-if="block.warnings > 0" class="arc__warning" aria-hidden="true">
						<NcIconSvgWrapper :path="mdiAlertOutline" :size="16" inline />
						{{ block.warnings }}
					</span>
				</button>
			</li>
		</ol>
		<p class="arc__summary">
			<span>{{ t('cantoria', 'Total duration: {duration}', { duration: formatDuration(totalSeconds) || '0:00' }) }}</span>
			<span v-if="endTime">{{ t('cantoria', 'ends about {time}', { time: endTime }) }}</span>
			<span v-if="guessed > 0">{{ n('cantoria', 'estimated for %n piece without a duration', 'estimated for %n pieces without a duration', guessed) }}</span>
			<span v-if="farSteps > 0">{{ n('cantoria', '%n new tone to give between pieces', '%n new tones to give between pieces', farSteps) }}</span>
		</p>
		<p v-if="editable && blocks.length > 1" class="arc__hint">
			{{ t('cantoria', 'Drag a piece to a new place, or use the arrows in the list below.') }}
		</p>
		<div v-if="warned.length > 0" class="arc__warnings">
			<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" />
			<p>
				{{ n('cantoria', '%n piece needs attention:', '%n pieces need attention:', warned.length) }}
			</p>
			<ul class="arc__warned">
				<li v-for="block in warned" :key="block.item.id">
					<a :href="`#event-item-${block.item.id}`" @click.prevent="emit('jump', block.item.id)">{{ block.title }}</a>
				</li>
			</ul>
		</div>
		<ul class="arc__legend" :aria-label="t('cantoria', 'Signs between the pieces')">
			<li v-for="kind in legend" :key="kind">
				<span class="arc__transition" :class="`arc__transition--${kind}`" aria-hidden="true">{{ transitionSign(kind) }}</span>
				{{ transitionLabel(kind) }}
			</li>
		</ul>
	</section>
</template>

<script setup lang="ts">
/**
 * The programme as a band (design proposal V4): one block per piece, as wide
 * as the piece is long, with the approximate start time, the key and the
 * number of warnings; between the blocks a sign for how the key of one piece
 * leads into the next. Planners drag a block to a new place; the arrows in
 * the list below do the same for the keyboard. Struck pieces are left out.
 */
import type { EventItem } from '../types.ts'
import type { PitchPiece, Transition } from '../scoreFacts.ts'

import { mdiAlertOutline } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { formatDuration } from '../durations.ts'
import { n, t } from '../l10n.ts'
import { keyName, transition } from '../scoreFacts.ts'
import { modeWords, transitionLabel } from '../scoreFactsLabels.ts'

const props = defineProps<{
	items: EventItem[]
	/** facts per item id, from the pitch sheet; empty without the module */
	pitches: Map<number, PitchPiece>
	/** HH:MM of the start of the concert */
	startTime: string | null
	editable: boolean
}>()

const emit = defineEmits<{
	/** go to an item of the list */
	jump: [itemId: number]
	/** the new order of all items (struck ones keep their place at the end of the order) */
	reorder: [order: number[]]
}>()

/** Length assumed for a piece without a duration */
const GUESS = 180

const german = getLanguage().toLowerCase().startsWith('de')
const words = modeWords()

const shown = computed(() => props.items.filter((item) => item.struck !== true))

const known = computed(() => shown.value.map((item) => item.duration).filter((value): value is number => typeof value === 'number' && value > 0))
const average = computed(() => known.value.length > 0 ? known.value.reduce((a, b) => a + b, 0) / known.value.length : GUESS)
const guessed = computed(() => shown.value.length - known.value.length)

interface Block {
	item: EventItem
	title: string
	seconds: number
	weight: number
	start: string | null
	key: string | null
	transition: Transition | null
	warnings: number
}

/**
 * HH:MM plus some seconds.
 *
 * @param time - HH:MM
 * @param seconds - seconds later
 */
function later(time: string, seconds: number): string | null {
	const match = /^(\d{1,2}):(\d{2})/.exec(time)
	if (!match) {
		return null
	}
	const minutes = Number(match[1]) * 60 + Number(match[2]) + Math.round(seconds / 60)
	return `${String(Math.floor(minutes / 60) % 24).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

const blocks = computed<Block[]>(() => {
	let elapsed = 0
	let previous: PitchPiece | null = null
	return shown.value.map((item) => {
		const seconds = typeof item.duration === 'number' && item.duration > 0 ? item.duration : average.value
		const piece = props.pitches.get(item.id) ?? null
		const start = props.startTime ? later(props.startTime, elapsed) : null
		elapsed += seconds
		const keyStart = piece?.facts?.keyStart ?? null
		const before = previous?.facts?.keyEnd ?? null
		const block: Block = {
			item,
			title: item.title || item.title_text || t('cantoria', '(no title)'),
			seconds,
			// Very short pieces stay wide enough for their number
			weight: Math.max(40, seconds),
			start,
			key: keyStart ? keyName(keyStart, german, words) : null,
			transition: keyStart && before ? transition(before, keyStart) : null,
			warnings: item.warnings?.length ?? 0,
		}
		previous = piece
		return block
	})
})

const totalSeconds = computed(() => blocks.value.reduce((sum, block) => sum + block.seconds, 0))
const endTime = computed(() => props.startTime && blocks.value.length > 0 ? later(props.startTime, totalSeconds.value) : null)
const warned = computed(() => blocks.value.filter((block) => block.warnings > 0))
const farSteps = computed(() => blocks.value.filter((block) => block.transition === 'near' || block.transition === 'far').length)
const legend = computed(() => (['same', 'relative', 'near', 'far'] as Transition[])
	.filter((kind) => blocks.value.some((block) => block.transition === kind)))

/**
 * The sign for a transition: the shape carries it, not only the colour.
 *
 * @param kind - the transition
 */
function transitionSign(kind: Transition): string {
	switch (kind) {
	case 'same':
		return '='
	case 'relative':
		return '≈'
	case 'near':
		return '↗'
	default:
		return '♪'
	}
}

/**
 * What a block says for screen readers.
 *
 * @param block - the block
 * @param index - its position
 */
function blockLabel(block: Block, index: number): string {
	const parts = [`${index + 1}. ${block.title}`]
	if (block.start) {
		parts.push(t('cantoria', 'about {time}', { time: block.start }))
	}
	parts.push(formatDuration(Math.round(block.seconds)))
	if (block.key) {
		parts.push(block.key)
	}
	if (block.warnings > 0) {
		parts.push(n('cantoria', '%n warning', '%n warnings', block.warnings))
	}
	return parts.join(', ')
}

// ---- dragging ----

const dragged = ref<number | null>(null)
const target = ref<number | null>(null)

/**
 * @param event - the drag event
 * @param index - the block dragged
 */
function onDragStart(event: DragEvent, index: number): void {
	if (!props.editable) {
		event.preventDefault()
		return
	}
	dragged.value = index
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
		event.dataTransfer.setData('text/plain', String(shown.value[index].id))
	}
}

/**
 * Put the dragged piece at the place of another.
 *
 * @param index - the block dropped on
 */
function onDrop(index: number): void {
	const from = dragged.value
	dragged.value = null
	target.value = null
	if (from === null || from === index || !props.editable) {
		return
	}
	const order = shown.value.map((item) => item.id)
	const [moved] = order.splice(from, 1)
	order.splice(index, 0, moved)
	// Struck pieces keep their places after the others
	const struck = props.items.filter((item) => item.struck === true).map((item) => item.id)
	emit('reorder', [...order, ...struck])
}
</script>

<style scoped>
.arc {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.arc__title {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.arc__blocks {
	display: flex;
	gap: 0;
	margin: 0;
	padding: 0;
	overflow-x: auto;
	list-style: none;
	scroll-snap-type: x proximity;
}

.arc__piece {
	position: relative;
	display: flex;
	flex: 1 1 0;
	min-inline-size: 6.5rem;
	padding-inline: 10px 2px;
	scroll-snap-align: start;
	transition: opacity var(--cantoria-motion) var(--cantoria-ease);
}

.arc__blocks--editable .arc__piece {
	cursor: grab;
}

.arc__piece--dragging {
	opacity: 0.45;
}

.arc__piece--target .arc__block {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: 2px;
}

.arc__block {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	min-inline-size: 0;
	margin: 0;
	padding: 8px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 10px);
	background: linear-gradient(180deg, var(--color-primary-element-light) 0%, var(--color-main-background) 100%);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
	box-shadow: var(--cantoria-shadow);
	transition: transform var(--cantoria-motion) var(--cantoria-ease);
}

.arc__block:hover {
	transform: translateY(-2px);
}

.arc__block:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.arc__piece--encore .arc__block {
	border-style: dashed;
}

.arc__time {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	font-variant-numeric: tabular-nums;
}

.arc__name {
	max-inline-size: 100%;
	overflow: hidden;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.arc__key {
	font-size: var(--font-size-small, 13px);
}

.arc__warning {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	color: var(--color-warning-text, var(--color-warning));
	font-size: var(--font-size-small, 13px);
	font-weight: 600;
}

.arc__transition {
	position: absolute;
	inset-block-start: 50%;
	inset-inline-start: -9px;
	z-index: 1;
	display: inline-grid;
	place-items: center;
	inline-size: 18px;
	block-size: 18px;
	border: 1px solid currentcolor;
	border-radius: 50%;
	background: var(--color-main-background);
	font-size: 12px;
	font-weight: 700;
	line-height: 1;
	transform: translateY(-50%);
}

.arc__legend .arc__transition {
	position: static;
	transform: none;
}

.arc__transition--same,
.arc__transition--relative {
	color: var(--color-success-text, var(--color-success));
}

.arc__transition--near {
	color: var(--color-text-maxcontrast);
}

.arc__transition--far {
	color: var(--color-warning-text, var(--color-warning));
}

.arc__summary,
.arc__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 4px calc(3 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-text-maxcontrast);
}

.arc__summary span:first-child {
	color: var(--color-main-text);
	font-weight: 600;
}

.arc__legend li {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: var(--font-size-small, 13px);
}

.arc__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.arc__warnings {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
}

.arc__warnings {
	flex-wrap: wrap;
}

.arc__warnings p {
	margin: 0;
}

.arc__warned {
	display: flex;
	flex-wrap: wrap;
	gap: 0 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.arc__warned li:not(:last-child)::after {
	content: ',';
}

.arc__warnings a {
	text-decoration: underline;
}

.arc__warnings :deep(.icon-vue) {
	flex-shrink: 0;
	color: var(--color-warning-text, var(--color-warning));
}

@media (forced-colors: active) {
	.arc__block {
		background: Canvas;
	}
}
</style>
