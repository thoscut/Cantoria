<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * A list was shown, and loading it again failed (a filter switched, a
 * decision made): the old list stays, and this says so, with "Try again".
 * Without it the switch would show the new filter over the old list.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { t } from '../l10n.ts'

defineProps<{
	/** the message of the failed load */
	error: string
}>()

const emit = defineEmits<{
	retry: []
}>()
</script>

<template>
	<NcNoteCard type="error" role="alert" class="reload-error no-print">
		<p>{{ t('cantoria', 'The list could not be updated and may be out of date: {error}', { error }) }}</p>
		<NcButton variant="secondary" @click="emit('retry')">
			{{ t('cantoria', 'Try again') }}
		</NcButton>
	</NcNoteCard>
</template>

<style scoped>
.reload-error p {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}
</style>
