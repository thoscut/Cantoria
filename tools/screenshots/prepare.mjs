/**
 * Brings the demo catalog into the state shown in the screenshots, through
 * the app's own API and as the accounts that may do each step: the demo
 * folder shared read-only with the conductors, persons, performances and
 * song lists imported, rights confirmed, a version in the singers' portal, a
 * license, valid versions, tasks, a proposal, an internal link and a planned
 * concert. Every text is invented.
 *
 * Steps that are done already are skipped, so it can run again; the planned
 * concert "Adventskonzert 2026" marks the state as complete.
 */

const CONCERT = 'Adventskonzert 2026'

/**
 * @param {object} options
 * @param {(role: string) => Promise<object>} options.session logged-in session of a role
 * @param {string} options.demoFolder folder of the demo data in the librarian's files
 * @param {(...text: string[]) => void} options.log progress
 */
export async function prepare({ session, demoFolder, log }) {
	const librarian = await session('librarian')
	const conductor = await session('conductor')
	const contributor = await session('contributor')

	const check = (what, result, ok = [200, 201]) => {
		if (!ok.includes(result.status)) {
			throw new Error(`${what}: HTTP ${result.status} ${JSON.stringify(result.json)}`)
		}
		return result.json
	}

	// File ids of the import lists in the demo folder
	const listing = await librarian.request('PROPFIND', `/remote.php/dav/files/${encodeURIComponent(librarian.uid)}/${encodeURIComponent(demoFolder)}/_Import/`, {
		headers: { Depth: '1', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	if (listing.status !== 207) {
		throw new Error(`The folder ${demoFolder}/_Import of ${librarian.uid} cannot be read (HTTP ${listing.status}).`)
	}
	const fileIds = Object.fromEntries([...listing.text.matchAll(/<d:href>([^<]+)<\/d:href>[\s\S]*?<oc:fileid>(\d+)<\/oc:fileid>/g)]
		.map((match) => [decodeURIComponent(match[1]).split('/').filter(Boolean).pop(), Number(match[2])]))

	// Everyone with a role reads the files through their own Nextcloud: the
	// conductors get the demo folder as a read-only share.
	const ocs = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
	const sharesPath = '/ocs/v2.php/apps/files_sharing/api/v1/shares'
	const shares = await librarian.request('GET', `${sharesPath}?path=${encodeURIComponent('/' + demoFolder)}`, { headers: ocs })
	const conductorGroup = process.env.NC_GROUP_CONDUCTORS ?? 'demo-conductors'
	if (!JSON.parse(shares.text).ocs?.data?.some((share) => share.share_with === conductorGroup)) {
		const shared = await librarian.request('POST', sharesPath, {
			headers: { ...ocs, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ path: '/' + demoFolder, shareType: '1', shareWith: conductorGroup, permissions: '1' }).toString(),
		})
		if (shared.status !== 200) {
			throw new Error(`Sharing ${demoFolder} with ${conductorGroup} failed: HTTP ${shared.status}`)
		}
		log(`${demoFolder} shared with ${conductorGroup} (read only)`)
	}

	const entries = check('entries', await librarian.api('GET', '/api/entries'))
	if (entries.length === 0) {
		throw new Error('The catalog is empty: import the demo catalog first (see README.md).')
	}
	const copy = Object.fromEntries(entries.map((entry) => [entry.shelfmark, entry]))
	const versionOf = async (shelfmark) => check('entry ' + shelfmark, await librarian.api('GET', `/api/entries/${copy[shelfmark].id}`)).version.id

	if (check('persons', await librarian.api('GET', '/api/persons')).length === 0) {
		const report = check('person import', await librarian.api('POST', '/api/persons/import', { fileId: fileIds['persons.csv'], dryRun: false }))
		log(`persons imported: ${report.created ?? '?'} new`)
	}
	const concerts = check('concerts', await librarian.api('GET', '/api/events?kind=concert'))
	if (!concerts.some((event) => event.imported)) {
		check('performance import', await librarian.api('POST', '/api/performances/import', { fileId: fileIds['performances.csv'], dryRun: false }))
		check('song list import', await librarian.api('POST', '/api/performances/import', { fileId: fileIds['song-lists.csv'], dryRun: false }))
		log('performances and song lists imported')
	}
	const context = { session, librarian, conductor, copy, versionOf, check, log, demoFolder, ocs, sharesPath }
	if (concerts.some((event) => event.title === CONCERT)) {
		log(`"${CONCERT}" exists: the base state is complete`)
		await extras(context)
		return
	}

	// Rights: confirmed public domain (1-3 stays unconfirmed for the dialog)
	for (const shelfmark of ['2-1', '1-1', '1-2', '2-2']) {
		check('confirm ' + shelfmark, await librarian.api('POST', `/api/versions/${await versionOf(shelfmark)}/confirm`, {
			status: 'public_domain',
			reason: 'Everyone involved died more than 70 years ago; see the persons of this version.',
			evidence: 'Life dates in the register of persons; title page of the print',
		}))
	}
	check('portal 2-1', await librarian.api('POST', `/api/versions/${await versionOf('2-1')}/portal`, { since: '2025-09-01' }))
	check('license 2-6', await librarian.api('POST', `/api/versions/${await versionOf('2-6')}/licenses`, {
		license: { holder: 'Demo-Musikverlag', uses: ['binder'], quantity: 30, valid_from: '2025-01-01', valid_until: '2026-11-15', evidence: 'Invoice 2025-017 (demo)', notes: '' },
	}))

	// The conductor chooses the versions the choir sings
	for (const shelfmark of ['2-1', '1-1']) {
		check('valid ' + shelfmark, await conductor.api('POST', `/api/versions/${await versionOf(shelfmark)}/valid`, { reason: 'The version the choir sings' }))
	}

	// Questions and tasks with a place in the score
	check('task 2-1', await librarian.api('POST', `/api/copies/${copy['2-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'conductor', category: 'reading', question: 'Tenor II in bar 6: slur or tie? The scan is unclear.', measure_from: '5', measure_to: '8', voice: 'Tenor II' },
	}))
	check('task 1-4', await librarian.api('POST', `/api/copies/${copy['1-4'].id}/tasks`, {
		task: { kind: 'check', addressee: 'operations', category: 'shelf', question: 'Please count the copies in binder 1 before Advent.' },
	}))
	check('task 1-1', await conductor.api('POST', `/api/copies/${copy['1-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'librarian', category: 'origin', question: 'Is there an edition with the third verse for the concert?', measure_from: '1', measure_to: '4', voice: 'Sopran', verse: '3' },
	}))
	// German question for the German quick start
	check('task 1-1 (de)', await librarian.api('POST', `/api/copies/${copy['1-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'conductor', category: 'reading', question: 'Alt, Takt 3: Gilt die Viertelnote aus dem Druck oder die zwei Achtel aus der Abschrift?', measure_from: '3', measure_to: '3', voice: 'Alt' },
	}))

	// A proposal from a tool waits in the inbox
	check('proposal', await contributor.api('POST', '/api/proposals', {
		level: 'version', id: await versionOf('1-2'), fields: { key_signature: 'D major' }, reason: 'Read from the MusicXML file', source: 'Transcription round (demo)',
	}))

	// Internal link to a place in the score
	check('link 2-1', await librarian.api('POST', `/api/copies/${copy['2-1'].id}/links`, {
		link: { measure_from: '5', measure_to: '8', voice: 'Tenor II', note: 'Slur or tie in bar 6?' },
	}))

	// A planned concert with warnings
	const event = check('concert', await librarian.api('POST', '/api/events', {
		kind: 'concert',
		event: { title: CONCERT, event_date: '2026-12-13', start_time: '17:00', end_time: '18:30', location: 'Demostadt, Kirche St. Beispiel', is_public: true, board_agreed: true, notes: '' },
	}))
	for (const shelfmark of ['1-6', '1-4', '1-1', '3-1', '2-6', '4-1', '1-3']) {
		check('item ' + shelfmark, await librarian.api('POST', `/api/events/${event.id}/items`, { copyId: copy[shelfmark].id }))
	}
	const detail = check('concert detail', await librarian.api('GET', `/api/events/${event.id}`))
	const durations = [150, 185, 140, 140, 165, 160, 120]
	for (const [index, item] of detail.items.entries()) {
		check('duration', await librarian.api('PATCH', `/api/event-items/${item.id}`, { item: { duration: durations[index] ?? 120 } }))
	}
	log(`demo state created (concert ${event.id})`)
	await extras(context)
}

/**
 * Share the demo folder read-only with a group, unless it is shared already.
 *
 * @param {object} librarian session of the account that owns the demo folder
 * @param {string} demoFolder folder of the demo data
 * @param {string} group group id
 * @param {object} options ocs headers, sharesPath and log
 */
async function shareWith(librarian, demoFolder, group, { ocs, sharesPath, log }) {
	const shares = await librarian.request('GET', `${sharesPath}?path=${encodeURIComponent('/' + demoFolder)}`, { headers: ocs })
	if (JSON.parse(shares.text).ocs?.data?.some((share) => share.share_with === group)) {
		return
	}
	const shared = await librarian.request('POST', sharesPath, {
		headers: { ...ocs, 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({ path: '/' + demoFolder, shareType: '1', shareWith: group, permissions: '1' }).toString(),
	})
	if (shared.status !== 200) {
		throw new Error(`Sharing ${demoFolder} with ${group} failed: HTTP ${shared.status}`)
	}
	log(`${demoFolder} shared with ${group} (read only)`)
}

/**
 * The state of the pictures of milestones 0.7 to 0.9: links for choir
 * members and the public, the public repertoire page, the folder book, the
 * mirror folder of the print station, a revision made by a correction, a
 * special case of the protection term and two annual snapshots. Needs the
 * modules member_links, public_links, public_repertoire, folder_book,
 * print_mirror and annual_snapshot (see README.md). Each part is skipped
 * when it is done already.
 *
 * @param {object} context sessions and helpers of prepare()
 */
async function extras({ session, librarian, copy, versionOf, check, log, demoFolder, ocs, sharesPath }) {
	const admin = await session('admin')
	const entry = async (shelfmark) => check('entry ' + shelfmark, await librarian.api('GET', `/api/entries/${copy[shelfmark].id}`))

	// Links for choir members need the release for the singers' portal
	if (!(await entry('1-1')).releases?.onPortal) {
		check('portal 1-1', await librarian.api('POST', `/api/versions/${await versionOf('1-1')}/portal`, { since: '2025-09-01' }))
	}
	// Both links share the whole piece and offer the scan (PDF) of the copy
	const scanOf = async (shelfmark) => {
		const scan = check('link options ' + shelfmark, await librarian.api('GET', `/api/copies/${copy[shelfmark].id}/link-options`)).scans?.[0]
		if (!scan) {
			throw new Error(`No scan (PDF) linked to ${shelfmark}: run the file sync first`)
		}
		return scan.id
	}
	const links = check('links', await librarian.api('GET', '/api/links'))
	if (!links.some((link) => link.circle === 'members')) {
		check('member link 1-1', await librarian.api('POST', `/api/copies/${copy['1-1'].id}/links`, {
			link: { voice: 'Alt', note: 'Please practise the alto until Thursday.', circle: 'members', scan_file_id: await scanOf('1-1') },
		}))
		log('link for choir members created (1-1, whole piece with scan)')
	}
	if (!links.some((link) => link.circle === 'public')) {
		if ((await entry('1-2')).version.publication_status !== 'free') {
			check('publication 1-2', await librarian.api('PATCH', `/api/version/${await versionOf('1-2')}`, { fields: { publication_status: 'free' }, reason: 'Public domain, own setting under CC0' }))
		}
		check('public link 1-2', await librarian.api('POST', `/api/copies/${copy['1-2'].id}/links`, {
			link: { circle: 'public', scan_file_id: await scanOf('1-2') },
		}))
		log('public link created (1-2, whole piece with scan)')
	}

	// The public repertoire page: works with a version confirmed public domain
	const repertoire = {
		'1-1': { cpdl_url: 'https://www.cpdl.org/wiki/index.php/Der_Mond_ist_aufgegangen_(Johann_Abraham_Peter_Schulz)' },
		'1-2': { imslp_url: 'https://imslp.org/wiki/Symphony_No.9,_Op.125_(Beethoven,_Ludwig_van)' },
		'2-1': { cpdl_url: 'https://www.cpdl.org/wiki/index.php/%C3%84nnchen_von_Tharau_(Friedrich_Silcher)' },
	}
	for (const [shelfmark, urls] of Object.entries(repertoire)) {
		const detail = await entry(shelfmark)
		if (!detail.work.public_repertoire) {
			check('repertoire ' + shelfmark, await librarian.api('PATCH', `/api/work/${detail.work.id}`, { fields: { public_repertoire: true, ...urls } }))
		}
	}

	// The person import leaves the role of a contribution open, but the public
	// repertoire page names composer and lyricist, and a protection proposal
	// needs both or traditional parts: take them from the catalog fields
	const plain = (name) => name.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue')
	for (const shelfmark of Object.keys(copy)) {
		const detail = await entry(shelfmark)
		const text = (detail.version.arranger_text ?? '').match(/Text: ([^;]*)/)?.[1] ?? null
		let lyricist = false
		for (const contribution of detail.contributions ?? []) {
			const name = contribution.person?.name ?? ''
			const role = plain(name) === plain(detail.work.composer ?? '') ? 'composer' : text?.includes(name) ? 'lyricist' : null
			lyricist ||= role === 'lyricist' || contribution.role === 'lyricist'
			if (contribution.role === 'unspecified' && role) {
				check('contribution ' + shelfmark, await librarian.api('PATCH', `/api/contributions/${contribution.id}`, { contribution: { role } }))
			}
		}
		// "Volksweise", "Melodie: Wien 1776"; texts without a person, such as "Speyer 1599"
		const fields = {}
		if (/^(Volksweise|Melodie:)/.test(detail.work.composer ?? '') && !detail.version.melody_origin) {
			fields.melody_origin = 'traditional'
		}
		if (text !== null && !lyricist && !detail.version.text_origin) {
			fields.text_origin = 'traditional'
		}
		if (Object.keys(fields).length > 0) {
			check('origin ' + shelfmark, await librarian.api('PATCH', `/api/version/${detail.version.id}`, { fields, reason: 'Traditional part (demo)' }))
		}
	}

	// Folder book: one folder out (an invented name), one in the cupboard,
	// one with missing sheets and a version that no longer applies
	if (check('folder book', await librarian.api('GET', '/api/folder-book')).folders.length === 0) {
		const folder = async (label, voice, items, extra = {}) => check('folder ' + label, await librarian.api('POST', '/api/folder-book/folders', {
			folder: { label, voice, contents: items.map(([shelfmark, count]) => ({ copyId: copy[shelfmark].id, count })), wear: 'good', ...extra },
		}))
		const out = await folder('12', 'Tenor II', [['2-1', 1], ['2-2', 1], ['1-6', 1]])
		await folder('14', 'Alto', [['1-1', 1], ['1-4', 1], ['4-1', 1]])
		await folder('21', 'Bass', [['1-5', 1], ['2-3', 1], ['1-6', 1]], { wear: 'worn', missing: '1-6, page 2' })
		check('issue 12', await librarian.api('POST', `/api/folder-book/folders/${out.id}/issue`, { loan: { holderName: 'Kim Beispiel', issuedOn: '2026-09-14' } }))
		log('folder book created')
	}

	// Mirror folder of the print station: the administrator reads the archive
	// folder and chooses a folder of their own; a file of their own in it is
	// skipped, never overwritten
	const groupAdmins = process.env.NC_GROUP_ADMINS ?? 'admin'
	await shareWith(librarian, demoFolder, groupAdmins, { ocs, sharesPath, log })
	const mirror = check('print mirror', await admin.api('GET', '/api/settings/print-mirror'))
	if (!mirror.folder) {
		const dav = `/remote.php/dav/files/${encodeURIComponent(admin.uid)}/Print%20station`
		await admin.request('MKCOL', dav)
		await admin.request('PUT', `${dav}/${encodeURIComponent('2-1 Ännchen von Tharau.pdf')}`, { body: 'Placed here by hand (demo).', headers: { 'Content-Type': 'application/pdf' } })
		const found = await admin.request('PROPFIND', dav, {
			headers: { Depth: '0', 'Content-Type': 'application/xml' },
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		const folderId = Number(found.text.match(/<oc:fileid>(\d+)<\/oc:fileid>/)?.[1])
		check('mirror target', await admin.api('PUT', '/api/settings/print-mirror', { folderId }))
		log('mirror folder of the print station chosen: Print station')
	}

	// Revision 2 of 2-2: a correction of one pitch in the app, made current
	const versionId = await versionOf('2-2')
	let states = check('revisions 2-2', await librarian.api('GET', `/api/versions/${versionId}/states`)).states
	if (states.length < 2) {
		const fileId = (await entry('2-2')).score.fileId
		const original = await librarian.request('GET', `/index.php/apps/cantoria/api/files/${fileId}/content`)
		// The first note of measure 3 in the second part, one step higher
		const parts = original.text.split('<part id=')
		const measure = parts[2].match(/<measure number="3"[\s\S]*?<\/measure>/)[0]
		const step = measure.match(/<step>([A-G])<\/step>/)[1]
		const higher = 'CDEFGAB'['CDEFGAB'.indexOf(step) + 1] ?? 'C'
		parts[2] = parts[2].replace(measure, measure.replace(`<step>${step}</step>`, `<step>${higher}</step>`))
		check('correction 2-2', await librarian.api('POST', `/api/versions/${versionId}/corrections`, {
			fileId, content: parts.join('<part id='), pointIds: [], reason: 'Wrong note in bar 3, compared with the printed edition',
		}))
		states = check('revisions 2-2', await librarian.api('GET', `/api/versions/${versionId}/states`)).states
		check('current revision', await librarian.api('PUT', `/api/states/${states.at(-1).id}/current`, { reason: 'The choir sings the corrected note.' }))
		log('revision 2 of 2-2 recorded and made current')
	}

	// Special case of the protection term: a scientific edition
	const ros = await entry('1-6')
	if (!ros.version.scientific_edition) {
		check('special case 1-6', await librarian.api('PATCH', `/api/version/${ros.version.id}`, { fields: { scientific_edition: true, first_published_year: 1991 }, reason: 'Critical edition (demo)' }))
	}

	// Two annual snapshots with a confirmed version and a new license between them
	const snapshots = check('annual snapshots', await librarian.api('GET', '/api/annual-snapshots')).snapshots
	if (snapshots.length < 2) {
		if (snapshots.length === 0) {
			check('snapshot 1', await librarian.api('POST', '/api/annual-snapshots'))
			// The list names snapshots by the minute: the second one in another minute
			await new Promise((done) => setTimeout(done, 61000))
		}
		if (!(await entry('4-2')).version.confirmed_at) {
			check('confirm 4-2', await librarian.api('POST', `/api/versions/${await versionOf('4-2')}/confirm`, {
				status: 'public_domain', reason: 'Bruckner died in 1896; the text is liturgical.', evidence: 'Life dates in the register of persons',
			}))
		}
		if (!(await entry('2-6')).licenses.some((license) => String(license.uses).includes('recording'))) {
			check('license 2-6', await librarian.api('POST', `/api/versions/${await versionOf('2-6')}/licenses`, {
				license: { holder: 'Demo-Musikverlag', uses: ['recording'], quantity: null, valid_from: '2026-10-01', valid_until: '2027-09-30', evidence: 'Contract 2026-041 (demo)', notes: '' },
			}))
		}
		check('snapshot 2', await librarian.api('POST', '/api/annual-snapshots'))
		log('annual snapshots written')
	}
}
