/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: CC0-1.0
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'fs'
import { join, resolve } from 'path'
import { build } from 'vite'

// Unit tests (npm test) run the pure logic in Node, without the build plugins;
// tools/l10n checks the translations
const testConfig = {
	test: {
		include: ['src/**/*.test.ts', 'tools/l10n/*.test.mjs'],
		environment: 'node',
	},
}

/**
 * The service worker of the pages that keep pieces on a device (milestone
 * 0.15): a classic script without imports, built after the app into
 * js/cantoria-offline-worker.js; OfflineController::worker serves it from
 * the app's path, so that it covers the pages.
 */
const offlineWorker = {
	name: 'cantoria-offline-worker',
	apply: 'build' as const,
	async closeBundle() {
		await build({
			configFile: false,
			logLevel: 'warn',
			build: {
				outDir: 'js',
				emptyOutDir: false,
				copyPublicDir: false,
				minify: true,
				lib: {
					entry: resolve(join('src', 'offline', 'worker.ts')),
					formats: ['iife'],
					name: 'cantoriaOfflineWorker',
					fileName: () => 'cantoria-offline-worker.js',
				},
			},
		})
	},
}

/**
 * Third-party code in the built scripts with its license text
 * (js/third-party-licenses.txt, part of the release archive): MIT, BSD and
 * ISC ask for the copyright notice and the license with every copy. The
 * release script adds the full texts of the GPL, LGPL and OFL to LICENSES/
 * (tools/release/package.sh).
 */
const thirdPartyLicenses = {
	name: 'cantoria-third-party-licenses',
	apply: 'build' as const,
	enforce: 'post' as const,
	generateBundle(_options: unknown, bundle: Record<string, { type: string, moduleIds?: string[] }>) {
		const roots = new Set<string>()
		for (const chunk of Object.values(bundle)) {
			for (const id of chunk.type === 'chunk' ? chunk.moduleIds ?? [] : []) {
				// virtual modules of the CommonJS plugin start with \0
				const match = id.replace(/^\0/, '').replace(/\\/g, '/').match(/^(.*\/node_modules\/(?:@[^/]+\/)?[^/]+)\//)
				if (match) {
					roots.add(match[1])
				}
			}
		}
		const packages = [...roots].map((root) => {
			const manifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf-8')) as { name?: string, version?: string, license?: string, repository?: string | { url?: string }, homepage?: string }
			const texts = readdirSync(root)
				.filter((file) => /^(licen[cs]e|copying|notice)/i.test(file) && statSync(join(root, file)).isFile())
				.sort()
				.map((file) => readFileSync(join(root, file), 'utf-8').trim())
			const source = typeof manifest.repository === 'string' ? manifest.repository : manifest.repository?.url ?? manifest.homepage
			return { name: manifest.name ?? root, version: manifest.version ?? '', license: manifest.license ?? 'not stated', source, texts }
		})
		const unique = new Map(packages.map((entry) => [`${entry.name}@${entry.version}`, entry]))
		const parts = [...unique.values()]
			.sort((x, y) => x.name.localeCompare(y.name))
			.map((entry) => {
				const head = [`${entry.name} ${entry.version}`, `License: ${entry.license}`]
				if (entry.source) {
					head.push(`Source: ${entry.source}`)
				}
				if (entry.name === 'verovio') {
					head.push('Verovio embeds the SMuFL music fonts Bravura, Gootville, Leipzig, Leland and Petaluma under the SIL Open Font License 1.1 (LICENSES/OFL-1.1.txt). The texts of the LGPL and GPL are in LICENSES/LGPL-3.0-or-later.txt and LICENSES/GPL-3.0-or-later.txt.')
				}
				const texts = entry.texts.length > 0 ? entry.texts : ['The package ships no license file; the text of the license named above is in LICENSES/ or at https://spdx.org/licenses/.']
				return [head.join('\n'), ...texts].join('\n\n')
			})
		mkdirSync('js', { recursive: true })
		writeFileSync(join('js', 'third-party-licenses.txt'), ['Third-party software in the scripts of Cantoria', ...parts].join('\n\n' + '-'.repeat(72) + '\n\n') + '\n')
	},
}

export default process.env.VITEST
	? testConfig
	: createAppConfig(
		{
			main: resolve(join('src', 'main.ts')),
			// Action "Open in Cantoria" in the Files app (small: no score viewer, no Vue)
			files: resolve(join('src', 'files.ts')),
			// Administration → Cantoria: only the settings, without the
			// catalog and the score viewer
			'admin-settings': resolve(join('src', 'adminSettings.ts')),
			// Page "Shared with me" for choir members (milestone 0.7: excerpts only, no catalog)
			member: resolve(join('src', 'member.ts')),
			// Page of a public link (milestone 0.7: one excerpt, no further requests)
			public: resolve(join('src', 'public.ts')),
			// Concert folder of the conductor (milestone 0.15: programmes, also without a network)
			'concert-folder': resolve(join('src', 'concertFolder.ts')),
		},
		{
			createEmptyCSSEntryPoints: true,
			extractLicenseInformation: true,
			thirdPartyLicense: false,
			config: {
				plugins: [offlineWorker, thirdPartyLicenses],
			},
		},
	)
