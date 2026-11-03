/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * How the service worker (worker.ts) chooses between the network and what
 * a page kept on the device. No imports: the worker is built from it, too.
 */

/** How long to wait for the network before a kept piece is answered from the device */
export const TIMEOUT_MS = 4000

/**
 * The network's answer; the kept one when the network fails, and with
 * `timeoutMs` also when the network takes longer than that. The network's
 * answer refreshes what is kept, also when it comes late.
 *
 * The page of a list ("Shared with me", the concert folder) is answered
 * from the device only when the network fails (timeoutMs null, S N19): it
 * carries the account it was kept for, and on a shared device whose
 * session ended without signing out, a slow network must not show the
 * page of the previous account to the next one.
 *
 * @param network - the request to the network
 * @param waitUntil - keeps the worker alive until the cache is written (FetchEvent.waitUntil)
 * @param kept - the kept answer, undefined when there is none or it may not be shown
 * @param store - writes the network's answer (a copy) to the device
 * @param timeoutMs - how long to wait for the network before the kept answer; null: until it answers or fails
 */
export function networkFirst(network: () => Promise<Response>, waitUntil: (promise: Promise<unknown>) => void, kept: Response | undefined,
	store: (response: Response) => Promise<void> | void, timeoutMs: number | null = TIMEOUT_MS): Promise<Response> {
	const answer = network().then((response) => {
		waitUntil(Promise.resolve(store(response.clone())).catch(() => undefined))
		return response
	})
	if (!kept) {
		return answer
	}
	waitUntil(answer.then(() => undefined, () => undefined))
	const fallback = answer.catch(() => kept)
	if (timeoutMs === null) {
		return fallback
	}
	const late = new Promise<Response>((resolve) => {
		setTimeout(() => resolve(kept), timeoutMs)
	})
	return Promise.race([fallback, late])
}
