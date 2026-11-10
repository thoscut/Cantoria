<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\TaskMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A few tasks get their shelf mark and title from their own copies; only a
 * long list reads the whole catalog once.
 */
#[CoversClass(TaskService::class)]
final class TaskContextTest extends TestCase {
	public function testAFewTasksReadOnlyTheirCopies(): void {
		$copies = $this->createMock(CopyMapper::class);
		$copies->expects(self::never())->method('findAll');
		$copies->expects(self::exactly(2))->method('find')->willReturnCallback(fn (int $id): Copy => self::copy($id));
		$versions = $this->createMock(WorkVersionMapper::class);
		$versions->expects(self::never())->method('findAllById');
		$versions->method('find')->willReturnCallback(fn (int $id): WorkVersion => self::version($id));
		$works = $this->createMock(WorkMapper::class);
		$works->expects(self::never())->method('findAllById');
		$works->expects(self::once())->method('find')->willReturnCallback(fn (int $id): Work => self::work($id));

		$rows = self::service($copies, $versions, $works)->withContext([self::task(1, 31), self::task(2, 32), self::task(3, 31)]);
		self::assertSame(['10-31', '10-32', '10-31'], array_map(fn (array $row): mixed => $row['shelfmark'], $rows));
		self::assertSame(['Abendlied', 'Abendlied', 'Abendlied'], array_map(fn (array $row): mixed => $row['title'], $rows));
	}

	public function testALongListReadsTheCatalogOnce(): void {
		$copies = $this->createMock(CopyMapper::class);
		$copies->expects(self::never())->method('find');
		$copies->expects(self::once())->method('findAll')->willReturn(array_map(fn (int $id): Copy => self::copy($id), range(1, 20)));
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturn([7 => self::version(7)]);
		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturn([5 => self::work(5)]);

		$rows = self::service($copies, $versions, $works)->withContext(array_map(fn (int $id): Task => self::task($id, $id), range(1, 20)));
		self::assertCount(20, $rows);
		self::assertSame('Abendlied', $rows[19]['title']);
	}

	private function service(CopyMapper $copies, WorkVersionMapper $versions, WorkMapper $works): TaskService {
		return new TaskService($this->createStub(TaskMapper::class), $copies, $versions, $works, $this->createStub(AuditLog::class),
			$this->createStub(Roles::class), $this->createStub(NotificationService::class), $this->createStub(IUserManager::class),
			$this->createStub(ITimeFactory::class));
	}

	private static function task(int $id, int $copyId): Task {
		$task = new Task();
		$task->setId($id);
		$task->setCopyId($copyId);
		return $task;
	}

	private static function copy(int $id): Copy {
		$copy = new Copy();
		$copy->setId($id);
		$copy->setVersionId(7);
		$copy->setShelfmark('10-' . $id);
		return $copy;
	}

	private static function version(int $id): WorkVersion {
		$version = new WorkVersion();
		$version->setId($id);
		$version->setWorkId(5);
		return $version;
	}

	private static function work(int $id): Work {
		$work = new Work();
		$work->setId($id);
		$work->setTitle('Abendlied');
		return $work;
	}
}
