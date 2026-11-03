/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Keeps the focus visible below a sticky header (WCAG 2.4.11 focus not
 * obscured): the scrolling content area gets a scroll padding as high as
 * the header, so that the browser scrolls a focused element below the
 * header instead of under it (e.g. when tabbing backwards through a list).
 * The header may appear later than the component (the bar of a score view
 * comes with the score); the container is the scrolling element around it.
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, watch } from 'vue'

/** Room between the header and a focused element (px). */
const GAP = 8

/** The scrolling content of the app pages */
export const APP_CONTENT = '#app-content-vue, .app-content'

let owners = 0

/**
 * Use in a component with a sticky header.
 *
 * @param header - the sticky element
 * @param containers - selector of the scrolling element around it, the nearest one counts
 */
export function useStickyHeader(header: Readonly<Ref<HTMLElement | null>>, containers: string = APP_CONTENT): void {
	let observer: ResizeObserver | null = null
	let container: HTMLElement | null = null
	const owner = ++owners

	const update = (): void => {
		if (container && header.value) {
			container.style.scrollPaddingTop = `${Math.ceil(header.value.getBoundingClientRect().height) + GAP}px`
			container.dataset.stickyOwner = String(owner)
		}
	}

	const release = (): void => {
		observer?.disconnect()
		observer = null
		// Another view may already have taken over the container
		if (container && container.dataset.stickyOwner === String(owner)) {
			container.style.scrollPaddingTop = ''
			delete container.dataset.stickyOwner
		}
		container = null
	}

	watch(header, (element) => {
		release()
		container = element?.parentElement?.closest<HTMLElement>(containers) ?? null
		if (!container || !element) {
			container = null
			return
		}
		update()
		if (typeof ResizeObserver !== 'undefined') {
			observer = new ResizeObserver(update)
			observer.observe(element)
		}
	}, { immediate: true, flush: 'post' })

	onBeforeUnmount(release)
}
