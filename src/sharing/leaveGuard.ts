/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The browser asks before the page is left or reloaded while a recording of
 * oneself is not saved (milestone 0.19).
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Ask before leaving the page while `unsaved` is true.
 *
 * @param unsaved - a recording waits unsaved
 */
export function useLeaveGuard(unsaved: Ref<boolean>): void {
	const onBeforeUnload = (event: BeforeUnloadEvent) => {
		if (unsaved.value) {
			event.preventDefault()
		}
	}
	onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
	onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
}
