<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings "Repertoire": when a work counts as current repertoire and when
 * as dormant (module "repertoire").
 */
import type { RepertoireThresholds } from '../../types.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../../l10n.ts'
import { reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, saveRepertoireThresholds } from '../../api.ts'
import { parseThresholds } from '../../settings.ts'

const props = defineProps<{
	thresholds: RepertoireThresholds
}>()

const emit = defineEmits<{
	/** the thresholds were saved, as the server has them now */
	saved: [thresholds: RepertoireThresholds]
}>()

const form = reactive({ current: '', dormant: '', referenceYear: '' })
const saving = ref(false)
const error = ref('')

watch(() => props.thresholds, (value) => {
	form.current = String(value.current)
	form.dormant = String(value.dormant)
	form.referenceYear = value.referenceYear === 0 ? '' : String(value.referenceYear)
}, { immediate: true })

async function save(): Promise<void> {
	const thresholds = parseThresholds(form.current, form.dormant, form.referenceYear)
	if (thresholds === null) {
		error.value = t('cantoria', 'Please enter whole numbers of years; "dormant" must be at least as long as "current". The reference year is a four-digit year or empty.')
		return
	}
	saving.value = true
	error.value = ''
	try {
		emit('saved', await saveRepertoireThresholds(thresholds))
		showSuccess(t('cantoria', 'The repertoire thresholds have been saved.'))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="settings-block">
		<p class="settings-block__text">
			{{ t('cantoria', 'A work belongs to the current repertoire if it was sung within the last years given here, and is dormant up to the second limit. Earlier performances make it "earlier". Documented performances come from concerts confirmed as sung and from the imported history.') }}
		</p>
		<p class="settings-block__text">
			{{ t('cantoria', 'The years are counted back from the reference year: from the current year when it is empty (the limits move on every New Year), or from a fixed year. Example: reference year 2026 and 3 years keep "current" at "sung in 2023 or later" until you change it.') }}
		</p>
		<form class="settings-repertoire" @submit.prevent="save">
			<NcTextField v-model="form.current"
				type="number"
				min="1"
				max="50"
				:label="t('cantoria', 'Current: sung within (years)')" />
			<NcTextField v-model="form.dormant"
				type="number"
				min="1"
				max="100"
				:label="t('cantoria', 'Dormant: sung within (years)')" />
			<NcTextField v-model="form.referenceYear"
				inputmode="numeric"
				:label="t('cantoria', 'Reference year (empty: today)')" />
			<NcButton type="submit" :disabled="saving">
				<template v-if="saving" #icon>
					<NcLoadingIcon />
				</template>
				{{ t('cantoria', 'Save thresholds') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<style scoped src="./settings-block.css"></style>

<style scoped>
.settings-repertoire {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.settings-repertoire > * {
	flex: 0 1 16em;
}
</style>
