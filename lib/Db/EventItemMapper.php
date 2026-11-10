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
 * @template-extends QBMapper<EventItem>
 */
class EventItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_event_items', EventItem::class);
	}

	public function find(int $id): EventItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<EventItem> in programme order
	 */
	public function findByEvent(int $eventId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
			->orderBy('position')
			->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<EventItem>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('event_id')->addOrderBy('position');
		return $this->findEntities($qb);
	}

	/**
	 * The number of items per event, counted by the database (the list of
	 * concerts needs no items).
	 *
	 * @return array<int, int> event id => items
	 */
	public function countPerEvent(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->groupBy('event_id');
		$result = $qb->executeQuery();
		$counts = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$counts[Row::int($row['event_id'])] = Row::int($row['cnt']);
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @return list<EventItem>
	 */
	public function findByAssignment(string $assignment): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('assignment', $qb->createNamedParameter($assignment)))
			->orderBy('event_id')->addOrderBy('position');
		return $this->findEntities($qb);
	}

	public function deleteByEvent(int $eventId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
