<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The setup in six steps at the top of the settings page: archive folder,
 * roles and groups, access to the archive folder, the functions for the
 * choir, import, file sync. Each
 * step shows its state in words and with an icon (never by colour alone),
 * says what to do and links the place where it is done. The states are
 * derived from what the page has loaded; nothing is stored.
 */
import type { SetupStep, StepKey, StepStatus } from '../../settings.ts'
import type { AccessReport, Settings } from '../../types.ts'

import { mdiAlertOutline, mdiCheckCircleOutline, mdiCircleOutline, mdiHelpCircleOutline } from '@mdi/js'
import { t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import GuideLink from '../GuideLink.vue'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { SETTINGS_SECTIONS, setupSteps } from '../../settings.ts'

const props = defineProps<{
	settings: Settings
	/** the access check of the saved archive folder, if it ran on this page */
	report: AccessReport | null
}>()

const steps = computed(() => setupSteps(props.settings, props.report))
const appUrl = generateUrl('/apps/cantoria/')

/**
 * Name of a step.
 *
 * @param key - the step
 */
function title(key: StepKey): string {
	switch (key) {
	case 'archive':
		return t('cantoria', 'Archive folder')
	case 'roles':
		return t('cantoria', 'Roles and groups')
	case 'access':
		return t('cantoria', 'Access to the archive folder')
	case 'modules':
		return t('cantoria', 'Functions for your choir')
	case 'import':
		return t('cantoria', 'Import catalog')
	default:
		return t('cantoria', 'File sync')
	}
}

/**
 * State of a step in words.
 *
 * @param status - state of the step
 */
function statusText(status: StepStatus): string {
	switch (status) {
	case 'done':
		return t('cantoria', 'Completed')
	case 'missing':
		return t('cantoria', 'Still to do')
	case 'problem':
		return t('cantoria', 'Needs attention')
	default:
		return t('cantoria', 'Not checked yet')
	}
}

/**
 * Icon of a state (the text says the same).
 *
 * @param status - state of the step
 */
function statusIcon(status: StepStatus): string {
	switch (status) {
	case 'done':
		return mdiCheckCircleOutline
	case 'problem':
		return mdiAlertOutline
	case 'unchecked':
		return mdiHelpCircleOutline
	default:
		return mdiCircleOutline
	}
}

/**
 * What to do for a step that is not done.
 *
 * @param step - the step
 */
function hint(step: SetupStep): string {
	switch (step.key) {
	case 'archive':
		return t('cantoria', 'Choose the folder in Nextcloud that holds the score files.')
	case 'roles':
		return step.status === 'problem'
			? t('cantoria', 'A group of a role no longer exists. Choose another group for the role.')
			: t('cantoria', 'Give at least the catalog role (music librarians) a group.')
	case 'access':
		if (step.status === 'missing') {
			return t('cantoria', 'Choose the archive folder first.')
		}
		return step.status === 'problem'
			? t('cantoria', 'Some accounts cannot open the archive folder. Share it with their groups (reading is enough) and check again.')
			: t('cantoria', 'Check whether everybody with a role can open the archive folder.')
	case 'modules':
		return t('cantoria', 'Choose what Cantoria should do for your choir: keep the archive in order, plan concerts, let the singers practise, or publish pages.')
	case 'import':
		return t('cantoria', 'A music librarian imports the catalog in the app: Archive management → Import catalog.')
	default:
		return t('cantoria', 'A music librarian links the files in the app: Archive management → Run file sync.')
	}
}

/**
 * Where a step is done: a section of this page, or the app.
 *
 * @param key - the step
 */
function target(key: StepKey): { href: string, label: string } {
	switch (key) {
	case 'archive':
		return { href: '#' + SETTINGS_SECTIONS.archive, label: t('cantoria', 'To the archive folder') }
	case 'roles':
		return { href: '#' + SETTINGS_SECTIONS.roles, label: t('cantoria', 'To roles and groups') }
	case 'access':
		return { href: '#' + SETTINGS_SECTIONS.access, label: t('cantoria', 'To the access check') }
	case 'modules':
		return { href: '#' + SETTINGS_SECTIONS.modules, label: t('cantoria', 'To the functions') }
	default:
		return { href: appUrl, label: t('cantoria', 'Open Cantoria') }
	}
}
</script>

<template>
	<div class="setup">
		<h3 class="setup__heading">
			{{ t('cantoria', 'Setting up') }}
		</h3>
		<ol class="setup__steps">
			<li v-for="step in steps" :key="step.key" :class="['setup__step', `setup__step--${step.status}`]">
				<span class="setup__line">
					<NcIconSvgWrapper class="setup__icon"
						:path="statusIcon(step.status)"
						:size="20"
						inline />
					<strong>{{ title(step.key) }}</strong>
					<span class="setup__state">{{ statusText(step.status) }}</span>
				</span>
				<template v-if="step.status !== 'done'">
					<span class="setup__hint">{{ hint(step) }}</span>
					<a class="setup__link" :href="target(step.key).href">{{ target(step.key).label }}</a>
				</template>
			</li>
		</ol>
		<p>
			<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.setup)" :label="t('cantoria', 'Setting up in six steps (administration guide)')" />
		</p>
	</div>
</template>

<style scoped>
.setup {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	max-width: 70ch;
}

.setup p {
	margin: 0;
}

.setup__heading {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.setup__steps {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: decimal;
}

.setup__step {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.setup__line {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.setup__state::before {
	content: '– ';
}

.setup__step--done .setup__icon {
	color: var(--color-success-text, var(--color-main-text));
}

.setup__step--problem .setup__icon,
.setup__step--problem .setup__state {
	color: var(--color-warning-text, var(--color-main-text));
	font-weight: bold;
}

.setup__hint {
	color: var(--color-text-maxcontrast);
}

.setup__link {
	align-self: flex-start;
	text-decoration: underline;
}
</style>
