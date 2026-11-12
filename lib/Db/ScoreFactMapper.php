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
 * @template-extends QBMapper<ScoreFact>
 */
class ScoreFactMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_score_facts', ScoreFact::class);
	}

	public function findByFile(int $archiveFileId): ?ScoreFact {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('archive_file_id', $qb->createNamedParameter($archiveFileId, IQueryBuilder::PARAM_INT)));
		$found = $this->findEntities($qb);
		return $found[0] ?? null;
	}

	/**
	 * @return array<int, ScoreFact> keyed by archive file id
	 */
	public function findAllByFile(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$result = [];
		foreach ($this->findEntities($qb) as $fact) {
			$result[$fact->getArchiveFileId()] = $fact;
		}
		return $result;
	}

	/**
	 * @param list<int> $archiveFileIds
	 * @return array<int, ScoreFact> keyed by archive file id
	 */
	public function findByFiles(array $archiveFileIds): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($archiveFileIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('archive_file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $fact) {
				$result[$fact->getArchiveFileId()] = $fact;
			}
		}
		return $result;
	}
}
