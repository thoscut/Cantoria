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
 *
 * Links that ended in the last 30 days come below the active ones under a
 * heading of their own, so that they can still be extended (U40). The
 * dialog for extending suggests the latest last day the server allows for
 * the link (`extend_until`) and says what sets it: six months from today,
 * a year after the link was made, or the end of the license (U41).
 */
import type { ExcerptLink } from '../types.ts'

import { mdiCalendarRefresh, mdiCalendarRemoveOutline, mdiClockAlertOutline, mdiContentCopy, mdiLinkOff } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { errorMessage, extendLink, linkUrl, revokeLink } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, formatDateTime, toIsoDate } from '../format.ts'
import { referenceWords } from '../labels.ts'
import { daysLeft, endsSoon, hasEnded } from '../sharing/linkDates.ts'
import { scopeText } from '../sharing/linkScope.ts'
import { entriesById, isLibrarian, openEntry } from '../store.ts'
import { showError } from '../toasts.ts'

const props = withDefaults(defineProps<{
	links: ExcerptLink[]
	/** show the shelf mark and title of each link (list across copies) */
	showCopy?: boolean
	/** level of the heading over the links that ended, one below the heading of the list */
	headingLevel?: 3 | 4
}>(), {
	showCopy: false,
	headingLevel: 3,
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
/** six months from today: the limit when the server names none */
const sixMonths = (() => {
	const date = new Date()
	date.setDate(date.getDate() + MAX_DAYS)
	return toIsoDate(date)
})()

/** The active links, then those that ended in the last 30 days (U40) */
const current = computed(() => props.links.filter((link) => !hasEnded(link.expires, today)))
const ended = computed(() => props.links.filter((link) => hasEnded(link.expires, today)))
const sections = computed(() => [{ key: 'current', items: current.value }, { key: 'ended', items: ended.value }])

/**
 * The latest last day the server allows for the link today (U41).
 *
 * @param link - excerpt link
 */
function latest(link: ExcerptLink): string {
	return link.extend_until ?? sixMonths
}

/**
 * Whether the link can be extended at all: not once it was made more than
 * a year ago.
 *
 * @param link - excerpt link
 */
function canExtend(link: ExcerptLink): boolean {
	return canRevoke(link) && link.extend_until !== null
}

/**
 * What sets the latest last day, in words.
 *
 * @param link - excerpt link
 */
function limitText(link: ExcerptLink): string {
	const date = formatDate(latest(link))
	switch (link.extend_limit) {
	case 'license':
		return t('cantoria', 'The license that releases the piece ends on {date}: the link lasts until then at most.', { date })
	case 'age':
		return t('cantoria', 'A link lasts at most a year after it was made: this one until {date} at most.', { date })
	default:
		return t('cantoria', 'A link lasts at most six months from today, until {date}.', { date })
	}
}

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
	newExpiry.value = latest(link)
	error.value = ''
}

async function extend(): Promise<void> {
	if (!extending.value) {
		return
	}
	const from = earliest(extending.value)
	const until = latest(extending.value)
	if (newExpiry.value === '' || newExpiry.value < from || newExpiry.value > until) {
		error.value = t('cantoria', 'Please choose a day between {from} and {date}.', { from: formatDate(from), date: formatDate(until) })
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
		<p v-if="current.length === 0" class="link-list__empty">
			{{ t('cantoria', 'No active links.') }}
		</p>
		<template v-for="section in sections" :key="section.key">
			<template v-if="section.key === 'ended' && section.items.length > 0">
				<component :is="`h${headingLevel}`" class="link-list__heading">
					{{ t('cantoria', 'Ended in the last 30 days') }}
				</component>
				<p class="link-list__empty">
					{{ t('cantoria', 'Extend a link to open it again. A link for choir members keeps its address; a public link gets a new one.') }}
				</p>
			</template>
			<ul v-if="section.items.length > 0" class="link-list__items">
				<li v-for="link in section.items"
					:key="link.id"
					class="link-list__item"
					:class="{ 'link-list__item--ending': endingText(link) !== null, 'link-list__item--ended': section.key === 'ended' }">
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
						<p v-if="section.key === 'ended'" class="link-list__ending">
							<NcIconSvgWrapper :path="mdiCalendarRemoveOutline" :size="16" />
							{{ t('cantoria', 'Ended on {date}', { date: formatDate(link.expires ?? '') }) }}
						</p>
						<p v-else-if="endingText(link) !== null" class="link-list__ending">
							<NcIconSvgWrapper :path="mdiClockAlertOutline" :size="16" />
							{{ endingText(link) }}
						</p>
						<p class="link-list__meta">
							<span class="link-list__circle">{{ circleLabel(link) }}</span>
							<template v-if="section.key !== 'ended'">
								· {{ t('cantoria', 'Valid until {date}', { date: formatDate(link.expires ?? '') }) }}
							</template>
							· {{ t('cantoria', 'created {date}', { date: formatDateTime(link.created) }) }}
							· {{ n('cantoria', 'opened %n time', 'opened %n times', link.hits) }}
							<template v-if="link.tempo">
								· {{ t('cantoria', 'tempo {percent} %', { percent: link.tempo }) }}
							</template>
							<template v-if="link.scan_file_id">
								· {{ t('cantoria', 'with scan (PDF)') }}
							</template>
						</p>
						<p v-if="canRevoke(link) && !canExtend(link)" class="link-list__meta">
							{{ t('cantoria', 'Made more than a year ago: it cannot be extended. Please make a new link.') }}
						</p>
					</div>
					<div class="link-list__actions no-print">
						<NcButton v-if="link.token !== null && section.key !== 'ended'"
							variant="tertiary"
							:aria-label="t('cantoria', 'Copy link')"
							@click="copy(link)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiContentCopy" />
							</template>
						</NcButton>
						<NcButton v-if="canExtend(link)"
							:variant="endingText(link) !== null || section.key === 'ended' ? 'secondary' : 'tertiary'"
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
		</template>

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
			<p v-if="extending.circle === 'public' && hasEnded(extending.expires, today)">
				{{ t('cantoria', 'The link has ended: it gets a new address, and the old one stays closed. Please pass the new one on.') }}
			</p>
			<p v-else>
				{{ t('cantoria', 'The link keeps its address: whoever has it can go on using it, and what the choir members keep on their devices stays.') }}
			</p>
			<p class="action-dialog__hint">
				{{ limitText(extending) }}
			</p>
			<DateField v-model="newExpiry"
				:min="earliest(extending)"
				:max="latest(extending)"
				required
				:label="t('cantoria', 'Valid until (at most {date})', { date: formatDate(latest(extending)) })" />
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

/* ends within a week or has ended: said in words, the line only underlines it */
.link-list__item--ending,
.link-list__item--ended {
	padding-inline-start: var(--default-grid-baseline);
	border-inline-start: 4px solid var(--color-warning);
}

.link-list__ending {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	font-weight: bold;
}

.link-list__item--ended {
	border-inline-start-color: var(--color-border-maxcontrast);
}

.link-list__heading {
	margin-block: calc(3 * var(--default-grid-baseline)) var(--default-grid-baseline);
	font-size: var(--default-font-size);
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
