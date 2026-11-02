/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FileNode, FilesState } from '../filesAction.ts'

import { describe, expect, it } from 'vitest'
import { actionKind, appLink, errorText, fileExtension, isBelow, parseCopyOfFile } from '../filesAction.ts'
import { parseStartParams } from '../startParams.ts'

const ROOT = '/files/demo-librarian'

const STATE: FilesState = {
	archivePath: '/Cantoria-Demo',
	extensions: ['pdf', 'mxl', 'musicxml', 'xml', 'mscz', 'md', 'capx', 'cap', 'sib', 'mp3', 'wav', 'm4a', 'jpg', 'jpeg', 'png', 'bmp', 'tif'],
	scoreExtensions: ['musicxml', 'mxl'],
	scoreView: true,
}

/**
 * A file of the Files app.
 *
 * @param path - path relative to the account's files
 * @param changes - other properties
 */
function node(path: string, changes: Partial<FileNode> = {}): FileNode {
	return {
		type: 'file',
		root: ROOT,
		path,
		basename: path.slice(path.lastIndexOf('/') + 1),
		mime: 'application/octet-stream',
		permissions: 1,
		id: '4711',
		...changes,
	}
}

const kind = (nodes: FileNode[], state: FilesState | null = STATE, viewer: string[] = []) => actionKind(nodes, state, ROOT, viewer)

describe('fileExtension', () => {
	it('reads the extension in lower case like the file sync', () => {
		expect(fileExtension('2-01_Demo.MXL')).toBe('mxl')
		expect(fileExtension('2-01_Demo.score.musicxml')).toBe('musicxml')
		expect(fileExtension('LIESMICH')).toBe('')
		expect(fileExtension('name.')).toBe('')
	})
})

describe('isBelow', () => {
	it('needs the folder and a slash, not just the same beginning', () => {
		expect(isBelow('/Cantoria-Demo/Mappe_2/2-01.pdf', '/Cantoria-Demo')).toBe(true)
		expect(isBelow('/Cantoria-Demo-alt/2-01.pdf', '/Cantoria-Demo')).toBe(false)
		expect(isBelow('/Cantoria-Demo', '/Cantoria-Demo')).toBe(false)
		expect(isBelow('/Other/2-01.pdf', '/Cantoria-Demo')).toBe(false)
	})

	it('takes every file when the archive folder is the root of the files', () => {
		expect(isBelow('/2-01.pdf', '/')).toBe(true)
		expect(isBelow('/', '/')).toBe(false)
	})

	it('ignores a trailing slash of the folder', () => {
		expect(isBelow('/Cantoria-Demo/2-01.pdf', '/Cantoria-Demo/')).toBe(true)
	})
})

describe('actionKind', () => {
	it('is the default action for MusicXML in the archive folder', () => {
		expect(kind([node('/Cantoria-Demo/Mappe_2/2-01_Demo.mxl')])).toBe('default')
		expect(kind([node('/Cantoria-Demo/Mappe_2/2-01_Demo.musicxml')])).toBe('default')
		expect(kind([node('/Cantoria-Demo/Mappe_2/2-01_Demo.MusicXML')])).toBe('default')
	})

	it('is only in the menu for scans, MuseScore, audio and MusicXML named .xml', () => {
		for (const name of ['2-01_Demo.pdf', '2-01_Demo.mscz', '2-01_Demo.mp3', '2-01_Demo.xml', '2-01_Demo.jpg']) {
			expect(kind([node('/Cantoria-Demo/Mappe_2/' + name)]), name).toBe('menu')
		}
	})

	it('leaves the click to the Nextcloud viewer when it opens the type', () => {
		const mxl = node('/Cantoria-Demo/2-01_Demo.mxl', { mime: 'application/vnd.recordare.musicxml' })
		expect(kind([mxl], STATE, ['application/vnd.recordare.musicxml'])).toBe('menu')
		expect(kind([mxl], STATE, ['application/pdf'])).toBe('default')
	})

	it('is only in the menu for MusicXML while the score view is off', () => {
		expect(kind([node('/Cantoria-Demo/2-01_Demo.mxl')], { ...STATE, scoreView: false })).toBe('menu')
	})

	it('is not shown outside the archive folder or for other extensions', () => {
		expect(kind([node('/Documents/2-01_Demo.mxl')])).toBeNull()
		expect(kind([node('/Cantoria-Demo/LIESMICH.txt')])).toBeNull()
		expect(kind([node('/Cantoria-Demo/_Import/catalog.csv')])).toBeNull()
		expect(kind([node('/Cantoria-Demo/Mappe_2/README')])).toBeNull()
	})

	it('is not shown for folders, several files, other roots, unreadable files or without file id', () => {
		expect(kind([node('/Cantoria-Demo/Mappe_2.pdf', { type: 'folder' })])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf'), node('/Cantoria-Demo/2-02.pdf', { id: '4712' })])).toBeNull()
		expect(kind([])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf', { root: '/files/other-account' })])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf', { root: '/trashbin/demo-librarian' })])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf', { permissions: 0 })])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf', { id: undefined })])).toBeNull()
		expect(kind([node('/Cantoria-Demo/2-01.pdf', { id: 'abc' })])).toBeNull()
	})

	it('is not shown without the initial state', () => {
		expect(kind([node('/Cantoria-Demo/2-01_Demo.mxl')], null)).toBeNull()
	})
})

describe('parseCopyOfFile', () => {
	it('reads the answer of the server', () => {
		expect(parseCopyOfFile({ copyId: 35, shelfmark: '2-1', role: 'musicxml', score: true }))
			.toEqual({ copyId: 35, shelfmark: '2-1', role: 'musicxml', score: true })
		expect(parseCopyOfFile({ copyId: 35 })).toEqual({ copyId: 35, shelfmark: '', role: 'other', score: false })
	})

	it('refuses anything else', () => {
		expect(parseCopyOfFile(null)).toBeNull()
		expect(parseCopyOfFile('35')).toBeNull()
		expect(parseCopyOfFile({ copyId: '35' })).toBeNull()
		expect(parseCopyOfFile({ copyId: 0 })).toBeNull()
		expect(parseCopyOfFile({ copyId: 3.5 })).toBeNull()
	})
})

describe('appLink', () => {
	it('opens the score view for MusicXML and the copy otherwise', () => {
		expect(appLink('/index.php/apps/cantoria/', { copyId: 35, shelfmark: '2-1', role: 'musicxml', score: true }))
			.toBe('/index.php/apps/cantoria/?copy=35&score=1')
		expect(appLink('/index.php/apps/cantoria/', { copyId: 35, shelfmark: '2-1', role: 'score_pdf', score: false }))
			.toBe('/index.php/apps/cantoria/?copy=35')
	})

	it('gives a link the app reads back', () => {
		const link = appLink('/apps/cantoria/', { copyId: 35, shelfmark: '2-1', role: 'musicxml', score: true })
		expect(parseStartParams(link.slice(link.indexOf('?')))).toMatchObject({ copyId: 35, score: true, view: null })
	})
})

describe('errorText', () => {
	it('shows the message of the server', () => {
		expect(errorText(404, { error: 'This file is not listed in the music archive.' })).toBe('This file is not listed in the music archive.')
	})

	it('explains the rate limit, other errors and a lost connection', () => {
		expect(errorText(429, null)).toBe('Too many requests. Please wait a moment and try again.')
		expect(errorText(500, { error: '' })).toContain('Please try again in a moment.')
		expect(errorText(403, null)).toContain('The administration assigns the roles')
		expect(errorText(null, null)).toBe('The server cannot be reached. Please check your connection.')
	})
})
