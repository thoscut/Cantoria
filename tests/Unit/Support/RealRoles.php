<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Support;

use OCA\Cantoria\Service\Roles;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The real role check (Roles) with one group per role, for the tests that
 * check what each role may do (T32: one helper instead of one per test).
 *
 * @psalm-require-extends TestCase
 */
trait RealRoles {
	/**
	 * @param \Closure(string, string): bool $holds whether an account holds a role
	 * @param \Closure(string): bool $isAdmin whether an account is an administrator
	 */
	protected function realRoles(\Closure $holds, \Closure $isAdmin): Roles {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => ['g-' . substr($key, strlen('groups_'))]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $holds($uid, substr($group, 2)));
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $isAdmin($uid));
		return new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class),
			$this->createStub(LoggerInterface::class));
	}
}
