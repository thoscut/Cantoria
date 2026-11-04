<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The scan (PDF) of a piece inside the page (milestone 0.15): the
 * browser's own PDF viewer in a frame, from the browser's memory. So it
 * also opens without a network, where a new tab would fail (iPhone, iPad).
 * Shown after a click only; the memory is freed again when it is closed.
 * On a phone (upright or sideways) the scan is a view of its own on the
 * whole screen, not a small frame below the notes that hangs on the
 * browser's PDF viewer inside the page (U3, D8); "Back to the notes"
 * closes it, as do Escape and the back gesture of the phone: the view adds
 * an entry to the history (U39). Closed by the person, the focus goes back
 * to "Show the scan (PDF)" (D28).
 */
import type { FocusTarget } from '../focus.ts'
import type { CollectionName } from '../offline/rules.ts'

import { mdiArrowLeft } from '@mdi/js'
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { focusAfterUpdate } from '../focus.ts'
import { t } from '../l10n.ts'
import { scanInMemory } from '../offline/device.ts'
import { SMALL_SCREEN } from '../score/screen.ts'

const props = defineProps<{
	collection: CollectionName
	/** token of the link or id of the programme item */
	pieceKey: string
	title: string
}>()

const frameId = useId()
const viewHeadingId = useId()
const address = ref<string | null>(null)
/** On a phone the scan is a view of its own */
const asView = ref(window.matchMedia?.(SMALL_SCREEN).matches ?? false)
const loading = ref(false)
const error = ref('')
const openButton = ref<FocusTarget>(null)
/** The view on a phone added an entry to the history, which the back gesture takes */
let inHistory = false

/** Free the memory of the shown scan. */
function close(): void {
	if (address.value !== null) {
		URL.revokeObjectURL(address.value)
	}
	address.value = null
}

/** Whether the entry of the history is the one the view added. */
function viewEntry(): boolean {
	return (window.history.state as { cantoriaScan?: unknown } | null)?.cantoriaScan === props.pieceKey
}

/** Closed by the person: the focus back on the button that opened it. */
function closed(): void {
	inHistory = false
	close()
	focusAfterUpdate(openButton)
}

/** "Back to the notes", "Close the scan", Escape: the entry of the view goes, too. */
function shut(): void {
	if (inHistory && viewEntry()) {
		// the back gesture's way: popstate closes the view
		window.history.back()
		return
	}
	closed()
}

/** The back gesture (or Back of the browser) while the view is open. */
function onPopState(): void {
	if (inHistory && !viewEntry()) {
		closed()
	}
}

async function open(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		close()
		asView.value = window.matchMedia?.(SMALL_SCREEN).matches ?? false
		address.value = await scanInMemory(props.collection, props.pieceKey)
		if (asView.value && !inHistory) {
			// the same address: the page keeps its piece, only the view closes on Back
			window.history.pushState({ ...(window.history.state ?? {}), cantoriaScan: props.pieceKey }, '', window.location.href)
			inHistory = true
		}
	} catch (reason) {
		error.value = reason instanceof Error && reason.message !== ''
			? reason.message
			: t('cantoria', 'The scan could not be loaded. Without a network, only a scan kept on this device opens.')
	} finally {
		loading.value = false
	}
}

watch(() => [props.collection, props.pieceKey], close)
onMounted(() => window.addEventListener('popstate', onPopState))
onBeforeUnmount(() => {
	window.removeEventListener('popstate', onPopState)
	close()
})
</script>

<template>
	<div class="scan-frame">
		<div class="scan-frame__buttons">
			<NcButton v-if="address === null"
				ref="openButton"
				variant="secondary"
				:aria-controls="frameId"
				:disabled="loading"
				@click="open">
				{{ loading ? t('cantoria', 'Loading the scan …') : t('cantoria', 'Show the scan (PDF)') }}
			</NcButton>
			<template v-else-if="!asView">
				<NcButton variant="secondary" :aria-controls="frameId" @click="shut">
					{{ t('cantoria', 'Close the scan') }}
				</NcButton>
				<a :href="address"
					class="scan-frame__link"
					target="_blank"
					rel="noopener noreferrer">{{ t('cantoria', 'Open the scan (PDF)') }}<span class="hidden-visually"> {{ t('cantoria', '(opens in a new tab)') }}</span></a>
			</template>
		</div>
		<NcNoteCard v-if="error" type="warning" role="alert">
			{{ error }}
		</NcNoteCard>
		<iframe v-if="address !== null && !asView"
			:id="frameId"
			class="scan-frame__frame"
			:src="address"
			:title="t('cantoria', 'Scan of {title}', { title })" />
		<NcModal v-if="address !== null && asView"
			size="full"
			:label-id="viewHeadingId"
			@close="shut">
			<div class="scan-frame__view">
				<div class="scan-frame__view-bar">
					<NcButton variant="secondary" @click="shut">
						<template #icon>
							<NcIconSvgWrapper :path="mdiArrowLeft" />
						</template>
						{{ t('cantoria', 'Back to the notes') }}
					</NcButton>
					<h2 :id="viewHeadingId" class="scan-frame__view-title">
						{{ t('cantoria', 'Scan of {title}', { title }) }}
					</h2>
					<a :href="address"
						class="scan-frame__link"
						target="_blank"
						rel="noopener noreferrer">{{ t('cantoria', 'Open the scan (PDF)') }}<span class="hidden-visually"> {{ t('cantoria', '(opens in a new tab)') }}</span></a>
				</div>
				<iframe :id="frameId"
					class="scan-frame__view-frame"
					:src="address"
					:title="t('cantoria', 'Scan of {title}', { title })" />
			</div>
		</NcModal>
	</div>
</template>

<style scoped>
.scan-frame {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.scan-frame__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.scan-frame__link {
	display: inline-block;
	min-height: var(--default-clickable-area);
	line-height: var(--default-clickable-area);
	color: var(--color-main-text);
	text-decoration: underline;
}

.scan-frame__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scan-frame__frame {
	width: 100%;
	height: 80vh;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

/* A phone: the scan fills the screen below a bar with the way back */
.scan-frame__view {
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	height: 100%;
	padding: var(--default-grid-baseline);
	gap: var(--default-grid-baseline);
}

.scan-frame__view-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	/* room for the close button of the modal */
	padding-inline-end: calc(var(--default-clickable-area) + var(--default-grid-baseline));
}

.scan-frame__view-title {
	flex: 1 1 10em;
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.scan-frame__view-frame {
	flex: 1 1 auto;
	width: 100%;
	min-height: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
</style>
