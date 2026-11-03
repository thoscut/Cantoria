/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The stage view of the concert folder and of "Shared with me" (milestone
 * 0.19): the shown piece fills the screen, and the screen stays on while it
 * is shown. The whole page goes to the whole screen, not only the piece:
 * dialogs, the list of a select, popovers and messages hang at the end of
 * the page (body), and the browser shows nothing outside the element that
 * fills the screen. The page hides everything but the piece with a class
 * of its own. Where the browser cannot fill the screen (an iPhone), the page
 * still hides the rest and keeps the screen on. Both end when the person
 * leaves the stage view: with its button, with Escape (also without the
 * whole screen), or with the browser's own way out of the whole screen.
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted, ref } from 'vue'

/** The part of the Screen Wake Lock API the stage view uses */
interface WakeLock {
	release(): Promise<void>
}

/** Where Escape belongs to something else: a dialog, an open list, a field */
const OWN_ESCAPE = '[role="dialog"], [role="alertdialog"], .modal-mask, [aria-expanded="true"], [role="listbox"], input, textarea, select, [contenteditable="true"]'

export interface Stage {
	/** the stage view is on */
	on: Ref<boolean>
	enter: () => Promise<void>
	leave: () => Promise<void>
	toggle: () => Promise<void>
}

/**
 * Stage view of a page.
 *
 * @param onLeave - called after the stage view ended, e.g. to put the focus back
 */
export function useStage(onLeave?: () => void): Stage {
	const on = ref(false)
	let lock: WakeLock | null = null

	async function holdScreen(): Promise<void> {
		const wakeLock = (navigator as Navigator & { wakeLock?: { request(type: 'screen'): Promise<WakeLock> } }).wakeLock
		if (!wakeLock || lock !== null || document.visibilityState !== 'visible') {
			return
		}
		try {
			lock = await wakeLock.request('screen')
			if (!on.value) {
				// left while the browser was still asked
				releaseScreen()
			}
		} catch {
			// e.g. battery saver: the screen may turn off as usual
		}
	}

	function releaseScreen(): void {
		lock?.release().catch(() => {})
		lock = null
	}

	async function enter(): Promise<void> {
		if (on.value) {
			return
		}
		on.value = true
		await holdScreen()
		// the whole page, so that dialogs and lists at the end of the page stay visible
		const page = document.documentElement
		if (on.value && document.fullscreenEnabled && document.fullscreenElement === null && typeof page.requestFullscreen === 'function') {
			try {
				await page.requestFullscreen({ navigationUI: 'hide' })
			} catch {
				// the page without the list is the stage view then
			}
		}
	}

	async function leave(): Promise<void> {
		if (!on.value) {
			return
		}
		on.value = false
		releaseScreen()
		if (document.fullscreenElement) {
			await document.exitFullscreen().catch(() => {})
		}
		onLeave?.()
	}

	function onFullscreenChange(): void {
		// Escape or the browser's own button ended the whole screen
		if (on.value && document.fullscreenElement === null) {
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

	/**
	 * Escape without the whole screen (an iPhone, or the browser refused):
	 * the browser does not end anything then, the page does. A dialog, an
	 * open list or a field keeps its own Escape.
	 *
	 * @param event - key event on the page
	 */
	function onKeydown(event: KeyboardEvent): void {
		if (!on.value || event.key !== 'Escape' || event.defaultPrevented || document.fullscreenElement) {
			return
		}
		const target = event.target instanceof Element ? event.target : null
		if (target?.closest(OWN_ESCAPE) || document.querySelector('.modal-mask, [role="dialog"][aria-modal="true"]')) {
			return
		}
		event.preventDefault()
		leave()
	}

	onMounted(() => {
		document.addEventListener('fullscreenchange', onFullscreenChange)
		document.addEventListener('visibilitychange', onVisibilityChange)
		document.addEventListener('keydown', onKeydown)
	})
	onBeforeUnmount(() => {
		document.removeEventListener('fullscreenchange', onFullscreenChange)
		document.removeEventListener('visibilitychange', onVisibilityChange)
		document.removeEventListener('keydown', onKeydown)
		releaseScreen()
		if (on.value && document.fullscreenElement) {
			document.exitFullscreen().catch(() => {})
		}
		on.value = false
	})

	return { on, enter, leave, toggle: () => (on.value ? leave() : enter()) }
}
