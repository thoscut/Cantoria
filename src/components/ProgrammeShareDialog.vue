<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Share the programme with the choir" (milestone 0.19): every piece of a
 * concert or song list in one step, for the choir members, the whole piece
 * each. The list says per piece what happens: shared, extended (a link to
 * the whole piece exists already; its token stays), or why not. One last
 * day (two weeks after the concert by default), one note and the scans
 * where the rights allow them. The server checks each piece as if it were
 * shared on its own.
 */
import type { ProgrammeShareOptions, ProgrammeShareResult } from '../types.ts'

import { mdiCancel } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { errorMessage, fetchProgrammeShareOptions, shareProgramme } from '../api.ts'
import { formatDate, toIsoDate } from '../format.ts'

const props = defineProps<{
	eventId: number
	title: string
}>()

const emit = defineEmits<{
	close: []
}>()

const options = ref<ProgrammeShareOptions | null>(null)
const loadError = ref('')
/** chosen programme items, as strings for the checkboxes */
const chosen = ref<string[]>([])
const expires = ref('')
const note = ref('')
const withScans = ref(false)
const busy = ref(false)
const error = ref('')
const result = ref<ProgrammeShareResult | null>(null)
const today = toIsoDate(new Date())

const shareable = computed(() => options.value?.items.filter((item) => item.allowed) ?? [])
const scansOffered = computed(() => shareable.value.some((item) => item.scan))
const offlineKnown = computed(() => shareable.value.some((item) => item.offline !== null))

/**
 * What happens with a piece, in words.
 *
 * @param item - piece of the programme
 * @param item.allowed - may be shared
 * @param item.reason - why not
 * @param item.existing - the link that is extended
 */
function status(item: { allowed: boolean, reason: string | null, existing: { expires: string | null } | null }): string {
	if (!item.allowed) {
		return item.reason ?? t('cantoria', 'This piece cannot be shared.')
	}
	if (item.existing) {
		return t('cantoria', 'Already shared until {date}: the link is extended and keeps its address.', { date: formatDate(item.existing.expires ?? '') })
	}
	return t('cantoria', 'A new link for the choir members.')
}

const dialogName = computed(() => t('cantoria', 'Share "{title}" with the choir', { title: props.title }))
const submitLabel = computed(() => n('cantoria', 'Share %n piece', 'Share %n pieces', chosen.value.length))

async function load(): Promise<void> {
	try {
		options.value = await fetchProgrammeShareOptions(props.eventId)
		expires.value = options.value.expires
		chosen.value = options.value.items.filter((item) => item.allowed).map((item) => String(item.id))
	} catch (caught) {
		loadError.value = errorMessage(caught)
	}
}

async function submit(): Promise<void> {
	if (result.value) {
		emit('close')
		return
	}
	if (chosen.value.length === 0) {
		error.value = t('cantoria', 'Please choose at least one piece.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		result.value = await shareProgramme(props.eventId, { items: chosen.value.map(Number), expires: expires.value, note: note.value, scan: withScans.value })
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>

<template>
	<ActionDialog :name="dialogName"
		:submit-label="result ? t('cantoria', 'Close') : submitLabel"
		:submit-disabled="!result && (options === null || chosen.length === 0)"
		:busy="busy"
		:done="result !== null"
		:error="error || loadError"
		size="normal"
		@submit="submit"
		@close="emit('close')">
		<p v-if="options === null && !loadError" class="programme-share__loading">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Checking the rights of each piece …') }}
		</p>
		<template v-else-if="options && !result">
			<p class="action-dialog__hint">
				{{ t('cantoria', 'Each piece is shared as a whole with the choir members under "Shared with me", as if you shared it on its own. The rights are checked for each piece.') }}
			</p>
			<fieldset class="programme-share__items">
				<legend>{{ t('cantoria', 'Pieces') }}</legend>
				<template v-for="item in options.items" :key="item.id">
					<NcCheckboxRadioSwitch v-if="item.allowed"
						v-model="chosen"
						:value="String(item.id)"
						type="checkbox">
						<span class="programme-share__title">
							{{ item.title }}<template v-if="item.shelfmark">
								· {{ item.shelfmark }}
							</template>
						</span>
						<template #description>
							{{ status(item) }}
							<template v-if="item.offline !== null">
								{{ item.offline ? t('cantoria', 'Available without a network.') : t('cantoria', 'Not available without a network: the rights allow no copy on the device.') }}
							</template>
						</template>
					</NcCheckboxRadioSwitch>
					<!-- not a disabled checkbox: the reason must stay readable -->
					<div v-else class="programme-share__blocked">
						<NcIconSvgWrapper :path="mdiCancel" :size="20" />
						<div>
							<span class="programme-share__title">
								{{ item.title }}<template v-if="item.shelfmark">
									· {{ item.shelfmark }}
								</template>
							</span>
							<span class="programme-share__reason">
								{{ t('cantoria', 'Not shared:') }} {{ status(item) }}
							</span>
						</div>
					</div>
				</template>
			</fieldset>
			<DateField v-model="expires"
				:min="today"
				:max="options.latest"
				required
				:label="t('cantoria', 'Valid until (at most six months)')" />
			<NcTextArea v-model="note"
				:label="t('cantoria', 'Note (optional)')"
				:helper-text="t('cantoria', 'The choir members see this note with each new link; extended links keep theirs.')"
				resize="vertical"
				rows="3" />
			<NcCheckboxRadioSwitch v-if="scansOffered" v-model="withScans" type="switch">
				{{ t('cantoria', 'Include the scans (PDF) where the rights allow it') }}
			</NcCheckboxRadioSwitch>
			<p v-if="offlineKnown" class="action-dialog__hint">
				{{ t('cantoria', 'Where it says "Available without a network", the singers can practise the piece on the train, too.') }}
			</p>
		</template>
		<div v-else-if="result" class="programme-share__result" role="status">
			<NcNoteCard v-if="result.created + result.extended > 0" type="success">
				{{ t('cantoria', '{created} new, {extended} extended. The choir members find them under "Shared with me".', { created: result.created, extended: result.extended }) }}
			</NcNoteCard>
			<template v-if="result.failed.length > 0">
				<NcNoteCard type="warning">
					{{ n('cantoria', '%n piece was not shared:', '%n pieces were not shared:', result.failed.length) }}
				</NcNoteCard>
				<ul class="programme-share__failed">
					<li v-for="failed in result.failed" :key="failed.id">
						<strong>{{ failed.title }}</strong>: {{ failed.reason }}
					</li>
				</ul>
			</template>
		</div>
	</ActionDialog>
</template>

<style scoped>
.programme-share__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.programme-share__items {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	border: none;
}

.programme-share__items legend {
	padding: 0;
	font-weight: bold;
}

.programme-share__title {
	overflow-wrap: anywhere;
}

/* aligned with the label of a checkbox */
.programme-share__blocked {
	display: flex;
	align-items: flex-start;
	gap: calc(1.5 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.programme-share__blocked .programme-share__title {
	display: block;
	color: var(--color-main-text);
}

.programme-share__reason {
	display: block;
	overflow-wrap: anywhere;
}

.programme-share__result {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.programme-share__result :deep(.notecard) {
	margin: 0;
}

.programme-share__failed {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
	overflow-wrap: anywhere;
}
</style>
