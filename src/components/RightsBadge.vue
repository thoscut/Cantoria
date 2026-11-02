<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Rights indicator ("Rechte-Ampel"): derived from the version, display
 * only. Color plus icon plus text, so it does not rely on color alone.
 */
import type { RightsLevel } from '../catalog.ts'

import {
	mdiAlertOutline,
	mdiCheckCircleOutline,
	mdiHelpCircleOutline,
	mdiLockOutline,
	mdiMinusCircleOutline,
	mdiShieldLockOutline,
} from '@mdi/js'
import { t } from '../l10n.ts'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { rightsLevel } from '../catalog.ts'

const props = defineProps<{
	rightsStatus: unknown
	rightsConfirmed: unknown
	preservationCopy: unknown
}>()

const level = computed<RightsLevel>(() => rightsLevel(props.rightsStatus, props.rightsConfirmed, props.preservationCopy))

const display = computed(() => {
	switch (level.value) {
	case 'preservation':
		return { text: t('cantoria', 'Internal preservation copy – do not share'), icon: mdiShieldLockOutline, tone: 'error' }
	case 'protected':
		// Protected is no error: the lock and the word say it, in the tone of information
		return { text: t('cantoria', 'protected'), icon: mdiLockOutline, tone: 'info' }
	case 'open':
		return { text: t('cantoria', 'open'), icon: mdiHelpCircleOutline, tone: 'neutral' }
	case 'unconfirmed':
		return { text: t('cantoria', 'public domain (suggestion, unconfirmed)'), icon: mdiAlertOutline, tone: 'warning' }
	case 'confirmed':
		return { text: t('cantoria', 'public domain'), icon: mdiCheckCircleOutline, tone: 'success' }
	default:
		return { text: t('cantoria', 'not assessed'), icon: mdiMinusCircleOutline, tone: 'neutral' }
	}
})
</script>

<template>
	<span class="rights-badge" :class="`rights-badge--${display.tone}`">
		<!-- inline: without it the icon keeps the size of a clickable area and every badge grows to 34 px -->
		<NcIconSvgWrapper class="rights-badge__icon"
			:path="display.icon"
			:size="16"
			inline />
		<span class="rights-badge__text">{{ display.text }}</span>
	</span>
</template>

<style scoped>
.rights-badge {
	--badge-color: var(--color-text-maxcontrast);
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	max-width: 100%;
	padding: 2px calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--badge-color);
	border-radius: var(--border-radius-pill, 999px);
	background-color: color-mix(in srgb, var(--badge-color) 12%, var(--color-main-background));
	color: var(--color-main-text);
	font-size: var(--font-size-small, 13px);
	line-height: 1.4;
}

.rights-badge--error {
	--badge-color: var(--color-error-text, var(--color-error));
}

.rights-badge--info {
	--badge-color: var(--color-info-text, var(--color-info, #00679e));
}

.rights-badge--warning {
	--badge-color: var(--color-warning-text, var(--color-warning));
}

.rights-badge--success {
	--badge-color: var(--color-success-text, var(--color-success));
}

.rights-badge__icon {
	flex-shrink: 0;
	align-self: center;
	color: var(--badge-color);
	vertical-align: middle;
}

.rights-badge__text {
	overflow-wrap: anywhere;
}

/* Windows contrast themes: system colours, the frame stays */
@media (forced-colors: active) {
	.rights-badge {
		border-color: CanvasText;
		background-color: Canvas;
		color: CanvasText;
	}

	.rights-badge__icon {
		color: CanvasText;
	}
}
</style>
