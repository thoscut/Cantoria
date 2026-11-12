<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The choir profile: which voice groups the choir has and, for each, the
 * range that is comfortable and the range that is still possible. It
 * describes the groups, never single singers. The filter "Our ranges", the
 * ranges of a piece and the suggested transposition follow from it.
 */
import type { ProfileGroup, VoiceGroup } from '../scoreFacts.ts'

import { getLanguage } from '@nextcloud/l10n'
import { t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ActionDialog from './ActionDialog.vue'
import AmbitusChart from './AmbitusChart.vue'
import VoiceMark from './VoiceMark.vue'
import { errorMessage } from '../api.ts'
import { GROUPS, groupLabel, runFacts, saveProfile, toneName } from '../scoreFacts.ts'
import { isLibrarian, loadScoreFacts, scoreFacts } from '../store.ts'
import { showSuccess } from '@nextcloud/dialogs'

const emit = defineEmits<{
	close: []
}>()

const german = getLanguage().toLowerCase().startsWith('de')
const DEFAULTS: Record<VoiceGroup, ProfileGroup> = {
	S: { group: 'S', comfortLow: 62, comfortHigh: 77, limitLow: 60, limitHigh: 81 },
	A: { group: 'A', comfortLow: 55, comfortHigh: 72, limitLow: 53, limitHigh: 74 },
	T: { group: 'T', comfortLow: 50, comfortHigh: 65, limitLow: 48, limitHigh: 69 },
	B: { group: 'B', comfortLow: 43, comfortHigh: 60, limitLow: 40, limitHigh: 64 },
}

const current = scoreFacts.value?.profile.groups ?? Object.values(DEFAULTS)
const rows = ref(GROUPS.map((group) => {
	const found = current.find((g) => g.group === group)
	return { ...(found ?? DEFAULTS[group]), present: found !== undefined }
}))
const canEdit = computed(() => scoreFacts.value?.canEditProfile === true)
const keyLabels = {
	limitLow: t('cantoria', 'Possible from'),
	comfortLow: t('cantoria', 'Comfortable from'),
	comfortHigh: t('cantoria', 'Comfortable to'),
	limitHigh: t('cantoria', 'Possible to'),
}
const summary = computed(() => scoreFacts.value?.summary ?? null)
const busy = ref(false)
const error = ref('')

interface ToneOption {
	id: number
	label: string
}

const toneOptions: ToneOption[] = []
for (let pitch = 33; pitch <= 91; pitch++) {
	toneOptions.push({ id: pitch, label: toneName(pitch, 0, german) })
}

const preview = computed(() => rows.value.filter((row) => row.present).map((row) => ({
	name: groupLabel(row.group),
	group: row.group,
	low: row.comfortLow,
	high: row.comfortHigh,
	tessLow: row.comfortLow,
	tessHigh: row.comfortHigh,
})))

const profilePreview = computed<ProfileGroup[]>(() => rows.value.filter((row) => row.present)
	.map(({ group, comfortLow, comfortHigh, limitLow, limitHigh }) => ({ group, comfortLow, comfortHigh, limitLow, limitHigh })))

const invalid = computed(() => profilePreview.value.length === 0 || profilePreview.value.some((row) => row.comfortLow > row.comfortHigh
	|| row.limitLow > row.comfortLow || row.limitHigh < row.comfortHigh))

/**
 * The option of a tone.
 *
 * @param pitch - MIDI pitch
 */
function option(pitch: number): ToneOption {
	return toneOptions.find((o) => o.id === pitch) ?? { id: pitch, label: toneName(pitch, 0, german) }
}

async function submit(): Promise<void> {
	if (!canEdit.value) {
		emit('close')
		return
	}
	busy.value = true
	error.value = ''
	try {
		await saveProfile(profilePreview.value)
		await loadScoreFacts()
		showSuccess(t('cantoria', 'Choir profile saved.'))
		emit('close')
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}

/**
 * Reads the next scores at once (catalog librarian) instead of waiting for
 * the background job.
 */
async function readNow(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await runFacts()
		await loadScoreFacts()
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}

async function reset(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await saveProfile([])
		await loadScoreFacts()
		rows.value = GROUPS.map((group) => ({ ...DEFAULTS[group], present: true }))
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="t('cantoria', 'Choir profile')"
		:submit-label="canEdit ? t('cantoria', 'Save profile') : t('cantoria', 'Close')"
		:submit-disabled="canEdit && invalid"
		:busy="busy"
		:error="error"
		size="large"
		@submit="submit"
		@close="emit('close')">
		<p class="profile__intro">
			{{ t('cantoria', 'The ranges the voice groups of the choir sing: comfortable, and still possible. The profile describes groups, never single singers.') }}
			{{ t('cantoria', 'Pieces in the catalog are compared with it, and the app suggests a transposition when a piece fits better higher or lower.') }}
		</p>
		<p v-if="scoreFacts && !scoreFacts.profile.custom" class="profile__hint">
			{{ t('cantoria', 'These are the values for a mixed amateur choir. Adjust them to your choir.') }}
		</p>
		<div class="profile__layout">
			<table class="profile__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('cantoria', 'Voice group') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Possible from') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Comfortable from') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Comfortable to') }}
						</th>
						<th scope="col">
							{{ t('cantoria', 'Possible to') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.group">
						<th scope="row">
							<NcCheckboxRadioSwitch v-model="row.present" :disabled="!canEdit">
								<VoiceMark :group="row.group" :label="groupLabel(row.group)" />
							</NcCheckboxRadioSwitch>
						</th>
						<td v-for="key in (['limitLow', 'comfortLow', 'comfortHigh', 'limitHigh'] as const)" :key="key">
							<NcSelect :model-value="option(row[key])"
								:options="toneOptions"
								:clearable="false"
								:disabled="!canEdit || !row.present"
								v-bind="{ ariaLabelCombobox: `${groupLabel(row.group)}: ${keyLabels[key]}` }"
								@update:model-value="(value: ToneOption | null) => { if (value) row[key] = value.id }" />
						</td>
					</tr>
				</tbody>
			</table>
			<AmbitusChart v-if="preview.length > 0"
				class="profile__chart"
				:voices="preview"
				:profile="profilePreview" />
		</div>
		<p v-if="canEdit && invalid" class="profile__hint" role="status">
			{{ t('cantoria', 'Each range goes from a lower to a higher tone, and the possible range includes the comfortable one.') }}
		</p>
		<NcButton v-if="canEdit && scoreFacts?.profile.custom"
			variant="tertiary"
			:disabled="busy"
			@click="reset">
			{{ t('cantoria', 'Back to the default values') }}
		</NcButton>
		<p v-if="summary" class="profile__read" role="status">
			{{ t('cantoria', '{read} of {files} scores read.', { read: summary.read, files: summary.files }) }}
			<template v-if="summary.read < summary.files">
				{{ t('cantoria', 'The rest follows in the background, a few every hour.') }}
			</template>
		</p>
		<NcButton v-if="isLibrarian && summary && summary.read < summary.files"
			variant="secondary"
			:disabled="busy"
			@click="readNow">
			{{ t('cantoria', 'Read the next scores now') }}
		</NcButton>
	</ActionDialog>
</template>

<style scoped>
.profile__intro {
	margin-block-end: 8px;
}

.profile__read {
	margin-block: 12px 4px;
	color: var(--color-text-maxcontrast);
}

.profile__hint {
	color: var(--color-text-maxcontrast);
}

.profile__layout {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 16px 24px;
	margin-block: 12px;
}

.profile__table {
	border-collapse: collapse;
}

.profile__table th,
.profile__table td {
	padding: 4px 6px;
	text-align: start;
}

.profile__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	font-size: 0.9em;
}

.profile__table td :deep(.v-select) {
	min-inline-size: 7.5em;
}
</style>
