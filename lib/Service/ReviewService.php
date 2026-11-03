<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ReviewPoint;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\TranscriptionReport;
use OCA\Cantoria\Db\TranscriptionReportMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reviewing transcriptions (milestone 0.6, module "transcription_review"):
 * reads reports of transcriptions into review points, lets the responsible
 * roles decide them and lists the correction orders for the tool chain.
 *
 * - Reports come through the file sync (a file named `…bericht.json`) or the
 *   API. The same report (same checksum) is read only once per copy.
 * - Musical points go to the conductor, the material and questions without a
 *   place in the score to the catalog librarian; the catalog librarian may
 *   decide every point (ROADMAP 0.6).
 * - Rights and catalog fields of a report become proposals in the inbox,
 *   never changes of the catalog (guidelines 1 and 6).
 * - A new report of the copy closes the points it no longer names when the
 *   MusicXML has changed since (checksums).
 * - Nothing is deleted; every step is recorded in the audit log.
 *
 * @psalm-import-type Warning from ImportService
 * @psalm-import-type ParsedReport from TranscriptionReportParser
 * @psalm-import-type ReportPoint from TranscriptionReportParser
 * @psalm-type IngestResult = array{report: TranscriptionReport, unchanged: bool, points: array{created: int, kept: int, closed: int, open: int},
 *     proposals: int, proposalsSkipped: bool, warnings: list<Warning>}
 */
class ReviewService {
	public const MODULE = 'transcription_review';
	/** Roles that hand in reports */
	public const INGESTERS = [Roles::LIBRARIAN, Roles::CONTRIBUTOR];
	/** Statuses a person may set; `corrected` is set by the app only */
	public const DECISIONS = [ReviewPoint::OPEN, ReviewPoint::CONFIRMED, ReviewPoint::CORRECT, ReviewPoint::ASKED, ReviewPoint::DISMISSED];

	public function __construct(
		private IDBConnection $db,
		private ReviewPointMapper $points,
		private TranscriptionReportMapper $reports,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ArchiveFileMapper $files,
		private AuditLog $audit,
		private Roles $roles,
		private Modules $modules,
		private NotificationService $notifications,
		private ProposalService $proposals,
		private TaskService $tasks,
		private AppSettings $settings,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads a report sent through the API for a copy of the version.
	 *
	 * @return IngestResult
	 * @throws InvalidInputException|AccessDeniedException
	 */
	public function ingest(int $versionId, string $raw, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireRole($uid, self::INGESTERS);
		$version = $this->loadVersion($versionId);
		$parsed = TranscriptionReportParser::parse($raw);
		$copy = null;
		foreach ($this->copies->findByVersion($version->getId()) as $candidate) {
			if (TranscriptionReportParser::canonicalShelfmark($candidate->getShelfmark()) === $parsed['report']['signatur']) {
				$copy = $candidate;
				break;
			}
		}
		if ($copy === null) {
			throw new InvalidInputException('The shelf mark of the report does not belong to this version.');
		}
		return $this->store($copy, $version, $raw, $parsed, TranscriptionReport::SOURCE_API, null, $uid);
	}

	/**
	 * Reads a report sent through the API for agents (v1) for one copy: the
	 * report must name exactly this copy, not another copy of its version.
	 *
	 * @return IngestResult
	 * @throws InvalidInputException|AccessDeniedException|NotFoundException
	 */
	public function ingestForCopy(int $copyId, string $raw, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireRole($uid, self::INGESTERS);
		try {
			$copy = $this->copies->find($copyId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$parsed = TranscriptionReportParser::parse($raw);
		if (TranscriptionReportParser::canonicalShelfmark($copy->getShelfmark()) !== $parsed['report']['signatur']) {
			throw new InvalidInputException('The shelf mark of the report does not belong to this copy.');
		}
		return $this->store($copy, $this->loadVersion($copy->getVersionId()), $raw, $parsed, TranscriptionReport::SOURCE_API, null, $uid);
	}

	/**
	 * Reads a report file found by the file sync for its copy.
	 *
	 * @return IngestResult
	 * @throws InvalidInputException|AccessDeniedException
	 */
	public function ingestFile(ArchiveFile $file, Copy $copy, string $raw, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireRole($uid, self::INGESTERS);
		$parsed = TranscriptionReportParser::parse($raw);
		if (TranscriptionReportParser::canonicalShelfmark($copy->getShelfmark()) !== $parsed['report']['signatur']) {
			throw new InvalidInputException('The shelf mark of the report does not match its file name.');
		}
		return $this->store($copy, $this->loadVersion($copy->getVersionId()), $raw, $parsed, TranscriptionReport::SOURCE_FILE, $file->getId(), $uid);
	}

	/**
	 * Reports, points and counts of a version, with what the account may do.
	 *
	 * @return array{version_id: int, reports: list<TranscriptionReport>, points: list<ReviewPoint>, counts: array<string, int>,
	 *     may: array{decideMusical: bool, decideMaterial: bool, ingest: bool, correct: bool}}
	 */
	public function forVersion(int $versionId, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireAccess($uid);
		$version = $this->loadVersion($versionId);
		if (!$this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)
			&& RightsPolicy::versionHasPreservationCopy($version, $this->copies->findByVersion($version->getId()))) {
			throw new AccessDeniedException('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		}
		$points = $this->points->findByVersion($version->getId());
		$counts = array_fill_keys(ReviewPoint::STATUSES, 0);
		foreach ($points as $point) {
			$counts[$point->getStatus()] = ($counts[$point->getStatus()] ?? 0) + 1;
		}
		return [
			'version_id' => $version->getId(),
			'reports' => $this->reports->findByVersion($version->getId()),
			'points' => $points,
			'counts' => $counts,
			'may' => [
				'decideMusical' => $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN, Roles::CONDUCTOR]),
				'decideMaterial' => $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]),
				'ingest' => $this->roles->hasAnyRole($uid, self::INGESTERS),
				'correct' => $this->modules->isEnabled('corrections') && $this->roles->hasAnyRole($uid, CorrectionService::CORRECTORS)
					&& $version->getPreservationCopy() !== true,
			],
		];
	}

	/**
	 * Decides a review point. `question` turns it into a task (module
	 * "tasks") with the point's place.
	 *
	 * @throws InvalidInputException|AccessDeniedException
	 */
	public function decide(int $pointId, string $status, ?string $correction, ?string $reason, ?string $addressee, string $uid): ReviewPoint {
		$this->modules->requireEnabled(self::MODULE);
		$point = $this->loadPoint($pointId);
		if (!self::mayDecide($this->roles->rolesOf($uid), $point->getRole())) {
			throw new AccessDeniedException('Only the catalog librarian decides this review point.');
		}
		self::checkTransition($point->getStatus(), $status);
		$reason = self::optionalText($reason);
		$correction = self::optionalText($correction);
		if ($reason !== null && mb_strlen($reason) > 10000) {
			throw new InvalidInputException('"%s" is too long.', ['Reason']);
		}
		if ($correction !== null && mb_strlen($correction) > 10000) {
			throw new InvalidInputException('"%s" is too long.', ['Correction']);
		}
		if ($status === ReviewPoint::CORRECT && $correction === null) {
			throw new InvalidInputException('Please describe the correction.');
		}
		if ($status === ReviewPoint::DISMISSED && $reason === null) {
			throw new InvalidInputException('Please give a reason.');
		}

		$old = $point->getStatus();
		$this->db->beginTransaction();
		try {
			if ($status === ReviewPoint::ASKED) {
				$this->modules->requireEnabled('tasks');
				$point->setTaskId($this->ask($point, $reason, $addressee, $uid));
			}
			if ($status === ReviewPoint::CORRECT) {
				$point->setCorrection($correction);
			}
			$point->setStatus($status);
			$point->setDecidedBy($uid);
			$point->setDecidedByName($this->userManager->getDisplayName($uid) ?? $uid);
			$point->setDecided($this->time->getTime());
			$point->setDecisionReason($reason);
			$this->points->update($point);
			$this->audit->record($this->audit->newOperation(), $uid, 'review_point', $point->getId(), 'decided', 'status', $old, $status,
				$status === ReviewPoint::CORRECT ? $correction : $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->withdrawIfDone($point);
		return $point;
	}

	/**
	 * The correction orders for the tool chain, oldest first. Each row is the
	 * point plus shelf mark, title, report base name, file name and whether
	 * the version is an internal preservation copy; roles that may not view
	 * preservation copies do not get those rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function orders(string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireAccess($uid);
		$mayViewPreservation = $this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS);
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$rows = [];
		foreach ($this->points->findByStatus([ReviewPoint::CORRECT]) as $point) {
			$copy = $copies[$point->getCopyId()] ?? null;
			$version = $versions[$point->getVersionId()] ?? null;
			if ($version !== null && RightsPolicy::isPreservationCopy($version, $copy) && !$mayViewPreservation) {
				continue;
			}
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$report = null;
			$file = null;
			$reportId = $point->getReportId();
			$fileId = $point->getFileId();
			try {
				$report = $reportId === null ? null : $this->reports->find($reportId);
				$file = $fileId === null ? null : $this->files->find($fileId);
			} catch (DoesNotExistException) {
			}
			$rows[] = $point->jsonSerialize() + [
				'shelfmark' => $copy?->getShelfmark(),
				'title' => $work?->getTitle(),
				'basename' => $report?->getBasename(),
				'file_name' => $file?->getName(),
				'preservation_copy' => $version?->getPreservationCopy() === true,
			];
		}
		return $rows;
	}

	/**
	 * Sets points of a version to `corrected` after a correction in the app.
	 * Runs inside the caller's transaction.
	 *
	 * @param list<int> $pointIds
	 * @return list<int> the ids set to corrected
	 * @throws InvalidInputException when a point does not belong to the version or is decided already
	 */
	public function markCorrected(array $pointIds, int $versionId, string $uid, string $note, string $operation): array {
		if ($pointIds === [] || !$this->modules->isEnabled(self::MODULE)) {
			return [];
		}
		$done = [];
		foreach (array_values(array_unique($pointIds)) as $pointId) {
			$point = $this->loadPoint($pointId);
			if ($point->getVersionId() !== $versionId || !in_array($point->getStatus(), ReviewPoint::UNRESOLVED, true)) {
				throw new InvalidInputException('Review point %s cannot be set to corrected.', [$pointId]);
			}
			$old = $point->getStatus();
			$point->setStatus(ReviewPoint::CORRECTED);
			$point->setDecidedBy($uid);
			$point->setDecidedByName($this->userManager->getDisplayName($uid) ?? $uid);
			$point->setDecided($this->time->getTime());
			$point->setDecisionReason($note);
			$this->points->update($point);
			$this->audit->record($operation, $uid, 'review_point', $point->getId(), 'corrected', 'status', $old, ReviewPoint::CORRECTED, $note);
			$this->withdrawIfDone($point);
			$done[] = $point->getId();
		}
		return $done;
	}

	/**
	 * Whether an account with these roles may decide a point of a role: the
	 * catalog librarian decides everything, the conductor the musical points.
	 *
	 * @param list<string> $roles roles of the account
	 */
	public static function mayDecide(array $roles, string $pointRole): bool {
		return in_array(Roles::LIBRARIAN, $roles, true)
			|| ($pointRole === Roles::CONDUCTOR && in_array(Roles::CONDUCTOR, $roles, true));
	}

	/**
	 * Checks a decision: only the statuses a person may set, and a change.
	 *
	 * @throws InvalidInputException
	 */
	public static function checkTransition(string $from, string $to): void {
		if ($to === ReviewPoint::CORRECTED) {
			throw new InvalidInputException('A review point is set to corrected only by a correction or a new version.');
		}
		if (!in_array($to, self::DECISIONS, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Status']);
		}
		if ($from === $to) {
			throw new InvalidInputException('The review point already has this status.');
		}
	}

	/**
	 * @param array{report: ParsedReport, warnings: list<Warning>} $parsed
	 * @return IngestResult
	 */
	private function store(Copy $copy, WorkVersion $version, string $raw, array $parsed, string $source, ?int $reportFileId, string $uid): array {
		$report = $parsed['report'];
		$warnings = $parsed['warnings'];
		$sha256 = hash('sha256', $raw);
		$known = $this->reports->findByChecksum($copy->getId(), $sha256);
		if ($known !== null) {
			if ($reportFileId !== null && $known->getReportFileId() === null) {
				$known->setReportFileId($reportFileId);
				$this->reports->update($known);
			}
			return ['report' => $known, 'unchanged' => true, 'points' => ['created' => 0, 'kept' => 0, 'closed' => 0,
				'open' => count($this->points->findByCopy($copy->getId(), [ReviewPoint::OPEN]))],
				'proposals' => 0, 'proposalsSkipped' => false, 'warnings' => $warnings];
		}

		$musicxml = $this->musicxmlOf($copy, $report['basisname'], $uid, $warnings);
		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();
		$created = 0;
		$kept = 0;
		$byRole = [];
		$this->db->beginTransaction();
		try {
			$entity = new TranscriptionReport();
			$entity->setVersionId($version->getId());
			$entity->setCopyId($copy->getId());
			$entity->setReportFileId($reportFileId);
			$entity->setSource($source);
			$entity->setSha256($sha256);
			$entity->setRound($report['runde']);
			$entity->setReportDate($report['datum']);
			$entity->setBasename($report['basisname']);
			$entity->setMusicxmlId($musicxml['file']?->getId());
			$entity->setMusicxmlSha256($musicxml['sha256']);
			$entity->setMethod($report['weg']);
			$entity->setOrigin($report['wv']['herkunft']);
			$entity->setReview($report['wv']['pruefstand']);
			$entity->setReasoning($report['wv']['begruendung']);
			$entity->setRightsClass($report['rechte']['einstufung']);
			$entity->setRaw($raw);
			$entity->setCreatedBy($uid);
			$entity->setCreated($now);
			$entity = $this->reports->insert($entity);

			foreach ($this->reports->findByCopy($copy->getId()) as $earlier) {
				if ($earlier->getId() !== $entity->getId() && $earlier->getSupersededBy() === null) {
					$earlier->setSupersededBy($entity->getId());
					$this->reports->update($earlier);
				}
			}

			$existing = $this->points->findByCopy($copy->getId());
			$byKey = [];
			foreach ($existing as $point) {
				$key = $point->getPointKey();
				if ($key !== null && $point->getStatus() !== ReviewPoint::CORRECTED) {
					$byKey[$key] = true;
				}
			}
			$named = [];
			foreach (TranscriptionReportParser::points($report) as $data) {
				$key = TranscriptionReportParser::pointKey($data);
				$named[$key] = true;
				if (isset($byKey[$key])) {
					// Named again: the open point stays, a decided one is not asked again.
					$kept++;
					continue;
				}
				$byKey[$key] = true;
				$this->points->insert($this->newPoint($data, $key, $copy, $entity, $musicxml, $uid, $now));
				$created++;
				$byRole[$data['role']] = ($byRole[$data['role']] ?? 0) + 1;
			}
			$closed = $this->closeDone($existing, $named, $report['erledigt'], $entity, $uid, $operation, $warnings);
			$this->audit->record($operation, $uid, 'review_report', $entity->getId(), 'imported', 'sha256', null, $sha256,
				sprintf('%s; %d points created, %d named again, %d done', $report['basisname'], $created, $kept, count($closed)));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		[$proposals, $skipped] = $this->propose($report, $copy, $version, $uid, $warnings);
		// Warnings are kept with the summary as English source strings with their parameters.
		$entity->setSummary(json_encode(self::summary($report, $warnings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$entity = $this->reports->update($entity);
		$this->notifyNewPoints($entity, $copy, $version, $byRole);
		foreach ($closed as $point) {
			$this->withdrawIfDone($point);
		}
		if ($closed !== []) {
			$this->notifyCorrection($copy, $version, 'report', $uid);
		}
		return ['report' => $entity, 'unchanged' => false,
			'points' => ['created' => $created, 'kept' => $kept, 'closed' => count($closed),
				'open' => count($this->points->findByCopy($copy->getId(), [ReviewPoint::OPEN]))],
			'proposals' => $proposals, 'proposalsSkipped' => $skipped, 'warnings' => $warnings];
	}

	/**
	 * @param ReportPoint $data
	 * @param array{file: ?ArchiveFile, sha256: ?string} $musicxml
	 */
	private function newPoint(array $data, string $key, Copy $copy, TranscriptionReport $report, array $musicxml, string $uid, int $now): ReviewPoint {
		$point = new ReviewPoint();
		$point->setVersionId($copy->getVersionId());
		$point->setCopyId($copy->getId());
		$point->setReportId($report->getId());
		$point->setFileId($musicxml['file']?->getId());
		$point->setFileSha256($musicxml['sha256']);
		$point->setCategory($data['category']);
		$point->setMeasures($data['measures']);
		$point->setMeasureFrom($data['measure_from']);
		$point->setMeasureTo($data['measure_to']);
		$point->setVoice($data['voice']);
		$point->setVerse($data['verse']);
		$point->setPage($data['page']);
		$point->setText($data['text']);
		$point->setPointKey($key);
		$point->setRole($data['role']);
		$point->setStatus(ReviewPoint::OPEN);
		$point->setCreatedBy($uid);
		$point->setCreated($now);
		return $point;
	}

	/**
	 * Closes the points of earlier reports that the new report no longer
	 * names or names as done, when the MusicXML has changed since.
	 *
	 * @param list<ReviewPoint> $existing points of the copy before the report
	 * @param array<string, true> $named keys of the points the report names
	 * @param list<int|array{measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string}> $done `erledigt`
	 * @param list<Warning> $warnings
	 * @return list<ReviewPoint> the points closed
	 */
	private function closeDone(array $existing, array $named, array $done, TranscriptionReport $report, string $uid, string $operation,
		array &$warnings): array {
		$ids = array_values(array_filter($done, 'is_int'));
		$places = array_values(array_filter($done, 'is_array'));
		$newSha = $report->getMusicxmlSha256();
		$closed = [];
		$unchecked = 0;
		$unchanged = 0;
		$label = $report->getRound() !== null ? $this->l->t('round %s', [$report->getRound()]) : '#' . $report->getId();
		foreach ($existing as $point) {
			if (!in_array($point->getStatus(), [ReviewPoint::OPEN, ReviewPoint::CORRECT], true)) {
				continue;
			}
			$explicit = in_array($point->getId(), $ids, true)
				|| ($point->getStatus() === ReviewPoint::CORRECT && self::matchesPlace($point, $places));
			if (!$explicit && isset($named[(string)$point->getPointKey()])) {
				continue;
			}
			$oldSha = $point->getFileSha256();
			if ($newSha === null || $oldSha === null) {
				$unchecked++;
				continue;
			}
			if (hash_equals($oldSha, $newSha)) {
				$unchanged++;
				continue;
			}
			$old = $point->getStatus();
			$note = $this->l->t('Done in a new version of the MusicXML (report %s).', [$label]);
			$point->setStatus(ReviewPoint::CORRECTED);
			$point->setDecidedBy(null);
			$point->setDecidedByName(null);
			$point->setDecided($this->time->getTime());
			$point->setDecisionReason($note);
			$point->setResolvedReportId($report->getId());
			$this->points->update($point);
			$this->audit->record($operation, $uid, 'review_point', $point->getId(), 'corrected', 'status', $old, ReviewPoint::CORRECTED, $note);
			$closed[] = $point;
		}
		if ($unchecked > 0) {
			$warnings[] = ['%s review points that the report no longer names stay open: it is not known whether the MusicXML has changed.', [$unchecked]];
		}
		if ($unchanged > 0) {
			$warnings[] = ['%s review points that the report no longer names stay open: the MusicXML has not changed.', [$unchanged]];
		}
		return $closed;
	}

	/**
	 * Whether a point lies at one of the places a report names as done.
	 *
	 * @param list<array{measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string}> $places
	 */
	private static function matchesPlace(ReviewPoint $point, array $places): bool {
		$normalize = fn (?string $text): string => mb_strtolower(trim((string)$text));
		foreach ($places as $place) {
			if ($normalize($place['measure_from']) === $normalize($point->getMeasureFrom())
				&& $normalize($place['measure_to']) === $normalize($point->getMeasureTo())
				&& $normalize($place['voice']) === $normalize($point->getVoice())) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The MusicXML of the copy a report is about (its name without extension
	 * is the report's base name) and its checksum.
	 *
	 * @param list<Warning> $warnings
	 * @return array{file: ?ArchiveFile, sha256: ?string}
	 */
	private function musicxmlOf(Copy $copy, string $basename, string $uid, array &$warnings): array {
		$candidates = array_values(array_filter($this->files->findByCopy($copy->getId()),
			fn (ArchiveFile $file): bool => mb_strtolower(self::withoutExtension($file->getName())) === mb_strtolower($basename)));
		$file = ScoreService::pick($candidates);
		if ($file === null) {
			$warnings[] = ['No MusicXML file named "%s" belongs to this copy; the review points refer to no file.', [$basename]];
			return ['file' => null, 'sha256' => null];
		}
		$sha256 = $this->checksum($file, $uid) ?? $file->getSha256();
		if ($sha256 === null) {
			$warnings[] = ['The MusicXML file "%s" could not be read; changes of it cannot be recognised.', [$file->getName()]];
		}
		return ['file' => $file, 'sha256' => $sha256];
	}

	/**
	 * SHA-256 of a file, read as the archive account (like the checksum job)
	 * or else as the account that hands in the report.
	 */
	private function checksum(ArchiveFile $file, string $uid): ?string {
		$archiveUser = $this->settings->archiveInfo()['user'];
		foreach (array_unique(array_filter([$archiveUser, $uid])) as $account) {
			try {
				$node = $this->rootFolder->getUserFolder($account)->getFirstNodeById($file->getFileId());
				if ($node instanceof File) {
					return ChecksumService::hash($node);
				}
			} catch (\Throwable $e) {
				$this->logger->info('Cantoria: checksum of file ' . $file->getId() . ' as ' . $account . ' failed: ' . $e->getMessage());
			}
		}
		return null;
	}

	/**
	 * Proposals for the rights status and the catalog fields of a report,
	 * while the proposals inbox is on.
	 *
	 * @param ParsedReport $report
	 * @param list<Warning> $warnings
	 * @return array{0: int, 1: bool} number of proposals, whether they were skipped
	 */
	private function propose(array $report, Copy $copy, WorkVersion $version, string $uid, array &$warnings): array {
		$class = $report['rechte']['einstufung'];
		if ($class === null && $report['wv']['felder'] === []) {
			return [0, false];
		}
		if (!$this->modules->isEnabled('proposals')) {
			$warnings[] = ['The proposals inbox is switched off: the rights and catalog fields of the report are only shown with the review.', []];
			return [0, true];
		}
		$label = $report['runde'] !== null ? $this->l->t('round %s', [$report['runde']]) : ($report['datum'] ?? $this->today($uid));
		$source = mb_substr($this->l->t('Transcription %s', [$label]), 0, 255);
		$count = 0;

		if ($class !== null) {
			$status = TranscriptionReportParser::RIGHTS_CLASSES[$class];
			if ($status !== $version->getRightsStatus()) {
				$persons = array_map(fn (array $p): string => trim(($p['name'] ?? '') . ' ('
					. implode('; ', array_filter([$p['rolle'], $p['lebensdaten'], $p['beleg']], fn (?string $s): bool => $s !== null && $s !== '')) . ')'),
					$report['rechte']['beteiligte']);
				$reason = implode("\n", array_filter([$report['wv']['begruendung'], implode("\n", $persons)], fn (?string $s): bool => $s !== null && $s !== ''));
				$count += $this->proposeField(Fields::VERSION, $version->getId(), 'rights_status', $status, $reason, $source, $uid, $warnings);
			}
		}
		foreach ($report['wv']['felder'] as $name => $value) {
			$target = self::catalogField($name);
			if ($target === null) {
				$warnings[] = ['Unknown catalog field "%s" in the report was ignored.', [$name]];
				continue;
			}
			[$level, $field] = $target;
			if ($level === Fields::VERSION && $field === 'rights_status') {
				$value = TranscriptionReportParser::RIGHTS_CLASSES[$value] ?? $value;
			}
			$objectId = match ($level) {
				Fields::WORK => $version->getWorkId(),
				Fields::VERSION => $version->getId(),
				default => $copy->getId(),
			};
			$current = match ($level) {
				Fields::WORK => $this->works->find($version->getWorkId())->getValue($field),
				Fields::VERSION => $version->getValue($field),
				default => $copy->getValue($field),
			};
			if ($current !== null && (string)$current === $value) {
				continue;
			}
			$count += $this->proposeField($level, $objectId, $field, $value, $report['wv']['begruendung'], $source, $uid, $warnings);
		}
		return [$count, false];
	}

	/**
	 * @param list<Warning> $warnings
	 * @return int 1 when the proposal was made
	 */
	private function proposeField(string $level, int $objectId, string $field, string $value, ?string $reason, string $source, string $uid,
		array &$warnings): int {
		try {
			$this->proposals->propose($level, $objectId, [$field => $value], $uid, $reason, $source);
			return 1;
		} catch (InvalidInputException $e) {
			$warnings[] = [$e->getMessage(), $e->getParameters()];
			return 0;
		}
	}

	/**
	 * Level and field of a catalog column named in a report: a column of the
	 * legacy catalog ("Verlag / Bestellnummer") or `level.field`.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function catalogField(string $name): ?array {
		if (isset(CatalogCsv::LEGACY_COLUMNS[$name])) {
			return CatalogCsv::LEGACY_COLUMNS[$name];
		}
		if (preg_match('/^(work|version|copy)\.([a-z0-9_]+)$/', $name, $m) === 1 && Fields::info($m[1], $m[2]) !== null) {
			return [$m[1], $m[2]];
		}
		return null;
	}

	/**
	 * The known fields of a report that are shown with the version.
	 *
	 * @param ParsedReport $report
	 * @param list<Warning> $warnings
	 * @return array<string, mixed>
	 */
	private static function summary(array $report, array $warnings): array {
		return [
			'title' => $report['titel'],
			'template' => $report['vorlage'],
			'extent' => $report['umfang'],
			'persons' => $report['rechte']['beteiligte'],
			'edition' => $report['rechte']['ausgabe'],
			'rights' => $report['rechte']['rights'],
			'fields' => (object)$report['wv']['felder'],
			'warnings' => $warnings,
		];
	}

	/**
	 * One notification per role that got new points, with their number.
	 *
	 * @param array<string, int> $byRole
	 */
	private function notifyNewPoints(TranscriptionReport $report, Copy $copy, WorkVersion $version, array $byRole): void {
		foreach ($byRole as $role => $count) {
			$this->notifications->notifyRole($role, NotificationService::REVIEW_POINTS, 'review_report', $report->getId() . '-' . $role,
				$this->describe($copy, $version) + ['count' => $count]);
		}
	}

	/**
	 * Tells the catalog librarian that a corrected MusicXML arrived; one
	 * notification per version, replaced by the next.
	 */
	public function notifyCorrection(Copy $copy, WorkVersion $version, string $kind, string $uid): void {
		$this->notifications->withdraw(NotificationService::CORRECTION_RECEIVED, 'version', (string)$version->getId());
		$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::CORRECTION_RECEIVED, 'version', (string)$version->getId(),
			$this->describe($copy, $version) + ['kind' => $kind], false, $uid);
	}

	/**
	 * Withdraws the notification of a report and role when none of its points
	 * waits for a decision any more.
	 */
	private function withdrawIfDone(ReviewPoint $point): void {
		$reportId = $point->getReportId();
		if ($reportId !== null && $this->points->countOpen($reportId, $point->getRole()) === 0) {
			$this->notifications->withdraw(NotificationService::REVIEW_POINTS, 'review_report', $reportId . '-' . $point->getRole());
		}
	}

	/**
	 * Notification parameters: shelf mark, title and copy (no personal data).
	 *
	 * @return array<string, scalar|null>
	 */
	private function describe(Copy $copy, WorkVersion $version): array {
		try {
			$title = (string)$this->works->find($version->getWorkId())->getTitle();
		} catch (DoesNotExistException) {
			$title = '';
		}
		return ['shelfmark' => $copy->getShelfmark(), 'title' => $title, 'copyId' => $copy->getId()];
	}

	/**
	 * A question about the point to a role, as a task with the point's place.
	 *
	 * @return int the task id
	 */
	private function ask(ReviewPoint $point, ?string $reason, ?string $addressee, string $uid): int {
		if ($addressee === null || $addressee === '') {
			$asksAsLibrarian = $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
			$addressee = $asksAsLibrarian ? Roles::CONDUCTOR : Roles::LIBRARIAN;
		}
		$question = trim((string)$point->getText() . ($reason !== null ? "\n\n" . $reason : ''));
		$task = $this->tasks->create($point->getCopyId(), [
			'kind' => 'question',
			'addressee' => $addressee,
			'category' => $point->getCategory() === ReviewPoint::MATERIAL ? 'origin' : 'reading',
			'measure_from' => $point->getMeasureFrom(),
			'measure_to' => $point->getMeasureTo(),
			'voice' => $point->getVoice(),
			'verse' => $point->getVerse(),
			'page' => $point->getPage(),
			'origin' => mb_substr($this->l->t('Review point %s', [$point->getId()]), 0, 255),
			'question' => $question === '' ? $this->l->t('Review point %s', [$point->getId()]) : $question,
		], $uid);
		return $task->getId();
	}

	private function today(string $uid): string {
		return $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
	}

	private static function withoutExtension(string $name): string {
		return (string)preg_replace('/\.[^.\/]*$/', '', $name);
	}

	private static function optionalText(?string $text): ?string {
		return $text === null || trim($text) === '' ? null : trim($text);
	}

	private function loadVersion(int $versionId): WorkVersion {
		try {
			return $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private function loadPoint(int $pointId): ReviewPoint {
		try {
			return $this->points->find($pointId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}
}
