#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Builds outreach material for the Cantoria app with the sender of a
// choir: a flyer (DIN lang), a business card and two banners.
//
//   node build.mjs [--sender file] [--lang de] [--only a,b] [--out dir]
//                  [--bleed 3] [--browser path] [--html-only]
//
// The texts come from content.json; name, logo, contact, colours and fonts of
// the choir come from the sender file (default: sender.json in this folder,
// otherwise example/sender.json). The HTML files need nothing else; the PDFs
// and pictures are made by a local Chrome, Chromium or Edge through
// puppeteer-core. See README.md in this folder.
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, extname, isAbsolute, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'

const HERE = dirname(fileURLToPath(import.meta.url))
const EXAMPLE_SENDER = join(HERE, 'example', 'sender.json')
const ISSUES = 'https://github.com/thoscut/Cantoria/issues'

/** The products: kind and final size, in mm for print and in px for screen. */
const PRODUCTS = {
	'flyer-dl': { kind: 'print', width: 99, height: 210 },
	'business-card': { kind: 'print', width: 85, height: 55 },
	'banner-social': { kind: 'screen', width: 1280, height: 640 },
	'banner-web': { kind: 'screen', width: 1500, height: 500 },
}

/** Resolution of the preview pictures of the print products. */
const PREVIEW_DPI = 150

/** Fields of a sender file. */
const SENDER_FIELDS = ['name', 'tagline', 'logo', 'logo_on_dark', 'web', 'mail', 'phone', 'colors', 'fonts']

/** Text colour on background colour, as the templates combine them. */
const TEXT_ON = [['ink', 'paper'], ['muted', 'paper'], ['accent', 'paper'], ['paper', 'accent'], ['light', 'accent'], ['paper', 'deep'], ['light', 'deep']]

/** WCAG 2.2 AA: contrast of normal text. */
const MIN_CONTRAST = 4.5

const IMAGE_TYPES = { '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp' }

const HELP = `Builds a flyer, a business card and two banners for the Cantoria app.

  node build.mjs [options]

  --sender <file>   sender file of your choir (default: sender.json in this
                    folder if it exists, otherwise example/sender.json)
  --lang <code>     language of the texts in content.json (default: de)
  --only <a,b>      only these products: ${Object.keys(PRODUCTS).join(', ')}
  --out <dir>       output folder (default: out); files go to <dir>/<lang>/
  --bleed <mm>      bleed of the print PDFs (default: 3; 0 = no print PDFs)
  --browser <path>  Chrome, Chromium or Edge (or the variable BROWSER_PATH)
  --html-only       write the HTML files only, without a browser
  --help            this text

See README.md in this folder.`

/** An error the user can fix; its message says how. */
class UserError extends Error {}

let warnings = 0

function warn(message) {
	warnings++
	console.warn(`Warning: ${message}`)
}

/** A path for messages: relative to the current folder when that is shorter. */
function show(file) {
	const rel = relative(process.cwd(), file)
	return rel && !rel.startsWith('..') && !isAbsolute(rel) ? rel : file
}

function escapeHtml(value) {
	return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
}

function readJson(file, what) {
	let text
	try {
		text = readFileSync(file, 'utf8')
	} catch (error) {
		throw new UserError(`Cannot read the ${what} ${show(file)} (${error.code ?? error.message}). Check the path.`)
	}
	try {
		return JSON.parse(text.replace(/^\uFEFF/, ''))
	} catch (error) {
		throw new UserError(`${show(file)} is not valid JSON: ${error.message}. Check the quotes and commas near that place; a missing or an extra comma is the usual cause.`)
	}
}

/**
 * Fills a template. {{a.b}} inserts a value as escaped text, {{{a.b}}} as it
 * is (HTML, CSS). {{#each a.b}}…{{/each}} repeats a block for every item of a
 * list (blocks do not nest); inside it, {{.}} is the item, {{#}} its number
 * from 1, and names are looked up on the item first.
 *
 * @param {string} template text of the template
 * @param {object} data values
 * @param {string} name file name of the template, for messages
 */
function fill(template, data, name) {
	const lookup = (path, scope) => {
		let value = path === '.' || path === '#' ? scope[path] : scope
		if (path !== '.' && path !== '#') {
			for (const key of path.split('.')) {
				value = value?.[key]
			}
		}
		if (value === undefined || value === null) {
			const hint = path.startsWith('t.')
				? `Add "${path.slice(2)}" to the texts of this language in content.json`
				: 'Correct the name in the template'
			throw new UserError(`The template ${name} uses {{${path}}}, but there is no such value. ${hint}.`)
		}
		return value
	}
	const inline = (text, scope) => text.replace(/\{\{\{\s*([\w.#]+)\s*\}\}\}|\{\{\s*([\w.#]+)\s*\}\}/g,
		(match, raw, escaped) => (raw ? String(lookup(raw, scope)) : escapeHtml(lookup(escaped, scope))))
	let result = ''
	let last = 0
	for (const match of template.matchAll(/\{\{#each\s+([\w.]+)\s*\}\}([\s\S]*?)\{\{\/each\}\}/g)) {
		const [whole, path, block] = match
		const list = lookup(path, data)
		if (!Array.isArray(list)) {
			throw new UserError(`The template ${name} repeats {{#each ${path}}}, but that value is not a list. Write it as a list in square brackets.`)
		}
		result += inline(template.slice(last, match.index), data)
		result += list.map((item, index) => inline(block, { ...data, ...(typeof item === 'object' ? item : {}), '.': item, '#': index + 1 })).join('')
		last = match.index + whole.length
	}
	return result + inline(template.slice(last), data)
}

/** Relative luminance of a colour #rrggbb (WCAG 2.2). */
function luminance(hex) {
	const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
		.map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a, b) {
	const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x)
	return (light + 0.05) / (dark + 0.05)
}

/** A logo as data URI, so that the HTML files stand alone. */
function imageUri(path, key, file) {
	if (!path) {
		return ''
	}
	const full = resolve(dirname(file), path)
	const type = IMAGE_TYPES[extname(full).toLowerCase()]
	if (!type) {
		throw new UserError(`"${key}" in ${show(file)} is "${path}". Use an SVG, PNG, JPEG or WebP file.`)
	}
	if (!existsSync(full)) {
		throw new UserError(`The logo ${show(full)} ("${key}" in ${show(file)}) does not exist. Paths are relative to the sender file: put the logo there, correct the path, or write "${key}": "" for no logo.`)
	}
	return `data:${type};base64,${readFileSync(full).toString('base64')}`
}

/** Reads and checks a sender file; colours and fonts it leaves out come from the example. */
function loadSender(file) {
	const raw = readJson(file, 'sender file')
	if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
		throw new UserError(`${show(file)} must hold one object in curly braces, like example/sender.json.`)
	}
	const example = file === EXAMPLE_SENDER ? raw : readJson(EXAMPLE_SENDER, 'example sender file')
	for (const key of Object.keys(raw)) {
		if (!SENDER_FIELDS.includes(key)) {
			warn(`the field "${key}" in ${show(file)} is unknown and ignored. Known fields: ${SENDER_FIELDS.join(', ')}.`)
		}
	}
	const text = (key) => {
		const value = raw[key] ?? ''
		if (typeof value !== 'string') {
			throw new UserError(`"${key}" in ${show(file)} must be text in quotes, e.g. "${key}": "...".`)
		}
		return value.trim()
	}
	const group = (key) => {
		const value = raw[key] ?? {}
		if (typeof value !== 'object' || value === null || Array.isArray(value)) {
			throw new UserError(`"${key}" in ${show(file)} must be an object in curly braces, as in example/sender.json.`)
		}
		for (const name of Object.keys(value)) {
			if (!(name in example[key])) {
				warn(`"${key}.${name}" in ${show(file)} is unknown and ignored. Known: ${Object.keys(example[key]).join(', ')}.`)
			}
		}
		return { ...example[key], ...value }
	}

	// "e. V." (registered association) stays on one line
	const name = text('name').replace(/\be\. V\./g, 'e. V.')
	if (!name) {
		throw new UserError(`"name" is missing in ${show(file)}. Enter the name of your choir, e.g. "name": "Chor Musterstadt".`)
	}
	const colors = group('colors')
	for (const [key, value] of Object.entries(colors)) {
		if (typeof value !== 'string' || !/^#[0-9a-f]{6}$/i.test(value)) {
			throw new UserError(`"colors.${key}" in ${show(file)} is ${JSON.stringify(value)}. Write it as a colour with six hex digits, e.g. "#1d5c58".`)
		}
	}
	const fonts = group('fonts')
	for (const [key, value] of Object.entries(fonts)) {
		if (typeof value !== 'string' || !/^[\p{L}\p{N} "',.-]+$/u.test(value)) {
			throw new UserError(`"fonts.${key}" in ${show(file)} must be a list of font names, e.g. "Georgia, serif"; quote names with spaces as \\"Noto Serif\\".`)
		}
	}
	const tagline = text('tagline')
	const contact = [text('web'), text('mail'), text('phone')].filter(Boolean)
	return {
		name,
		lines: [name, tagline].filter(Boolean),
		contact,
		logo: imageUri(text('logo'), 'logo', file),
		logoOnDark: imageUri(text('logo_on_dark'), 'logo_on_dark', file),
		colors,
		fonts,
	}
}

/** Warns about colour pairs of the templates whose text would be hard to read. */
function checkContrast(colors, file) {
	for (const [text, ground] of TEXT_ON) {
		const ratio = contrast(colors[text], colors[ground])
		if (ratio < MIN_CONTRAST) {
			warn(`text in "${text}" (${colors[text]}) on "${ground}" (${colors[ground]}) has a contrast of ${ratio.toFixed(1)}:1; WCAG 2.2 AA needs ${MIN_CONTRAST}:1. Change one of the two colours in ${show(file)}.`)
		}
	}
}

/** The app icon (img/app-dark.svg of the app), coloured by the text around it. */
function appIcon() {
	const file = join(HERE, '..', '..', 'img', 'app-dark.svg')
	const path = existsSync(file) ? /\sd="([^"]+)"/.exec(readFileSync(file, 'utf8'))?.[1] : null
	return path ? `<svg class="app-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="${path}"/></svg>` : ''
}

/** A QR code as SVG: error correction M, quiet zone of four modules, always on white. */
function qrSvg(qrcode, url, color, label) {
	const qr = qrcode(0, 'M')
	qr.addData(url)
	qr.make()
	const count = qr.getModuleCount()
	const size = count + 8
	let path = ''
	for (let row = 0; row < count; row++) {
		for (let col = 0; col < count; col++) {
			if (qr.isDark(row, col)) {
				let run = 1
				while (col + run < count && qr.isDark(row, col + run)) {
					run++
				}
				path += `M${col + 4} ${row + 4}h${run}v1h-${run}z`
				col += run - 1
			}
		}
	}
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" role="img" aria-label="${escapeHtml(label)}" shape-rendering="crispEdges">`
		+ `<rect width="${size}" height="${size}" fill="#fff"/><path fill="${color}" d="${path}"/></svg>`
}

/** Values for the templates of one product; bleed in mm (print products only). */
function templateData({ product, lang, texts, project, sender, bleed, qr, icon }) {
	const unit = product.kind === 'print' ? 'mm' : 'px'
	const extra = product.kind === 'print' ? bleed : 0
	const alt = escapeHtml(`${texts.logo_label} ${sender.name}`)
	const img = (uri) => `<img src="${uri}" alt="${alt}">`
	let logoOnDark = ''
	if (sender.logoOnDark) {
		logoOnDark = img(sender.logoOnDark)
	} else if (sender.logo) {
		logoOnDark = `<span class="plate">${img(sender.logo)}</span>`
	}
	return {
		lang,
		t: texts,
		project,
		sender: { name: sender.name, lines: sender.lines, contact: sender.contact },
		colors: sender.colors,
		fonts: sender.fonts,
		page: { width: `${product.width + 2 * extra}${unit}`, height: `${product.height + 2 * extra}${unit}` },
		trim: { width: `${product.width}${unit}`, height: `${product.height}${unit}` },
		bleed: `${extra}${unit}`,
		logo_img: sender.logo ? img(sender.logo) : '',
		logo_on_dark_img: logoOnDark,
		qr_svg: qr,
		app_icon: icon,
		url_html: escapeHtml(project.url_short).replaceAll('/', '/<wbr>'),
	}
}

/** First Chromium-based browser found in the usual places, or null. */
function findBrowser() {
	const local = process.env.LOCALAPPDATA
	const candidates = [
		'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
		local && join(local, 'Google', 'Chrome', 'Application', 'chrome.exe'),
		'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
		'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
		'C:\\Program Files\\Chromium\\Application\\chrome.exe',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
		'/usr/bin/google-chrome',
		'/usr/bin/google-chrome-stable',
		'/usr/bin/microsoft-edge',
		'/snap/bin/chromium',
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		'/Applications/Chromium.app/Contents/MacOS/Chromium',
		'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
	]
	return candidates.find((candidate) => candidate && existsSync(candidate)) ?? null
}

/** Warns when the content of a page runs past its edge (too much text). */
async function checkFit(page, name, unit) {
	const overflow = await page.evaluate(() => [...document.querySelectorAll('.trim')].map((trim) => {
		const box = trim.getBoundingClientRect()
		let bottom = box.bottom
		let right = box.right
		for (const element of trim.querySelectorAll('*')) {
			const rect = element.getBoundingClientRect()
			if (rect.width > 0 && rect.height > 0) {
				bottom = Math.max(bottom, rect.bottom)
				right = Math.max(right, rect.right)
			}
		}
		return Math.max(bottom - box.bottom, right - box.right)
	}))
	overflow.forEach((px, index) => {
		if (px > 0.5) {
			const amount = unit === 'mm' ? `${(px * 25.4 / 96).toFixed(1)} mm` : `${Math.ceil(px)} px`
			warn(`${name}, page ${index + 1}: the content runs ${amount} past the edge and is cut off. Shorten the texts in content.json or the name, tagline or contact in the sender file.`)
		}
	})
}

/**
 * Saves a picture of a page. Pages in mm do not end on whole pixels, so the
 * ground around the page takes its colour and no grey edge shows.
 */
async function picture(element, file) {
	await element.evaluate((page) => {
		page.style.boxShadow = 'none'
		document.documentElement.style.background = getComputedStyle(page).backgroundColor
	})
	await element.screenshot({ path: file })
}

/** Prints the PDFs and takes the pictures of all products. */
async function render(puppeteer, executablePath, jobs, out, written) {
	let browser
	try {
		browser = await puppeteer.launch({
			executablePath,
			headless: true,
			args: ['--no-first-run', '--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none'],
		})
	} catch (error) {
		throw new UserError(`The browser ${executablePath} did not start (${error.message.split('\n')[0]}). Update it, or give another Chrome, Chromium or Edge with --browser.`)
	}
	try {
		const page = await browser.newPage()
		const pdf = async (file, count) => {
			await page.pdf({ path: file, printBackground: true, preferCSSPageSize: true, tagged: true, pageRanges: `1-${count}` })
			written.push(file)
		}
		for (const { name, product, html, printHtml } of jobs) {
			const print = product.kind === 'print'
			await page.setViewport(print
				? { width: 1200, height: 2000, deviceScaleFactor: PREVIEW_DPI / 96 }
				: { width: product.width + 48, height: product.height + 48, deviceScaleFactor: 1 })
			await page.setContent(html, { waitUntil: 'load' })
			await page.evaluate(() => document.fonts.ready)
			await checkFit(page, name, print ? 'mm' : 'px')
			const pages = await page.$$('.page')
			for (const [index, element] of pages.entries()) {
				const file = join(out, pages.length > 1 ? `${name}-${index + 1}.png` : `${name}.png`)
				await picture(element, file)
				written.push(file)
			}
			if (print) {
				await pdf(join(out, `${name}.pdf`), pages.length)
				if (printHtml) {
					await page.setContent(printHtml, { waitUntil: 'load' })
					await page.evaluate(() => document.fonts.ready)
					await pdf(join(out, `${name}-print.pdf`), pages.length)
				}
			}
		}
	} finally {
		await browser.close()
	}
}

async function main() {
	let args
	try {
		args = parseArgs({
			options: {
				sender: { type: 'string' },
				lang: { type: 'string', default: 'de' },
				only: { type: 'string' },
				out: { type: 'string', default: join(HERE, 'out') },
				bleed: { type: 'string', default: '3' },
				browser: { type: 'string', default: process.env.BROWSER_PATH },
				'html-only': { type: 'boolean', default: false },
				help: { type: 'boolean', short: 'h', default: false },
			},
		}).values
	} catch (error) {
		throw new UserError(`${error.message.replace(/\.?$/, '.')} Run "node build.mjs --help" for the options.`)
	}
	if (args.help) {
		console.log(HELP)
		return 0
	}

	const names = args.only ? args.only.split(',').map((name) => name.trim()).filter(Boolean) : Object.keys(PRODUCTS)
	for (const name of names) {
		if (!PRODUCTS[name]) {
			throw new UserError(`There is no product "${name}". Choose from: ${Object.keys(PRODUCTS).join(', ')}.`)
		}
	}
	const bleed = Number(args.bleed)
	if (!Number.isFinite(bleed) || bleed < 0 || bleed > 10) {
		throw new UserError(`--bleed takes millimetres from 0 to 10, e.g. --bleed 3. Your print shop says how much it needs.`)
	}

	const content = readJson(join(HERE, 'content.json'), 'texts')
	const texts = content.texts?.[args.lang]
	if (!texts) {
		throw new UserError(`content.json has no texts for the language "${args.lang}". Choose one of: ${Object.keys(content.texts ?? {}).join(', ')}, or add a block "${args.lang}" under "texts".`)
	}
	const senderFile = args.sender ? resolve(args.sender) : (existsSync(join(HERE, 'sender.json')) ? join(HERE, 'sender.json') : EXAMPLE_SENDER)
	if (!existsSync(senderFile)) {
		throw new UserError(`The sender file ${show(senderFile)} does not exist. Check the path after --sender.`)
	}
	console.log(`Sender: ${show(senderFile)}${senderFile === EXAMPLE_SENDER ? ' (the invented example; copy it to sender.json for your own)' : ''}`)
	const sender = loadSender(senderFile)
	checkContrast(sender.colors, senderFile)

	let qrcode
	try {
		qrcode = (await import('qrcode-generator')).default
	} catch (error) {
		if (error.code !== 'ERR_MODULE_NOT_FOUND') {
			throw error
		}
		throw new UserError('The packages of this tool are not installed. Run "npm ci" in tools/outreach once (needs network access), then run the build again.')
	}
	const qr = qrSvg(qrcode, content.project.url, sender.colors.ink, `${texts.qr_label} ${content.project.url_short}`)
	const icon = appIcon()
	const baseCss = readFileSync(join(HERE, 'templates', 'base.css'), 'utf8')

	const out = resolve(args.out, args.lang)
	try {
		mkdirSync(out, { recursive: true })
	} catch (error) {
		throw new UserError(`Cannot create the output folder ${show(out)} (${error.code ?? error.message}). Choose another one with --out.`)
	}
	const written = []
	const jobs = []
	for (const name of names) {
		const product = PRODUCTS[name]
		const template = readFileSync(join(HERE, 'templates', `${name}.html`), 'utf8')
		const build = (withBleed) => {
			const data = templateData({ product, lang: args.lang, texts, project: content.project, sender, bleed: withBleed, qr, icon })
			data.base_css = fill(baseCss, data, 'base.css')
			return fill(template, data, `${name}.html`)
		}
		const html = build(0)
		const file = join(out, `${name}.html`)
		writeFileSync(file, html)
		written.push(file)
		jobs.push({ name, product, html, printHtml: product.kind === 'print' && bleed > 0 ? build(bleed) : null })
	}

	const report = () => {
		for (const file of written) {
			console.log(`  ${show(file)}`)
		}
		if (warnings > 0) {
			console.log(`${warnings} warning(s) above: please read them before printing.`)
		}
	}
	if (args['html-only']) {
		report()
		return 0
	}
	let puppeteer
	try {
		puppeteer = (await import('puppeteer-core')).default
	} catch (error) {
		if (error.code !== 'ERR_MODULE_NOT_FOUND') {
			throw error
		}
		report()
		console.error(`Only the HTML files were written: puppeteer-core is not installed. Run "npm ci" in tools/outreach, then run the build again for the PDFs and pictures.`)
		return 1
	}
	const browser = args.browser || findBrowser()
	if (!browser || !existsSync(browser)) {
		report()
		console.error([
			browser
				? `Only the HTML files were written: there is no browser at ${browser}.`
				: 'Only the HTML files were written: no Chrome, Chromium or Edge was found for the PDFs and pictures.',
			'Install Chrome, Chromium or Edge, or give the full path of the browser and run the build again, for example:',
			'  node build.mjs --browser "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"',
			'  node build.mjs --browser /usr/bin/chromium',
			'The environment variable BROWSER_PATH works as well.',
		].join('\n'))
		return 1
	}
	console.log(`Browser: ${browser}`)
	await render(puppeteer, browser, jobs, out, written)
	report()
	console.log('Before printing: scan the QR code with a phone and read every text once.')
	return 0
}

try {
	process.exitCode = await main()
} catch (error) {
	if (error instanceof UserError) {
		console.error(`Error: ${error.message}`)
	} else {
		console.error(error)
		console.error(`Unexpected error. Please report it with the output above at ${ISSUES}.`)
	}
	process.exitCode = 1
}
