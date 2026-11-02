#!/usr/bin/env node
/**
 * Recreates the screenshots of the documentation (docs/screenshots) from a
 * Nextcloud with the Cantoria app and the public demo data (tools/demo).
 *
 *   node shoot.mjs --url http://localhost:8080 [--prepare] [--only a,b] [--out dir]
 *
 * See README.md in this folder for the requirements, the accounts and the
 * environment variables with their passwords.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'
import puppeteer from 'puppeteer-core'
import { SHOTS } from './shots.mjs'
import { prepare } from './prepare.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))

const { values: args } = parseArgs({
	options: {
		url: { type: 'string', default: process.env.NC_URL },
		browser: { type: 'string', default: process.env.BROWSER_PATH },
		out: { type: 'string', default: join(HERE, '..', '..', 'docs', 'screenshots') },
		only: { type: 'string' },
		prepare: { type: 'boolean', default: false },
		'no-optimize': { type: 'boolean', default: false },
		list: { type: 'boolean', default: false },
		'demo-folder': { type: 'string', default: 'Cantoria-Demo' },
	},
})

if (args.list) {
	for (const shot of SHOTS) {
		console.log(`${shot.file.padEnd(32)} ${shot.account.padEnd(12)} ${shot.lang} ${shot.theme}`)
	}
	process.exit(0)
}
if (!args.url) {
	console.error('Give the address of the Nextcloud with --url (or NC_URL).')
	process.exit(2)
}

/** Accounts of the demo; the user ids can be changed by environment variables. */
const ACCOUNTS = {
	librarian: process.env.NC_USER_LIBRARIAN ?? 'demo-librarian',
	conductor: process.env.NC_USER_CONDUCTOR ?? 'demo-conductor',
	contributor: process.env.NC_USER_CONTRIBUTOR ?? 'demo-contributor',
	admin: process.env.NC_USER_ADMIN ?? 'demo-admin',
	member: process.env.NC_USER_MEMBER ?? 'demo-member',
	// the public: no account, no login
	public: null,
}

/**
 * Password of an account: NC_PASSWORD_<UID> (upper case, other characters
 * as "_"), otherwise NC_PASSWORD. Never printed.
 *
 * @param {string} uid account
 */
function password(uid) {
	const specific = 'NC_PASSWORD_' + uid.toUpperCase().replace(/[^A-Z0-9]/g, '_')
	const value = process.env[specific] ?? process.env.NC_PASSWORD
	if (!value) {
		throw new Error(`No password for ${uid}: set ${specific} or NC_PASSWORD.`)
	}
	return value
}

/** First existing browser executable (Chromium-based: Chrome, Chromium, Edge). */
async function browserPath() {
	if (args.browser) {
		return args.browser
	}
	const { existsSync } = await import('node:fs')
	const candidates = [
		'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
		'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
		'/usr/bin/google-chrome',
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	]
	const found = candidates.find((candidate) => existsSync(candidate))
	if (!found) {
		throw new Error('No Chromium-based browser found; give its path with --browser.')
	}
	return found
}

const WIDTH = 1600
const HEIGHT = 1000
const BASE = args.url.replace(/\/+$/, '')
const APP = `${BASE}/index.php/apps/cantoria/`
const sleep = (ms) => new Promise((done) => setTimeout(done, ms))

/**
 * Styles that keep screenshots stable: no animations and transitions, no
 * text cursor, no toasts. Nextcloud's own layout stays untouched.
 */
const STABLE_CSS = `
*, *::before, *::after { animation-duration: 0s !important; animation-delay: 0s !important; transition: none !important; caret-color: transparent !important; }
.toastify, .toast-undo-container { display: none !important; }
`

/** Language of each account as last set here (sessions of both browsers share it). */
const accountLanguages = new Map()

/** A logged-in browser tab of one account (own context, own cookies). */
class Session {
	constructor(browser, role) {
		this.browser = browser
		this.role = role
		this.uid = ACCOUNTS[role]
		this.page = null
		this.lang = null
		this.theme = null
	}

	async open() {
		const context = await this.browser.createBrowserContext()
		const page = await context.newPage()
		await page.setViewport({ width: WIDTH, height: HEIGHT, deviceScaleFactor: 1 })
		// Background jobs started by the browser ("AJAX" cron) would change
		// the state while capturing; the screenshots do not need them.
		await page.setRequestInterception(true)
		page.on('request', (request) => {
			if (/\/cron\.php(\?|$)/.test(request.url())) {
				request.abort()
			} else {
				request.continue()
			}
		})
		page.on('dialog', (dialog) => dialog.dismiss())
		this.page = page
		if (this.uid === null) {
			return this
		}
		await page.goto(`${BASE}/index.php/login`, { waitUntil: 'networkidle2' })
		if (page.url().includes('/login')) {
			await page.waitForSelector('input[name=user]')
			await page.type('input[name=user]', this.uid)
			await page.type('input[name=password]', password(this.uid))
			await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }), page.keyboard.press('Enter')])
			if (page.url().includes('/login')) {
				throw new Error(`Login of ${this.uid} failed.`)
			}
		}
		this.page = page
		return this
	}

	/** JSON request to the app's API with the session's CSRF token. */
	async api(method, path, body = undefined) {
		return this.page.evaluate(async (method, url, body) => {
			const headers = { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: window.OC?.requestToken ?? document.head.dataset.requesttoken }
			const response = await fetch(url, { method, headers, credentials: 'include', body: body === undefined ? undefined : JSON.stringify(body) })
			const text = await response.text()
			let json = null
			try {
				json = JSON.parse(text)
			} catch {}
			return { status: response.status, json }
		}, method, `${BASE}/index.php/apps/cantoria${path}`, body)
	}

	/** Raw request in the session (WebDAV, OCS). */
	async request(method, path, { body = undefined, headers = {} } = {}) {
		return this.page.evaluate(async (method, url, body, headers) => {
			const response = await fetch(url, { method, credentials: 'include', body, headers: { requesttoken: window.OC?.requestToken ?? document.head.dataset.requesttoken, ...headers } })
			return { status: response.status, text: await response.text() }
		}, method, BASE + path, body, headers)
	}

	/**
	 * Language and theme of the next screenshots. The language is the
	 * account's own setting (changed like in the personal settings).
	 */
	async use({ lang, theme }) {
		// Without an account the browser's language counts
		if (this.uid === null) {
			await this.page.setExtraHTTPHeaders({ 'Accept-Language': lang })
		} else if (lang !== accountLanguages.get(this.uid)) {
			const locale = lang === 'de' ? 'de_DE' : 'en_GB'
			for (const [key, value] of [['language', lang], ['locale', locale]]) {
				const result = await this.request('PUT', `/ocs/v2.php/cloud/users/${encodeURIComponent(this.uid)}`, {
					body: new URLSearchParams({ key, value }).toString(),
					headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
				})
				if (result.status !== 200) {
					throw new Error(`Setting ${key} of ${this.uid} failed: HTTP ${result.status}`)
				}
			}
			accountLanguages.set(this.uid, lang)
		}
		this.lang = lang
		if (theme !== this.theme) {
			await this.page.emulateMediaFeatures([
				{ name: 'prefers-color-scheme', value: theme },
				{ name: 'prefers-reduced-motion', value: 'reduce' },
			])
			this.theme = theme
		}
	}
}

/** German translations of the app (informal), to find buttons by their text. */
const GERMAN = JSON.parse(readFileSync(join(HERE, '..', '..', 'l10n', 'de.json'), 'utf8')).translations

/** Helpers for the shot recipes in shots.mjs. */
function helpers(session) {
	const page = session.page
	const h = {
		page,
		session,
		sleep,
		/** An English source string of the app in the session's language. */
		t(text) {
			if (session.lang !== 'de') {
				return text
			}
			const translated = GERMAN[text]
			return Array.isArray(translated) ? translated[0] : (translated ?? text)
		},
		/** Id of a copy by its shelf mark. */
		async copyId(shelfmark) {
			const entries = (await session.api('GET', '/api/entries')).json ?? []
			const entry = entries.find((e) => e.shelfmark === shelfmark)
			if (!entry) {
				throw new Error(`No copy ${shelfmark}`)
			}
			return entry.id
		},
		/** Id of a concert by its title. */
		async eventId(title) {
			const events = (await session.api('GET', '/api/events?kind=concert')).json ?? []
			const event = events.find((e) => e.title === title)
			if (!event) {
				throw new Error(`No concert ${title}`)
			}
			return event.id
		},
		/** Wait until the app shows content and nothing is loading. */
		async settle(extra = 800) {
			await page.waitForFunction(() => !document.querySelector('.loading-icon, .icon-loading'), { timeout: 30000 }).catch(() => {})
			await page.evaluate(() => document.fonts.ready)
			await sleep(extra)
		},
		/** Open a view of the app (query string such as "?view=catalog"). */
		async app(query = '') {
			await page.goto(APP + query, { waitUntil: 'networkidle2', timeout: 60000 })
			await page.waitForFunction(() => (document.querySelector('#app-content-vue')?.innerText.length ?? 0) > 30, { timeout: 30000 })
			await page.addStyleTag({ content: STABLE_CSS })
			await h.settle(1200)
		},
		/** Open any Nextcloud page (path below index.php). */
		async goto(path) {
			await page.goto(`${BASE}/index.php${path}`, { waitUntil: 'networkidle2', timeout: 60000 })
			await page.addStyleTag({ content: STABLE_CSS })
			await h.settle(1500)
		},
		/** Click the first visible element whose text or label contains one of the texts. */
		async click(selector, texts) {
			const list = texts === undefined ? null : [].concat(texts)
			const done = await page.evaluate((selector, list) => {
				const visible = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'
				const label = (el) => `${el.innerText ?? ''} ${el.getAttribute('aria-label') ?? ''} ${el.getAttribute('title') ?? ''}`
				const el = [...document.querySelectorAll(selector)].filter(visible)
					.find((c) => list === null || list.some((text) => label(c).includes(text)))
				if (!el) {
					return false
				}
				el.scrollIntoView({ block: 'center' })
				el.click()
				return true
			}, selector, list)
			if (!done) {
				throw new Error(`Nothing to click: ${selector} ${list ?? ''}`)
			}
			await sleep(700)
		},
		/** Set an input (found by its label text) like a user would. */
		async fill(labels, value, scope = 'body') {
			const handle = await page.evaluateHandle((labels, scope) => {
				const root = document.querySelector(scope) ?? document.body
				const label = [...root.querySelectorAll('label')].find((l) => labels.some((text) => l.innerText.trim().startsWith(text)))
				if (!label) {
					return null
				}
				return label.htmlFor ? document.getElementById(label.htmlFor) : label.querySelector('input, textarea')
			}, [].concat(labels), scope)
			const element = handle.asElement()
			if (!element) {
				throw new Error(`No field ${labels}`)
			}
			await element.click()
			await page.keyboard.down('Control')
			await page.keyboard.press('KeyA')
			await page.keyboard.up('Control')
			await page.keyboard.press('Backspace')
			await page.keyboard.type(value)
			await sleep(300)
		},
		/** Choose an option of an NcSelect (vue-select) found by its label. */
		async select(labels, optionText) {
			const toggle = await page.evaluateHandle((labels) => {
				const label = [...document.querySelectorAll('label')].find((l) => labels.some((text) => l.innerText.trim().startsWith(text)))
				const root = label?.closest('.v-select') ?? label?.parentElement?.querySelector('.v-select')
				const target = root?.querySelector('.vs__dropdown-toggle') ?? null
				target?.scrollIntoView({ block: 'center' })
				return target
			}, [].concat(labels))
			const element = toggle.asElement()
			if (!element) {
				throw new Error(`No select ${labels}`)
			}
			await element.click()
			await sleep(500)
			const chosen = await page.evaluate((text) => {
				const options = [...document.querySelectorAll('.vs__dropdown-option')]
				const option = options.find((o) => o.innerText.trim() === text) ?? options.find((o) => o.innerText.includes(text))
				option?.click()
				return option ? null : options.map((o) => o.innerText.trim()).join(' | ')
			}, optionText)
			if (chosen !== null) {
				throw new Error(`No option "${optionText}" in ${labels}: ${chosen}`)
			}
			await sleep(600)
		},
		/** Remove the focus ring and the mouse hover before a screenshot. */
		async calm() {
			await page.evaluate(() => {
				document.activeElement?.blur?.()
				document.querySelectorAll('.toastify').forEach((toast) => toast.remove())
			})
			await page.mouse.move(WIDTH - 2, HEIGHT - 2)
			await sleep(400)
		},
		/**
		 * Rectangle around elements (union), with padding, inside the viewport.
		 *
		 * @param {string|string[]} selectors CSS selectors; the first match of each counts
		 * @param {object} options pad: padding; maxHeight: cut at this height;
		 *   fit: only as high as the visible content of the elements
		 */
		async rect(selectors, { pad = 16, maxHeight = HEIGHT, minWidth = 0, fit = false } = {}) {
			const box = await page.evaluate((selectors, fit, pad) => {
				const rects = []
				let first = null
				for (const selector of selectors) {
					const el = [...document.querySelectorAll(selector)].find((c) => c.getClientRects().length > 0)
					if (el) {
						first ??= el
						const rect = el.getBoundingClientRect()
						let bottom = rect.bottom
						if (fit) {
							// Only as high as the visible content inside the element
							bottom = rect.top
							for (const child of el.querySelectorAll('*')) {
								const r = child.getBoundingClientRect()
								if (r.width > 0 && r.height > 0 && r.bottom <= rect.bottom) {
									bottom = Math.max(bottom, r.bottom)
								}
							}
						}
						rects.push({ left: rect.left, top: rect.top, right: rect.right, bottom })
					}
				}
				if (rects.length === 0) {
					return null
				}
				const box = {
					x: Math.min(...rects.map((r) => r.left)) - pad,
					y: Math.min(...rects.map((r) => r.top)) - pad,
					right: Math.max(...rects.map((r) => r.right)) + pad,
					bottom: Math.max(...rects.map((r) => r.bottom)) + pad,
				}
				// Inside Nextcloud's content area: stay off its edge and rounded corners
				const content = document.querySelector('#content-vue, #content')
				if (content && content.contains(first) && !first.closest('.modal-container')) {
					const c = content.getBoundingClientRect()
					box.x = Math.max(box.x, c.left)
					box.y = Math.max(box.y, c.top)
					box.right = Math.min(box.right, c.right - 12)
					box.bottom = Math.min(box.bottom, c.bottom - 12)
				}
				return box
			}, [].concat(selectors), fit, pad)
			if (!box) {
				throw new Error(`Nothing to crop: ${selectors}`)
			}
			const x = Math.max(0, Math.floor(box.x))
			const y = Math.max(0, Math.floor(box.y))
			const width = Math.max(minWidth, Math.min(WIDTH, Math.ceil(box.right)) - x)
			const height = Math.min(maxHeight, Math.min(HEIGHT, Math.ceil(box.bottom)) - y)
			return { x, y, width: Math.min(width, WIDTH - x), height }
		},
	}
	return h
}

/**
 * Write a PNG; with sharp it is reduced to a palette of 256 colours, which
 * keeps screenshots of user interfaces sharp at a fraction of the size.
 */
async function writePng(file, buffer) {
	mkdirSync(dirname(file), { recursive: true })
	if (args['no-optimize']) {
		writeFileSync(file, buffer)
		return buffer.length
	}
	const { default: sharp } = await import('sharp')
	const output = await sharp(buffer).png({ palette: true, colours: 256, quality: 90, effort: 10, compressionLevel: 9, dither: 0.6 }).toBuffer()
	writeFileSync(file, output)
	return output.length
}

/**
 * One browser per language: date fields and other controls of the browser
 * follow its own locale (--lang, on Linux the environment variable LANG).
 */
const browsers = new Map()
async function browserFor(lang) {
	if (!browsers.has(lang)) {
		const locale = lang === 'de' ? 'de-DE' : 'en-GB'
		browsers.set(lang, await puppeteer.launch({
			executablePath: await browserPath(),
			headless: true,
			args: [`--lang=${locale}`, '--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none', '--no-first-run'],
			env: { ...process.env, LANG: `${locale.replace('-', '_')}.UTF-8` },
			defaultViewport: { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1 },
		}))
	}
	return browsers.get(lang)
}
const sessions = new Map()
async function session(role, lang = 'en') {
	const key = `${role} ${lang}`
	if (!sessions.has(key)) {
		sessions.set(key, await new Session(await browserFor(lang), role).open())
	}
	return sessions.get(key)
}

let failures = 0
try {
	if (args.prepare) {
		await prepare({ session, sleep, demoFolder: args['demo-folder'], log: (...text) => console.log('prepare:', ...text) })
	}
	const only = args.only ? args.only.split(',').map((name) => name.trim()) : null
	// session(role) lets a recipe ask another account, e.g. for the token of a link
	const state = { demoFolder: args['demo-folder'], session }
	for (const shot of SHOTS) {
		if (only && !only.includes(shot.name) && !only.includes(shot.file)) {
			continue
		}
		const s = await session(shot.account, shot.lang)
		await s.use(shot)
		const h = helpers(s)
		try {
			const clip = await shot.run(h, state)
			if (!h.keepOpen) {
				await h.calm()
			}
			const buffer = await s.page.screenshot({ type: 'png', captureBeyondViewport: false, ...(clip ? { clip } : {}) })
			const file = resolve(args.out, shot.file)
			const size = await writePng(file, buffer)
			console.log(`${shot.file}: ${clip ? `${clip.width} x ${clip.height}` : `${WIDTH} x ${HEIGHT}`}, ${Math.round(size / 1024)} KB`)
		} catch (error) {
			failures++
			console.error(`${shot.file}: FAILED ${error.message}`)
		}
		if (shot.after) {
			await shot.after(h, state).catch(() => {})
		}
	}
} finally {
	// Back to English, the language the accounts started with
	for (const s of sessions.values()) {
		await s.use({ lang: 'en', theme: 'light' }).catch(() => {})
	}
	for (const browser of browsers.values()) {
		await browser.close()
	}
}
process.exit(failures > 0 ? 1 : 0)
