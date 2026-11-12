<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The guided setup: a dialog that leads through the first configuration,
 * one page at a time. A welcome, then the four steps of the settings page
 * (archive folder, functions, roles and groups, access check), each with the
 * same form as on the page, a page about the catalog (import and file sync
 * happen in the app) and a summary. Every page says in words whether its
 * step is done; nothing is saved by going on, each form keeps its own
 * button. "Later" closes the dialog; the page keeps the list "Setting up"
 * and the button to come back.
 */
import type { SetupStep, StepKey, WizardStepKey } from '../../settings.ts'
import type { AccessReport, ModuleInfo, Settings } from '../../types.ts'

import { mdiArrowLeft, mdiArrowRight, mdiCheck } from '@mdi/js'
import { t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, useTemplateRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ArchiveAccessCheck from './ArchiveAccessCheck.vue'
import ArchiveFolderSettings from './ArchiveFolderSettings.vue'
import RoleSettings from './RoleSettings.vue'
import GuideLink from '../GuideLink.vue'
import ModuleSettings from '../ModuleSettings.vue'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { focusAfterUpdate } from '../../focus.ts'
import { setupSteps, WIZARD_STEPS, wizardStartStep, wizardStepKeys, wizardStepOf } from '../../settings.ts'
import { stepHint, stepStatusIcon, stepStatusText, stepTitle } from '../../setupLabels.ts'

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
 * "done" only when all are done. Welcome and summary have none.
 *
 * @param key - the page
 */
function pageStatus(key: WizardStepKey): SetupStep['status'] | null {
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
 * Go to a page.
 *
 * @param key - the page
 */
function go(key: WizardStepKey): void {
	current.value = key
}

function next(): void {
	if (isLast.value) {
		emit('close', true)
		return
	}
	go(WIZARD_STEPS[index.value + 1] as WizardStepKey)
}

function back(): void {
	if (!isFirst.value) {
		go(WIZARD_STEPS[index.value - 1] as WizardStepKey)
	}
}

/**
 * Open the page of a setup step from the summary.
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
		emit('close', false)
	}
}

const nextLabel = computed(() => {
	if (isLast.value) {
		return t('cantoria', 'Finish')
	}
	return isFirst.value ? t('cantoria', 'Begin the setup') : t('cantoria', 'Continue')
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
					<li v-for="(key, position) in WIZARD_STEPS" :key="key">
						<button type="button"
							:class="['wizard__page', { 'wizard__page--current': key === current }]"
							:aria-current="key === current ? 'step' : undefined"
							@click="go(key)">
							<span class="wizard__number" aria-hidden="true">{{ position + 1 }}</span>
							<span class="wizard__page-name">{{ pageTitle(key) }}</span>
							<template v-if="pageStatus(key) !== null">
								<NcIconSvgWrapper :class="['wizard__icon', `wizard__icon--${pageStatus(key)}`]"
									:path="stepStatusIcon(pageStatus(key) ?? 'missing')"
									:size="16"
									inline />
								<span class="hidden-visually">{{ stepStatusText(pageStatus(key) ?? 'missing') }}</span>
							</template>
						</button>
					</li>
				</ol>
			</nav>

			<section class="wizard__body" :aria-labelledby="'wizard-heading'">
				<p class="wizard__counter">
					{{ t('cantoria', 'Step {current} of {total}', { current: index + 1, total: WIZARD_STEPS.length }) }}
				</p>
				<h3 id="wizard-heading"
					ref="heading"
					class="wizard__heading"
					tabindex="-1">
					{{ pageTitle(current) }}
				</h3>

				<!-- State of the step(s) of this page, in words -->
				<ul v-if="pageSteps.length > 0" class="wizard__states">
					<li v-for="step in pageSteps" :key="step.key" :class="['wizard__state', `wizard__state--${step.status}`]">
						<NcIconSvgWrapper class="wizard__icon"
							:path="stepStatusIcon(step.status)"
							:size="20"
							inline />
						<span>
							<strong v-if="pageSteps.length > 1">{{ stepTitle(step.key) }}: </strong>
							<strong v-else>{{ stepStatusText(step.status) }}</strong>
							<template v-if="pageSteps.length > 1">{{ stepStatusText(step.status) }}</template>
							<template v-if="step.status !== 'done' && pageSteps.length === 1"> – {{ stepHint(step, settings.roleLabels) }}</template>
						</span>
					</li>
				</ul>

				<template v-if="current === 'welcome'">
					<p>{{ t('cantoria', 'This guide leads you through the first setup of Cantoria in a few minutes. You can stop at any time and come back later.') }}</p>
					<p>{{ t('cantoria', 'You will need:') }}</p>
					<ul class="wizard__list">
						<li>{{ t('cantoria', 'a folder in Nextcloud for the score files (it may still be empty),') }}</li>
						<li>{{ t('cantoria', 'Nextcloud groups for the music librarians and, if you like, for the conductors and the choir members,') }}</li>
						<li>{{ t('cantoria', 'optionally your existing catalog as a CSV or spreadsheet file.') }}</li>
					</ul>
					<p class="wizard__muted">
						{{ t('cantoria', 'Each page has the same form as the settings page. Save there before you go on; going on saves nothing.') }}
					</p>
				</template>

				<ArchiveFolderSettings v-else-if="current === 'archive'"
					:archive="settings.archive"
					:backups="settings.backups"
					:admin="settings.admin"
					:role-labels="settings.roleLabels"
					@saved="emit('saved', $event)" />

				<ModuleSettings v-else-if="current === 'modules'" @changed="emit('modulesChanged', $event)" />

				<RoleSettings v-else-if="current === 'roles'" :settings="settings" @saved="emit('rolesSaved', $event)" />

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
						:role-labels="settings.roleLabels" />
				</template>

				<template v-else-if="current === 'catalog'">
					<p>{{ t('cantoria', 'The catalog and the files are brought in by a music librarian, in the app under Archive management:') }}</p>
					<ol class="wizard__list">
						<li>{{ t('cantoria', 'Import catalog: a template, a dry run, then the import. A choir without a catalog file starts with the first new accession.') }}</li>
						<li>{{ t('cantoria', 'Run file sync: it links the files of the archive folder to the copies.') }}</li>
					</ol>
					<div class="wizard__actions">
						<NcButton :href="appUrl">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArrowRight" />
							</template>
							{{ t('cantoria', 'Open Cantoria') }}
						</NcButton>
						<NcButton v-if="settings.admin" variant="tertiary" @click="emit('section', 'backup')">
							{{ t('cantoria', 'Restore a complete backup instead') }}
						</NcButton>
					</div>
					<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.setup)" :label="t('cantoria', 'Setting up in six steps (administration guide)')" />
				</template>

				<template v-else>
					<p>{{ t('cantoria', 'This is where the setup stands. Steps that are still open stay in the list "Setting up" on the settings page.') }}</p>
					<ol class="wizard__summary">
						<li v-for="step in steps" :key="step.key" :class="['wizard__state', `wizard__state--${step.status}`]">
							<NcIconSvgWrapper class="wizard__icon"
								:path="stepStatusIcon(step.status)"
								:size="20"
								inline />
							<span class="wizard__summary-text">
								<strong>{{ stepTitle(step.key) }}</strong> – {{ stepStatusText(step.status) }}
							</span>
							<NcButton v-if="step.status !== 'done'" variant="tertiary" @click="goToStep(step.key)">
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
			<NcButton variant="tertiary" @click="emit('close', false)">
				{{ t('cantoria', 'Later') }}
			</NcButton>
			<NcButton v-if="!isFirst" @click="back">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
				{{ t('cantoria', 'Back') }}
			</NcButton>
			<NcButton variant="primary" @click="next">
				<template #icon>
					<NcIconSvgWrapper :path="isLast ? mdiCheck : mdiArrowRight" />
				</template>
				{{ nextLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.wizard {
	display: grid;
	grid-template-columns: minmax(12em, 16em) 1fr;
	gap: calc(4 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
}

/* On a phone the list of pages would push the form down: the counter and the buttons remain */
@media (max-width: 720px) {
	.wizard {
		grid-template-columns: 1fr;
	}

	.wizard__nav {
		display: none;
	}
}

.wizard p {
	max-width: 70ch;
	margin: 0;
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
	padding-inline: calc(2 * var(--default-grid-baseline));
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

.wizard__page--current {
	background: var(--color-primary-element-light);
	font-weight: bold;
}

.wizard__page-name {
	flex: 1;
}

.wizard__number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 1.6em;
	height: 1.6em;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 50%;
	font-size: 0.85em;
}

.wizard__body {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	min-width: 0;
}

.wizard__counter,
.wizard__muted {
	color: var(--color-text-maxcontrast);
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

.wizard__states,
.wizard__summary {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.wizard__state {
	display: flex;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
	max-width: 70ch;
}

.wizard__summary .wizard__state {
	align-items: center;
}

.wizard__summary-text {
	flex: 1;
}

.wizard__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 70ch;
	margin: 0;
	padding-inline-start: 1.5em;
}

ul.wizard__list {
	list-style: disc;
}

ol.wizard__list {
	list-style: decimal;
}

.wizard__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.wizard__icon--done,
.wizard__state--done .wizard__icon {
	color: var(--color-success-text, var(--color-main-text));
}

.wizard__icon--problem,
.wizard__state--problem .wizard__icon {
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
