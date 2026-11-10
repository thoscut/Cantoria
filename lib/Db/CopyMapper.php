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
 * @template-extends QBMapper<Copy>
 */
class CopyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_copies', Copy::class);
	}

	public function find(int $id): Copy {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * In catalog order; copies added later follow by binder and number.
	 *
	 * @return list<Copy>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('sort_order')
			->addOrderBy('binder')
			->addOrderBy('number');
		return $this->findEntities($qb);
	}

	/**
	 * @return array<string, Copy> keyed by shelf mark
	 */
	public function findAllByShelfmark(): array {
		$result = [];
		foreach ($this->findAll() as $copy) {
			$result[$copy->getShelfmark()] = $copy;
		}
		return $result;
	}

	/**
	 * @return list<Copy>
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('binder')
			->addOrderBy('number');
		return $this->findEntities($qb);
	}

	/**
	 * Hired copies due back on or before a day (YYYY-MM-DD), retired copies
	 * (returned) left out: counted by the database.
	 */
	public function countReturnDue(string $until): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('return_date'))
			->andWhere($qb->expr()->lte('return_date', $qb->createNamedParameter($until)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('retired')));
		$result = $qb->executeQuery();
		$count = Row::int($result->fetchOne());
		$result->closeCursor();
		return $count;
	}
}
