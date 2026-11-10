<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A notification whose occasion is switched off is not sent; withdrawing
 * always works.
 */
#[CoversClass(NotificationService::class)]
final class NotificationServiceTest extends TestCase {
	use ModuleSwitches;

	/**
	 * @param list<string> $off modules switched off
	 */
	private function service(IManager&Stub $manager, array $off): NotificationService {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('lena');
		$group = $this->createStub(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$roles = $this->createStub(Roles::class);
		$roles->method('groups')->willReturn(['archive']);
		$notification = $this->createStub(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$manager->method('createNotification')->willReturn($notification);
		return new NotificationService($manager, $groups, $roles, $this->createStub(ITimeFactory::class),
			$this->createStub(LoggerInterface::class), $this->modulesWithout($off));
	}

	public function testSendsWhileTheOccasionIsOn(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::once())->method('notify');
		$this->service($manager, [])->notifyRole(Roles::LIBRARIAN, NotificationService::TASK_ANSWERED, 'task', '4');
	}

	public function testThePersonWhoCausedItIsNotNotified(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');
		$this->service($manager, [])->notifyRole(Roles::LIBRARIAN, NotificationService::CORRECTION_RECEIVED, 'version', '3', [], false, 'lena');
	}

	public function testSendsNothingForASwitchedOffOccasion(): void {
		foreach ([['notify_task_answered'], ['tasks'], ['notifications']] as $off) {
			$this->moduleConfig = [];
			$manager = $this->createMock(IManager::class);
			$manager->expects(self::never())->method('notify');
			$manager->expects(self::once())->method('markProcessed');
			$service = $this->service($manager, $off);
			$service->notifyRole(Roles::LIBRARIAN, NotificationService::TASK_ANSWERED, 'task', '4');
			$service->withdraw(NotificationService::TASK_ANSWERED, 'task', '4');
		}
	}
}
