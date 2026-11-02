/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Why a piece could not be kept on this device, in plain words (milestone
 * 0.19): the browser's own messages ("Load failed", "QuotaExceededError")
 * go to the console only. The server's message stays as it is: it is
 * translated and says what to do.
 */
import { t } from '../l10n.ts'

/**
 * A text for a failure of keeping a piece.
 *
 * @param error - what keepPieces or forgetPieces threw
 */
export function deviceErrorText(error: unknown): string {
	if (error instanceof DOMException && (error.name === 'QuotaExceededError' || error.code === 22)) {
		return t('cantoria', 'There is not enough space on this device. Remove other pieces or leave out the scans.')
	}
	// fetch rejects with a TypeError when there is no connection
	if (error instanceof TypeError || (typeof navigator !== 'undefined' && navigator.onLine === false)) {
		return t('cantoria', 'There is no connection. Please try again with a network.')
	}
	// keep() throws an Error with the server's message, or an empty one
	if (error instanceof Error && error.message !== '' && !(error instanceof DOMException)) {
		return error.message
	}
	return t('cantoria', 'It did not work. Please check the network and the space on the device and try again.')
}
