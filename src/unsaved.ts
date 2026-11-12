/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Input that is typed but not saved yet: forms register while they have
 * changes, and whatever would throw them away (another copy in the sidebar,
 * closing the sidebar, a new load of the inventory, leaving the page) asks
 * first. Leitlinie 13: avoid the error instead of reporting it.
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted } from 'vue'
import { t } from './l10n.ts'

/** The registered forms: each says whether it has changes */
const sources = new Set<() => boolean>()

/**
 * Whether any registered form has changes that are not saved.
 */
export function hasUnsavedInput(): boolean {
	for (const dirty of sources) {
		if (dirty()) {
			return true
		}
	}
	return false
}

/**
 * Asks whether to throw away unsaved input; true without asking when there is
 * none. The dialog can be replaced in tests.
 *
 * @param ask - shows the question and resolves with the answer
 */
export async function confirmDiscard(ask: () => Promise<boolean> = askDiscard): Promise<boolean> {
	return hasUnsavedInput() ? ask() : true
}

/**
 * The question itself, as a dialog of Nextcloud.
 */
async function askDiscard(): Promise<boolean> {
	// loaded on first use: the store imports this module, and the dialogs
	// bring components with styles along
	const { getDialogBuilder } = await import('@nextcloud/dialogs')
	let discard = false
	const dialog = getDialogBuilder(t('cantoria', 'Discard your changes?'))
		.setText(t('cantoria', 'You have changes that are not saved. If you go on, they are gone.'))
		.setButtons([
			{ label: t('cantoria', 'Keep editing'), variant: 'primary', callback: () => { discard = false } },
			{ label: t('cantoria', 'Discard changes'), variant: 'error', callback: () => { discard = true } },
		])
		.build()
	try {
		await dialog.show()
	} catch {
		// closed without an answer: keep editing
		return false
	}
	return discard
}

/**
 * Registers a form while it is shown; the browser also asks before the page
 * is left or reloaded while it has changes.
 *
 * @param dirty - the form has changes that are not saved
 */
export function useUnsavedGuard(dirty: Ref<boolean>): void {
	const source = () => dirty.value
	const onBeforeUnload = (event: BeforeUnloadEvent) => {
		if (dirty.value) {
			event.preventDefault()
		}
	}
	onMounted(() => {
		sources.add(source)
		window.addEventListener('beforeunload', onBeforeUnload)
	})
	onBeforeUnmount(() => {
		sources.delete(source)
		window.removeEventListener('beforeunload', onBeforeUnload)
	})
}
