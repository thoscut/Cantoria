<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * A dialog with a form for one action (confirm rights, answer a task, …):
 * the fields come in the default slot, Cancel and the action button below.
 * Required fields use the browser's validation; server errors appear above
 * the buttons and are announced.
 */
import { t } from '../l10n.ts'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const props = withDefaults(defineProps<{
	name: string
	submitLabel: string
	busy?: boolean
	error?: string
	submitDisabled?: boolean
	size?: 'small' | 'normal' | 'large'
	/** 'error' for actions that cannot easily be undone */
	variant?: 'primary' | 'error'
	/** the action is done and the dialog shows its result: Cancel is gone */
	done?: boolean
}>(), {
	busy: false,
	error: '',
	submitDisabled: false,
	size: 'normal',
	variant: 'primary',
	done: false,
})

const emit = defineEmits<{
	submit: []
	close: []
}>()

/**
 * The form was submitted (button or Enter in a field).
 *
 * @param event - submit event of the form
 */
function onSubmit(event: Event): void {
	event.preventDefault()
	if (!props.busy && !props.submitDisabled) {
		emit('submit')
	}
}

/**
 * The dialog asks to close (Escape, close button).
 *
 * @param open - new open state
 */
function onUpdateOpen(open: boolean): void {
	if (!open && !props.busy) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="name"
		:open="true"
		:size="size"
		is-form
		:no-close="busy"
		@submit="onSubmit"
		@update:open="onUpdateOpen">
		<div class="action-dialog">
			<slot />
			<NcNoteCard v-if="error !== ''" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton v-if="!done" :disabled="busy" @click="emit('close')">
				{{ t('cantoria', 'Cancel') }}
			</NcButton>
			<NcButton type="submit" :variant="variant" :disabled="busy || submitDisabled">
				<template v-if="busy" #icon>
					<NcLoadingIcon />
				</template>
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.action-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
}

.action-dialog :deep(.action-dialog__hint) {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
