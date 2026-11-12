<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\StateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A new programme from an existing one: a draft of the kind chosen with the
 * pieces of the source in their order, without the struck ones and without
 * what was recorded about the performance; all or nothing.
 */
#[CoversClass(EventService::class)]
final class ProgrammeCopyTest extends TestCase {
	/** @var list<EventItem> */
	private array $inserted = [];
	/** @var list<string> */
	private array $transaction = [];

	private function service(bool $planner = true): EventService {
		$source = new Event();
		$source->setId(4);
		$source->setKind(Event::CONCERT);
		$source->setTitle('Adventskonzert 2025');
		$source->setStatus('sung');
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturnCallback(fn (int $id): Event => $id === 4 ? $source : throw new DoesNotExistException(''));
		$events->method('insert')->willReturnCallback(function (Event $event): Event {
			$event->setId(9);
			return $event;
		});
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturn([
			self::item(1, 31, duration: 180, note: 'mit Solo'),
			self::item(2, 32, struck: true),
			self::item(3, null, title: 'Tochter Zion', assignment: EventItem::NOT_IN_CATALOG),
			self::item(4, 31, encore: true, sung: true, stateId: 77),
		]);
		$items->method('insert')->willReturnCallback(function (EventItem $item): EventItem {
			$this->inserted[] = $item;
			return $item;
		});
		$db = $this->createStub(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->transaction[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->transaction[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->transaction[] = 'rollback';
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('requireRole')->willReturnCallback(function () use ($planner): void {
			if (!$planner) {
				throw new AccessDeniedException('This requires one of these roles: %s');
			}
		});
		return new EventService($db, $events, $items, $this->createStub(CopyMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(WorkMapper::class), $this->createStub(LicenseMapper::class), $this->createStub(RightsService::class),
			$this->createStub(StateService::class), $this->createStub(AuditLog::class), $roles, $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->createStub(ContributionMapper::class), $this->createStub(PersonMapper::class));
	}

	private static function item(int $position, ?int $copyId, string $title = '', bool $struck = false, bool $encore = false, ?int $duration = null,
		?string $note = null, string $assignment = EventItem::CERTAIN, ?bool $sung = null, ?int $stateId = null): EventItem {
		$item = new EventItem();
		$item->setId(100 + $position);
		$item->setEventId(4);
		$item->setPosition($position);
		$item->setCopyId($copyId);
		$item->setVersionId($copyId === null ? null : $copyId - 10);
		$item->setTitleText($title);
		$item->setStruck($struck);
		$item->setEncore($encore);
		$item->setDuration($duration);
		$item->setNote($note);
		$item->setAssignment($assignment);
		$item->setSung($sung);
		$item->setStateId($stateId);
		$item->setCounts(true);
		return $item;
	}

	public function testTheNewProgrammeTakesThePiecesInTheirOrderWithoutTheStruckOnes(): void {
		$event = $this->service()->duplicate(4, Event::CONCERT, ['title' => 'Adventskonzert 2026', 'event_date' => '2026-12-13'], 'carla');
		self::assertSame(9, $event->getId());
		self::assertSame('draft', $event->getStatus());
		self::assertSame('2026-12-13', $event->getEventDate());
		self::assertSame([1, 2, 3], array_map(fn (EventItem $item): int => $item->getPosition(), $this->inserted));
		self::assertSame([31, null, 31], array_map(fn (EventItem $item): ?int => $item->getCopyId(), $this->inserted));
		self::assertSame([180, 'mit Solo'], [$this->inserted[0]->getDuration(), $this->inserted[0]->getNote()]);
		self::assertSame(['Tochter Zion', EventItem::NOT_IN_CATALOG], [$this->inserted[1]->getTitleText(), $this->inserted[1]->getAssignment()]);
		self::assertTrue($this->inserted[2]->getEncore());
		foreach ($this->inserted as $item) {
			self::assertSame(9, $item->getEventId());
			self::assertFalse($item->getCounts(), 'a copied piece is not a performance');
			self::assertNull($item->getSung());
			self::assertNull($item->getStateId(), 'the revision sung stays with the source');
		}
		self::assertSame(['begin', 'commit'], $this->transaction);
	}

	public function testAConcertBecomesASongListToo(): void {
		$event = $this->service()->duplicate(4, Event::SONG_LIST, ['title' => 'Weihnachtslieder'], 'carla');
		self::assertSame(Event::SONG_LIST, $event->getKind());
		self::assertCount(3, $this->inserted);
	}

	public function testNothingIsWrittenWithoutATitle(): void {
		try {
			$this->service()->duplicate(4, Event::CONCERT, ['title' => ' '], 'carla');
			self::fail('no title');
		} catch (InvalidInputException) {
			self::assertSame([], $this->inserted);
			self::assertSame(['begin', 'rollback'], $this->transaction);
		}
	}

	public function testOnlyPlannersCopyAndTheSourceMustExist(): void {
		$this->expectException(AccessDeniedException::class);
		try {
			$this->service()->duplicate(5, Event::CONCERT, ['title' => 'x'], 'carla');
			self::fail('unknown source');
		} catch (NotFoundException) {
		}
		$this->service(planner: false)->duplicate(4, Event::CONCERT, ['title' => 'x'], 'singer');
	}
}
