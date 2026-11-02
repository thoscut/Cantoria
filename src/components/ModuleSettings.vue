<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings "Modules" (Administration → Cantoria): the core that is
 * always on, what Cantoria should do for the choir in words (scenarios,
 * with the one the switches match or come closest to), the presets and one
 * switch per module, grouped; the notifications folded. Labels and
 * descriptions come translated from the server. Switching a module on also
 * switches on what it requires (the server does it); switching off a module
 * that others need asks first and lists them. The server checks everything
 * again. Needs no catalog: it loads its own data and tells the page about
 * each change (`changed`).
 */
import type { ModuleChanges, ModuleInfo, ModulePreset, ModuleScenario, ModuleSettings } from '../types.ts'

import { mdiCheckCircleOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, fetchModuleSettings, saveModules } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { closestScenario, inactiveBecause, moduleLabels, presetChanges, stopsWorking, switchedOnWith } from '../modules.ts'
import { showError } from '../toasts.ts'

const emit = defineEmits<{
	/** modules were switched: what depends on them may have changed */
	changed: [modules: ModuleInfo[]]
}>()

const settings = ref<ModuleSettings | null>(null)
const loading = ref(false)
const loadError = ref('')
const busy = ref(false)
/** Result of the last change, announced in a status region */
const statusText = ref('')

const modules = computed<ModuleInfo[]>(() => settings.value?.modules ?? [])

/** Groups with their modules, in the order of the server. */
const groups = computed(() => (settings.value?.groups ?? [])
	.map((group) => ({ ...group, modules: modules.value.filter((module) => module.group === group.key) }))
	.filter((group) => group.modules.length > 0))

async function load(): Promise<void> {
	loading.value = true
	loadError.value = ''
	try {
		settings.value = await fetchModuleSettings()
	} catch (error) {
		loadError.value = errorMessage(error)
	} finally {
		loading.value = false
	}
}

onMounted(load)

/**
 * Labels of modules as one text.
 *
 * @param keys - module keys
 */
function labelsOf(keys: readonly string[]): string {
	return moduleLabels(keys, modules.value).join(', ')
}

/**
 * What a module needs, as text.
 *
 * @param module - the module
 */
function needsText(module: ModuleInfo): string {
	return module.requires.length > 0 ? t('cantoria', 'Needs: {modules}.', { modules: labelsOf(module.requires) }) : ''
}

/**
 * Why a module whose switch is on does not work, as text.
 *
 * @param module - the module
 */
function inactiveText(module: ModuleInfo): string {
	const because = inactiveBecause(module, modules.value)
	if (because.length === 0) {
		return ''
	}
	return t('cantoria', 'Switched on, but not working at the moment, because this is switched off: {modules}.', { modules: because.map((other) => other.label).join(', ') })
}

/**
 * The warning at a public module while Nextcloud's theming has no imprint or
 * no privacy policy: the public pages show both in their footer.
 *
 * @param module - the module
 */
function legalText(module: ModuleInfo): string {
	const legal = settings.value?.legalPages
	if (!legal || !settings.value?.publicModules.includes(module.key) || (legal.imprint && legal.privacy)) {
		return ''
	}
	return t('cantoria', 'Before these pages go online: enter the addresses of the imprint and the privacy policy under Administration → Theming. The public pages show them; the privacy policy must name the server logs with IP addresses.')
}

/**
 * Announce what a change did.
 *
 * @param changes - changes reported by the server
 */
function announce(changes: ModuleChanges): void {
	const parts: string[] = []
	if (changes.enabled.length > 0) {
		parts.push(t('cantoria', 'Switched on: {modules}.', { modules: labelsOf(changes.enabled) }))
	}
	if (changes.alsoEnabled.length > 0) {
		parts.push(t('cantoria', 'Also switched on, because it is needed: {modules}.', { modules: labelsOf(changes.alsoEnabled) }))
	}
	if (changes.disabled.length > 0) {
		parts.push(t('cantoria', 'Switched off: {modules}.', { modules: labelsOf(changes.disabled) }))
	}
	if (changes.inactive.length > 0) {
		parts.push(t('cantoria', 'No longer working: {modules}.', { modules: labelsOf(changes.inactive) }))
	}
	if (changes.activated.length > 0) {
		parts.push(t('cantoria', 'Working again: {modules}.', { modules: labelsOf(changes.activated) }))
	}
	statusText.value = parts.length > 0 ? parts.join(' ') : t('cantoria', 'Nothing has changed.')
}

/**
 * Send a change to the server, then reload what the app shows.
 *
 * @param change - switches or a preset
 */
async function apply(change: { modules: Record<string, boolean> } | { preset: string } | { scenario: string }): Promise<boolean> {
	busy.value = true
	statusText.value = ''
	try {
		const result = await saveModules(change)
		settings.value = {
			modules: result.modules,
			groups: result.groups,
			presets: result.presets,
			scenarios: result.scenarios,
			core: result.core,
			publicModules: result.publicModules,
			legalPages: result.legalPages,
		}
		announce(result.changes)
		// E.g. the repertoire thresholds of the page follow the new state
		emit('changed', result.modules)
		return true
	} catch (error) {
		showError(errorMessage(error))
		return false
	} finally {
		busy.value = false
	}
}

// ---- switching a module --------------------------------------------------------

/** A module to switch off that others need: asked first. */
const pendingOff = ref<{ module: ModuleInfo, stopping: ModuleInfo[] } | null>(null)

/**
 * A switch was changed.
 *
 * @param module - the module
 * @param on - new state of its switch
 */
async function onToggle(module: ModuleInfo, on: boolean): Promise<void> {
	if (!on) {
		const stopping = stopsWorking(module.key, modules.value)
		if (stopping.length > 0) {
			pendingOff.value = { module, stopping }
			return
		}
	}
	await apply({ modules: { [module.key]: on } })
}

/**
 * Id of the switch of a module, to give it the focus back after a dialog.
 *
 * @param key - module key
 */
function switchId(key: string): string {
	return `module-switch-${key}`
}

async function confirmOff(): Promise<void> {
	const pending = pendingOff.value
	if (pending === null) {
		return
	}
	await apply({ modules: { [pending.module.key]: false } })
	pendingOff.value = null
	await focusAfterDialog(() => document.getElementById(switchId(pending.module.key)))
}

function cancelOff(): void {
	const key = pendingOff.value?.module.key ?? ''
	pendingOff.value = null
	focusAfterDialog(() => document.getElementById(switchId(key)))
}

// ---- presets -------------------------------------------------------------------

const pendingPreset = ref<ModulePreset | null>(null)

const presetDiff = computed(() => (pendingPreset.value === null
	? { on: [], off: [] }
	: presetChanges(pendingPreset.value.modules, modules.value)))

async function confirmPreset(): Promise<void> {
	const preset = pendingPreset.value
	if (preset === null) {
		return
	}
	await apply({ preset: preset.key })
	pendingPreset.value = null
	await focusAfterDialog(() => document.getElementById(`module-preset-${preset.key}`))
}

function cancelPreset(): void {
	const key = pendingPreset.value?.key ?? ''
	pendingPreset.value = null
	focusAfterDialog(() => document.getElementById(`module-preset-${key}`))
}

// ---- scenarios -----------------------------------------------------------------

/** The scenario the switches match or come closest to */
const closest = computed(() => closestScenario(settings.value?.scenarios ?? [], modules.value))

/** Where the switches stand, in words */
const currentText = computed(() => {
	const found = closest.value
	if (found === null) {
		return ''
	}
	const count = found.differences.length
	if (count === 0) {
		return t('cantoria', 'Currently: “{scenario}”.', { scenario: found.scenario.label })
	}
	if (count > 4) {
		return n('cantoria', 'Currently closest to “{scenario}”, with %n difference.', 'Currently closest to “{scenario}”, with %n differences.', count, { scenario: found.scenario.label })
	}
	const named = found.differences.map((module) => module.enabled
		? t('cantoria', '{module} (on)', { module: module.label })
		: t('cantoria', '{module} (off)', { module: module.label })).join(', ')
	return n('cantoria', 'Currently closest to “{scenario}”, with %n difference: {modules}.', 'Currently closest to “{scenario}”, with %n differences: {modules}.', count, { scenario: found.scenario.label, modules: named })
})

/**
 * Whether the switches are exactly as a scenario sets them.
 *
 * @param scenario - the scenario
 */
function isCurrent(scenario: ModuleScenario): boolean {
	return closest.value?.scenario.key === scenario.key && closest.value.differences.length === 0
}

const pendingScenario = ref<ModuleScenario | null>(null)

const scenarioDiff = computed(() => (pendingScenario.value === null
	? { on: [], off: [] }
	: presetChanges(pendingScenario.value.modules, modules.value)))

async function confirmScenario(): Promise<void> {
	const scenario = pendingScenario.value
	if (scenario === null) {
		return
	}
	await apply({ scenario: scenario.key })
	pendingScenario.value = null
	await focusAfterDialog(() => document.getElementById(`module-scenario-${scenario.key}`))
}

function cancelScenario(): void {
	const key = pendingScenario.value?.key ?? ''
	pendingScenario.value = null
	focusAfterDialog(() => document.getElementById(`module-scenario-${key}`))
}

/**
 * How many modules of a group are switched on, for a folded group.
 *
 * @param group - the group with its modules
 * @param group.modules - its modules
 */
function onCount(group: { modules: ModuleInfo[] }): number {
	return group.modules.filter((module) => module.enabled).length
}

/**
 * What switching a module on also switches on, for its description.
 *
 * @param module - the module
 */
function alsoOnText(module: ModuleInfo): string {
	if (module.enabled) {
		return ''
	}
	const also = switchedOnWith(module.key, modules.value)
	return also.length > 0 ? t('cantoria', 'Switching it on also switches on: {modules}.', { modules: also.map((other) => other.label).join(', ') }) : ''
}
</script>

<template>
	<div class="module-settings">
		<p class="module-settings__intro">
			{{ t('cantoria', 'Switch off what your choir does not need. A switched-off function disappears from the app and is off on the server, too: no routes, no background jobs, no notifications. Its data are kept and are back when you switch it on again. Switching off never relaxes a check of the rights.') }}
		</p>

		<p v-if="loading" class="module-settings__busy">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Loading …') }}
		</p>
		<NcNoteCard v-else-if="loadError" type="error">
			<p>{{ loadError }}</p>
			<NcButton @click="load">
				{{ t('cantoria', 'Try again') }}
			</NcButton>
		</NcNoteCard>

		<template v-else-if="settings">
			<section class="module-settings__core" aria-labelledby="module-settings-core">
				<h3 id="module-settings-core">
					{{ t('cantoria', 'Always on') }}
				</h3>
				<ul>
					<li v-for="text in settings.core" :key="text">
						{{ text }}
					</li>
				</ul>
			</section>

			<section aria-labelledby="module-settings-scenarios">
				<h3 id="module-settings-scenarios">
					{{ t('cantoria', 'What should Cantoria do for your choir?') }}
				</h3>
				<p v-if="currentText" class="module-settings__current">
					{{ currentText }}
				</p>
				<ul class="module-settings__scenarios">
					<li v-for="scenario in settings.scenarios"
						:key="scenario.key"
						:class="['module-settings__scenario', { 'module-settings__scenario--current': isCurrent(scenario) }]">
						<strong class="module-settings__scenario-title">{{ scenario.label }}</strong>
						<span :id="`module-scenario-${scenario.key}-description`" class="module-settings__scenario-text">
							<span>{{ scenario.description }}</span>
							<span v-if="scenario.rights" class="module-settings__muted">{{ scenario.rights }}</span>
						</span>
						<span v-if="isCurrent(scenario)" class="module-settings__scenario-state">
							<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" inline />
							{{ t('cantoria', 'Current') }}
						</span>
						<NcButton v-else
							:id="`module-scenario-${scenario.key}`"
							:disabled="busy"
							:aria-describedby="`module-scenario-${scenario.key}-description`"
							aria-haspopup="dialog"
							@click="pendingScenario = scenario">
							{{ t('cantoria', 'Choose') }}
							<span class="hidden-visually">{{ scenario.label }}</span>
						</NcButton>
					</li>
				</ul>
			</section>

			<section aria-labelledby="module-settings-presets">
				<h3 id="module-settings-presets">
					{{ t('cantoria', 'Presets') }}
				</h3>
				<ul class="module-settings__presets">
					<li v-for="preset in settings.presets" :key="preset.key">
						<NcButton :id="`module-preset-${preset.key}`"
							:disabled="busy"
							:aria-describedby="`module-preset-${preset.key}-description`"
							aria-haspopup="dialog"
							@click="pendingPreset = preset">
							{{ preset.label }}
						</NcButton>
						<span :id="`module-preset-${preset.key}-description`" class="module-settings__muted">
							{{ preset.description }}
						</span>
					</li>
				</ul>
			</section>

			<!-- Result of the last change; always present, so that screen readers announce it -->
			<div class="module-settings__status" role="status">
				<NcNoteCard v-if="statusText" type="success">
					{{ statusText }}
				</NcNoteCard>
			</div>

			<template v-for="group in groups" :key="group.key">
				<details v-if="group.key === 'notifications'" class="module-settings__fold">
					<summary>
						{{ group.label }}
						<span class="module-settings__muted">{{ t('cantoria', '({on} of {total} switched on)', { on: onCount(group), total: group.modules.length }) }}</span>
					</summary>
					<fieldset class="module-settings__group" :disabled="busy">
						<legend class="hidden-visually">
							{{ group.label }}
						</legend>
						<div v-for="module in group.modules" :key="module.key" class="module-settings__module">
							<NcCheckboxRadioSwitch :id="switchId(module.key)"
								type="switch"
								:model-value="module.enabled"
								:disabled="busy"
								@update:model-value="onToggle(module, $event)">
								{{ module.label }}
								<template #description>
									<span class="module-settings__description">{{ module.description }}</span>
									<span v-if="needsText(module)" class="module-settings__description">{{ needsText(module) }}</span>
									<span v-if="alsoOnText(module)" class="module-settings__description">{{ alsoOnText(module) }}</span>
									<span v-if="inactiveText(module)" class="module-settings__description module-settings__description--inactive">
										{{ inactiveText(module) }}
									</span>
								</template>
							</NcCheckboxRadioSwitch>
						</div>
					</fieldset>
				</details>
				<fieldset v-else
					class="module-settings__group"
					:disabled="busy">
					<legend>{{ group.label }}</legend>
					<div v-for="module in group.modules" :key="module.key" class="module-settings__module">
						<NcCheckboxRadioSwitch :id="switchId(module.key)"
							type="switch"
							:model-value="module.enabled"
							:disabled="busy"
							@update:model-value="onToggle(module, $event)">
							{{ module.label }}
							<template #description>
								<span class="module-settings__description">{{ module.description }}</span>
								<span v-if="needsText(module)" class="module-settings__description">{{ needsText(module) }}</span>
								<span v-if="alsoOnText(module)" class="module-settings__description">{{ alsoOnText(module) }}</span>
								<span v-if="legalText(module)" class="module-settings__description module-settings__description--inactive">{{ legalText(module) }}</span>
								<span v-if="inactiveText(module)" class="module-settings__description module-settings__description--inactive">
									{{ inactiveText(module) }}
								</span>
							</template>
						</NcCheckboxRadioSwitch>
					</div>
				</fieldset>
			</template>
		</template>

		<ActionDialog v-if="pendingOff !== null"
			:name="t('cantoria', 'Switch off {module}?', { module: pendingOff.module.label })"
			:submit-label="t('cantoria', 'Switch off')"
			:busy="busy"
			@submit="confirmOff"
			@close="cancelOff">
			<p>{{ t('cantoria', 'These functions need it and stop working:') }}</p>
			<ul class="module-settings__list">
				<li v-for="other in pendingOff.stopping" :key="other.key">
					{{ other.label }}
				</li>
			</ul>
			<p>{{ t('cantoria', 'Their switches stay as they are; they work again when you switch {module} on. No data are deleted.', { module: pendingOff.module.label }) }}</p>
		</ActionDialog>

		<ActionDialog v-if="pendingScenario !== null"
			:name="t('cantoria', 'Choose “{scenario}”?', { scenario: pendingScenario.label })"
			:submit-label="t('cantoria', 'Choose')"
			:busy="busy"
			@submit="confirmScenario"
			@close="cancelScenario">
			<p>{{ pendingScenario.description }}</p>
			<NcNoteCard v-if="pendingScenario.rights" type="info">
				{{ pendingScenario.rights }}
			</NcNoteCard>
			<template v-if="scenarioDiff.on.length > 0">
				<p>{{ t('cantoria', 'Switched on:') }}</p>
				<ul class="module-settings__list">
					<li v-for="module in scenarioDiff.on" :key="module.key">
						{{ module.label }}
					</li>
				</ul>
			</template>
			<template v-if="scenarioDiff.off.length > 0">
				<p>{{ t('cantoria', 'Switched off:') }}</p>
				<ul class="module-settings__list">
					<li v-for="module in scenarioDiff.off" :key="module.key">
						{{ module.label }}
					</li>
				</ul>
			</template>
			<p v-if="scenarioDiff.on.length === 0 && scenarioDiff.off.length === 0">
				{{ t('cantoria', 'The switches already match.') }}
			</p>
			<p>{{ t('cantoria', 'The data of switched-off functions are kept. The single switches below stay changeable.') }}</p>
		</ActionDialog>

		<ActionDialog v-if="pendingPreset !== null"
			:name="t('cantoria', 'Apply the preset “{preset}”?', { preset: pendingPreset.label })"
			:submit-label="t('cantoria', 'Apply preset')"
			:busy="busy"
			@submit="confirmPreset"
			@close="cancelPreset">
			<p>{{ pendingPreset.description }}</p>
			<template v-if="presetDiff.on.length > 0">
				<p>{{ t('cantoria', 'Switched on:') }}</p>
				<ul class="module-settings__list">
					<li v-for="module in presetDiff.on" :key="module.key">
						{{ module.label }}
					</li>
				</ul>
			</template>
			<template v-if="presetDiff.off.length > 0">
				<p>{{ t('cantoria', 'Switched off:') }}</p>
				<ul class="module-settings__list">
					<li v-for="module in presetDiff.off" :key="module.key">
						{{ module.label }}
					</li>
				</ul>
			</template>
			<p v-if="presetDiff.on.length === 0 && presetDiff.off.length === 0">
				{{ t('cantoria', 'The switches already match this preset.') }}
			</p>
			<p>{{ t('cantoria', 'The data of switched-off functions are kept.') }}</p>
		</ActionDialog>
	</div>
</template>

<style scoped>
.module-settings {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.module-settings__intro {
	max-width: 70ch;
}

.module-settings h3 {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1.1em;
	font-weight: bold;
}

.module-settings__core ul,
.module-settings__list {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}

.module-settings__presets {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.module-settings__presets li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.module-settings__current {
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	font-weight: bold;
}

.module-settings__scenarios {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 24em), 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.module-settings__scenario {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
}

/* the current one: a thicker border, and the word "Current" says it too */
.module-settings__scenario--current {
	border: 2px solid var(--color-primary-element);
}

.module-settings__scenario-text {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	flex-grow: 1;
}

.module-settings__scenario-state {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	min-height: var(--default-clickable-area);
	font-weight: bold;
}

.module-settings__fold summary {
	padding-block: var(--default-grid-baseline);
	font-weight: bold;
	cursor: pointer;
}

.module-settings__fold summary .module-settings__muted {
	font-weight: normal;
}

.module-settings__fold[open] summary {
	margin-block-end: calc(2 * var(--default-grid-baseline));
}

.module-settings__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.module-settings__group {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
}

.module-settings__group legend {
	padding-inline: var(--default-grid-baseline);
	font-weight: bold;
}

.module-settings__description {
	display: block;
}

.module-settings__description--inactive {
	font-weight: bold;
}

.module-settings__muted {
	color: var(--color-text-maxcontrast);
}
</style>
