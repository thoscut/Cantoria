<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ArchiveFile>
 */
class ArchiveFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_files', ArchiveFile::class);
	}

	public function find(int $id): ArchiveFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return array<int, ArchiveFile> keyed by Nextcloud file id
	 */
	public function findAllByFileId(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$result = [];
		foreach ($this->findEntities($qb) as $file) {
			$result[$file->getFileId()] = $file;
		}
		return $result;
	}

	/**
	 * @return list<ArchiveFile>
	 */
	public function findByCopy(int $copyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)))
			->orderBy('name');
		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findByFileId(int $fileId): ArchiveFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Found files after the given row id, in id order: the checksum check
	 * walks through the archive round by round.
	 *
	 * @return list<ArchiveFile>
	 */
	public function findFoundAfter(int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('found', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @return list<ArchiveFile>
	 */
	public function findFound(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('found', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('path');
		return $this->findEntities($qb);
	}

	/**
	 * Number of found files per copy and type, for the list view.
	 *
	 * @return array<int, array<string, int>> copy id => type => count
	 */
	public function countPerCopy(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('copy_id', 'type')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('copy_id'))
			->andWhere($qb->expr()->eq('found', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->groupBy('copy_id', 'type');
		$result = $qb->executeQuery();
		$counts = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$counts[Row::int($row['copy_id'])][Row::stringOrNull($row['type']) ?? ''] = Row::int($row['cnt']);
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * The MusicXML files of the copies that have any, for the facts of
	 * their scores (ScoreFactsService).
	 *
	 * @return array<int, list<ArchiveFile>> copy id => files
	 */
	public function findMusicxmlPerCopy(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('copy_id'))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('musicxml')))
			->orderBy('id');
		$result = [];
		foreach ($this->findEntities($qb) as $file) {
			$result[(int)$file->getCopyId()][] = $file;
		}
		return $result;
	}
}
