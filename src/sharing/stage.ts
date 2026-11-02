/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The stage view of the concert folder (milestone 0.19): the shown piece
 * fills the screen, and the screen stays on while it is shown. Where the
 * browser cannot show an element on the whole screen (an iPhone), the page
 * still hides the programme list and keeps the screen on. Both end when
 * the person leaves the stage view, also with Escape.
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted, ref } from 'vue'

/** The part of the Screen Wake Lock API the stage view uses */
interface WakeLock {
	release(): Promise<void>
}

/**
 * Stage view of an element.
 *
 * @param element - the element that fills the screen
 */
export function useStage(element: Ref<HTMLElement | null>): { on: Ref<boolean>, toggle: () => Promise<void> } {
	const on = ref(false)
	let lock: WakeLock | null = null

	async function holdScreen(): Promise<void> {
		const wakeLock = (navigator as Navigator & { wakeLock?: { request(type: 'screen'): Promise<WakeLock> } }).wakeLock
		if (!wakeLock || lock !== null || document.visibilityState !== 'visible') {
			return
		}
		try {
			lock = await wakeLock.request('screen')
		} catch {
			// e.g. battery saver: the screen may turn off as usual
		}
	}

	function releaseScreen(): void {
		lock?.release().catch(() => {})
		lock = null
	}

	async function enter(): Promise<void> {
		on.value = true
		await holdScreen()
		const target = element.value
		if (target && document.fullscreenEnabled && typeof target.requestFullscreen === 'function') {
			try {
				await target.requestFullscreen({ navigationUI: 'hide' })
			} catch {
				// the page view without the list is the stage view then
			}
		}
	}

	async function leave(): Promise<void> {
		on.value = false
		releaseScreen()
		if (document.fullscreenElement) {
			await document.exitFullscreen().catch(() => {})
		}
	}

	function onFullscreenChange(): void {
		// Escape or the browser's own button ended the whole screen
		if (on.value && document.fullscreenElement === null && document.fullscreenEnabled) {
			leave()
		}
	}

	function onVisibilityChange(): void {
		// the browser releases the lock when the page is hidden
		if (document.visibilityState === 'visible' && on.value) {
			lock = null
			holdScreen()
		}
	}

	onMounted(() => {
		document.addEventListener('fullscreenchange', onFullscreenChange)
		document.addEventListener('visibilitychange', onVisibilityChange)
	})
	onBeforeUnmount(() => {
		document.removeEventListener('fullscreenchange', onFullscreenChange)
		document.removeEventListener('visibilitychange', onVisibilityChange)
		releaseScreen()
	})

	return { on, toggle: () => (on.value ? leave() : enter()) }
}
