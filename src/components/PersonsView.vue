<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Register of persons (composers, lyricists, arrangers, …) with life dates,
 * identification and the works they are involved in. The life dates feed
 * the protection proposal; unknown or uncertain data counts as protected.
 */
import type { Person, PersonDetail } from '../types.ts'

import { mdiAccountPlusOutline, mdiAccountSearchOutline, mdiAlertCircleOutline, mdiCheckDecagramOutline, mdiDeleteOutline, mdiPencilOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import PersonDialog from './PersonDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { deletePerson, errorMessage, fetchPerson, fetchPersons, markPersonChecked } from '../api.ts'
import { formatDate } from '../format.ts'
import { blockingReasonLabel, contributionRoleLabel, lifeDates, personOptionLabel } from '../labels.ts'
import { selectedPersonId } from '../navigation.ts'
import { isLibrarian, openEntry } from '../store.ts'

const persons = ref<Person[]>([])
const query = ref('')
/** The search in words, for the header of the printout */
const printFilter = computed(() => query.value.trim() === '' ? '' : t('cantoria', 'Search: {text}', { text: query.value.trim() }))
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		persons.value = await fetchPersons('')
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(async () => {
	await load()
	if (selectedPersonId.value !== null) {
		await select(selectedPersonId.value)
	}
})

const collator = new Intl.Collator(undefined, { sensitivity: 'base' })

/** Filtered by the search, sorted by name. */
const shown = computed(() => {
	const needle = query.value.trim().toLowerCase()
	return persons.value
		.filter((person) => needle === '' || person.name.toLowerCase().includes(needle) || (person.gnd ?? '').includes(needle))
		.sort((a, b) => collator.compare(a.name, b.name))
})

// ---- detail ----

const current = ref<PersonDetail | null>(null)
const detailLoading = ref(false)
/** The person chosen last could not be loaded; shown in place of the detail */
const detailError = ref('')
/** Counter of the loads: two persons clicked quickly show the second one */
let selectRun = 0

/**
 * Show a person. Until the new one is there, the old one is not shown, so
 * that no button acts on the wrong person.
 *
 * @param id - person id
 */
async function select(id: number): Promise<void> {
	const run = ++selectRun
	selectedPersonId.value = id
	detailLoading.value = true
	detailError.value = ''
	if (current.value?.person.id !== id) {
		current.value = null
	}
	try {
		const person = await fetchPerson(id)
		if (run === selectRun) {
			current.value = person
		}
	} catch (caught) {
		if (run === selectRun) {
			detailError.value = errorMessage(caught)
		}
	} finally {
		if (run === selectRun) {
			detailLoading.value = false
		}
	}
}

watch(selectedPersonId, (id) => {
	if (id !== null && current.value?.person.id !== id) {
		select(id)
	}
})

const editing = ref<'new' | 'edit' | null>(null)
const deleting = ref(false)
const busy = ref(false)
const actionError = ref('')

/**
 * @param person - saved person
 */
async function onSaved(person: Person): Promise<void> {
	editing.value = null
	showSuccess(t('cantoria', 'The person has been saved.'))
	await load()
	await select(person.id)
}

async function check(): Promise<void> {
	if (!current.value) {
		return
	}
	busy.value = true
	try {
		await markPersonChecked(current.value.person.id)
		showSuccess(t('cantoria', 'Marked as checked.'))
		await load()
		await select(current.value.person.id)
	} catch (caught) {
		actionError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

async function remove(): Promise<void> {
	if (!current.value) {
		return
	}
	busy.value = true
	actionError.value = ''
	try {
		await deletePerson(current.value.person.id)
		showSuccess(t('cantoria', 'The person has been deleted.'))
		deleting.value = false
		current.value = null
		selectedPersonId.value = null
		await load()
	} catch (caught) {
		actionError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

/**
 * Date of a person field with its precision, for display.
 *
 * @param value - date or year
 * @param precision - precision key
 */
function dateText(value: string | null, precision: string | null): string {
	if (precision === 'living') {
		return personOptionLabel('died_precision', 'living')
	}
	if (!value) {
		return precision ? personOptionLabel('born_precision', precision) : '–'
	}
	const shown = /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value
	return precision && precision !== 'exact' && precision !== 'year' ? `${shown} (${personOptionLabel('born_precision', precision)})` : shown
}
</script>

<template>
	<div class="persons-view">
		<ViewHeader :title="t('cantoria', 'Persons')"
			:description="t('cantoria', 'Composers, lyricists and arrangers with life dates. The life dates are the basis of the protection proposal; unknown or uncertain data counts as protected.')"
			printable
			:print-filter="printFilter"
			reloadable
			:loading="loading"
			@reload="load">
			<template #actions>
				<NcButton v-if="isLibrarian" variant="primary" @click="editing = 'new'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAccountPlusOutline" />
					</template>
					{{ t('cantoria', 'New person') }}
				</NcButton>
			</template>
		</ViewHeader>

		<div class="persons-view__content">
			<section class="persons-view__list" :aria-label="t('cantoria', 'Register')">
				<!-- NcTextField puts a class on its input, so the wrapper hides it in print -->
				<div class="no-print">
					<NcTextField v-model="query"
						type="search"
						:label="t('cantoria', 'Search by name or GND number')" />
				</div>
				<p class="persons-view__muted">
					{{ n('cantoria', '%n person', '%n persons', shown.length) }}
				</p>
				<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
					<template #icon>
						<NcLoadingIcon :size="64" />
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="!loaded && error" :name="t('cantoria', 'The register could not be loaded')" :description="error">
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
					:name="t('cantoria', 'No persons')"
					:description="t('cantoria', 'Persons are added here, in the rights of a version or by the person import in the archive management.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAccountSearchOutline" />
					</template>
				</NcEmptyContent>
				<ul v-else class="persons-view__items">
					<li v-for="person in shown" :key="person.id">
						<button type="button"
							class="persons-view__item"
							:class="{ 'persons-view__item--selected': person.id === selectedPersonId }"
							:aria-current="person.id === selectedPersonId ? 'true' : undefined"
							@click="select(person.id)">
							<span class="persons-view__name">{{ person.name }}</span>
							<span class="persons-view__muted">
								{{ lifeDates(person) }}
								<template v-if="person.contributions">
									· {{ n('cantoria', '%n work', '%n works', person.contributions) }}
								</template>
							</span>
							<span v-if="person.blocking" class="persons-view__warning">
								{{ blockingReasonLabel(person.blocking) }}
							</span>
							<span v-if="!person.checked_at" class="persons-view__muted">{{ t('cantoria', 'not checked') }}</span>
						</button>
					</li>
				</ul>
			</section>

			<NcNoteCard v-if="detailError && selectedPersonId !== null"
				type="error"
				role="alert"
				class="persons-view__detail">
				<p>{{ t('cantoria', 'This person could not be loaded: {error}', { error: detailError }) }}</p>
				<NcButton variant="secondary" @click="select(selectedPersonId)">
					{{ t('cantoria', 'Try again') }}
				</NcButton>
			</NcNoteCard>
			<p v-else-if="!current && detailLoading" class="persons-view__detail">
				{{ t('cantoria', 'Loading …') }}
			</p>
			<section v-if="current" class="persons-view__detail" aria-labelledby="persons-view-name">
				<h3 id="persons-view-name">
					{{ current.person.name }}
				</h3>
				<p v-if="detailLoading">
					<NcLoadingIcon />
				</p>
				<dl class="persons-view__facts">
					<dt>{{ t('cantoria', 'Born') }}</dt>
					<dd>{{ dateText(current.person.born, current.person.born_precision) }}</dd>
					<dt>{{ t('cantoria', 'Died') }}</dt>
					<dd>{{ dateText(current.person.died, current.person.died_precision) }}</dd>
					<template v-if="current.person.life_dates_text">
						<dt>{{ t('cantoria', 'Life dates as found') }}</dt>
						<dd>{{ current.person.life_dates_text }}</dd>
					</template>
					<template v-if="current.person.source">
						<dt>{{ t('cantoria', 'Source of the life dates') }}</dt>
						<dd>{{ current.person.source }}</dd>
					</template>
					<dt>{{ t('cantoria', 'Identification') }}</dt>
					<dd>{{ personOptionLabel('identification', current.person.identification) || '–' }}</dd>
					<template v-if="current.person.gnd">
						<dt>{{ t('cantoria', 'GND number') }}</dt>
						<dd>{{ current.person.gnd }}</dd>
					</template>
					<template v-if="current.person.wikidata">
						<dt>{{ t('cantoria', 'Wikidata ID') }}</dt>
						<dd>{{ current.person.wikidata }}</dd>
					</template>
					<dt>{{ t('cantoria', 'Checked') }}</dt>
					<dd>
						<template v-if="current.person.checked_at">
							{{ t('cantoria', 'on {date} by {name}', { date: formatDate(current.person.checked_at), name: current.person.checked_by_name || current.person.checked_by || '' }) }}
						</template>
						<template v-else>
							{{ t('cantoria', 'not checked') }}
						</template>
					</dd>
					<template v-if="current.person.notes">
						<dt>{{ t('cantoria', 'Notes') }}</dt>
						<dd class="persons-view__notes">
							{{ current.person.notes }}
						</dd>
					</template>
				</dl>
				<div v-if="isLibrarian" class="persons-view__actions no-print">
					<NcButton @click="editing = 'edit'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencilOutline" />
						</template>
						{{ t('cantoria', 'Edit') }}
					</NcButton>
					<NcButton :disabled="busy" @click="check">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCheckDecagramOutline" />
						</template>
						{{ t('cantoria', 'Mark as checked') }}
					</NcButton>
					<NcButton v-if="current.contributions.length === 0" variant="tertiary" @click="deleting = true; actionError = ''">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('cantoria', 'Delete') }}
					</NcButton>
				</div>
				<p v-if="actionError" class="persons-view__error" role="alert">
					{{ actionError }}
				</p>

				<h4>{{ t('cantoria', 'Involved in') }}</h4>
				<p v-if="current.contributions.length === 0" class="persons-view__muted">
					{{ t('cantoria', 'No work yet.') }}
				</p>
				<ul v-else class="persons-view__works">
					<li v-for="contribution in current.contributions" :key="contribution.id">
						<strong>{{ contribution.title || t('cantoria', '(no title)') }}</strong>
						<span class="persons-view__muted">
							{{ contributionRoleLabel(contribution.role) }}<template v-if="contribution.text_for_composition">
								· {{ t('cantoria', 'Text written for this composition') }}
							</template>
						</span>
						<span class="persons-view__copies">
							<button v-for="copy in contribution.copies"
								:key="copy.id"
								type="button"
								class="persons-view__copy"
								:aria-label="t('cantoria', 'Open {shelfmark}', { shelfmark: copy.shelfmark })"
								@click="openEntry(copy.id, 'rights')">
								{{ copy.shelfmark }}
							</button>
						</span>
					</li>
				</ul>
			</section>
		</div>

		<PersonDialog v-if="editing !== null"
			:person="editing === 'edit' ? current?.person : null"
			:initial-name="editing === 'new' ? query : ''"
			@saved="onSaved"
			@close="editing = null" />

		<ActionDialog v-if="deleting && current"
			:name="t('cantoria', 'Delete person')"
			:submit-label="t('cantoria', 'Delete')"
			variant="error"
			:busy="busy"
			:error="actionError"
			@submit="remove"
			@close="deleting = false">
			<p>{{ t('cantoria', 'Delete {name} from the register? This is only possible while the person is not involved in any version.', { name: current.person.name }) }}</p>
		</ActionDialog>
	</div>
</template>

<style scoped>
.persons-view__content {
	display: grid;
	grid-template-columns: minmax(16em, 1fr) minmax(0, 1.4fr);
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

@media (max-width: 900px) {
	.persons-view__content {
		grid-template-columns: 1fr;
	}
}

.persons-view__list,
.persons-view__detail {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.persons-view__items {
	display: flex;
	flex-direction: column;
}

.persons-view__item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	margin: 0;
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border: none;
	border-bottom: 1px solid var(--color-border);
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.persons-view__item:hover {
	background-color: var(--color-background-hover);
}

.persons-view__item:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.persons-view__item--selected {
	background-color: var(--color-primary-element-light);
}

.persons-view__name {
	font-weight: bold;
}

.persons-view__muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.persons-view__warning {
	color: var(--color-warning-text, var(--color-warning));
	font-size: var(--font-size-small, 13px);
}

.persons-view__detail h3 {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
}

.persons-view__detail h4 {
	margin: calc(2 * var(--default-grid-baseline)) 0 0;
	font-weight: bold;
}

.persons-view__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(3 * var(--default-grid-baseline));
	margin: 0;
}

.persons-view__facts dt {
	color: var(--color-text-maxcontrast);
}

.persons-view__facts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.persons-view__notes {
	white-space: pre-wrap;
}

.persons-view__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.persons-view__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}

.persons-view__works {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.persons-view__works li {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0 calc(2 * var(--default-grid-baseline));
}

.persons-view__copy {
	margin: 0 var(--default-grid-baseline) 0 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.persons-view__copy:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

@media print {
	.persons-view__content {
		display: block;
		padding: 0;
	}

	.persons-view__detail {
		display: none;
	}
}
</style>
