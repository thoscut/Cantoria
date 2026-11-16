<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RepertoireService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Repertoire figures from documented performances: the thresholds as
 * settings, the classes measured from the current year or from a fixed
 * reference year, dates by day, month or year, song lists apart.
 */
#[CoversClass(RepertoireService::class)]
final class RepertoireServiceTest extends TestCase {
	/** @var array<string, int> the app settings */
	private array $settings = [];
	/** @var list<Event> */
	private array $events = [];
	/** @var list<EventItem> */
	private array $items = [];
	/** @var array<string, bool> */
	private array $modules = ['concerts' => true, 'repertoire' => true];

	#[\Override]
	protected function setUp(): void {
		$this->settings = [];
		$this->events = [];
		$this->items = [];
		$this->modules = ['concerts' => true, 'repertoire' => true];
	}

	private function service(string $today = '2026-11-06'): RepertoireService {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => $this->settings[$key] ?? $default);
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime($today . ' 12:00:00', new \DateTimeZone('Europe/Berlin')));
		$events = $this->createStub(EventMapper::class);
		$events->method('findAllById')->willReturnCallback(function (): array {
			$byId = [];
			foreach ($this->events as $event) {
				$byId[$event->getId()] = $event;
			}
			return $byId;
		});
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findAll')->willReturnCallback(fn (): array => $this->items);
		$modules = $this->createStub(Modules::class);
		$modules->method('isEnabled')->willReturnCallback(fn (string $key): bool => $this->modules[$key] ?? false);
		return new RepertoireService($events, $items, $config, $time, $modules);
	}

	private function concert(int $id, ?string $date, string $status = 'sung'): void {
		$event = new Event();
		$event->setId($id);
		$event->setKind(Event::CONCERT);
		$event->setEventDate($date);
		$event->setStatus($status);
		$this->events[] = $event;
	}

	private function songList(int $id, string $period): void {
		$event = new Event();
		$event->setId($id);
		$event->setKind(Event::SONG_LIST);
		$event->setPeriod($period);
		$event->setStatus('fixed');
		$this->events[] = $event;
	}

	private function line(int $eventId, ?int $workId, bool $counts = true, string $assignment = EventItem::CERTAIN): void {
		$item = new EventItem();
		$item->setEventId($eventId);
		$item->setWorkId($workId);
		$item->setAssignment($assignment);
		$item->setCounts($counts);
		$this->items[] = $item;
	}

	/** Four works last sung in 2023, 2022, 2015 and never (song list only) */
	private function fourWorks(): void {
		$this->concert(1, '2023-12-10');
		$this->line(1, 1);
		$this->concert(2, '2022-06-01');
		$this->line(2, 2);
		$this->concert(3, '2015-05-05');
		$this->line(3, 3);
		$this->songList(4, '2024');
		$this->line(4, 4, false);
	}

	/** @return array<int, string> class per work */
	private function classes(string $today = '2026-11-06'): array {
		return array_map(fn (array $work): string => $work['class'], $this->service($today)->perWork());
	}

	public function testDefaultThresholdsMeasureFromToday(): void {
		self::assertSame(['current' => 3, 'dormant' => 10, 'referenceYear' => 0], $this->service()->thresholds());
		self::assertSame(2026, $this->service()->referenceYear());
	}

	public function testSetThresholdsClampsAndStores(): void {
		$service = $this->service();
		$service->setThresholds(0, 2, 2026);
		self::assertSame(['current' => 1, 'dormant' => 2, 'referenceYear' => 2026], $service->thresholds());
		self::assertSame(2026, $service->referenceYear());
		// "dormant" is at least as long as "current"; no reference year means today
		$service->setThresholds(5, 3);
		self::assertSame(['current' => 5, 'dormant' => 5, 'referenceYear' => 0], $service->thresholds());
		// Upper limits and impossible years
		$service->setThresholds(99, 999, 1850);
		self::assertSame(['current' => 50, 'dormant' => 100, 'referenceYear' => 0], $service->thresholds());
		$service->setThresholds(3, 10, 3000);
		self::assertSame(0, $service->thresholds()['referenceYear']);
		self::assertSame(['repertoire_current_years', 'repertoire_dormant_years', 'repertoire_reference_year'], array_keys($this->settings));
	}

	public function testClassesRollWithTheCurrentYear(): void {
		$this->fourWorks();
		// 2026: current from 2023, dormant from 2016
		self::assertSame([1 => 'current', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2026-11-06'));
		// A year later the work last sung in 2023 drops to dormant, 2016 to earlier
		self::assertSame([1 => 'dormant', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2027-01-01'));
	}

	public function testAFixedReferenceYearKeepsTheLimits(): void {
		$this->fourWorks();
		// The choir's rule "current = sung in 2023 or later, dormant = 2016 to 2022" as a fixed setting
		$this->service()->setThresholds(3, 10, 2026);
		self::assertSame([1 => 'current', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2026-11-06'));
		self::assertSame([1 => 'current', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2027-01-01'));
		self::assertSame([1 => 'current', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2031-06-30'));
		self::assertSame(2026, $this->service('2031-06-30')->referenceYear());
		// Back to rolling
		$this->service()->setThresholds(3, 10, 0);
		self::assertSame([1 => 'dormant', 2 => 'dormant', 3 => 'earlier', 4 => 'none'], $this->classes('2027-01-01'));
	}

	public function testPerformancesAreCountedPerDateOfAnyPrecision(): void {
		// An annual list (year), a monthly list and a concert with a day date are three dates
		$this->concert(1, '2019');
		$this->line(1, 7);
		$this->concert(2, '2019-06');
		$this->line(2, 7);
		$this->concert(3, '2019-06-15');
		$this->line(3, 7);
		// The same day twice is one performance day
		$this->concert(4, '2019-06-15');
		$this->line(4, 7);
		$figures = $this->service()->perWork();
		self::assertSame(3, $figures[7]['performances']);
		self::assertSame('2019', $figures[7]['first']);
		self::assertSame('2019-06-15', $figures[7]['last']);
		self::assertSame('dormant', $figures[7]['class']);
		self::assertSame([], $figures[7]['songLists']);
	}

	public function testAMonthAsTheLastDateGivesItsYear(): void {
		$this->concert(1, '2024-05');
		$this->line(1, 7);
		$figures = $this->service('2026-11-06')->perWork();
		self::assertSame('2024-05', $figures[7]['last']);
		self::assertSame('current', $figures[7]['class']);
	}

	public function testOnlyCountingLinesOfSungConcertsWithADate(): void {
		$this->concert(1, '2025-03-03');
		$this->line(1, 7, false);
		$this->concert(2, '2025-03-04', 'fixed');
		$this->line(2, 7);
		$this->concert(3, null);
		$this->line(3, 7);
		$this->concert(4, '2025-03-05');
		$this->line(4, 7, true, EventItem::AMBIGUOUS);
		$this->line(4, null);
		self::assertSame([], $this->service()->perWork());
	}

	public function testSongListsAreNoProofOfPerformance(): void {
		$this->songList(1, '2024');
		$this->line(1, 7, false);
		$this->songList(2, 'Advent 2025');
		$this->line(2, 7, false);
		$figures = $this->service()->perWork();
		self::assertSame(['performances' => 0, 'first' => null, 'last' => null, 'class' => 'none', 'songLists' => ['2024', 'Advent 2025']], $figures[7]);
	}

	public function testModulesDecideWhatIsReported(): void {
		$this->fourWorks();
		$this->modules['repertoire'] = false;
		$figures = $this->service()->perWork();
		self::assertSame('none', $figures[1]['class']);
		self::assertSame(0, $figures[1]['performances']);
		self::assertSame(['2024'], $figures[4]['songLists']);
		$this->modules['concerts'] = false;
		self::assertSame([], $this->service()->perWork());
	}
}
