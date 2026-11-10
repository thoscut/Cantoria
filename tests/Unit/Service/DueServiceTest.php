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
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concerts with a deadline on the start page "due" (and so in the dashboard
 * widget): for the roles the notifications go to, and only for the modules
 * that are on.
 */
#[CoversClass(DueService::class)]
final class DueServiceTest extends TestCase {
	use ModuleSwitches;

	private const TODAY = '2026-10-22';

	/**
	 * @param list<string> $roles
	 * @param list<string> $off modules switched off
	 * @param list<Copy> $copies
	 */
	private function service(array $roles, array $off = [], ?TaskService $tasks = null, array $copies = []): DueService {
		$roleService = $this->createStub(Roles::class);
		$roleService->method('rolesOf')->willReturn($roles);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		$events = $this->createStub(EventMapper::class);
		$events->method('findByKind')->willReturn([
			self::concert(1, '2026-12-31'),                       // 70 days: 10 weeks
			self::concert(2, '2026-11-20'),                       // 29 days: 6 weeks
			self::concert(3, '2026-10-01'),                       // past, not confirmed
			self::concert(4, '2026-10-01', 'sung'),               // past, confirmed
			self::concert(5, '2026-11-20', Event::CANCELLED),     // cancelled
			self::concert(6, '2026-11-20', 'fixed', true),        // imported history
			self::concert(7, '2027-06-01'),                       // far ahead
		]);
		if ($tasks === null) {
			$tasks = $this->createStub(TaskService::class);
			$tasks->method('forUser')->willReturn([['id' => 4, 'copy_id' => 35, 'status' => 'open']]);
		}
		$proposals = $this->createStub(ProposalMapper::class);
		$proposals->method('countOpen')->willReturn(2);
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAll')->willReturn($copies);
		return new DueService($roleService, $tasks, $proposals,
			$this->createStub(ReleaseListService::class), $this->createStub(LicenseMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(WorkMapper::class), $rights, $this->createStub(RepertoireService::class), $this->createStub(EventItemMapper::class),
			$events, $this->modulesWithout($off), $copyMapper);
	}

	private static function concert(int $id, string $date, string $status = 'draft', bool $imported = false): Event {
		$event = new Event();
		$event->setId($id);
		$event->setKind(Event::CONCERT);
		$event->setTitle('Konzert ' . $id);
		$event->setEventDate($date);
		$event->setStatus($status);
		$event->setImported($imported);
		return $event;
	}

	public function testTheConductorSeesDeadlinesAndConcertsToConfirm(): void {
		$concerts = $this->service([Roles::CONDUCTOR])->forUser('carla')['concerts'];
		self::assertSame([
			['id' => 3, 'title' => 'Konzert 3', 'date' => '2026-10-01', 'stage' => 'confirm', 'label' => 'Confirm what was sung'],
			['id' => 2, 'title' => 'Konzert 2', 'date' => '2026-11-20', 'stage' => '6', 'label' => 'Prepare the concert binders'],
			['id' => 1, 'title' => 'Konzert 1', 'date' => '2026-12-31', 'stage' => '10', 'label' => 'Fix the programme and the valid versions'],
		], $concerts);
	}

	public function testTheLibrarianSeesTheDeadlinesOnly(): void {
		$concerts = $this->service([Roles::LIBRARIAN])->forUser('lena')['concerts'];
		self::assertSame([2, 1], array_column($concerts, 'id'));
	}

	public function testNothingOfASwitchedOffModule(): void {
		$all = $this->service([Roles::CONDUCTOR, Roles::LIBRARIAN])->forUser('carla');
		self::assertNotSame([], $all['concerts']);
		self::assertContains('proposals', array_column($all['cards'], 'key'));

		$due = $this->service([Roles::CONDUCTOR, Roles::LIBRARIAN], ['concerts', 'proposals'])->forUser('carla');
		self::assertSame([], $due['concerts']);
		self::assertNotContains('proposals', array_column($due['cards'], 'key'));
		self::assertContains('tasks_open', array_column($due['cards'], 'key'), 'the other cards stay');
	}

	public function testTasksAreNotReadWithoutTheModule(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects(self::never())->method('forUser');
		$due = $this->service([Roles::CONDUCTOR], ['tasks'], $tasks)->forUser('carla');
		self::assertSame([], $due['tasks']);
		self::assertNotContains('tasks_open', array_column($due['cards'], 'key'));
	}

	public function testOtherRolesSeeNoConcerts(): void {
		self::assertSame([], $this->service([Roles::READER, Roles::OPERATIONS, Roles::CONTRIBUTOR])->forUser('rolf')['concerts']);
	}

	public function testTheLibrarianSeesHiredMaterialToReturn(): void {
		$copy = function (?string $returnDate, string $status = 'active'): Copy {
			$copy = new Copy();
			$copy->setReturnDate($returnDate);
			$copy->setStatus($status);
			return $copy;
		};
		// overdue, within 30 days, later, none, retired (returned)
		$copies = [$copy('2026-10-01'), $copy('2026-11-20'), $copy('2027-02-01'), $copy(null), $copy('2026-10-01', 'retired')];
		$cards = $this->service([Roles::LIBRARIAN], [], null, $copies)->forUser('lena')['cards'];
		self::assertContains(['key' => 'hired_return', 'count' => 2, 'label' => 'Hired material to return'], $cards);
		self::assertNotContains('hired_return', array_column($this->service([Roles::CONDUCTOR], [], null, $copies)->forUser('carla')['cards'], 'key'));
		self::assertNotContains('hired_return', array_column($this->service([Roles::LIBRARIAN])->forUser('lena')['cards'], 'key'));
	}
}
