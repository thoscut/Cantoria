<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\StatisticsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The aggregation of the statistics without a database: the static
 * functions get the rows the service reads with its few queries.
 *
 * @psalm-import-type CopyRow from StatisticsService
 */
#[CoversClass(StatisticsService::class)]
final class StatisticsServiceTest extends TestCase {
	/**
	 * @param array<string, mixed> $values
	 */
	private static function work(int $id, array $values = []): Work {
		$work = Work::fromParams($values);
		$work->setId($id);
		return $work;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function version(int $id, int $workId, array $values = []): WorkVersion {
		$version = WorkVersion::fromParams(['workId' => $workId] + $values);
		$version->setId($id);
		return $version;
	}

	private static function event(int $id, ?string $date, string $status = 'sung', string $kind = Event::CONCERT): Event {
		$event = Event::fromParams(['kind' => $kind, 'eventDate' => $date, 'status' => $status, 'title' => 'Concert ' . $id]);
		$event->setId($id);
		return $event;
	}

	private static function item(int $eventId, ?int $workId, bool $counts = true, string $assignment = EventItem::CERTAIN, bool $struck = false): EventItem {
		return EventItem::fromParams(['eventId' => $eventId, 'workId' => $workId, 'counts' => $counts, 'assignment' => $assignment,
			'struck' => $struck]);
	}

	/**
	 * @return CopyRow
	 */
	private static function copy(int $id, int $versionId, int $binder, string $status = 'active', int $created = 0, ?string $kind = null): array {
		return ['id' => $id, 'versionId' => $versionId, 'binder' => $binder, 'number' => $id, 'shelfmark' => $binder . '-' . $id,
			'status' => $status, 'created' => $created, 'kind' => $kind];
	}

	private static function time(string $date): int {
		return (new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
	}

	public function testPeriodDefaultsToTheLastTenYears(): void {
		self::assertSame([2017, 2026], StatisticsService::period(null, null, 2026));
		self::assertSame([2016, 2025], StatisticsService::period(null, 2025, 2026));
		self::assertSame([1990, 2026], StatisticsService::period(1990, null, 2026));
		self::assertSame([2030, 2040], StatisticsService::period(2030, 2040, 2026));
	}

	public function testInvalidPeriodsAreRefused(): void {
		foreach ([[2026, 2025], [1700, 1750], [2026, 2100], [1900, 2026]] as [$from, $to]) {
			try {
				StatisticsService::period($from, $to, 2026);
				self::fail("$from–$to was accepted");
			} catch (InvalidInputException $e) {
				self::assertStringContainsString('period', $e->getMessage());
			}
		}
	}

	public function testYearsAndDateKeys(): void {
		self::assertSame(2025, StatisticsService::yearOf('2025-12-06'));
		self::assertSame(2003, StatisticsService::yearOf('2003'));
		self::assertNull(StatisticsService::yearOf(null));
		self::assertNull(StatisticsService::yearOf('Advent'));
		self::assertSame(20251206, StatisticsService::dateKey('2025-12-06'));
		self::assertSame(20030000, StatisticsService::dateKey('2003'));
		self::assertSame(20050000, StatisticsService::dateKey('Advent 2005'));
		self::assertSame(0, StatisticsService::dateKey(null));
	}

	public function testStockCountsStatesPerBinderFilesAndPreservationCopies(): void {
		$versions = [
			1 => self::version(1, 1),
			2 => self::version(2, 2, ['musicxmlValid' => '2-1.mxl']),
			3 => self::version(3, 3, ['preservationCopy' => true]),
		];
		$copies = [self::copy(1, 1, 1), self::copy(2, 2, 1), self::copy(3, 3, 2), self::copy(4, 1, 2, 'retired'),
			self::copy(5, 2, 2, 'lent'), self::copy(6, 1, 3, 'missing'), self::copy(7, 1, 3, 'odd')];
		$files = [1 => ['pdf' => 2], 2 => ['pdf' => 1], 3 => ['mxl' => 1], 4 => ['musicxml' => 1]];
		$stock = StatisticsService::stock($copies, $versions, $files);

		self::assertSame(7, $stock['copies']);
		self::assertSame(['active' => 3, 'retired' => 1, 'missing' => 1, 'lent' => 1, 'other' => 1], $stock['status']);
		self::assertSame([
			['binder' => 1, 'active' => 2, 'retired' => 0, 'missing' => 0, 'lent' => 0, 'other' => 0, 'total' => 2],
			['binder' => 2, 'active' => 1, 'retired' => 1, 'missing' => 0, 'lent' => 1, 'other' => 0, 'total' => 3],
			['binder' => 3, 'active' => 0, 'retired' => 0, 'missing' => 1, 'lent' => 0, 'other' => 1, 'total' => 2],
		], $stock['binders']);
		self::assertSame(3, $stock['active']);
		self::assertSame(3, $stock['activeWithFile']);
		// copy 2: valid MusicXML noted in the catalog; copy 3: an .mxl file; copy 4 is not active
		self::assertSame(2, $stock['activeWithMusicxml']);
		self::assertSame(1, $stock['preservationVersions']);
		self::assertSame(1, $stock['preservationCopies']);
	}

	public function testAdditionsComeFromTheAuditLogOrTheCreationDate(): void {
		$zone = new \DateTimeZone('UTC');
		$copies = [self::copy(1, 1, 1, 'active', self::time('2026-01-05')), self::copy(2, 1, 1, 'active', self::time('2026-01-05')),
			self::copy(3, 1, 1, 'retired', self::time('2024-03-01')), self::copy(4, 1, 1, 'active', 0)];
		$created = [
			['copyId' => 1, 'time' => self::time('2025-02-01'), 'reason' => 'import'],
			['copyId' => 2, 'time' => self::time('2025-06-01'), 'reason' => 'accession'],
			// a later record of the same copy does not count again
			['copyId' => 2, 'time' => self::time('2026-06-01'), 'reason' => 'import'],
			// a copy that does not exist (any more) is not counted
			['copyId' => 99, 'time' => self::time('2025-06-01'), 'reason' => 'accession'],
		];
		self::assertSame([
			['year' => 2024, 'accession' => 0, 'import' => 0, 'other' => 1, 'total' => 1],
			['year' => 2025, 'accession' => 1, 'import' => 1, 'other' => 0, 'total' => 2],
			['year' => 2026, 'accession' => 0, 'import' => 0, 'other' => 0, 'total' => 0],
		], StatisticsService::additions($copies, $created, 2024, 2026, $zone));
	}

	public function testAdditionsFollowTheTimeZoneOfTheUser(): void {
		$copies = [self::copy(1, 1, 1)];
		$created = [['copyId' => 1, 'time' => (new \DateTimeImmutable('2025-12-31 23:30:00', new \DateTimeZone('UTC')))->getTimestamp(),
			'reason' => 'accession']];
		$berlin = StatisticsService::additions($copies, $created, 2025, 2026, new \DateTimeZone('Europe/Berlin'));
		self::assertSame([0, 1], array_column($berlin, 'accession'));
		$utc = StatisticsService::additions($copies, $created, 2025, 2026, new \DateTimeZone('UTC'));
		self::assertSame([1, 0], array_column($utc, 'accession'));
	}

	public function testAccessionsOfAYearWithoutPreservationCopies(): void {
		$works = [1 => self::work(1, ['title' => 'Abendlied', 'composer' => 'Rheinberger']), 2 => self::work(2, ['title' => 'Secret'])];
		$versions = [1 => self::version(1, 1), 2 => self::version(2, 2, ['preservationCopy' => true])];
		$copies = [self::copy(1, 1, 10), self::copy(2, 2, 10), self::copy(3, 1, 10)];
		$created = [
			['copyId' => 3, 'time' => self::time('2025-09-01'), 'reason' => 'accession'],
			['copyId' => 1, 'time' => self::time('2025-03-01'), 'reason' => 'accession'],
			['copyId' => 2, 'time' => self::time('2025-04-01'), 'reason' => 'accession'],
		];
		$result = StatisticsService::accessions($copies, $created, $versions, $works, 2025, new \DateTimeZone('UTC'));
		self::assertSame(['10-1', '10-3'], array_column($result['rows'], 'shelfmark'));
		self::assertSame('2025-03-01', $result['rows'][0]['date']);
		self::assertSame('Abendlied', $result['rows'][0]['title']);
		self::assertSame(1, $result['omitted']);
	}

	public function testRightsStatusSeparatesConfirmedVersions(): void {
		$versions = [
			1 => self::version(1, 1, ['rightsStatus' => 'public_domain', 'confirmedAt' => '2026-01-01']),
			2 => self::version(2, 1, ['rightsStatus' => 'public_domain']),
			3 => self::version(3, 2, ['rightsStatus' => 'protected', 'confirmedAt' => '2026-01-01']),
			4 => self::version(4, 2, ['rightsStatus' => 'open']),
			5 => self::version(5, 3),
			6 => self::version(6, 3, ['rightsStatus' => 'bogus']),
		];
		self::assertSame([
			['status' => 'public_domain', 'confirmed' => 1, 'unconfirmed' => 1, 'total' => 2],
			['status' => 'protected', 'confirmed' => 1, 'unconfirmed' => 0, 'total' => 1],
			['status' => 'open', 'confirmed' => 0, 'unconfirmed' => 1, 'total' => 1],
			['status' => 'none', 'confirmed' => 0, 'unconfirmed' => 2, 'total' => 2],
		], StatisticsService::rightsStatus($versions));
	}

	public function testValidVersionsPerWork(): void {
		$all = [1 => self::work(1), 2 => self::work(2), 3 => self::work(3), 4 => self::work(4, ['mergedInto' => 2])];
		$works = StatisticsService::listedWorks($all);
		self::assertSame([1, 2, 3], array_keys($works));
		$versions = [
			1 => self::version(1, 1, ['validSince' => '2026-01-01']),
			2 => self::version(2, 1),
			3 => self::version(3, 2),
			// a version still pointing to a merged work belongs to the work it was merged into
			4 => self::version(4, 4),
			5 => self::version(5, 3),
		];
		self::assertSame(['works' => 3, 'withValid' => 1, 'withoutValid' => 2, 'withoutValidSeveral' => 1, 'currentWithoutValid' => 1],
			StatisticsService::validVersions($works, $versions, $all, [1 => true, 3 => true]));
	}

	public function testLicenseGroupsDoNotOverlap(): void {
		$license = fn (?string $from, ?string $until): License => License::fromParams(['validFrom' => $from, 'validUntil' => $until, 'uses' => 'portal']);
		$licenses = [1 => [
			$license(null, null),
			$license('2020-01-01', '2027-12-31'),
			$license(null, '2026-11-10'),
			$license(null, '2026-10-22'),
			$license(null, '2026-10-21'),
			$license('2027-01-01', '2027-12-31'),
		]];
		self::assertSame(['total' => 6, 'active' => 2, 'ending' => 2, 'ended' => 1, 'future' => 1, 'endingDays' => 30],
			StatisticsService::licenseCounts($licenses, '2026-10-22'));
	}

	public function testPerformancesPerYearAndTopLists(): void {
		$works = [
			1 => self::work(1, ['title' => 'Abendlied', 'composer' => 'Josef Rheinberger']),
			2 => self::work(2, ['title' => 'Die Nacht', 'composer' => 'Franz  Schubert']),
			3 => self::work(3, ['title' => 'Ständchen', 'composer' => 'Franz Schubert']),
			4 => self::work(4, ['title' => 'Old copy of Die Nacht', 'mergedInto' => 2]),
		];
		$events = [
			1 => self::event(1, '2024-05-01'),
			2 => self::event(2, '2025-12-06'),
			3 => self::event(3, '2025'),
			4 => self::event(4, '2026-12-06', 'fixed'),
			5 => self::event(5, null),
			6 => self::event(6, '2025-03-01', 'fixed', Event::SONG_LIST),
			7 => self::event(7, '2010-01-01'),
		];
		$items = [
			self::item(1, 1), self::item(1, 2),
			self::item(2, 1), self::item(2, 4), self::item(2, 3), self::item(2, null, false, EventItem::NOT_IN_CATALOG),
			self::item(2, 3, false),
			self::item(3, 2), self::item(3, 3, true, EventItem::AMBIGUOUS),
			self::item(4, 1, false),
			self::item(5, 1),
			self::item(6, 1, false),
			self::item(7, 1),
		];
		$result = StatisticsService::performances($events, $items, $works, 2024, 2026);

		self::assertSame([
			['year' => 2024, 'concerts' => 1, 'planned' => 0, 'pieces' => 2, 'works' => 2],
			['year' => 2025, 'concerts' => 2, 'planned' => 0, 'pieces' => 4, 'works' => 3],
			['year' => 2026, 'concerts' => 0, 'planned' => 1, 'pieces' => 0, 'works' => 0],
		], $result['years']);
		self::assertSame(1, $result['undated']);
		// Die Nacht: 2024-05-01, 2025-12-06 (as merged work 4) and 2025; Abendlied: 2024 and 2025
		self::assertSame([[2, 3, '2025-12-06'], [1, 2, '2025-12-06'], [3, 1, '2025-12-06']],
			array_map(fn (array $w): array => [$w['workId'], $w['performances'], $w['last']], $result['topWorks']));
		self::assertSame([['Franz Schubert', 4, 2], ['Josef Rheinberger', 2, 1]],
			array_map(fn (array $c): array => [$c['composer'], $c['performances'], $c['works']], $result['topComposers']));
		self::assertCount(1, StatisticsService::performances($events, $items, $works, 2024, 2026, 1)['topWorks']);
	}

	public function testConcertsOfTheAnnualReport(): void {
		$events = [1 => self::event(1, '2025-12-06'), 2 => self::event(2, '2025-05-01', 'fixed'), 3 => self::event(3, '2024-12-06'),
			4 => self::event(4, '2025-01-01', 'fixed', Event::SONG_LIST)];
		$items = [self::item(1, 1), self::item(1, 2), self::item(1, 3, false), self::item(2, 1, false), self::item(2, 2, false, EventItem::CERTAIN, true),
			self::item(3, 1)];
		$concerts = StatisticsService::concerts($events, $items, 2025);
		self::assertSame([[2, '2025-05-01', 'fixed', 1], [1, '2025-12-06', 'sung', 2]],
			array_map(fn (array $c): array => [$c['id'], $c['date'], $c['status'], $c['pieces']], $concerts));
		$totals = StatisticsService::performances($events, $items, [], 2025, 2025, 0)['years'][0];
		self::assertSame(array_sum(array_column(array_filter($concerts, fn (array $c): bool => $c['status'] === 'sung'), 'pieces')), $totals['pieces']);
	}

	public function testRepertoireClassesAndLongNotSung(): void {
		$works = [
			1 => self::work(1, ['title' => 'A']),
			2 => self::work(2, ['title' => 'B', 'repertoireImport' => 'ruhend', 'performancesImport' => '12', 'lastPerformedImport' => '2011']),
			3 => self::work(3, ['title' => 'C']),
			4 => self::work(4, ['title' => 'D', 'repertoireImport' => 'früher']),
			5 => self::work(5, ['title' => 'E']),
		];
		$derived = [
			1 => ['performances' => 3, 'first' => '2020-01-01', 'last' => '2025-01-01', 'class' => 'current', 'songLists' => []],
			3 => ['performances' => 2, 'first' => '2001-01-01', 'last' => '2005-01-01', 'class' => 'earlier', 'songLists' => []],
			5 => ['performances' => 0, 'first' => null, 'last' => null, 'class' => 'none', 'songLists' => ['2024']],
		];
		self::assertSame(['classes' => ['current' => 1, 'dormant' => 1, 'earlier' => 2, 'none' => 1], 'sources' => ['performances' => 2, 'import' => 2]],
			StatisticsService::repertoireClasses($works, $derived));

		$versions = [1 => self::version(1, 1), 2 => self::version(2, 2), 3 => self::version(3, 3), 4 => self::version(4, 4, ['preservationCopy' => true])];
		$copies = [self::copy(1, 1, 1), self::copy(2, 2, 1), self::copy(3, 3, 1, 'retired'), self::copy(4, 3, 2), self::copy(5, 4, 2)];
		$singable = StatisticsService::singableCopies($copies, $versions, $works);
		self::assertSame([1, 2, 3], array_keys($singable));
		$long = StatisticsService::longNotSung($works, $derived, $singable, 10);
		// D has only an internal preservation copy; B was sung more often than C
		self::assertSame(2, $long['total']);
		self::assertSame([[2, 'dormant', '1-2'], [3, 'earlier', '2-4']],
			array_map(fn (array $w): array => [$w['workId'], $w['class'], $w['shelfmark']], $long['rows']));
	}

	public function testTasksByRoleAndAge(): void {
		$now = self::time('2026-10-22');
		$day = 86400;
		$tasks = [
			['addressee' => 'conductor', 'status' => 'open', 'category' => 'rights', 'created' => $now - 2 * $day],
			['addressee' => 'conductor', 'status' => 'answered', 'category' => null, 'created' => $now - 60 * $day],
			['addressee' => 'librarian', 'status' => 'open', 'category' => null, 'created' => $now - 200 * $day],
			['addressee' => 'librarian', 'status' => 'open', 'category' => null, 'created' => $now - 800 * $day],
		];
		$rows = StatisticsService::tasksByRole($tasks, $now);
		self::assertSame(['conductor', 'librarian', 'operations', 'reader'], array_column($rows, 'addressee'));
		self::assertSame(['addressee' => 'conductor', 'open' => 1, 'answered' => 1, 'days30' => 1, 'days90' => 1, 'days365' => 0, 'older' => 0], $rows[0]);
		self::assertSame(['addressee' => 'librarian', 'open' => 2, 'answered' => 0, 'days30' => 0, 'days90' => 0, 'days365' => 1, 'older' => 1], $rows[1]);
	}

	/**
	 * @return array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int, blocking: list<array{personId: ?int, name: string, reason: string}>, persons: int}
	 */
	private static function proposal(string $status, ?string $until, int $persons = 1): array {
		return ['status' => $status, 'until' => $until, 'freeSince' => null, 'reason' => 'running', 'parameters' => [],
			'decisivePersonId' => null, 'blocking' => [], 'persons' => $persons];
	}

	public function testProtectionTermsEndingInThePeriod(): void {
		$works = [1 => self::work(1, ['title' => 'A']), 2 => self::work(2, ['title' => 'B']), 3 => self::work(3, ['title' => 'C'])];
		$versions = [1 => self::version(1, 1, ['rightsStatus' => 'protected']), 2 => self::version(2, 2, ['preservationCopy' => true]),
			3 => self::version(3, 3), 4 => self::version(4, 3)];
		$byVersion = StatisticsService::copiesByVersion([self::copy(1, 1, 1), self::copy(2, 2, 1), self::copy(3, 3, 1)]);
		$proposals = [
			1 => self::proposal('protected', '2027-12-31'),
			2 => self::proposal('protected', '2028-12-31'),
			3 => self::proposal('free', '1930-12-31'),
			// no copies: not counted
			4 => self::proposal('protected', '2027-12-31'),
		];
		$result = StatisticsService::protectionEnding($proposals, $versions, $works, $byVersion, 2026, 2030, 50);
		self::assertSame([0, 1, 1, 0, 0], array_column($result['years'], 'versions'));
		self::assertSame(2, $result['total']);
		self::assertSame(1, $result['omitted']);
		self::assertSame([[1, '2027-12-31', ['1-1']]],
			array_map(fn (array $r): array => [$r['versionId'], $r['until'], array_column($r['copies'], 'shelfmark')], $result['rows']));
	}

	/**
	 * A copy of the kind "Preservation copy" in a version without the flag
	 * is counted, but never listed, like a version marked as one: the rule
	 * of RightsPolicy::isPreservationCopy (S N17).
	 */
	public function testCopiesOfTheKindPreservationCopyAreCountedButNotListed(): void {
		$works = [1 => self::work(1, ['title' => 'A']), 2 => self::work(2, ['title' => 'Secret'])];
		$versions = [1 => self::version(1, 1, ['rightsStatus' => 'protected']), 2 => self::version(2, 2, ['rightsStatus' => 'protected'])];
		$kind = RightsPolicy::PRESERVATION_COPY_KIND;
		$copies = [self::copy(1, 1, 1), self::copy(2, 2, 1, 'active', 0, $kind), self::copy(3, 1, 1, 'active', 0, $kind)];
		$byVersion = StatisticsService::copiesByVersion($copies);

		$stock = StatisticsService::stock($copies, $versions, []);
		self::assertSame(2, $stock['preservationCopies']);
		self::assertSame(2, $stock['preservationVersions']);

		$created = [['copyId' => 1, 'time' => self::time('2025-03-01'), 'reason' => 'accession'],
			['copyId' => 2, 'time' => self::time('2025-04-01'), 'reason' => 'accession']];
		$accessions = StatisticsService::accessions($copies, $created, $versions, $works, 2025, new \DateTimeZone('UTC'));
		self::assertSame(['1-1'], array_column($accessions['rows'], 'shelfmark'));
		self::assertSame(1, $accessions['omitted']);

		// Copy 3 of work A is a preservation copy, copy 1 is sung from
		self::assertSame([1 => [['id' => 1, 'shelfmark' => '1-1']]], StatisticsService::singableCopies($copies, $versions, $works));

		$license = License::fromParams(['validFrom' => '2025-02-01', 'validUntil' => null, 'uses' => 'binder', 'holder' => 'Verlag', 'created' => 0]);
		$license->setId(7);
		$licenses = StatisticsService::licenseReport([2 => [$license]], $versions, $works, $byVersion, 2025, '2026-10-22', new \DateTimeZone('UTC'));
		self::assertSame([], $licenses['started']);
		self::assertSame(1, $licenses['omitted']);

		$ending = StatisticsService::protectionEnding([2 => self::proposal('protected', '2027-12-31')], $versions, $works, $byVersion, 2026, 2030, 50);
		self::assertSame(1, $ending['total']);
		self::assertSame([], $ending['rows']);
		self::assertSame(1, $ending['omitted']);
		// The rows name the copies without their kind
		$listed = StatisticsService::protectionEnding([1 => self::proposal('protected', '2027-12-31')],
			[1 => self::version(1, 1, ['rightsStatus' => 'protected'])], $works, [1 => [$byVersion[1][0]]], 2026, 2030, 50);
		self::assertSame([['id' => 1, 'shelfmark' => '1-1', 'status' => 'active']], $listed['rows'][0]['copies']);
	}

	public function testOpenRightsQuestions(): void {
		$versions = [
			1 => self::version(1, 1, ['rightsStatus' => 'open']),
			2 => self::version(2, 1),
			3 => self::version(3, 2, ['rightsStatus' => 'public_domain']),
			4 => self::version(4, 2, ['rightsStatus' => 'protected']),
			5 => self::version(5, 3, ['rightsStatus' => 'protected', 'confirmedAt' => '2026-01-01']),
			6 => self::version(6, 3, ['rightsStatus' => 'public_domain', 'confirmedAt' => '2026-01-01']),
		];
		$byVersion = StatisticsService::copiesByVersion([self::copy(1, 4, 1), self::copy(2, 5, 1), self::copy(3, 6, 1)]);
		$proposals = [4 => self::proposal('free', '1930-12-31'), 5 => self::proposal('free', '1930-12-31'), 6 => self::proposal('unknown', null),
			3 => self::proposal('unknown', null)];
		$tasks = [['addressee' => 'librarian', 'status' => 'open', 'category' => 'rights', 'created' => 0],
			['addressee' => 'librarian', 'status' => 'open', 'category' => 'other', 'created' => 0]];
		self::assertSame(['open' => 1, 'none' => 1, 'publicDomainUnconfirmed' => 1, 'protectedUnconfirmed' => 1, 'protectionEnded' => 2,
			'conflicts' => 1, 'rightsTasks' => 1], StatisticsService::openRights($versions, $proposals, $byVersion, $tasks));
	}

	public function testLicensesOfTheAnnualReport(): void {
		$works = [1 => self::work(1, ['title' => 'A']), 2 => self::work(2, ['title' => 'B'])];
		$versions = [1 => self::version(1, 1), 2 => self::version(2, 2, ['preservationCopy' => true])];
		$byVersion = StatisticsService::copiesByVersion([self::copy(1, 1, 1), self::copy(2, 1, 1, 'retired')]);
		$license = function (int $id, ?string $from, ?string $until, int $created = 0): License {
			$license = License::fromParams(['validFrom' => $from, 'validUntil' => $until, 'uses' => 'binder,portal', 'holder' => 'Verlag',
				'created' => $created]);
			$license->setId($id);
			return $license;
		};
		$licenses = [
			1 => [$license(1, '2025-02-01', '2026-01-31'), $license(2, null, '2025-06-30', self::time('2024-01-01')),
				$license(3, null, null, self::time('2025-05-05'))],
			2 => [$license(4, '2025-01-01', null)],
		];
		$report = StatisticsService::licenseReport($licenses, $versions, $works, $byVersion, 2025, '2026-10-22', new \DateTimeZone('UTC'));
		self::assertSame('2025-12-31', $report['validOn']);
		self::assertSame(3, $report['valid']);
		self::assertSame([1, 3], array_column($report['started'], 'licenseId'));
		self::assertSame([2], array_column($report['ended'], 'licenseId'));
		self::assertSame(['1-1'], $report['started'][0]['shelfmarks']);
		self::assertSame(['binder', 'portal'], $report['started'][0]['uses']);
		self::assertSame(1, $report['omitted']);
		self::assertSame('2026-10-22', StatisticsService::licenseReport($licenses, $versions, $works, $byVersion, 2026, '2026-10-22',
			new \DateTimeZone('UTC'))['validOn']);
	}

	/**
	 * A catalog of 2,000 copies with 40 years of concerts: the aggregation
	 * in PHP stays far below a second (the database part is a fixed number
	 * of queries; tests/Integration/statistics_test.php measures the whole
	 * call against a real database).
	 */
	public function testTwoThousandCopiesAreAggregatedQuickly(): void {
		$works = [];
		$versions = [];
		$copies = [];
		$files = [];
		$created = [];
		$proposals = [];
		for ($i = 1; $i <= 2000; $i++) {
			$works[$i] = self::work($i, ['title' => 'Work ' . $i, 'composer' => 'Composer ' . ($i % 150)]);
			$versions[$i] = self::version($i, $i, ['rightsStatus' => ['public_domain', 'protected', 'open'][$i % 3],
				'preservationCopy' => $i % 50 === 0, 'validSince' => $i % 4 === 0 ? '2020-01-01' : null]);
			$copies[] = self::copy($i, $i, intdiv($i, 80) + 1, $i % 20 === 0 ? 'retired' : 'active', self::time('2020-01-01'));
			$files[$i] = ['pdf' => 1, 'mxl' => $i % 3 === 0 ? 1 : 0];
			$created[] = ['copyId' => $i, 'time' => self::time(sprintf('%04d-03-01', 1990 + $i % 37)), 'reason' => $i % 2 === 0 ? 'import' : 'accession'];
			$proposals[$i] = self::proposal('protected', sprintf('%04d-12-31', 2000 + $i % 60));
		}
		$events = [];
		$items = [];
		for ($e = 1; $e <= 400; $e++) {
			$events[$e] = self::event($e, sprintf('%04d-%02d-15', 1987 + intdiv($e - 1, 10), $e % 12 + 1));
			for ($p = 0; $p < 15; $p++) {
				$items[] = self::item($e, ($e * 7 + $p * 13) % 2000 + 1);
			}
		}
		$licenses = [];
		for ($i = 1; $i <= 300; $i++) {
			$licenses[$i][] = License::fromParams(['validFrom' => '2020-01-01', 'validUntil' => sprintf('%04d-06-30', 2020 + $i % 10), 'uses' => 'portal']);
		}
		$zone = new \DateTimeZone('Europe/Berlin');
		$derived = [];
		foreach ($works as $id => $work) {
			$derived[$id] = ['performances' => $id % 7, 'first' => '1990-01-01', 'last' => sprintf('%04d-01-01', 1990 + $id % 37),
				'class' => ['current', 'dormant', 'earlier', 'none'][$id % 4], 'songLists' => []];
		}

		$start = hrtime(true);
		$stock = StatisticsService::stock($copies, $versions, $files);
		$additions = StatisticsService::additions($copies, $created, 1987, 2026, $zone);
		StatisticsService::rightsStatus($versions);
		StatisticsService::validVersions($works, $versions, $works, []);
		StatisticsService::licenseCounts($licenses, '2026-10-22');
		$performances = StatisticsService::performances($events, $items, $works, 1987, 2026);
		StatisticsService::repertoireClasses($works, $derived);
		StatisticsService::longNotSung($works, $derived, StatisticsService::singableCopies($copies, $versions, $works), 20);
		$byVersion = StatisticsService::copiesByVersion($copies);
		StatisticsService::protectionEnding($proposals, $versions, $works, $byVersion, 2017, 2026, 50);
		StatisticsService::openRights($versions, $proposals, $byVersion, []);
		$seconds = (hrtime(true) - $start) / 1e9;

		self::assertSame(2000, $stock['copies']);
		self::assertSame(2000, array_sum(array_column($additions, 'total')));
		self::assertSame(400 * 15, array_sum(array_column($performances['years'], 'pieces')));
		self::assertLessThan(0.5, $seconds, sprintf('aggregation took %.3f s', $seconds));
	}
}
