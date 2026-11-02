<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * One way to keep pieces for use without a network on all three pages
 * ("Shared with me", the concert folder, a public link; milestone 0.19):
 * a switch "Available without a network" and below it a switch for the
 * scan that can be changed at any time, before or after. What happens,
 * the progress and an error stand right under the switches, not at the end
 * of the page. Before the first piece is kept on an iPhone or iPad, a hint
 * says to put the page on the home screen first: a page opened from there
 * keeps its own pieces, apart from Safari.
 */
import { mdiAlertCircleOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { formatSize } from '../format.ts'
import { t } from '../l10n.ts'
import { homeScreenAdvice } from '../offline/platform.ts'

const props = withDefaults(defineProps<{
	/** the piece, or every piece of the programme, is kept */
	kept: boolean
	/** the scans are kept, too */
	scanKept: boolean
	/** there is a scan that may be kept */
	scanOffered: boolean
	/** size of the scans in bytes, when known */
	scanSize?: number | null
	/** a whole programme: "the scans" */
	several?: boolean
	/** the rights allow keeping it */
	allowed: boolean
	/** why the rights do not allow it */
	denial?: string
	/** no network: nothing can be changed */
	withoutNetwork: boolean
	/** this switch is at work */
	busy: boolean
	/** another switch is at work */
	locked?: boolean
	/** e.g. "3 of 12 pieces ready …" */
	progress?: string
	/** why the last change did not work */
	error?: string
	/** why the kept piece does not open without a network, or '' */
	lock?: string
	/** nothing is kept on this device yet: time for the hint about the home screen */
	firstKeep?: boolean
	/** label of the main switch */
	label?: string
}>(), {
	scanSize: null,
	several: false,
	denial: '',
	locked: false,
	progress: '',
	error: '',
	lock: '',
	firstKeep: false,
	label: '',
})

const emit = defineEmits<{
	/** keep (with or without the scans) or remove */
	change: [keep: boolean, withScan: boolean]
}>()

/** the scan switch before the piece is kept */
const wantScan = ref(false)
const scanShown = computed(() => props.kept ? props.scanKept : wantScan.value)
const disabled = computed(() => props.withoutNetwork || props.busy || props.locked)
const homeScreen = homeScreenAdvice()

const scanLabel = computed(() => {
	const size = props.scanSize !== null && props.scanSize > 0 ? formatSize(props.scanSize) : ''
	if (props.several) {
		return size === '' ? t('cantoria', 'With the scans (PDF)') : t('cantoria', 'With the scans (PDF, {size})', { size })
	}
	return size === '' ? t('cantoria', 'With the scan (PDF)') : t('cantoria', 'With the scan (PDF, {size})', { size })
})

/**
 * The main switch.
 *
 * @param on - keep it
 */
function setKept(on: boolean): void {
	emit('change', on, on && wantScan.value)
}

/**
 * The scan switch: before keeping it only remembers the choice.
 *
 * @param on - with the scan
 */
function setScan(on: boolean): void {
	wantScan.value = on
	if (props.kept) {
		emit('change', true, on)
	}
}
</script>

<template>
	<div class="keep-offline">
		<p v-if="!allowed" class="keep-offline__muted">
			{{ denial || t('cantoria', 'Not available without a network: the rights do not allow downloading it.') }}
		</p>
		<template v-else>
			<NcNoteCard v-if="homeScreen && firstKeep && !kept" type="info" class="keep-offline__hint">
				{{ t('cantoria', 'On an iPhone or iPad: first add this page to the home screen, open it from there, sign in and then make the pieces available. Safari and the home screen keep them apart.') }}
			</NcNoteCard>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="kept"
				:disabled="disabled"
				:loading="busy"
				@update:model-value="setKept">
				{{ label || t('cantoria', 'Available without a network') }}
				<template v-if="progress || lock || withoutNetwork" #description>
					{{ progress || lock || (kept ? '' : t('cantoria', 'Needs a network to change.')) }}
				</template>
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-if="scanOffered"
				type="switch"
				class="keep-offline__scan"
				:model-value="scanShown"
				:disabled="disabled"
				@update:model-value="setScan">
				{{ scanLabel }}
			</NcCheckboxRadioSwitch>
			<p v-if="error" class="keep-offline__error" role="alert">
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="20" aria-hidden="true" />
				<span>{{ error }}</span>
			</p>
		</template>
	</div>
</template>

<style scoped>
.keep-offline {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.keep-offline p {
	margin: 0;
}

/* the scan belongs to the switch above it */
.keep-offline__scan {
	margin-inline-start: calc(4 * var(--default-grid-baseline));
}

.keep-offline__hint {
	margin: 0;
}

.keep-offline__muted {
	color: var(--color-text-maxcontrast);
}

.keep-offline__error {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
