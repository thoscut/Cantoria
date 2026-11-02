/**
 * The screenshots of the documentation: file, account, language, theme and
 * the steps that lead to the picture. A step function returns a clip
 * rectangle for a detail picture, or nothing for the whole window
 * (1600 x 1000). Texts of the app are given as English source strings;
 * h.t() turns them into the session's language.
 */

const CONCERT = 'Adventskonzert 2026'

/** Open the sidebar of a copy on one of its tabs. */
async function sidebar(h, shelfmark, tab = 'overview') {
	await h.app(`?view=catalog&copy=${await h.copyId(shelfmark)}`)
	await h.page.waitForSelector('.app-sidebar', { visible: true })
	if (tab !== 'overview') {
		await h.page.click(`#tab-button-${tab}`)
	}
	await h.settle(1200)
}

/** Open the score viewer of a copy and choose the focus voice. */
async function score(h, shelfmark, voice = null, mode = null, measures = null) {
	await sidebar(h, shelfmark)
	await h.click('.app-sidebar button', h.t('Show score of {shelfmark}').replace('{shelfmark}', shelfmark))
	await h.page.waitForSelector('.score-viewer__page svg', { timeout: 60000 })
	await h.settle(1000)
	if (measures) {
		await h.fill(h.t('From measure'), measures[0], '.score-viewer')
		await h.fill(h.t('To measure'), measures[1], '.score-viewer')
		await h.click('.score-viewer button', h.t('Show'))
		await h.page.waitForSelector('.score-viewer__page svg', { timeout: 60000 })
		await h.settle(800)
	}
	if (voice) {
		await h.select(h.t('Focus voice'), voice)
	}
	if (mode) {
		// "all", "focus" (focus voice louder) or "solo"
		await h.page.evaluate((mode) => document.querySelector(`.score-viewer input[type=radio][value="${mode}"]`)?.click(), mode)
	}
	await h.settle(1200)
}

/** Focus voice, sharing and the first system of the score. */
const scoreClip = async (h) => {
	// The actions for the passage (text reference, link, task) open on request
	const closed = await h.page.evaluate(() => !!document.querySelector('.score-viewer__place') && getComputedStyle(document.querySelector('.score-viewer__place')).display === 'none')
	if (closed) {
		await h.click('.score-viewer button', h.t('This passage'))
		await h.settle(400)
	}
	const clip = await h.rect(['.score-viewer__voice', '.score-viewer__place', '.score-viewer__summary', '.score-viewer__page svg'], { pad: 12, maxHeight: 470 })
	return { ...clip, width: Math.min(clip.width, 960) }
}

/** The visible part of the sidebar. */
const sidebarClip = (h, maxHeight = 1000) => h.rect('.app-sidebar', { pad: 0, maxHeight, fit: true })

/** The open modal dialog. */
const dialogClip = async (h) => {
	// The dialog on top: the last one that is visible
	await h.page.evaluate(() => {
		const dialogs = [...document.querySelectorAll('.modal-container')].filter((d) => d.getClientRects().length > 0)
		dialogs.forEach((d) => d.removeAttribute('data-screenshot'))
		dialogs.pop()?.setAttribute('data-screenshot', 'dialog')
	})
	return h.rect('[data-screenshot=dialog]', { pad: 0 })
}

/** Close a dialog without saving. */
const escape = async (h) => {
	await h.page.keyboard.press('Escape')
	await h.sleep(500)
}

/** Open the archive management dialog. */
async function archiveManagement(h) {
	await h.app('?view=catalog')
	await h.click('.app-navigation a, .app-navigation button', h.t('Archive management'))
	await h.page.waitForSelector('.modal-container', { visible: true })
	await h.settle(800)
}

const en = { lang: 'en', theme: 'light' }
const de = { lang: 'de', theme: 'light' }

export const SHOTS = [
	// ---- full window, also the app store screenshots ----
	{
		name: 'catalog', file: 'catalog.png', account: 'librarian', ...en,
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'catalog-dark', file: 'catalog-dark.png', account: 'librarian', lang: 'en', theme: 'dark',
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'copy-rights', file: 'copy-rights.png', account: 'librarian', ...en,
		run: async (h) => sidebar(h, '2-1', 'rights'),
	},
	{
		name: 'score-voice-focus', file: 'score-voice-focus.png', account: 'librarian', ...en,
		run: async (h) => score(h, '2-1', 'Tenor II', 'focus', ['1', '8']),
	},
	{
		name: 'practice-audio', file: 'practice-audio.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
		},
		after: escape,
	},
	{
		name: 'start-page-due', file: 'start-page-due.png', account: 'librarian', ...en,
		run: async (h) => h.app(''),
	},
	{
		name: 'concert-planning', file: 'concert-planning.png', account: 'librarian', ...en,
		run: async (h) => h.app(`?event=${await h.eventId(CONCERT)}`),
	},
	{
		name: 'statistics', file: 'statistics.png', account: 'librarian', ...en,
		run: async (h) => h.app('?view=statistics'),
	},
	{
		name: 'dashboard-widget', file: 'dashboard-widget.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.goto('/apps/dashboard/')
			await h.page.waitForFunction(() => document.body.innerText.includes('Cantoria'), { timeout: 30000 })
			await h.settle(1500)
		},
	},
	{
		name: 'score-dark', file: 'score-dark.png', account: 'librarian', lang: 'en', theme: 'dark',
		run: async (h) => score(h, '2-1', 'Tenor II', 'focus', ['1', '8']),
	},

	// ---- details ----
	{
		name: 'copy-overview', file: 'copy-overview.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '1-1')
			return sidebarClip(h, 600)
		},
	},
	{
		name: 'rights-panel', file: 'rights-panel.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '2-1', 'rights')
			return sidebarClip(h, 800)
		},
	},
	{
		name: 'score-excerpt', file: 'score-excerpt.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			return scoreClip(h)
		},
	},
	{
		name: 'practice-audio-dialog', file: 'practice-audio-dialog.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'task-question', file: 'task-question.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '2-1', 'tasks')
			return sidebarClip(h, 620)
		},
	},
	{
		name: 'concert-warnings', file: 'concert-warnings.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			return h.rect(['.event-detail__summary', '.event-detail__items'], { pad: 12 })
		},
	},
	{
		name: 'accession-form', file: 'accession-form.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=accession')
			await h.select(h.t('Binder (required)'), '4')
			return h.rect('section[aria-labelledby=accession-form]', { pad: 12 })
		},
	},
	{
		name: 'notifications', file: 'notifications.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=catalog')
			await h.click('header button.header-menu__trigger', ['Notifications', 'Benachrichtigungen'])
			await h.page.waitForSelector('.notification-dismiss-button', { visible: true, timeout: 15000 })
			await h.settle(800)
			// Mark the open menu of the notifications app to crop it
			await h.page.evaluate(() => {
				const button = [...document.querySelectorAll('.notification-dismiss-button')].find((b) => b.getClientRects().length > 0)
				button?.closest('.header-menu__wrapper, .header-menu__content, [role=dialog]')?.setAttribute('data-screenshot', 'notifications')
			})
			return h.rect('[data-screenshot=notifications]', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'backup-restore', file: 'backup-restore.png', account: 'librarian', ...en,
		run: async (h) => {
			await archiveManagement(h)
			await h.page.evaluate(() => document.querySelector('#archive-backup')?.scrollIntoView({ block: 'start' }))
			await h.settle(500)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'import-report', file: 'import-report.png', account: 'librarian', ...en,
		run: async (h, state) => {
			await archiveManagement(h)
			await h.click('.modal-container button', h.t('Choose CSV file …'))
			await pickFile(h, [state.demoFolder, '_Import', 'catalog.csv'])
			await h.page.waitForSelector('.import-report', { visible: true, timeout: 30000 })
			await h.settle(800)
			await h.page.evaluate(() => document.querySelector('#archive-import')?.scrollIntoView({ block: 'start' }))
			await h.settle(400)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'file-sync-report', file: 'file-sync-report.png', account: 'librarian', ...en,
		run: async (h) => {
			await archiveManagement(h)
			await h.click('.modal-container button', h.t('Run file sync'))
			await h.page.waitForSelector('.file-sync-report', { visible: true, timeout: 60000 })
			await h.page.evaluate(() => document.querySelector('#archive-file-sync')?.scrollIntoView({ block: 'start' }))
			await h.settle(600)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		// Administration → Cantoria: the roles and their groups
		name: 'settings-roles', file: 'settings-roles.png', account: 'admin', ...en,
		run: async (h) => {
			await h.goto('/settings/admin/cantoria')
			await h.page.waitForSelector('#cantoria-roles', { visible: true, timeout: 30000 })
			await h.page.evaluate(() => document.querySelector('#cantoria-roles')?.scrollIntoView({ block: 'start' }))
			await h.settle(1200)
			return h.rect('#cantoria-roles', { pad: 0 })
		},
	},
	{
		// The access check of the archive folder, opened like the link of the app does
		name: 'admin-settings', file: 'admin-settings.png', account: 'admin', ...en,
		run: async (h) => {
			await h.goto('/settings/admin/cantoria#cantoria-access')
			await h.page.waitForSelector('#cantoria-access button', { visible: true, timeout: 30000 })
			if (!(await h.page.$('#cantoria-access .access-check__table'))) {
				await h.click('#cantoria-access button', h.t('Check access'))
			}
			await h.page.waitForSelector('#cantoria-access .access-check__table', { visible: true, timeout: 60000 })
			await h.page.evaluate(() => document.querySelector('#cantoria-access')?.scrollIntoView({ block: 'start' }))
			await h.settle(1200)
			return h.rect('#cantoria-access', { pad: 0 })
		},
	},
	{
		name: 'protection-terms', file: 'protection-terms.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=protection-review')
			return h.rect('#app-content-vue', { pad: 0, fit: true })
		},
	},
	{
		name: 'merge-proposals', file: 'merge-proposals.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=merges')
			return h.rect('#app-content-vue', { pad: 0, maxHeight: 885 })
		},
	},
	{
		name: 'annual-report', file: 'annual-report.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=statistics')
			await h.page.evaluate(() => document.querySelector('.annual-report')?.scrollIntoView({ block: 'start' }))
			await h.settle(600)
			return h.rect('.annual-report', { pad: 12 })
		},
	},
	{
		name: 'calendar-dialog', file: 'calendar-dialog.png', account: 'conductor', ...en,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			await h.page.click('#event-detail-add-to-calendar')
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(1200)
			return dialogClip(h)
		},
		after: escape,
	},

	// ---- milestones 0.7 to 0.9 ----
	{
		// The review point at measure 7, Tenor II, of the demo report of 2-1
		name: 'review-points', file: 'review-points.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app(`?copy=${await h.copyId('2-1')}&review=1`)
			await h.page.waitForSelector('.review-panel__point', { visible: true, timeout: 60000 })
			await h.page.waitForSelector('.score-viewer__page svg', { timeout: 60000 })
			await h.page.evaluate(() => {
				const points = [...document.querySelectorAll('.review-panel__point')]
				const point = points.find((p) => /\b7\b/.test(p.querySelector('.review-panel__place')?.innerText ?? '')) ?? points[0]
				point?.click()
			})
			await h.settle(1500)
			// The chosen point with its decisions at the end of the panel
			await h.page.evaluate(() => document.querySelector('.review-panel')?.lastElementChild?.scrollIntoView({ block: 'end' }))
			await h.settle(600)
		},
	},
	{
		name: 'files-action', file: 'files-action.png', account: 'librarian', ...en,
		run: async (h, state) => {
			await h.goto(`/apps/files/files?dir=/${encodeURIComponent(state.demoFolder)}/Mappe_2`)
			const row = 'tr[data-cy-files-list-row-name="2-01_Aennchen-von-Tharau_Silcher.mxl"]'
			await h.page.waitForSelector(row, { visible: true, timeout: 30000 })
			await h.settle(800)
			await h.page.click(`${row} .files-list__row-actions button[aria-label="${h.session.lang === 'de' ? 'Aktionen' : 'Actions'}"]`)
			await h.page.waitForFunction((label) => [...document.querySelectorAll('[role=menuitem]')].some((item) => item.innerText.includes(label)), { timeout: 15000 }, h.t('Open in Cantoria'))
			await h.sleep(600)
			// Keep the menu open: no mouse move, no blur afterwards
			h.keepOpen = true
		},
		after: escape,
	},
	{
		name: 'share-dialog', file: 'share-dialog.png', account: 'librarian', ...en,
		run: async (h) => {
			// The whole piece with the scan (PDF), for choir members
			await score(h, '1-1', 'Alt', 'focus')
			const label = h.t('Share …')
			if (!(await h.page.evaluate((label) => [...document.querySelectorAll('.score-viewer button')].some((b) => b.getClientRects().length > 0 && b.innerText.includes(label)), label))) {
				await h.click('.score-viewer button', h.t('This passage'))
			}
			await h.click('.score-viewer button', label)
			await h.page.waitForSelector('.dialog__modal .modal-container', { visible: true })
			await h.settle(600)
			const choose = (label) => h.page.evaluate((label) => [...document.querySelectorAll('.dialog__modal .checkbox-radio-switch')]
				.find((choice) => choice.innerText.trim() === label)?.querySelector('input')?.click(), label)
			await choose(h.t('Choir members'))
			await h.settle(1500)
			await choose(h.t('Whole piece'))
			await choose(h.t('Include the scan (PDF)'))
			await h.settle(1000)
		},
		after: async (h) => {
			await escape(h)
			await escape(h)
		},
	},
	{
		name: 'shared-with-me', file: 'shared-with-me.png', account: 'member', ...en,
		run: async (h) => {
			await h.goto('/apps/cantoria/')
			await h.page.waitForSelector('.excerpt-viewer svg', { timeout: 60000 })
			await h.settle(1500)
		},
	},
	{
		name: 'public-link', file: 'public-link.png', account: 'public', ...en,
		run: async (h, state) => {
			const librarian = await state.session('librarian')
			const link = ((await librarian.api('GET', '/api/links')).json ?? []).find((candidate) => candidate.circle === 'public')
			if (!link) {
				throw new Error('No public link: run with --prepare')
			}
			await h.goto(`/apps/cantoria/p/${link.token}`)
			await h.page.waitForSelector('.excerpt-viewer svg', { timeout: 60000 })
			await h.settle(1500)
		},
	},
	{
		name: 'public-repertoire', file: 'public-repertoire.png', account: 'public', ...en,
		run: async (h) => h.goto('/apps/cantoria/repertoire'),
	},
	{
		name: 'folder-book', file: 'folder-book.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=folder-book')
			return h.rect('#app-content-vue', { pad: 0, fit: true })
		},
	},
	{
		name: 'folder-take-back', file: 'folder-take-back.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=folder-book')
			await h.click('#app-content-vue button', h.t('Take back'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(600)
			await h.page.evaluate((label) => [...document.querySelectorAll('.modal-container .checkbox-radio-switch')]
				.find((choice) => choice.innerText.trim() === label)?.querySelector('input')?.click(), h.t('Worn'))
			await h.fill(h.t('Missing sheets'), h.session.lang === 'de' ? '2-2, Seite 1' : '2-2, page 1', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'print-mirror-preview', file: 'print-mirror-preview.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=print-station')
			await h.click('#app-content-vue button', h.t('Preview and first sync …'))
			await h.page.waitForSelector('.modal-container', { visible: true, timeout: 30000 })
			await h.settle(1500)
		},
		after: escape,
	},
	{
		name: 'settings-print-mirror', file: 'settings-print-mirror.png', account: 'admin', ...en,
		run: async (h) => {
			await h.goto('/settings/admin/cantoria#cantoria-print-mirror')
			await h.page.waitForSelector('#cantoria-print-mirror', { visible: true, timeout: 30000 })
			await h.settle(1000)
			await h.click('#cantoria-print-mirror button', h.t('Choose folder …'))
			await pickFolder(h, 'Print station')
			const fits = h.t('The folder {path} fits: outside the archive folder, with write permission.').split('{path}')[1].split(':')[0].trim()
			await h.page.waitForFunction((fits) => document.querySelector('#cantoria-print-mirror')?.innerText.includes(fits), { timeout: 30000 }, fits)
			await h.page.evaluate(() => document.querySelector('#cantoria-print-mirror')?.scrollIntoView({ block: 'start' }))
			await h.settle(800)
			return h.rect('#cantoria-print-mirror', { pad: 0, fit: true })
		},
	},
	{
		name: 'unified-search', file: 'unified-search.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.goto('/apps/files/')
			await h.page.evaluate(() => [...document.querySelectorAll('header button')].find((b) => /search|such/i.test(b.getAttribute('aria-label') ?? ''))?.click())
			await h.page.waitForSelector('[data-cy-unified-search-input]', { visible: true, timeout: 15000 })
			await h.page.type('[data-cy-unified-search-input]', 'Mond')
			await h.page.waitForFunction(() => document.querySelector('.unified-search-modal')?.innerText.includes('Cantoria'), { timeout: 30000 })
			await h.settle(1500)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'protection-special-case', file: 'protection-special-case.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '1-6', 'rights')
			await h.page.evaluate((label) => {
				const field = [...document.querySelectorAll('.app-sidebar label, .app-sidebar .checkbox-radio-switch')].find((el) => el.innerText.includes(label))
				field?.scrollIntoView({ block: 'start' })
			}, h.t('Scientific edition (§ 70 UrhG)'))
			await h.settle(600)
			return sidebarClip(h, 1000)
		},
	},
	{
		name: 'rights-notice', file: 'rights-notice.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '1-1', 'files')
			await h.page.waitForSelector('.file-list__notice', { visible: true, timeout: 20000 })
			await h.page.evaluate(() => document.querySelector('.file-list__notice')?.closest('li')?.scrollIntoView({ block: 'center' }))
			await h.settle(600)
			return sidebarClip(h, 1000)
		},
	},
	{
		name: 'shelfmark-gaps', file: 'shelfmark-gaps.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=shelfmark-gaps')
			return h.rect('#app-content-vue', { pad: 0, fit: true })
		},
	},
	{
		name: 'annual-snapshots', file: 'annual-snapshots.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=annual-snapshots')
			await h.click('#app-content-vue button', h.t('Compare'))
			await h.page.waitForFunction(() => document.querySelector('#app-content-vue')?.innerText.includes('Locus iste'), { timeout: 30000 })
			await h.settle(800)
			return h.rect('#app-content-vue', { pad: 0, fit: true })
		},
	},
	{
		// Revision 2 of 2-2 (a correction in the app) compared with revision 1
		name: 'revisions', file: 'revisions.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '2-2', 'files')
			await h.page.waitForSelector('.states__compare-form', { visible: true, timeout: 20000 })
			await h.click('.states__compare-form button', h.t('Compare'))
			await h.page.waitForSelector('.states__changes', { visible: true, timeout: 30000 })
			await h.page.evaluate(() => document.querySelector('.states')?.scrollIntoView({ block: 'start' }))
			await h.settle(600)
			return sidebarClip(h, 1000)
		},
	},

	// ---- German interface (informal) ----
	{
		name: 'de-catalog', file: 'de/catalog.png', account: 'librarian', ...de,
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'de-start-page', file: 'de/start-page.png', account: 'librarian', ...de,
		run: async (h) => {
			await h.app('')
			return h.rect(['.due-view .view-header', '.due-view__cards', '.due-view__concerts'], { pad: 8 })
		},
	},
	{
		name: 'de-rights-confirm', file: 'de/rights-confirm.png', account: 'librarian', ...de,
		run: async (h) => {
			await sidebar(h, '1-3', 'rights')
			await h.click('.rights-panel button', h.t('Confirm'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Reason (required)'), 'Alle Beteiligten sind vor mehr als 70 Jahren gestorben.', '.modal-container')
			await h.fill(h.t('Evidence (required), e.g. source of the life dates or license'), 'Lebensdaten im Personenregister; Titelseite des Drucks', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-score-voice', file: 'de/score-voice.png', account: 'conductor', ...de,
		run: async (h) => {
			await score(h, '1-1', 'Alt', 'focus')
			return scoreClip(h)
		},
	},
	{
		name: 'de-practice-audio', file: 'de/practice-audio.png', account: 'conductor', ...de,
		run: async (h) => {
			await score(h, '1-1', 'Alt', 'focus')
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-valid-version', file: 'de/valid-version.png', account: 'conductor', ...de,
		run: async (h) => {
			await sidebar(h, '2-2', 'rights')
			await h.click('.rights-panel button', h.t('Set as valid for the choir'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Reason (optional)'), 'Diesen Satz singen wir im Adventskonzert.', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-question-answer', file: 'de/question-answer.png', account: 'conductor', ...de,
		run: async (h) => {
			await h.app('?view=tasks')
			await h.page.evaluate(() => {
				const item = [...document.querySelectorAll('li, article')].find((el) => el.innerText.includes('Alt, Takt 3'))
				item?.scrollIntoView({ block: 'center' })
				;[...(item?.querySelectorAll('button') ?? [])].find((b) => b.innerText.trim() === 'Antworten' || b.innerText.trim() === 'Antwort')?.click()
			})
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Answer (required)'), 'Die zwei Achtel aus der Abschrift gelten, so singen wir es.', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-concert', file: 'de/concert.png', account: 'conductor', ...de,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			return h.rect(['.event-detail__summary', '.event-detail__items'], { pad: 12 })
		},
	},
]

/**
 * Pictures of the English guides that the German guides show in the German
 * interface: the same recipe as de/<file>. The German guides link only
 * pictures in de/ (src/__tests__/docs.test.ts).
 */
const GERMAN_TWINS = [
	'copy-overview', 'rights-panel', 'task-question', 'accession-form', 'notifications', 'backup-restore',
	'import-report', 'file-sync-report', 'settings-roles', 'admin-settings', 'protection-terms', 'merge-proposals',
	'statistics', 'annual-report', 'calendar-dialog', 'dashboard-widget', 'review-points', 'files-action',
	'share-dialog', 'shared-with-me', 'public-link', 'public-repertoire', 'folder-book', 'folder-take-back',
	'print-mirror-preview', 'settings-print-mirror', 'unified-search', 'protection-special-case', 'rights-notice',
	'shelfmark-gaps', 'annual-snapshots', 'revisions',
]
for (const name of GERMAN_TWINS) {
	const shot = SHOTS.find((candidate) => candidate.name === name)
	SHOTS.push({ ...shot, name: `de-${name}`, file: `de/${shot.file}`, ...de })
}

/**
 * Choose a file in Nextcloud's file picker by clicking through the folders.
 *
 * @param {object} h helpers
 * @param {string[]} path folder names and the file name
 */
async function pickFile(h, path) {
	await h.page.waitForSelector('.file-picker', { visible: true, timeout: 20000 })
	for (const name of path) {
		const row = `.file-picker tr[data-filename="${name}"]`
		await h.page.waitForSelector(row, { visible: true, timeout: 20000 })
		await h.page.click(`${row} .row-name`)
		await h.sleep(1200)
	}
	await h.click('.file-picker button', ['Choose', 'Auswählen', 'auswählen'])
}

/**
 * Choose a folder of the account's files in Nextcloud's file picker.
 *
 * @param {object} h helpers
 * @param {string} name folder name in the root of the account's files
 */
async function pickFolder(h, name) {
	await h.page.waitForSelector('.file-picker', { visible: true, timeout: 20000 })
	const row = `.file-picker tr[data-filename="${name}"]`
	await h.page.waitForSelector(row, { visible: true, timeout: 20000 })
	await h.page.click(`${row} .row-name`)
	await h.sleep(1200)
	await h.click('.file-picker button', ['Choose', 'Auswählen', 'auswählen'])
}
