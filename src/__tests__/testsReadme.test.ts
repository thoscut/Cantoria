/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/README.md describes every test of the server; this keeps it in step
// with the test files (N5), as security.test.ts does for SECURITY.md.
import { describe, expect, it } from 'vitest'
import readme from '../../tests/README.md?raw'

const UNIT = Object.keys(import.meta.glob('../../tests/Unit/**/*Test.php'))
	.map((path) => path.replace('../../tests/Unit/', '').replace(/\.php$/, ''))
const INTEGRATION = Object.keys(import.meta.glob('../../tests/Integration/*_test.php'))
	.map((path) => path.replace('../../tests/Integration/', ''))

/** The section "What is covered" of the unit tests */
const covered = readme.split('What is covered:')[1]?.split('\n## ')[0] ?? ''

describe('tests/README.md', () => {
	it('names every unit test class in the table "What is covered"', () => {
		expect(UNIT.length).toBeGreaterThan(50)
		expect(UNIT.filter((test) => !covered.includes('`' + test + '`'))).toEqual([])
	})

	it('names no unit test class that does not exist', () => {
		const named = [...covered.matchAll(/`((?:[A-Z]\w*\/)+\w+Test)`/g)].map(([, test]) => test)
		expect(named.length).toBeGreaterThan(50)
		expect(named.filter((test) => !UNIT.includes(test!))).toEqual([])
	})

	it('describes each unit test class once', () => {
		const rows = covered.split('\n').filter((line) => line.startsWith('| `'))
		const first = rows.flatMap((row) => [...row.split(' | ')[0]!.matchAll(/`([^`]+)`/g)].map(([, test]) => test))
		expect(first.filter((test, index) => first.indexOf(test) !== index)).toEqual([])
	})

	it('shows how to run every integration script and what the CI passes to it', () => {
		const ci = readme.split('### Integration tests in CI')[1] ?? ''
		expect(INTEGRATION.length).toBeGreaterThan(15)
		expect(INTEGRATION.filter((script) => !readme.includes('tests/Integration/' + script))).toEqual([])
		expect(INTEGRATION.filter((script) => !ci.includes('| `' + script + '` |'))).toEqual([])
	})
})
