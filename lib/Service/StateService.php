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
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\TranscriptionReportMapper;
use OCA\Cantoria\Db\VersionState;
use OCA\Cantoria\Db\VersionStateMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Revisions of a version (milestone 0.8, module "version_states").
 *
 * A revision is a record that points to the MusicXML file of a copy of the
 * version, with its checksum, what changed, who and when. The app copies no
 * file and changes none (guideline 4); a revision is never changed
 * afterwards, except for the mark of the current revision.
 *
 * - A small correction in the app (CorrectionService) records the corrected
 *   file as a new revision, with the measures and voices it changed, the
 *   reason and the review points it settled.
 * - The file sync records the score file of a copy (ScoreService::pick) when
 *   it is new or changed and its content differs from the newest revision.
 * - The first revision of a version is recorded by the first file sync after
 *   the module is switched on (or by the first correction, from the file it
 *   corrects): the file sync reads the archive folder anyway, as an account
 *   that may, and a repair step would read every score during an update
 *   whether the module is wanted or not.
 * - The catalog librarian sets the current revision of a version (guideline
 *   1: the app records, people decide). Nothing sets it automatically.
 * - Comparing two revisions happens in the browser on the two files (see
 *   docs/api.md); the server only lists the revisions.
 */
class StateService {
	public const MODULE = Modules::VERSION_STATES;
	/** Roles that set the current revision */
	public const SETTERS = [Roles::LIBRARIAN];
	/** Most changes of a correction kept with its revision */
	private const MAX_CHANGES = 500;

	public function __construct(
		private IDBConnection $db,
		private VersionStateMapper $states,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ArchiveFileMapper $files,
		private ReviewPointMapper $points,
		private TranscriptionReportMapper $reports,
		private AuditLog $audit,
		private Roles $roles,
		private Modules $modules,
		private AppSettings $settings,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function isActive(): bool {
		return $this->modules->isEnabled(self::MODULE);
	}

	/**
	 * The revisions of a version, oldest first, the current one and what the
	 * account may do.
	 *
	 * @return array{version_id: int, states: list<VersionState>, current_id: ?int, may: array{setCurrent: bool}}
	 * @throws AccessDeniedException|NotFoundException
	 */
	public function forVersion(int $versionId, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireAccess($uid);
		$version = $this->loadVersion($versionId);
		if (!$this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)
			&& RightsPolicy::versionHasPreservationCopy($version, $this->copies->findByVersion($version->getId()))) {
			throw new AccessDeniedException('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		}
		$states = $this->states->findByVersion($version->getId());
		return [
			'version_id' => $version->getId(),
			'states' => $states,
			'current_id' => self::current($states)?->getId(),
			'may' => ['setCurrent' => $this->roles->hasAnyRole($uid, self::SETTERS)],
		];
	}

	/**
	 * Sets the current revision of its version (catalog librarian). The
	 * revision that was current before loses the mark; both steps are in the
	 * audit log.
	 *
	 * @throws AccessDeniedException|InvalidInputException|NotFoundException
	 */
	public function setCurrent(int $stateId, string $uid, ?string $reason): VersionState {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireRole($uid, self::SETTERS);
		$reason = $reason === null || trim($reason) === '' ? null : trim($reason);
		if ($reason !== null && mb_strlen($reason) > 10000) {
			throw new InvalidInputException('"%s" is too long.', ['Reason']);
		}
		try {
			$state = $this->states->find($stateId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($state->getValidSince() !== null) {
			throw new InvalidInputException('This revision is already the current one.');
		}
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$before = null;
			foreach ($this->states->findByVersion($state->getVersionId()) as $other) {
				if ($other->getValidSince() !== null) {
					$before = $other->getNumber();
					$other->setValidSince(null);
					$other->setValidSetBy(null);
					$this->states->update($other);
				}
			}
			$state->setValidSince($this->today($uid));
			$state->setValidSetBy($this->userManager->getDisplayName($uid) ?? $uid);
			$state = $this->states->update($state);
			$this->audit->record($operation, $uid, Fields::VERSION, $state->getVersionId(), 'state_current', null, $before, $state->getNumber(), $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $state;
	}

	/**
	 * What the copy sidebar shows about the revisions of a version: their
	 * number, the newest, the current one and the revision of the score file
	 * shown (the newest revision that points to it).
	 *
	 * @return array{count: int, latest: ?int, current: ?array{id: int, number: int, valid_since: string}, shown: ?int}
	 */
	public function summary(int $versionId, ?int $shownFileId): array {
		$states = $this->states->findByVersion($versionId);
		$current = self::current($states);
		$shown = null;
		foreach ($states as $state) {
			if ($shownFileId !== null && $state->getFileId() === $shownFileId) {
				$shown = $state->getNumber();
			}
		}
		$latest = $states === [] ? null : $states[count($states) - 1]->getNumber();
		return [
			'count' => count($states),
			'latest' => $latest,
			'current' => $current === null ? null : ['id' => $current->getId(), 'number' => $current->getNumber(), 'valid_since' => (string)$current->getValidSince()],
			'shown' => $shown,
		];
	}

	/**
	 * The revision a concert sings: the current revision of the version, or
	 * the newest when none is set. Null without revisions or while the module
	 * is off.
	 */
	public function sungState(int $versionId): ?VersionState {
		if (!$this->modules->isEnabled(self::MODULE)) {
			return null;
		}
		$states = $this->states->findByVersion($versionId);
		return self::current($states) ?? ($states === [] ? null : $states[count($states) - 1]);
	}

	/**
	 * The revision of each programme item: the revision sung (once the concert
	 * is confirmed as sung), else the current revision of its version. Items
	 * without either are left out; empty while the module is off.
	 *
	 * @param list<EventItem> $items
	 * @return array<int, array{number: int, valid_since: ?string, sung: bool}> by item id
	 */
	public function forItems(array $items): array {
		if ($items === [] || !$this->modules->isEnabled(self::MODULE)) {
			return [];
		}
		$sung = $this->states->findByIds(array_values(array_filter(array_map(fn (EventItem $item): ?int => $item->getStateId(), $items),
			fn (?int $id): bool => $id !== null)));
		$current = [];
		$result = [];
		foreach ($items as $item) {
			$stateId = $item->getStateId();
			if ($stateId !== null && isset($sung[$stateId])) {
				$state = $sung[$stateId];
				$result[$item->getId()] = ['number' => $state->getNumber(), 'valid_since' => $state->getValidSince(), 'sung' => true];
				continue;
			}
			$versionId = $item->getVersionId();
			if ($versionId === null) {
				continue;
			}
			if (!array_key_exists($versionId, $current)) {
				$current[$versionId] = self::current($this->states->findByVersion($versionId));
			}
			$state = $current[$versionId];
			if ($state !== null) {
				$result[$item->getId()] = ['number' => $state->getNumber(), 'valid_since' => $state->getValidSince(), 'sung' => false];
			}
		}
		return $result;
	}

	/**
	 * Records a correction saved in the app as a new revision. Before it, the
	 * corrected file itself becomes a revision when no revision has its
	 * content yet (the first revision of the version, or a change the file
	 * sync has not seen). Runs inside the caller's transaction; does nothing
	 * while the module is off.
	 *
	 * @param list<array{measure: string, part: string, staff: string, voice: string, kind: string, verse: ?string, old: string, new: string}> $changes
	 * @param list<int> $pointIds review points the correction settled
	 */
	public function recordCorrection(Copy $copy, WorkVersion $version, ArchiveFile $old, string $oldSha256, ArchiveFile $new, string $newSha256,
		array $changes, array $pointIds, ?string $reason, string $uid, string $operation): ?VersionState {
		if (!$this->modules->isEnabled(self::MODULE)) {
			return null;
		}
		$existing = $this->states->findByVersion($version->getId());
		$known = false;
		foreach ($existing as $state) {
			if ($state->getFileId() === $old->getId() && $state->getSha256() === $oldSha256) {
				$known = true;
			}
		}
		$number = $existing === [] ? 0 : $existing[count($existing) - 1]->getNumber();
		if (!$known) {
			$number++;
			$this->insert($version->getId(), $copy->getId(), $old, $oldSha256, $number, $existing === [] ? VersionState::FIRST : VersionState::SYNC,
				null, null, null, null, $uid, $operation);
		}

		$changes = array_slice($changes, 0, self::MAX_CHANGES);
		$notes = $reason === null || trim($reason) === '' ? [] : [trim($reason)];
		foreach (array_slice($pointIds, 0, 50) as $pointId) {
			try {
				$point = $this->points->find($pointId);
			} catch (DoesNotExistException) {
				continue;
			}
			$place = implode(', ', array_filter([
				$point->getMeasures() === null || $point->getMeasures() === '' ? null : $this->l->t('m. %s', [$point->getMeasures()]),
				$point->getVoice(),
			], fn (?string $part): bool => $part !== null && $part !== ''));
			$notes[] = $this->l->t('Review point %s', [$point->getId()]) . ($place !== '' ? ' (' . $place . ')' : '') . ': ' . trim((string)$point->getText());
		}
		return $this->insert($version->getId(), $copy->getId(), $new, $newSha256, $number + 1, VersionState::CORRECTION,
			self::measureList(array_map(fn (array $c): string => $c['measure'], $changes)),
			self::textList(array_map(fn (array $c): string => $c['part'], $changes)),
			$notes === [] ? null : mb_substr(implode("\n", $notes), 0, 10000),
			$changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
			$uid, $operation);
	}

	/**
	 * After a file sync: records the score file of each copy as a new
	 * revision when its version has none yet, or when the file is new, has
	 * changed or has no revision yet and its content differs from the newest
	 * revision of the version and of the file. One file that cannot be read
	 * does not stop the others.
	 *
	 * @param list<int> $changedFileIds cantoria_files.id of the MusicXML files the sync found new or changed
	 * @param int $since start of the sync: reports read since then name the change
	 * @return array{recorded: int, first: int, failed: int, switchedOff: bool}
	 */
	public function recordSync(array $changedFileIds, int $since, string $uid): array {
		$result = ['recorded' => 0, 'first' => 0, 'failed' => 0, 'switchedOff' => !$this->modules->isEnabled(self::MODULE)];
		if ($result['switchedOff']) {
			return $result;
		}
		$archive = $this->settings->archiveFolder($uid);
		$byVersion = $this->states->findAllByVersion();
		$filesByCopy = [];
		foreach ($this->files->findAllByFileId() as $file) {
			$copyId = $file->getCopyId();
			if ($copyId !== null) {
				$filesByCopy[$copyId][] = $file;
			}
		}
		$changed = array_fill_keys($changedFileIds, true);
		$operation = $this->audit->newOperation();
		foreach ($this->copies->findAll() as $copy) {
			$file = ScoreService::pick($filesByCopy[$copy->getId()] ?? []);
			if ($file === null) {
				continue;
			}
			$versionId = $copy->getVersionId();
			$existing = $byVersion[$versionId] ?? [];
			$ofFile = array_values(array_filter($existing, fn (VersionState $s): bool => $s->getFileId() === $file->getId()));
			if ($existing !== [] && $ofFile !== [] && !isset($changed[$file->getId()])) {
				continue;
			}
			try {
				$node = $archive->getFirstNodeById($file->getFileId());
				if (!$node instanceof File) {
					throw new \RuntimeException('not found in the archive folder');
				}
				$sha256 = ChecksumService::hash($node);
			} catch (\Throwable $e) {
				$this->logger->info('Cantoria: revision of file ' . $file->getId() . ' not recorded: ' . $e->getMessage());
				$result['failed']++;
				continue;
			}
			$newest = $existing === [] ? null : $existing[count($existing) - 1];
			$newestOfFile = $ofFile === [] ? null : $ofFile[count($ofFile) - 1];
			if ($newest?->getSha256() === $sha256 || $newestOfFile?->getSha256() === $sha256) {
				continue;
			}
			$note = null;
			foreach ($this->reports->findByCopy($copy->getId()) as $report) {
				if ($report->getMusicxmlId() === $file->getId() && $report->getCreated() >= $since) {
					$note = $report->getRound() !== null
						? $this->l->t('Transcription report, round %s', [$report->getRound()])
						: $this->l->t('Transcription report %s', [$report->getBasename()]);
					break;
				}
			}
			try {
				$state = $this->insert($versionId, $copy->getId(), $file, $sha256, ($newest?->getNumber() ?? 0) + 1,
					$existing === [] ? VersionState::FIRST : VersionState::SYNC, null, null, $note, null, $uid, $operation);
			} catch (\Throwable $e) {
				$this->logger->warning('Cantoria: revision of file ' . $file->getId() . ' not recorded: ' . $e->getMessage(), ['exception' => $e]);
				$result['failed']++;
				continue;
			}
			$result[$existing === [] ? 'first' : 'recorded']++;
			$byVersion[$versionId][] = $state;
		}
		return $result;
	}

	/**
	 * The current revision among those of a version.
	 *
	 * @param list<VersionState> $states
	 */
	public static function current(array $states): ?VersionState {
		foreach ($states as $state) {
			if ($state->getValidSince() !== null) {
				return $state;
			}
		}
		return null;
	}

	/**
	 * Printed measure numbers as a short list: unique, in order, runs of
	 * whole numbers joined ("3, 12–14, 20a"). Null for none.
	 *
	 * @param list<string> $measures
	 */
	public static function measureList(array $measures): ?string {
		$measures = array_values(array_unique(array_filter(array_map('trim', $measures), fn (string $m): bool => $m !== '')));
		if ($measures === []) {
			return null;
		}
		/** @var list<array{0: int, 1: int}|string> $runs runs of whole numbers, other measure numbers as they are */
		$runs = [];
		foreach ($measures as $measure) {
			$last = count($runs) - 1;
			if (preg_match('/^\d+$/', $measure) !== 1) {
				$runs[] = $measure;
			} elseif ($last >= 0 && is_array($runs[$last]) && (int)$measure === $runs[$last][1] + 1) {
				$runs[$last][1] = (int)$measure;
			} else {
				$runs[] = [(int)$measure, (int)$measure];
			}
		}
		$parts = array_map(fn (array|string $run): string => is_string($run) ? $run : ($run[0] === $run[1] ? (string)$run[0] : $run[0] . '–' . $run[1]), $runs);
		return mb_substr(implode(', ', $parts), 0, 1000);
	}

	/**
	 * Unique, non-empty texts joined with commas; null for none.
	 *
	 * @param list<string> $texts
	 */
	private static function textList(array $texts): ?string {
		$texts = array_values(array_unique(array_filter(array_map('trim', $texts), fn (string $t): bool => $t !== '')));
		return $texts === [] ? null : mb_substr(implode(', ', $texts), 0, 1000);
	}

	private function insert(int $versionId, ?int $copyId, ArchiveFile $file, ?string $sha256, int $number, string $source, ?string $measures,
		?string $voices, ?string $note, ?string $changes, string $uid, string $operation): VersionState {
		$state = new VersionState();
		$state->setVersionId($versionId);
		$state->setCopyId($copyId);
		$state->setFileId($file->getId());
		$state->setFileName(mb_substr($file->getName(), 0, 255));
		$state->setSha256($sha256);
		$state->setNumber($number);
		$state->setSource($source);
		$state->setMeasures($measures);
		$state->setVoices($voices);
		$state->setNote($note);
		$state->setChanges($changes);
		$state->setCreatedBy($uid);
		$state->setCreatedByName($this->userManager->getDisplayName($uid) ?? $uid);
		$state->setCreated($this->time->getTime());
		$state = $this->states->insert($state);
		$this->audit->record($operation, $uid, Fields::VERSION, $versionId, 'state_recorded', null, null, $number,
			$note ?? $file->getName());
		return $state;
	}

	private function today(string $uid): string {
		return $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
	}

	private function loadVersion(int $versionId): WorkVersion {
		try {
			return $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}
}
