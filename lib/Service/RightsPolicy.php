<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\WorkVersion;

/**
 * Decides what may be done with the score of a version, based on its rights.
 *
 * A rendered excerpt counts as much as the whole sheet, and playback amounts
 * to practice audio. Therefore:
 * - Internal preservation copies may only be viewed (silently) by the roles
 *   that check transcriptions: catalog librarian and conductor. They are
 *   never played.
 * - Playback is offered for public domain versions and for versions with a
 *   valid license covering practice audio.
 * - Practice audio files (WAV or MIDI made from the score view) are copies
 *   that leave the app. Only the catalog librarian and the conductor make
 *   them, and only for confirmed public domain versions or versions with a
 *   valid license covering practice audio; never for preservation copies.
 * - Links for choir members (milestone 0.7) need the release for the
 *   digital provision to members, i.e. the singers' portal (confirmed public
 *   domain or a license for the portal). Playback and practice audio follow
 *   the same rules as above; choir members make practice audio of a shared
 *   excerpt themselves.
 * - Public links need the release for publication (publication status
 *   "free", set by the catalog librarian) and confirmed public domain or a
 *   license for online publication; playback as above.
 *
 * What counts as an internal preservation copy is decided here alone, for
 * every way out (internal API, API for agents, links, scans, print station):
 * a version marked as one, or a copy of the kind "Preservation copy"
 * (isPreservationCopy). Callers that know the copy pass it.
 *
 * Messages are English source strings; callers translate them. Reasons for
 * the people who share say what to do next.
 */
class RightsPolicy {
	/** Roles allowed to view internal preservation copies */
	public const PRESERVATION_COPY_VIEWERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];
	/** Roles allowed to make practice audio files */
	public const PRACTICE_AUDIO_MAKERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];
	/** Kind of copy (Fields, copy.kind) that makes its files an internal preservation copy */
	public const PRESERVATION_COPY_KIND = 'preservation_copy';

	public function __construct(
		private Roles $roles,
		private RightsService $rights,
		private LicenseMapper $licenses,
	) {
	}

	/**
	 * Whether the files of a version (or of one of its copies) are an internal
	 * preservation copy: the version is marked as one, or the copy is of the
	 * kind "Preservation copy". The one rule for every way out.
	 */
	public static function isPreservationCopy(WorkVersion $version, ?Copy $copy = null): bool {
		return $version->getPreservationCopy() === true || $copy?->getKind() === self::PRESERVATION_COPY_KIND;
	}

	/**
	 * @param Copy|null $copy the copy, when the request is about one
	 * @return string|null null when viewing is allowed, otherwise the reason
	 */
	public function viewDenial(WorkVersion $version, string $uid, ?Copy $copy = null): ?string {
		if (!$this->roles->hasAccess($uid)) {
			return 'You have no access to the music archive.';
		}
		if (self::isPreservationCopy($version, $copy) && !$this->mayViewPreservationCopies($uid)) {
			return 'Internal preservation copy: only the catalog librarian and the conductor may view it.';
		}
		return null;
	}

	/**
	 * Whether the account holds a role that may view internal preservation
	 * copies (the API for agents also leaves them out of its lists for the
	 * other roles).
	 */
	public function mayViewPreservationCopies(string $uid): bool {
		return $this->roles->hasAnyRole($uid, self::PRESERVATION_COPY_VIEWERS);
	}

	/**
	 * @return string|null null when playback is allowed, otherwise the reason
	 */
	public function playDenial(WorkVersion $version, string $uid, ?Copy $copy = null): ?string {
		return $this->viewDenial($version, $uid, $copy) ?? $this->playRightsDenial($version, $this->rights->today($uid), $copy);
	}

	/**
	 * Whether practice audio files may be made from the score: everything
	 * playback needs (see playDenial), one of the roles that make them, and a
	 * confirmed public domain status unless a license covers practice audio.
	 * Playing inside the app needs no confirmation; a file that leaves the
	 * app does.
	 *
	 * @return string|null null when the export is allowed, otherwise the reason
	 */
	public function practiceAudioDenial(WorkVersion $version, string $uid, ?Copy $copy = null): ?string {
		$playDenial = $this->playDenial($version, $uid, $copy);
		if ($playDenial !== null) {
			return $playDenial;
		}
		if (!$this->roles->hasAnyRole($uid, self::PRACTICE_AUDIO_MAKERS)) {
			return 'Practice audio files are made by the catalog librarian or the conductor.';
		}
		return $this->practiceAudioRightsDenial($version, $this->rights->today($uid));
	}

	/**
	 * Whether a version may be shared with choir members through a link for
	 * them: it must be released for the digital provision to members (the
	 * singers' portal), never an internal preservation copy. Checked when the
	 * link is made and on every call.
	 *
	 * @param string $day today (YYYY-MM-DD), for the validity of licenses
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function memberViewDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		if (self::isPreservationCopy($version, $copy)) {
			return 'Internal preservation copy: it is never shared with choir members.';
		}
		if ($this->rights->portalDenial($version, $this->licenses->findByVersion($version->getId()), $day) === null) {
			return null;
		}
		return $version->getRightsStatus() === 'public_domain'
			? 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then the version can be shared with choir members.'
			: 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Whether a link for choir members may offer the PDF scan, which choir
	 * members can save and print: the release of the notes, and for a
	 * version that is not confirmed public domain a license for download
	 * and printing by choir members (or for self-printing). A license for
	 * the singers' portal alone covers viewing, not copies (§ 53 (4) UrhG).
	 *
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function memberScanDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->memberViewDenial($version, $day, $copy);
		if ($denial !== null || ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed())) {
			return $denial;
		}
		$licenses = $this->licenses->findByVersion($version->getId());
		if ($this->rights->hasLicense($licenses, 'member_download', $day) || $this->rights->hasLicense($licenses, 'self_print', $day)) {
			return null;
		}
		return 'Choir members can save and print the scan: unless public domain is confirmed, it needs a license for download and printing by choir members. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Whether the conductor or the catalog librarian may keep the score and
	 * the scan of a version on their own device (concert folder, milestone
	 * 0.19): a copy that stays there for weeks is no viewing in the archive
	 * (§ 53 (4) UrhG). Confirmed public domain, or a license for download
	 * or self-printing; never an internal preservation copy.
	 *
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function deviceCopyDenial(WorkVersion $version, string $uid, ?Copy $copy = null): ?string {
		$denial = $this->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			return $denial;
		}
		if (self::isPreservationCopy($version, $copy)) {
			return 'Internal preservation copy: it is not kept on a device.';
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return null;
		}
		$day = $this->rights->today($uid);
		$licenses = $this->licenses->findByVersion($version->getId());
		if ($this->rights->hasLicense($licenses, 'member_download', $day) || $this->rights->hasLicense($licenses, 'self_print', $day)) {
			return null;
		}
		return 'Not available without a network: unless public domain is confirmed, a copy on a device needs a license for download or self-printing. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Playback of a link for choir members: the release for members and the
	 * rights for playback (see playDenial).
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function memberPlayDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		return $this->memberViewDenial($version, $day, $copy) ?? $this->playRightsDenial($version, $day, $copy);
	}

	/**
	 * Practice audio files that choir members make of a link for them: what
	 * playback needs plus the rights for practice audio files (see
	 * practiceAudioDenial); instead of the role check, the link is for
	 * choir members.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function memberPracticeAudioDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		return $this->memberPlayDenial($version, $day, $copy) ?? $this->practiceAudioRightsDenial($version, $day);
	}

	/**
	 * Whether an audio file of the archive folder (a practice recording) may
	 * go into the hand-over folder for a choir system (module
	 * "choir_handover"): released for choir members, and confirmed public
	 * domain or a license for practice audio. Scans and MusicXML follow
	 * memberScanDenial, as choir members can save and print them there.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function handoverAudioDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		return $this->memberViewDenial($version, $day, $copy) ?? $this->playRightsDenial($version, $day, $copy)
			?? $this->practiceAudioRightsDenial($version, $day);
	}

	/**
	 * Whether an excerpt of a version may be shown to everyone through a
	 * public link: released for publication by the catalog librarian, and
	 * confirmed public domain or a valid license for online publication;
	 * never an internal preservation copy.
	 *
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function publicViewDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		if (self::isPreservationCopy($version, $copy)) {
			return 'Internal preservation copy: it is never published.';
		}
		if ($version->getPublicationStatus() !== 'free') {
			return 'Not released for publication. The catalog librarian sets the publication status of the version to "Released for publication".';
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'online_publication', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'public_domain'
			? 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then a public link can be made.'
			: 'A public link needs confirmed public domain or a valid license for online publication. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Playback on the page of a public link: a public domain version plays,
	 * a protected one only with a license for online playback; a license for
	 * practice audio covers the use inside the choir, not making a sound
	 * version available to everyone (§ 19a UrhG).
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function publicPlayDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->publicViewDenial($version, $day, $copy);
		if ($denial !== null || $version->getRightsStatus() === 'public_domain') {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'online_audio', $day)) {
			return null;
		}
		return 'Playback on a public link needs public domain or a license for online playback (audio). The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * The rights part of playDenial: public domain or a license for practice
	 * audio; never a preservation copy. No role check.
	 */
	private function playRightsDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		if (self::isPreservationCopy($version, $copy)) {
			return 'Internal preservation copy: no playback.';
		}
		if ($version->getRightsStatus() === 'public_domain') {
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'practice_audio', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'protected'
			? 'Protected version: playback needs a license for practice audio.'
			: 'Rights not clarified: no playback until the rights status is known.';
	}

	/**
	 * The rights part of practiceAudioDenial, once playback is allowed: a
	 * public domain version must be confirmed unless a license covers
	 * practice audio. No role check.
	 */
	private function practiceAudioRightsDenial(WorkVersion $version, string $day): ?string {
		if ($version->getRightsStatus() !== 'public_domain' || $version->isConfirmed()) {
			// Protected or open: playback was allowed by a license for practice audio.
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'practice_audio', $day)) {
			return null;
		}
		return 'Public domain is not confirmed yet: practice audio files need a confirmed rights status or a license for practice audio.';
	}
}
