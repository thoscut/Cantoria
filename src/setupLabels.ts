/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Names, states and icons of the setup steps, shared by the list
 * "Setting up" and the guided setup. The state is always said in words; the
 * icon repeats it (never colour alone).
 */
import type { SetupStep, StepKey, StepStatus } from './settings.ts'

import { mdiAlertOutline, mdiCheckCircleOutline, mdiCircleOutline, mdiHelpCircleOutline } from '@mdi/js'
import { translateLabel } from './format.ts'
import { t } from './l10n.ts'

/**
 * Name of a step.
 *
 * @param key - the step
 */
export function stepTitle(key: StepKey): string {
	switch (key) {
	case 'archive':
		return t('cantoria', 'Archive folder')
	case 'roles':
		return t('cantoria', 'Roles and groups')
	case 'access':
		return t('cantoria', 'Access to the archive folder')
	case 'modules':
		return t('cantoria', 'Functions for your choir')
	case 'import':
		return t('cantoria', 'Import catalog')
	default:
		return t('cantoria', 'File sync')
	}
}

/**
 * State of a step in words.
 *
 * @param status - state of the step
 */
export function stepStatusText(status: StepStatus): string {
	switch (status) {
	case 'done':
		return t('cantoria', 'Completed')
	case 'missing':
		return t('cantoria', 'Still to do')
	case 'problem':
		return t('cantoria', 'Needs attention')
	default:
		return t('cantoria', 'Not checked yet')
	}
}

/**
 * Icon of a state (the text says the same).
 *
 * @param status - state of the step
 */
export function stepStatusIcon(status: StepStatus): string {
	switch (status) {
	case 'done':
		return mdiCheckCircleOutline
	case 'problem':
		return mdiAlertOutline
	case 'unchecked':
		return mdiHelpCircleOutline
	default:
		return mdiCircleOutline
	}
}

/**
 * Names of roles as one text, as the role settings show them.
 *
 * @param roles - role keys
 * @param roleLabels - labels of the roles from the server
 */
function roleNames(roles: string[], roleLabels: Record<string, string>): string {
	return roles.map((role) => translateLabel(roleLabels[role] ?? role)).join(', ')
}

/**
 * What to do for a step that is not done.
 *
 * @param step - the step
 * @param roleLabels - labels of the roles from the server
 */
export function stepHint(step: SetupStep, roleLabels: Record<string, string>): string {
	const without = step.rolesWithoutGroup ?? []
	switch (step.key) {
	case 'archive':
		return t('cantoria', 'Choose the folder in Nextcloud that holds the score files.')
	case 'roles':
		if (step.status === 'problem') {
			return t('cantoria', 'A group of a role no longer exists. Choose another group for the role.')
		}
		if (without.includes('member')) {
			return t('cantoria', 'Give the role "Choir members" a group. Otherwise nobody sees what the conductor shares with the choir.')
		}
		if (without.some((role) => role !== 'librarian')) {
			return t('cantoria', 'The functions you chose need a group for these roles: {roles}.', { roles: roleNames(without, roleLabels) })
		}
		return t('cantoria', 'Give at least the catalog role (music librarians) a group.')
	case 'access':
		if (step.status === 'missing') {
			return t('cantoria', 'Choose the archive folder first.')
		}
		return step.status === 'problem'
			? t('cantoria', 'Some accounts cannot open the archive folder. Share it with their groups (reading is enough) and check again.')
			: t('cantoria', 'Check whether everybody with a role can open the archive folder.')
	case 'modules':
		return t('cantoria', 'Choose what Cantoria should do for your choir: keep the archive in order, plan concerts, let the singers practise, or publish pages.')
	case 'import':
		return t('cantoria', 'A music librarian imports the catalog (Archive management → Import catalog, with a template) or starts without one, with the first new accession.')
	default:
		return t('cantoria', 'A music librarian links the files in the app: Archive management → Run file sync.')
	}
}
