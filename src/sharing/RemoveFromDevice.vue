<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Delete everything on this device" on "Shared with me" and in the concert
 * folder: one name and one confirmation for one action (U43, D36), at the
 * top of the page while pieces are kept (for a shared device, S N6) and in
 * the section "Without a network". The confirmation opens right below the
 * button that was pressed, not at the end of the page, and says what goes
 * besides the pieces: remembered passages and the settings for practising.
 * The focus never gets lost (D28): on the confirming button when it opens,
 * back on the button after "Cancel"; after deleting, the page moves it.
 */
import type { FocusTarget } from '../focus.ts'

import { ref, useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { focusAfterUpdate } from '../focus.ts'
import { n, t } from '../l10n.ts'

const props = withDefaults(defineProps<{
	/** pieces kept on this device, said beside the button; null says nothing */
	count?: number | null
	/** what goes besides the pieces, in one sentence */
	consequence: string
	/** deletes everything; the confirmation closes afterwards */
	remove: () => Promise<void>
}>(), {
	count: null,
})

const emit = defineEmits<{
	/** everything is deleted: the page puts the focus to a sensible place */
	removed: []
}>()

const confirming = ref(false)
const busy = ref(false)
const startButton = ref<FocusTarget>(null)
const confirmButton = ref<FocusTarget>(null)
const consequenceId = useId()

function start(): void {
	confirming.value = true
	focusAfterUpdate(confirmButton)
}

function cancel(): void {
	confirming.value = false
	focusAfterUpdate(startButton)
}

async function confirm(): Promise<void> {
	busy.value = true
	try {
		await props.remove()
		confirming.value = false
		emit('removed')
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="remove-from-device no-print">
		<p v-if="count !== null || !confirming" class="remove-from-device__line">
			<span v-if="count !== null">{{ n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', count) }}</span>
			<NcButton v-if="!confirming"
				ref="startButton"
				variant="secondary"
				@click="start">
				{{ t('cantoria', 'Delete everything on this device') }}
			</NcButton>
		</p>
		<div v-if="confirming" class="remove-from-device__confirm">
			<p :id="consequenceId">
				{{ consequence }}
			</p>
			<div class="remove-from-device__buttons">
				<NcButton ref="confirmButton"
					variant="error"
					:disabled="busy"
					:aria-describedby="consequenceId"
					@click="confirm">
					{{ t('cantoria', 'Delete kept pieces, passages and settings') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="cancel">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style scoped>
.remove-from-device {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.remove-from-device p {
	margin: 0;
}

.remove-from-device__line,
.remove-from-device__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.remove-from-device__confirm {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding: calc(2 * var(--default-grid-baseline));
	border-inline-start: 4px solid var(--color-error);
	background-color: var(--color-background-hover);
}
</style>
