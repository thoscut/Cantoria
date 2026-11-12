<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The setup in six steps at the top of the settings page: archive folder,
 * the functions for the choir, roles and groups (the roles these functions
 * need), access to the archive folder, import, file sync. Each
 * step shows its state in words and with an icon (never by colour alone),
 * says what to do and links the place where it is done. The states are
 * derived from what the page has loaded; nothing is stored.
 */
import type { SetupStep, StepKey } from '../../settings.ts'
import type { AccessReport, Settings } from '../../types.ts'

import { t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import GuideLink from '../GuideLink.vue'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { SETTINGS_SECTIONS, setupSteps } from '../../settings.ts'
import { stepHint, stepStatusIcon as statusIcon, stepStatusText as statusText, stepTitle as title } from '../../setupLabels.ts'

const props = defineProps<{
	settings: Settings
	/** the access check of the saved archive folder, if it ran on this page */
	report: AccessReport | null
}>()

const steps = computed(() => setupSteps(props.settings, props.report))
const appUrl = generateUrl('/apps/cantoria/')

/**
 * What to do for a step that is not done.
 *
 * @param step - the step
 */
function hint(step: SetupStep): string {
	return stepHint(step, props.settings.roleLabels)
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
