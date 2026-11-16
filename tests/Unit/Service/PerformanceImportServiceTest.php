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
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\XlsxReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counting rule for documented performances, the detection of the own
 * choir, the dates, and a dry run of programme lines against stubbed mappers
 * (deduplication of annual lists, composer rule). The import against a
 * database is covered by tests/Integration/events_test.php.
 */
#[CoversClass(PerformanceImportService::class)]
final class PerformanceImportServiceTest extends TestCase {
	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: list<string>, 3: ?list<string>, 4: bool}>
	 */
	public static function lines(): iterable {
		// participation, state, counting participations, excluded states (null: default), counts
		yield 'nothing recorded' => [null, null, [], null, true];
		yield 'sung by the own choir' => ['MGV Liederkranz', 'Programm', ['MGV Liederkranz'], null, true];
		yield 'own choir, other case and blanks' => ['  mgv liederkranz ', null, ['MGV Liederkranz'], null, true];
		yield 'counting value with blanks and other case' => ['MGV Liederkranz', null, [' mgv LIEDERKRANZ '], null, true];
		yield 'empty participation counts as own choir' => ['', null, ['MGV Liederkranz'], null, true];
		yield 'sung by a guest choir' => ['Frauenchor Eintracht', null, ['MGV Liederkranz'], null, false];
		yield 'joint concert counts when listed' => ['gemeinsam', 'Programm', ['MGV Liederkranz', 'gemeinsam'], null, true];
		yield 'joint concert, other case' => ['Gemeinsam', null, ['MGV Liederkranz', 'gemeinsam'], null, true];
		yield 'guest choir with two counting values' => ['Frauenchor Eintracht', null, ['MGV Liederkranz', 'gemeinsam'], null, false];
		yield 'no counting participation given: everything counts' => ['Frauenchor Eintracht', null, [], null, true];
		yield 'only empty counting values: everything counts' => ['Frauenchor Eintracht', null, ['', '  '], null, true];
		yield 'draft (German)' => [null, 'Entwurf vom 3.5.', [], null, false];
		yield 'draft (English)' => [null, 'Draft 2', [], null, false];
		yield 'reserve piece' => [null, 'Reserve', [], null, false];
		yield 'encore if needed (German)' => [null, 'Zugabe nach Bedarf', [], null, false];
		yield 'encore if needed (English)' => [null, 'Encore if needed', [], null, false];
		yield 'uncertain line (German)' => [null, 'fraglich, kein Programm', [], null, false];
		yield 'uncertain line, upper case' => [null, 'Fraglich', [], null, false];
		yield 'work list' => [null, 'Werkliste 2019', [], null, false];
		yield 'song list' => [null, 'Notenliste', [], null, false];
		yield 'unsure (German)' => [null, 'unsicher', [], null, false];
		yield 'uncertain (English)' => [null, 'Uncertain', [], null, false];
		yield 'planned encore counts' => [null, 'Zugabe', [], null, true];
		yield 'final programme' => [null, 'endgültig', [], null, true];
		yield 'press report counts' => [null, 'Pressebericht gesungen', [], null, true];
		yield 'own excluded words replace the default' => [null, 'Reserve', [], ['entwurf'], true];
		yield 'own excluded words apply' => [null, 'Zugabe wenn noch Zeit', [], ['entwurf', 'wenn noch zeit'], false];
		yield 'own excluded words, other case and blanks' => [null, 'Eventuell', [], [' EVENTUELL '], false];
		yield 'no excluded words: every state counts' => [null, 'Entwurf', [], [], true];
		yield 'guest choir is excluded before the state' => ['Gastchor', 'Programm', ['MGV Liederkranz'], [], false];
	}

	/**
	 * @param list<string> $counting
	 * @param ?list<string> $excluded
	 */
	#[DataProvider('lines')]
	public function testCountsByRule(?string $participation, ?string $state, array $counting, ?array $excluded, bool $counts): void {
		self::assertSame($counts, PerformanceImportService::countsByRule($participation, $state, $counting, $excluded));
	}

	public function testTheDefaultExcludedStatesAreTheChoirsWords(): void {
		foreach (['entwurf', 'draft', 'reserve', 'nach bedarf', 'if needed', 'fraglich', 'werkliste', 'notenliste', 'unsicher', 'uncertain'] as $word) {
			self::assertContains($word, PerformanceImportService::DEFAULT_EXCLUDED_STATES);
		}
		// The default is used when nothing is given, and it is lower case (compared as substrings of the lower-cased state)
		self::assertSame(PerformanceImportService::DEFAULT_EXCLUDED_STATES, array_map('mb_strtolower', PerformanceImportService::DEFAULT_EXCLUDED_STATES));
		self::assertFalse(PerformanceImportService::countsByRule(null, 'Werkliste'));
		self::assertTrue(PerformanceImportService::countsByRule(null, 'Werkliste', [], ['entwurf']));
	}

	public function testListParametersAreSplitAtBarsAndCommas(): void {
		self::assertSame(['Chor A', 'gemeinsam', 'Chor B'], PerformanceImportService::splitList(' Chor A | gemeinsam, Chor B '));
		self::assertSame([], PerformanceImportService::splitList(null));
		self::assertSame([], PerformanceImportService::splitList(' | , '));
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: ?string}>
	 */
	public static function dates(): iterable {
		yield 'day' => ['2019-06-15', '2019-06-15'];
		yield 'day with time' => ['2019-06-15 19:30', '2019-06-15'];
		yield 'impossible day' => ['2019-02-30', null];
		yield 'month is kept' => ['2019-06', '2019-06'];
		yield 'month 12' => ['2019-12', '2019-12'];
		yield 'impossible month' => ['2019-13', null];
		yield 'month 00' => ['2019-00', null];
		yield 'year' => ['2019', '2019'];
		yield 'year with blanks' => [' 2019 ', '2019'];
		yield 'Excel serial date' => ['45000', '2023-03-15'];
		yield 'words' => ['Juni 2019', null];
		yield 'empty' => ['', null];
		yield 'null' => [null, null];
	}

	#[DataProvider('dates')]
	public function testDates(?string $value, ?string $expected): void {
		self::assertSame($expected, PerformanceImportService::date($value));
	}

	public function testMostFrequentValueOfAColumn(): void {
		$sheet = ['sheet' => 'Programme', 'header' => ['Datum', 'Mitwirkung'], 'rows' => [
			['2024-05-01', 'MGV Liederkranz'],
			['2024-05-01', ' MGV Liederkranz '],
			['2024-05-01', 'Gastchor'],
			['2024-06-01', ''],
			['2024-06-01'],
		]];
		self::assertSame('MGV Liederkranz', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testOnATieTheFirstValueWins(): void {
		$sheet = ['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [['B'], ['A'], ['A'], ['B']]];
		self::assertSame('B', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testNumericValuesStayText(): void {
		$sheet = ['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [['1990'], ['1990']]];
		self::assertSame('1990', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testNoValue(): void {
		self::assertNull(PerformanceImportService::mostFrequent(['sheet' => 'S', 'header' => ['Datum'], 'rows' => [['x']]], 'Mitwirkung'));
		self::assertNull(PerformanceImportService::mostFrequent(['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [[''], [' ']]], 'Mitwirkung'));
	}

	// ---- a dry run of programme lines against stubbed mappers --------------------

	/**
	 * Programme lines of an invented choir "Chor A": a summer concert with a
	 * day date, the same works on an annual list (year only) and a monthly
	 * list, a monthly list and an annual list without a concert with a day
	 * date, a joint concert, an uncertain line, an ambiguous line that names
	 * a composer, and a copy whose version is unknown (deduplicated by shelf
	 * mark).
	 */
	private const PROGRAMME = <<<CSV
		Datum;Anlass;Ort;Titel laut Programm;Komponist/Satz laut Programm;Mitwirkung;Signatur;Zuordnung;Stand;Quelle
		2019-06-15;Sommerkonzert;Musterstadt;Abendlied;;Chor A;1-5;sicher;Programm;Programmheft
		2019;Jahresliste;;Abendlied;;Chor A;1-5;sicher;;Jahresliste
		2019-06;Monatsliste;;Abendlied;;Chor A;1-5;sicher;;Chronik
		2019-07;Monatsliste;;Abendlied;;Chor A;1-5;sicher;;Chronik
		2020;Jahresliste;;Abendlied;;Chor A;1-5;sicher;;Jahresliste
		2019;Jahresliste;;Morgenlied;;Chor A;1-6;sicher;;Jahresliste
		2019-06-15;Sommerkonzert;Musterstadt;Morgenlied;;gemeinsam;1-6;sicher;Programm;Programmheft
		2019-06-15;Sommerkonzert;Musterstadt;Nachtlied;;Chor A;1-7;sicher;fraglich;Programmheft
		2019-06-15;Sommerkonzert;Musterstadt;Wanderlied;Franz Beispiel, Satz: Karl Muster;Chor A;1-8 | 1-9;mehrdeutig;Programm;Programmheft
		2018-03-03;Frühjahrskonzert;Musterstadt;Heimatlied;;Chor A;2-1;sicher;Programm;Programmheft
		2018;Jahresliste;;Heimatlied;;Chor A;2-1;sicher;;Jahresliste
		CSV;

	/** @var list<Event> */
	private array $events = [];
	/** @var list<EventItem> */
	private array $items = [];

	private function service(): PerformanceImportService {
		$this->events = [];
		$this->items = [];
		$copies = [];
		foreach (['1-5' => [1, 10], '1-6' => [2, 11], '1-7' => [3, 12], '1-8' => [4, 13], '1-9' => [5, 14], '2-1' => [6, 99]] as $mark => [$id, $versionId]) {
			$copy = new Copy();
			$copy->setId($id);
			$copy->setShelfmark($mark);
			$copy->setVersionId($versionId);
			$copies[$mark] = $copy;
		}
		$versions = [];
		$works = [];
		foreach ([10 => [100, 'Anna Beispiel', null], 11 => [101, 'Anna Beispiel', null], 12 => [102, null, null],
			13 => [103, 'Otto Probe', 'Satz: Karl Muster'], 14 => [104, 'Franz Beispiel', null]] as $versionId => [$workId, $composer, $arranger]) {
			$version = new WorkVersion();
			$version->setId($versionId);
			$version->setWorkId($workId);
			$version->setArrangerText($arranger);
			$versions[$versionId] = $version;
			$work = new Work();
			$work->setId($workId);
			$work->setComposer($composer);
			$works[$workId] = $work;
		}
		// Version 99 of copy 2-1 is unknown: the work is unknown, the shelf mark identifies the line.

		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAllByShelfmark')->willReturn($copies);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$eventMapper = $this->createStub(EventMapper::class);
		$eventMapper->method('findImported')->willReturn([]);
		$eventMapper->method('insert')->willReturnCallback(function (Event $event): Event {
			$event->setId(count($this->events) + 1);
			$this->events[] = $event;
			return $event;
		});
		$itemMapper = $this->createStub(EventItemMapper::class);
		$itemMapper->method('insert')->willReturnCallback(function (EventItem $item): EventItem {
			$this->items[] = $item;
			return $item;
		});
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		return new PerformanceImportService($this->createStub(IDBConnection::class), $this->createStub(XlsxReader::class),
			$eventMapper, $itemMapper, $copyMapper, $versionMapper, $workMapper, $this->createStub(ExportService::class), $audit, $time);
	}

	/**
	 * @param list<string> $counting
	 * @param ?list<string> $excluded
	 * @return array<string, mixed>
	 */
	private function dryRun(array $counting = [], ?array $excluded = null): array {
		return $this->service()->import(self::PROGRAMME, 'auffuehrungen.csv', 'librarian', true, $counting, $excluded);
	}

	/** The titles of the lines that count, in file order */
	private function counted(): array {
		return array_values(array_map(fn (EventItem $item): string => (string)$item->getTitleText() . '@' . (string)$this->events[$item->getEventId() - 1]->getEventDate(),
			array_filter($this->items, fn (EventItem $item): bool => $item->getCounts() === true)));
	}

	public function testAnnualAndMonthlyListsDoNotCountTwice(): void {
		$report = $this->dryRun(['Chor A', 'gemeinsam']);
		self::assertSame(11, $report['items']);
		self::assertSame(['Chor A', 'gemeinsam'], $report['countingParticipations']);
		self::assertSame('Chor A', $report['ownChoir']);
		self::assertSame(PerformanceImportService::DEFAULT_EXCLUDED_STATES, $report['excludedStates']);
		// Not counted twice: "Abendlied" 2019 and 2019-06 (the concert on 2019-06-15 counts), "Morgenlied" 2019 (the joint
		// concert on 2019-06-15 counts), "Heimatlied" 2018 (the concert on 2018-03-03 counts; its work is unknown, so the
		// shelf mark identifies it). Counted: the month 2019-07 and the year 2020, which have no concert with a day date.
		self::assertSame(4, $report['deduplicated']);
		// In the order of the events (grouped by date, occasion and place as they first appear) and their lines
		self::assertSame(['Abendlied@2019-06-15', 'Morgenlied@2019-06-15', 'Abendlied@2019-07', 'Abendlied@2020', 'Heimatlied@2018-03-03'], $this->counted());
		self::assertSame(5, $report['counted']);
		self::assertTrue($report['dryRun']);
	}

	public function testMonthDatesAreKept(): void {
		$this->dryRun();
		$dates = array_map(fn (Event $event): ?string => $event->getEventDate(), $this->events);
		self::assertContains('2019-06', $dates);
		self::assertContains('2019-07', $dates);
		self::assertContains('2019', $dates);
		self::assertContains('2019-06-15', $dates);
		self::assertNotContains(null, $dates);
	}

	public function testWithoutJointConcertsOnlyTheOwnChoirCounts(): void {
		// The older parameter `ownChoir` becomes a list with one value
		$report = $this->dryRun(['Chor A']);
		self::assertSame(['Chor A'], $report['countingParticipations']);
		self::assertNotContains('Morgenlied@2019-06-15', $this->counted());
		// Without the joint concert the annual list of "Morgenlied" 2019 is the only evidence and counts
		self::assertContains('Morgenlied@2019', $this->counted());
		self::assertSame(5, $report['counted']);
		self::assertSame(3, $report['deduplicated']);
	}

	public function testTheMostFrequentParticipationCountsWhenNoneIsGiven(): void {
		$report = $this->dryRun();
		self::assertSame(['Chor A'], $report['countingParticipations']);
		self::assertSame('Chor A', $report['ownChoir']);
		// The spelling as typed is kept, duplicates are dropped case-insensitively
		$report = $this->dryRun([' chor a ', 'CHOR A', 'Gemeinsam']);
		self::assertSame(['chor a', 'Gemeinsam'], $report['countingParticipations']);
		self::assertSame(5, $report['counted']);
	}

	public function testUncertainLinesDoNotCountUnlessAllowed(): void {
		$this->dryRun(['Chor A', 'gemeinsam']);
		self::assertNotContains('Nachtlied@2019-06-15', $this->counted());
		$report = $this->dryRun(['Chor A', 'gemeinsam'], ['entwurf']);
		self::assertSame(['entwurf'], $report['excludedStates']);
		self::assertContains('Nachtlied@2019-06-15', $this->counted());
	}

	public function testComposerRuleSuggestsACandidateWithoutDeciding(): void {
		$report = $this->dryRun(['Chor A']);
		self::assertSame(1, $report['ambiguous']);
		$ambiguous = array_values(array_filter($this->items, fn (EventItem $item): bool => $item->getAssignment() === EventItem::AMBIGUOUS));
		self::assertCount(1, $ambiguous);
		$item = $ambiguous[0];
		// "Franz Beispiel" names the composer of 1-9 only; "Satz: Karl Muster" names the arranger of 1-8 only: two matches, no hint
		self::assertSame('1-8 | 1-9', $item->getCandidates());
		self::assertNull($item->getNote());
		self::assertNotTrue($item->getCounts());

		$content = str_replace('Franz Beispiel, Satz: Karl Muster', 'F. Beispiel', self::PROGRAMME);
		$this->service()->import($content, 'auffuehrungen.csv', 'librarian', true, ['Chor A']);
		$item = array_values(array_filter($this->items, fn (EventItem $item): bool => $item->getAssignment() === EventItem::AMBIGUOUS))[0];
		self::assertSame('1-9 | 1-8', $item->getCandidates(), 'the matching candidate comes first');
		self::assertSame('Composer rule: 1-9 matches "F. Beispiel"', $item->getNote());
		self::assertSame(EventItem::AMBIGUOUS, $item->getAssignment(), 'the assignment is not decided');
		self::assertNotTrue($item->getCounts());

		$content = str_replace('Franz Beispiel, Satz: Karl Muster', 'Satz: Muster', self::PROGRAMME);
		$this->service()->import($content, 'auffuehrungen.csv', 'librarian', true, ['Chor A']);
		$item = array_values(array_filter($this->items, fn (EventItem $item): bool => $item->getAssignment() === EventItem::AMBIGUOUS))[0];
		self::assertSame('1-8 | 1-9', $item->getCandidates(), 'the arranger counts as well');
		self::assertStringStartsWith('Composer rule: 1-8', (string)$item->getNote());
	}

	public function testCountsOfCertainLinesOnlyAndNotInCatalog(): void {
		$report = $this->dryRun(['Chor A', 'gemeinsam']);
		self::assertSame(10, $report['certain']);
		self::assertSame(0, $report['notInCatalog']);
		self::assertSame([], $report['unknownShelfmarks']);
		$content = str_replace(';2-1;sicher;Programm;', ';3-1;sicher;Programm;', self::PROGRAMME);
		$report = $this->service()->import($content, 'auffuehrungen.csv', 'librarian', true, ['Chor A', 'gemeinsam']);
		self::assertSame(1, $report['notInCatalog']);
		self::assertSame(['3-1'], $report['unknownShelfmarks']);
		// Without a concert with a day date the annual list of "Heimatlied" counts again
		self::assertContains('Heimatlied@2018', $this->counted());
	}
}
