// SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// The result of the whole CI (job "ci-ok" in ci.yml, the required check of
// the branch protection): reads the results of all jobs from NEEDS
// (toJSON(needs)) and exits with 1 unless
// - every job passed, or
// - the job "changes" found documentation alone and only the jobs for code
//   were skipped (the jobs below in ALWAYS must pass in any case).
// A failed or cancelled job is always red, and so is a skipped job when code
// changed (e.g. the integration tests after a failed archive).

import { pathToFileURL } from 'node:url'

/** Jobs that run for every change, documentation alone included */
export const ALWAYS = ['changes', 'reuse', 'docs']

/**
 * @param {Record<string, {result: string, outputs?: Record<string, string>}>} needs
 * @return {{ok: boolean, lines: string[]}}
 */
export function verdict(needs) {
	const code = needs.changes?.outputs?.code !== 'false'
	const lines = []
	let ok = needs.changes?.result === 'success'
	for (const job of ALWAYS) {
		if (!(job in needs)) {
			lines.push(`NOT ${job}: missing`)
			ok = false
		}
	}
	for (const [job, { result }] of Object.entries(needs)) {
		const fine = result === 'success' || (result === 'skipped' && !code && !ALWAYS.includes(job))
		lines.push(`${fine ? 'ok ' : 'NOT'} ${job}: ${result}`)
		ok = ok && fine
	}
	lines.push(code ? 'code changed: every job must pass' : 'documentation alone: the jobs for code may be skipped')
	return { ok, lines }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	const { ok, lines } = verdict(JSON.parse(process.env.NEEDS ?? '{}'))
	console.log(lines.join('\n'))
	process.exit(ok ? 0 : 1)
}
