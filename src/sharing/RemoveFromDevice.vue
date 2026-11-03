<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * At the top of "Shared with me" and of the concert folder, while pieces are
 * kept on this device (S N6): how many, and a way to remove them that does
 * not wait at the end of the page, for a shared device. The button opens
 * the confirmation of "Delete everything on this device" in the section
 * "Without a network"; the page moves the focus there.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import { n, t } from '../l10n.ts'

defineProps<{
	/** pieces kept on this device */
	count: number
}>()

const emit = defineEmits<{
	/** open the confirmation of the removal */
	remove: []
}>()
</script>

<template>
	<p class="remove-from-device no-print">
		<span>{{ n('cantoria', '%n piece is available without a network.', '%n pieces are available without a network.', count) }}</span>
		<NcButton variant="tertiary" @click="emit('remove')">
			{{ t('cantoria', 'Remove from this device') }}
		</NcButton>
	</p>
</template>

<style scoped>
.remove-from-device {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}
</style>
