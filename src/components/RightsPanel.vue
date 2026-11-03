<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Rights of the version in the sidebar (milestone 0.2): confirmation of the
 * rights status, releases for the singers' portal and the print station,
 * the version valid for the choir, licenses and open proposals. Buttons
 * appear only for the roles that may act; the server checks again.
 */
import type { Detail, License, Proposal } from '../types.ts'

import {
	mdiAlertOutline,
	mdiCheckCircleOutline,
	mdiCloseCircleOutline,
	mdiPencilOutline,
	mdiPlus,
} from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import HelpTerm from './HelpTerm.vue'
import LicenseDialog from './LicenseDialog.vue'
import DateField from './DateField.vue'
import {
	addToPortal,
	clearValidVersion,
	confirmRights,
	errorMessage,
	removeFromPortal,
	setRightsOpen,
	setValidVersion,
} from '../api.ts'
import { displayValue, formatDate, isEmpty, toIsoDate, translateLabel } from '../format.ts'
import { licenseUseLabel } from '../labels.ts'
import { navigate } from '../navigation.ts'
import { confirmConsequences } from '../rightsHints.ts'
import { canSeeProposals, choiceLabel, entries, fieldInfo, fieldLabel, isConductor, isFreeOnly, isLibrarian, isModuleActive } from '../store.ts'

const props = defineProps<{
	detail: Detail
}>()

const emit = defineEmits<{
	saved: []
}>()

const version = computed(() => props.detail.version)
const versionId = computed(() => version.value.id)
const today = toIsoDate(new Date())

// ---- confirmation ------------------------------------------------------------

const confirmedAt = computed(() => String(version.value.confirmed_at ?? ''))
const isConfirmed = computed(() => confirmedAt.value !== '')
const confirmedBy = computed(() => String(version.value.confirmed_by_name ?? version.value.confirmed_by ?? ''))
const rightsStatus = computed(() => String(version.value.rights_status ?? ''))
const isPreservationCopy = computed(() => version.value.preservation_copy === true)

// ---- releases ----------------------------------------------------------------

const releases = computed(() => props.detail.releases)
const portalSince = computed(() => String(version.value.portal_since ?? ''))
const portalRemovedAt = computed(() => String(version.value.portal_removed_at ?? ''))
const portalRemovedReason = computed(() => String(version.value.portal_removed_reason ?? ''))

// ---- valid version -------------------------------------------------------------

const validSince = computed(() => String(version.value.valid_since ?? ''))
const validReason = computed(() => String(version.value.valid_reason ?? ''))
const validSetBy = computed(() => String(version.value.valid_set_by ?? ''))

/** Copies of other versions of the same work that are valid. */
const otherValidCopies = computed(() => entries.value
	.filter((entry) => entry.work_id === props.detail.work.id && entry.version_id !== versionId.value && entry.valid_since)
	.map((entry) => entry.shelfmark))

const onlyVersion = computed(() => props.detail.siblings.every((sibling) => sibling.version_id === versionId.value))

const validText = computed(() => {
	if (validSince.value !== '') {
		return t('cantoria', 'This version is valid for the choir since {date}.', { date: formatDate(validSince.value) })
	}
	if (otherValidCopies.value.length > 0) {
		return t('cantoria', 'Another version of this work is valid for the choir: {shelfmarks}.', { shelfmarks: otherValidCopies.value.join(', ') })
	}
	if (onlyVersion.value) {
		return t('cantoria', 'Only version of the work – not decided.')
	}
	return t('cantoria', 'No version of this work has been chosen yet.')
})

// ---- licenses ------------------------------------------------------------------

const licenseDialog = ref<{ license: License | null } | null>(null)

/**
 * State of a license by its end date.
 *
 * @param license - the license
 */
function licenseState(license: License): 'ended' | 'ending' | 'valid' {
	if (license.valid_until === null || license.valid_until === '') {
		return 'valid'
	}
	if (license.valid_until < today) {
		return 'ended'
	}
	const soon = new Date()
	soon.setDate(soon.getDate() + 30)
	return license.valid_until <= toIsoDate(soon) ? 'ending' : 'valid'
}

/**
 * Validity of a license as text.
 *
 * @param license - the license
 */
function licensePeriod(license: License): string {
	const from = license.valid_from ? formatDate(license.valid_from) : ''
	const until = license.valid_until ? formatDate(license.valid_until) : ''
	if (from && until) {
		return t('cantoria', '{from} to {until}', { from, until })
	}
	if (until) {
		return t('cantoria', 'until {until}', { until })
	}
	if (from) {
		return t('cantoria', 'from {from}', { from })
	}
	return t('cantoria', 'no time limit')
}

/**
 * Translated uses of a license.
 *
 * @param license - the license
 */
function licenseUses(license: License): string {
	return (license.uses ?? '').split(',').map((use) => use.trim()).filter((use) => use !== '').map(licenseUseLabel).join(', ')
}

const sortedLicenses = computed(() => [...props.detail.licenses].sort((a, b) => (b.valid_until ?? '9999').localeCompare(a.valid_until ?? '9999')))

// ---- proposals -----------------------------------------------------------------

/**
 * Current value of the proposed field, for display.
 *
 * @param proposal - open proposal
 */
function currentValue(proposal: Proposal): string {
	const record = props.detail[proposal.object_type]
	return displayValue(fieldInfo(proposal.object_type, proposal.field), record?.[proposal.field]) || t('cantoria', '(empty)')
}

/**
 * Proposed value, for display.
 *
 * @param proposal - open proposal
 */
function proposedValue(proposal: Proposal): string {
	let value: unknown = null
	try {
		value = proposal.new_value === null ? null : JSON.parse(proposal.new_value)
	} catch {
		value = proposal.new_value
	}
	const shown = typeof value === 'object' && value !== null ? JSON.stringify(value) : value as string | number | boolean | null
	return displayValue(fieldInfo(proposal.object_type, proposal.field), shown) || t('cantoria', '(empty)')
}

// ---- actions -------------------------------------------------------------------

type DialogKind = 'confirm' | 'open' | 'portal-add' | 'portal-remove' | 'valid-set' | 'valid-clear'

const dialog = ref<DialogKind | null>(null)
const busy = ref(false)
const error = ref('')
const draft = ref({ status: 'public_domain', reason: '', evidence: '', date: today })
/** Confirming public domain against the protection proposal needs a second confirmation (milestone 0.11). */
const proposalChecked = ref(false)

/**
 * Open an action dialog with a fresh form.
 *
 * @param kind - which action
 */
function openDialog(kind: DialogKind): void {
	draft.value = {
		status: rightsStatus.value === 'protected' ? 'protected' : 'public_domain',
		reason: '',
		evidence: '',
		date: today,
	}
	proposalChecked.value = false
	error.value = ''
	dialog.value = kind
}

// Buttons and dialog titles name the action, not the state it leads to
const dialogTitle = computed(() => {
	switch (dialog.value) {
	case 'confirm':
		return t('cantoria', 'Confirm the rights status')
	case 'open':
		return t('cantoria', 'When in doubt: set to open')
	case 'portal-add':
		return t('cantoria', 'Provide in the singers\' portal')
	case 'portal-remove':
		return t('cantoria', 'Take out of the singers\' portal')
	case 'valid-set':
		return t('cantoria', 'Set as valid for the choir')
	case 'valid-clear':
		return t('cantoria', 'Set as no longer valid for the choir')
	default:
		return ''
	}
})

const dialogSubmit = computed(() => {
	switch (dialog.value) {
	case 'confirm':
		return t('cantoria', 'Confirm')
	case 'open':
		return t('cantoria', 'Set to open')
	case 'portal-add':
		return t('cantoria', 'Save date')
	case 'portal-remove':
		return t('cantoria', 'Record removal')
	case 'valid-set':
		return t('cantoria', 'Set as valid')
	case 'valid-clear':
		return t('cantoria', 'Clear')
	default:
		return ''
	}
})

/** What the confirmation makes possible, for the chosen status. */
const consequences = computed(() => confirmConsequences(draft.value.status, isPreservationCopy.value, isModuleActive, isFreeOnly))

/** The protection proposal does not say "public domain", but public domain is about to be confirmed. */
const againstProposal = computed(() => dialog.value === 'confirm' && draft.value.status === 'public_domain'
	&& isModuleActive('protection_terms') && props.detail.protection !== null && props.detail.protection.status !== 'free')

/** Whether the reason is required in the current dialog. */
const reasonRequired = computed(() => dialog.value === 'confirm' || dialog.value === 'open' || dialog.value === 'portal-remove')

async function submitDialog(): Promise<void> {
	const kind = dialog.value
	if (kind === null) {
		return
	}
	const reason = draft.value.reason.trim()
	if (reasonRequired.value && reason === '') {
		error.value = t('cantoria', 'Please give a reason.')
		return
	}
	if (kind === 'confirm' && draft.value.evidence.trim() === '') {
		error.value = t('cantoria', 'Please name the evidence.')
		return
	}
	if (againstProposal.value && !proposalChecked.value) {
		error.value = t('cantoria', 'Please confirm that you have checked the protection proposal.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		switch (kind) {
		case 'confirm':
			await confirmRights(versionId.value, draft.value.status, reason, draft.value.evidence.trim())
			showSuccess(t('cantoria', 'The rights status has been confirmed.'))
			break
		case 'open':
			await setRightsOpen(versionId.value, reason)
			showSuccess(t('cantoria', 'The rights status is now open.'))
			break
		case 'portal-add':
			await addToPortal(versionId.value, draft.value.date)
			showSuccess(t('cantoria', 'Recorded as provided in the singers\' portal.'))
			break
		case 'portal-remove':
			await removeFromPortal(versionId.value, reason)
			showSuccess(t('cantoria', 'Recorded as taken out of the singers\' portal.'))
			break
		case 'valid-set':
			await setValidVersion(versionId.value, reason === '' ? null : reason)
			showSuccess(t('cantoria', 'This version is now valid for the choir.'))
			break
		case 'valid-clear':
			await clearValidVersion(versionId.value, reason === '' ? null : reason)
			showSuccess(t('cantoria', 'This version is no longer valid for the choir.'))
			break
		}
		dialog.value = null
		emit('saved')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

function onLicenseSaved(): void {
	licenseDialog.value = null
	showSuccess(t('cantoria', 'The license has been saved.'))
	emit('saved')
}
</script>

<template>
	<div class="rights-panel">
		<!-- Confirmation (module "rights") -->
		<section v-if="isModuleActive('rights')" class="rights-panel__section" aria-labelledby="rights-panel-confirmation">
			<div class="rights-panel__title">
				<h3 id="rights-panel-confirmation">
					{{ t('cantoria', 'Confirmation') }}
				</h3>
				<HelpTerm term="rightsStatus" />
			</div>
			<p v-if="isConfirmed" class="rights-panel__state rights-panel__state--success">
				<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" inline />
				<span>{{ t('cantoria', '{status}, confirmed on {date} by {name}.', { status: choiceLabel('version', 'rights_status', rightsStatus), date: formatDate(confirmedAt), name: confirmedBy }) }}</span>
			</p>
			<p v-else class="rights-panel__state rights-panel__state--warning">
				<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" inline />
				<span>{{ t('cantoria', 'The rights status is not confirmed.') }}</span>
			</p>
			<dl v-if="isConfirmed" class="rights-panel__facts">
				<dt>{{ t('cantoria', 'Reason') }}</dt>
				<dd>{{ version.confirmed_reason }}</dd>
				<dt>{{ t('cantoria', 'Evidence') }}</dt>
				<dd>{{ version.confirmed_evidence }}</dd>
			</dl>
			<div v-if="isLibrarian" class="rights-panel__actions">
				<NcButton @click="openDialog('confirm')">
					{{ isConfirmed ? t('cantoria', 'Confirm again') : t('cantoria', 'Confirm') }}
				</NcButton>
				<NcButton variant="tertiary" @click="openDialog('open')">
					{{ t('cantoria', 'When in doubt: set to open') }}
				</NcButton>
			</div>
		</section>

		<!-- Releases (modules "portal" and "print_station") -->
		<section v-if="isModuleActive('portal') || isModuleActive('print_station')" class="rights-panel__section" aria-labelledby="rights-panel-releases">
			<div class="rights-panel__title">
				<h3 id="rights-panel-releases">
					{{ t('cantoria', 'Releases') }}
				</h3>
				<HelpTerm term="release" />
			</div>
			<ul class="rights-panel__releases">
				<li v-if="isModuleActive('portal')">
					<span class="rights-panel__release-name">{{ t('cantoria', 'Singers\' portal') }}</span>
					<span v-if="releases.portal === null" class="rights-panel__state rights-panel__state--success">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" inline />
						{{ t('cantoria', 'Released') }}
					</span>
					<span v-else class="rights-panel__state rights-panel__state--error">
						<NcIconSvgWrapper :path="mdiCloseCircleOutline" :size="20" inline />
						{{ translateLabel(releases.portal) }}
					</span>
				</li>
				<li v-if="isModuleActive('print_station')">
					<span class="rights-panel__release-name">{{ t('cantoria', 'Print station') }}</span>
					<span v-if="releases.printStation === null" class="rights-panel__state rights-panel__state--success">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" inline />
						{{ t('cantoria', 'Released') }}
					</span>
					<span v-else class="rights-panel__state rights-panel__state--error">
						<NcIconSvgWrapper :path="mdiCloseCircleOutline" :size="20" inline />
						{{ translateLabel(releases.printStation) }}
					</span>
				</li>
			</ul>
			<template v-if="isModuleActive('portal')">
				<p v-if="releases.onPortal">
					{{ t('cantoria', 'In the singers\' portal since {date}.', { date: formatDate(portalSince) }) }}
				</p>
				<p v-else-if="portalRemovedAt !== ''" class="rights-panel__muted">
					{{ t('cantoria', 'Taken out of the singers\' portal on {date}: {reason}', { date: formatDate(portalRemovedAt), reason: portalRemovedReason }) }}
				</p>
				<div v-if="isLibrarian && !isPreservationCopy" class="rights-panel__actions">
					<NcButton v-if="!releases.onPortal && releases.portal === null" @click="openDialog('portal-add')">
						{{ t('cantoria', 'Provide in the singers\' portal') }}
					</NcButton>
					<NcButton v-if="releases.onPortal" @click="openDialog('portal-remove')">
						{{ t('cantoria', 'Take out of the singers\' portal') }}
					</NcButton>
				</div>
				<p v-if="releases.onPortal && releases.portal !== null" class="rights-panel__state rights-panel__state--error">
					<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" inline />
					{{ t('cantoria', 'This version is in the singers\' portal but no longer released for it. Please take it out.') }}
				</p>
			</template>
		</section>

		<!-- Valid version (module "valid_version") -->
		<section v-if="isModuleActive('valid_version')" class="rights-panel__section" aria-labelledby="rights-panel-valid">
			<div class="rights-panel__title">
				<h3 id="rights-panel-valid">
					{{ t('cantoria', 'Valid for the choir') }}
				</h3>
				<HelpTerm term="validVersion" />
			</div>
			<p>{{ validText }}</p>
			<dl v-if="validSince !== '' && (validReason !== '' || validSetBy !== '')" class="rights-panel__facts">
				<template v-if="validSetBy !== ''">
					<dt>{{ t('cantoria', 'Set by') }}</dt>
					<dd>{{ validSetBy }}</dd>
				</template>
				<template v-if="validReason !== ''">
					<dt>{{ t('cantoria', 'Reason') }}</dt>
					<dd>{{ validReason }}</dd>
				</template>
			</dl>
			<div v-if="isConductor" class="rights-panel__actions">
				<NcButton v-if="validSince === ''" @click="openDialog('valid-set')">
					{{ t('cantoria', 'Set as valid for the choir') }}
				</NcButton>
				<NcButton v-else @click="openDialog('valid-clear')">
					{{ t('cantoria', 'Set as no longer valid for the choir') }}
				</NcButton>
			</div>
		</section>

		<!-- Licenses (module "rights") -->
		<section v-if="isModuleActive('rights')" class="rights-panel__section" aria-labelledby="rights-panel-licenses">
			<h3 id="rights-panel-licenses">
				{{ t('cantoria', 'Licenses') }}
			</h3>
			<p v-for="warning in detail.licenseWarnings ?? []" :key="warning" class="rights-panel__state rights-panel__state--warning">
				<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" inline />
				{{ warning }}
			</p>
			<p v-if="sortedLicenses.length === 0" class="rights-panel__muted">
				{{ t('cantoria', 'No licenses recorded.') }}
			</p>
			<ul v-else class="rights-panel__licenses">
				<li v-for="license in sortedLicenses" :key="license.id" class="rights-panel__license">
					<div class="rights-panel__license-head">
						<strong>{{ license.holder || t('cantoria', 'License') }}</strong>
						<span v-if="licenseState(license) === 'ended'" class="rights-panel__chip rights-panel__chip--error">
							{{ t('cantoria', 'ended') }}
						</span>
						<span v-else-if="licenseState(license) === 'ending'" class="rights-panel__chip rights-panel__chip--warning">
							{{ t('cantoria', 'ends soon') }}
						</span>
						<NcButton v-if="isLibrarian"
							variant="tertiary"
							:aria-label="t('cantoria', 'Edit license')"
							@click="licenseDialog = { license }">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencilOutline" />
							</template>
						</NcButton>
					</div>
					<dl class="rights-panel__facts">
						<dt>{{ t('cantoria', 'Uses') }}</dt>
						<dd>{{ licenseUses(license) }}</dd>
						<dt>{{ t('cantoria', 'Valid') }}</dt>
						<dd>{{ licensePeriod(license) }}</dd>
						<template v-if="license.quantity !== null">
							<dt>{{ t('cantoria', 'Number of copies') }}</dt>
							<dd>{{ license.quantity }}</dd>
						</template>
						<template v-if="license.evidence">
							<dt>{{ t('cantoria', 'Evidence') }}</dt>
							<dd>{{ license.evidence }}</dd>
						</template>
						<template v-if="license.notes">
							<dt>{{ t('cantoria', 'Notes') }}</dt>
							<dd>{{ license.notes }}</dd>
						</template>
					</dl>
				</li>
			</ul>
			<div v-if="isLibrarian" class="rights-panel__actions">
				<NcButton @click="licenseDialog = { license: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('cantoria', 'Add license') }}
				</NcButton>
			</div>
		</section>

		<!-- Open proposals -->
		<section v-if="detail.proposals.length > 0" class="rights-panel__section" aria-labelledby="rights-panel-proposals">
			<h3 id="rights-panel-proposals">
				{{ t('cantoria', 'Open proposals') }}
			</h3>
			<ul class="rights-panel__proposals">
				<li v-for="proposal in detail.proposals" :key="proposal.id">
					<strong>{{ fieldLabel(proposal.object_type, proposal.field) }}</strong>:
					<span class="rights-panel__old">{{ currentValue(proposal) }}</span>
					→
					<span class="rights-panel__new">{{ proposedValue(proposal) }}</span>
					<span v-if="!isEmpty(proposal.source) || !isEmpty(proposal.reason)" class="rights-panel__muted">
						({{ [proposal.source, proposal.reason].filter((part) => part).join(' · ') }})
					</span>
				</li>
			</ul>
			<NcButton v-if="canSeeProposals" variant="tertiary" @click="navigate('proposals')">
				{{ t('cantoria', 'Go to the inbox') }}
			</NcButton>
		</section>

		<ActionDialog v-if="dialog !== null"
			:name="dialogTitle"
			:submit-label="dialogSubmit"
			:busy="busy"
			:error="error"
			@submit="submitDialog"
			@close="dialog = null">
			<template v-if="dialog === 'confirm'">
				<fieldset class="rights-panel__fieldset">
					<legend>{{ t('cantoria', 'Confirmed status') }}</legend>
					<NcCheckboxRadioSwitch v-model="draft.status"
						type="radio"
						value="public_domain"
						name="rights-confirm-status">
						{{ choiceLabel('version', 'rights_status', 'public_domain') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="draft.status"
						type="radio"
						value="protected"
						name="rights-confirm-status">
						{{ choiceLabel('version', 'rights_status', 'protected') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<NcTextArea v-model="draft.reason"
					:label="t('cantoria', 'Reason (required)')"
					required
					resize="vertical"
					rows="3" />
				<NcTextArea v-model="draft.evidence"
					:label="t('cantoria', 'Evidence (required), e.g. source of the life dates or license')"
					required
					resize="vertical"
					rows="2" />
				<div v-if="againstProposal" class="rights-panel__against">
					<p class="rights-panel__state rights-panel__state--warning">
						<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" inline />
						<span>{{ t('cantoria', 'The protection proposal does not say "public domain": {reason}', { reason: detail.protection?.reasonText ?? '' }) }}</span>
					</p>
					<NcCheckboxRadioSwitch v-model="proposalChecked" type="checkbox">
						{{ t('cantoria', 'I have checked the proposal and confirm public domain all the same.') }}
					</NcCheckboxRadioSwitch>
				</div>
				<!-- what the confirmation leads to, updated when the status changes -->
				<div aria-live="polite">
					<p v-for="sentence in consequences" :key="sentence">
						{{ sentence }}
					</p>
				</div>
				<p class="action-dialog__hint">
					{{ t('cantoria', 'The confirmation is recorded with your name and the date. Changing the rights status later removes it.') }}
				</p>
			</template>
			<template v-else-if="dialog === 'open'">
				<p>{{ t('cantoria', 'The rights status becomes "open" and any confirmation is removed.') }}</p>
				<NcTextArea v-model="draft.reason"
					:label="t('cantoria', 'Reason (required)')"
					required
					resize="vertical"
					rows="3" />
			</template>
			<template v-else-if="dialog === 'portal-add'">
				<DateField v-model="draft.date"
					:max="today"
					required
					:label="t('cantoria', 'In the singers\' portal since')" />
			</template>
			<template v-else-if="dialog === 'portal-remove'">
				<NcTextArea v-model="draft.reason"
					:label="t('cantoria', 'Reason (required)')"
					required
					resize="vertical"
					rows="3" />
			</template>
			<template v-else>
				<p v-if="dialog === 'valid-set' && otherValidCopies.length > 0">
					{{ t('cantoria', 'The version that is valid now ({shelfmarks}) will no longer be valid.', { shelfmarks: otherValidCopies.join(', ') }) }}
				</p>
				<NcTextArea v-model="draft.reason"
					:label="t('cantoria', 'Reason (optional)')"
					resize="vertical"
					rows="3" />
			</template>
		</ActionDialog>

		<LicenseDialog v-if="licenseDialog !== null"
			:version-id="versionId"
			:license="licenseDialog.license"
			@saved="onLicenseSaved"
			@close="licenseDialog = null" />
	</div>
</template>

<style scoped>
.rights-panel__section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.rights-panel__title {
	display: flex;
	align-items: center;
	gap: 2px;
}

.rights-panel__section h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.rights-panel__section p {
	margin: 0;
}

.rights-panel__state {
	display: inline-flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.rights-panel__state--success :deep(.icon-vue) {
	color: var(--color-success-text, var(--color-success));
}

.rights-panel__state--warning :deep(.icon-vue) {
	color: var(--color-warning-text, var(--color-warning));
}

.rights-panel__state--error :deep(.icon-vue) {
	color: var(--color-error-text, var(--color-error));
}

.rights-panel__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(2 * var(--default-grid-baseline));
	margin: 0;
}

.rights-panel__facts dt {
	color: var(--color-text-maxcontrast);
}

.rights-panel__facts dd {
	margin: 0;
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}

.rights-panel__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.rights-panel__releases {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.rights-panel__releases li {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(2 * var(--default-grid-baseline));
}

.rights-panel__release-name {
	min-width: 9em;
	font-weight: bold;
}

.rights-panel__licenses {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.rights-panel__license {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.rights-panel__license-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.rights-panel__chip {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid currentColor;
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
}

.rights-panel__chip--error {
	color: var(--color-error-text, var(--color-error));
}

.rights-panel__chip--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.rights-panel__proposals {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
}

.rights-panel__old {
	text-decoration: line-through;
}

.rights-panel__new {
	font-weight: bold;
}

.rights-panel__muted {
	color: var(--color-text-maxcontrast);
}

.rights-panel__fieldset {
	margin: 0;
	padding: 0;
	border: none;
}

.rights-panel__fieldset legend {
	font-weight: bold;
}
</style>
