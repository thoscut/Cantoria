<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\License;
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
 *   valid license covering practice audio. Outside the archive (choir
 *   members, the public, the hand-over folder) only confirmed public domain
 *   counts (R B15).
 * - Practice audio files (WAV or MIDI made from the score view) are copies
 *   that leave the app. Only the catalog librarian and the conductor make
 *   them, and only for confirmed public domain versions or versions with a
 *   valid license covering practice audio; never for preservation copies.
 * - Links for choir members (milestone 0.7) need the release for the
 *   digital provision to members, i.e. the singers' portal (confirmed public
 *   domain or a license for the portal). Playback and practice audio need
 *   confirmed public domain or a license for practice audio; choir members
 *   make practice audio of a shared excerpt themselves.
 * - Public links need the release for publication (publication status
 *   "free", set by the catalog librarian) and confirmed public domain or a
 *   license for online publication; playback confirmed public domain or a
 *   license for online playback (`online_audio`). The scan of a public
 *   link can be saved and printed by everyone: it needs confirmed public
 *   domain or a license for the download of the scan on public links
 *   (`online_download`); a license for online publication covers showing
 *   the notes, not handing out the sheet (R B2).
 * - A rights conflict suspends public links (R M4): a version confirmed as
 *   public domain whose protection proposal, by the persons recorded now,
 *   says protected is published only with a license, until the catalog
 *   librarian has checked the rights status.
 * - How long a link may last follows the licenses that carry it
 *   (releaseEnd): a link ends at the latest on the last day of the license
 *   that releases it (R B20).
 * - Printing and keeping a piece on a device are copies that stay with a
 *   person (printDenial, deviceDenial), decided here for every circle:
 *   choir members, the concert folder and the visitors of a public link
 *   (R B21, B30, A23). Choir members and the concert folder need confirmed
 *   public domain or a license for download or self-printing (COPY_USES);
 *   visitors hold no license, so only confirmed public domain without a
 *   rights conflict counts for them. A MusicXML file kept on a device is a
 *   complete digital edition: unless public domain is confirmed, it needs a
 *   license for digital score files on top (R B26). The page in the
 *   browser receives the MusicXML of what it shows in order to draw the
 *   notes; that transfer follows the release of the notes, not this rule.
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
	/** License use for the download of the scan on public links (Fields::LICENSE_USES) */
	public const PUBLIC_SCAN_USE = 'online_download';
	/**
	 * The license uses that carry a link, per circle: what the notes need,
	 * and what the scan needs on top (each list: one of them is enough).
	 * Choir members keep a piece on their device under the uses of the scan.
	 */
	private const CARRYING_USES = [
		ExcerptLink::CIRCLE_MEMBERS => ['notes' => ['portal'], 'scan' => ['member_download', 'self_print']],
		ExcerptLink::CIRCLE_PUBLIC => ['notes' => ['online_publication'], 'scan' => [self::PUBLIC_SCAN_USE]],
	];
	/** License uses under which a piece goes into a songbook printed for the choir (one is enough) */
	public const SONGBOOK_USES = ['binder', 'print_station'];
	/** The circle of the concert folder: the device of the conductor or the catalog librarian, signed in (milestone 0.19) */
	public const CIRCLE_FOLDER = 'folder';
	/** License use for passing on MusicXML files: the hand-over folder and every MusicXML kept on a device (R B26) */
	public const DIGITAL_SCORE_USE = 'digital_scores';
	/**
	 * The license uses under which a copy stays with a person, per circle:
	 * printed or kept on a device (one of them is enough). The visitors of a
	 * public link hold no license: for them none counts.
	 */
	private const COPY_USES = [
		ExcerptLink::CIRCLE_MEMBERS => self::CARRYING_USES[ExcerptLink::CIRCLE_MEMBERS]['scan'],
		self::CIRCLE_FOLDER => self::CARRYING_USES[ExcerptLink::CIRCLE_MEMBERS]['scan'],
		ExcerptLink::CIRCLE_PUBLIC => [],
	];
	/**
	 * Ways out of the archive that only confirmed public domain opens, never
	 * a license: a public link kept on a device or printed, and the public
	 * repertoire page. The dialog for confirming the rights reads them from
	 * the server (MetaService, src/rightsHints.ts).
	 */
	public const FREE_ONLY_WAYS = ['public_device', 'public_print', 'public_repertoire'];

	/** @var array<int, list<License>>|null licenses by version while remembering() runs */
	private ?array $remembered = null;

	public function __construct(
		private Roles $roles,
		private RightsService $rights,
		private LicenseMapper $licenses,
		private ?PersonService $persons = null,
	) {
	}

	/**
	 * Confirmed public domain that may be published: no rights conflict
	 * with the persons recorded now (R M4; without the person register, as
	 * in some tests, there is none).
	 */
	private function freeToPublish(WorkVersion $version, string $day): bool {
		if ($version->getRightsStatus() !== 'public_domain' || !$version->isConfirmed()) {
			return false;
		}
		return $this->persons === null
			|| $this->persons->protection($version->getId(), (int)substr($day, 0, 4))['status'] !== ProtectionTerm::PROTECTED;
	}

	/**
	 * Free of every license in a circle: confirmed public domain, for the
	 * public also without a rights conflict (freeToPublish).
	 */
	private function free(string $circle, WorkVersion $version, string $day): bool {
		return $circle === ExcerptLink::CIRCLE_PUBLIC
			? $this->freeToPublish($version, $day)
			: $version->getRightsStatus() === 'public_domain' && $version->isConfirmed();
	}

	/**
	 * Runs a piece of work that asks about many versions, or many times about
	 * the same one (the page "Shared with me"): meanwhile the licenses of a
	 * version are read once (A25). Outside it every question reads them
	 * anew, so that a license saved earlier in the same request counts.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function remembering(callable $work): mixed {
		$outer = $this->remembered;
		$this->remembered ??= [];
		try {
			return $work();
		} finally {
			$this->remembered = $outer;
		}
	}

	/**
	 * @return list<License>
	 */
	private function licensesOf(WorkVersion $version): array {
		$id = $version->getId();
		if ($this->remembered === null) {
			return $this->licenses->findByVersion($id);
		}
		return $this->remembered[$id] ??= $this->licenses->findByVersion($id);
	}

	/**
	 * Whether one of the licenses covers one of the uses today.
	 *
	 * @param list<License> $licenses
	 * @param list<string> $uses
	 */
	private function coversOne(array $licenses, array $uses, string $day): bool {
		foreach ($uses as $use) {
			if ($this->rights->hasLicense($licenses, $use, $day)) {
				return true;
			}
		}
		return false;
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
	 * Whether anything of a version is an internal preservation copy, for
	 * what belongs to the version as a whole (reviews, revisions): the
	 * version is marked as one, or one of its copies is of the kind
	 * "Preservation copy" (isPreservationCopy).
	 *
	 * @param list<Copy> $copies the copies of the version
	 */
	public static function versionHasPreservationCopy(WorkVersion $version, array $copies): bool {
		if (self::isPreservationCopy($version)) {
			return true;
		}
		foreach ($copies as $copy) {
			if (self::isPreservationCopy($version, $copy)) {
				return true;
			}
		}
		return false;
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
		if ($this->rights->portalDenial($version, $this->licensesOf($version), $day) === null) {
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
		if ($denial !== null || $this->free(ExcerptLink::CIRCLE_MEMBERS, $version, $day)) {
			return $denial;
		}
		if ($this->coversOne($this->licensesOf($version), self::COPY_USES[ExcerptLink::CIRCLE_MEMBERS], $day)) {
			return null;
		}
		return 'Choir members can save and print the scan: unless public domain is confirmed, it needs a license for download and printing by choir members. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Whether a piece may be printed in a circle (R B21): printing the notes
	 * is a copy that stays with a person. The release of the circle, and
	 * unless the version is free a license for download or self-printing
	 * (COPY_USES); for the visitors of a public link only confirmed public
	 * domain without a rights conflict, as they hold no license (R B30). For
	 * the concert folder the caller checks the account first
	 * (ConcertFolderService); here only the rights.
	 *
	 * @param string $circle ExcerptLink::CIRCLE_MEMBERS, ExcerptLink::CIRCLE_PUBLIC or self::CIRCLE_FOLDER
	 * @param string $day today (YYYY-MM-DD), for the validity of licenses
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function printDenial(string $circle, WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		return $this->copyDenial($circle, $version, $day, $copy, false);
	}

	/**
	 * Whether a piece may go into a songbook (a PDF of several scans with a
	 * cover and contents, printed for the choir): that is a copy on paper
	 * for many people, like the copies for the binders. Never an internal
	 * preservation copy; otherwise confirmed public domain, or a license for
	 * copies for the binders or for the print station today.
	 *
	 * @param string $day today (YYYY-MM-DD), for the validity of licenses
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function songbookDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		if (self::isPreservationCopy($version, $copy)) {
			return 'Internal preservation copy: it does not go into a songbook.';
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return null;
		}
		if ($this->coversOne($this->licensesOf($version), self::SONGBOOK_USES, $day)) {
			return null;
		}
		return 'A songbook needs confirmed public domain or a license for copies for the binders or for the print station. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Whether a piece may be kept on a device in a circle (milestones 0.15,
	 * 0.19): the notes as MusicXML and the scan stay there for weeks, which
	 * is no viewing (§ 53 (4) UrhG). Everything printing needs, and for the
	 * notes (`$withScore`) a license for digital score files unless the
	 * version is free: a MusicXML file is a complete digital edition, which a
	 * license for printing a PDF does not cover (R B26, as in the hand-over
	 * folder). The one rule for the page, for the answers the service worker
	 * keeps (header X-Cantoria-Keep) and for the dialogs.
	 *
	 * @param string $circle ExcerptLink::CIRCLE_MEMBERS, ExcerptLink::CIRCLE_PUBLIC or self::CIRCLE_FOLDER
	 * @param bool $withScore whether the piece kept has notes (MusicXML); an item of the concert folder may have only a scan
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function deviceDenial(string $circle, WorkVersion $version, string $day, ?Copy $copy = null, bool $withScore = true): ?string {
		$denial = $this->copyDenial($circle, $version, $day, $copy, true);
		if ($denial !== null || !$withScore || $this->free($circle, $version, $day)) {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), self::DIGITAL_SCORE_USE, $day)) {
			return null;
		}
		return 'Not available without a network: unless public domain is confirmed, the notes (MusicXML) also need a license for digital score files. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * The last day a piece may stay on a device of a circle (`keepUntil`,
	 * R B8, B29): the earliest end of the licenses deviceDenial needs, for
	 * choir members also of the release of the notes. Null when nothing
	 * ends (a free version, licenses without end). Of several licenses for
	 * the same use, the one that lasts longest counts.
	 */
	public function deviceEnd(string $circle, WorkVersion $version, string $day, bool $withScore = true): ?string {
		if ($this->free($circle, $version, $day)) {
			return null;
		}
		$groups = [];
		if (isset(self::CARRYING_USES[$circle])) {
			$groups[] = self::CARRYING_USES[$circle]['notes'];
		}
		if ((self::COPY_USES[$circle] ?? []) !== []) {
			$groups[] = self::COPY_USES[$circle];
		}
		if ($withScore) {
			$groups[] = [self::DIGITAL_SCORE_USE];
		}
		return self::earliestEnd($this->licensesOf($version), $groups, $day);
	}

	/**
	 * The rule of printDenial and deviceDenial, without the one for the notes.
	 */
	private function copyDenial(string $circle, WorkVersion $version, string $day, ?Copy $copy, bool $device): ?string {
		$denial = match ($circle) {
			ExcerptLink::CIRCLE_MEMBERS => $this->memberViewDenial($version, $day, $copy),
			ExcerptLink::CIRCLE_PUBLIC => $this->publicViewDenial($version, $day, $copy),
			self::CIRCLE_FOLDER => self::isPreservationCopy($version, $copy) ? 'Internal preservation copy: it is not kept on a device.' : null,
			default => throw new \InvalidArgumentException('Unknown circle ' . $circle),
		};
		if ($denial !== null || $this->free($circle, $version, $day)) {
			return $denial;
		}
		if ($this->coversOne($this->licensesOf($version), self::COPY_USES[$circle] ?? [], $day)) {
			return null;
		}
		if ($circle === ExcerptLink::CIRCLE_PUBLIC) {
			return 'Visitors of a public link hold no license: printing the piece or keeping it on a device needs confirmed public domain without a rights conflict.';
		}
		return $device
			? 'Not available without a network: unless public domain is confirmed, a copy on a device needs a license for download or self-printing. The catalog librarian records it on the tab "Rights".'
			: 'Printing is a copy: unless public domain is confirmed, it needs a license for download or self-printing. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Playback of a link for choir members: the release for members and the
	 * rights for playback (see playDenial), where public domain counts only
	 * when it is confirmed (R B15): a license for the singers' portal
	 * releases the notes of an unconfirmed version, not a sound version of
	 * them.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function memberPlayDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->memberViewDenial($version, $day, $copy) ?? $this->playRightsDenial($version, $day, $copy);
		if ($denial !== null || $version->getRightsStatus() !== 'public_domain' || $version->isConfirmed()
			|| $this->rights->hasLicense($this->licensesOf($version), 'practice_audio', $day)) {
			return $denial;
		}
		return 'Public domain is not confirmed yet: playback for choir members needs a confirmed rights status or a license for practice audio.';
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
		// memberPlayDenial already needs confirmed public domain or a license for practice audio
		return $this->memberPlayDenial($version, $day, $copy);
	}

	/**
	 * Whether an audio file of the archive folder (a recording) may go into
	 * the hand-over folder for a choir system (module "choir_handover"):
	 * released for choir members, confirmed public domain or a license for
	 * practice audio (the rights in the work), and in every case a license
	 * for passing on recordings (`recording_sharing`). A recording has rights
	 * of its own, of the performers and of the producer (§§ 73, 77, 78, 85
	 * UrhG), which a public domain work or a license for practice audio does
	 * not cover. Scans follow memberScanDenial, as choir members can save
	 * and print them there; MusicXML follows handoverScoreDenial.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function handoverAudioDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->memberPlayDenial($version, $day, $copy);
		if ($denial !== null) {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), 'recording_sharing', $day)) {
			return null;
		}
		return 'A recording has rights of its own (performers, producer): passing it on to choir members needs a license for passing on recordings (tab "Rights").';
	}

	/**
	 * Whether a MusicXML file may go into the hand-over folder: released for
	 * choir members, and confirmed public domain or a license for passing on
	 * digital score files (`digital_scores`). A MusicXML file is a complete
	 * digital edition from which new prints and parts can be made; a license
	 * for printing a PDF does not cover it.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function handoverScoreDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->memberViewDenial($version, $day, $copy);
		if ($denial !== null || $this->free(ExcerptLink::CIRCLE_MEMBERS, $version, $day)) {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), self::DIGITAL_SCORE_USE, $day)) {
			return null;
		}
		return 'New prints and parts can be made from a MusicXML file: unless public domain is confirmed, passing it on to choir members needs a license for digital score files (tab "Rights").';
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
		if ($this->freeToPublish($version, $day)) {
			return null;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), 'online_publication', $day)) {
			return null;
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return 'Rights conflict: confirmed as public domain, but protected by the persons recorded now. Public links stay closed until the catalog librarian checks the tab "Rights".';
		}
		return $version->getRightsStatus() === 'public_domain'
			? 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then a public link can be made.'
			: 'A public link needs confirmed public domain or a valid license for online publication. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Whether a public link may offer the PDF scan, which everyone who opens
	 * the link can save and print: the release of the notes, and for a
	 * version that is not confirmed public domain a license for the download
	 * of the scan on public links. A license for online publication covers
	 * showing the notes, not handing out the whole sheet (§ 19a UrhG; for
	 * visitors § 53 (4) UrhG does not help). Checked when the link is made
	 * or extended and on every call of the scan.
	 *
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function publicScanDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->publicViewDenial($version, $day, $copy);
		if ($denial !== null || $this->freeToPublish($version, $day)) {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), self::PUBLIC_SCAN_USE, $day)) {
			return null;
		}
		return 'Everyone can save the scan of a public link: it needs confirmed public domain or a license for the download of the scan on public links. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * The last day the licenses that release a version for a circle last
	 * (milestone 0.18, R B20): for the notes, and with `$scan` also for the
	 * scan (for choir members also what they keep on their device). Null
	 * when nothing ends: confirmed public domain, a license without end, or
	 * no release at all (the denials say so). Of several licenses for the
	 * same use, the one that lasts longest counts.
	 */
	public function releaseEnd(WorkVersion $version, string $circle, bool $scan, string $day): ?string {
		$uses = self::CARRYING_USES[$circle] ?? null;
		if ($uses === null || $this->free($circle, $version, $day)) {
			return null;
		}
		return self::earliestEnd($this->licensesOf($version), $scan ? [$uses['notes'], $uses['scan']] : [$uses['notes']], $day);
	}

	/**
	 * The earliest of the last days of groups of uses (lastDay); null when
	 * none of them ends.
	 *
	 * @param list<License> $licenses
	 * @param list<list<string>> $groups
	 */
	private static function earliestEnd(array $licenses, array $groups, string $day): ?string {
		$end = null;
		foreach ($groups as $group) {
			$groupEnd = self::lastDay($licenses, $group, $day);
			if ($groupEnd !== null && ($end === null || $groupEnd < $end)) {
				$end = $groupEnd;
			}
		}
		return $end;
	}

	/**
	 * For how many people the licenses that release a version for a circle
	 * were bought (W B17): the quantities of the licenses valid today, added
	 * up, for the notes and for the scan; the smaller of the two. Null when
	 * no quantity is known: confirmed public domain, no license, or a
	 * license without a quantity. A hint for the dialogs, never a barrier.
	 */
	public function licensedQuantity(WorkVersion $version, string $circle, string $day): ?int {
		$uses = self::CARRYING_USES[$circle] ?? null;
		if ($uses === null || $this->free($circle, $version, $day)) {
			return null;
		}
		$licenses = $this->licensesOf($version);
		$least = null;
		foreach ([$uses['notes'], $uses['scan']] as $group) {
			$sum = 0;
			$covered = false;
			foreach ($licenses as $license) {
				if (array_filter($group, fn (string $use): bool => $license->covers($use, $day)) === []) {
					continue;
				}
				$quantity = $license->getQuantity();
				if ($quantity === null) {
					continue 2;
				}
				$covered = true;
				$sum += $quantity;
			}
			if ($covered && ($least === null || $sum < $least)) {
				$least = $sum;
			}
		}
		return $least;
	}

	/**
	 * The last day of the licenses that cover one of the uses today; null
	 * when one of them has no end or none covers them.
	 *
	 * @param list<License> $licenses
	 * @param list<string> $uses
	 */
	private static function lastDay(array $licenses, array $uses, string $day): ?string {
		$last = null;
		foreach ($licenses as $license) {
			foreach ($uses as $use) {
				if (!$license->covers($use, $day)) {
					continue;
				}
				$until = $license->getValidUntil();
				if ($until === null) {
					return null;
				}
				$last = $last === null || $until > $last ? $until : $last;
			}
		}
		return $last;
	}

	/**
	 * Playback on the page of a public link: a version confirmed as public
	 * domain (without a rights conflict, as for publishing) plays, any other
	 * only with a license for online playback; a license for practice audio
	 * covers the use inside the choir, not making a sound version available
	 * to everyone (§ 19a UrhG). An unconfirmed "public domain" that a
	 * license for online publication shows does not play (R B15).
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function publicPlayDenial(WorkVersion $version, string $day, ?Copy $copy = null): ?string {
		$denial = $this->publicViewDenial($version, $day, $copy);
		if ($denial !== null || $this->freeToPublish($version, $day)) {
			return $denial;
		}
		if ($this->rights->hasLicense($this->licensesOf($version), 'online_audio', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'public_domain' && !$version->isConfirmed()
			? 'Public domain is not confirmed yet: a public link plays only after the confirmation or with a license for online playback (audio), both on the tab "Rights".'
			: 'Playback on a public link needs public domain or a license for online playback (audio). The catalog librarian records it on the tab "Rights".';
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
		if ($this->rights->hasLicense($this->licensesOf($version), 'practice_audio', $day)) {
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
		if ($this->rights->hasLicense($this->licensesOf($version), 'practice_audio', $day)) {
			return null;
		}
		return 'Public domain is not confirmed yet: practice audio files need a confirmed rights status or a license for practice audio.';
	}
}
