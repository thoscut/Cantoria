/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { deviceErrorText } from '../offline/errors.ts'
import { homeScreenAdvice } from '../offline/platform.ts'

describe('failures of keeping a piece on the device', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('say that the space is used up', () => {
		expect(deviceErrorText(new DOMException('quota', 'QuotaExceededError'))).toContain('not enough space')
	})

	it('say that there is no connection instead of the browser message', () => {
		expect(deviceErrorText(new TypeError('Load failed'))).toContain('no connection')
		expect(deviceErrorText(new TypeError('Failed to fetch'))).not.toContain('fetch')
	})

	it('keep the translated message of the server', () => {
		expect(deviceErrorText(new Error('This piece may not be kept.'))).toBe('This piece may not be kept.')
	})

	it('say what to check when nothing is known', () => {
		expect(deviceErrorText(new Error(''))).toContain('try again')
		expect(deviceErrorText(new DOMException('aborted', 'AbortError'))).toContain('try again')
		expect(deviceErrorText('x')).toContain('try again')
	})

	it('say that there is no connection while the device is offline', () => {
		vi.stubGlobal('navigator', { onLine: false })
		expect(deviceErrorText(new Error(''))).toContain('no connection')
	})
})

describe('the hint about the home screen', () => {
	const iphone = { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', platform: 'iPhone', maxTouchPoints: 5 }
	const ipad = { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5 }
	const mac = { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 0 }
	const android = { userAgent: 'Mozilla/5.0 (Linux; Android 15; Pixel 9)', platform: 'Linux armv8l', maxTouchPoints: 5 }

	it('is given in Safari on an iPhone or iPad', () => {
		expect(homeScreenAdvice(iphone, false)).toBe(true)
		expect(homeScreenAdvice(ipad, false)).toBe(true)
	})

	it('is not given from the home screen or elsewhere', () => {
		expect(homeScreenAdvice(iphone, true)).toBe(false)
		expect(homeScreenAdvice(mac, false)).toBe(false)
		expect(homeScreenAdvice(android, false)).toBe(false)
	})
})
