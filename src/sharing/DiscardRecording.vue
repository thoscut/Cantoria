<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Asks before another piece replaces the shown one while a recording of
 * oneself is not saved (milestone 0.19): the view of each piece is new, and
 * the recording would be gone without a word.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { t } from '../l10n.ts'

defineProps<{
	/** the piece that would be opened */
	next: string
}>()

const emit = defineEmits<{
	/** open the other piece; the recording is gone */
	discard: []
	/** stay with the recording */
	stay: []
}>()
</script>

<template>
	<NcDialog :name="t('cantoria', 'Discard the recording?')"
		:open="true"
		size="small"
		@update:open="(open: boolean) => open || emit('stay')">
		<p>
			{{ t('cantoria', 'Your recording is not saved. If you open "{title}" now, it is gone.', { title: next }) }}
		</p>
		<template #actions>
			<NcButton variant="tertiary" @click="emit('discard')">
				{{ t('cantoria', 'Discard and open') }}
			</NcButton>
			<NcButton variant="primary" @click="emit('stay')">
				{{ t('cantoria', 'Keep the recording') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
