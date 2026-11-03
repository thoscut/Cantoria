<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use OCP\IDBConnection;

/**
 * Finds the rows in which an account or a name is recorded as who created,
 * decided, checked or set something (tasks, links, proposals, review
 * points, …), for the access report of PrivacyService. Table and column
 * names come from the code (PrivacyService::ACCOUNT_FIELDS), never from a
 * request.
 */
class AccountRecordFinder {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param array<string, list<string>> $fields table => columns with an account or a name
	 * @return list<array{table: string, id: int, fields: array<string, string>}> the matching columns per row
	 */
	public function find(array $fields, string $who): array {
		$found = [];
		foreach ($fields as $table => $columns) {
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', ...$columns)->from($table)->orderBy('id');
			$or = [];
			foreach ($columns as $column) {
				$or[] = $qb->expr()->eq($column, $qb->createNamedParameter($who));
			}
			$qb->where($qb->expr()->orX(...$or));
			$result = $qb->executeQuery();
			while (($row = $result->fetchAssociative()) !== false) {
				$matched = [];
				foreach ($columns as $column) {
					if (($row[$column] ?? null) === $who) {
						$matched[$column] = $who;
					}
				}
				$found[] = ['table' => $table, 'id' => (int)$row['id'], 'fields' => $matched];
			}
			$result->closeCursor();
		}
		return $found;
	}
}
