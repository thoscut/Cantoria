<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Large-print part" (proposal 6, November 2026): one voice of the score
 * set large for a singer with a visual impairment, with a preview, printed
 * from the browser on A4. The server says whether the rights allow the
 * printed copy; without confirmed public domain or a license the catalog
 * librarian can confirm an accessible copy (§ 45b UrhG). People decide; the
 * app records the part, never the name of the singer.
 */
import type { LargePrintCheck } from '../largePrint.ts'
import type { LargePrintSize } from '../score/largePrint.ts'
import type { ScoreModel } from '../score/model.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'

import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ActionDialog from './ActionDialog.vue'
import { errorMessage } from '../api.ts'
import { n, t } from '../l10n.ts'
import { fetchLargePrintCheck, recordLargePrint } from '../largePrint.ts'
import { extractStaves, renderLargePrint } from '../score/largePrint.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'

const props = defineProps<{
	copyId: number
	scoreText: string
	model: ScoreModel
	/** the voice chosen in the score view, '' without one */
	focusKey: string
	voiceName: (voice: Voice) => string
	/** title of the piece and the line of the version, for the running line */
	title: string
	versionLine: string
}>()

const emit = defineEmits<{
	close: []
}>()

interface Option {
	id: string
	label: string
}

const NONE: Option = { id: '', label: t('cantoria', 'No second voice') }

const options = computed<Option[]>(() => props.model.voices.map((voice) => ({ id: voice.key, label: props.voiceName(voice) })))
const voice = ref<Option | null>(null)
const second = ref<Option>(NONE)
const size = ref<LargePrintSize>('larger')
const check = ref<LargePrintCheck | null>(null)
const accessible = ref(false)
const loadError = ref('')
const error = ref('')
const busy = ref(false)
const rendering = ref(false)
const pages = shallowRef<string[]>([])
const printed = ref(false)

let toolkit: VerovioToolkit | null = null
let renderRun = 0

const secondOptions = computed(() => [NONE, ...options.value.filter((option) => option.id !== voice.value?.id)])
const allowed = computed(() => check.value !== null && (check.value.allowed || (check.value.canConfirm && accessible.value)))

/** Positions of the staves of the chosen voices, counted over all parts */
const staves = computed(() => {
	const order = props.model.parts.flatMap((part) => part.staves)
	const keys = [voice.value?.id, second.value.id].filter((key): key is string => typeof key === 'string' && key !== '')
	const chosen = props.model.voices.filter((candidate) => keys.includes(candidate.key))
	return [...new Set(chosen.flatMap((candidate) => candidate.members.map((member) => order.indexOf(member.staff))))].filter((index) => index >= 0)
})

/** Other voices on the staves of the part: they are printed with it */
const sharing = computed(() => {
	const order = props.model.parts.flatMap((part) => part.staves)
	const keys = [voice.value?.id, second.value.id]
	return props.model.voices
		.filter((candidate) => !keys.includes(candidate.key) && candidate.members.some((member) => staves.value.includes(order.indexOf(member.staff))))
		.map((candidate) => props.voiceName(candidate))
})

const runningLine = computed(() => [props.title, voice.value?.label, props.versionLine].filter((part) => part).join(' · '))

async function render(): Promise<void> {
	const run = ++renderRun
	error.value = ''
	if (staves.value.length === 0) {
		pages.value = []
		return
	}
	rendering.value = true
	try {
		toolkit ??= await createToolkit()
		// let the spinner show before the engraving blocks the page
		await new Promise((resolve) => setTimeout(resolve, 30))
		if (run !== renderRun) {
			return
		}
		const part = extractStaves(props.scoreText, staves.value)
		const result = part === null ? null : renderLargePrint(toolkit, part, size.value)
		if (result === null) {
			pages.value = []
			error.value = t('cantoria', 'This voice could not be set as a part of its own.')
			return
		}
		pages.value = result.map(sanitizeSvg)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		if (run === renderRun) {
			rendering.value = false
		}
	}
}

watch([voice, second, size], render)
watch(voice, () => {
	if (second.value.id === voice.value?.id) {
		second.value = NONE
	}
})

function afterPrint(): void {
	document.body.classList.remove('cantoria-large-print')
}

async function submit(): Promise<void> {
	if (!voice.value || pages.value.length === 0 || !allowed.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await recordLargePrint(props.copyId, voice.value.label, check.value?.allowed !== true && accessible.value)
		document.body.classList.add('cantoria-large-print')
		// The sheet is in the page already; let the class take effect
		await new Promise((resolve) => requestAnimationFrame(() => resolve(null)))
		window.print()
		printed.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	window.addEventListener('afterprint', afterPrint)
	voice.value = options.value.find((option) => option.id === props.focusKey) ?? options.value[0] ?? null
	try {
		check.value = await fetchLargePrintCheck(props.copyId)
	} catch (caught) {
		loadError.value = errorMessage(caught)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('afterprint', afterPrint)
	afterPrint()
	renderRun++
	toolkit?.destroy()
	toolkit = null
})
</script>

<template>
	<ActionDialog :name="t('cantoria', 'Large-print part')"
		:submit-label="t('cantoria', 'Print the part')"
		:submit-disabled="!allowed || pages.length === 0 || rendering"
		:busy="busy"
		:error="error || loadError"
		size="large"
		@submit="submit"
		@close="emit('close')">
		<p class="action-dialog__hint">
			{{ t('cantoria', 'One voice with large notes, few measures per line, strong lines and the lyrics below, for singers with a visual impairment. It prints on A4 from this browser.') }}
		</p>
		<div class="large-print__choices">
			<NcSelect v-model="voice"
				:input-label="t('cantoria', 'Voice')"
				:options="options"
				:clearable="false"
				:searchable="false" />
			<NcSelect v-model="second"
				:input-label="t('cantoria', 'With a second voice (e.g. the neighbour or the piano)')"
				:options="secondOptions"
				:clearable="false"
				:searchable="false" />
		</div>
		<fieldset class="large-print__sizes">
			<legend>{{ t('cantoria', 'Size of the notes') }}</legend>
			<NcCheckboxRadioSwitch v-model="size"
				type="radio"
				value="large"
				name="large-print-size">
				{{ t('cantoria', 'Large (about 1.4 times)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="size"
				type="radio"
				value="larger"
				name="large-print-size">
				{{ t('cantoria', 'Larger (about 1.8 times)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="size"
				type="radio"
				value="largest"
				name="large-print-size">
				{{ t('cantoria', 'Largest (about 2.3 times)') }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<p v-if="sharing.length > 0" class="action-dialog__hint">
			{{ t('cantoria', 'On the same staff and printed with it: {voices}.', { voices: sharing.join(', ') }) }}
		</p>

		<NcNoteCard v-if="check && !check.allowed" type="warning">
			{{ check.reason }}
		</NcNoteCard>
		<NcCheckboxRadioSwitch v-if="check && !check.allowed && check.canConfirm" v-model="accessible" type="checkbox">
			{{ t('cantoria', 'I confirm as catalog librarian: this part is made for a singer with a visual impairment and stays with that person (§ 45b UrhG). The confirmation is recorded, without the name of the singer.') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="printed" type="success">
			{{ t('cantoria', 'The part has been recorded in the history of the copy.') }}
		</NcNoteCard>

		<div class="large-print__preview" role="status">
			<p v-if="rendering" class="large-print__status">
				<NcLoadingIcon :size="20" />
				{{ t('cantoria', 'Setting the part …') }}
			</p>
			<p v-else-if="pages.length > 0" class="large-print__status">
				{{ n('cantoria', '%n page. The first one:', '%n pages. The first one:', pages.length) }}
			</p>
			<!-- eslint-disable vue/no-v-html -- Verovio's SVG, sanitized -->
			<div v-if="pages.length > 0 && !rendering"
				class="large-print__page"
				aria-hidden="true"
				v-html="pages[0]" />
			<!-- eslint-enable vue/no-v-html -->
		</div>

		<!-- What is printed: outside the dialog, shown only on paper -->
		<Teleport to="body">
			<div class="large-print-sheet" aria-hidden="true">
				<section v-for="(page, index) in pages" :key="index" class="large-print-sheet__page">
					<p class="large-print-sheet__line">
						<span>{{ runningLine }}</span>
						<span>{{ index + 1 }} / {{ pages.length }}</span>
					</p>
					<!-- eslint-disable-next-line vue/no-v-html -- Verovio's SVG, sanitized -->
					<div class="large-print-sheet__notes" v-html="page" />
				</section>
			</div>
		</Teleport>
	</ActionDialog>
</template>

<style scoped>
.large-print__choices {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
	gap: calc(2 * var(--default-grid-baseline));
}

.large-print__sizes {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	border: none;
}

.large-print__sizes legend {
	padding: 0;
	font-weight: bold;
}

.large-print__status {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.large-print__page {
	max-inline-size: 26rem;
	margin-block-start: var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius, 4px);
	/* paper: black on white in every theme */
	background: #fff;
	color: #000;
}

.large-print__page :deep(svg) {
	display: block;
	inline-size: 100%;
	block-size: auto;
}
</style>

<style>
/* The sheet lives in <body>: hidden on screen, the only thing on paper */
.large-print-sheet {
	display: none;
}

@media print {
	body.cantoria-large-print > :not(.large-print-sheet) {
		display: none !important;
	}

	body.cantoria-large-print > .large-print-sheet {
		display: block;
		color: #000;
		background: #fff;
	}

	body.cantoria-large-print .large-print-sheet__page {
		break-after: page;
		break-inside: avoid;
	}

	body.cantoria-large-print .large-print-sheet__page:last-child {
		break-after: auto;
	}

	body.cantoria-large-print .large-print-sheet__line {
		display: flex;
		justify-content: space-between;
		gap: 8mm;
		margin: 0;
		padding: 0 13mm;
		font-size: 14pt;
		font-weight: 700;
		line-height: 12mm;
		white-space: nowrap;
		overflow: hidden;
	}

	body.cantoria-large-print .large-print-sheet__notes svg {
		display: block;
		width: 100%;
		height: auto;
	}
}
</style>
