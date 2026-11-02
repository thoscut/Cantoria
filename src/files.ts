/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Script of the Files app (module "files_integration"): registers the action
 * "Open in Cantoria" for files of the archive folder. The server adds
 * it only for accounts with access to the music archive
 * (Listener\FilesScriptListener), as an init script before the Files app
 * starts.
 *
 * It must stay small: no score viewer, no Vue, no store. The toast for an
 * error is loaded only when one occurs. Two actions with the same name: one
 * is the default action of a click on MusicXML, the other is only in the
 * actions menu (scans, MuseScore, audio, …); at most one applies to a file.
 *
 * Uses the file actions of @nextcloud/files 4, which Nextcloud 33 to 35 read
 * (see docs/api.md, "Opening from the Files app").
 */
import type { ActionContext, ActionContextSingle, IFileAction } from '@nextcloud/files'
import type { CopyOfFile, FilesState } from './filesAction.ts'

import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { DefaultType, registerFileAction } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { actionKind, appLink, errorText, parseCopyOfFile } from './filesAction.ts'
import { t } from './l10n.ts'

/** Before the download action of the Files app (30), after the Nextcloud viewer (0). */
const ORDER = 20

/**
 * The app icon (img/app-dark.svg) in the colour of the text. Not an icon of
 * the Material Design package: the icons of the app would come with it into
 * this script (shared chunk).
 */
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="currentColor" d="M13.36 3.5A7 7 0 1 0 13.36 12.5L11.83 11.21A5 5 0 1 1 11.83 4.79ZM8.7 3.6H10.1C11.6 4 12.8 5 12.7 6.6 12.2 5.7 11.2 5.3 10.1 5.2V10.4A2.15 1.6-20 1 1 8.7 9.25Z"/></svg>'

const state = loadState<FilesState | null>('cantoria', 'files', null)
const root = '/files/' + (getCurrentUser()?.uid ?? '')

/** MIME types the Nextcloud viewer opens (apps such as Text register theirs there). */
function viewerMimes(): readonly string[] {
	const mimes = (window as { OCA?: { Viewer?: { mimetypes?: unknown } } }).OCA?.Viewer?.mimetypes
	return Array.isArray(mimes) ? mimes.filter((mime): mime is string => typeof mime === 'string') : []
}

/**
 * Shows an error as a toast of Nextcloud that stays until it is closed
 * (toasts.ts). @nextcloud/dialogs is loaded only now, so that the script
 * stays small.
 *
 * @param message - translated text
 * @return null when the toast was shown, false when not (the Files app then reports the failure itself)
 */
async function report(message: string): Promise<null | false> {
	try {
		const { showError } = await import('./toasts.ts')
		showError(message)
		return null
	} catch {
		return false
	}
}

/**
 * Asks the server which copy the file belongs to and opens it in the app:
 * MusicXML in the score view, other files in the sidebar. The app checks
 * the rights itself.
 *
 * @param context - the action context of the Files app
 * @param context.nodes - the one file
 */
async function openInArchive({ nodes }: ActionContextSingle): Promise<boolean | null> {
	const url = generateUrl('/apps/cantoria/api/nextcloud-files/{fileId}/copy', { fileId: nodes[0].id ?? '' })
	let response: Response
	try {
		response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', requesttoken: getRequestToken() ?? '' },
		})
	} catch {
		return report(errorText(null, null))
	}
	const body: unknown = await response.json().catch(() => null)
	const copy: CopyOfFile | null = response.ok ? parseCopyOfFile(body) : null
	if (copy === null) {
		return report(errorText(response.status, body))
	}
	window.location.assign(appLink(generateUrl('/apps/cantoria/'), copy))
	return null
}

/**
 * One of the two actions.
 *
 * @param id - action id
 * @param kind - which files it applies to
 * @param isDefault - whether it is the default action of a click
 */
function action(id: string, kind: 'default' | 'menu', isDefault: boolean): IFileAction {
	return {
		id,
		displayName: () => t('cantoria', 'Open in Cantoria'),
		iconSvgInline: () => ICON,
		enabled: ({ nodes }: ActionContext) => actionKind(nodes, state, root, viewerMimes()) === kind,
		exec: openInArchive,
		order: ORDER,
		...(isDefault ? { default: DefaultType.DEFAULT } : {}),
	}
}

if (state !== null) {
	registerFileAction(action('cantoria-open-score', 'default', true))
	registerFileAction(action('cantoria-open', 'menu', false))
}
