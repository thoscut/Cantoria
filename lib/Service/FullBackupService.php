<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Complete, lossless backup of all app tables as JSON, and restore into an
 * empty catalog.
 *
 * The CSV and ODS exports are for people (readable without the app); this
 * backup is for machines: it keeps the ids and therefore shared works and
 * versions, licenses, proposals, tasks, links, events, persons, merges, the
 * folder book and the audit log.
 *
 * Instance-specific settings (role groups, archive folder, the target
 * folders of the print station and of the hand-over) are not part of the
 * backup. The manifest of those folders (which files the app wrote there)
 * is, so that after a restore on the same instance the app knows its own
 * files again. Nextcloud file ids are only valid within one instance: after
 * a restore on another instance, the file sync matches the files again by
 * their path, and the folders treat their files as written by someone else
 * until they are chosen again.
 *
 * Format version 2 names the tables by their logical names (TABLE_NAMES,
 * e.g. `works`), without the prefix of the database tables, so that a
 * renamed table does not change the format. Version 1 used the names of the
 * database tables (`cantoria_works`); such backups are still read. A file
 * without any known table is refused.
 *
 * @psalm-type BackupRow = array<string, string|int|float|bool|null>
 * @psalm-type Backup = array{formatVersion: int, created: string, appVersion: string, tables: array<string, list<BackupRow>>}
 */
class FullBackupService {
	public const FORMAT = 'cantoria-backup';
	public const FORMAT_VERSION = 2;

	/** Largest backup file accepted for a restore in the web interface (bytes) */
	public const MAX_BYTES = 64 * 1024 * 1024;
	/** JSON nesting accepted when parsing; a backup needs five levels */
	private const MAX_DEPTH = 8;

	public const KIND_INTEGER = 'integer';
	public const KIND_BOOLEAN = 'boolean';
	public const KIND_TEXT = 'text';

	/** Tables in an order that satisfies the references on restore */
	public const TABLES = [
		'cantoria_works', 'cantoria_versions', 'cantoria_copies', 'cantoria_files', 'cantoria_states', 'cantoria_licenses',
		'cantoria_proposals', 'cantoria_tasks', 'cantoria_links', 'cantoria_events', 'cantoria_event_items',
		'cantoria_persons', 'cantoria_contributions', 'cantoria_merges', 'cantoria_review_reports', 'cantoria_review_points',
		'cantoria_folders', 'cantoria_folder_loans', 'cantoria_mirror_files', 'cantoria_audit',
	];

	/** Name of each table in the backup file (format version 2) => table in the database */
	public const TABLE_NAMES = [
		'works' => 'cantoria_works', 'versions' => 'cantoria_versions', 'copies' => 'cantoria_copies', 'files' => 'cantoria_files',
		'states' => 'cantoria_states', 'licenses' => 'cantoria_licenses', 'proposals' => 'cantoria_proposals', 'tasks' => 'cantoria_tasks',
		'links' => 'cantoria_links', 'events' => 'cantoria_events', 'event_items' => 'cantoria_event_items', 'persons' => 'cantoria_persons',
		'contributions' => 'cantoria_contributions', 'merges' => 'cantoria_merges', 'review_reports' => 'cantoria_review_reports',
		'review_points' => 'cantoria_review_points', 'folders' => 'cantoria_folders', 'folder_loans' => 'cantoria_folder_loans',
		'mirror_files' => 'cantoria_mirror_files', 'audit' => 'cantoria_audit',
	];

	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private ITimeFactory $time,
		private AuditLog $audit,
		private IConfig $config,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Tables of an annual snapshot: the catalog without the folder book,
	 * the audit log, links, tasks, proposals and reviews, which name people
	 * or accounts or hold link tokens.
	 */
	public const SNAPSHOT_TABLES = [
		'cantoria_works', 'cantoria_versions', 'cantoria_copies', 'cantoria_files', 'cantoria_states', 'cantoria_licenses',
		'cantoria_events', 'cantoria_event_items', 'cantoria_persons', 'cantoria_contributions', 'cantoria_folders',
	];
	/** Columns with accounts or their names, emptied in an annual snapshot */
	public const ACCOUNT_COLUMNS = ['confirmed_by', 'confirmed_by_name', 'valid_set_by', 'created_by', 'created_by_name', 'checked_by'];

	public function export(): string {
		$stream = $this->exportStream();
		try {
			return (string)stream_get_contents($stream);
		} finally {
			fclose($stream);
		}
	}

	/**
	 * The backup as a stream, written row by row into a temporary stream
	 * that moves to disk when it grows (not the whole backup in memory).
	 * Link tokens are left out: links come back revoked after a restore.
	 * For an annual snapshot only SNAPSHOT_TABLES, without accounts.
	 *
	 * @return resource at its start
	 */
	public function exportStream(bool $snapshot = false) {
		$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		if ($stream === false) {
			throw new \RuntimeException('cannot open a temporary stream');
		}
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
		$head = json_encode([
			'format' => self::FORMAT,
			'formatVersion' => self::FORMAT_VERSION,
			'app' => Application::APP_ID,
			'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'created' => $this->time->getDateTime()->format(DATE_ATOM),
		], $flags);
		fwrite($stream, substr($head, 0, -1) . ",\n\"tables\": {");
		$tables = $snapshot ? array_values(array_intersect($this->existingTables(), self::SNAPSHOT_TABLES)) : $this->existingTables();
		foreach ($tables as $t => $table) {
			fwrite($stream, ($t === 0 ? '' : ',') . "\n" . json_encode(self::logicalName($table), $flags) . ': [');
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($table)->orderBy('id');
			$result = $qb->executeQuery();
			$first = true;
			while (($row = $result->fetchAssociative()) !== false) {
				fwrite($stream, ($first ? '' : ',') . "\n" . json_encode(self::exportRow($table, $row, $snapshot), $flags));
				$first = false;
			}
			$result->closeCursor();
			fwrite($stream, ']');
		}
		fwrite($stream, "\n}}\n");
		rewind($stream);
		return $stream;
	}

	/**
	 * A row as it goes into the backup: without the token of a link, and in
	 * an annual snapshot without accounts.
	 *
	 * @param array<array-key, mixed> $row
	 * @return array<array-key, mixed>
	 */
	public static function exportRow(string $table, array $row, bool $snapshot): array {
		if ($table === 'cantoria_links' && array_key_exists('token', $row)) {
			$row['token'] = '';
		}
		if ($table === 'cantoria_links' && array_key_exists('token_secret', $row)) {
			$row['token_secret'] = null;
		}
		if ($snapshot) {
			foreach (self::ACCOUNT_COLUMNS as $column) {
				if (is_string($row[$column] ?? null)) {
					$row[$column] = '';
				}
			}
		}
		return $row;
	}

	/**
	 * Whether a restore is possible: every table of the app is empty (the
	 * same check as the restore itself, which repeats it in its transaction).
	 */
	public function isEmpty(): bool {
		return array_sum($this->counts()) === 0;
	}

	/**
	 * Rows per table of the app (installed tables only), e.g. for the setup
	 * steps of the settings page.
	 *
	 * @return array<string, int> table => rows
	 */
	public function counts(): array {
		$counts = [];
		foreach ($this->existingTables() as $table) {
			$counts[$table] = $this->count($table);
		}
		return $counts;
	}

	/**
	 * Restores a backup into an empty catalog. Rows are inserted with their
	 * original ids; the whole restore runs in one transaction.
	 *
	 * Only tables from TABLES and only columns that exist in the installed
	 * schema are written (column names from the file are never used as SQL
	 * identifiers unchecked); every value is checked against the type, length
	 * and nullability of its column.
	 *
	 * @return array<string, int> table => restored rows
	 */
	public function restore(string $content, string $uid): array {
		$backup = self::parse($content);
		$existing = $this->existingTables();

		$restored = [];
		$this->db->beginTransaction();
		try {
			// Checked inside the transaction, right before writing, so that a
			// second restore or an import started meanwhile cannot slip in
			// between; rows with the same ids then collide on the primary key.
			if (!$this->isEmpty()) {
				throw new InvalidInputException('A backup can only be restored into an empty catalog.');
			}
			$schema = $this->db->createSchema();
			foreach (self::TABLES as $table) {
				$rows = $backup['tables'][$table] ?? [];
				if (!in_array($table, $existing, true)) {
					continue;
				}
				$columns = $this->columns($schema, $table);
				foreach ($rows as $index => $row) {
					if ($table === 'cantoria_links' && ($row['token'] ?? '') === '') {
						// Backups hold no tokens: the link comes back revoked.
						// Only the hash of a token nobody knows: the link stays dead.
						$row['token'] = ExcerptLink::hashToken($this->random->generate(LinkTokens::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
						$row['token_secret'] = null;
						$row['revoked'] ??= $this->time->getTime();
					}
					$qb = $this->db->getQueryBuilder();
					$qb->insert($table);
					$written = 0;
					foreach ($row as $name => $value) {
						$column = $columns[$name] ?? null;
						if ($column === null) {
							// Unknown columns (e.g. of another app version) are left out.
							continue;
						}
						$typed = self::columnValue(self::columnKind($column), self::columnLength($column), $column->getNotnull(), $value);
						if ($typed === null) {
							throw new InvalidInputException('The backup contains an invalid value in %1$s, row %2$s, column %3$s.',
								[$table, $index + 1, $name]);
						}
						$qb->setValue($name, $qb->createNamedParameter($typed[0], $typed[1]));
						$written++;
					}
					if ($written === 0) {
						throw new InvalidInputException('The file is not a backup of the music archive.');
					}
					$qb->executeStatement();
				}
				$restored[$table] = count($rows);
				$this->resetSequence($table);
			}
			$this->audit->record($this->audit->newOperation(), $uid, 'import', null, 'restore_backup', null, null,
				'backup of ' . mb_substr($backup['created'], 0, 64) . ', app ' . mb_substr($backup['appVersion'], 0, 32));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $restored;
	}

	/**
	 * Checks a value from the backup against its column and converts it for
	 * binding: whole numbers (also as digits in text, as some databases export
	 * them), yes/no (also 0/1, "t"/"f"), and text up to the column length.
	 *
	 * @param self::KIND_* $kind
	 * @return array{0: string|int|bool|null, 1: IQueryBuilder::PARAM_STR|IQueryBuilder::PARAM_INT|IQueryBuilder::PARAM_BOOL}|null null when the value does not fit
	 */
	public static function columnValue(string $kind, ?int $length, bool $notNull, string|int|float|bool|null $value): ?array {
		if ($value === null) {
			return $notNull ? null : [null, IQueryBuilder::PARAM_STR];
		}
		switch ($kind) {
			case self::KIND_INTEGER:
				if (is_bool($value)) {
					return [$value ? 1 : 0, IQueryBuilder::PARAM_INT];
				}
				if (is_int($value)) {
					return [$value, IQueryBuilder::PARAM_INT];
				}
				if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
					return [(int)$value, IQueryBuilder::PARAM_INT];
				}
				return null;
			case self::KIND_BOOLEAN:
				$bool = match (true) {
					is_bool($value) => $value,
					$value === 1, $value === '1', $value === 't', $value === 'true' => true,
					$value === 0, $value === '0', $value === 'f', $value === 'false' => false,
					default => null,
				};
				return $bool === null ? null : [$bool, IQueryBuilder::PARAM_BOOL];
			default:
				if (is_bool($value)) {
					return null;
				}
				$text = (string)$value;
				if ($length !== null && mb_strlen($text) > $length) {
					return null;
				}
				return [$text, IQueryBuilder::PARAM_STR];
		}
	}

	/**
	 * The maximum length in characters, only for columns of a fixed length
	 * (VARCHAR). Long text columns have no limit to check here: MySQL and
	 * MariaDB report them with the length 0 or in bytes.
	 */
	public static function columnLength(Column $column): ?int {
		$length = $column->getLength();
		return $column->getType() instanceof StringType && $length !== null && $length > 0 ? $length : null;
	}

	/**
	 * @return self::KIND_*
	 */
	private static function columnKind(Column $column): string {
		$type = $column->getType();
		return match (true) {
			$type instanceof BooleanType => self::KIND_BOOLEAN,
			$type instanceof IntegerType, $type instanceof BigIntType, $type instanceof SmallIntType => self::KIND_INTEGER,
			default => self::KIND_TEXT,
		};
	}

	/**
	 * The name of a database table in the backup file (TABLE_NAMES).
	 */
	public static function logicalName(string $table): string {
		$name = array_search($table, self::TABLE_NAMES, true);
		return $name === false ? $table : $name;
	}

	/**
	 * Checks that a file is a backup in a format this version can restore and
	 * returns its content, with the tables by their names in the database.
	 * Tables are named as in format version 2 (TABLE_NAMES) or, in backups
	 * of version 1, by the names of the database tables. Unknown tables are
	 * dropped, but a file without any known table is refused; every row
	 * must be an object of plain values.
	 *
	 * @return Backup
	 * @throws InvalidInputException
	 */
	public static function parse(string $content): array {
		try {
			// A backup is nested five levels deep (file, tables, table, row, value).
			$data = self::object(json_decode($content, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			$data = null;
		}
		if ($data === null || ($data['format'] ?? null) !== self::FORMAT || !is_int($data['formatVersion'] ?? null)) {
			throw new InvalidInputException('The file is not a backup of the music archive.');
		}
		$formatVersion = (int)$data['formatVersion'];
		if ($formatVersion > self::FORMAT_VERSION) {
			throw new InvalidInputException('The backup was made with a newer version of the app. Please update the app first.');
		}
		$tables = self::object($data['tables'] ?? null);
		if ($tables === null) {
			throw new InvalidInputException('The file is not a backup of the music archive.');
		}
		$parsed = [];
		foreach (self::TABLES as $table) {
			$name = self::logicalName($table);
			$key = $formatVersion >= 2 ? $name : $table;
			if (array_key_exists($key, $tables)) {
				$parsed[$table] = self::rows($tables[$key]);
			}
		}
		if ($parsed === []) {
			throw new InvalidInputException('The file contains no table of the music archive. Is it a backup of Cantoria?');
		}
		return [
			'formatVersion' => $formatVersion,
			'created' => is_string($data['created'] ?? null) ? (string)$data['created'] : '?',
			'appVersion' => is_string($data['appVersion'] ?? null) ? (string)$data['appVersion'] : '?',
			'tables' => $parsed,
		];
	}

	/**
	 * @return list<BackupRow>
	 * @throws InvalidInputException
	 */
	private static function rows(mixed $rows): array {
		if (!is_array($rows) || !array_is_list($rows)) {
			throw new InvalidInputException('The file is not a backup of the music archive.');
		}
		$result = [];
		foreach (array_keys($rows) as $index) {
			$row = self::object($rows[$index]);
			if ($row === null || $row === []) {
				throw new InvalidInputException('The file is not a backup of the music archive.');
			}
			$values = [];
			foreach (array_keys($row) as $column) {
				if (!is_string($column) || !self::isPlain($row[$column])) {
					throw new InvalidInputException('The file is not a backup of the music archive.');
				}
				$values[$column] = self::plain($row[$column]);
			}
			$result[] = $values;
		}
		return $result;
	}

	/**
	 * A JSON object as array, or null for anything else.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private static function object(mixed $value): ?array {
		return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
	}

	private static function isPlain(mixed $value): bool {
		return $value === null || is_scalar($value);
	}

	private static function plain(mixed $value): string|int|float|bool|null {
		return $value === null || is_scalar($value) ? $value : null;
	}

	/**
	 * @return list<string>
	 */
	private function existingTables(): array {
		return array_values(array_filter(self::TABLES, fn (string $t): bool => $this->db->tableExists($t)));
	}

	/**
	 * The columns of a table as installed (whitelist for the restore).
	 *
	 * @return array<string, Column> column name => column
	 */
	private function columns(Schema $schema, string $table): array {
		$prefixed = $schema->hasTable($table) ? $table : $this->tablePrefix() . $table;
		$columns = [];
		foreach ($schema->getTable($prefixed)->getColumns() as $column) {
			$columns[$column->getName()] = $column;
		}
		return $columns;
	}

	private function count(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * After inserting explicit ids, PostgreSQL sequences must be moved past
	 * the highest id; MySQL/MariaDB and SQLite do this by themselves.
	 */
	private function resetSequence(string $table): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
			return;
		}
		$name = $this->tablePrefix() . $table;
		$this->db->executeStatement("SELECT setval(pg_get_serial_sequence('" . $name . "', 'id'), COALESCE((SELECT MAX(id) FROM " . $name . '), 1))');
	}

	private function tablePrefix(): string {
		return $this->config->getSystemValueString('dbtableprefix', 'oc_');
	}
}
