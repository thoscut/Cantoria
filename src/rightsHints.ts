/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What confirming a rights status makes possible, said in the dialog before
 * saving (guideline 13: consequences before saving). It follows the rules of
 * the server (lib/Service/RightsPolicy.php) and names only switched-on
 * functions; the server decides again on every use. Every way out is named
 * (R B14): inside the choir, to choir members (also a whole programme, on
 * their devices and through the hand-over folder) and to everyone (playback
 * and the scan of public links, on a device and printed, the repertoire
 * page). Which ways only confirmed public domain opens, never a license, the
 * server says (RightsPolicy::FREE_ONLY_WAYS, A23).
 */
import { t } from './l10n.ts'

/**
 * The ways out of the archive that a confirmed status opens, in the order of
 * the dialog: inside the choir, to choir members, to everyone. `way`: the key
 * under which the server names a way that only confirmed public domain
 * opens.
 */
const USES: { active: (isActive: (module: string) => boolean) => boolean, label: () => string, way?: string }[] = [
	{ active: (on) => on('portal'), label: () => t('cantoria', 'singers\' portal') },
	{ active: (on) => on('print_station'), label: () => t('cantoria', 'print station') },
	{ active: (on) => on('practice_audio'), label: () => t('cantoria', 'practice files') },
	{ active: (on) => on('concert_folder'), label: () => t('cantoria', 'printing from the concert folder') },
	{ active: (on) => on('concert_folder') && on('offline'), label: () => t('cantoria', 'the concert folder on the device without a network') },
	{ active: (on) => on('member_links'), label: () => t('cantoria', 'sharing with choir members') },
	{ active: (on) => on('member_links') && on('concerts'), label: () => t('cantoria', 'sharing a programme with the choir') },
	{ active: (on) => on('member_links'), label: () => t('cantoria', 'playback and the scan for choir members') },
	{ active: (on) => on('member_links') && on('offline'), label: () => t('cantoria', 'pieces on the devices of choir members without a network') },
	{ active: (on) => on('choir_handover'), label: () => t('cantoria', 'hand-over folder for the choir system (recordings only with a license for passing them on)') },
	{ active: (on) => on('public_links'), label: () => t('cantoria', 'public links (after the release for publication)') },
	{ active: (on) => on('public_links'), label: () => t('cantoria', 'playback and the scan of public links') },
	{ active: (on) => on('public_links') && on('offline'), label: () => t('cantoria', 'public links on a device without a network'), way: 'public_device' },
	{ active: (on) => on('public_links'), label: () => t('cantoria', 'printing from public links'), way: 'public_print' },
	{ active: (on) => on('public_repertoire'), label: () => t('cantoria', 'public repertoire page'), way: 'public_repertoire' },
]

/**
 * Sentences about the consequences of a confirmation.
 *
 * @param status - the status to be confirmed ('public_domain' or 'protected')
 * @param preservationCopy - whether the version is an internal preservation copy
 * @param isActive - whether a module is switched on
 * @param isFreeOnly - whether only confirmed public domain opens a way (from the server)
 */
export function confirmConsequences(status: string, preservationCopy: boolean, isActive: (module: string) => boolean,
	isFreeOnly: (way: string) => boolean): string[] {
	if (preservationCopy) {
		return [t('cantoria', 'This version is an internal preservation copy: it stays internal and is never shared, played or exported, whatever the rights status.')]
	}
	const free = status === 'public_domain'
	const uses = USES.filter((use) => use.active(isActive) && (free || use.way === undefined || !isFreeOnly(use.way))).map((use) => use.label())
	if (uses.length === 0) {
		return []
	}
	if (free) {
		return [t('cantoria', 'After the confirmation this becomes possible: {uses}.', { uses: uses.join(', ') })]
	}
	return [t('cantoria', 'A protected version needs a valid license for each of these uses: {uses}.', { uses: uses.join(', ') })]
}
