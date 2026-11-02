<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create a link to the whole piece or an excerpt (measures), with voice,
 * verse, tempo and a note, valid for at most six months. By default only
 * logged-in holders of an archive role can open it. With the modules
 * "member_links" and "public_links" (milestone 0.7) the link can be made
 * for the choir members or for anyone without signing in, optionally with
 * the scan (PDF); the dialog then shows beforehand what the recipients will
 * see and why such a link is not possible, if it is not. The server checks
 * the rights on every call. After creating, the link can be copied.
 */
import type { LinkScope } from '../sharing/linkScope.ts'
import type { CircleOption, ExcerptLink, LinkCircle, LinkInput, LinkOptions } from '../types.ts'

import { mdiContentCopy } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, onMounted, reactive, ref, shallowRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createLink, errorMessage, fetchLinkOptions, linkUrl } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, toIsoDate } from '../format.ts'
import { canOfferScan, initialScope, linkMeasures, measureProblem, previewRows } from '../sharing/linkScope.ts'
import { canShareOutside } from '../store.ts'
import { showError } from '../toasts.ts'

export interface LinkPrefill {
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	taskId?: number | null
}

const props = defineProps<{
	copyId: number
	shelfmark: string
	prefill?: LinkPrefill | null
}>()

const emit = defineEmits<{
	created: [link: ExcerptLink]
	close: []
}>()

/** Longest validity allowed by the server (days). */
const MAX_DAYS = 183

const today = toIsoDate(new Date())
const latest = (() => {
	const date = new Date()
	date.setDate(date.getDate() + MAX_DAYS)
	return toIsoDate(date)
})()

/** Whole piece (the default) or an excerpt; the excerpt when the score view shows a range */
const scope = ref<LinkScope>(initialScope(props.prefill))

const draft = reactive({
	measureFrom: props.prefill?.measureFrom ?? '',
	measureTo: props.prefill?.measureTo ?? '',
	voice: props.prefill?.voice ?? '',
	verse: props.prefill?.verse ? String(props.prefill.verse) : '',
	tempo: props.prefill?.tempo && props.prefill.tempo !== 100 ? String(props.prefill.tempo) : '',
	note: '',
	expires: latest,
})

const busy = ref(false)
const error = ref('')
const created = ref<ExcerptLink | null>(null)
const url = computed(() => (created.value?.token ? linkUrl(created.value.token, created.value.circle) : ''))

// ---- who can open the link (milestone 0.7) --------------------------------------

const circle = ref<LinkCircle>('internal')
const options = shallowRef<LinkOptions | null>(null)
const optionsLoading = ref(false)
const optionsError = ref('')

async function loadOptions(): Promise<void> {
	optionsLoading.value = true
	optionsError.value = ''
	try {
		options.value = await fetchLinkOptions(props.copyId)
	} catch (caught) {
		optionsError.value = errorMessage(caught)
	} finally {
		optionsLoading.value = false
	}
}

onMounted(() => {
	if (canShareOutside.value) {
		loadOptions()
	}
})

/** What the server says about the chosen circle; null for internal links. */
const option = computed<CircleOption | null>(() => (circle.value === 'internal' ? null : options.value?.[circle.value] ?? null))

/** Why a link for the chosen circle cannot be made, or ''. */
const blockReason = computed(() => {
	if (circle.value === 'internal' || optionsLoading.value) {
		return ''
	}
	if (optionsError.value !== '') {
		return t('cantoria', 'It could not be checked whether this link is possible: {reason}', { reason: optionsError.value })
	}
	const chosen = option.value
	if (chosen === null) {
		return ''
	}
	if (!chosen.available) {
		return chosen.reason ?? t('cantoria', 'This kind of link is switched off. An administrator can switch it on in the settings of Cantoria under "Modules".')
	}
	return chosen.allowed ? '' : (chosen.reason ?? t('cantoria', 'The rights of this version do not allow this link.'))
})

const blocked = computed(() => circle.value !== 'internal' && (optionsLoading.value || option.value === null || blockReason.value !== ''))

const dialogName = computed(() => (canShareOutside.value
	? t('cantoria', 'Share {shelfmark}', { shelfmark: props.shelfmark })
	: t('cantoria', 'Internal link to {shelfmark}', { shelfmark: props.shelfmark })))

const circleHint = computed(() => {
	switch (circle.value) {
	case 'members':
		return t('cantoria', 'Choir members see it in Cantoria under "Shared with me", without the catalog: the whole piece or the chosen measures with all voices, the voice highlighted. The server sends only the shared measures as notation, and the scan only if you add it; it checks the rights on every call. What arrives in a browser can be saved there.')
	case 'public':
		return t('cantoria', 'Anyone who has the link can view and play it without signing in, until it expires or is revoked: the whole piece or the chosen measures, without names and without a note. The server sends only the shared measures as notation, and the scan only if you add it; it checks the rights on every call. What arrives in a browser can be saved there.')
	default:
		return t('cantoria', 'Only people with a role in the music archive can open the link. It shows the whole piece or the measures with all voices; the voice is highlighted. The server checks the rights on every call.')
	}
})

// ---- the scan (choir members and public links) -------------------------------------

const scans = computed(() => options.value?.scans ?? [])
const scanOffered = computed(() => canOfferScan(circle.value, option.value, scans.value.length))
const withScan = ref(false)
const scanFileId = ref<number | null>(null)
/** The chosen scan as text, for the radio buttons of several scans */
const scanChoice = computed<string>({
	get: () => String(scanFileId.value ?? scans.value[0]?.id ?? ''),
	set: (value) => {
		scanFileId.value = Number(value)
	},
})

// ---- preview ----------------------------------------------------------------------

const yes = t('cantoria', 'Yes')
const no = t('cantoria', 'No')

/** What the recipients of a link for choir members or the public will see. */
const preview = computed(() => {
	const chosen = option.value
	if (circle.value === 'internal' || chosen === null || blockReason.value !== '') {
		return []
	}
	return previewRows({
		circle: circle.value,
		scope: scope.value,
		measureFrom: draft.measureFrom,
		measureTo: draft.measureTo,
		voice: draft.voice,
		verse: draft.verse,
		tempo: draft.tempo,
		validUntil: formatDate(draft.expires),
		option: chosen,
		scan: scanOffered.value ? withScan.value : null,
	}, {
		scope: t('cantoria', 'Shared'),
		whole: t('cantoria', 'Whole piece'),
		measures: (from, to) => t('cantoria', 'Measures {from} to {to}', { from, to }),
		missing: t('cantoria', 'measures still missing'),
		voice: t('cantoria', 'Focus voice'),
		none: t('cantoria', 'No focus voice'),
		verse: t('cantoria', 'Verse'),
		tempo: t('cantoria', 'Tempo'),
		asInScore: t('cantoria', 'as in the score'),
		validUntil: t('cantoria', 'Valid until'),
		playback: t('cantoria', 'Playback'),
		practiceFiles: t('cantoria', 'Practice files'),
		scan: t('cantoria', 'Scan (PDF)'),
		offline: t('cantoria', 'Without a network'),
		offlineNo: t('cantoria', 'No: the rights allow no copy on the device'),
		yes,
		no,
	})
})

// ---- creating -------------------------------------------------------------------

async function save(): Promise<void> {
	if (blocked.value) {
		return
	}
	const problem = measureProblem(scope.value, circle.value, draft.measureFrom, draft.measureTo)
	if (problem === 'format') {
		error.value = t('cantoria', 'Measure numbers may only contain digits, letters and dots.')
		return
	}
	if (problem === 'missing') {
		error.value = t('cantoria', 'Please give the first and the last measure of the excerpt, or choose "Whole piece".')
		return
	}
	const verse = draft.verse.trim() === '' ? null : Number(draft.verse)
	const tempo = draft.tempo.trim() === '' ? null : Number(draft.tempo)
	if (verse !== null && (!Number.isInteger(verse) || verse < 1 || verse > 99)) {
		error.value = t('cantoria', 'The verse must be a number from 1 to 99.')
		return
	}
	if (tempo !== null && (!Number.isInteger(tempo) || tempo < 20 || tempo > 300)) {
		error.value = t('cantoria', 'The tempo must be between 20 and 300 %.')
		return
	}
	if (draft.expires === '' || draft.expires < today || draft.expires > latest) {
		error.value = t('cantoria', 'The expiry date must lie between today and six months from now.')
		return
	}
	const link: LinkInput = {
		...linkMeasures(scope.value, draft.measureFrom, draft.measureTo),
		voice: draft.voice.trim() || null,
		verse,
		tempo,
		// a public link has no note: everyone would read it
		note: circle.value === 'public' ? null : (draft.note.trim() || null),
		task_id: circle.value === 'internal' ? (props.prefill?.taskId ?? null) : null,
		expires: draft.expires,
		circle: circle.value,
		scan_file_id: scanOffered.value && withScan.value ? Number(scanChoice.value) : null,
	}
	busy.value = true
	error.value = ''
	try {
		created.value = await createLink(props.copyId, link)
		emit('created', created.value)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

const createdText = computed(() => {
	const link = created.value
	if (!link) {
		return ''
	}
	const date = formatDate(link.expires ?? '')
	switch (link.circle) {
	case 'members':
		return t('cantoria', 'Choir members find it under "Shared with me" until {date}; you can also send them this link. It can be revoked at any time in the list of links.', { date })
	case 'public':
		return t('cantoria', 'Anyone with this link can view and play it until {date}, without signing in. It can be revoked at any time in the list of links.', { date })
	default:
		return t('cantoria', 'The link is valid until {date}. It can be revoked at any time.', { date })
	}
})

async function copyLink(): Promise<void> {
	if (await copyText(url.value)) {
		showSuccess(t('cantoria', 'The link has been copied.'))
	} else {
		showError(t('cantoria', 'The link could not be copied. Please select and copy it by hand.'))
	}
}
</script>

<template>
	<ActionDialog v-if="created === null"
		:name="dialogName"
		:submit-label="t('cantoria', 'Create link')"
		:busy="busy"
		:error="error"
		:submit-disabled="blocked"
		size="large"
		@submit="save"
		@close="emit('close')">
		<fieldset v-if="canShareOutside" class="link-dialog__choice">
			<legend>{{ t('cantoria', 'Who can open the link?') }}</legend>
			<NcCheckboxRadioSwitch v-model="circle"
				value="internal"
				type="radio"
				name="link-dialog-circle">
				{{ t('cantoria', 'Archive roles (internal)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="circle"
				value="members"
				type="radio"
				name="link-dialog-circle">
				{{ t('cantoria', 'Choir members') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="circle"
				value="public"
				type="radio"
				name="link-dialog-circle">
				{{ t('cantoria', 'Public (without signing in)') }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<p class="action-dialog__hint">
			{{ circleHint }}
		</p>
		<NcNoteCard v-if="circle !== 'internal' && optionsLoading" type="info">
			{{ t('cantoria', 'Checking whether this link is possible …') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="blockReason !== ''" type="warning" role="status">
			{{ blockReason }}
		</NcNoteCard>
		<fieldset class="link-dialog__choice">
			<legend>{{ t('cantoria', 'What is shared?') }}</legend>
			<NcCheckboxRadioSwitch v-model="scope"
				value="whole"
				type="radio"
				name="link-dialog-scope">
				{{ t('cantoria', 'Whole piece') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="scope"
				value="excerpt"
				type="radio"
				name="link-dialog-scope">
				{{ t('cantoria', 'Excerpt (measures)') }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<div v-if="scope === 'excerpt'" class="link-dialog__row">
			<NcTextField v-model="draft.measureFrom"
				:label="t('cantoria', 'From measure')"
				:required="circle !== 'internal'" />
			<NcTextField v-model="draft.measureTo"
				:label="t('cantoria', 'To measure')"
				:required="circle !== 'internal'" />
		</div>
		<p v-else class="action-dialog__hint">
			{{ t('cantoria', 'All pages of the piece, with repeats and jumps as in the score.') }}
		</p>
		<div class="link-dialog__row">
			<NcTextField v-model="draft.voice" :label="t('cantoria', 'Voice')" />
			<NcTextField v-model="draft.verse"
				type="number"
				min="1"
				max="99"
				:label="t('cantoria', 'Verse')" />
			<NcTextField v-model="draft.tempo"
				type="number"
				min="20"
				max="300"
				:label="t('cantoria', 'Tempo in % (optional)')" />
		</div>
		<template v-if="circle !== 'public'">
			<NcTextArea v-model="draft.note"
				:label="t('cantoria', 'Note (optional)')"
				:helper-text="circle === 'members' ? t('cantoria', 'The choir members see this note.') : ''"
				resize="vertical"
				rows="3" />
		</template>
		<DateField v-model="draft.expires"
			:min="today"
			:max="latest"
			required
			:label="t('cantoria', 'Valid until (at most six months)')" />
		<div v-if="scanOffered" class="link-dialog__scan">
			<NcCheckboxRadioSwitch v-model="withScan" type="checkbox">
				{{ t('cantoria', 'Include the scan (PDF)') }}
			</NcCheckboxRadioSwitch>
			<fieldset v-if="withScan && scans.length > 1" class="link-dialog__choice">
				<legend>{{ t('cantoria', 'Which scan?') }}</legend>
				<NcCheckboxRadioSwitch v-for="scan in scans"
					:key="scan.id"
					v-model="scanChoice"
					:value="String(scan.id)"
					type="radio"
					name="link-dialog-scan">
					{{ scan.name }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<p class="action-dialog__hint">
				{{ t('cantoria', 'The recipients see the scan in the page. Whoever sees the scan can also save it.') }}
				{{ t('cantoria', 'The whole PDF is shared, also with a link to some measures.') }}
			</p>
		</div>
		<p v-else-if="option?.allowed && option.scanReason && scans.length > 0" class="action-dialog__hint">
			{{ option.scanReason }}
		</p>
		<section v-if="preview.length > 0" class="link-dialog__preview" :aria-label="t('cantoria', 'What the recipients see')">
			<h3 class="link-dialog__preview-title">
				{{ t('cantoria', 'What the recipients see') }}
			</h3>
			<dl class="link-dialog__preview-list">
				<div v-for="row in preview" :key="row.label">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
		</section>
	</ActionDialog>

	<NcDialog v-else
		:name="t('cantoria', 'Link created')"
		:open="true"
		@update:open="emit('close')">
		<NcNoteCard type="success">
			{{ createdText }}
		</NcNoteCard>
		<NcTextField :model-value="url"
			readonly
			:label="t('cantoria', 'Link')" />
		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('cantoria', 'Close') }}
			</NcButton>
			<NcButton variant="primary" @click="copyLink">
				<template #icon>
					<NcIconSvgWrapper :path="mdiContentCopy" />
				</template>
				{{ t('cantoria', 'Copy link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.link-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.link-dialog__row > * {
	flex: 1 1 9em;
}

.link-dialog__choice {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
}

.link-dialog__choice legend {
	font-weight: bold;
}

.link-dialog__scan {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.link-dialog__preview {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.link-dialog__preview-title {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1em;
	font-weight: bold;
}

.link-dialog__preview-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(12em, 1fr));
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
	margin: 0;
}

.link-dialog__preview-list dt {
	/* Nextcloud's styles put dt and dd on one line: the value goes below the label */
	display: block;
	color: var(--color-text-maxcontrast);
}

.link-dialog__preview-list dd {
	display: block;
	margin: 0;
	font-weight: bold;
}
</style>
