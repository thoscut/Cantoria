<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Header of a view: heading, optional description, actions and a print
 * button. Sticky on screen; in print only the heading and the description
 * remain, under a line with choir, view, filter and date, and every page
 * gets its number (printHeader.ts). The notices about the whole app follow
 * below it (not sticky).
 */
import { mdiPrinterOutline, mdiRefresh } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, onMounted, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AppNotices from './AppNotices.vue'
import { formatDate } from '../format.ts'
import { addPageNumbers, printHeaderLine } from '../printHeader.ts'
import { useStickyHeader } from '../stickyHeader.ts'
import { meta } from '../store.ts'
import { todayIso } from '../folderBook.ts'

const props = withDefaults(defineProps<{
	title: string
	description?: string
	printable?: boolean
	/** the filters that are on, in words, for the header of the printout */
	printFilter?: string
	/** show a reload button */
	reloadable?: boolean
	loading?: boolean
}>(), {
	description: '',
	printable: false,
	printFilter: '',
	reloadable: false,
	loading: false,
})

const emit = defineEmits<{
	reload: []
}>()

// Focused elements scroll below this sticky header, not under it
useStickyHeader(useTemplateRef<HTMLElement>('header'))

const printLine = computed(() => printHeaderLine({
	site: meta.value?.siteName ?? '',
	view: props.title,
	filter: props.printFilter,
	date: formatDate(todayIso()),
}))

onMounted(() => {
	if (props.printable) {
		addPageNumbers()
	}
})

function print(): void {
	window.print()
}
</script>

<template>
	<header ref="header" class="view-header">
		<p v-if="printable" class="view-header__print-line print-only" aria-hidden="true">
			{{ printLine }}
		</p>
		<div class="view-header__text">
			<div class="view-header__heading">
				<h2 class="view-header__title">
					{{ title }}
				</h2>
				<!-- e.g. the "?" of a term (HelpTerm) -->
				<slot name="term" />
			</div>
			<p v-if="description" class="view-header__description">
				{{ description }}
			</p>
		</div>
		<div class="view-header__actions no-print">
			<slot name="actions" />
			<NcButton v-if="reloadable"
				variant="tertiary"
				:aria-label="t('cantoria', 'Reload')"
				:disabled="loading"
				@click="emit('reload')">
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<NcIconSvgWrapper v-else :path="mdiRefresh" />
				</template>
			</NcButton>
			<NcButton v-if="printable" variant="secondary" @click="print">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPrinterOutline" />
				</template>
				{{ t('cantoria', 'Print') }}
			</NcButton>
		</div>
	</header>
	<AppNotices />
</template>

<style scoped>
.view-header {
	position: sticky;
	top: 0;
	z-index: 2;
	display: flex;
	flex-wrap: wrap;
	/* actions stay beside the heading, also when the description is long */
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(2 * var(--default-grid-baseline));
	/* room for the navigation toggle button */
	padding-inline-start: calc(var(--default-clickable-area) + 3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

/* The text takes the room that is left; only below 20em do the actions
   move under it (narrow windows, large zoom). */
.view-header__text {
	flex: 1 1 20em;
	min-width: 0;
}

.view-header__heading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.view-header__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.view-header__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 80ch;
}

.view-header__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
}

.view-header__print-line {
	display: none;
}

@media print {
	.view-header {
		position: static;
		padding: 0 0 4mm;
		border-bottom: 1px solid #000;
	}

	.view-header__print-line {
		display: block;
		flex: 1 0 100%;
		margin: 0;
		font-size: 9pt;
	}
}
</style>
