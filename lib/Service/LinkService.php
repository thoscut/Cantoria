<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UnknownLinkException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Excerpt links: a shelf mark, printed measures, a voice as focus, verse,
 * tempo and a note, for one circle:
 *
 * - `internal`: logged-in holders of an archive role (milestone 0.3);
 * - `members`: the choir members (module member_links, milestone 0.7), for
 *   versions released for the singers' portal;
 * - `public`: everyone, without login (module public_links), for versions
 *   released for publication, without a note.
 *
 * The rights policy is checked when a link is made and again on every call,
 * so a downgraded version or an expired or revoked link shows nothing.
 * Choir members and the public get the measures of the excerpt or, for a
 * link without measures, the whole piece (see SharedLinkService); given
 * measures must exist in the score. A link for them may also offer a PDF
 * scan of the copy (`scan_file_id`): it needs the same release as the notes
 * (guideline 2: an excerpt counts as much as the whole, a scan as much as
 * the notes), and for the scan the rights to hand out a copy: for choir
 * members a license for download and printing, for the public a license for
 * the download of the scan on public links (RightsPolicy::publicScanDenial).
 *
 * How long a link lasts (milestone 0.18): at most MAX_DAYS from the day it
 * is made or extended, at most MAX_AGE_DAYS after it was made, and never
 * beyond the last day of the license that releases the piece for its circle
 * (RightsPolicy::releaseEnd). Extending never shortens a link. An expired
 * public link gets a new token when it is extended, so that an address from
 * an old message or a proxy log does not open it again.
 *
 * Tokens (milestone 0.11): the table holds their hashes (LinkTokens). The
 * token of a link for choir members or the public, a secret that opens the
 * link to anyone who has it, is shown only to its creator and the catalog
 * librarian; internal links need an archive role anyway, so every archive
 * role sees their tokens. Lists name the creator by display name, not by
 * account.
 *
 * @psalm-type LinkView = array<string, string|int|bool|null>
 * @psalm-type CircleOption = array{available: bool, allowed: bool, reason: ?string, scan: bool, scanReason: ?string, playback: bool, practiceAudio: bool, offline: ?bool,
 *     until: ?string, quantity: ?array{licensed: int, members: int}}
 * @psalm-type ScanOption = array{id: int, name: string}
 */
class LinkService {
	public const MAX_DAYS = 183;
	/** Days after it was made that a link ends at the latest, however often it is extended */
	public const MAX_AGE_DAYS = 365;
	/** Days after their last day that expired links stay in the lists, to be extended (U40) */
	public const RECENTLY_EXPIRED_DAYS = 30;
	/** Roles that make, extend and share links */
	public const CREATORS = [Roles::LIBRARIAN, Roles::CONDUCTOR, Roles::OPERATIONS];
	/** Fields a request may set when creating a link */
	private const FIELDS = ['measure_from', 'measure_to', 'voice', 'verse', 'tempo', 'note', 'task_id', 'expires', 'circle', 'scan_file_id'];
	/** The module of each circle beyond the archive roles */
	public const CIRCLE_MODULES = [
		ExcerptLink::CIRCLE_MEMBERS => Modules::MEMBER_LINKS,
		ExcerptLink::CIRCLE_PUBLIC => Modules::PUBLIC_LINKS,
	];
	/** Reasons for the dialog (English source strings, translated by the controller) */
	public const SWITCHED_OFF = 'This kind of link is switched off. An administrator can switch it on in the settings of Cantoria under "Modules".';
	public const NO_SCORE = 'This copy has no MusicXML file. Links for choir members and public links show the notes of a MusicXML file; the catalog librarian links one on the tab "Files".';

	public function __construct(
		private ExcerptLinkMapper $links,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private ArchiveFileMapper $files,
		private ScoreService $scores,
		private RightsPolicy $policy,
		private RightsService $rights,
		private AuditLog $audit,
		private Roles $roles,
		private IUserManager $userManager,
		private LinkTokens $tokens,
		private ITimeFactory $time,
		private Modules $modules,
		private ScanService $scans,
	) {
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 * @param int|null $programme the programme shared as a whole with the choir members (ProgrammeShareService); never from a request
	 */
	public function create(int $copyId, array $data, string $uid, ?int $programme = null): ExcerptLink {
		$values = Input::values($data);
		$circle = $values['circle'] ?? ExcerptLink::CIRCLE_INTERNAL;
		if (!is_string($circle) || !in_array($circle, ExcerptLink::CIRCLES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Circle']);
		}
		$module = self::CIRCLE_MODULES[$circle] ?? null;
		if ($module !== null) {
			$this->modules->requireEnabled($module);
		}
		$this->roles->requireRole($uid, self::CREATORS);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		[$version, $score, $copy] = $this->load($copyId, $uid);

		$today = $this->rights->today($uid);
		$shared = $module !== null;
		if ($shared) {
			$denial = $this->shareDenial($circle, $version, $today, $copy);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			if ($score === null) {
				throw new InvalidInputException(self::NO_SCORE);
			}
			$given = array_filter(['measure_from', 'measure_to'], fn (string $field): bool => trim((string)($values[$field] ?? '')) !== '');
			if (count($given) === 1) {
				throw new InvalidInputException('Please give both the first and the last measure of the excerpt, or leave both empty to share the whole piece.');
			}
			if ($circle === ExcerptLink::CIRCLE_PUBLIC && self::text($values['note'] ?? null, 2000) !== null) {
				throw new InvalidInputException('A public link has no note, because everyone could read it. Please leave the note empty.');
			}
		}
		$scanFileId = $values['scan_file_id'] ?? null;
		if ($scanFileId !== null && $scanFileId !== '') {
			if (!$shared) {
				throw new InvalidInputException('Only links for choir members and public links offer the scan; the archive roles see it beside the score.');
			}
			$scanDenial = $this->scanDenial($circle, $version, $today, $copy);
			if ($scanDenial !== null) {
				throw new AccessDeniedException($scanDenial);
			}
			$scanFileId = $this->checkScan($copyId, $scanFileId, $uid);
		} else {
			$scanFileId = null;
		}
		$expires = self::expiry($values['expires'] ?? null, $today, $shared ? $this->policy->releaseEnd($version, $circle, false, $today) : null);

		$link = new ExcerptLink();
		$this->tokens->issue($link);
		$link->setCopyId($copyId);
		if ($score !== null) {
			$file = $this->files->find($score['fileId']);
			$link->setFileId($file->getId());
			$link->setFileEtag($file->getEtag());
		}
		foreach (['measure_from', 'measure_to'] as $field) {
			$value = $values[$field] ?? null;
			if ($value !== null && $value !== '' && preg_match('/^[0-9A-Za-z.]{1,16}$/', (string)$value) !== 1) {
				throw new InvalidInputException('Measure numbers may only contain digits, letters and dots.');
			}
			$link->setValue($field, $value === '' ? null : ($value === null ? null : (string)$value));
		}
		$link->setValue('voice', self::text($values['voice'] ?? null, 100));
		$link->setValue('note', self::text($values['note'] ?? null, 2000));
		foreach (['verse' => [1, 99], 'tempo' => [20, 300]] as $field => [$min, $max]) {
			$value = $values[$field] ?? null;
			if ($value !== null && $value !== '') {
				$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
				if ($int === false) {
					throw new InvalidInputException('"%s": value not allowed.', [$field === 'verse' ? 'Verse' : 'Tempo']);
				}
				$link->setValue($field, $int);
			}
		}
		$taskId = $values['task_id'] ?? null;
		if ($taskId !== null && $taskId !== '') {
			$taskId = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($taskId === false) {
				throw new InvalidInputException('"%s": value not allowed.', ['Task']);
			}
		}
		$link->setValue('task_id', $taskId === '' || $shared ? null : $taskId);
		if ($shared && $score !== null) {
			// The measures must exist and the score must be one the recipients
			// can get: the same checks as when the link is opened.
			$xml = $this->scores->scoreText($this->scores->content($score['fileId'], $uid)['content'])['xml'];
			if ($link->getMeasureFrom() === null) {
				MusicXmlExcerpt::whole($xml);
			} else {
				MusicXmlExcerpt::cut($xml, (string)$link->getMeasureFrom(), (string)$link->getMeasureTo());
			}
		}
		$link->setScanFileId($scanFileId);
		$link->setEventId($circle === ExcerptLink::CIRCLE_MEMBERS ? $programme : null);
		$link->setCircle($circle);
		$link->setCreatedBy($uid);
		$link->setCreated($this->time->getTime());
		$link->setExpires($expires);
		$link = $this->links->insert($link);
		$operation = $this->audit->newOperation();
		$this->audit->record($operation, $uid, 'link', $link->getId(), 'created', 'copy_id', null, $copyId);
		if ($shared) {
			$this->audit->record($operation, $uid, 'link', $link->getId(), 'created', 'circle', null, $circle);
		}
		if ($scanFileId !== null) {
			$this->audit->record($operation, $uid, 'link', $link->getId(), 'created', 'scan_file_id', null, $scanFileId);
		}
		return $link;
	}

	/**
	 * The last day of a link: given, between today and MAX_DAYS from today
	 * (and not after `$cap`, the end of the license or the age limit of the
	 * link), or else the latest.
	 *
	 * @param string|null $cap the last day allowed besides MAX_DAYS; null: none
	 * @throws InvalidInputException
	 */
	public static function expiry(mixed $value, string $today, ?string $cap = null): string {
		$latest = self::latestExpiry($today, $cap);
		if ($value === null || $value === '') {
			return $latest;
		}
		if (!is_string($value) || !FieldValue::isDate($value) || $value < $today || $value > $latest) {
			if ($latest !== self::latestExpiry($today)) {
				throw new InvalidInputException('The expiry date must lie between today and %s: the license that releases the piece, or the age of the link, allows no later day.', [$latest]);
			}
			throw new InvalidInputException('The expiry date must lie between today and six months from now.');
		}
		return $value;
	}

	/**
	 * The latest last day a link made or extended today may have; with a
	 * cap (end of the license, age limit) not after it.
	 */
	public static function latestExpiry(string $today, ?string $cap = null): string {
		$latest = (new \DateTimeImmutable($today))->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d');
		return $cap !== null && $cap < $latest ? $cap : $latest;
	}

	/**
	 * The last day a link may last because of its age: MAX_AGE_DAYS after
	 * the day it was made, in the time zone of the server.
	 */
	public static function ageLimit(ExcerptLink $link): string {
		return (new \DateTimeImmutable('@' . $link->getCreated()))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
			->modify('+' . self::MAX_AGE_DAYS . ' days')->format('Y-m-d');
	}

	/**
	 * The last day a link may get today when it is extended: MAX_DAYS from
	 * today, the age limit and, beyond the archive roles, the end of the
	 * license that releases the piece; null when the age limit has passed.
	 */
	public function extensionLimit(ExcerptLink $link, WorkVersion $version, string $today): ?string {
		return $this->extensionBound($link, $version, $today)['until'];
	}

	/**
	 * The last day a link may get today when it is extended (extensionLimit)
	 * and what sets it (U41): `six_months` (MAX_DAYS from today), `age`
	 * (MAX_AGE_DAYS after the link was made; `until` null once that has
	 * passed) or `license` (the end of the license that releases it).
	 *
	 * @return array{until: ?string, limit: 'six_months'|'age'|'license'}
	 */
	public function extensionBound(ExcerptLink $link, WorkVersion $version, string $today): array {
		$age = self::ageLimit($link);
		if ($age < $today) {
			return ['until' => null, 'limit' => 'age'];
		}
		$end = isset(self::CIRCLE_MODULES[$link->getCircle()]) ? $this->policy->releaseEnd($version, $link->getCircle(), false, $today) : null;
		$months = self::latestExpiry($today);
		$until = min($months, $age, $end ?? $months);
		$limit = match (true) {
			$until === $months => 'six_months',
			$until === $end => 'license',
			default => 'age',
		};
		return ['until' => $until, 'limit' => $limit];
	}

	/**
	 * Extends a link to a new last day and keeps its token (milestone 0.19):
	 * what choir members keep on their devices stays, and a link sent by
	 * message keeps working. For the creator and the catalog librarian; the
	 * rights are checked again as when the link was made. An expired link
	 * can be extended, a revoked one not; an expired public link gets a new
	 * token (milestone 0.18). The new last day lies within MAX_DAYS from
	 * today, MAX_AGE_DAYS after the link was made and the end of the license
	 * that releases it, and never before the current one.
	 *
	 * `$changes` (sharing a programme): `note`, a new note for choir members
	 * (empty: the note stays), and `scan_file_id`, a scan to offer from now
	 * on, with the same checks as when a link is made. A link that offers a
	 * scan the rights of today no longer allow is not extended: a new link
	 * without the scan is needed. `programme`: the programme the link is
	 * shared with now, for "Shared with me" (U24; choir members only).
	 *
	 * @param mixed $expires the new last day; empty: the latest
	 * @param array{note?: ?string, scan_file_id?: ?int, programme?: int} $changes
	 * @throws UserFacingException
	 */
	public function extend(int $linkId, mixed $expires, string $uid, array $changes = []): ExcerptLink {
		$this->roles->requireRole($uid, self::CREATORS);
		try {
			$link = $this->links->find($linkId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($link->getCreatedBy() !== $uid && !$this->roles->hasAnyRole($uid, [Roles::LIBRARIAN])) {
			throw new AccessDeniedException('Only the creator or the catalog librarian can extend this link.');
		}
		if ($link->getRevoked() !== null) {
			throw new InvalidInputException('A revoked link cannot be extended. Please make a new one.');
		}
		$circle = $link->getCircle();
		$module = self::CIRCLE_MODULES[$circle] ?? null;
		if ($module !== null) {
			$this->modules->requireEnabled($module);
		}
		[$version, , $copy] = $this->load($link->getCopyId(), $uid);
		$today = $this->rights->today($uid);
		if ($module !== null) {
			$denial = $this->shareDenial($circle, $version, $today, $copy);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
		}
		$latest = $this->extensionLimit($link, $version, $today);
		if ($latest === null) {
			throw new InvalidInputException('This link was made more than a year ago and cannot be extended any more. Please make a new one.');
		}
		$old = $link->getExpires();
		// the same last day again (a programme shared once more): it stays, even beyond a later limit
		$new = $old !== null && $expires === $old && $old >= $today ? $old : self::expiry($expires, $today, $latest);
		if ($old !== null && $new < $old) {
			throw new InvalidInputException('The link is valid until %s already. Extending never shortens a link; to end it earlier, revoke it.', [$old]);
		}
		$note = self::text($changes['note'] ?? null, 2000);
		$scanFileId = $changes['scan_file_id'] ?? null;
		$operation = $this->audit->newOperation();
		$changed = false;
		if ($note !== null && $note !== $link->getNote()) {
			if ($circle !== ExcerptLink::CIRCLE_MEMBERS) {
				throw new InvalidInputException('A public link has no note, because everyone could read it. Please leave the note empty.');
			}
			$this->audit->record($operation, $uid, 'link', $linkId, 'extended', 'note', $link->getNote(), $note);
			$link->setNote($note);
			$changed = true;
		}
		if ($scanFileId === null && $link->getScanFileId() !== null && $module !== null) {
			// the scan it offers needs the rights of today, too
			$scanDenial = $this->scanDenial($circle, $version, $today, $copy);
			if ($scanDenial !== null) {
				throw new AccessDeniedException($scanDenial);
			}
		}
		if ($scanFileId !== null && $scanFileId !== $link->getScanFileId()) {
			if ($module === null) {
				throw new InvalidInputException('Only links for choir members and public links offer the scan; the archive roles see it beside the score.');
			}
			$scanDenial = $this->scanDenial($circle, $version, $today, $copy);
			if ($scanDenial !== null) {
				throw new AccessDeniedException($scanDenial);
			}
			$scanFileId = $this->checkScan($link->getCopyId(), $scanFileId, $uid);
			$this->audit->record($operation, $uid, 'link', $linkId, 'extended', 'scan_file_id', $link->getScanFileId(), $scanFileId);
			$link->setScanFileId($scanFileId);
			$changed = true;
		}
		$programme = $changes['programme'] ?? null;
		if ($programme !== null && $circle === ExcerptLink::CIRCLE_MEMBERS && $programme !== $link->getEventId()) {
			// where "Shared with me" lists it; no question of rights, so not in the audit log
			$link->setEventId($programme);
			$changed = true;
		}
		if ($circle === ExcerptLink::CIRCLE_PUBLIC && $old !== null && $old < $today) {
			// The old address may be in messages and logs: it stays closed.
			$this->tokens->issue($link);
			$this->audit->record($operation, $uid, 'link', $linkId, 'extended', 'token', null, 'new');
			$changed = true;
		}
		if ($new !== $old) {
			$link->setExpires($new);
			$this->audit->record($operation, $uid, 'link', $linkId, 'extended', 'expires', $old, $new);
			$licenseEnd = $module === null ? null : $this->policy->releaseEnd($version, $circle, false, $today);
			if ($licenseEnd !== null) {
				// evidence for the rights holder: until when the license carries the link
				$this->audit->record($operation, $uid, 'link', $linkId, 'extended', 'license_until', null, $licenseEnd);
			}
			$changed = true;
		}
		return $changed ? $this->links->update($link) : $link;
	}

	/**
	 * The scan a link may offer: a PDF linked to the copy (role `score_pdf`,
	 * still found) that the account itself can open beside the score.
	 *
	 * @throws InvalidInputException|AccessDeniedException
	 */
	private function checkScan(int $copyId, string|int|float|bool $value, string $uid): int {
		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($id === false) {
			throw new InvalidInputException('"%s": value not allowed.', ['Scan']);
		}
		try {
			$file = $this->files->find($id);
		} catch (DoesNotExistException) {
			$file = null;
		}
		if ($file === null || $file->getCopyId() !== $copyId || $file->getRole() !== 'score_pdf' || $file->getFound() === false) {
			throw new InvalidInputException('This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").');
		}
		// readable for the account and really a PDF
		$this->scans->scan($id, $uid);
		return $id;
	}

	/**
	 * What the dialog shows before a link for choir members or the public is
	 * made: whether the module is on, whether the rights allow it (if not,
	 * why and what to do), and whether the recipients can play the excerpt
	 * and make practice audio files.
	 *
	 * `reason` explains why the link is not possible, or else why it plays
	 * nothing; English source strings, translated by the controller.
	 * `scans` are the PDF scans of the copy that such a link may offer; an
	 * allowed link may offer them, as they need the same release as the
	 * notes.
	 *
	 * @return array{members: CircleOption, public: CircleOption, scans: list<ScanOption>}
	 */
	public function circleOptions(int $copyId, string $uid): array {
		$this->roles->requireRole($uid, self::CREATORS);
		[$version, $score, $copy] = $this->load($copyId, $uid);
		$today = $this->rights->today($uid);
		$scans = [];
		foreach ($this->files->findByCopy($copyId) as $file) {
			if ($file->getRole() === 'score_pdf' && $file->getFound() !== false) {
				$scans[] = ['id' => $file->getId(), 'name' => $file->getName()];
			}
		}
		return [
			'members' => $this->circleOption(ExcerptLink::CIRCLE_MEMBERS, $version, $copy, $score !== null, $today),
			'public' => $this->circleOption(ExcerptLink::CIRCLE_PUBLIC, $version, $copy, $score !== null, $today),
			'scans' => $scans,
		];
	}

	/**
	 * @return CircleOption
	 */
	private function circleOption(string $circle, WorkVersion $version, Copy $copy, bool $hasScore, string $today): array {
		if (!$this->modules->isEnabled(self::CIRCLE_MODULES[$circle] ?? '')) {
			return ['available' => false, 'allowed' => false, 'reason' => self::SWITCHED_OFF, 'scan' => false, 'scanReason' => null, 'playback' => false,
				'practiceAudio' => false, 'offline' => null, 'until' => null, 'quantity' => null];
		}
		$members = $circle === ExcerptLink::CIRCLE_MEMBERS;
		$denial = $this->shareDenial($circle, $version, $today, $copy) ?? ($hasScore ? null : self::NO_SCORE);
		$playDenial = $members ? $this->policy->memberPlayDenial($version, $today, $copy) : $this->policy->publicPlayDenial($version, $today, $copy);
		$scanDenial = $denial ?? $this->scanDenial($circle, $version, $today, $copy);
		$licensed = $members && $denial === null ? $this->policy->licensedQuantity($version, $circle, $today) : null;
		$memberCount = $licensed === null ? 0 : $this->roles->memberCount();
		return [
			'available' => true,
			'allowed' => $denial === null,
			'reason' => $denial ?? $playDenial,
			'scan' => $scanDenial === null,
			'scanReason' => $denial === null ? $scanDenial : null,
			'playback' => $denial === null && $playDenial === null,
			'practiceAudio' => $members && $denial === null && $playDenial === null && $this->modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO)
				&& $this->policy->memberPracticeAudioDenial($version, $today, $copy) === null,
			// whether the recipients can keep it on their device (milestone 0.19, the one rule of RightsPolicy); null: the module is off
			'offline' => !$this->modules->isEnabled(Modules::OFFLINE) ? null
				: $denial === null && $this->policy->deviceDenial($circle, $version, $today, $copy) === null,
			// the last day of the license that releases it; null: no end
			'until' => $denial === null ? $this->policy->releaseEnd($version, $circle, false, $today) : null,
			// a hint, no barrier: more choir members than the licenses are for (W B17)
			'quantity' => $licensed !== null && $memberCount > $licensed ? ['licensed' => $licensed, 'members' => $memberCount] : null,
		];
	}

	/**
	 * Why a link of a circle may not offer the scan of a version, with the
	 * next step; null when it may.
	 */
	private function scanDenial(string $circle, WorkVersion $version, string $day, Copy $copy): ?string {
		return $circle === ExcerptLink::CIRCLE_PUBLIC
			? $this->policy->publicScanDenial($version, $day, $copy)
			: $this->policy->memberScanDenial($version, $day, $copy);
	}

	/**
	 * Why a version may not be shared with a circle beyond the archive roles,
	 * with the next step; null when it may.
	 */
	private function shareDenial(string $circle, WorkVersion $version, string $day, Copy $copy): ?string {
		return $circle === ExcerptLink::CIRCLE_PUBLIC
			? $this->policy->publicViewDenial($version, $day, $copy)
			: $this->policy->memberViewDenial($version, $day, $copy);
	}

	/**
	 * The version of a copy and its score, after checking that the account
	 * may view it.
	 *
	 * @return array{0: WorkVersion, 1: array{fileId: int, fileName: string, canView: bool, canPlay: bool, reason: ?string, canExportAudio: bool, exportReason: ?string}|null, 2: Copy}
	 */
	private function load(int $copyId, string $uid): array {
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		return [$version, $this->scores->describe($version, $this->files->findByCopy($copyId), $uid, $copy), $copy];
	}

	/**
	 * Opens a link: checks expiry, revocation, the user's role and the rights
	 * policy; counts the call without recording who made it.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	public function resolve(string $token, string $uid): array {
		$this->roles->requireAccess($uid);
		try {
			$link = $this->links->findByToken($token);
		} catch (DoesNotExistException) {
			throw new UnknownLinkException('This link does not exist.');
		}
		try {
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new InvalidInputException('This link does not exist.');
		}
		if ($link->getRevoked() !== null) {
			throw new InvalidInputException('This link has been revoked.');
		}
		if ($link->getExpires() !== null && $link->getExpires() < $this->rights->today($uid)) {
			throw new InvalidInputException('This link has expired.');
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$this->links->countHit($link->getId());

		$changed = false;
		$fileId = $link->getFileId();
		if ($fileId !== null) {
			try {
				$changed = $this->files->find($fileId)->getEtag() !== $link->getFileEtag();
			} catch (DoesNotExistException) {
				$changed = true;
			}
		}
		$createdBy = $link->getCreatedBy();
		$data = $link->jsonSerialize();
		unset($data['created_by']);
		return $data + [
			'token' => $token,
			'shelfmark' => $copy->getShelfmark(),
			'createdByName' => $createdBy === null ? null : $this->userManager->getDisplayName($createdBy),
			'changed' => $changed,
		];
	}

	/**
	 * A link as lists and the dialog show it: without the account of the
	 * creator (`own` says whether it is the account's own link,
	 * `created_by_name` names the creator), with the token only for those who
	 * may pass the link on (see the class comment); null for the others and
	 * for links whose token is gone.
	 *
	 * @return LinkView
	 */
	public function view(ExcerptLink $link, string $uid): array {
		$createdBy = $link->getCreatedBy();
		$own = $createdBy !== null && $createdBy === $uid;
		$mayCopy = $link->getCircle() === ExcerptLink::CIRCLE_INTERNAL || $own || $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
		$data = $link->jsonSerialize();
		unset($data['created_by']);
		return $data + [
			'token' => $mayCopy ? $this->tokens->reveal($link) : null,
			'own' => $own,
			'created_by_name' => $createdBy === null ? null : $this->userManager->getDisplayName($createdBy),
		];
	}

	public function revoke(int $linkId, string $uid): void {
		try {
			$link = $this->links->find($linkId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($link->getCreatedBy() !== $uid && !$this->roles->hasAnyRole($uid, [Roles::LIBRARIAN])) {
			throw new AccessDeniedException('Only the creator or the catalog librarian can revoke this link.');
		}
		if ($link->getRevoked() === null) {
			$link->setRevoked($this->time->getTime());
			$this->links->update($link);
			$this->audit->record($this->audit->newOperation(), $uid, 'link', $linkId, 'revoked');
		}
	}

	/**
	 * Active links (not revoked, not expired) and, so that they can still be
	 * extended (U40), those that ended in the last RECENTLY_EXPIRED_DAYS
	 * days; optionally of one copy, as view() shows them. Links to copies the
	 * account may not view (internal preservation copies) are left out.
	 *
	 * For the links the account may extend (its own; the catalog librarian:
	 * all), `extend_until` is the latest last day the server allows today
	 * and `extend_limit` what sets it (extensionBound, U41); null for the
	 * others.
	 *
	 * @return list<LinkView>
	 */
	public function active(?int $copyId, string $uid): array {
		$today = $this->rights->today($uid);
		$since = (new \DateTimeImmutable($today))->modify('-' . self::RECENTLY_EXPIRED_DAYS . ' days')->format('Y-m-d');
		$mayViewPreservation = $this->policy->mayViewPreservationCopies($uid);
		$librarian = $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
		$versions = $this->versions->findAllById();
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$rows = [];
		foreach ($this->links->findByCopy($copyId) as $link) {
			if ($link->getRevoked() !== null || ($link->getExpires() !== null && $link->getExpires() < $since)) {
				continue;
			}
			$copy = $copies[$link->getCopyId()] ?? null;
			$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
			if (!$mayViewPreservation && ($copy === null || $version === null || RightsPolicy::isPreservationCopy($version, $copy))) {
				continue;
			}
			$bound = $version !== null && ($librarian || $link->getCreatedBy() === $uid)
				? $this->extensionBound($link, $version, $today) : ['until' => null, 'limit' => null];
			$rows[] = $this->view($link, $uid) + ['extend_until' => $bound['until'], 'extend_limit' => $bound['limit']];
		}
		return $rows;
	}

	private static function text(string|int|float|bool|null $value, int $max): ?string {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if (!is_string($value) || mb_strlen($value) > $max) {
			throw new InvalidInputException('The text is too long.');
		}
		return $value;
	}
}
