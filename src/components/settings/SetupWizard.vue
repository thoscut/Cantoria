<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The guided setup: a dialog that leads through the first configuration,
 * one page at a time. A welcome, then five steps: the four of the settings
 * page (archive folder, functions, roles and groups, access check), each
 * with the same form as on the page, and one about the catalog (import and
 * file sync happen in the app). An overview closes it. Every step says in
 * words whether it is done.
 *
 * "Continue" saves what the form of the page holds ("Save and continue") and
 * stays on the page when that fails. Going back, jumping to another page or
 * closing with input that is not saved asks first (unsaved.ts). "Later"
 * closes the dialog; the page keeps the list "Setting up" and the button to
 * come back.
 *
 * The page does not show its own sections while the dialog is open, so each
 * form (and each of its ids) exists once.
 */
import type { SetupStep, StepKey, StepStatus, WizardStepKey } from '../../settings.ts'
import type { AccessReport, ModuleInfo, Settings } from '../../types.ts'

import { mdiArrowLeft, mdiArrowRight, mdiCheck, mdiContentSaveOutline, mdiFlagCheckered, mdiHandWaveOutline } from '@mdi/js'
import { t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ArchiveAccessCheck from './ArchiveAccessCheck.vue'
import ArchiveFolderSettings from './ArchiveFolderSettings.vue'
import RoleSettings from './RoleSettings.vue'
import GuideLink from '../GuideLink.vue'
import ModuleSettings from '../ModuleSettings.vue'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { focusAfterUpdate } from '../../focus.ts'
import { setupSteps, WIZARD_STEP_COUNT, WIZARD_STEPS, wizardStartStep, wizardStepKeys, wizardStepNumber, wizardStepOf } from '../../settings.ts'
import { stepHint, stepStatusIcon, stepStatusText, stepTitle } from '../../setupLabels.ts'
import { confirmDiscard } from '../../unsaved.ts'

/** The functions: "Continue" keeps them as they are shown, which makes the step done. */
interface ChoosingForm {
	keepAsShown: () => Promise<boolean>
}

/** What a form with a button to save tells the dialog. */
interface SavingForm {
	/** it holds input that is not saved */
	dirty: boolean
	/** saves; false when that failed (the form says why) */
	save: () => Promise<boolean>
}

const props = defineProps<{
	settings: Settings
}>()

/** The access check of the saved archive folder; shared with the page */
const report = defineModel<AccessReport | null>('report', { default: null })

const emit = defineEmits<{
	/** "Later", Escape or "Finish": the dialog closes */
	close: [finished: boolean]
	/** a form saved settings: the page takes them over */
	saved: [settings: Settings]
	/** the roles were saved: an earlier access check no longer applies */
	rolesSaved: [settings: Settings]
	/** modules were switched: the page loads its settings again */
	modulesChanged: [modules: ModuleInfo[]]
	/** go to a section of the page (closes the dialog) */
	section: [id: 'backup']
}>()

const steps = computed<SetupStep[]>(() => setupSteps(props.settings, report.value))
const current = ref<WizardStepKey>(wizardStartStep(props.settings, steps.value))
const index = computed(() => WIZARD_STEPS.indexOf(current.value))
const isFirst = computed(() => index.value === 0)
const isLast = computed(() => index.value === WIZARD_STEPS.length - 1)
const appUrl = generateUrl('/apps/cantoria/')

/** The setup steps of the current page, with their state */
const pageSteps = computed(() => wizardStepKeys(current.value)
	.map((key) => steps.value.find((step) => step.key === key))
	.filter((step): step is SetupStep => step !== undefined))

/** A page with one setup step shows its state next to the heading */
const ownStep = computed(() => (pageSteps.value.length === 1 ? pageSteps.value[0] ?? null : null))

/**
 * What to do, below the heading: only where the form of the page does not
 * say it itself. The roles depend on the chosen functions; a problem is
 * named in any case; the functions say what "Continue" does.
 */
const ownHint = computed(() => {
	const step = ownStep.value
	if (step === null || step.status === 'done') {
		return ''
	}
	if (step.key === 'modules') {
		return t('cantoria', '"Continue" keeps the functions as they are shown here.')
	}
	return step.key === 'roles' || step.status === 'problem' ? stepHint(step, props.settings.roleLabels) : ''
})

const allDone = computed(() => steps.value.every((step) => step.status === 'done'))

/** The forms with a button to save: "Continue" saves them */
const archiveForm = useTemplateRef<SavingForm>('archiveForm')
const rolesForm = useTemplateRef<SavingForm>('rolesForm')
const modulesForm = useTemplateRef<ChoosingForm>('modulesForm')
const pageForm = computed<SavingForm | null>(() => {
	if (current.value === 'archive') {
		return archiveForm.value
	}
	return current.value === 'roles' ? rolesForm.value : null
})
const dirty = computed(() => pageForm.value?.dirty === true)
const saving = ref(false)

/** The access check runs by itself when its page opens without a result */
const checkOnOpen = computed(() => report.value === null)

/** The heading of the page gets the focus on each change of page (WCAG 2.4.3) */
const heading = useTemplateRef<HTMLElement>('heading')
watch(current, () => {
	focusAfterUpdate(heading)
})

/**
 * Name of a page of the guided setup.
 *
 * @param key - the page
 */
function pageTitle(key: WizardStepKey): string {
	switch (key) {
	case 'welcome':
		return t('cantoria', 'Welcome')
	case 'catalog':
		return t('cantoria', 'Catalog and files')
	case 'done':
		return t('cantoria', 'Overview')
	default:
		return stepTitle(key)
	}
}

/**
 * State of a page for the list of pages: the state of its setup steps,
 * "done" only when all are done. Welcome and overview have none.
 *
 * @param key - the page
 */
function pageStatus(key: WizardStepKey): StepStatus | null {
	const own = wizardStepKeys(key).map((step) => steps.value.find((candidate) => candidate.key === step)?.status)
	if (own.length === 0) {
		return null
	}
	if (own.includes('problem')) {
		return 'problem'
	}
	if (own.every((status) => status === 'done')) {
		return 'done'
	}
	return own.includes('missing') ? 'missing' : 'unchecked'
}

/**
 * What the list of pages shows in front of a name: the number of a step
 * that is open, a check mark or a warning for one that is done or needs
 * attention, an icon for welcome and overview.
 */
const pages = computed(() => WIZARD_STEPS.map((key) => {
	const status = pageStatus(key)
	let icon: string | null = null
	if (key === 'welcome') {
		icon = mdiHandWaveOutline
	} else if (key === 'done') {
		icon = mdiFlagCheckered
	} else if (status === 'done' || status === 'problem') {
		icon = stepStatusIcon(status)
	}
	return { key, status, icon, number: wizardStepNumber(key) }
}))

/**
 * Go to a page; asks first when the form of this page has input that is not saved.
 *
 * @param key - the page
 */
async function go(key: WizardStepKey): Promise<void> {
	if (key !== current.value && !saving.value && await confirmDiscard()) {
		current.value = key
	}
}

/**
 * Close the dialog; asks first when the form of this page has input that is not saved.
 *
 * @param finished - "Finish" on the last page, not "Later"
 */
async function close(finished: boolean): Promise<void> {
	if (!saving.value && await confirmDiscard()) {
		emit('close', finished)
	}
}

/** To the complete backup on the settings page (closes the dialog). */
async function toBackup(): Promise<void> {
	if (await confirmDiscard()) {
		emit('section', 'backup')
	}
}

/**
 * "Continue": saves what the form of the page holds and goes on; stays when
 * saving failed (the form says why). "Finish" on the last page.
 */
async function next(): Promise<void> {
	if (isLast.value) {
		close(true)
		return
	}
	const target = WIZARD_STEPS[index.value + 1] as WizardStepKey
	const form = pageForm.value
	if (form === null || !form.dirty) {
		if (current.value === 'modules' && ownStep.value?.status !== 'done') {
			// Whoever goes on has seen the functions: they are chosen. A failure is shown and does not hold up.
			saving.value = true
			try {
				await modulesForm.value?.keepAsShown()
			} finally {
				saving.value = false
			}
		}
		go(target)
		return
	}
	saving.value = true
	try {
		if (await form.save()) {
			// the form follows the saved settings with the next update: nothing is left to ask about
			await nextTick()
			current.value = target
		}
	} finally {
		saving.value = false
	}
}

function back(): void {
	if (!isFirst.value) {
		go(WIZARD_STEPS[index.value - 1] as WizardStepKey)
	}
}

/**
 * Open the page of a setup step from the overview.
 *
 * @param key - the setup step
 */
function goToStep(key: StepKey): void {
	go(wizardStepOf(key))
}

/**
 * The dialog asks to close (Escape, close button): the same as "Later".
 *
 * @param open - new open state
 */
function onUpdateOpen(open: boolean): void {
	if (!open) {
		close(false)
	}
}

const nextLabel = computed(() => {
	if (isLast.value) {
		return t('cantoria', 'Finish')
	}
	if (dirty.value) {
		return t('cantoria', 'Save and continue')
	}
	return isFirst.value ? t('cantoria', 'Begin the setup') : t('cantoria', 'Continue')
})

const nextIcon = computed(() => {
	if (isLast.value) {
		return mdiCheck
	}
	return dirty.value ? mdiContentSaveOutline : mdiArrowRight
})
</script>

<template>
	<NcDialog :name="t('cantoria', 'Set up Cantoria')"
		:open="true"
		size="large"
		@update:open="onUpdateOpen">
		<div class="wizard">
			<nav class="wizard__nav" :aria-label="t('cantoria', 'Steps of the setup')">
				<ol class="wizard__pages">
					<li v-for="page in pages" :key="page.key">
						<button type="button"
							:class="['wizard__page', { 'wizard__page--current': page.key === current }]"
							:aria-current="page.key === current ? 'step' : undefined"
							@click="go(page.key)">
							<span :class="['wizard__mark', page.status === null ? 'wizard__mark--frame' : `wizard__mark--${page.status}`]" aria-hidden="true">
								<NcIconSvgWrapper v-if="page.icon !== null"
									:path="page.icon"
									:size="18"
									inline />
								<template v-else>{{ page.number }}</template>
							</span>
							<span class="wizard__page-name">{{ pageTitle(page.key) }}</span>
							<span v-if="page.status !== null" class="hidden-visually">{{ stepStatusText(page.status) }}</span>
						</button>
					</li>
				</ol>
			</nav>

			<section class="wizard__body" aria-labelledby="wizard-heading">
				<header class="wizard__header">
					<p v-if="wizardStepNumber(current) !== null" class="wizard__counter">
						{{ t('cantoria', 'Step {current} of {total}', { current: wizardStepNumber(current) ?? 0, total: WIZARD_STEP_COUNT }) }}
					</p>
					<div class="wizard__title">
						<h3 id="wizard-heading"
							ref="heading"
							class="wizard__heading"
							tabindex="-1">
							{{ pageTitle(current) }}
						</h3>
						<!-- State of the step of this page, in words -->
						<span v-if="ownStep" :class="['wizard__badge', `wizard__badge--${ownStep.status}`]">
							<NcIconSvgWrapper :path="stepStatusIcon(ownStep.status)" :size="18" inline />
							{{ stepStatusText(ownStep.status) }}
						</span>
					</div>
					<p v-if="ownHint !== ''" class="wizard__hint">
						{{ ownHint }}
					</p>
				</header>

				<template v-if="current === 'welcome'">
					<p>{{ t('cantoria', 'This guide leads you through the first setup of Cantoria in a few minutes. You can stop at any time and come back later.') }}</p>
					<p>{{ t('cantoria', 'You will need:') }}</p>
					<ul class="wizard__list">
						<li>{{ t('cantoria', 'a folder in Nextcloud for the score files (it may still be empty),') }}</li>
						<li>{{ t('cantoria', 'Nextcloud groups for the music librarians and, if you like, for the conductors and the choir members,') }}</li>
						<li>{{ t('cantoria', 'optionally your existing catalog as a CSV or spreadsheet file.') }}</li>
					</ul>
					<p class="wizard__muted">
						{{ t('cantoria', 'Every step can be skipped and changed later on the settings page.') }}
					</p>
				</template>

				<ArchiveFolderSettings v-else-if="current === 'archive'"
					ref="archiveForm"
					embedded
					:archive="settings.archive"
					:backups="settings.backups"
					:admin="settings.admin"
					:role-labels="settings.roleLabels"
					@saved="emit('saved', $event)" />

				<ModuleSettings v-else-if="current === 'modules'" ref="modulesForm" @changed="emit('modulesChanged', $event)" />

				<RoleSettings v-else-if="current === 'roles'"
					ref="rolesForm"
					embedded
					:settings="settings"
					@saved="emit('rolesSaved', $event)" />

				<template v-else-if="current === 'access'">
					<NcNoteCard v-if="settings.archive.id === 0" type="info">
						<p>{{ t('cantoria', 'Choose the archive folder first.') }}</p>
						<NcButton @click="go('archive')">
							{{ t('cantoria', 'To the archive folder') }}
						</NcButton>
					</NcNoteCard>
					<ArchiveAccessCheck v-else
						v-model:report="report"
						:archive="settings.archive"
						:role-labels="settings.roleLabels"
						:auto-start="checkOnOpen"
						embedded />
				</template>

				<template v-else-if="current === 'catalog'">
					<p>{{ t('cantoria', 'These two steps are done in the app by a music librarian (catalog role), not on this page.') }}</p>
					<ol class="wizard__summary">
						<li v-for="step in pageSteps" :key="step.key" class="wizard__summary-row">
							<strong class="wizard__summary-name">{{ stepTitle(step.key) }}</strong>
							<span :class="['wizard__badge', `wizard__badge--${step.status}`]">
								<NcIconSvgWrapper :path="stepStatusIcon(step.status)" :size="18" inline />
								{{ stepStatusText(step.status) }}
							</span>
							<span class="wizard__summary-text">{{ stepHint(step, settings.roleLabels) }}</span>
						</li>
					</ol>
					<div class="wizard__actions">
						<NcButton :href="appUrl">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArrowRight" />
							</template>
							{{ t('cantoria', 'Open Cantoria') }}
						</NcButton>
						<NcButton v-if="settings.admin" variant="tertiary" @click="toBackup">
							{{ t('cantoria', 'Restore a complete backup instead') }}
						</NcButton>
					</div>
					<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.setup)" :label="t('cantoria', 'Setting up in six steps (administration guide)')" />
				</template>

				<template v-else>
					<NcNoteCard v-if="allDone" type="success">
						{{ t('cantoria', 'Cantoria is set up. Everything can be changed later on the settings page.') }}
					</NcNoteCard>
					<p v-else>
						{{ t('cantoria', 'This is where the setup stands. Steps that are still open stay in the list "Setting up" on the settings page.') }}
					</p>
					<ol class="wizard__summary">
						<li v-for="step in steps" :key="step.key" class="wizard__summary-row">
							<strong class="wizard__summary-name">{{ stepTitle(step.key) }}</strong>
							<span :class="['wizard__badge', `wizard__badge--${step.status}`]">
								<NcIconSvgWrapper :path="stepStatusIcon(step.status)" :size="18" inline />
								{{ stepStatusText(step.status) }}
							</span>
							<NcButton v-if="step.status !== 'done'"
								class="wizard__summary-go"
								variant="tertiary"
								@click="goToStep(step.key)">
								{{ t('cantoria', 'Go to step') }}
								<span class="hidden-visually">{{ stepTitle(step.key) }}</span>
							</NcButton>
						</li>
					</ol>
					<NcButton :href="appUrl" variant="secondary">
						<template #icon>
							<NcIconSvgWrapper :path="mdiArrowRight" />
						</template>
						{{ t('cantoria', 'Open Cantoria') }}
					</NcButton>
				</template>
			</section>
		</div>

		<template #actions>
			<!-- On the last page "Finish" closes; "Later" would do the same -->
			<NcButton v-if="!isLast"
				class="wizard__later"
				variant="tertiary"
				:disabled="saving"
				@click="close(false)">
				{{ t('cantoria', 'Later') }}
			</NcButton>
			<NcButton v-if="!isFirst" :disabled="saving" @click="back">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
				{{ t('cantoria', 'Back') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="next">
				<template #icon>
					<NcLoadingIcon v-if="saving" />
					<NcIconSvgWrapper v-else :path="nextIcon" />
				</template>
				{{ nextLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
/* A height of its own, so that the buttons below do not jump from page to page */
.wizard {
	display: grid;
	grid-template-columns: minmax(12em, 15em) 1fr;
	align-items: start;
	gap: calc(6 * var(--default-grid-baseline));
	min-height: min(30em, 60vh);
	padding-block: var(--default-grid-baseline);
}

/* On a phone the list of pages would push the form down: the counter and the buttons remain */
@media (max-width: 720px) {
	.wizard {
		grid-template-columns: 1fr;
		min-height: 0;
	}

	.wizard__nav {
		display: none;
	}

	/* Three buttons do not fit side by side; the close button of the dialog does the same as "Later" */
	.wizard__later {
		display: none;
	}
}

.wizard p {
	max-width: 70ch;
	margin: 0;
}

.wizard__nav {
	position: sticky;
	top: 0;
}

.wizard__pages {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.wizard__page {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border: none;
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.wizard__page:hover,
.wizard__page:focus-visible {
	background: var(--color-background-hover);
}

.wizard__page--current,
.wizard__page--current:hover {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.wizard__page-name {
	flex: 1;
}

/* In front of a name: the number of an open step in a ring, an icon otherwise */
.wizard__mark {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	width: 1.7em;
	height: 1.7em;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 50%;
	font-size: 0.85em;
	font-weight: normal;
}

.wizard__mark--frame,
.wizard__mark--done,
.wizard__mark--problem {
	border-color: transparent;
}

.wizard__mark--done {
	color: var(--color-success-text, var(--color-main-text));
}

.wizard__mark--problem {
	color: var(--color-warning-text, var(--color-main-text));
}

.wizard__body {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	min-width: 0;
}

.wizard__header {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.wizard__counter,
.wizard__muted,
.wizard__hint {
	color: var(--color-text-maxcontrast);
}

.wizard__title {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
}

.wizard__heading {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
}

.wizard__heading:focus {
	outline: none;
}

.wizard__heading:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

/* The state of a step: icon and words, never colour alone */
.wizard__badge {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: 2px calc(2.5 * var(--default-grid-baseline)) 2px calc(1.5 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill, 100px);
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	white-space: nowrap;
}

.wizard__badge--done {
	border-color: var(--color-success-text, var(--color-border-maxcontrast));
	color: var(--color-success-text, var(--color-main-text));
}

.wizard__badge--problem {
	border-color: var(--color-warning-text, var(--color-border-maxcontrast));
	color: var(--color-warning-text, var(--color-main-text));
	font-weight: bold;
}

.wizard__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 70ch;
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}

.wizard__summary {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.wizard__summary-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
	min-height: calc(var(--default-clickable-area) + 2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.wizard__summary-row:last-child {
	border-bottom: none;
}

.wizard__summary-name {
	flex: 1 1 12em;
}

.wizard__summary-go {
	flex-shrink: 0;
}

/* What a step is about, below its name and state */
.wizard__summary-text {
	flex-basis: 100%;
	max-width: 70ch;
	padding-block-end: calc(2 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.wizard__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
