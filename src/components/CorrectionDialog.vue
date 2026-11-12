<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Small correction of a score in the app (milestone 0.6, guideline 4): the
 * notes of one voice in a few measures as a list. Only the pitch (step,
 * alteration, octave), the visible accidental and the syllables can be
 * changed; note values are shown, not changed. A preview shows the changed
 * notes in color and bold, and a list says the same in words.
 *
 * Saving sends the whole changed MusicXML; the server compares it with the
 * old file, writes the old document with only these changes as a new file
 * next to it and links it as the MusicXML of the copy. The old file stays
 * unchanged.
 *
 * This component is loaded lazily (own chunk).
 */
import type { MeasureRange } from '../score/range.ts'
import type { ScoreModel } from '../score/model.ts'
import type { NoteChange, NoteRow, NoteValues } from '../score/correction.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'
import type { CorrectionResult, Detail, ReviewPoint } from '../types.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { getLanguage } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { errorMessage, saveCorrection } from '../api.ts'
import { pointInRange, pointPlace, isUnresolved } from '../review.ts'
import {
	ACCIDENTALS,
	ALTERATIONS,
	correctedScore,
	listNotes,
	parseScoreDocument,
	pendingChanges,
	previewScore,
	rowValues,
	sameMeasureLengths,
	STEPS,
	stepLabel,
	voiceTargets,
} from '../score/correction.ts'
import { loadScore, renderPages, selectMeasures } from '../score/model.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'
import { confirmDiscard, useUnsavedGuard } from '../unsaved.ts'

const props = defineProps<{
	detail: Detail
	model: ScoreModel
	/** the MusicXML text of the score shown (as loaded by the score view) */
	scoreText: string
	range: MeasureRange
	voice: Voice
	voiceName: string
	/** review points of the version (may be empty) */
	points: readonly ReviewPoint[]
	/** the point the correction was started from */
	pointId?: number | null
}>()

const emit = defineEmits<{
	saved: [result: CorrectionResult]
	close: []
}>()

/** Interface language, for the note names (H or B). */
const language = getLanguage()

const previewHeadingId = useId()
const changesHeadingId = useId()

const first = computed(() => props.model.measures[props.range.start]?.number ?? '')
const last = computed(() => props.model.measures[props.range.end]?.number ?? '')
const place = computed(() => first.value === last.value
	? t('cantoria', 'Measure {number}, {voice}', { number: first.value, voice: props.voiceName })
	: t('cantoria', 'Measures {from} to {to}, {voice}', { from: first.value, to: last.value, voice: props.voiceName }))

// ---- the notes ----------------------------------------------------------------

const readError = ref('')
let rows: NoteRow[] = []
try {
	rows = listNotes(parseScoreDocument(props.scoreText), props.range, voiceTargets(props.model.parts, props.voice.members))
} catch {
	readError.value = t('cantoria', 'This score cannot be corrected in the app: only partwise MusicXML files can.')
}
const noteRows = rows

const values = reactive<Record<string, NoteValues>>(Object.fromEntries(rows.map((row) => [row.key, rowValues(row)])))

/** The values as a map, for the pure functions. */
function valueMap(): Map<string, NoteValues> {
	return new Map(Object.entries(values))
}

function reset(): void {
	for (const row of noteRows) {
		values[row.key] = rowValues(row)
	}
}

/** Position of each row within its measure (1, 2, …), for labels. */
const positions = computed(() => {
	const result = new Map<string, number>()
	const counts = new Map<number, number>()
	for (const row of noteRows) {
		const next = (counts.get(row.measureIndex) ?? 0) + 1
		counts.set(row.measureIndex, next)
		result.set(row.key, next)
	}
	return result
})

/**
 * Accessible name of a control of a row, e.g. "Measure 3, note 2: step".
 *
 * @param row - note of the list
 * @param what - name of the control
 */
function controlLabel(row: NoteRow, what: string): string {
	return t('cantoria', 'Measure {measure}, note {number}: {what}', { measure: row.measure, number: positions.value.get(row.key) ?? 0, what })
}

const NOTE_TYPES: Record<string, () => string> = {
	breve: () => t('cantoria', 'Double whole note'),
	whole: () => t('cantoria', 'Whole note'),
	half: () => t('cantoria', 'Half note'),
	quarter: () => t('cantoria', 'Quarter note'),
	eighth: () => t('cantoria', 'Eighth note'),
	'16th': () => t('cantoria', '16th note'),
	'32nd': () => t('cantoria', '32nd note'),
	'64th': () => t('cantoria', '64th note'),
}

/**
 * Note value as text, e.g. "dotted quarter note", "rest (half note)".
 *
 * @param row - note of the list
 */
function durationText(row: NoteRow): string {
	let text = row.type ? (NOTE_TYPES[row.type]?.() ?? row.type) : t('cantoria', 'Note value not given')
	if (row.dots === 1) {
		text = t('cantoria', 'dotted {value}', { value: text })
	} else if (row.dots > 1) {
		text = t('cantoria', 'double-dotted {value}', { value: text })
	}
	if (row.rest) {
		text = t('cantoria', 'Rest ({value})', { value: text })
	}
	const flags: string[] = []
	if (row.chord) {
		flags.push(t('cantoria', 'chord note'))
	}
	if (row.grace) {
		flags.push(t('cantoria', 'grace note'))
	}
	return flags.length > 0 ? `${text}, ${flags.join(', ')}` : text
}

/**
 * Label of an alteration.
 *
 * @param alter - semitones
 */
function alterLabel(alter: number): string {
	switch (alter) {
	case -2:
		return t('cantoria', '𝄫 double flat')
	case -1:
		return t('cantoria', '♭ flat')
	case 1:
		return t('cantoria', '♯ sharp')
	case 2:
		return t('cantoria', '𝄪 double sharp')
	}
	return t('cantoria', 'none')
}

/**
 * Label of a visible accidental.
 *
 * @param value - MusicXML accidental value, or '' for none
 */
function accidentalLabel(value: string | null): string {
	switch (value) {
	case 'sharp':
		return t('cantoria', '♯ sharp')
	case 'natural':
		return t('cantoria', '♮ natural')
	case 'flat':
		return t('cantoria', '♭ flat')
	case 'double-sharp':
		return t('cantoria', '𝄪 double sharp')
	case 'sharp-sharp':
		return t('cantoria', '♯♯ two sharps')
	case 'flat-flat':
		return t('cantoria', '𝄫 double flat')
	case 'natural-sharp':
		return t('cantoria', '♮♯ natural and sharp')
	case 'natural-flat':
		return t('cantoria', '♮♭ natural and flat')
	case null:
	case '':
		return t('cantoria', 'none')
	}
	return value
}

/**
 * Alteration of a row with a value the form does not offer (e.g. a quarter
 * tone): kept as it is.
 *
 * @param row - note of the list
 */
function extraAlter(row: NoteRow): boolean {
	return !(ALTERATIONS as readonly number[]).includes(row.alter)
}

/**
 * Accidental of a row with a value the form does not offer: kept as it is.
 *
 * @param row - note of the list
 */
function extraAccidental(row: NoteRow): boolean {
	return row.accidental !== null && !(ACCIDENTALS as readonly string[]).includes(row.accidental)
}

/**
 * Set the octave from the number field.
 *
 * @param row - note of the list
 * @param raw - field value
 */
function setOctave(row: NoteRow, raw: string): void {
	const octave = Number(raw)
	if (Number.isInteger(octave) && octave >= 0 && octave <= 9) {
		values[row.key].octave = octave
	}
}

/**
 * Set the visible accidental from its select ('' = none).
 *
 * @param row - note of the list
 * @param raw - select value
 */
function setAccidental(row: NoteRow, raw: string): void {
	values[row.key].accidental = raw === '' ? null : raw
}

/**
 * Set a syllable.
 *
 * @param row - note of the list
 * @param lyric - index of the lyric
 * @param text - index of the text in the lyric
 * @param raw - field value
 */
function setSyllable(row: NoteRow, lyric: number, text: number, raw: string): void {
	const lyrics = values[row.key].lyrics
	if (lyrics[lyric]) {
		lyrics[lyric][text] = raw.replace(/[\r\n\t]/g, ' ')
	}
}

// ---- changes --------------------------------------------------------------------

const changes = computed(() => pendingChanges(noteRows, valueMap(), true, language))
/** Corrections typed and not saved: closing or leaving the page asks first */
useUnsavedGuard(computed(() => changes.value.length > 0 && !busy.value))

/**
 * One change in words.
 *
 * @param change - change of a note
 */
function changeText(change: NoteChange): string {
	switch (change.kind) {
	case 'pitch':
		return t('cantoria', 'Measure {measure}: {old} → {new}', { measure: change.measure, old: change.old, new: change.new })
	case 'accidental':
		return t('cantoria', 'Measure {measure}: visible accidental {old} → {new}', {
			measure: change.measure, old: accidentalLabel(change.old), new: accidentalLabel(change.new),
		})
	}
	return change.verse
		? t('cantoria', 'Measure {measure}, verse {verse}: "{old}" → "{new}"', { measure: change.measure, verse: change.verse, old: change.old, new: change.new })
		: t('cantoria', 'Measure {measure}: "{old}" → "{new}"', { measure: change.measure, old: change.old, new: change.new })
}

/** Keys of rows with a change, to mark them in the table. */
const changedKeys = computed(() => new Set(changes.value.map((change) => change.key)))

// ---- preview ----------------------------------------------------------------------

const previewElement = ref<HTMLElement | null>(null)
const previewPages = shallowRef<string[]>([])
const previewState = ref<'rendering' | 'ready' | 'error'>('rendering')
let toolkit: VerovioToolkit | null = null
let toolkitReady: Promise<VerovioToolkit> | null = null
let disposed = false
let previewTimer: ReturnType<typeof setTimeout> | null = null
let previewRun = 0

const CHANGED_CLASS = 'correction-changed'

async function renderPreview(): Promise<void> {
	if (readError.value !== '') {
		return
	}
	const run = ++previewRun
	previewState.value = 'rendering'
	try {
		// one toolkit for the dialog, also when an edit comes while it loads
		toolkitReady ??= createToolkit().then((created) => {
			if (disposed) {
				created.destroy()
			} else {
				toolkit = created
			}
			return created
		}, (error: unknown) => {
			toolkitReady = null
			throw error
		})
		const engraver = await toolkitReady
		if (disposed) {
			return
		}
		const { xml } = previewScore(props.scoreText, noteRows, valueMap())
		const loaded = loadScore(engraver, xml)
		const width = Math.max(280, (previewElement.value?.clientWidth ?? 600) - 16)
		const scale = 30
		engraver.setOptions({ scale, pageWidth: Math.min(100000, Math.max(100, Math.round(width * 100 / scale))) })
		selectMeasures(engraver, loaded, props.range)
		const pages = renderPages(engraver).map(sanitizeSvg)
		if (run !== previewRun || disposed) {
			return
		}
		previewPages.value = pages
		previewState.value = 'ready'
		await nextTick()
		// Verovio draws changed notes in their color; the class adds bold note heads and the theme color
		for (const element of previewElement.value?.querySelectorAll('g.note[color]') ?? []) {
			element.classList.add(CHANGED_CLASS)
		}
	} catch (error) {
		console.error('Correction: the preview could not be rendered', error)
		if (run === previewRun) {
			previewState.value = 'error'
		}
	}
}

watch(values, () => {
	if (previewTimer !== null) {
		clearTimeout(previewTimer)
	}
	previewTimer = setTimeout(renderPreview, 400)
}, { deep: true })

onMounted(renderPreview)

onBeforeUnmount(() => {
	disposed = true
	if (previewTimer !== null) {
		clearTimeout(previewTimer)
	}
	toolkit?.destroy()
	toolkit = null
})

const previewDescription = computed(() => changes.value.length === 0
	? t('cantoria', 'Preview of {place}, unchanged.', { place: place.value })
	: t('cantoria', 'Preview of {place}. Changed notes are drawn in color with bold note heads; the list of changes names them.', { place: place.value }))

// ---- review points ---------------------------------------------------------------

/** Points this correction may settle: unresolved ones in these measures and the one it started from. */
const candidatePoints = computed(() => props.points.filter((point) => isUnresolved(point)
	&& (point.id === props.pointId || pointInRange(point, props.model.measures, props.range))))

const pointIds = ref<number[]>(props.pointId ? [props.pointId] : [])

/**
 * Tick or untick a point.
 *
 * @param id - point id
 * @param checked - whether it is ticked
 */
function togglePoint(id: number, checked: boolean): void {
	pointIds.value = checked ? [...new Set([...pointIds.value, id])] : pointIds.value.filter((other) => other !== id)
}

// ---- saving ------------------------------------------------------------------------

const reason = ref('')
const busy = ref(false)
const error = ref('')

async function save(): Promise<void> {
	if (busy.value) {
		return
	}
	const fileId = props.detail.score?.fileId
	if (fileId === undefined) {
		error.value = t('cantoria', 'There is no score to correct.')
		return
	}
	let result: { xml: string, changes: NoteChange[] }
	try {
		result = correctedScore(props.scoreText, noteRows, valueMap())
	} catch {
		error.value = t('cantoria', 'This score cannot be corrected in the app: only partwise MusicXML files can.')
		return
	}
	if (result.changes.length === 0) {
		error.value = t('cantoria', 'Nothing has been changed yet.')
		return
	}
	if (!sameMeasureLengths(props.scoreText, result.xml)) {
		error.value = t('cantoria', 'The lengths of the measures would change. The correction cannot be saved.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		const saved = await saveCorrection(props.detail.version.id, {
			fileId,
			content: result.xml,
			pointIds: pointIds.value.filter((id) => candidatePoints.value.some((point) => point.id === id)),
			reason: reason.value.trim() || null,
		})
		showSuccess(typeof saved.state === 'number'
			? t('cantoria', 'Saved as a new file: {name}. It is revision {number} of this version.', { name: saved.file.name, number: saved.state })
			: t('cantoria', 'Saved as a new file: {name}', { name: saved.file.name }))
		emit('saved', saved)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

/**
 * The dialog asks to close (Escape, close button).
 *
 * @param open - new open state
 */
async function onUpdateOpen(open: boolean): Promise<void> {
	if (!open && !busy.value && await confirmDiscard()) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="t('cantoria', 'Correct notes: {place}', { place })"
		:open="true"
		size="large"
		:no-close="busy"
		@update:open="onUpdateOpen">
		<div class="correction">
			<NcNoteCard v-if="readError" type="error">
				{{ readError }}
			</NcNoteCard>
			<template v-else>
				<p class="correction__hint">
					{{ t('cantoria', 'Only pitches, visible accidentals and syllables can be changed here. The alteration is the sounding pitch; the visible accidental is the sign printed before the note. Note values stay as they are; anything larger is a correction order for the tool chain.') }}
				</p>
				<p class="correction__hint">
					{{ t('cantoria', 'Saving writes a new file next to the old one, with your permissions in Nextcloud. The old file stays unchanged; the change is recorded in the audit log.') }}
				</p>

				<p v-if="noteRows.length === 0" class="correction__hint">
					{{ t('cantoria', 'This voice has no notes in these measures.') }}
				</p>
				<div v-else class="correction__table-wrapper">
					<table class="correction__table">
						<caption>
							{{ t('cantoria', 'Notes of {place}', { place }) }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('cantoria', 'Measure') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Note value') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Step') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Alteration') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Octave') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Visible accidental') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Syllables') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in noteRows"
								:key="row.key"
								:class="{ 'correction__row--changed': changedKeys.has(row.key) }">
								<th scope="row">
									{{ row.measure }}
									<span v-if="changedKeys.has(row.key)" class="correction__changed-tag">{{ t('cantoria', 'changed') }}</span>
								</th>
								<td>{{ durationText(row) }}</td>
								<template v-if="row.pitched">
									<td>
										<select v-model="values[row.key].step" :aria-label="controlLabel(row, t('cantoria', 'Step'))">
											<option v-for="step in STEPS" :key="step" :value="step">
												{{ stepLabel(step, language) }}
											</option>
										</select>
									</td>
									<td>
										<select v-model.number="values[row.key].alter" :aria-label="controlLabel(row, t('cantoria', 'Alteration'))">
											<option v-if="extraAlter(row)" :value="row.alter">
												{{ row.alter }}
											</option>
											<option v-for="alter in ALTERATIONS" :key="alter" :value="alter">
												{{ alterLabel(alter) }}
											</option>
										</select>
									</td>
									<td>
										<input :value="values[row.key].octave"
											type="number"
											min="0"
											max="9"
											step="1"
											class="correction__octave"
											:aria-label="controlLabel(row, t('cantoria', 'Octave'))"
											@input="setOctave(row, ($event.target as HTMLInputElement).value)">
									</td>
									<td>
										<select :value="values[row.key].accidental ?? ''"
											:aria-label="controlLabel(row, t('cantoria', 'Visible accidental'))"
											@change="setAccidental(row, ($event.target as HTMLSelectElement).value)">
											<option value="">
												{{ accidentalLabel(null) }}
											</option>
											<option v-if="extraAccidental(row)" :value="row.accidental ?? ''">
												{{ row.accidental }}
											</option>
											<option v-for="accidental in ACCIDENTALS" :key="accidental" :value="accidental">
												{{ accidentalLabel(accidental) }}
											</option>
										</select>
									</td>
								</template>
								<td v-else colspan="4" class="correction__muted">
									{{ row.rest ? t('cantoria', 'Rest: nothing to change') : t('cantoria', 'No pitch') }}
								</td>
								<td>
									<span v-if="row.lyrics.length === 0" class="correction__muted">–</span>
									<span v-for="(lyric, lyricIndex) in row.lyrics" :key="lyricIndex" class="correction__syllables">
										<input v-for="(text, textIndex) in lyric.texts"
											:key="textIndex"
											:value="values[row.key].lyrics[lyricIndex]?.[textIndex] ?? text"
											type="text"
											maxlength="200"
											class="correction__syllable"
											:aria-label="controlLabel(row, lyric.number
												? t('cantoria', 'syllable of verse {verse}', { verse: lyric.number })
												: t('cantoria', 'syllable'))"
											@input="setSyllable(row, lyricIndex, textIndex, ($event.target as HTMLInputElement).value)">
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<section class="correction__section" :aria-labelledby="changesHeadingId">
					<h3 :id="changesHeadingId" class="correction__heading">
						{{ t('cantoria', 'Changes') }}
					</h3>
					<p v-if="changes.length === 0" class="correction__muted">
						{{ t('cantoria', 'No changes yet.') }}
					</p>
					<ul v-else class="correction__changes">
						<li v-for="(change, index) in changes" :key="index">
							{{ changeText(change) }}
						</li>
					</ul>
					<NcButton variant="tertiary" :disabled="changes.length === 0" @click="reset">
						{{ t('cantoria', 'Undo all changes') }}
					</NcButton>
				</section>

				<section class="correction__section" :aria-labelledby="previewHeadingId">
					<h3 :id="previewHeadingId" class="correction__heading">
						{{ t('cantoria', 'Preview') }}
					</h3>
					<p v-if="previewState === 'rendering'" class="correction__muted">
						<NcLoadingIcon :size="20" class="correction__loading" />
						{{ t('cantoria', 'Engraving the preview …') }}
					</p>
					<p v-else-if="previewState === 'error'" class="correction__muted">
						{{ t('cantoria', 'The preview could not be shown. The list of changes above is complete.') }}
					</p>
					<div ref="previewElement"
						class="correction__preview"
						role="img"
						:aria-label="previewDescription">
						<!-- SVG rendered by Verovio from the corrected MusicXML and cleaned by sanitizeSvg() -->
						<!-- eslint-disable vue/no-v-html -->
						<div v-for="(svg, index) in previewPages"
							:key="index"
							class="correction__page"
							v-html="svg" />
						<!-- eslint-enable vue/no-v-html -->
					</div>
				</section>

				<fieldset v-if="candidatePoints.length > 0" class="correction__points">
					<legend>{{ t('cantoria', 'Review points settled by this correction') }}</legend>
					<NcCheckboxRadioSwitch v-for="point in candidatePoints"
						:key="point.id"
						:model-value="pointIds.includes(point.id)"
						@update:model-value="togglePoint(point.id, $event)">
						{{ pointPlace(point) }}<template v-if="point.text">
							: {{ point.text }}
						</template>
					</NcCheckboxRadioSwitch>
				</fieldset>

				<NcTextArea v-model="reason"
					:label="t('cantoria', 'Reason (optional), e.g. compared with the scan')"
					resize="vertical"
					rows="2" />
			</template>
			<NcNoteCard v-if="error !== ''" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="emit('close')">
				{{ t('cantoria', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || readError !== '' || changes.length === 0"
				@click="save">
				<template v-if="busy" #icon>
					<NcLoadingIcon />
				</template>
				{{ t('cantoria', 'Save as new file') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.correction {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
}

.correction__hint,
.correction__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.correction__table-wrapper {
	max-width: 100%;
	overflow-x: auto;
}

.correction__table {
	border-collapse: collapse;
	width: 100%;
}

.correction__table caption {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
	text-align: start;
}

.correction__table th,
.correction__table td {
	padding: 2px var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.correction__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	white-space: nowrap;
}

.correction__table select,
.correction__table input {
	min-height: var(--default-clickable-area);
	margin: 2px 0;
}

.correction__octave {
	width: 5em;
}

.correction__syllables {
	display: inline-flex;
	gap: 2px;
}

.correction__syllable {
	width: 8em;
}

/* changed rows: a marker word and a thick border, not only a color */
.correction__row--changed th {
	border-inline-start: 4px solid var(--color-primary-element);
}

.correction__changed-tag {
	display: block;
	color: var(--color-main-text);
	font-size: var(--font-size-small, 13px);
	font-weight: normal;
}

.correction__section {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.correction__heading {
	margin: 0;
	font-size: 1.05em;
	font-weight: bold;
}

.correction__changes {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}

.correction__loading {
	display: inline-block;
	vertical-align: middle;
}

.correction__preview {
	align-self: stretch;
	min-height: 4em;
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.correction__page :deep(svg) {
	display: block;
	max-width: none;
	margin: 0 auto;
}

.correction__page :deep(svg.definition-scale) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* changed notes: theme color and bold note heads (the list says it in words) */
.correction__page :deep(g.note.correction-changed) {
	color: var(--color-error-text, #c9302c);
	fill: var(--color-error-text, #c9302c);
}

.correction__page :deep(g.note.correction-changed g.notehead) {
	stroke: currentColor;
	stroke-width: 40px;
}

.correction__points {
	margin: 0;
	padding: 0;
	border: none;
}

.correction__points legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}
</style>
