<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span v-if="text" class="fit-badge" :class="`fit-badge--${level}`">
		<NcIconSvgWrapper :path="icon" :size="14" inline />
		<span>{{ text }}</span>
	</span>
</template>

<script setup lang="ts">
/**
 * How a piece fits the ranges of the choir profile, in one short label with
 * a symbol: fits comfortably, possible, fits a few semitones higher or
 * lower, or out of range. The symbol differs for each, so the colour never
 * carries the information alone.
 */
import type { PieceFit } from '../scoreFacts.ts'

import { mdiArrowUpDown, mdiCheckCircleOutline, mdiAlertOutline, mdiCloseCircleOutline } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { transposeLabel } from '../scoreFactsLabels.ts'

const props = defineProps<{
	fit: PieceFit | null | undefined
}>()

const level = computed(() => {
	const fit = props.fit
	if (!fit || fit.fit === null) {
		return 'none'
	}
	if (fit.fit === 'out' && fit.transpose !== null && fit.transpose !== 0 && fit.transposedFit !== 'out') {
		return 'transposed'
	}
	return fit.fit
})

const text = computed(() => {
	const fit = props.fit
	switch (level.value) {
	case 'comfortable':
		return t('cantoria', 'Fits our ranges')
	case 'possible':
		return t('cantoria', 'Possible in our ranges')
	case 'transposed':
		return t('cantoria', 'Fits {transposition}', { transposition: transposeLabel(fit?.transpose ?? 0) })
	case 'out':
		return t('cantoria', 'Out of our ranges')
	default:
		return ''
	}
})

const icon = computed(() => {
	switch (level.value) {
	case 'comfortable':
		return mdiCheckCircleOutline
	case 'possible':
		return mdiAlertOutline
	case 'transposed':
		return mdiArrowUpDown
	default:
		return mdiCloseCircleOutline
	}
})
</script>

<style scoped>
.fit-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 0 8px 0 6px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.85em;
	line-height: 1.6;
	white-space: nowrap;
	color: var(--color-main-text);
	background: color-mix(in srgb, var(--fit) 12%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--fit) 45%, transparent);
}

.fit-badge :deep(svg) {
	color: var(--fit);
}

.fit-badge--comfortable {
	--fit: var(--color-success-text, var(--color-success));
}

.fit-badge--possible,
.fit-badge--transposed {
	--fit: var(--color-warning-text, var(--color-warning));
}

.fit-badge--out {
	--fit: var(--color-error-text, var(--color-error));
}

@media (forced-colors: active) {
	.fit-badge {
		border-color: CanvasText;
	}
}
</style>
