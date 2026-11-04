<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\BoundedZip;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\ImportService;
use OCA\Cantoria\Service\OdsReader;
use OCA\Cantoria\Service\OdsWriter;
use OCA\Cantoria\Service\XlsxReader;
use OCA\Cantoria\Tests\Unit\Support\TempFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Import of the catalog CSV with mappers, database and export replaced by test
 * doubles. The doubles record what the service does, so that each test can
 * check the resulting entities, the transaction and the audit log. The same
 * columns come from an Excel workbook or an OpenDocument spreadsheet, read
 * by the real readers (U32).
 */
#[CoversClass(ImportService::class)]
#[UsesClass(CatalogCsv::class)]
#[UsesClass(XlsxReader::class)]
#[UsesClass(OdsReader::class)]
#[UsesClass(OdsWriter::class)]
#[UsesClass(BoundedZip::class)]
final class ImportServiceTest extends TestCase {
	use TempFiles;
	private const NOW = 1761134400;
	private const UID = 'anna';
	private const SNAPSHOT = '_cantoria-app/20261022-120000_catalog_before-import.csv';

	private ImportService $service;

	/** @var array<int, Work> */
	private array $works = [];
	/** @var array<int, WorkVersion> */
	private array $versions = [];
	/** @var array<string, Copy> */
	private array $copies = [];

	/** @var list<Work|WorkVersion|Copy> */
	private array $inserted = [];
	/** @var list<Work|WorkVersion|Copy> */
	private array $updated = [];
	/** @var list<string> */
	private array $transaction = [];
	/** @var list<list<string>> */
	private array $snapshots = [];
	/** @var list<array{objectType: string, objectId: ?int, action: string, field: ?string, old: mixed, new: mixed, reason: ?string}> */
	private array $audit = [];
	private ?\Throwable $failOnInsert = null;
	private int $nextId = 1000;

	#[\Override]
	protected function setUp(): void {
		$db = $this->createStub(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(function () use ($method): void {
				$this->transaction[] = $method;
			});
		}

		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturnCallback(fn (): array => $this->works);
		$works->method('insert')->willReturnCallback($this->insert(...));
		$works->method('update')->willReturnCallback($this->update(...));

		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturnCallback(fn (): array => $this->versions);
		$versions->method('insert')->willReturnCallback($this->insert(...));
		$versions->method('update')->willReturnCallback($this->update(...));

		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAllByShelfmark')->willReturnCallback(fn (): array => $this->copies);
		$copies->method('insert')->willReturnCallback($this->insert(...));
		$copies->method('update')->willReturnCallback($this->update(...));

		$export = $this->createStub(ExportService::class);
		$export->method('snapshot')->willReturnCallback(function (string $occasion, string $uid): string {
			$this->snapshots[] = [$occasion, $uid];
			return self::SNAPSHOT;
		});

		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op-1');
		$audit->method('record')->willReturnCallback(
			function (string $operation, ?string $uid, string $objectType, ?int $objectId, string $action, ?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
				self::assertSame('op-1', $operation);
				self::assertSame(self::UID, $uid);
				$this->audit[] = ['objectType' => $objectType, 'objectId' => $objectId, 'action' => $action, 'field' => $field, 'old' => $old, 'new' => $new, 'reason' => $reason];
			});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new ImportService($db, $works, $versions, $copies, $export, $audit, $time,
			new XlsxReader($this->tempManager()), new OdsReader($this->tempManager()));
	}

	private function insert(Work|WorkVersion|Copy $entity): Work|WorkVersion|Copy {
		if ($this->failOnInsert !== null) {
			throw $this->failOnInsert;
		}
		$entity->setId($this->nextId++);
		$this->inserted[] = $entity;
		return $entity;
	}

	private function update(Work|WorkVersion|Copy $entity): Work|WorkVersion|Copy {
		$this->updated[] = $entity;
		return $entity;
	}

	/**
	 * Adds a catalog entry as if loaded from the database.
	 *
	 * @param array<string, mixed> $work
	 * @param array<string, mixed> $version
	 * @return array{0: Work, 1: WorkVersion, 2: Copy}
	 */
	private function givenEntry(string $shelfmark, array $work = [], array $version = []): array {
		$n = count($this->copies) + 1;
		$workEntity = new Work();
		$workEntity->setId($n);
		$versionEntity = new WorkVersion();
		$versionEntity->setId(10 * $n);
		$versionEntity->setWorkId($n);
		$copyEntity = new Copy();
		$copyEntity->setId(100 * $n);
		$copyEntity->setVersionId(10 * $n);
		[$binder, $number] = array_map('intval', explode('-', $shelfmark));
		$copyEntity->setShelfmark($shelfmark);
		$copyEntity->setBinder($binder);
		$copyEntity->setNumber($number);
		$copyEntity->setSortOrder($n);
		foreach ($work as $column => $value) {
			$workEntity->setValue($column, $value);
		}
		foreach ($version as $column => $value) {
			$versionEntity->setValue($column, $value);
		}
		foreach ([$workEntity, $versionEntity, $copyEntity] as $entity) {
			$entity->resetUpdatedFields();
		}
		$this->works[$n] = $workEntity;
		$this->versions[10 * $n] = $versionEntity;
		$this->copies[$shelfmark] = $copyEntity;
		return [$workEntity, $versionEntity, $copyEntity];
	}

	/**
	 * @param list<string> $header
	 * @param list<string> ...$rows
	 */
	private static function csv(array $header, array ...$rows): string {
		return CatalogCsv::write($header, $rows);
	}

	/**
	 * @param array<string, mixed> $report
	 * @return list<string> warnings with their parameters filled in
	 */
	private static function warnings(array $report): array {
		/** @var list<array{0: string, 1: list<string|int>}> $warnings */
		$warnings = $report['warnings'];
		return array_map(fn (array $w): string => vsprintf($w[0], $w[1]), $warnings);
	}

	/**
	 * @template T of Work|WorkVersion|Copy
	 * @param class-string<T> $class
	 * @return list<T>
	 */
	private function insertedOf(string $class): array {
		return array_values(array_filter($this->inserted, fn (object $e): bool => $e instanceof $class));
	}

	// ---- dry run, snapshot, transaction ----

	public function testDryRunPerformsTheImportAndRollsBack(): void {
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['5-1', 'Ave verum corpus']), 'catalog.csv', self::UID, true);

		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
		self::assertCount(1, $this->insertedOf(Work::class), 'the dry run runs the real import inside the transaction');
		self::assertTrue($report['dryRun']);
		self::assertNull($report['operation']);
		self::assertSame(1, $report['created']);
		self::assertNotContains('import', array_column($this->audit, 'objectType'), 'no import record');
		self::assertSame([], $this->snapshots);
	}

	/**
	 * W B28: the example row of the template is left out with a note, so
	 * that a forgotten one neither enters the catalog nor pushes out the
	 * real copy 1-1; the rest imports without warnings.
	 */
	public function testTheTemplateImportsWithoutItsExampleRow(): void {
		$template = CatalogCsv::template();
		$real = array_map(fn (string $column): string => ['Mappe' => '1', 'Nr' => '1', 'Signatur' => '1-1', 'Titel' => 'Ave verum corpus'][$column] ?? '',
			$template['header']);
		$report = $this->service->import(CatalogCsv::write($template['header'], [...$template['rows'], $real]), 'template.csv', self::UID, true);

		self::assertSame([1, 1], [$report['created'], $report['rows']]);
		self::assertSame('Ave verum corpus', $this->insertedOf(Work::class)[0]->getTitle(), 'the real 1-1, not the example');
		self::assertSame(['Row 1 is the example row of the template and was not imported.'], self::warnings($report),
			'no missing columns, no invalid value, no duplicate 1-1');
	}

	public function testTheExampleRowOfAnEarlierTemplateIsLeftOutToo(): void {
		$this->service->import(self::csv(['Signatur', 'Titel', 'Komponist', 'Anmerkung'],
			['1-1', 'Abendlied', 'Josef Rheinberger', 'Beispielzeile: vor dem Import ersetzen oder löschen'],
			['1-2', 'Abendlied', 'Josef Rheinberger', 'Unsere Ausgabe von 1998']), 'catalog.csv', self::UID, true);
		self::assertSame(['1-2'], array_map(fn (Copy $c): string => $c->getShelfmark(), $this->insertedOf(Copy::class)),
			'a real copy of the same piece stays');
	}

	public function testTheExampleRowSaysHowAShelfMarkIsFormed(): void {
		self::assertStringContainsString('Mappe-Nummer', CatalogCsv::TEMPLATE_EXAMPLE['Anmerkung'], 'W B25');
	}

	public function testDryRunOfANonEmptyCatalogTakesNoSnapshot(): void {
		$this->givenEntry('5-1', ['title' => 'Ave verum corpus']);
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['5-1', 'Ave verum']), 'catalog.csv', self::UID, true);

		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
		self::assertSame([], $this->snapshots);
		self::assertNull($report['snapshot']);
		self::assertFalse($report['restore']);
	}

	public function testImportIntoANonEmptyCatalogTakesASnapshotAndCommits(): void {
		$this->givenEntry('5-1', ['title' => 'Ave verum corpus']);
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['5-1', 'Ave verum corpus'], ['5-2', 'Locus iste']),
			'catalog.csv', self::UID, false);

		self::assertSame([['before-import', self::UID]], $this->snapshots);
		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
		self::assertSame(self::SNAPSHOT, $report['snapshot']);
		self::assertSame('op-1', $report['operation']);
		self::assertFalse($report['restore']);
		self::assertSame([2, 1, 0, 1], [$report['rows'], $report['created'], $report['changed'], $report['unchanged']]);
		self::assertSame(['objectType' => 'import', 'objectId' => null, 'action' => 'import', 'field' => null, 'old' => null, 'new' => 'catalog.csv', 'reason' => null],
			end($this->audit));
		self::assertSame(hash('sha256', self::csv(['Signatur', 'Titel'], ['5-1', 'Ave verum corpus'], ['5-2', 'Locus iste'])), $report['sha256']);
	}

	public function testImportIntoAnEmptyCatalogIsARestoreWithoutSnapshot(): void {
		$report = $this->service->import(self::csv(['Signatur'], ['5-1']), 'backup.csv', self::UID, false);

		self::assertSame([], $this->snapshots);
		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
		self::assertTrue($report['restore']);
		self::assertSame('restore', end($this->audit)['action']);
	}

	public function testAFailureRollsBackAndIsRethrown(): void {
		$this->failOnInsert = new \RuntimeException('database gone');
		try {
			$this->service->import(self::csv(['Signatur'], ['5-1']), 'catalog.csv', self::UID, false);
			self::fail('The exception must be rethrown');
		} catch (\RuntimeException $e) {
			self::assertSame('database gone', $e->getMessage());
		}
		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
	}

	public function testACsvWithoutShelfMarkColumnIsRejectedBeforeAnyChange(): void {
		try {
			$this->service->import(self::csv(['Titel'], ['Ave']), 'other.csv', self::UID, false);
			self::fail('The CSV must be rejected');
		} catch (InvalidInputException $e) {
			self::assertStringContainsString('"Signatur"', $e->getMessage());
		}
		self::assertSame([], $this->transaction);
		self::assertSame([], $this->snapshots);
	}

	// ---- invalid values ----

	public function testInvalidValuesAreReportedAndNotImported(): void {
		$header = ['Mappe', 'Signatur', 'Titel', 'Komponist', 'Rechtestatus', 'version.duration', 'copy.last_counted'];
		$report = $this->service->import(self::csv($header,
			['abc', '5-1', str_repeat('x', 501), 'W. A. Mozart', 'vielleicht', 'long', '2026-02-31'],
			['5', '5-2', 'Locus iste', 'A. Bruckner', 'gemeinfrei', '180', '2026-02-28'],
		), 'catalog.csv', self::UID, false);

		$warnings = self::warnings($report);
		foreach (['Mappe', 'Titel', 'Rechtestatus', 'version.duration', 'copy.last_counted'] as $column) {
			$prefix = 'Row 1, column "' . $column . '": the value';
			self::assertNotEmpty(array_filter($warnings, fn (string $w): bool => str_starts_with($w, $prefix)), $column);
		}
		self::assertCount(5, array_filter($warnings, fn (string $w): bool => str_contains($w, 'is not valid there and was not imported')));

		[$work1, $work2] = $this->insertedOf(Work::class);
		[$version1, $version2] = $this->insertedOf(WorkVersion::class);
		[$copy1, $copy2] = $this->insertedOf(Copy::class);
		// row 1: invalid cells are left empty, valid cells are taken
		self::assertNull($work1->getTitle());
		self::assertSame('W. A. Mozart', $work1->getComposer());
		self::assertNull($version1->getRightsStatus());
		self::assertNull($version1->getValue('duration'));
		self::assertNull($copy1->getValue('last_counted'));
		self::assertSame(5, $copy1->getBinder(), 'binder falls back to the shelf mark');
		// row 2: everything valid, legacy rights status translated
		self::assertSame('Locus iste', $work2->getTitle());
		self::assertSame('public_domain', $version2->getRightsStatus());
		self::assertSame('public_domain', $version2->getValue('rights_status_import'));
		self::assertSame(180, $version2->getValue('duration'));
		self::assertSame('2026-02-28', $copy2->getValue('last_counted'));
	}

	public function testAnInvalidValueKeepsTheStoredValue(): void {
		[, $version] = $this->givenEntry('5-1', [], ['rights_status' => 'public_domain']);
		$report = $this->service->import(self::csv(['Signatur', 'Rechtestatus'], ['5-1', 'unklar']), 'catalog.csv', self::UID, false);

		self::assertSame('public_domain', $version->getRightsStatus());
		self::assertSame([], $this->updated);
		self::assertSame(1, $report['unchanged']);
		self::assertContains('Row 1, column "Rechtestatus": the value "unklar" is not valid there and was not imported.', self::warnings($report));
	}

	// ---- decisions ----

	public function testDecisionFieldsAreNeverOverwritten(): void {
		[, $version] = $this->givenEntry('5-1', [], ['rights_confirmed' => '2025-03-01 Notenwart', 'portal_since' => '2025-04-01']);
		$report = $this->service->import(self::csv(['Signatur', 'Rechte bestätigt', 'Auf Singste seit'], ['5-1', 'other confirmation', '2026-01-01']),
			'catalog.csv', self::UID, false);

		self::assertSame('2025-03-01 Notenwart', $version->getValue('rights_confirmed'));
		self::assertSame('2025-04-01', $version->getPortalSince());
		self::assertSame([], $this->updated);
		self::assertSame([], array_filter($this->audit, fn (array $a): bool => $a['objectType'] === 'version'));
		$warnings = self::warnings($report);
		self::assertContains('Shelf mark 5-1: "Rights confirmed" is already set and is not overwritten by the import.', $warnings);
		self::assertContains('Shelf mark 5-1: "In singers\' portal since" is already set and is not overwritten by the import.', $warnings);
	}

	/**
	 * The column of the singers' portal is "Im Chorportal seit" in every
	 * export; files with the former name "Auf Singste seit" are still read.
	 */
	public function testTheFormerNameOfThePortalColumnIsStillRead(): void {
		self::assertArrayHasKey('Im Chorportal seit', CatalogCsv::LEGACY_COLUMNS);
		self::assertArrayNotHasKey('Auf Singste seit', CatalogCsv::columns(true));
		foreach (['Auf Singste seit', 'Im Chorportal seit'] as $header) {
			[, $version] = $this->givenEntry('5-1', [], ['portal_since' => null]);
			$report = $this->service->import(self::csv(['Signatur', $header], ['5-1', '2026-01-01']), 'catalog.csv', self::UID, false);
			self::assertSame('2026-01-01', $version->getPortalSince(), $header);
			self::assertNotContains('Unknown columns are ignored: ' . $header, self::warnings($report), $header);
		}
	}

	public function testEmptyDecisionFieldsAreFilled(): void {
		[, $version] = $this->givenEntry('5-1', [], ['rights_confirmed' => null]);
		$report = $this->service->import(self::csv(['Signatur', 'Rechte bestätigt'], ['5-1', '2025-03-01 Notenwart']), 'catalog.csv', self::UID, false);

		self::assertSame('2025-03-01 Notenwart', $version->getValue('rights_confirmed'));
		self::assertSame([$version], $this->updated);
		self::assertSame(self::NOW, $version->getModified());
		self::assertSame([1, 1], [$report['changed'], $report['fieldsChanged']]);
		self::assertSame(['objectType' => 'version', 'objectId' => $version->getId(), 'action' => 'import', 'field' => 'rights_confirmed',
			'old' => null, 'new' => '2025-03-01 Notenwart', 'reason' => null], $this->audit[0]);
	}

	public function testChangedValuesAreTakenOverAndRecorded(): void {
		[$work] = $this->givenEntry('5-1', ['title' => 'Ave verum']);
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['5-1', 'Ave verum corpus']), 'catalog.csv', self::UID, false);

		self::assertSame('Ave verum corpus', $work->getTitle());
		self::assertSame([$work], $this->updated);
		self::assertSame(['title', 'Ave verum', 'Ave verum corpus'], [$this->audit[0]['field'], $this->audit[0]['old'], $this->audit[0]['new']]);
		self::assertSame(1, $report['changed']);
	}

	public function testReadOnlyFieldsOutsideTheLegacyColumnsAreOnlyRestoredIntoAnEmptyCatalog(): void {
		[, $version] = $this->givenEntry('5-1');
		$this->service->import(self::csv(['Signatur', 'version.confirmed_by'], ['5-1', 'mallory']), 'catalog.csv', self::UID, false);
		self::assertNull($version->getValue('confirmed_by'), 'not taken over into an existing catalog');

		$this->copies = $this->versions = $this->works = [];
		$this->service->import(self::csv(['Signatur', 'version.confirmed_by'], ['6-1', 'anna']), 'backup.csv', self::UID, false);
		self::assertSame('anna', $this->insertedOf(WorkVersion::class)[0]->getValue('confirmed_by'), 'restored from a backup');
	}

	// ---- shelf marks ----

	public function testShelfMarksAreNormalized(): void {
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['05-01', 'A'], [' 6-008 ', 'B']), 'catalog.csv', self::UID, false);

		$copies = $this->insertedOf(Copy::class);
		self::assertSame(['5-1', '6-8'], array_map(fn (Copy $c): string => $c->getShelfmark(), $copies));
		self::assertSame([[5, 1], [6, 8]], array_map(fn (Copy $c): array => [$c->getBinder(), $c->getNumber()], $copies));
		$warnings = self::warnings($report);
		self::assertContains('Row 1: shelf mark "05-01" was normalized to 5-1.', $warnings);
		self::assertContains('Row 2: shelf mark "6-008" was normalized to 6-8.', $warnings);
	}

	public function testInvalidAndDuplicateShelfMarksAreSkipped(): void {
		$report = $this->service->import(self::csv(['Signatur', 'Titel'], ['X-1', 'A'], ['7-1', 'B'], ['07-01', 'C'], ['8', 'D'], ['', 'E']),
			'catalog.csv', self::UID, false);

		self::assertSame(['7-1'], array_map(fn (Copy $c): string => $c->getShelfmark(), $this->insertedOf(Copy::class)));
		self::assertSame(5, $report['rows']);
		self::assertSame(1, $report['created']);
		$warnings = self::warnings($report);
		self::assertContains('Row 1: shelf mark "X-1" is invalid and was skipped. A shelf mark is binder and number, for example 12-3.', $warnings);
		self::assertContains('Row 3: shelf mark 7-1 occurs twice and was skipped.', $warnings);
		self::assertContains('Row 4: shelf mark "8" is invalid and was skipped. A shelf mark is binder and number, for example 12-3.', $warnings);
		self::assertContains('Row 5: shelf mark "" is invalid and was skipped. A shelf mark is binder and number, for example 12-3.', $warnings);
	}

	/**
	 * W B25: an empty "Signatur" is formed from "Mappe" and "Nr"; a shelf
	 * mark that a spreadsheet program turned into a date says so.
	 */
	public function testAnEmptyShelfMarkIsFormedFromBinderAndNumber(): void {
		$report = $this->service->import(self::csv(['Mappe', 'Nr', 'Signatur', 'Titel'], ['12', '3', '', 'A'], ['', '', '2026-01-03', 'B']),
			'catalog.csv', self::UID, false);
		self::assertSame(['12-3'], array_map(fn (Copy $c): string => $c->getShelfmark(), $this->insertedOf(Copy::class)));
		self::assertContains('Row 2: shelf mark "2026-01-03" is a date, not binder and number, and was skipped. Please format the column "Signatur" as text and type the shelf mark again.',
			self::warnings($report));
	}

	/**
	 * W B30: a CSV file separated by commas or tabs is read, and the report
	 * says so.
	 */
	public function testACsvFileWithCommasOrTabsIsRead(): void {
		foreach ([',' => 'commas', "\t" => 'tabs'] as $separator => $name) {
			$this->inserted = [];
			$report = $this->service->import("Signatur{$separator}Titel{$separator}Komponist\r\n5-1{$separator}\"Ave; verum\"{$separator}Mozart\r\n",
				'catalog.csv', self::UID, true);
			self::assertSame(['Ave; verum'], array_map(fn (Work $work): ?string => $work->getTitle(), $this->insertedOf(Work::class)), $name);
			self::assertContains('The CSV file is separated by ' . $name . '; it was read that way.', self::warnings($report));
		}
	}

	public function testAnExistingCopyIsFoundByItsNormalizedShelfMark(): void {
		[$work] = $this->givenEntry('5-1', ['title' => 'Old']);
		$this->service->import(self::csv(['Signatur', 'Titel'], ['005-1', 'New']), 'catalog.csv', self::UID, false);

		self::assertSame([], $this->inserted);
		self::assertSame('New', $work->getTitle());
	}

	public function testShelfMarksMissingFromTheCsvAreOnlyReported(): void {
		$this->givenEntry('5-1');
		$this->givenEntry('5-2');
		$report = $this->service->import(self::csv(['Signatur'], ['5-1']), 'catalog.csv', self::UID, false);

		self::assertSame(['5-2'], $report['notInCsv']);
		self::assertSame([], $this->updated, 'nothing is deleted or changed');
	}

	// ---- legacy rules and report ----

	public function testLegacyPublicationNoteDerivesPreservationCopyAndStatusOnCreation(): void {
		$report = $this->service->import(self::csv(['Signatur', 'Rechtestatus', 'Veröffentlichung'],
			['5-1', 'geschützt', 'nein – interne Erhaltungskopie'],
			['5-2', 'gemeinfrei', 'frei'],
		), 'catalog.csv', self::UID, false);

		[$internal, $free] = $this->insertedOf(WorkVersion::class);
		self::assertTrue($internal->getPreservationCopy());
		self::assertSame('internal', $internal->getValue('publication_status'));
		self::assertSame('protected', $internal->getValue('rights_status_import'));
		self::assertFalse($free->getPreservationCopy());
		self::assertSame('free', $free->getValue('publication_status'));
		self::assertSame(1, $report['preservationCopies']);
		self::assertSame(['protected' => 1, 'public_domain' => 1], $report['distribution']['rightsStatus']);
	}

	public function testThePreservationFlagOfAnExistingVersionIsNotDerivedAgain(): void {
		[, $version] = $this->givenEntry('5-1', [], ['publication' => 'frei', 'preservation_copy' => true]);
		$this->service->import(self::csv(['Signatur', 'Veröffentlichung'], ['5-1', 'frei']), 'catalog.csv', self::UID, false);
		self::assertTrue($version->getPreservationCopy(), 'a manual correction survives the import');
	}

	public function testEmptyRowsUnknownAndMissingColumnsAreHandled(): void {
		$report = $this->service->import(self::csv(['Signatur', 'Titel', 'Lieblingsfarbe'], ['', '', ''], ['5-1', 'A', 'blau']), 'catalog.csv', self::UID, true);

		self::assertSame(1, $report['rows'], 'rows without content are not counted');
		$warnings = self::warnings($report);
		self::assertContains('Unknown columns are ignored: Lieblingsfarbe', $warnings);
		self::assertCount(1, array_filter($warnings, fn (string $w): bool => str_starts_with($w, 'Catalog columns are missing: Mappe, Nr, Komponist')));
	}

	public function testTheLegacyCatalogFormatImportsWithoutWarnings(): void {
		$header = array_keys(CatalogCsv::LEGACY_COLUMNS);
		$values = ['Mappe' => '5', 'Nr' => '1', 'Signatur' => '5-1', 'Titel' => 'Ave verum corpus', 'Rechtestatus' => 'gemeinfrei'];
		$row = array_map(fn (string $column): string => $values[$column] ?? '', $header);

		$report = $this->service->import(CatalogCsv::write($header, [$row]), 'Werkverzeichnis.csv', self::UID, true);

		self::assertSame([], $report['warnings']);
		self::assertSame(1, $report['created']);
	}

	// ---- Excel workbooks and OpenDocument spreadsheets (U32) ----

	/**
	 * An .xlsx workbook with inline strings, a sheet per entry of `$sheets`.
	 *
	 * @param array<string, list<list<string>>> $sheets name => rows
	 */
	private function xlsx(array $sheets): string {
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$entries = [];
		$workbook = '';
		$rels = '';
		$n = 0;
		foreach ($sheets as $name => $rows) {
			$n++;
			$workbook .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels .= '<Relationship Id="rId' . $n . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$xml = '';
			foreach ($rows as $row) {
				$xml .= '<row>' . implode('', array_map(fn (string $cell): string => $cell === '' ? '<c/>'
					: (is_numeric($cell) ? '<c><v>' . $cell . '</v></c>' : '<c t="inlineStr"><is><t>' . htmlspecialchars($cell) . '</t></is></c>'), $row)) . '</row>';
			}
			$entries['xl/worksheets/sheet' . $n . '.xml'] = '<worksheet xmlns="' . $main . '"><sheetData>' . $xml . '</sheetData></worksheet>';
		}
		$entries['xl/workbook.xml'] = '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets>' . $workbook . '</sheets></workbook>';
		$entries['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
		return $this->zip($entries);
	}

	public function testAWorkbookIsImportedWithTheSameColumns(): void {
		$workbook = $this->xlsx([
			'Notizen' => [['Bestand am 1. November']],
			'Werkverzeichnis' => [['Notenarchiv des Chors'], [], ['Mappe', 'Nr', 'Signatur', 'Titel', 'Rechtestatus'],
				['5', '1', '5-1', 'Ave verum corpus', 'gemeinfrei'], [], ['5', '2', '5-2', 'Locus iste']],
		]);
		$report = $this->service->import($workbook, 'Werkverzeichnis.xlsx', self::UID, false);

		self::assertSame([2, 2], [$report['rows'], $report['created']], 'the rows below the header; the title rows above it are left out');
		self::assertSame(['Ave verum corpus', 'Locus iste'], array_map(fn (Work $work): ?string => $work->getTitle(), $this->insertedOf(Work::class)));
		self::assertSame([5, 1], [$this->insertedOf(Copy::class)[0]->getBinder(), $this->insertedOf(Copy::class)[0]->getNumber()], 'numbers as numbers');
		self::assertSame('public_domain', $this->insertedOf(WorkVersion::class)[0]->getRightsStatus());
		self::assertContains('Only the sheet "Werkverzeichnis" was read; the other sheets of the file are left out.', self::warnings($report));
		self::assertSame('Werkverzeichnis.xlsx', $report['source']);
		self::assertSame(hash('sha256', $workbook), $report['sha256'], 'the checksum of the file as it was chosen');
	}

	public function testTheOdsTemplateImportsAsItIs(): void {
		$template = CatalogCsv::template();
		$real = $template['rows'][0];
		$real[2] = '2-1';
		$real[(int)array_search('Anmerkung', $template['header'], true)] = '';
		$ods = (new OdsWriter($this->tempManager()))->write([['name' => 'Werkverzeichnis', 'rows' => [$template['header'], ...$template['rows'], $real], 'text' => [2]]]);
		$report = $this->service->import($ods, 'cantoria_import_template.ods', self::UID, true);

		self::assertSame(1, $report['created']);
		self::assertSame(['Row 1 is the example row of the template and was not imported.'], self::warnings($report), 'the same columns as the CSV template, one sheet');
		self::assertSame('Abendlied', $this->insertedOf(Work::class)[0]->getTitle());
		self::assertSame('2-1', $this->insertedOf(Copy::class)[0]->getShelfmark());
	}

	/**
	 * W B24: a date in a workbook is a serial number with a date format;
	 * it comes back as ISO date, as from an OpenDocument file. A number
	 * without a date format stays a number.
	 */
	public function testDatesOfAWorkbookComeAsIsoDates(): void {
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$cell = fn (string $ref, string $value, ?int $style = null): string => '<c r="' . $ref . '"' . ($style === null ? '' : ' s="' . $style . '"') . '><v>' . $value . '</v></c>';
		$text = fn (string $ref, string $value): string => '<c r="' . $ref . '" t="inlineStr"><is><t>' . $value . '</t></is></c>';
		$sheet = '<worksheet xmlns="' . $main . '"><sheetData>'
			. '<row r="1">' . $text('A1', 'Signatur') . $text('B1', 'Im Chorportal seit') . $text('C1', 'Zuletzt aufgeführt') . $text('D1', 'Seiten') . '</row>'
			. '<row r="2">' . $text('A2', '5-1') . $cell('B2', '46329', 1) . $cell('C2', '46329.5', 2) . $cell('D2', '12', 0) . '</row>'
			. '</sheetData></worksheet>';
		$styles = '<styleSheet xmlns="' . $main . '"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy\\ hh:mm"/></numFmts>'
			. '<cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/></cellXfs></styleSheet>';
		$workbook = $this->zip([
			'xl/workbook.xml' => '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets><sheet name="Tabelle1" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml' => $sheet,
			'xl/styles.xml' => $styles,
		]);
		$this->service->import($workbook, 'catalog.xlsx', self::UID, true);

		self::assertSame('2026-11-03', $this->insertedOf(WorkVersion::class)[0]->getPortalSince());
		self::assertSame('2026-11-03 12:00', $this->insertedOf(Work::class)[0]->getValue('last_performed_import'));
		self::assertSame('12', (string)$this->insertedOf(Copy::class)[0]->getValue('pages'));
	}

	public function testASpreadsheetWithoutShelfMarkColumnIsRejected(): void {
		foreach (['list.xlsx' => $this->xlsx(['Tabelle1' => [['Titel'], ['Ave']]]), 'list.ods' => (new OdsWriter($this->tempManager()))
			->write([['name' => 'Tabelle1', 'rows' => [['Titel'], ['Ave']]]]), 'empty.xlsx' => $this->xlsx([])] as $name => $content) {
			try {
				$this->service->import($content, $name, self::UID, false);
				self::fail($name);
			} catch (InvalidInputException $e) {
				self::assertSame('The file has no column "Signatur". Is it the catalog?', $e->getMessage(), $name);
			}
		}
		self::assertSame([], $this->transaction);
	}

	public function testTheExtensionSaysTheFormat(): void {
		$cases = [
			'a workbook under another name' => [$this->xlsx(['Tabelle1' => [['Signatur'], ['5-1']]]), 'catalog.csv',
				'The file is neither CSV nor .xlsx or .ods. Please save it in one of these formats, for example in Excel with "Save as" → "Excel workbook (.xlsx)".'],
			'an Excel 97–2003 file' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 100), 'catalog.xls',
				'The file is neither CSV nor .xlsx or .ods. Please save it in one of these formats, for example in Excel with "Save as" → "Excel workbook (.xlsx)".'],
			'CSV under the name of a workbook' => [self::csv(['Signatur'], ['5-1']), 'catalog.xlsx', 'The file is not an Excel workbook (.xlsx).'],
			'CSV under the name of a spreadsheet' => [self::csv(['Signatur'], ['5-1']), 'catalog.ODS', 'The file is not an OpenDocument spreadsheet (.ods).'],
			'CSV in another encoding' => ["Signatur;Titel\r\n5-1;\xC4pfel\r\n", 'catalog.csv',
				'The CSV file is not UTF-8 encoded. In Excel: "Save as" → "CSV UTF-8"; in LibreOffice: "Save as" → "Text CSV" with the character set UTF-8.'],
		];
		foreach ($cases as $case => [$content, $name, $message]) {
			try {
				$this->service->import($content, $name, self::UID, false);
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
		self::assertSame([], $this->transaction, 'nothing is changed');
		self::assertSame(1, $this->service->import(self::csv(['Signatur'], ['5-1']), 'catalog.txt', self::UID, true)['created'], 'any other name is CSV');
	}
}
