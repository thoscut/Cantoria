<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The next concert at the top of the start page (design proposal V6): its
 * date, the days until then and a ring that says how many of its pieces are
 * ready – in the archive and without a warning about rights, copies or the
 * valid version. The ring repeats the number in words; nothing depends on
 * its colour. Without a planned concert with a date nothing is shown.
 */
import type { ChoirEvent, EventItem } from '../types.ts'

import { mdiArrowRight, mdiCheckBold } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fetchEvent, fetchEvents } from '../api.ts'
import { formatDate, parseIsoDate, toIsoDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { navigate } from '../navigation.ts'
import { nextConcert, readiness } from '../nextConcert.ts'

const concert = ref<ChoirEvent | null>(null)
const items = ref<EventItem[]>([])

onMounted(async () => {
	try {
		const next = nextConcert(await fetchEvents('concert'), toIsoDate(new Date()))
		if (next) {
			items.value = (await fetchEvent(next.id)).items
			concert.value = next
		}
	} catch {
		// The start page works without this card
	}
})

const ready = computed(() => readiness(items.value))

const days = computed(() => {
	const date = concert.value?.event_date ? parseIsoDate(concert.value.event_date) : null
	const today = parseIsoDate(toIsoDate(new Date()))
	return date && today ? Math.round((date.getTime() - today.getTime()) / 86400000) : null
})

const daysText = computed(() => {
	if (days.value === null) {
		return ''
	}
	if (days.value === 0) {
		return t('cantoria', 'today')
	}
	return days.value === 1 ? t('cantoria', 'tomorrow') : n('cantoria', 'in %n day', 'in %n days', days.value)
})

/** The ring: circumference of a circle of radius 42 */
const RING = 2 * Math.PI * 42
const share = computed(() => ready.value.pieces > 0 ? ready.value.ready / ready.value.pieces : 0)
const complete = computed(() => ready.value.pieces > 0 && ready.value.ready === ready.value.pieces)
</script>

<template>
	<section v-if="concert" class="next-concert cantoria-appear" aria-labelledby="next-concert-title">
		<div class="next-concert__text">
			<p class="next-concert__eyebrow">
				{{ t('cantoria', 'Next concert') }}
				<strong v-if="daysText">{{ daysText }}</strong>
			</p>
			<h3 id="next-concert-title" class="next-concert__title">
				{{ concert.title || t('cantoria', '(no title)') }}
			</h3>
			<p class="next-concert__meta">
				<span v-if="concert.event_date">{{ formatDate(concert.event_date) }}</span>
				<span v-if="concert.start_time">{{ concert.start_time }}</span>
				<span v-if="concert.location">{{ concert.location }}</span>
			</p>
			<NcButton variant="primary" @click="navigate('event', { eventId: concert.id })">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowRight" />
				</template>
				{{ t('cantoria', 'Open the programme') }}
			</NcButton>
		</div>
		<div v-if="ready.pieces > 0" class="next-concert__ready">
			<svg class="next-concert__ring"
				viewBox="0 0 100 100"
				width="112"
				height="112"
				aria-hidden="true">
				<circle class="next-concert__track"
					cx="50"
					cy="50"
					r="42" />
				<circle class="next-concert__progress"
					cx="50"
					cy="50"
					r="42"
					:stroke-dasharray="`${share * RING} ${RING}`"
					transform="rotate(-90 50 50)" />
				<path v-if="complete"
					:d="mdiCheckBold"
					class="next-concert__check"
					transform="translate(32 32) scale(1.5)" />
				<text v-else
					x="50"
					y="58"
					text-anchor="middle"
					class="next-concert__number">{{ ready.ready }}/{{ ready.pieces }}</text>
			</svg>
			<p class="next-concert__ready-text">
				<strong>{{ t('cantoria', '{ready} of {pieces} pieces ready', ready) }}</strong>
				<span v-if="complete">{{ t('cantoria', 'Every piece is in the archive and has no warning.') }}</span>
				<span v-else>{{ n('cantoria', '%n piece still has a warning or is not in the archive.', '%n pieces still have a warning or are not in the archive.', ready.pieces - ready.ready) }}</span>
			</p>
		</div>
		<p v-else class="next-concert__ready-text">
			{{ t('cantoria', 'The programme has no pieces yet.') }}
		</p>
	</section>
</template>

<style scoped>
.next-concert {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(5 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container, 16px);
	background:
		radial-gradient(120% 140% at 100% 0%, var(--color-primary-element-light) 0%, transparent 60%),
		var(--color-main-background);
	box-shadow: var(--cantoria-shadow);
}

.next-concert__text {
	display: flex;
	flex: 1 1 18rem;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	min-inline-size: 0;
}

.next-concert__eyebrow {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(2 * var(--default-grid-baseline));
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.next-concert__eyebrow strong {
	color: var(--color-main-text);
}

.next-concert__title {
	margin: 0;
	font-size: clamp(1.4rem, 1rem + 1.6vw, 2.1rem);
	font-weight: 700;
	line-height: 1.15;
	overflow-wrap: anywhere;
}

.next-concert__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
	margin: 0 0 var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.next-concert__ready {
	display: flex;
	flex: 0 1 22rem;
	align-items: center;
	gap: calc(3 * var(--default-grid-baseline));
}

.next-concert__ring {
	flex: none;
}

.next-concert__track,
.next-concert__progress {
	fill: none;
	stroke-width: 9;
}

.next-concert__track {
	stroke: var(--color-background-darker);
}

.next-concert__progress {
	stroke: var(--color-primary-element);
	stroke-linecap: round;
	transition: stroke-dasharray var(--cantoria-motion) var(--cantoria-ease);
}

.next-concert__number {
	fill: var(--color-main-text);
	font-size: 22px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.next-concert__check {
	fill: var(--color-primary-element);
}

.next-concert__ready-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
}

.next-concert__ready-text span {
	color: var(--color-text-maxcontrast);
}

@media (forced-colors: active) {
	.next-concert__progress {
		stroke: Highlight;
	}

	.next-concert__track {
		stroke: GrayText;
	}
}

@media print {
	.next-concert {
		border: 0.3mm solid #000;
		background: none;
		box-shadow: none;
	}

	.next-concert__ring,
	.next-concert :deep(.button-vue) {
		display: none;
	}
}
</style>
