/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The terms of the archive that have a short explanation at their place
 * (the "?" button, HelpTerm.vue): name, one or two sentences and the
 * section of the guide. All texts in one place, so that they stay the same
 * wherever a term is explained.
 */
import type { GuideSection } from './docs.ts'

import { USER_GUIDE } from './docs.ts'
import { t } from './l10n.ts'

export type HelpTermKey =
	| 'version'
	| 'copy'
	| 'shelfmark'
	| 'rightsStatus'
	| 'release'
	| 'validVersion'
	| 'preservationCopy'
	| 'revision'
	| 'singersPortal'

export interface HelpTermText {
	/** the term as it appears in the interface */
	name: string
	/** what it means, in plain words */
	text: string
	/** where the guide says more */
	section: GuideSection
}

/**
 * Name, explanation and guide section of a term.
 *
 * @param term - the term
 */
export function helpTerm(term: HelpTermKey): HelpTermText {
	switch (term) {
	case 'version':
		return {
			name: t('cantoria', 'Version'),
			text: t('cantoria', 'A version is one edition or arrangement of a work. Rights, releases and the valid version belong to the version, not to the work.'),
			section: USER_GUIDE.termVersion,
		}
	case 'copy':
		return {
			name: t('cantoria', 'Copy'),
			text: t('cantoria', 'A copy is one copy of a version on the shelf or in the archive folder, with its own shelf mark and status. A version can have several copies.'),
			section: USER_GUIDE.termVersion,
		}
	case 'shelfmark':
		return {
			name: t('cantoria', 'Shelf mark'),
			text: t('cantoria', 'The shelf mark says where a copy is kept: archive binder and number, e.g. 5-12. File names start with it, so that the file sync links the files to the copy.'),
			section: USER_GUIDE.termShelfmark,
		}
	case 'rightsStatus':
		return {
			name: t('cantoria', 'Rights status'),
			text: t('cantoria', 'Public domain, protected or open. The app proposes a status from the data; it counts for releases only after the music librarians have confirmed it. It is not legal advice.'),
			section: USER_GUIDE.termRightsStatus,
		}
	case 'release':
		return {
			name: t('cantoria', 'Release'),
			text: t('cantoria', 'A release says whether a version may be given out in a certain way, e.g. in the singers\' portal or at the print station. It follows from the confirmed rights or a valid license.'),
			section: USER_GUIDE.termRelease,
		}
	case 'validVersion':
		return {
			name: t('cantoria', 'Valid version'),
			text: t('cantoria', 'The version the conductor has chosen for the choir, at most one per work. It says nothing about the rights.'),
			section: USER_GUIDE.termValidVersion,
		}
	case 'preservationCopy':
		return {
			name: t('cantoria', 'Internal preservation copy'),
			text: t('cantoria', 'A copy kept only for the archive, e.g. a paper copy of a crumbling original of your own. It is never shared, played or exported. A digital scan of a protected print is usually not covered by the law for such copies.'),
			section: USER_GUIDE.termPreservationCopy,
		}
	case 'revision':
		return {
			name: t('cantoria', 'Revision'),
			text: t('cantoria', 'A revision is one state of the MusicXML of a version, numbered 1, 2, 3 …: after a correction or a better transcription. The current revision is the one exports and printouts name.'),
			section: USER_GUIDE.revisions,
		}
	case 'singersPortal':
		return {
			name: t('cantoria', 'Singers\' portal'),
			text: t('cantoria', 'In Cantoria, the list of what the choir may get. Where the choir gets it is the choir system outside Cantoria, or the page "Shared with me" of the choir members.'),
			section: USER_GUIDE.termPortal,
		}
	}
	return { name: '', text: '', section: USER_GUIDE.termVersion }
}

/** Sections of the sidebar whose heading is explained (FieldSection.vue). */
export const SECTION_HELP: Readonly<Partial<Record<string, HelpTermKey>>> = {
	version: 'version',
	copy: 'copy',
}

/** Fields whose label is explained (FieldSection.vue). */
export const FIELD_HELP: Readonly<Partial<Record<string, HelpTermKey>>> = {
	shelfmark: 'shelfmark',
	rights_status: 'rightsStatus',
	preservation_copy: 'preservationCopy',
}
