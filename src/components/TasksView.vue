<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * All questions and tasks: for my roles or all, optionally with the closed
 * ones; sorted by archive binder and number so that a printed list follows
 * the shelf.
 */
import type { Task } from '../types.ts'

import { mdiAlertCircleOutline, mdiBookOpenPageVariantOutline, mdiClipboardCheckOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import TaskList from './TaskList.vue'
import ReloadError from './ReloadError.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchTasks } from '../api.ts'
import { navigate, taskFilters } from '../navigation.ts'
import { hasRole, isLibrarian } from '../store.ts'

const tasks = ref<Task[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

/** Counter of the loads: only the answer to the latest one counts (a filter switched quickly) */
let loadRun = 0

async function load(): Promise<void> {
	const run = ++loadRun
	loading.value = true
	error.value = ''
	try {
		const result = await fetchTasks(taskFilters.closed)
		if (run !== loadRun) {
			return
		}
		tasks.value = result
		loaded.value = true
	} catch (caught) {
		if (run === loadRun) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (run === loadRun) {
			loading.value = false
		}
	}
}

onMounted(load)
watch(() => taskFilters.closed, load)

const scope = computed({
	get: () => taskFilters.scope,
	set: (value: string) => {
		taskFilters.scope = value === 'all' ? 'all' : 'mine'
	},
})

/** The filters in words, for the header of the printout */
const printFilter = computed(() => [
	taskFilters.scope === 'all' ? t('cantoria', 'All') : t('cantoria', 'For my roles'),
	status.value === 'open' ? t('cantoria', 'Only open') : status.value === 'answered' ? t('cantoria', 'Only answered') : t('cantoria', 'Open and answered'),
	taskFilters.closed ? t('cantoria', 'Also closed tasks') : '',
].filter((part) => part !== '').join(', '))

const status = computed({
	get: () => taskFilters.status || 'all',
	set: (value: string) => {
		taskFilters.status = value === 'open' || value === 'answered' ? value : ''
	},
})

/**
 * Whether a task needs one of my roles: addressed to it, or answered and
 * to be applied by the catalog role.
 *
 * @param task - the task
 */
function isMine(task: Task): boolean {
	return hasRole(task.addressee) || (isLibrarian.value && task.status === 'answered')
}

const shown = computed(() => tasks.value.filter((task) => {
	if (taskFilters.scope === 'mine' && !isMine(task)) {
		return false
	}
	if (taskFilters.status !== '' && task.status !== taskFilters.status && !(taskFilters.closed && (task.status === 'applied' || task.status === 'discarded'))) {
		return false
	}
	return true
}))

/** Tasks exist, but the filters hide them all. */
const hiddenByFilters = computed(() => shown.value.length === 0 && tasks.value.length > 0)

/** Show the tasks of all roles, open and answered. */
function showAll(): void {
	taskFilters.scope = 'all'
	taskFilters.status = ''
}
</script>

<template>
	<div class="tasks-view">
		<ViewHeader :title="t('cantoria', 'Tasks')"
			:description="n('cantoria', '%n task shown', '%n tasks shown', shown.length)"
			printable
			:print-filter="printFilter"
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="tasks-view__filters no-print">
			<NcRadioGroup v-model="scope" :label="t('cantoria', 'Whose tasks')">
				<NcRadioGroupButton value="mine" :label="t('cantoria', 'For my roles')" />
				<NcRadioGroupButton value="all" :label="t('cantoria', 'All')" />
			</NcRadioGroup>
			<NcRadioGroup v-model="status" :label="t('cantoria', 'Status')">
				<NcRadioGroupButton value="all" :label="t('cantoria', 'Open and answered')" />
				<NcRadioGroupButton value="open" :label="t('cantoria', 'Only open')" />
				<NcRadioGroupButton value="answered" :label="t('cantoria', 'Only answered')" />
			</NcRadioGroup>
			<NcCheckboxRadioSwitch v-model="taskFilters.closed" type="switch">
				{{ t('cantoria', 'Also closed tasks') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="tasks-view__content">
			<ReloadError v-if="loaded && error" :error="error" @retry="load()" />
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The tasks could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="shown.length === 0"
				:name="t('cantoria', 'No tasks')"
				:description="hiddenByFilters
					? t('cantoria', 'No task matches the filters.')
					: t('cantoria', 'New questions and tasks are created in the sidebar of a copy (tab Queries) or in the score view.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClipboardCheckOutline" />
				</template>
				<template #action>
					<NcButton v-if="hiddenByFilters" @click="showAll">
						{{ t('cantoria', 'Show all tasks') }}
					</NcButton>
					<NcButton v-else @click="navigate('catalog')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookOpenPageVariantOutline" />
						</template>
						{{ t('cantoria', 'Open the catalog') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<TaskList v-else :tasks="shown" @changed="load" />
		</div>
	</div>
</template>

<style scoped>
.tasks-view__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.tasks-view__content {
	padding: calc(3 * var(--default-grid-baseline));
}

@media print {
	.tasks-view__content {
		padding: 0;
	}
}
</style>
