<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UnknownLinkException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;

/**
 * Opens links for the circles beyond the archive roles (milestone 0.7):
 * the choir members (module member_links) and the public (module
 * public_links). Links are made in LinkService.
 *
 * Choir members and the public never get the score file: the server reads
 * it through the account that configured the archive folder (only inside
 * that folder, like the background jobs), unpacks `.mxl` and sends only the
 * content for the view: the measures of the excerpt (MusicXmlExcerpt::cut)
 * or, for a link without measures, the whole piece with its full playing
 * order (MusicXmlExcerpt::whole). A link may also offer the PDF scan chosen
 * when it was made, read the same way; it needs the same release as the
 * notes. The rights policy is checked on every call; calls of the score are
 * counted without recording who made them.
 *
 * What they see: title and composer of the work, the measures (null for the
 * whole piece), voice, verse and tempo of the link, for choir members also
 * its note. Never a shelf mark, an account name or an internal note of the
 * catalog. Since milestone 0.11 also the names of the lyricists, arrangers
 * and translators from the person register (names only, no life dates; the
 * arranger text of the version when the register has no arranger) and the
 * notices of the licenses valid today ("Notice on shared pages"), so that
 * the authors are named (§ 13 UrhG) and a license's condition can be met.
 *
 * The scan of a public link needs its own license (milestone 0.18, R B2):
 * the download of the scan on public links, unless public domain is
 * confirmed (RightsPolicy::publicScanDenial), checked on every call.
 * Choir members learn until when a copy may stay on their device
 * (`keepUntil`, the end of the license for download and printing), so that
 * the device drops it then, even while the link lasts longer (R B8, B20).
 *
 * The page lists the pieces by programme (milestone 0.20, U24): `programmes`
 * of a link names the programmes it was shared with as a whole, with title,
 * date, the place of the programme on the page and of the piece in it
 * (memberProgrammes()). Programmes appear with a key, not with their id
 * (PageKeys, an HMAC with the secret of the instance).
 *
 * @psalm-type Credit = array{role: string, name: string}
 * @psalm-type MemberProgramme = array{key: string, title: string, date: ?string, order: int, position: int}
 * @psalm-type MemberLink = array{token: string, title: string, composer: string, measure_from: ?string, measure_to: ?string,
 *     voice: ?string, verse: ?int, tempo: ?int, note: ?string, expires: ?string, canPlay: bool, canExportAudio: bool, reason: ?string,
 *     scan: bool, scanSize: ?int, offline: bool, keepUntil: ?string, print: bool, practiceKey: string, credits: list<Credit>, notices: list<string>,
 *     programmes: list<MemberProgramme>}
 * @psalm-type PublicExcerpt = array{title: string, composer: string, measureFrom: ?string, measureTo: ?string, voice: ?string,
 *     verse: ?int, tempo: ?int, canPlay: bool, scan: bool, scanSize: ?int, practiceKey: string, expires: ?string, offline: bool, offlineModule: bool, print: bool, served: string, xml: string,
 *     credits: list<Credit>, notices: list<string>}
 */
class SharedLinkService {
	/** Roles of the person register named on the shared pages besides the composer */
	private const CREDITED_ROLES = ['lyricist', 'arranger', 'translator'];

	public function __construct(
		private ExcerptLinkMapper $links,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ArchiveFileMapper $files,
		private ScoreService $scores,
		private RightsPolicy $policy,
		private RightsService $rights,
		private Roles $roles,
		private Modules $modules,
		private AppSettings $settings,
		private LinkTokens $tokens,
		private ContributionMapper $contributions,
		private PersonMapper $persons,
		private LicenseMapper $licenses,
		private EventMapper $events,
		private EventItemMapper $items,
		private PageKeys $keys,
	) {
	}

	/**
	 * The links for choir members that are active and still allowed, newest
	 * first: what the page "Shared with me" lists. `reason` (English source
	 * string) says why the piece cannot be played; `scan` whether the link
	 * offers the scan; `programmes` under which programmes the page lists it.
	 *
	 * @return list<MemberLink>
	 * @throws AccessDeniedException when the account is no choir member
	 */
	public function forMember(string $uid): array {
		$this->requireMember($uid);
		$today = $this->rights->today($uid);
		$rows = [];
		/** @var list<array{copy: int, version: int, programme: ?int}> $pieces per row */
		$pieces = [];
		foreach ($this->links->findUnrevokedByCircle(ExcerptLink::CIRCLE_MEMBERS) as $link) {
			$token = $this->tokens->reveal($link);
			if ($token === null || self::expired($link, $today)) {
				continue;
			}
			try {
				$copy = $this->copies->find($link->getCopyId());
				$version = $this->versions->find($copy->getVersionId());
				$work = $this->works->find($version->getWorkId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($this->policy->memberViewDenial($version, $today, $copy) !== null) {
				continue;
			}
			$playDenial = $this->policy->memberPlayDenial($version, $today, $copy);
			$saveDenial = $this->policy->memberScanDenial($version, $today, $copy);
			$rows[] = [
				'token' => $token,
				'title' => (string)$work->getTitle(),
				'composer' => (string)$work->getComposer(),
				'measure_from' => $link->getMeasureFrom(),
				'measure_to' => $link->getMeasureTo(),
				'voice' => $link->getVoice(),
				'verse' => $link->getVerse(),
				'tempo' => $link->getTempo(),
				'note' => $link->getNote(),
				'expires' => $link->getExpires(),
				'canPlay' => $playDenial === null,
				'canExportAudio' => $playDenial === null && $this->modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO)
					&& $this->policy->memberPracticeAudioDenial($version, $today, $copy) === null,
				'reason' => $playDenial,
				'scan' => $link->getScanFileId() !== null && $saveDenial === null,
				'scanSize' => $saveDenial === null ? $this->scanSize($link) : null,
				// kept on the device (milestone 0.15): a copy, like saving the scan
				'offline' => $saveDenial === null && $this->modules->isEnabled(Modules::OFFLINE),
				// the last day the license allows a copy on the device; null: no end
				'keepUntil' => $saveDenial === null ? $this->policy->releaseEnd($version, ExcerptLink::CIRCLE_MEMBERS, true, $today) : null,
				// printing the notes is a copy, too (B21): the same rights as saving the scan
				'print' => $saveDenial === null,
				'practiceKey' => $this->keys->practice($version),
			] + $this->attribution($version, $today);
			$pieces[] = ['copy' => $copy->getId(), 'version' => $version->getId(), 'programme' => $link->getEventId()];
		}
		$programmes = $this->memberProgrammes($pieces, $today);
		$listed = [];
		foreach ($rows as $index => $row) {
			$listed[] = $row + ['programmes' => $programmes[$index] ?? []];
		}
		return $listed;
	}

	/**
	 * The programmes under which "Shared with me" lists each link (U24). A
	 * link that came with "Share the programme with the choir" knows its
	 * programme; it stands under every programme shared that way that holds
	 * its piece (its copy, or its version for an item without a copy), at
	 * the place of the piece among the items not struck, so that a piece of
	 * two concerts is found in both. Where a programme has a link of its own
	 * for a piece, other links to it stay out of that programme. A link whose
	 * programme no longer holds its piece, and every link made on its own,
	 * stands under none.
	 *
	 * Order on the page: the next programme first, then the later ones;
	 * past programmes after them, the latest first; those without a date at
	 * the end.
	 *
	 * @param list<array{copy: int, version: int, programme: ?int}> $pieces per row
	 * @return list<list<MemberProgramme>> per row
	 */
	private function memberProgrammes(array $pieces, string $today): array {
		$programmes = [];
		foreach (array_unique(array_filter(array_column($pieces, 'programme'), fn (?int $id): bool => $id !== null)) as $id) {
			try {
				$programmes[] = $this->events->find($id);
			} catch (DoesNotExistException) {
			}
		}
		usort($programmes, fn (Event $a, Event $b): int => self::compareProgrammes($a, $b, $today));
		$listed = array_fill(0, count($pieces), []);
		foreach ($programmes as $order => $event) {
			$items = array_values(array_filter($this->items->findByEvent($event->getId()), fn (EventItem $item): bool => $item->getStruck() !== true));
			/** @var array<int, int> $placed row => position */
			$placed = [];
			/** @var array<int, true> $own positions with a link of this programme */
			$own = [];
			foreach ($pieces as $row => $piece) {
				if ($piece['programme'] === null) {
					continue;
				}
				foreach ($items as $position => $item) {
					if (self::holds($item, $piece['copy'], $piece['version'])) {
						$placed[$row] = $position;
						if ($piece['programme'] === $event->getId()) {
							$own[$position] = true;
						}
						break;
					}
				}
			}
			$key = $this->keys->programme($event->getId());
			foreach ($placed as $row => $position) {
				if (!isset($own[$position]) || $pieces[$row]['programme'] === $event->getId()) {
					$listed[$row][] = ['key' => $key, 'title' => (string)$event->getTitle(), 'date' => $event->getEventDate(),
						'order' => $order, 'position' => $position];
				}
			}
		}
		return array_values($listed);
	}

	/**
	 * Whether an item of a programme is the piece of a link.
	 */
	private static function holds(EventItem $item, int $copyId, int $versionId): bool {
		$itemCopy = $item->getCopyId();
		return $itemCopy !== null ? $itemCopy === $copyId : $item->getVersionId() === $versionId;
	}

	/**
	 * Programmes still to come by date, then past ones from the latest, then
	 * those without a date; the same date by id.
	 */
	private static function compareProgrammes(Event $a, Event $b, string $today): int {
		$when = fn (Event $event): int => $event->getEventDate() === null ? 2 : ((string)$event->getEventDate() < $today ? 1 : 0);
		$byWhen = $when($a) <=> $when($b);
		if ($byWhen !== 0) {
			return $byWhen;
		}
		$byDate = (string)$a->getEventDate() <=> (string)$b->getEventDate();
		return ($when($a) === 1 ? -$byDate : $byDate) ?: $a->getId() <=> $b->getId();
	}

	/**
	 * The measures of a link for choir members, or the whole piece, as
	 * MusicXML; counts the call.
	 *
	 * @throws UserFacingException
	 */
	public function memberScore(string $token, string $uid): string {
		['link' => $link] = $this->memberLink($token, $uid);
		$xml = $this->score($link);
		$this->links->countHit($link->getId());
		return $xml;
	}

	/**
	 * Whether the notes and the scan of a link for choir members may stay on
	 * the device of the member (header X-Cantoria-Keep, which the service
	 * worker follows; S N5): the module "offline" and the rights for a copy,
	 * checked again with every answer, so that a license that ended
	 * between two checks keeps nothing new on the device.
	 */
	public function memberMayKeep(string $token, string $uid): bool {
		if (!$this->modules->isEnabled(Modules::OFFLINE)) {
			return false;
		}
		try {
			['copy' => $copy, 'version' => $version] = $this->memberLink($token, $uid);
		} catch (UserFacingException) {
			return false;
		}
		return $this->policy->memberScanDenial($version, $this->rights->today($uid), $copy) === null;
	}

	/**
	 * Whether the page and the scan of a public link may stay on the device
	 * of a visitor (header X-Cantoria-Keep): the module "offline" and
	 * confirmed public domain, as visitors have no license.
	 */
	public function publicMayKeep(string $token): bool {
		if (!$this->modules->isEnabled(Modules::OFFLINE)) {
			return false;
		}
		$open = $this->publicLink($token);
		return $open !== null && self::publicDomainConfirmed($open['version']);
	}

	private static function publicDomainConfirmed(WorkVersion $version): bool {
		return $version->getRightsStatus() === 'public_domain' && $version->isConfirmed();
	}

	/**
	 * The scan a link for choir members offers, after the same checks as
	 * the score.
	 *
	 * @throws UserFacingException
	 */
	public function memberScan(string $token, string $uid): File {
		['link' => $link, 'copy' => $copy, 'version' => $version] = $this->memberLink($token, $uid);
		$denial = $this->policy->memberScanDenial($version, $this->rights->today($uid), $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		return $this->scan($link);
	}

	/**
	 * An active link for choir members with its copy and version, after
	 * checking the account and the rights; for the score, the scan and
	 * practice audio files.
	 *
	 * @return array{link: ExcerptLink, copy: Copy, version: WorkVersion}
	 * @throws UserFacingException
	 */
	public function memberLink(string $token, string $uid): array {
		$this->requireMember($uid);
		try {
			$link = $this->links->findByToken($token);
		} catch (DoesNotExistException) {
			throw new UnknownLinkException('This link does not exist.');
		}
		if ($link->getCircle() !== ExcerptLink::CIRCLE_MEMBERS) {
			// Internal and public links are not opened here; the same answer as for an unknown token.
			throw new UnknownLinkException('This link does not exist.');
		}
		if ($link->getRevoked() !== null) {
			throw new InvalidInputException('This link has been revoked.');
		}
		$today = $this->rights->today($uid);
		if (self::expired($link, $today)) {
			throw new InvalidInputException('This link has expired.');
		}
		try {
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('This piece is no longer shared with choir members.');
		}
		if ($this->policy->memberViewDenial($version, $today, $copy) !== null) {
			throw new AccessDeniedException('This piece is no longer shared with choir members.');
		}
		return ['link' => $link, 'copy' => $copy, 'version' => $version];
	}

	/**
	 * What the page of a public link shows, or null for a token that is
	 * unknown, of another circle, revoked or expired, or whose version is no
	 * longer released for publication: the caller answers all of them with
	 * the same 404. Counts the call.
	 *
	 * @return PublicExcerpt|null
	 */
	public function publicExcerpt(string $token): ?array {
		$open = $this->publicLink($token);
		if ($open === null) {
			return null;
		}
		['link' => $link, 'copy' => $copy, 'version' => $version, 'work' => $work, 'today' => $today] = $open;
		try {
			$xml = $this->score($link);
		} catch (UserFacingException) {
			return null;
		}
		$this->links->countHit($link->getId());
		$scan = $link->getScanFileId() !== null && $this->policy->publicScanDenial($version, $today, $copy) === null;
		return [
			'title' => (string)$work->getTitle(),
			'composer' => (string)$work->getComposer(),
			'measureFrom' => $link->getMeasureFrom(),
			'measureTo' => $link->getMeasureTo(),
			'voice' => $link->getVoice(),
			'verse' => $link->getVerse(),
			'tempo' => $link->getTempo(),
			'canPlay' => $this->policy->publicPlayDenial($version, $today, $copy) === null,
			'scan' => $scan,
			'scanSize' => $scan ? $this->scanSize($link) : null,
			'practiceKey' => $this->keys->practice($version),
			// Kept on a device (milestone 0.15): only confirmed public domain, as visitors have no license
			'expires' => $link->getExpires(),
			'offline' => $this->modules->isEnabled(Modules::OFFLINE) && self::publicDomainConfirmed($version),
			// the module apart from this piece (A24): a piece that may not be kept removes only itself
			// from the device, only a module switched off removes every public piece kept there
			'offlineModule' => $this->modules->isEnabled(Modules::OFFLINE),
			// printing is a copy (B21): only confirmed public domain, as for keeping it
			'print' => self::publicDomainConfirmed($version),
			'served' => gmdate('Y-m-d\TH:i:s\Z'),
			'xml' => $xml,
		] + $this->attribution($version, $today);
	}

	/**
	 * The scan a public link offers, or null in every case in which the page
	 * of the link is refused, when the rights do not allow everyone a copy of
	 * the scan (RightsPolicy::publicScanDenial), and when the link offers no
	 * scan or it cannot be read: the caller answers all of them with the same
	 * 404.
	 */
	public function publicScan(string $token): ?File {
		$open = $this->publicLink($token);
		if ($open === null || $this->policy->publicScanDenial($open['version'], $open['today'], $open['copy']) !== null) {
			return null;
		}
		try {
			return $this->scan($open['link']);
		} catch (UserFacingException) {
			return null;
		}
	}

	/**
	 * A public link that may be shown today, with its version and work; null
	 * otherwise.
	 *
	 * @return array{link: ExcerptLink, copy: Copy, version: WorkVersion, work: Work, today: string}|null
	 */
	private function publicLink(string $token): ?array {
		try {
			$link = $this->links->findByToken($token);
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
			$work = $this->works->find($version->getWorkId());
		} catch (DoesNotExistException) {
			return null;
		}
		$today = $this->rights->today(null);
		if ($link->getCircle() !== ExcerptLink::CIRCLE_PUBLIC || $link->getRevoked() !== null || self::expired($link, $today)
			|| $this->policy->publicViewDenial($version, $today, $copy) !== null) {
			return null;
		}
		return ['link' => $link, 'copy' => $copy, 'version' => $version, 'work' => $work, 'today' => $today];
	}

	/**
	 * The authors to name besides the composer and the notices the licenses
	 * ask for (milestone 0.11).
	 *
	 * @return array{credits: list<array{role: string, name: string}>, notices: list<string>}
	 */
	private function attribution(WorkVersion $version, string $today): array {
		$credits = [];
		$contributions = $this->contributions->findByVersion($version->getId());
		foreach (self::CREDITED_ROLES as $role) {
			foreach ($contributions as $contribution) {
				if ($contribution->getRole() !== $role) {
					continue;
				}
				try {
					$credits[] = ['role' => $role, 'name' => $this->persons->find($contribution->getPersonId())->getName()];
				} catch (DoesNotExistException) {
				}
			}
		}
		$arranger = trim((string)$version->getArrangerText());
		if ($arranger !== '' && !in_array('arranger', array_column($credits, 'role'), true)) {
			$credits[] = ['role' => 'arranger', 'name' => $arranger];
		}
		$notices = [];
		foreach ($this->licenses->findByVersion($version->getId()) as $license) {
			$notice = trim((string)$license->getShareNotice());
			if ($notice !== '' && ($license->getValidFrom() === null || $license->getValidFrom() <= $today)
				&& ($license->getValidUntil() === null || $license->getValidUntil() >= $today)) {
				$notices[] = $notice;
			}
		}
		return ['credits' => $credits, 'notices' => array_values(array_unique($notices))];
	}

	/**
	 * What a link shows of the current score of its copy: the measures of
	 * the excerpt, or the whole piece for a link without measures.
	 *
	 * @throws UserFacingException
	 */
	private function score(ExcerptLink $link): string {
		$notAvailable = new NotFoundException('The score of this link is not available at the moment.');
		$file = ScoreService::pick($this->files->findByCopy($link->getCopyId()));
		if ($file === null) {
			throw $notAvailable;
		}
		$content = UserFiles::content($this->archiveNode($file->getFileId(), $notAvailable), ScoreService::MAX_BYTES, $notAvailable);
		if (!$this->scores->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		$xml = $this->scores->scoreText($content)['xml'];
		$from = $link->getMeasureFrom();
		$to = $link->getMeasureTo();
		return $from === null || $to === null ? MusicXmlExcerpt::whole($xml) : MusicXmlExcerpt::cut($xml, $from, $to);
	}

	/**
	 * Size in bytes of the scan of a link, as the file sync last saw it, for
	 * the switch "With the scan (PDF, 2.4 MB)"; null without a scan or when
	 * unknown.
	 */
	private function scanSize(ExcerptLink $link): ?int {
		$id = $link->getScanFileId();
		if ($id === null) {
			return null;
		}
		try {
			return $this->files->find($id)->getSize();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The scan of a link: still a PDF of its copy (role `score_pdf`), read
	 * through the account of the archive folder.
	 *
	 * @throws UserFacingException
	 */
	private function scan(ExcerptLink $link): File {
		$notAvailable = new NotFoundException('The scan of this link is not available at the moment.');
		$id = $link->getScanFileId();
		if ($id === null) {
			throw $notAvailable;
		}
		try {
			$file = $this->files->find($id);
		} catch (DoesNotExistException) {
			throw $notAvailable;
		}
		if ($file->getCopyId() !== $link->getCopyId() || $file->getRole() !== 'score_pdf' || $file->getFound() === false) {
			throw $notAvailable;
		}
		$node = $this->archiveNode($file->getFileId(), $notAvailable);
		if (!ScanService::isPdf($node)) {
			throw $notAvailable;
		}
		return $node;
	}

	/**
	 * A file of the archive folder, read through the account that configured
	 * it and only inside it.
	 *
	 * @throws NotFoundException
	 */
	private function archiveNode(int $fileId, NotFoundException $notAvailable): File {
		$account = $this->settings->archiveInfo()['user'];
		if ($account === '') {
			throw $notAvailable;
		}
		$node = $this->settings->archiveFolder($account)->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			throw $notAvailable;
		}
		return $node;
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function requireMember(string $uid): void {
		if (!$this->roles->isMember($uid)) {
			throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
		}
	}

	private static function expired(ExcerptLink $link, string $today): bool {
		return $link->getExpires() !== null && $link->getExpires() < $today;
	}
}
