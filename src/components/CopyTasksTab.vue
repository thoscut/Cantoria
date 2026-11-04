<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Sidebar tab "Tasks": questions and tasks of this copy, and its active
 * internal links. New tasks and links can be created here or, with the
 * measures filled in, from the score view.
 */
import type { Detail } from '../types.ts'

import { mdiLinkVariantPlus, mdiPlus } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, nextTick, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import LinkDialog from './LinkDialog.vue'
import LinkList from './LinkList.vue'
import TaskDialog from './TaskDialog.vue'
import TaskItem from './TaskItem.vue'
import { canCreateLinks, canCreateTasks, highlightedTaskId, isModuleActive } from '../store.ts'

const props = defineProps<{
	detail: Detail
}>()

const emit = defineEmits<{
	changed: []
}>()

const shelfmark = computed(() => String(props.detail.copy.shelfmark ?? ''))
const showClosed = ref(false)
const taskDialogOpen = ref(false)
const linkDialogOpen = ref(false)

const tasks = computed(() => props.detail.tasks
	.filter((task) => showClosed.value || task.status === 'open' || task.status === 'answered' || task.id === highlightedTaskId.value))

const closedCount = computed(() => props.detail.tasks.filter((task) => task.status === 'applied' || task.status === 'discarded').length)

const canShowScore = computed(() => props.detail.score?.canView === true)

// A task opened via a notification: scroll it into view
watch(highlightedTaskId, async (id) => {
	if (id === null) {
		return
	}
	await nextTick()
	document.getElementById(`task-${id}-title`)?.scrollIntoView({ block: 'center' })
}, { immediate: true })

function onCreated(): void {
	taskDialogOpen.value = false
	emit('changed')
}
</script>

<template>
	<div class="copy-tasks">
		<section v-if="isModuleActive('tasks')" aria-labelledby="copy-tasks-heading">
			<div class="copy-tasks__head">
				<h3 id="copy-tasks-heading">
					{{ t('cantoria', 'Questions and tasks') }}
				</h3>
				<NcButton v-if="canCreateTasks" @click="taskDialogOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('cantoria', 'New task') }}
				</NcButton>
			</div>
			<NcCheckboxRadioSwitch v-if="closedCount > 0" v-model="showClosed" type="switch">
				{{ t('cantoria', 'Show closed tasks ({count})', { count: closedCount }) }}
			</NcCheckboxRadioSwitch>
			<p v-if="tasks.length === 0" class="copy-tasks__empty">
				{{ t('cantoria', 'No open tasks for this copy.') }}
			</p>
			<div v-else class="copy-tasks__list">
				<TaskItem v-for="task in tasks"
					:key="task.id"
					:task="task"
					:highlighted="task.id === highlightedTaskId"
					:can-show-score="canShowScore"
					@changed="emit('changed')" />
			</div>
		</section>

		<section v-if="isModuleActive('excerpt_links')" aria-labelledby="copy-links-heading">
			<div class="copy-tasks__head">
				<h3 id="copy-links-heading">
					{{ t('cantoria', 'Internal links') }}
				</h3>
				<NcButton v-if="canCreateLinks && detail.score?.canView" @click="linkDialogOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiLinkVariantPlus" />
					</template>
					{{ t('cantoria', 'New link') }}
				</NcButton>
			</div>
			<LinkList :links="detail.links" :heading-level="4" @changed="emit('changed')" />
		</section>

		<TaskDialog v-if="taskDialogOpen"
			:copy-id="detail.copy.id"
			:shelfmark="shelfmark"
			@created="onCreated"
			@close="taskDialogOpen = false" />
		<LinkDialog v-if="linkDialogOpen"
			:copy-id="detail.copy.id"
			:shelfmark="shelfmark"
			@created="emit('changed')"
			@close="linkDialogOpen = false" />
	</div>
</template>

<style scoped>
.copy-tasks {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
}

.copy-tasks__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	margin-bottom: var(--default-grid-baseline);
}

.copy-tasks__head h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.copy-tasks__list {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.copy-tasks__empty {
	color: var(--color-text-maxcontrast);
}
</style>
