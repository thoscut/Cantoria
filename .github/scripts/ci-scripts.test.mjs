// SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// The CI decides in a job of its own whether code changed, and one job
// ("CI result") sums up all others as the required check (T26). These tests
// run the two scripts as the CI does and check that every job of ci.yml is
// part of the sum (npm test).
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, describe, expect, it } from 'vitest'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const workflow = readFileSync(join(root, '.github/workflows/ci.yml'), 'utf8')

/** @return {Map<string, string>} the jobs of ci.yml with their text */
function jobs() {
	const body = workflow.slice(workflow.indexOf('\njobs:\n') + 7)
	const result = new Map()
	const parts = body.split(/^ {2}(?=[a-z][a-z0-9-]*:$)/m).filter((part) => part.trim() !== '')
	for (const part of parts) {
		result.set(part.slice(0, part.indexOf(':')), part)
	}
	return result
}

/** @return {number | null} the exit code of ci-result.mjs for these results of the jobs */
function ciResult(needs) {
	return spawnSync('node', [join(root, '.github/scripts/ci-result.mjs')], { env: { ...process.env, NEEDS: JSON.stringify(needs) }, encoding: 'utf8' }).status
}

describe('the job "CI result"', () => {
	const all = [...jobs().keys()].filter((job) => job !== 'ci-ok')
	const passed = (result, code = 'true') => Object.fromEntries(all.map((job) => [job, job === 'changes' ? { result: 'success', outputs: { code } } : { result }]))

	it('waits for every other job of ci.yml, also when one failed', () => {
		const job = jobs().get('ci-ok') ?? ''
		expect(job).toContain('if: always()')
		const needs = (/needs: \[([^\]]*)\]/.exec(job)?.[1] ?? '').split(',').map((name) => name.trim())
		expect([...needs].sort()).toEqual([...all].sort())
	})

	it('runs the jobs for code only when code changed, the others always', () => {
		const all = jobs()
		/** @return {string[]} the jobs a job needs */
		const needsOf = (job) => {
			const line = /^ {4}needs: (.*)$/m.exec(job)?.[1] ?? ''
			return line.replace(/[[\]]/g, '').split(',').map((name) => name.trim()).filter((name) => name !== '')
		}
		const direct = (job) => job.includes("if: needs.changes.outputs.code == 'true'") && needsOf(job).includes('changes')
		// a job without a condition of its own is skipped with a job it needs (e.g. the integration tests with the archive)
		const gated = (name) => direct(all.get(name) ?? '') || (!/^ {4}if:/m.test(all.get(name) ?? '') && needsOf(all.get(name) ?? '').some((need) => need !== 'changes' && gated(need)))
		for (const name of all.keys()) {
			expect(gated(name), name).toBe(!['changes', 'reuse', 'docs', 'ci-ok'].includes(name))
		}
		expect(gated('integration'), 'skipped with the archive').toBe(true)
	})

	it('is green when all passed, or when documentation alone skipped the jobs for code', () => {
		expect(ciResult(passed('success'))).toBe(0)
		const docsAlone = { ...passed('skipped', 'false'), reuse: { result: 'success' }, docs: { result: 'success' } }
		expect(ciResult(docsAlone)).toBe(0)
	})

	it('is red when a job failed, was cancelled, or was skipped although code changed', () => {
		for (const result of ['failure', 'cancelled']) {
			expect(ciResult({ ...passed('success'), integration: { result } }), result).toBe(1)
			expect(ciResult({ ...passed('skipped', 'false'), reuse: { result: 'success' }, docs: { result } }), `docs ${result}`).toBe(1)
		}
		expect(ciResult({ ...passed('success'), integration: { result: 'skipped' } })).toBe(1)
		const docsAlone = { ...passed('skipped', 'false'), reuse: { result: 'success' }, docs: { result: 'success' } }
		expect(ciResult({ ...docsAlone, docs: { result: 'skipped' } }), 'the documentation checks always run').toBe(1)
		expect(ciResult({ ...docsAlone, changes: { result: 'failure' } }), 'no decision').toBe(1)
		expect(ciResult({ ...passed('skipped'), changes: { result: 'success' } }), 'no output counts as code').toBe(1)
		expect(ciResult({}), 'nothing ran').toBe(1)
	})
})

describe('the job "Changed paths"', () => {
	const repo = mkdtempSync(join(tmpdir(), 'cantoria-changes-'))
	afterAll(() => rmSync(repo, { recursive: true, force: true }))
	const git = (...args) => execFileSync('git', ['-c', 'user.name=CI', '-c', 'user.email=ci@example.org', '-c', 'commit.gpgsign=false', ...args], { cwd: repo, encoding: 'utf8' }).trim()
	const commit = (files) => {
		for (const [file, text] of Object.entries(files)) {
			mkdirSync(dirname(join(repo, file)), { recursive: true })
			writeFileSync(join(repo, file), text)
		}
		git('add', '-A')
		git('commit', '-q', '-m', Object.keys(files).join(' '))
		return git('rev-parse', 'HEAD')
	}
	const decide = (event, before = '') => execFileSync('sh', [join(root, '.github/scripts/code-changed.sh'), event, before], { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()

	git('init', '-q', '-b', 'main')
	const first = commit({ 'lib/A.php': '<?php\n', 'README.md': 'x\n' })

	it('finds documentation alone in a push', () => {
		const before = git('rev-parse', 'HEAD')
		commit({ 'docs/guide.md': 'a\n', 'SECURITY.md': 'b\n', 'tools/screenshots/a.png': 'c', '.github/ISSUE_TEMPLATE/bug.yml': 'd\n', '.github/pull_request_template.md': 'e\n' })
		expect(decide('push', before)).toBe('code=false')
	})

	it('finds code in any commit of a push, also a workflow or a deletion', () => {
		const before = git('rev-parse', 'HEAD')
		commit({ 'lib/A.php': '<?php // b\n' })
		commit({ 'docs/other.md': 'b\n' })
		expect(decide('push', before)).toBe('code=true')
		const next = git('rev-parse', 'HEAD')
		commit({ '.github/workflows/ci.yml': 'x\n' })
		expect(decide('push', next)).toBe('code=true')
		const last = git('rev-parse', 'HEAD')
		git('rm', '-q', 'lib/A.php')
		git('commit', '-q', '-m', 'remove')
		expect(decide('push', last)).toBe('code=true')
	})

	it('runs everything when it cannot tell', () => {
		expect(decide('push', '0000000000000000000000000000000000000000'), 'a tag or new branch').toBe('code=true')
		expect(decide('push', ''), 'no base').toBe('code=true')
		expect(decide('push', 'ffffffffffffffffffffffffffffffffffffffff'), 'a base outside the history').toBe('code=true')
		expect(decide('workflow_dispatch', first)).toBe('code=true')
		expect(decide('pull_request'), 'no merge commit').toBe('code=true')
	})

	it('compares the merge commit of a pull request with its base', () => {
		const base = git('rev-parse', 'HEAD')
		git('checkout', '-q', '-b', 'topic')
		commit({ 'docs/topic.md': 'c\n' })
		git('checkout', '-q', 'main')
		commit({ 'lib/B.php': '<?php\n' })
		git('merge', '-q', '--no-ff', '--no-edit', 'topic')
		expect(decide('pull_request'), 'only the documentation of the pull request counts').toBe('code=false')
		git('checkout', '-q', '-b', 'code', base)
		commit({ 'src/main.ts': 'x\n' })
		git('checkout', '-q', 'main')
		git('merge', '-q', '--no-ff', '--no-edit', 'code')
		expect(decide('pull_request')).toBe('code=true')
	})
})
