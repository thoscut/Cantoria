/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Large-print parts: what the server says about the rights, and the record
 * of a part that is printed (LargePrintService).
 */
import axios from '@nextcloud/axios'
import { apiUrl } from './api.ts'

export interface LargePrintCheck {
	allowed: boolean
	/** "free" (confirmed public domain) or "license" */
	basis: string | null
	/** why not, translated */
	reason: string | null
	/** the catalog librarian may confirm an accessible copy (§ 45b UrhG) */
	canConfirm: boolean
}

/**
 * Whether a large-print part of a copy may be made.
 *
 * @param copyId - the copy
 */
export async function fetchLargePrintCheck(copyId: number): Promise<LargePrintCheck> {
	const { data } = await axios.get<LargePrintCheck>(apiUrl('/copies/{id}/large-print', { id: copyId }))
	return data
}

/**
 * Records the part that is being printed; the server checks the rights again.
 *
 * @param copyId - the copy
 * @param voice - the voice of the part, as shown
 * @param accessible - the catalog librarian confirms an accessible copy
 */
export async function recordLargePrint(copyId: number, voice: string, accessible: boolean): Promise<void> {
	await axios.post(apiUrl('/copies/{id}/large-print', { id: copyId }), { voice, accessible })
}
