<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Start page "Due": what needs the user's roles now, as cards with a number
 * that lead to the filtered view, the concerts with a deadline now and the
 * tasks for these roles below. The Nextcloud dashboard widget shows the same.
 */
import type { DueCard, DueResult } from '../types.ts'

import { mdiAlertCircleOutline, mdiBookOpenPageVariantOutline, mdiCheckCircleOutline } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NextConcert from './NextConcert.vue'
import TaskList from './TaskList.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchDue } from '../api.ts'
import { dueCardLabel } from '../dueLabels.ts'
import { dueTarget } from '../dueTargets.ts'
import { formatDate } from '../format.ts'
import { navigate, openDueTarget } from '../navigation.ts'
import { ROLES } from '../labels.ts'
import { DUE_CONCERTS, DUE_TASKS } from '../modules.ts'
import { canSeeProposals, hasAnyRole, isCardShown, isModuleActive, personalView, personalViewDialogOpen } from '../store.ts'

const due = ref<DueResult | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		due.value = await fetchDue()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

/**
 * Cards of the start page with their label in singular or plural. A card
 * leading to a view the roles do not include (the inbox for an admin without
 * a role) is left out; the server does not send it either.
 */
const cards = computed(() => (due.value?.cards ?? [])
	.filter((card) => dueTarget(card.key).view !== 'proposals' || canSeeProposals.value)
	.filter((card) => isCardShown(card.key))
	.map((card) => ({ ...card, label: dueCardLabel(card.key, card.count, card.label) })))

/**
 * Open the view a card stands for.
 *
 * @param card - card of the start page
 */
function openCard(card: DueCard): void {
	openDueTarget(card.key)
}

/** Concerts with a deadline now, the next first. */
const concerts = computed(() => (isCardShown(DUE_CONCERTS) ? due.value?.concerts ?? [] : []))

/** Tasks for the user's roles. */
const tasks = computed(() => (isCardShown(DUE_TASKS) ? due.value?.tasks ?? [] : []))

const nothingDue = computed(() => cards.value.length === 0 && concerts.value.length === 0 && tasks.value.length === 0)

/** The next concert on top, for everyone who sees the concerts (design proposal V6) */
const showNextConcert = computed(() => isModuleActive('concerts') && hasAnyRole(ROLES) && isCardShown(DUE_CONCERTS))

/** Whether the personal view hides something of this page. */
const somethingHidden = computed(() => personalView.value.cards.length > 0)
</script>

<template>
	<div class="due-view">
		<ViewHeader :title="t('cantoria', 'Due')"
			:description="t('cantoria', 'What needs your roles now. The tasks are sorted by archive binder, so a printed list follows the shelf.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="due-view__content">
			<NcEmptyContent v-if="!due && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="!due && error"
				:name="t('cantoria', 'The start page could not be loaded')"
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

			<template v-else-if="due">
				<NextConcert v-if="showNextConcert" />

				<section v-if="cards.length > 0" aria-labelledby="due-cards-heading">
					<h3 id="due-cards-heading" class="hidden-visually">
						{{ t('cantoria', 'Overview') }}
					</h3>
					<ul class="due-view__cards">
						<li v-for="card in cards" :key="card.key">
							<!-- The visible text is the accessible name (WCAG 2.5.3) -->
							<button type="button"
								class="due-card"
								@click="openCard(card)">
								<span class="due-card__count">{{ card.count }}</span>
								<span class="due-card__label">{{ card.label }}</span>
							</button>
						</li>
					</ul>
				</section>

				<section v-if="concerts.length > 0" class="due-view__concerts" aria-labelledby="due-concerts-heading">
					<h3 id="due-concerts-heading">
						{{ t('cantoria', 'Concert deadlines') }}
					</h3>
					<ul class="due-view__concert-list">
						<li v-for="concert in concerts" :key="concert.id">
							<!-- The visible text is the accessible name (WCAG 2.5.3) -->
							<button type="button"
								class="due-concert"
								@click="navigate('event', { eventId: concert.id })">
								<span class="due-concert__title">{{ concert.title || t('cantoria', '(no title)') }}</span>
								<span class="due-concert__meta">{{ formatDate(concert.date) }} · {{ concert.label }}</span>
							</button>
						</li>
					</ul>
				</section>

				<NcEmptyContent v-if="nothingDue"
					:name="t('cantoria', 'Nothing is due')"
					:description="t('cantoria', 'There are no open tasks or other things to do for your roles.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
					</template>
					<template #action>
						<NcButton @click="navigate('catalog')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiBookOpenPageVariantOutline" />
							</template>
							{{ t('cantoria', 'Open the catalog') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<section v-if="tasks.length > 0" class="due-view__tasks" aria-labelledby="due-tasks-heading">
					<h3 id="due-tasks-heading">
						{{ t('cantoria', 'Tasks for your roles') }}
					</h3>
					<TaskList :tasks="tasks" @changed="load" />
				</section>

				<p v-if="somethingHidden" class="due-view__hidden no-print">
					{{ t('cantoria', 'Some parts of the start page are hidden in your view.') }}
					<NcButton variant="tertiary" @click="personalViewDialogOpen = true">
						{{ t('cantoria', 'Customize view') }}
					</NcButton>
				</p>
			</template>
		</div>
	</div>
</template>

<style scoped>
.due-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.due-view__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 14em), 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.due-card {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	width: 100%;
	height: 100%;
	margin: 0;
	padding: calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.due-card:hover {
	background-color: var(--color-background-hover);
}

.due-card:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.due-card__count {
	font-size: 2em;
	font-weight: bold;
	line-height: 1;
	font-variant-numeric: tabular-nums;
}

.due-view__tasks h3,
.due-view__concerts h3 {
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	font-size: 1.2em;
	font-weight: bold;
}

.due-view__hidden {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.due-view__concert-list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.due-concert {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	margin: 0;
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.due-concert:hover {
	background-color: var(--color-background-hover);
}

.due-concert:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.due-concert__title {
	font-weight: bold;
}

.due-concert__meta {
	color: var(--color-text-maxcontrast);
}

@media print {
	.due-view__content {
		padding: 0;
	}

	.due-view__cards {
		display: none;
	}
}
</style>
