<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings "Access to the archive folder": who of the role groups (and of
 * the admins without a role) can open the archive folder in their own
 * Nextcloud. The app reads files only with the permissions of the person
 * who uses it, so whoever cannot open the folder sees no scores, scans or
 * practice files. The check only reads; it shares nothing.
 *
 * The status of each group is shown as text with an icon, never by colour
 * alone (WCAG 1.4.1).
 */
import type { AccessStatus } from '../../settings.ts'
import type { AccessAccount, AccessReport, ArchiveInfo } from '../../types.ts'

import { mdiAlertOutline, mdiCheckCircleOutline, mdiFolderAccountOutline, mdiHelpCircleOutline, mdiMinusCircleOutline, mdiOpenInNew } from '@mdi/js'
import { n, t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import GuideLink from '../GuideLink.vue'
import { errorMessage, fetchArchiveAccess } from '../../api.ts'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { focusAfterUpdate } from '../../focus.ts'
import { translateLabel } from '../../format.ts'
import { accessRows, accessStatus, accountsWithoutAccess, needsAction } from '../../settings.ts'

const props = withDefaults(defineProps<{
	archive: ArchiveInfo
	/** role key => English label */
	roleLabels: Record<string, string>
	/** run the check right away (link from the app, guided setup) */
	autoStart?: boolean
	/** in the guided setup "Continue" is the main action: the button of the form steps back */
	embedded?: boolean
}>(), {
	autoStart: false,
	embedded: false,
})

/** The result; the page shows it in the setup steps, too */
const report = defineModel<AccessReport | null>('report', { default: null })
const busy = ref(false)
const error = ref('')

/** The result gets the focus after a check started by the button (WCAG 2.4.3) */
const resultHeading = useTemplateRef<HTMLElement>('resultHeading')

const rows = computed(() => (report.value ? accessRows(report.value) : []))
const withoutAccess = computed(() => (report.value ? accountsWithoutAccess(report.value) : []))
const admins = computed(() => report.value?.adminsWithoutRole ?? null)
const adminsStatus = computed(() => (admins.value ? accessStatus(admins.value) : 'empty'))
const actionNeeded = computed(() => report.value !== null && (needsAction(report.value) || adminsStatus.value === 'missing' || !report.value.self.canOpen))
const anyNotChecked = computed(() => rows.value.some((row) => row.notChecked > 0) || (admins.value?.notChecked ?? 0) > 0)
/** Opens the archive folder in Files, to share it from there */
const folderUrl = computed(() => generateUrl('/f/{id}', { id: props.archive.id }))

/**
 * Run the check.
 *
 * @param focusResult - move the focus to the result afterwards
 */
async function check(focusResult: boolean): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		report.value = await fetchArchiveAccess()
	} catch (caught) {
		report.value = null
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
	if (focusResult && report.value) {
		// The result appears with the next update
		await focusAfterUpdate(resultHeading)
	}
}

// A new archive folder: an earlier result no longer applies
watch(() => props.archive.id, () => {
	report.value = null
	error.value = ''
})

onMounted(() => {
	if (props.autoStart && props.archive.id > 0) {
		check(false)
	}
})

/**
 * Text of a group's status.
 *
 * @param status - status of the group
 * @param canOpen - checked accounts that can open the folder
 * @param cannotOpen - checked accounts that cannot
 */
function statusText(status: AccessStatus, canOpen: number, cannotOpen: number): string {
	switch (status) {
	case 'ok':
		return n('cantoria', '%n checked account can open it', 'All %n checked accounts can open it', canOpen)
	case 'missing':
		return n('cantoria', '%n account cannot open it', '%n accounts cannot open it', cannotOpen)
	case 'unchecked':
		return t('cantoria', 'Not checked')
	case 'empty':
		return t('cantoria', 'No members')
	case 'gone':
		return t('cantoria', 'The group no longer exists')
	}
	return ''
}

/**
 * Icon of a group's status (the text says the same).
 *
 * @param status - status of the group
 */
function statusIcon(status: AccessStatus): string {
	switch (status) {
	case 'ok':
		return mdiCheckCircleOutline
	case 'missing':
	case 'gone':
		return mdiAlertOutline
	case 'unchecked':
		return mdiHelpCircleOutline
	default:
		return mdiMinusCircleOutline
	}
}

/**
 * An account as text: the display name, and the account name if it differs.
 *
 * @param account - the account
 */
function accountText(account: AccessAccount): string {
	return account.displayName === account.uid ? account.uid : `${account.displayName} (${account.uid})`
}

/**
 * Translated label of a role.
 *
 * @param role - role key
 */
function roleLabel(role: string): string {
	return translateLabel(props.roleLabels[role] ?? role)
}
</script>

<template>
	<div class="settings-block">
		<p class="settings-block__text">
			{{ t('cantoria', 'The app reads files only with the permissions of the person who uses it. Whoever cannot open the archive folder in their own Nextcloud sees the catalog, but no scores, scans or practice files. The check looks at every member of the role groups; it only reads and shares nothing.') }}
		</p>

		<NcNoteCard v-if="archive.id === 0" type="info">
			{{ t('cantoria', 'Choose the archive folder first.') }}
		</NcNoteCard>

		<div class="settings-block__actions">
			<NcButton :variant="embedded ? 'secondary' : 'primary'" :disabled="busy || archive.id === 0" @click="check(true)">
				<template #icon>
					<NcLoadingIcon v-if="busy" />
					<NcIconSvgWrapper v-else :path="mdiFolderAccountOutline" />
				</template>
				{{ report ? t('cantoria', 'Check again') : t('cantoria', 'Check access') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<!-- Always present, so that screen readers announce the result -->
		<div role="status" class="access-check__status">
			<p v-if="busy" class="settings-block__muted">
				{{ t('cantoria', 'Checking the accounts … With many members this takes a moment.') }}
			</p>
			<template v-else-if="report">
				<h3 ref="resultHeading" tabindex="-1" class="access-check__heading">
					{{ t('cantoria', 'Result') }}
				</h3>
				<p>
					<strong v-if="withoutAccess.length === 0">
						{{ t('cantoria', 'Every checked account of the role groups can open the archive folder.') }}
					</strong>
					<strong v-else>
						{{ n('cantoria', '%n account of the role groups cannot open the archive folder.', '%n accounts of the role groups cannot open the archive folder.', withoutAccess.length) }}
					</strong>
				</p>
				<p>
					{{ report.self.canOpen
						? t('cantoria', 'You can open the archive folder yourself.')
						: t('cantoria', 'You cannot open the archive folder yourself, so the app shows you no files either.') }}
				</p>
			</template>
		</div>

		<template v-if="report && !busy">
			<div class="access-check__table-wrapper">
				<table class="access-check__table">
					<caption class="hidden-visually">
						{{ t('cantoria', 'Access to the archive folder per role and group') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('cantoria', 'Role') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Group') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Access') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Cannot open the folder') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'Not checked') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-if="rows.length === 0">
							<td colspan="5">
								{{ t('cantoria', 'No group has a role yet.') }}
							</td>
						</tr>
						<tr v-for="row in rows" :key="`${row.role}-${row.id}`" :class="`access-check__row--${row.status}`">
							<td>{{ roleLabel(row.role) }}</td>
							<th scope="row">
								{{ row.displayName }}
							</th>
							<td>
								<span class="access-check__state">
									<NcIconSvgWrapper :path="statusIcon(row.status)" :size="20" inline />
									{{ statusText(row.status, row.canOpen, row.cannotOpen.length) }}
								</span>
							</td>
							<td>
								<ul v-if="row.cannotOpen.length > 0" class="access-check__accounts">
									<li v-for="account in row.cannotOpen" :key="account.uid">
										{{ accountText(account) }}
									</li>
								</ul>
								<template v-else>
									–
								</template>
							</td>
							<td class="access-check__number">
								{{ row.notChecked > 0 ? row.notChecked : '–' }}
							</td>
						</tr>
						<tr v-if="admins && admins.members > 0" :class="`access-check__row--${adminsStatus}`">
							<td>–</td>
							<th scope="row">
								{{ t('cantoria', 'Administrators without a role') }}
							</th>
							<td>
								<span class="access-check__state">
									<NcIconSvgWrapper :path="statusIcon(adminsStatus)" :size="20" inline />
									{{ statusText(adminsStatus, admins.canOpen, admins.cannotOpen.length) }}
								</span>
							</td>
							<td>
								<ul v-if="admins.cannotOpen.length > 0" class="access-check__accounts">
									<li v-for="account in admins.cannotOpen" :key="account.uid">
										{{ accountText(account) }}
									</li>
								</ul>
								<template v-else>
									–
								</template>
							</td>
							<td class="access-check__number">
								{{ admins.notChecked > 0 ? admins.notChecked : '–' }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p v-if="admins && admins.cannotOpen.length > 0" class="settings-block__text settings-block__muted">
				{{ t('cantoria', 'Administrators without a role can read the catalog. To open scores in the app, they need access to the archive folder as well.') }}
			</p>
			<p v-if="anyNotChecked" class="settings-block__text settings-block__muted">
				{{ t('cantoria', 'Not checked are disabled accounts, accounts that have never logged in (their access follows from their groups like that of the others), and accounts beyond the limit of the check.') }}
			</p>
			<NcNoteCard v-if="!report.complete" type="warning">
				{{ t('cantoria', 'Not every account was checked: the check stops after {limit} accounts.', { limit: report.limit }) }}
			</NcNoteCard>

			<NcNoteCard v-if="actionNeeded" type="warning">
				<p><strong>{{ t('cantoria', 'How to give access') }}</strong></p>
				<ol class="access-check__steps">
					<li>{{ t('cantoria', 'Share the archive folder with the groups of the roles, or keep it in a team folder or group folder that these groups can open. Sharing with single persons is possible, but a group follows changes of office by itself.') }}</li>
					<li>{{ t('cantoria', 'Read permission is enough. Only whoever puts corrected files into the folder or stores exports and backups there (the catalog role, and the account that chose the folder for the weekly backups) needs permission to edit.') }}</li>
					<li v-if="rows.some((row) => row.status === 'gone')">
						{{ t('cantoria', 'A group that no longer exists: choose another group for the role under "Roles and groups".') }}
					</li>
					<li>{{ t('cantoria', 'Then check again.') }}</li>
				</ol>
				<NcButton v-if="report.self.canOpen"
					:href="folderUrl"
					target="_blank"
					rel="noopener noreferrer">
					<template #icon>
						<NcIconSvgWrapper :path="mdiOpenInNew" />
					</template>
					{{ t('cantoria', 'Open the archive folder in Files (new tab)') }}
				</NcButton>
				<p class="access-check__guide">
					<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.sharing)" :label="t('cantoria', 'Sharing the archive folder (administration guide)')" />
				</p>
			</NcNoteCard>
		</template>
	</div>
</template>

<style scoped src="./settings-block.css"></style>

<style scoped>
.access-check__heading {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.access-check__status {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.access-check__table-wrapper {
	max-width: 100%;
	overflow-x: auto;
}

.access-check__table {
	border-collapse: collapse;
	min-width: 40em;
}

.access-check__table th,
.access-check__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	white-space: normal;
}

.access-check__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.access-check__table tbody th {
	font-weight: bold;
}

.access-check__state {
	display: inline-flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.access-check__row--missing .access-check__state,
.access-check__row--gone .access-check__state {
	color: var(--color-warning-text, var(--color-main-text));
	font-weight: bold;
}

.access-check__row--ok .access-check__state {
	color: var(--color-success-text, var(--color-main-text));
}

.access-check__accounts {
	margin: 0;
	padding: 0;
	list-style: none;
}

.access-check__number {
	font-variant-numeric: tabular-nums;
}

.access-check__guide {
	margin-top: var(--default-grid-baseline);
}

.access-check__steps {
	margin: var(--default-grid-baseline) 0;
	padding-inline-start: 1.5em;
	list-style: decimal;
}
</style>
