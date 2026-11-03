<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IDateTimeZone;

/**
 * Exports the catalog as CSV.
 *
 * The extended format appends all app fields to the legacy columns; it is the
 * backup from which the catalog can be restored without the developer.
 */
class ExportService {
	/** App config: time of the last complete backup that was stored */
	public const LAST_BACKUP_KEY = 'last_backup';
	/** App config: since when backups are watched (first daily check) */
	private const WATCHED_SINCE_KEY = 'backup_watched_since';
	/** Weekly backups that are kept; older ones are removed */
	public const KEEP_WEEKLY = 8;
	/** Files of a weekly backup; group 1 tells the backups apart */
	private const WEEKLY_PATTERN = '/^(\d{8}-\d{6})_(?:catalog|backup)_weekly(-\d+)?\.(?:csv|ods|json)$/';

	public function __construct(
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private AppSettings $settings,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private AuditEntryMapper $auditEntries,
		private OdsWriter $odsWriter,
		private PersonService $persons,
		private FullBackupService $fullBackup,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param bool $snapshot for an annual snapshot: without the columns that
	 *                       hold accounts (FullBackupService::ACCOUNT_COLUMNS)
	 */
	public function csv(bool $extended = false, bool $snapshot = false): string {
		[$header, $rows] = $this->table($extended, $snapshot);
		return CatalogCsv::write($header, $rows);
	}

	/**
	 * The full backup as spreadsheet: catalog with all fields, the audit log
	 * and the register of persons with their contributions, readable without
	 * the app.
	 */
	public function ods(): string {
		[$header, $rows] = $this->table(true);
		$audit = [['id', 'operation', 'time', 'user_id', 'user_name', 'roles', 'object_type', 'object_id', 'action', 'field', 'old_value', 'new_value', 'reason']];
		foreach ($this->auditEntries->findAllInOrder() as $entry) {
			$data = $entry->jsonSerialize();
			$data['time'] = gmdate('Y-m-d H:i:s', (int)$data['time']) . ' UTC';
			$audit[] = array_map(fn (string $column): string => (string)($data[$column] ?? ''), $audit[0]);
		}
		[$personHeader, $personRows] = $this->persons->table();
		return $this->odsWriter->write([
			['name' => 'Catalog', 'rows' => array_merge([$header], $rows)],
			['name' => 'Audit log', 'rows' => $audit],
			['name' => 'Persons', 'rows' => array_merge([$personHeader], $personRows)],
		]);
	}

	/**
	 * The import template (CatalogCsv::template()) as CSV in the format of
	 * the import (UTF-8 with BOM, `;`) or as ODS for LibreOffice and Excel.
	 *
	 * @param string $format 'csv' or 'ods'
	 */
	public function importTemplate(string $format): string {
		['header' => $header, 'rows' => $rows] = CatalogCsv::template();
		if ($format === 'ods') {
			return $this->odsWriter->write([['name' => 'Catalog', 'rows' => array_merge([$header], $rows)]]);
		}
		return CatalogCsv::write($header, $rows);
	}

	/**
	 * Stores the full backup (extended CSV, ODS with the audit log and the
	 * complete JSON backup) in the backup folder, outside the archive folder
	 * (AppSettings::backupFolder()). Of the weekly backups the newest
	 * KEEP_WEEKLY stay; older ones go to the trash bin of the account.
	 *
	 * @return array{paths: list<string>, account: string} paths in the files of that account
	 */
	public function storeBackup(string $occasion, string $uid): array {
		$folder = $this->settings->backupFolder();
		$account = $this->settings->archiveInfo()['user'];
		$occasion = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $occasion) ?? 'backup', '-');
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid));
		$base = $now->format('Ymd-His');
		$suffix = '';
		for ($i = 2; $folder->nodeExists($base . '_catalog_' . $occasion . $suffix . '.csv'); $i++) {
			$suffix = '-' . $i;
		}
		$names = [
			$base . '_catalog_' . $occasion . $suffix . '.csv',
			$base . '_catalog_' . $occasion . $suffix . '.ods',
			$base . '_backup_' . $occasion . $suffix . '.json',
		];
		try {
			$folder->newFile($names[0], $this->csv(true));
			$folder->newFile($names[1], $this->ods());
			// Nextcloud closes the stream after writing it.
			$folder->newFile($names[2], $this->fullBackup->exportStream());
		} catch (NotPermittedException) {
			throw new InvalidInputException('The account %s may not write into the backup folder.', [$account]);
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::LAST_BACKUP_KEY, $this->time->getTime());
		if ($occasion === 'weekly') {
			$this->rotate($folder);
		}
		$prefix = $this->settings->backupFolderPath() ?? $folder->getName();
		return ['paths' => array_map(fn (string $name): string => $prefix . '/' . $name, $names), 'account' => $account];
	}

	/**
	 * Time of the last complete backup, or null when none has been stored
	 * by this version of the app.
	 */
	public function lastBackup(): ?int {
		$time = $this->appConfig->getValueInt(Application::APP_ID, self::LAST_BACKUP_KEY, 0);
		return $time === 0 ? null : $time;
	}

	/**
	 * Seconds since the last complete backup; without one, since the
	 * backups have been watched (the first call starts watching).
	 */
	public function lastBackupAge(): int {
		$now = $this->time->getTime();
		$since = $this->lastBackup() ?? $this->appConfig->getValueInt(Application::APP_ID, self::WATCHED_SINCE_KEY, 0);
		if ($since === 0) {
			$this->appConfig->setValueInt(Application::APP_ID, self::WATCHED_SINCE_KEY, $now);
			return 0;
		}
		return $now - $since;
	}

	/**
	 * Removes the weekly backups but the newest KEEP_WEEKLY (each made of a
	 * CSV, an ODS and a JSON file of the same time).
	 */
	private function rotate(Folder $folder): void {
		$sets = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && preg_match(self::WEEKLY_PATTERN, $node->getName(), $m) === 1) {
				$sets[$m[1] . ($m[2] ?? '')][] = $node;
			}
		}
		krsort($sets, SORT_STRING);
		foreach (array_slice($sets, self::KEEP_WEEKLY, null, true) as $nodes) {
			foreach ($nodes as $node) {
				$node->delete();
			}
		}
	}

	/**
	 * @return array{0: list<string>, 1: list<list<string>>}
	 */
	private function table(bool $extended, bool $snapshot = false): array {
		$columns = CatalogCsv::columns($extended);
		if ($snapshot) {
			$columns = array_filter($columns, fn (array $column): bool => !in_array($column[1], FullBackupService::ACCOUNT_COLUMNS, true));
		}
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();

		$rows = [];
		foreach ($this->copies->findAll() as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$objects = [Fields::COPY => $copy, Fields::VERSION => $version, Fields::WORK => $work];
			$row = [];
			foreach ($columns as $header => [$level, $field]) {
				$object = $objects[$level];
				$row[] = $object === null ? '' : CatalogCsv::toCsv($header, $object->getValue($field));
			}
			$rows[] = $row;
		}
		return [array_keys($columns), $rows];
	}

	/**
	 * Stores a dated export in the export folder and returns its path relative
	 * to the archive folder. Existing files are never overwritten.
	 */
	public function store(string $occasion, string $uid, bool $extended = true): string {
		$folder = $this->settings->exportFolder($uid);
		$occasion = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $occasion) ?? 'export', '-');
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid));
		$base = $now->format('Ymd-His') . '_catalog_' . $occasion;
		$name = $base . '.csv';
		for ($i = 2; $folder->nodeExists($name); $i++) {
			$name = $base . '-' . $i . '.csv';
		}
		try {
			$folder->newFile($name, $this->csv($extended));
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
		return AppSettings::EXPORT_FOLDER . '/' . $name;
	}
}
