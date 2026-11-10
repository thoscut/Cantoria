<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\XlsxReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Importing the same performances again keeps the lines someone assigned
 * to a copy by hand after the first import.
 */
#[CoversClass(PerformanceImportService::class)]
#[UsesClass(CatalogCsv::class)]
final class PerformanceReimportTest extends TestCase {
	/** @var list<EventItem> */
	private array $inserted = [];

	/**
	 * @param list<EventItem> $before lines of the earlier import
	 */
	private function import(array $before, bool $copyExists = true): array {
		$old = new Event();
		$old->setId(1);
		$old->setKind(Event::CONCERT);
		$old->setEventDate('2026-05-01');
		$old->setTitle('Frühjahrskonzert');
		$old->setImported(true);
		$events = $this->createStub(EventMapper::class);
		$events->method('findImported')->willReturn([$old]);
		$events->method('insert')->willReturnCallback(function (Event $event): Event {
			$event->setId(2);
			return $event;
		});
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturn($before);
		$items->method('insert')->willReturnCallback(function (EventItem $item): EventItem {
			$this->inserted[] = $item;
			return $item;
		});
		$copy = new Copy();
		$copy->setId($copyExists ? 9 : 10);
		$copy->setVersionId(5);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAllByShelfmark')->willReturn(['3-4' => $copy]);
		$version = new WorkVersion();
		$version->setId(5);
		$version->setWorkId(6);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturn([5 => $version]);
		$service = new PerformanceImportService($this->createStub(IDBConnection::class), $this->createStub(XlsxReader::class), $events, $items,
			$copies, $versions, $this->createStub(ExportService::class), $this->createStub(AuditLog::class), $this->createStub(ITimeFactory::class));
		$csv = "Datum;Anlass;Titel laut Programm;Signatur\r\n2026-05-01;Frühjahrskonzert;Abendlied;\r\n2026-05-01;Frühjahrskonzert;Ave verum;3-4|3-5\r\n";
		return $service->import($csv, 'auffuehrungen.csv', 'lena', true, null);
	}

	private static function line(int $position, string $title, string $assignment, ?int $copyId): EventItem {
		$item = new EventItem();
		$item->setEventId(1);
		$item->setPosition($position);
		$item->setTitleText($title);
		$item->setAssignment($assignment);
		$item->setCopyId($copyId);
		$item->setVersionId($copyId === null ? null : 5);
		$item->setWorkId($copyId === null ? null : 6);
		return $item;
	}

	public function testAnAssignmentMadeByHandSurvivesANewImport(): void {
		$report = $this->import([self::line(1, 'Abendlied', EventItem::CERTAIN, 9), self::line(2, 'Ave verum', EventItem::NOT_IN_CATALOG, null)]);
		self::assertSame([EventItem::CERTAIN, 9, 6], [$this->inserted[0]->getAssignment(), $this->inserted[0]->getCopyId(), $this->inserted[0]->getWorkId()]);
		self::assertSame(EventItem::NOT_IN_CATALOG, $this->inserted[1]->getAssignment(), 'also "not in the archive" chosen by hand');
		self::assertSame([2, 1, 0, 1], [$report['keptAssignments'], $report['certain'], $report['ambiguous'], $report['notInCatalog']]);
	}

	public function testALineThatChangedOrACopyThatIsGoneIsImportedAnew(): void {
		$report = $this->import([self::line(1, 'Abendlied (Rheinberger)', EventItem::CERTAIN, 9)]);
		self::assertSame([0, EventItem::NOT_IN_CATALOG], [$report['keptAssignments'], $this->inserted[0]->getAssignment()]);
		$this->inserted = [];
		$report = $this->import([self::line(1, 'Abendlied', EventItem::CERTAIN, 9)], copyExists: false);
		self::assertSame([0, null], [$report['keptAssignments'], $this->inserted[0]->getCopyId()]);
	}
}
