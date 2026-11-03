<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Active links: who can open them (archive roles, choir members or the
 * public), what they share (the whole piece or measures, with the scan or
 * not), until when they are valid, how often they were opened (a count
 * only, no names). The creator and the catalog role can revoke a link,
 * extend it (its address stays, and so does what choir members keep on
 * their devices) and copy a link for choir members or a public link; the
 * server sends its token only to them. A link that ends within a week says
 * so in words (milestone 0.18, U30); "Extend" is a labelled button.
 * Extending never shortens a link; an expired public link gets a new
 * address.
 */
import type { ExcerptLink } from '../types.ts'

import { mdiCalendarRefresh, mdiClockAlertOutline, mdiContentCopy, mdiLinkOff } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { errorMessage, extendLink, linkUrl, revokeLink } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, formatDateTime, toIsoDate } from '../format.ts'
import { referenceWords } from '../labels.ts'
import { daysLeft, endsSoon } from '../sharing/linkDates.ts'
import { scopeText } from '../sharing/linkScope.ts'
import { entriesById, isLibrarian, openEntry } from '../store.ts'
import { showError } from '../toasts.ts'

withDefaults(defineProps<{
	links: ExcerptLink[]
	/** show the shelf mark and title of each link (list across copies) */
	showCopy?: boolean
}>(), {
	showCopy: false,
})

const emit = defineEmits<{
	changed: []
}>()

/**
 * Where the link points.
 *
 * @param link - excerpt link
 */
function place(link: ExcerptLink): string {
	return scopeText({
		measureFrom: link.measure_from,
		measureTo: link.measure_to,
		voice: link.voice,
		verse: link.verse,
	}, referenceWords(), t('cantoria', 'Whole piece'))
}

/**
 * Who can open the link, as text (not only as a color).
 *
 * @param link - excerpt link
 */
function circleLabel(link: ExcerptLink): string {
	switch (link.circle) {
	case 'members':
		return t('cantoria', 'For choir members')
	case 'public':
		return t('cantoria', 'Public')
	default:
		return t('cantoria', 'Internal')
	}
}

/**
 * @param link - excerpt link
 */
function canRevoke(link: ExcerptLink): boolean {
	return isLibrarian.value || link.own
}

/**
 * @param link - excerpt link
 */
async function copy(link: ExcerptLink): Promise<void> {
	if (link.token !== null && await copyText(linkUrl(link.token, link.circle))) {
		showSuccess(t('cantoria', 'The link has been copied.'))
	} else {
		showError(t('cantoria', 'The link could not be copied. Please select and copy it by hand.'))
	}
}

const revoking = ref<ExcerptLink | null>(null)
const busy = ref(false)
const error = ref('')

async function revoke(): Promise<void> {
	if (!revoking.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await revokeLink(revoking.value.id)
		showSuccess(t('cantoria', 'The link has been revoked.'))
		revoking.value = null
		emit('changed')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

/** Days a link may last at most, as on the server */
const MAX_DAYS = 183

const extending = ref<ExcerptLink | null>(null)
const newExpiry = ref('')
const today = toIsoDate(new Date())
const latest = (() => {
	const date = new Date()
	date.setDate(date.getDate() + MAX_DAYS)
	return toIsoDate(date)
})()

/**
 * The earliest day a link can be extended to: never before its current
 * last day.
 *
 * @param link - excerpt link
 */
function earliest(link: ExcerptLink): string {
	return link.expires !== null && link.expires > today ? link.expires : today
}

/**
 * How soon a link ends, in words, when within a week; null otherwise.
 *
 * @param link - excerpt link
 */
function endingText(link: ExcerptLink): string | null {
	if (!endsSoon(link.expires, today)) {
		return null
	}
	const days = daysLeft(link.expires, today) ?? 0
	return days === 0 ? t('cantoria', 'Ends today') : n('cantoria', 'Ends in %n day', 'Ends in %n days', days)
}

/**
 * @param link - excerpt link
 */
function startExtending(link: ExcerptLink): void {
	extending.value = link
	newExpiry.value = latest
	error.value = ''
}

async function extend(): Promise<void> {
	if (!extending.value) {
		return
	}
	const from = earliest(extending.value)
	if (newExpiry.value === '' || newExpiry.value < from || newExpiry.value > latest) {
		error.value = t('cantoria', 'Please choose a day between {from} and {date}.', { from: formatDate(from), date: formatDate(latest) })
		return
	}
	busy.value = true
	error.value = ''
	try {
		const extended = await extendLink(extending.value.id, newExpiry.value)
		showSuccess(t('cantoria', 'The link is valid until {date}.', { date: formatDate(extended.expires ?? newExpiry.value) }))
		if (extended.token !== null && extending.value.token !== null && extended.token !== extending.value.token) {
			// an expired public link: the old address stays closed
			showSuccess(t('cantoria', 'The link has a new address. Please copy it and pass it on again.'))
		}
		extending.value = null
		emit('changed')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="link-list">
		<p v-if="links.length === 0" class="link-list__empty">
			{{ t('cantoria', 'No active links.') }}
		</p>
		<ul v-else class="link-list__items">
			<li v-for="link in links"
				:key="link.id"
				class="link-list__item"
				:class="{ 'link-list__item--ending': endingText(link) !== null }">
				<div class="link-list__body">
					<p class="link-list__title">
						<template v-if="showCopy">
							<button type="button" class="link-list__copy-button" @click="openEntry(link.copy_id)">
								{{ entriesById.get(link.copy_id)?.shelfmark ?? link.copy_id }}
							</button>
							{{ entriesById.get(link.copy_id)?.title ?? '' }} ·
						</template>
						{{ place(link) }}
					</p>
					<p v-if="link.note" class="link-list__note">
						{{ link.note }}
					</p>
					<p v-if="endingText(link) !== null" class="link-list__ending">
						<NcIconSvgWrapper :path="mdiClockAlertOutline" :size="16" />
						{{ endingText(link) }}
					</p>
					<p class="link-list__meta">
						<span class="link-list__circle">{{ circleLabel(link) }}</span>
						· {{ t('cantoria', 'Valid until {date}', { date: formatDate(link.expires ?? '') }) }}
						· {{ t('cantoria', 'created {date}', { date: formatDateTime(link.created) }) }}
						· {{ n('cantoria', 'opened %n time', 'opened %n times', link.hits) }}
						<template v-if="link.tempo">
							· {{ t('cantoria', 'tempo {percent} %', { percent: link.tempo }) }}
						</template>
						<template v-if="link.scan_file_id">
							· {{ t('cantoria', 'with scan (PDF)') }}
						</template>
					</p>
				</div>
				<div class="link-list__actions no-print">
					<NcButton v-if="link.token !== null"
						variant="tertiary"
						:aria-label="t('cantoria', 'Copy link')"
						@click="copy(link)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentCopy" />
						</template>
					</NcButton>
					<NcButton v-if="canRevoke(link)"
						:variant="endingText(link) !== null ? 'secondary' : 'tertiary'"
						@click="startExtending(link)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarRefresh" />
						</template>
						{{ t('cantoria', 'Extend') }}
					</NcButton>
					<NcButton v-if="canRevoke(link)"
						variant="tertiary"
						:aria-label="t('cantoria', 'Revoke link')"
						@click="revoking = link; error = ''">
						<template #icon>
							<NcIconSvgWrapper :path="mdiLinkOff" />
						</template>
					</NcButton>
				</div>
			</li>
		</ul>

		<ActionDialog v-if="revoking !== null"
			:name="t('cantoria', 'Revoke link')"
			:submit-label="t('cantoria', 'Revoke')"
			variant="error"
			:busy="busy"
			:error="error"
			@submit="revoke"
			@close="revoking = null">
			<p>{{ t('cantoria', 'The link stops working immediately for everyone. This cannot be undone.') }}</p>
			<p class="link-list__title">
				{{ place(revoking) }}
			</p>
		</ActionDialog>

		<ActionDialog v-if="extending !== null"
			:name="t('cantoria', 'Extend link')"
			:submit-label="t('cantoria', 'Extend')"
			:busy="busy"
			:error="error"
			@submit="extend"
			@close="extending = null">
			<p class="link-list__title">
				{{ place(extending) }}
			</p>
			<p>{{ t('cantoria', 'The link keeps its address: whoever has it can go on using it, and what the choir members keep on their devices stays.') }}</p>
			<p class="action-dialog__hint">
				{{ t('cantoria', 'A link lasts at most a year after it was made and never beyond the end of the license that releases the piece.') }}
			</p>
			<DateField v-model="newExpiry"
				:min="earliest(extending)"
				:max="latest"
				required
				:label="t('cantoria', 'Valid until (at most six months)')" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.link-list__items {
	display: flex;
	flex-direction: column;
}

.link-list__item {
	display: flex;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.link-list__item:last-child {
	border-bottom: none;
}

/* ends within a week: said in words, the line only underlines it */
.link-list__item--ending {
	padding-inline-start: var(--default-grid-baseline);
	border-inline-start: 4px solid var(--color-warning);
}

.link-list__ending {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	font-weight: bold;
}

.link-list__body {
	min-width: 0;
}

.link-list__body p {
	margin: 0;
}

.link-list__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.link-list__copy-button {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.link-list__copy-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.link-list__note {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.link-list__meta,
.link-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.link-list__circle {
	font-weight: bold;
}

.link-list__actions {
	display: flex;
	flex-shrink: 0;
	align-items: flex-start;
}
</style>
