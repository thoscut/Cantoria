/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Links into the guides (the same place as `<documentation>` in
 * appinfo/info.xml). Messages that say what to do link the matching
 * section. Both guides exist in English and in German; a German interface
 * links the German guide. A unit test checks that every anchor exists in
 * both languages. Opening a link is the person's own choice; the app itself
 * never requests anything from outside.
 */
import { getLanguage } from '@nextcloud/l10n'

const DOCS = 'https://github.com/thoscut/choircoresmanager/blob/main/docs/'

/** The files of the guides, per language. */
const FILES = {
	admin: { en: 'admin-guide.md', de: 'de/administrationshandbuch.md' },
	user: { en: 'user-guide.md', de: 'de/benutzerhandbuch.md' },
	members: { en: 'for-choir-members.md', de: 'de/fuer-chormitglieder.md' },
} as const

/** A section of a guide: GitHub's anchor of its heading in each language. */
export interface GuideSection {
	/** anchor in the English guide */
	en: string
	/** anchor in the German guide */
	de: string
}

/** Sections of the administration guide. */
export const ADMIN_GUIDE = {
	setup: { en: 'setting-up-in-six-steps', de: 'einrichten-in-sechs-schritten' },
	roles: { en: '2-roles-and-groups', de: '2-rollen-und-gruppen' },
	delegation: { en: 'giving-the-settings-to-a-group', de: 'die-einstellungen-einer-gruppe-übertragen' },
	archive: { en: '3-archive-folder', de: '3-archivordner' },
	sharing: { en: 'sharing-the-archive-folder', de: 'den-archivordner-teilen' },
	access: { en: 'checking-who-can-open-the-archive-folder', de: 'prüfen-wer-den-archivordner-öffnen-kann' },
	import: { en: '4-first-import', de: '4-erster-import' },
	backup: { en: '5-backups-and-restore', de: '5-sicherung-und-wiederherstellung' },
	modules: { en: '12-modules-and-presets', de: '12-module-und-voreinstellungen' },
	folderBook: { en: '15-folder-book-retention-period', de: '15-mappenbuch-löschfrist' },
	printMirror: { en: '16-mirror-folder-for-the-print-station', de: '16-spiegelordner-für-die-druckstation' },
	choirHandover: { en: '17-hand-over-to-a-choir-system', de: '17-übergabe-an-ein-chorsystem' },
	problems: { en: 'common-problems', de: 'häufige-probleme' },
} as const satisfies Record<string, GuideSection>

/** Sections of the user guide. */
export const USER_GUIDE = {
	filesNotShared: { en: 'files-you-cannot-open', de: 'dateien-die-sich-nicht-öffnen-lassen' },
	revisions: { en: 'revisions-of-a-version', de: 'stände-einer-fassung' },
	validVersion: { en: 'choosing-the-valid-version', de: 'die-geltende-fassung-festlegen' },
	noAccess: { en: 'no-access-to-the-music-archive', de: 'kein-zugang-zum-notenarchiv' },
	termVersion: { en: 'work-version-and-copy', de: 'werk-fassung-und-exemplar' },
	termShelfmark: { en: 'shelf-mark', de: 'signatur' },
	termRightsStatus: { en: 'rights-status-and-confirmation', de: 'rechtestatus-und-bestätigung' },
	termRelease: { en: 'release', de: 'freigabe' },
	termValidVersion: { en: 'valid-version', de: 'geltende-fassung' },
	termPreservationCopy: { en: 'internal-preservation-copy', de: 'interne-erhaltungskopie' },
	termPortal: { en: 'singers-portal-choir-system-and-shared-with-me', de: 'chorportal-chorsystem-und-mit-mir-geteilt' },
} as const satisfies Record<string, GuideSection>

/**
 * Link to a section of the administration guide, in the language of the
 * user.
 *
 * @param section - anchors of the section, a value of ADMIN_GUIDE
 */
export function adminGuideUrl(section: GuideSection): string {
	return guideLink('admin', section)
}

/**
 * Link to a section of the user guide, in the language of the user.
 *
 * @param section - anchors of the section, a value of USER_GUIDE
 */
export function userGuideUrl(section: GuideSection): string {
	return guideLink('user', section)
}

/** The guide behind "Help": the user guide in the language of the user. */
export function helpUrl(): string {
	return DOCS + (isGerman() ? FILES.user.de : FILES.user.en)
}

/**
 * The one-page guide for choir members (milestone 0.19), which the conductor
 * can pass on; linked from the empty page "Shared with me".
 */
export function membersGuideUrl(): string {
	return DOCS + (isGerman() ? FILES.members.de : FILES.members.en)
}

/**
 * The German guide for a German interface (informal or formal), otherwise
 * the English one.
 *
 * @param guide - the guide
 * @param section - anchors of the section
 */
function guideLink(guide: keyof typeof FILES, section: GuideSection): string {
	return isGerman()
		? `${DOCS}${FILES[guide].de}#${encodeURIComponent(section.de)}`
		: `${DOCS}${FILES[guide].en}#${encodeURIComponent(section.en)}`
}

/** Whether the user reads German. */
function isGerman(): boolean {
	return getLanguage().toLowerCase().startsWith('de')
}

/**
 * The anchor GitHub gives a heading: lower case, without punctuation, spaces
 * as hyphens. Umlauts stay. A heading that occurs twice gets "-1" on the
 * second anchor; the guides avoid that.
 *
 * @param heading - text of the heading without the leading #
 */
export function headingAnchor(heading: string): string {
	return heading.trim().toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/g, '-')
}
