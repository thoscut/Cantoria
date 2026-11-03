<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Share the programme with the choir" (milestone 0.19): every piece of a
 * concert or song list in one step, for the choir members, the whole piece
 * each. The list says per piece what happens: shared, extended (a link to
 * the whole piece exists already; its token stays), kept as it is (it lasts
 * longer already: a link is never shortened), or why not. One last day (two
 * weeks after the concert by default, at most until the license of a piece
 * ends), one note and the scans where the rights allow them, for new and
 * extended links alike. The server checks each piece as if it were shared
 * on its own. Afterwards the dialog offers a message for the choir with the
 * address of "Shared with me" to copy (milestone 0.18).
 */
import type { ProgrammeShareItem, ProgrammeShareOptions, ProgrammeShareResult } from '../types.ts'

import { mdiCancel, mdiContentCopy } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { errorMessage, fetchProgrammeShareOptions, shareProgramme } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, toIsoDate } from '../format.ts'
import { sharedUntil } from '../sharing/linkDates.ts'
import { showError } from '../toasts.ts'

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
 * What happens with a piece, in words: a new link, a link extended to the
 * day it gets, or a link that lasts longer already and stays so.
 *
 * @param item - piece of the programme
 */
function status(item: ProgrammeShareItem): string {
	if (!item.allowed) {
		return item.reason ?? t('cantoria', 'This piece cannot be shared.')
	}
	const day = sharedUntil(expires.value, item.until, item.existing?.expires)
	if (item.existing) {
		const was = formatDate(item.existing.expires ?? '')
		return item.existing.expires !== null && item.existing.expires >= day
			? t('cantoria', 'Already shared until {date}; it stays so.', { date: was })
			: t('cantoria', 'Already shared until {date}: the link is extended to {new} and keeps its address.', { date: was, new: formatDate(day) })
	}
	return t('cantoria', 'A new link for the choir members.')
}

/**
 * What the rights add for a piece: that its license ends before the chosen
 * day, and that there are more choir members than licenses (a hint).
 *
 * @param item - piece of the programme
 */
function rightsHints(item: ProgrammeShareItem): string[] {
	const hints: string[] = []
	if (item.until && item.until < expires.value) {
		hints.push(t('cantoria', 'The license ends on {date}: shared until then at most.', { date: formatDate(item.until) }))
	}
	if (item.quantity) {
		hints.push(t('cantoria', 'Licensed for {licensed} people, but {members} accounts are in the group of the choir members.', item.quantity))
	}
	return hints
}

/** The address of "Shared with me": the app itself, for choir members */
const memberPage = window.location.origin + generateUrl('/apps/cantoria/')

/** A message to the choir about the shared pieces, to copy into a mail or a chat (U24) */
const message = computed(() => t('cantoria', 'Hello everyone, the pieces for "{title}" are shared with you in Cantoria under "Shared with me": {url}. Choose your voice there and practise difficult passages with "Loop".',
	{ title: props.title, url: memberPage }))

async function copyMessage(): Promise<void> {
	if (await copyText(message.value)) {
		showSuccess(t('cantoria', 'The message has been copied.'))
	} else {
		showError(t('cantoria', 'The message could not be copied. Please select and copy it by hand.'))
	}
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
							<template v-for="hint in rightsHints(item)" :key="hint">
								<span class="programme-share__hint">{{ t('cantoria', 'Hint:') }} {{ hint }}</span>
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
				:helper-text="t('cantoria', 'The choir members see this note with each piece, also with links that exist already. Empty: they keep their note.')"
				resize="vertical"
				rows="3" />
			<NcCheckboxRadioSwitch v-if="scansOffered" v-model="withScans" type="switch">
				{{ t('cantoria', 'Include the scans (PDF) where the rights allow it, also with links that exist already') }}
			</NcCheckboxRadioSwitch>
			<p v-if="offlineKnown" class="action-dialog__hint">
				{{ t('cantoria', 'Where it says "Available without a network", the singers can practise the piece on the train, too.') }}
			</p>
		</template>
		<div v-else-if="result" class="programme-share__result" role="status">
			<NcNoteCard v-if="result.created + result.extended + (result.kept ?? 0) > 0" type="success">
				{{ t('cantoria', '{created} new, {extended} extended, {kept} already shared for longer. The choir members find them under "Shared with me".', { created: result.created, extended: result.extended, kept: result.kept ?? 0 }) }}
			</NcNoteCard>
			<template v-if="result.limited && result.limited.length > 0">
				<p class="programme-share__limited-title">
					{{ t('cantoria', 'Shared until the license ends:') }}
				</p>
				<ul class="programme-share__failed">
					<li v-for="limited in result.limited" :key="limited.id">
						<strong>{{ limited.title }}</strong>: {{ t('cantoria', 'until {date}', { date: formatDate(limited.expires) }) }}
					</li>
				</ul>
			</template>
			<div v-if="result.created + result.extended + (result.kept ?? 0) > 0" class="programme-share__message">
				<NcTextArea :model-value="message"
					:label="t('cantoria', 'Message to the choir')"
					readonly
					resize="vertical"
					rows="4" />
				<NcButton variant="secondary" @click="copyMessage">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentCopy" />
					</template>
					{{ t('cantoria', 'Copy message to the choir') }}
				</NcButton>
			</div>
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

.programme-share__hint {
	display: block;
	overflow-wrap: anywhere;
}

.programme-share__limited-title {
	margin: 0;
	font-weight: bold;
}

.programme-share__message {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.programme-share__message :deep(.textarea) {
	width: 100%;
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
