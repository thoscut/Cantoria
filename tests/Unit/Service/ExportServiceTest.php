<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\OdsWriter;
use OCA\Cantoria\Service\PersonService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The complete backup goes into the backup folder (outside the archive
 * folder), only the newest weekly backups are kept, and the backup holds no
 * link tokens and, for an annual snapshot, no accounts.
 */
#[CoversClass(ExportService::class)]
#[CoversClass(FullBackupService::class)]
final class ExportServiceTest extends TestCase {
	public function testOnlyTheNewestWeeklyBackupsAreKept(): void {
		$deleted = [];
		$listing = [];
		// Ten weekly backups of three files each, a manual backup and another file
		for ($week = 1; $week <= 10; $week++) {
			$base = sprintf('202609%02d-030000', $week);
			foreach (['_catalog_weekly.csv', '_catalog_weekly.ods', '_backup_weekly.json'] as $suffix) {
				$listing[] = $this->file($base . $suffix, $deleted);
			}
		}
		$listing[] = $this->file('20260101-120000_backup_manual.json', $deleted);
		$listing[] = $this->file('notes.txt', $deleted);
		$written = [];
		$folder = $this->createStub(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('getDirectoryListing')->willReturn($listing);
		$folder->method('newFile')->willReturnCallback(function (string $name) use (&$written): File {
			$written[] = $name;
			return $this->createStub(File::class);
		});
		$settings = $this->createStub(AppSettings::class);
		$settings->method('backupFolder')->willReturn($folder);
		$settings->method('backupFolderPath')->willReturn('Music archive backups');
		$settings->method('archiveInfo')->willReturn(['id' => 3, 'path' => 'Archive', 'user' => 'lena']);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-27 03:00:00'));
		$time->method('getTime')->willReturn(1793000000);
		$backup = $this->createStub(FullBackupService::class);
		$backup->method('exportStream')->willReturnCallback(fn () => fopen('php://memory', 'r'));
		$stored = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value) use (&$stored): bool {
			$stored[$key] = $value;
			return true;
		});
		$person = $this->createStub(PersonService::class);
		$person->method('table')->willReturn([['name'], []]);
		$service = new ExportService($this->createStub(WorkMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(CopyMapper::class), $settings, $time, $this->createStub(IDateTimeZone::class),
			$this->createStub(AuditEntryMapper::class), $this->createStub(OdsWriter::class), $person, $backup, $config);

		$result = $service->storeBackup('weekly', 'lena');

		self::assertSame(['20261027-030000_catalog_weekly.csv', '20261027-030000_catalog_weekly.ods', '20261027-030000_backup_weekly.json'], $written);
		self::assertSame('lena', $result['account']);
		self::assertSame('Music archive backups/20261027-030000_backup_weekly.json', $result['paths'][2]);
		self::assertSame(1793000000, $stored[ExportService::LAST_BACKUP_KEY]);
		// Of the ten weekly backups the folder lists, the newest eight stay.
		sort($deleted);
		self::assertSame([
			'20260901-030000_backup_weekly.json', '20260901-030000_catalog_weekly.csv', '20260901-030000_catalog_weekly.ods',
			'20260902-030000_backup_weekly.json', '20260902-030000_catalog_weekly.csv', '20260902-030000_catalog_weekly.ods',
		], $deleted);
	}

	public function testTheImportTemplateIsTheCatalogFormatOrOneSheet(): void {
		$sheets = null;
		$ods = $this->createStub(OdsWriter::class);
		$ods->method('write')->willReturnCallback(function (array $written) use (&$sheets): string {
			$sheets = $written;
			return 'ods';
		});
		$service = new ExportService($this->createStub(WorkMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(AppSettings::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->createStub(AuditEntryMapper::class), $ods,
			$this->createStub(PersonService::class), $this->createStub(FullBackupService::class), $this->createStub(IAppConfig::class));
		$template = CatalogCsv::template();

		self::assertSame(CatalogCsv::write($template['header'], $template['rows']), $service->importTemplate('csv'));
		self::assertSame('ods', $service->importTemplate('ods'));
		self::assertSame([['name' => 'Catalog', 'rows' => [$template['header'], ...$template['rows']]]], $sheets);
	}

	public function testABackupHoldsNoLinkTokensAndASnapshotNoAccounts(): void {
		$link = ['id' => 4, 'token' => hash('sha256', 'abcdefghijklmnopqrstuvwxyz012345'), 'token_secret' => 'encrypted', 'created_by' => 'lena'];
		self::assertSame(['id' => 4, 'token' => '', 'token_secret' => null, 'created_by' => 'lena'], FullBackupService::exportRow('cantoria_links', $link, false));
		$version = ['id' => 2, 'confirmed_by' => 'lena', 'confirmed_by_name' => 'Lena', 'valid_set_by' => null, 'rights_status' => 'free'];
		self::assertSame($version, FullBackupService::exportRow('cantoria_versions', $version, false));
		self::assertSame(['id' => 2, 'confirmed_by' => '', 'confirmed_by_name' => '', 'valid_set_by' => null, 'rights_status' => 'free'],
			FullBackupService::exportRow('cantoria_versions', $version, true));
		foreach (['cantoria_folder_loans', 'cantoria_audit', 'cantoria_links', 'cantoria_tasks', 'cantoria_proposals'] as $table) {
			self::assertNotContains($table, FullBackupService::SNAPSHOT_TABLES, $table . ' names people or holds tokens');
			self::assertContains($table, FullBackupService::TABLES);
		}
	}

	/**
	 * @param list<string> $deleted
	 */
	private function file(string $name, array &$deleted): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('delete')->willReturnCallback(function () use ($name, &$deleted): void {
			$deleted[] = $name;
		});
		return $file;
	}
}
