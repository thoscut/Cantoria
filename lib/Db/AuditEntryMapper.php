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
 * The audit log is append-only. There is deliberately no method to delete
 * entries, and only few to change them, all for data protection: the
 * retention period of the folder book (FolderBookService) replaces the
 * personal data of a folder loan in that loan's own entries; a deleted
 * account and an erasure on request (PrivacyService) remove the account or
 * the name of who made an entry, and from the old and new values of the
 * fields that hold an account or a name (a confirmation records who
 * confirmed as its new value, R B27).
 *
 * @template-extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_audit', AuditEntry::class);
	}

	/**
	 * @param list<array{0: string, 1: int}> $objects pairs of object type and id
	 * @return list<AuditEntry> newest first
	 */
	public function findForObjects(array $objects, int $limit = 200): array {
		if ($objects === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$conditions = [];
		foreach ($objects as [$type, $id]) {
			$conditions[] = $qb->expr()->andX(
				$qb->expr()->eq('object_type', $qb->createNamedParameter($type)),
				$qb->expr()->eq('object_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			);
		}
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(...$conditions))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @return list<AuditEntry> oldest first, for the export
	 */
	public function findAllInOrder(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $excludeTypes object types to leave out (e.g. the folder book for roles that may not read it)
	 * @return list<AuditEntry> newest first
	 */
	public function findLatest(int $limit = 200, array $excludeTypes = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		if ($excludeTypes !== []) {
			$qb->where($qb->expr()->notIn('object_type', $qb->createNamedParameter($excludeTypes, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Replaces the old and new values of some fields in the entries of one
	 * object with a placeholder (values that were empty stay empty). Used
	 * only for the personal data of a folder loan after its retention period.
	 *
	 * @param list<string> $fields
	 */
	public function anonymizeFields(string $objectType, int $objectId, array $fields, string $placeholder): void {
		foreach (['old_value', 'new_value'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set($column, $qb->createNamedParameter($placeholder))
				->where($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
				->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('field', $qb->createNamedParameter($fields, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNotNull($column));
			$qb->executeStatement();
		}
	}

	/**
	 * Entries made by an account or under a display name.
	 *
	 * @return list<AuditEntry> oldest first
	 */
	public function findByActor(string $uid, string $name): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('user_name', $qb->createNamedParameter($name)),
			))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Entries of one of the fields that have an old or a new value, for the
	 * fields whose values hold an account or a name (PrivacyService). The
	 * values are compared in PHP, so that neither the database nor its
	 * collation decides about upper and lower case.
	 *
	 * @param list<string> $fields
	 * @return list<AuditEntry> oldest first
	 */
	public function findWithValues(array $fields): array {
		if ($fields === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('field', $qb->createNamedParameter($fields, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->orX($qb->expr()->isNotNull('old_value'), $qb->expr()->isNotNull('new_value')))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Sets the old and the new value of one entry (erasure of an account or
	 * a name from the values, PrivacyService).
	 */
	public function replaceValues(int $id, ?string $oldValue, ?string $newValue): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('old_value', $qb->createNamedParameter($oldValue, $oldValue === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('new_value', $qb->createNamedParameter($newValue, $newValue === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Removes the account from the entries it made; the display name
	 * becomes the given one, or stays when null.
	 *
	 * @return int the number of entries changed
	 */
	public function removeAccount(string $uid, ?string $name): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		if ($name !== null) {
			$qb->set('user_name', $qb->createNamedParameter($name));
		}
		return $qb->executeStatement();
	}

	/**
	 * Removes account and display name from the entries made before a time,
	 * and replaces the old and new values of the fields that hold an
	 * account or a name ($valueFields, e.g. who confirmed a rights status)
	 * with a placeholder; the roles stay, so that it is still known which
	 * function made a change (retention period of the audit log,
	 * PrivacyService). Values that were empty stay empty. Plain updates
	 * with the query builder, the same on MySQL/MariaDB, PostgreSQL and
	 * SQLite; a second run changes nothing.
	 *
	 * @param list<string> $valueFields
	 * @return int the number of entries changed
	 */
	public function pseudonymizeBefore(int $time, array $valueFields = [], string $placeholder = ''): int {
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('id'))->from($this->getTableName())
			->where($count->expr()->lt('time', $count->createNamedParameter($time, IQueryBuilder::PARAM_INT)));
		$pending = [$count->expr()->isNotNull('user_id'), $count->expr()->isNotNull('user_name')];
		if ($valueFields !== []) {
			$values = [];
			foreach (['old_value', 'new_value'] as $column) {
				$values[] = $count->expr()->andX($count->expr()->isNotNull($column),
					$count->expr()->neq($column, $count->createNamedParameter($placeholder)));
			}
			$pending[] = $count->expr()->andX(
				$count->expr()->in('field', $count->createNamedParameter($valueFields, IQueryBuilder::PARAM_STR_ARRAY)),
				$count->expr()->orX(...$values));
		}
		$count->andWhere($count->expr()->orX(...$pending));
		$result = $count->executeQuery();
		$changed = Row::int($result->fetchOne());
		$result->closeCursor();
		if ($changed === 0) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('user_name', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->lt('time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX($qb->expr()->isNotNull('user_id'), $qb->expr()->isNotNull('user_name')));
		$qb->executeStatement();
		if ($valueFields === []) {
			return $changed;
		}
		foreach (['old_value', 'new_value'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set($column, $qb->createNamedParameter($placeholder))
				->where($qb->expr()->lt('time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('field', $qb->createNamedParameter($valueFields, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNotNull($column))
				->andWhere($qb->expr()->neq($column, $qb->createNamedParameter($placeholder)));
			$qb->executeStatement();
		}
		return $changed;
	}

	/**
	 * Replaces a display name in the entries made under it.
	 *
	 * @return int the number of entries changed
	 */
	public function replaceName(string $name, string $placeholder): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('user_name', $qb->createNamedParameter($placeholder))
			->where($qb->expr()->eq('user_name', $qb->createNamedParameter($name)));
		return $qb->executeStatement();
	}
}
