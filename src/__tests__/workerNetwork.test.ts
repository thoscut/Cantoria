/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { networkFirst, TIMEOUT_MS } from '../offline/network.ts'

/** A request to the network that answers or fails when the test says so */
function slowNetwork(): { network: () => Promise<Response>, answer: (body: string) => void, fail: () => void } {
	let answerWith: (response: Response) => void = () => undefined
	let failWith: (error: Error) => void = () => undefined
	const promise = new Promise<Response>((resolve, reject) => {
		answerWith = resolve
		failWith = reject
	})
	return { network: () => promise, answer: (body) => answerWith(new Response(body)), fail: () => failWith(new TypeError('Failed to fetch')) }
}

describe('the service worker between network and device', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('answers a kept piece from the device when the network is slow, and refreshes it later', async () => {
		const { network, answer } = slowNetwork()
		const stored: string[] = []
		const waiting: Promise<unknown>[] = []
		const result = networkFirst(network, (promise) => waiting.push(promise), new Response('kept'), async (response) => {
			stored.push(await response.text())
		})
		await vi.advanceTimersByTimeAsync(TIMEOUT_MS)
		expect(await (await result).text()).toBe('kept')
		answer('fresh')
		// the late answer asks to wait for writing it to the device
		await Promise.all(waiting)
		await Promise.all(waiting)
		expect(waiting).toHaveLength(2)
		expect(stored).toEqual(['fresh'])
	})

	// S N19: a shared device whose session ended without signing out; the next account opens the list on a slow network
	it('never answers the page of a list from the device only because the network is slow', async () => {
		const { network, answer } = slowNetwork()
		let settled = false
		const result = networkFirst(network, () => undefined, new Response('page of the previous account'), () => undefined, null)
		result.then(() => {
			settled = true
		}, () => undefined)
		await vi.advanceTimersByTimeAsync(TIMEOUT_MS * 10)
		expect(settled).toBe(false)
		answer('page of the account signed in now')
		expect(await (await result).text()).toBe('page of the account signed in now')
	})

	it('answers the page of a list from the device without a network', async () => {
		const { network, fail } = slowNetwork()
		const result = networkFirst(network, () => undefined, new Response('kept page'), () => undefined, null)
		fail()
		expect(await (await result).text()).toBe('kept page')
	})

	it('passes the network on when nothing is kept', async () => {
		const { network, fail } = slowNetwork()
		const result = networkFirst(network, () => undefined, undefined, () => undefined)
		fail()
		await expect(result).rejects.toThrow('Failed to fetch')
	})
})
