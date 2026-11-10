<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\Merge;
use OCA\Cantoria\Db\MergeMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Merging works and moving copies to another version, each as an operation
 * that can be undone (milestone 0.5).
 *
 * - Merging works moves all versions of the source work to the target work.
 *   The source work is kept with `merged_into` (never deleted) and no longer
 *   appears in lists. The target work gets a work number "W-0001", … if it
 *   has none.
 * - Moving a copy assigns it to another version, e.g. two copies of the same
 *   print (6-8 to the version of 1-23).
 *
 * Programme lines follow the moved versions and copies, so that performance
 * figures stay with the work. Rights hang on the version: a merge never
 * changes a rights status. The app proposes candidates (same title and
 * composer); only the catalog librarian merges. Everything is recorded in
 * the audit log.
 */
class MergeService {
	public function __construct(
		private IDBConnection $db,
		private MergeMapper $merges,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private EventItemMapper $items,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
		private IUserManager $users,
	) {
	}

	public function mergeWorks(int $sourceWorkId, int $targetWorkId, string $uid, ?string $reason): Merge {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if ($sourceWorkId === $targetWorkId) {
			throw new InvalidInputException('A work cannot be merged into itself.');
		}
		$source = $this->loadWork($sourceWorkId);
		$target = $this->loadWork($targetWorkId);
		if ($source->getMergedInto() !== null) {
			throw new InvalidInputException('This work has already been merged into another work.');
		}
		if ($target->getMergedInto() !== null) {
			throw new InvalidInputException('The target work has been merged into another work itself. Please choose that one.');
		}
		$sourceVersions = $this->versions->findByWork($sourceWorkId);
		$hasValid = fn (array $versions): bool => array_filter($versions, fn (WorkVersion $v): bool => $v->getValidSince() !== null) !== [];
		if ($hasValid($sourceVersions) && $hasValid($this->versions->findByWork($targetWorkId))) {
			throw new InvalidInputException('Both works have a version that is valid for the choir. The conductor must first clear one of them.');
		}
		$reason = self::reason($reason);
		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$movedVersions = [];
			foreach ($sourceVersions as $version) {
				$version->setWorkId($targetWorkId);
				$version->setModified($now);
				$this->versions->update($version);
				$movedVersions[] = $version->getId();
				$this->audit->record($operation, $uid, Fields::VERSION, $version->getId(), 'merged', 'work_id', $sourceWorkId, $targetWorkId, $reason);
			}
			$movedItems = [];
			foreach ($this->items->findAll() as $item) {
				if ($item->getWorkId() === $sourceWorkId) {
					$item->setValue('work_id', $targetWorkId);
					$this->items->update($item);
					$movedItems[] = $item->getId();
				}
			}
			$source->setMergedInto($targetWorkId);
			$source->setModified($now);
			$this->works->update($source);
			$this->audit->record($operation, $uid, Fields::WORK, $sourceWorkId, 'merged', 'merged_into', null, $targetWorkId, $reason);
			$workNo = null;
			$targetWorkNo = $target->getWorkNo();
			if ($targetWorkNo === null || trim($targetWorkNo) === '') {
				$workNo = sprintf('W-%04d', $this->works->highestWorkNumber() + 1);
				$target->setWorkNo($workNo);
				$target->setModified($now);
				$this->works->update($target);
				$this->audit->record($operation, $uid, Fields::WORK, $targetWorkId, 'merged', 'work_no', null, $workNo, $reason);
			}
			$merge = $this->insert(Merge::WORK, $sourceWorkId, $targetWorkId,
				['versions' => $movedVersions, 'eventItems' => $movedItems, 'workNo' => $workNo], $reason, $uid, $now);
			$this->audit->record($operation, $uid, 'merge', $merge->getId(), 'created', 'kind', null, Merge::WORK, $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $merge;
	}

	/**
	 * Moves a copy to another version. `emptyVersionId` is the former version
	 * when it has no copies left (it is kept).
	 *
	 * @return array{merge: Merge, emptyVersionId: ?int}
	 */
	public function moveCopy(int $copyId, int $targetVersionId, string $uid, ?string $reason): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$copy = $this->loadCopy($copyId);
		$target = $this->loadVersion($targetVersionId);
		$fromVersionId = $copy->getVersionId();
		if ($fromVersionId === $targetVersionId) {
			throw new InvalidInputException('The copy already belongs to this version.');
		}
		if ($this->loadWork($target->getWorkId())->getMergedInto() !== null) {
			throw new InvalidInputException('The target work has been merged into another work itself. Please choose that one.');
		}
		$reason = self::reason($reason);
		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$copy->setVersionId($targetVersionId);
			$copy->setModified($now);
			$this->copies->update($copy);
			$this->audit->record($operation, $uid, Fields::COPY, $copyId, 'merged', 'version_id', $fromVersionId, $targetVersionId, $reason);
			$movedItems = [];
			foreach ($this->items->findAll() as $item) {
				if ($item->getCopyId() === $copyId) {
					$movedItems[] = ['id' => $item->getId(), 'version' => $item->getVersionId(), 'work' => $item->getWorkId()];
					$item->setValue('version_id', $targetVersionId);
					$item->setValue('work_id', $target->getWorkId());
					$this->items->update($item);
				}
			}
			$merge = $this->insert(Merge::COPY, $copyId, $targetVersionId,
				['fromVersion' => $fromVersionId, 'eventItems' => $movedItems], $reason, $uid, $now);
			$this->audit->record($operation, $uid, 'merge', $merge->getId(), 'created', 'kind', null, Merge::COPY, $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['merge' => $merge, 'emptyVersionId' => $this->copies->findByVersion($fromVersionId) === [] ? $fromVersionId : null];
	}

	/**
	 * Undoes a merge, as long as the moved data is still where the merge put
	 * it. Later merges of the same data must be undone first. A work number
	 * given by the merge stays.
	 */
	public function undo(int $mergeId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$merge = $this->merges->find($mergeId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($merge->getUndone() !== null) {
			throw new InvalidInputException('This merge has already been undone.');
		}
		$reason = self::reason($reason);
		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();

		if ($merge->getKind() === Merge::WORK) {
			$source = $this->loadWork($merge->getSourceId());
			$target = $this->loadWork($merge->getTargetId());
			if ($target->getMergedInto() !== null) {
				throw new InvalidInputException('The target work has been merged into another work since. Please undo that merge first.');
			}
			$moved = $merge->movedWork();
			$versions = [];
			foreach ($moved['versions'] as $versionId) {
				$version = $this->loadVersion($versionId);
				if ($version->getWorkId() !== $target->getId()) {
					throw new InvalidInputException('This merge cannot be undone because the data has changed since.');
				}
				$versions[$versionId] = $version;
			}
			if ($source->getMergedInto() !== $target->getId()) {
				throw new InvalidInputException('This merge cannot be undone because the data has changed since.');
			}
			$movedItems = $moved['eventItems'];
			$this->db->beginTransaction();
			try {
				foreach ($versions as $version) {
					$version->setWorkId($source->getId());
					$version->setModified($now);
					$this->versions->update($version);
					$this->audit->record($operation, $uid, Fields::VERSION, $version->getId(), 'merge_undone', 'work_id', $target->getId(), $source->getId(), $reason);
				}
				foreach ($this->items->findAll() as $item) {
					$itemVersionId = $item->getVersionId();
					$followsVersion = $itemVersionId !== null && isset($versions[$itemVersionId]);
					$recordedWorkOnly = $itemVersionId === null && in_array($item->getId(), $movedItems, true);
					if (($followsVersion || $recordedWorkOnly) && $item->getWorkId() === $target->getId()) {
						$item->setValue('work_id', $source->getId());
						$this->items->update($item);
					}
				}
				$source->setMergedInto(null);
				$source->setModified($now);
				$this->works->update($source);
				$this->audit->record($operation, $uid, Fields::WORK, $source->getId(), 'merge_undone', 'merged_into', $target->getId(), null, $reason);
				$this->finish($merge, $uid, $now, $operation, $reason);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return;
		}

		$copy = $this->loadCopy($merge->getSourceId());
		$moved = $merge->movedCopy();
		$fromVersionId = $moved['fromVersion'];
		if ($copy->getVersionId() !== $merge->getTargetId()) {
			throw new InvalidInputException('This merge cannot be undone because the data has changed since.');
		}
		$from = $this->loadVersion($fromVersionId);
		$this->db->beginTransaction();
		try {
			$copy->setVersionId($from->getId());
			$copy->setModified($now);
			$this->copies->update($copy);
			$this->audit->record($operation, $uid, Fields::COPY, $copy->getId(), 'merge_undone', 'version_id', $merge->getTargetId(), $from->getId(), $reason);
			$recorded = [];
			foreach ($moved['eventItems'] as $entry) {
				$recorded[$entry['id']] = $entry;
			}
			foreach ($this->items->findAll() as $item) {
				if ($item->getCopyId() === $copy->getId() && $item->getVersionId() === $merge->getTargetId()) {
					$entry = $recorded[$item->getId()] ?? null;
					$item->setValue('version_id', $entry === null ? $from->getId() : $entry['version']);
					$item->setValue('work_id', $entry === null ? $from->getWorkId() : $entry['work']);
					$this->items->update($item);
				}
			}
			$this->finish($merge, $uid, $now, $operation, $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @return list<array<string, mixed>> merges with readable context, newest first
	 */
	public function list(bool $includeUndone): array {
		return $this->describe($this->merges->findLatest($includeUndone));
	}

	/**
	 * Merges of a work or copy that can still be undone, with the same context
	 * as the list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function openFor(int $workId, int $copyId): array {
		return $this->describe($this->merges->findOpenFor($workId, $copyId));
	}

	/**
	 * Adds what was merged into what (titles, shelf mark) and the display
	 * names of the people who merged and undid.
	 *
	 * @param list<Merge> $merges
	 * @return list<array<string, mixed>>
	 */
	private function describe(array $merges): array {
		if ($merges === []) {
			return [];
		}
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$workOfVersion = fn (int $versionId): ?Work => isset($versions[$versionId]) ? ($works[$versions[$versionId]->getWorkId()] ?? null) : null;
		$rows = [];
		foreach ($merges as $merge) {
			$row = $merge->jsonSerialize();
			unset($row['moved_ids']);
			$row['moved'] = $merge->moved();
			$row['created_by_name'] = $this->displayName($merge->getCreatedBy());
			$row['undone_by_name'] = $this->displayName($merge->getUndoneBy());
			if ($merge->getKind() === Merge::WORK) {
				$source = $works[$merge->getSourceId()] ?? null;
				$target = $works[$merge->getTargetId()] ?? null;
				$row['source'] = ['title' => $source?->getTitle(), 'composer' => $source?->getComposer(), 'work_no' => $source?->getWorkNo()];
				$row['target'] = ['title' => $target?->getTitle(), 'composer' => $target?->getComposer(), 'work_no' => $target?->getWorkNo()];
			} else {
				$copy = $copies[$merge->getSourceId()] ?? null;
				$from = $workOfVersion($merge->movedCopy()['fromVersion']);
				$to = $workOfVersion($merge->getTargetId());
				$row['source'] = ['shelfmark' => $copy?->getShelfmark(), 'copyId' => $copy?->getId(), 'title' => $from?->getTitle(), 'composer' => $from?->getComposer()];
				$row['target'] = ['title' => $to?->getTitle(), 'composer' => $to?->getComposer(), 'work_no' => $to?->getWorkNo()];
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private function displayName(?string $uid): ?string {
		return $uid === null ? null : ($this->users->getDisplayName($uid) ?? $uid);
	}

	/**
	 * Proposals: works (not merged) with the same title and the same composer
	 * surname, or without composer. The librarian decides.
	 *
	 * @return list<array{title: ?string, works: list<array<string, mixed>>}>
	 */
	public function candidates(): array {
		/** @var array<int, list<array{id: int, shelfmark: string, versionId: int, status: string}>> $copiesByVersion */
		$copiesByVersion = [];
		foreach ($this->copies->findAll() as $copy) {
			$copiesByVersion[$copy->getVersionId()][] = ['id' => $copy->getId(), 'shelfmark' => $copy->getShelfmark(), 'versionId' => $copy->getVersionId(), 'status' => $copy->getStatus()];
		}
		/** @var array<int, list<array{id: int, shelfmark: string, versionId: int, status: string}>> $copiesByWork */
		$copiesByWork = [];
		foreach ($this->versions->findAllById() as $version) {
			foreach ($copiesByVersion[$version->getId()] ?? [] as $copy) {
				$copiesByWork[$version->getWorkId()][] = $copy;
			}
		}
		/** @var array<string, list<Work>> $byTitle */
		$byTitle = [];
		foreach ($this->works->findAllById() as $work) {
			$key = self::normalizeTitle($work->getTitle());
			if ($work->getMergedInto() !== null || $key === '' || !isset($copiesByWork[$work->getId()])) {
				continue;
			}
			$byTitle[$key][] = $work;
		}
		$groups = [];
		$groupKeys = [];
		foreach ($byTitle as $works) {
			if (count($works) < 2) {
				continue;
			}
			$bySurname = [];
			$withoutComposer = [];
			foreach ($works as $work) {
				$surname = self::surname($work->getComposer());
				if ($surname === '') {
					$withoutComposer[] = $work;
				} else {
					$bySurname[$surname][] = $work;
				}
			}
			$clusters = $bySurname === [] ? [$withoutComposer] : array_values($bySurname);
			if (count($clusters) === 1 && $bySurname !== []) {
				$clusters[0] = array_merge($clusters[0], $withoutComposer);
			} elseif (count($clusters) > 1) {
				// several composers: the works without one are a group of
				// their own, not belonging to any of them
				$clusters[] = $withoutComposer;
			}
			foreach ($clusters as $cluster) {
				if (count($cluster) < 2) {
					continue;
				}
				/** @var list<array{workId: int, work_no: ?string, title: ?string, composer: ?string, copies: list<array{id: int, shelfmark: string, versionId: int, status: string}>}> $rows */
				$rows = [];
				$keys = [];
				foreach ($cluster as $work) {
					$list = $copiesByWork[$work->getId()];
					$list = Sort::byKeys($list, array_map(fn (array $copy): array => [$copy['shelfmark']], $list));
					$rows[] = ['workId' => $work->getId(), 'work_no' => $work->getWorkNo(), 'title' => $work->getTitle(),
						'composer' => $work->getComposer(), 'copies' => $list];
					$keys[] = [$list[0]['shelfmark'] ?? ''];
				}
				$rows = Sort::byKeys($rows, $keys);
				$groups[] = ['title' => $rows[0]['title'] ?? null, 'works' => $rows];
				$groupKeys[] = [$rows[0]['copies'][0]['shelfmark'] ?? ''];
			}
		}
		return Sort::byKeys($groups, $groupKeys);
	}

	/**
	 * Title for comparing: like the search (CatalogSearch::normalize), so a
	 * decomposed "Ä" from macOS or an accent ("Ave María") does not hide a
	 * duplicate; punctuation counts as a space.
	 */
	public static function normalizeTitle(?string $title): string {
		$text = CatalogSearch::normalize((string)$title);
		$text = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
		return trim($text);
	}

	/**
	 * Surname of a composer as written in the catalog, e.g. "Carl Friedrich
	 * Zelter (1758-1832)" → "zelter", "Friedrich Silcher, op. 26" → "silcher".
	 */
	public static function surname(?string $composer): string {
		$text = CatalogSearch::normalize((string)$composer);
		$text = (string)preg_replace('/\(.*?\)/u', ' ', $text);
		$text = explode(',', $text)[0];
		$text = (string)preg_replace('/[^\p{L}\s-]+/u', ' ', $text);
		$words = preg_split('/\s+/u', trim($text)) ?: [];
		return (string)end($words);
	}

	/**
	 * @param array<string, mixed> $moved
	 */
	private function insert(string $kind, int $sourceId, int $targetId, array $moved, ?string $reason, string $uid, int $now): Merge {
		$merge = new Merge();
		$merge->setKind($kind);
		$merge->setSourceId($sourceId);
		$merge->setTargetId($targetId);
		$merge->setMovedIds(json_encode($moved, JSON_THROW_ON_ERROR));
		$merge->setReason($reason);
		$merge->setCreatedBy($uid);
		$merge->setCreated($now);
		return $this->merges->insert($merge);
	}

	private function finish(Merge $merge, string $uid, int $now, string $operation, ?string $reason): void {
		$merge->setUndone($now);
		$merge->setUndoneBy($uid);
		$this->merges->update($merge);
		$this->audit->record($operation, $uid, 'merge', $merge->getId(), 'undone', 'kind', $merge->getKind(), null, $reason);
	}

	private static function reason(?string $reason): ?string {
		if ($reason === null || trim($reason) === '') {
			return null;
		}
		if (mb_strlen($reason) > 10000) {
			throw new InvalidInputException('"%s" is too long.', ['Reason']);
		}
		return trim($reason);
	}

	private function loadWork(int $id): Work {
		try {
			return $this->works->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private function loadVersion(int $id): WorkVersion {
		try {
			return $this->versions->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private function loadCopy(int $id): Copy {
		try {
			return $this->copies->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}
}
