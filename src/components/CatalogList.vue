<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The catalog: a table on wide screens, cards below ~700px. Large
 * catalogs are rendered in steps: the first rows at once, the rest in the
 * following frames, so that typing a search or clearing a filter never
 * blocks the page for long (2,000 copies: about 0.1 s instead of 0.7 s).
 */
import type { Entry, SortOrder } from '../types.ts'

import { mdiFilterRemoveOutline, mdiFilterVariant, mdiMagnify, mdiTextBoxCheckOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { emit as emitEvent } from '@nextcloud/event-bus'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import AppNotices from './AppNotices.vue'
import RightsBadge from './RightsBadge.vue'
import { activeFilterCount, isEntryConfirmed, songListCount } from '../catalog.ts'
import { formatDate } from '../format.ts'
import { repertoireClassLabel, repertoireSourceLabel } from '../labels.ts'
import {
	choiceLabel,
	entries,
	filters,
	filtersActive,
	isModuleActive,
	openEntry,
	resetFilters,
	selectedId,
	sortOrder,
	visibleEntries,
} from '../store.ts'
import { useStickyHeader } from '../stickyHeader.ts'

/** Below this width of the list area cards replace the table. */
const CARD_BREAKPOINT = 700

const isMobile = useIsMobile()
const filterCount = computed(() => activeFilterCount(filters))

/** Open the navigation with the filters (on a phone it is closed) */
function openFilters(): void {
	emitEvent('toggle-navigation', { open: true })
}
const root = useTemplateRef<HTMLElement>('root')

// Focused rows scroll below the sticky header, not under it (WCAG 2.4.11)
useStickyHeader(useTemplateRef<HTMLElement>('header'))
const narrow = ref(false)
let observer: ResizeObserver | null = null

onMounted(() => {
	if (!root.value) {
		return
	}
	narrow.value = root.value.clientWidth < CARD_BREAKPOINT
	observer = new ResizeObserver(([entry]) => {
		narrow.value = entry.contentRect.width < CARD_BREAKPOINT
	})
	observer.observe(root.value)
})

onBeforeUnmount(() => {
	observer?.disconnect()
	window.cancelAnimationFrame(renderFrame)
})

/** Rows rendered at once, and per following frame. */
const FIRST_ROWS = 150
const MORE_ROWS = 250

const renderLimit = ref(FIRST_ROWS)
let renderFrame = 0

/** Render further rows in the next frame until all matches are shown. */
function renderMore(): void {
	window.cancelAnimationFrame(renderFrame)
	if (renderLimit.value >= visibleEntries.value.length) {
		return
	}
	renderFrame = window.requestAnimationFrame(() => {
		renderLimit.value += MORE_ROWS
		renderMore()
	})
}

// A new search, filter or order starts with the first rows again
watch(visibleEntries, () => {
	renderLimit.value = FIRST_ROWS
	renderMore()
}, { immediate: true })

const renderedEntries = computed(() => visibleEntries.value.slice(0, renderLimit.value))

const sortModel = computed({
	get: () => sortOrder.value,
	set: (value: string) => {
		sortOrder.value = value as SortOrder
	},
})

interface SortOption {
	id: SortOrder
	label: string
}

const sortOptions = computed<SortOption[]>(() => [
	{ id: 'catalog', label: t('cantoria', 'Catalog order') },
	{ id: 'shelfmark', label: t('cantoria', 'Shelf mark') },
	{ id: 'title', label: t('cantoria', 'Title') },
])

/** The sort order as an option of the select (a labelled select fits every width). */
const sortOption = computed({
	get: () => sortOptions.value.find((option) => option.id === sortOrder.value) ?? sortOptions.value[0],
	set: (option: SortOption | null) => {
		if (option) {
			sortOrder.value = option.id
		}
	},
})

const countText = computed(() => {
	const shown = visibleEntries.value.length
	const total = entries.value.length
	if (shown === total) {
		return n('cantoria', '%n copy', '%n copies', total)
	}
	return t('cantoria', '{shown} of {total} copies', { shown, total })
})

/**
 * aria-sort value of a sortable column.
 *
 * @param order - the column's sort order
 */
function ariaSort(order: SortOrder): 'ascending' | 'none' {
	return sortOrder.value === order ? 'ascending' : 'none'
}

/**
 * Text for the files column, e.g. "9 files, 3 PDFs".
 *
 * @param entry - catalog entry
 */
function filesTitle(entry: Entry): string {
	return n('cantoria', '%n file', '%n files', entry.files)
		+ ', ' + n('cantoria', '%n PDF', '%n PDFs', entry.pdfs)
}

/**
 * Confirmation of the rights for the badge (true or null).
 *
 * @param entry - catalog entry
 */
function confirmation(entry: Entry): true | null {
	return isEntryConfirmed(entry) ? true : null
}

/**
 * Repertoire class with where it comes from and the last performance.
 *
 * @param entry - catalog entry
 */
function repertoireText(entry: Entry): string {
	const parts = [repertoireClassLabel(entry.repertoire_class)]
	if (entry.last_performed_on) {
		parts.push(t('cantoria', 'last {date}', { date: formatDate(entry.last_performed_on) }))
	}
	return parts.join(', ')
}

/**
 * Where the repertoire class comes from, for a tooltip and screen readers.
 *
 * @param entry - catalog entry
 */
function repertoireDetail(entry: Entry): string {
	// Derived from performances: the number says it. "From the imported catalog"
	// would stand in nearly every row; the sidebar names the source.
	const parts = [entry.repertoire_source === 'performances' && entry.performances
		? n('cantoria', '%n documented performance', '%n documented performances', entry.performances)
		: (entry.repertoire_source === 'import' ? '' : repertoireSourceLabel(entry.repertoire_source))]
	const songLists = songListCount(entry)
	if (songLists > 0) {
		parts.push(n('cantoria', 'on %n song list', 'on %n song lists', songLists))
	}
	return parts.filter((part) => part !== '').join(', ')
}

/**
 * The same, short enough for one line of the table: the numbers only. The
 * full text is the title of the cell and stands in the sidebar.
 *
 * @param entry - catalog entry
 */
function repertoireShort(entry: Entry): string {
	const parts = [entry.repertoire_source === 'performances' && entry.performances
		? n('cantoria', '%n performance', '%n performances', entry.performances)
		: (entry.repertoire_source === 'import' ? '' : repertoireSourceLabel(entry.repertoire_source))]
	const songLists = songListCount(entry)
	if (songLists > 0) {
		parts.push(n('cantoria', '%n song list', '%n song lists', songLists))
	}
	return parts.filter((part) => part !== '').join(' · ')
}

/**
 * Open review points of the transcription, as text ('' when there are none
 * or the review is switched off).
 *
 * @param entry - catalog entry
 */
function reviewText(entry: Entry): string {
	const open = isModuleActive('transcription_review') ? (entry.review_open ?? 0) : 0
	return open > 0 ? n('cantoria', '%n open review point', '%n open review points', open) : ''
}

/**
 * Open a copy in the sidebar. While the list is filtered for something to
 * do in the tab "Rights" (rights to confirm, no valid version; e.g. from a
 * card of the start page), that tab opens right away.
 *
 * @param id - id of the copy
 */
function openRow(id: number): void {
	openEntry(id, filters.unconfirmed || filters.noValidVersion ? 'rights' : undefined)
}

/**
 * Status shown next to the shelf mark if the copy is not active.
 *
 * @param entry - catalog entry
 */
function statusText(entry: Entry): string {
	return entry.status === 'active' ? '' : choiceLabel('copy', 'status', entry.status)
}
</script>

<template>
	<div ref="root" class="catalog">
		<div ref="header" class="catalog__header">
			<h2 class="hidden-visually">
				{{ t('cantoria', 'Catalog') }}
			</h2>
			<NcTextField v-if="isMobile"
				v-model="filters.search"
				class="catalog__search"
				type="search"
				:label="t('cantoria', 'Shelf mark, title or composer')"
				:show-trailing-button="filters.search !== ''"
				@trailing-button-click="filters.search = ''">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMagnify" />
				</template>
			</NcTextField>
			<div class="catalog__toolbar">
				<p class="catalog__count" aria-live="polite">
					{{ countText }}
				</p>
				<!-- On a phone the filters are in the navigation, which is closed: a way there -->
				<NcButton v-if="isMobile"
					variant="secondary"
					aria-haspopup="true"
					@click="openFilters">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFilterVariant" />
					</template>
					{{ filterCount > 0
						? n('cantoria', 'Filters (%n set)', 'Filters (%n set)', filterCount)
						: t('cantoria', 'Filters') }}
				</NcButton>
				<NcSelect v-model="sortOption"
					class="catalog__sort"
					:input-label="t('cantoria', 'Sort by')"
					:options="sortOptions"
					:clearable="false"
					:searchable="false" />
			</div>
		</div>
		<AppNotices />

		<NcEmptyContent v-if="visibleEntries.length === 0"
			:name="t('cantoria', 'No matches')"
			:description="t('cantoria', 'No copy matches the search and filters. Retired copies are only shown when the status filter includes them.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiMagnify" />
			</template>
			<template v-if="filtersActive" #action>
				<NcButton @click="resetFilters">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFilterRemoveOutline" />
					</template>
					{{ t('cantoria', 'Reset search and filters') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Cards for narrow screens -->
		<ul v-else-if="narrow" class="catalog-cards">
			<li v-for="entry in renderedEntries" :key="entry.id">
				<button type="button"
					class="catalog-card"
					:class="{ 'catalog-card--selected': entry.id === selectedId }"
					:aria-current="entry.id === selectedId ? 'true' : undefined"
					@click="openRow(entry.id)">
					<span class="catalog-card__head">
						<span class="shelfmark">{{ entry.shelfmark }}</span>
						<span class="catalog-card__title">{{ entry.title || t('cantoria', '(no title)') }}</span>
					</span>
					<span v-if="entry.composer" class="catalog-card__composer">{{ entry.composer }}</span>
					<span class="catalog-card__meta">
						<RightsBadge :rights-status="entry.rights_status"
							:rights-confirmed="confirmation(entry)"
							:preservation-copy="entry.preservation_copy" />
						<span v-if="statusText(entry)" class="status-tag">{{ statusText(entry) }}</span>
						<span class="catalog-card__detail">{{ repertoireText(entry) }}</span>
						<span class="catalog-card__detail">{{ filesTitle(entry) }}</span>
						<span v-if="entry.has_musicxml" class="musicxml-tag">MusicXML</span>
						<span v-if="reviewText(entry)" class="review-tag">{{ reviewText(entry) }}</span>
					</span>
				</button>
			</li>
		</ul>

		<!-- Table for wide screens -->
		<table v-else class="catalog-table">
			<caption class="hidden-visually">
				{{ t('cantoria', 'Catalog') }}
			</caption>
			<thead>
				<tr>
					<th scope="col" :aria-sort="ariaSort('shelfmark')">
						<button type="button" class="catalog-table__sort" @click="sortModel = 'shelfmark'">
							{{ t('cantoria', 'Shelf mark') }}
						</button>
					</th>
					<th scope="col" :aria-sort="ariaSort('title')">
						<button type="button" class="catalog-table__sort" @click="sortModel = 'title'">
							{{ t('cantoria', 'Title') }}
						</button>
					</th>
					<th scope="col">
						{{ t('cantoria', 'Composer') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'Rights') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'Repertoire') }}
					</th>
					<th scope="col" class="catalog-table__files">
						{{ t('cantoria', 'Files') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="entry in renderedEntries"
					:key="entry.id"
					:class="{ 'catalog-table__row--selected': entry.id === selectedId }"
					@click="openRow(entry.id)">
					<td>
						<button type="button"
							class="catalog-table__open shelfmark"
							:aria-current="entry.id === selectedId ? 'true' : undefined"
							:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: entry.shelfmark })"
							@click.stop="openRow(entry.id)">
							{{ entry.shelfmark }}
						</button>
						<span v-if="statusText(entry)" class="status-tag">{{ statusText(entry) }}</span>
					</td>
					<td class="catalog-table__title">
						{{ entry.title }}
					</td>
					<td>{{ entry.composer }}</td>
					<td>
						<RightsBadge :rights-status="entry.rights_status"
							:rights-confirmed="confirmation(entry)"
							:preservation-copy="entry.preservation_copy" />
					</td>
					<td :title="repertoireDetail(entry) || undefined">
						{{ repertoireText(entry) }}
						<span v-if="repertoireShort(entry)" class="catalog-table__detail">
							<span aria-hidden="true">{{ repertoireShort(entry) }}</span>
							<span class="hidden-visually">{{ repertoireDetail(entry) }}</span>
						</span>
					</td>
					<td class="catalog-table__files" :title="filesTitle(entry)">
						{{ entry.files }}
						<span v-if="entry.has_musicxml" class="musicxml-tag">MusicXML</span>
						<!-- in the narrow column: a symbol with the number, the words for screen readers and as title -->
						<span v-if="reviewText(entry)" class="review-count" :title="reviewText(entry)">
							<NcIconSvgWrapper :path="mdiTextBoxCheckOutline" :size="16" inline />
							<span aria-hidden="true">{{ entry.review_open }}</span>
							<span class="hidden-visually">{{ reviewText(entry) }}</span>
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style scoped>
.catalog {
	min-height: 100%;
}

.catalog__header {
	position: sticky;
	top: 0;
	z-index: 2;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline));
	/* room for the navigation toggle button */
	padding-inline-start: calc(var(--default-clickable-area) + 3 * var(--default-grid-baseline));
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.catalog__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
}

.catalog__count {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

/* .nc-select sets min-width: 260px with three classes; one more wins */
.catalog__toolbar .catalog__sort.nc-select {
	flex: 0 1 20em;
	min-width: min(100%, 12em);
}

@media (max-width: 512px) {
	.catalog__toolbar .catalog__sort.nc-select {
		flex-basis: 100%;
	}
}

/* one line per badge: the rows stay low and the column lines up */
.catalog-table :deep(.rights-badge) {
	white-space: nowrap;
}

.shelfmark {
	font-weight: bold;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.status-tag,
.musicxml-tag,
.review-tag {
	display: inline-block;
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	white-space: nowrap;
}

.review-tag {
	max-width: 12em;
	color: var(--color-main-text);
	white-space: normal;
}

.status-tag {
	margin-inline-start: var(--default-grid-baseline);
	border-color: var(--color-warning-text, var(--color-warning));
	color: var(--color-main-text);
}

/* ---- table ---- */

.catalog-table {
	width: 100%;
	border-collapse: collapse;
}

.catalog-table th,
.catalog-table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.catalog-table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	white-space: nowrap;
}

.catalog-table tbody tr {
	cursor: pointer;
}

.catalog-table tbody tr:hover {
	background-color: var(--color-background-hover);
}

.catalog-table__row--selected,
.catalog-table tbody tr.catalog-table__row--selected:hover {
	background-color: var(--color-primary-element-light);
}

/* not only a colour: a bar at the start of the row and the shelf mark
   underlined, as on the pages of the choir members */
.catalog-table__row--selected > td:first-child {
	box-shadow: inset 4px 0 0 var(--color-primary-element);
}

.catalog-table__row--selected .catalog-table__open {
	text-decoration: underline;
	text-decoration-thickness: 2px;
	text-underline-offset: 3px;
}

.catalog-table__detail {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.catalog-table__title {
	overflow-wrap: anywhere;
}

.catalog-table__files {
	min-width: 6em;
}

.catalog-table__files .musicxml-tag {
	margin-inline-start: var(--default-grid-baseline);
}

.review-count {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	font-size: var(--font-size-small, 13px);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.catalog-table__sort,
.catalog-table__open {
	/* a target of at least 24 by 24 pixels (WCAG 2.5.8) */
	min-width: 24px;
	min-height: 24px;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	cursor: pointer;
}

.catalog-table__sort {
	text-decoration: underline dotted;
}

.catalog-table th[aria-sort='ascending'] .catalog-table__sort {
	color: var(--color-main-text);
	font-weight: bold;
	text-decoration: none;
}

/* not only bold: an arrow says which column sorts (screen readers hear aria-sort) */
.catalog-table th[aria-sort='ascending'] .catalog-table__sort::after {
	content: ' ↑' / '';
}

.catalog-table__open {
	font-weight: bold;
}

.catalog-table__sort:focus-visible,
.catalog-table__open:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

/* ---- cards ---- */

.catalog-cards {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline));
}

.catalog-card {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: var(--default-grid-baseline);
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.catalog-card:hover {
	background-color: var(--color-background-hover);
}

.catalog-card:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.catalog-card--selected {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
	box-shadow: inset 4px 0 0 var(--color-primary-element);
}

.catalog-card__head {
	display: flex;
	gap: calc(2 * var(--default-grid-baseline));
	align-items: baseline;
}

.catalog-card__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.catalog-card__composer,
.catalog-card__detail {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.catalog-card__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
}
</style>
