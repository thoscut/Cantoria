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
 * @template-extends QBMapper<Person>
 */
class PersonMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_persons', Person::class);
	}

	public function find(int $id): Person {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Person> sorted by name
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name')->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Several persons in one query (in parts of 1000 ids).
	 *
	 * @param list<int> $ids
	 * @return array<int, Person> keyed by id; ids not found are missing
	 */
	public function findByIds(array $ids): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $person) {
				$result[$person->getId()] = $person;
			}
		}
		return $result;
	}

	/**
	 * @return array<int, Person> keyed by id
	 */
	public function findAllById(): array {
		$result = [];
		foreach ($this->findAll() as $person) {
			$result[$person->getId()] = $person;
		}
		return $result;
	}
}
