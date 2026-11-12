<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\LargePrintService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Large-print parts: who makes them, what the rights say, that only the
 * catalog librarian can confirm an accessible copy (§ 45b UrhG), and that
 * every part is recorded – without the name of the singer.
 */
#[CoversClass(LargePrintService::class)]
final class LargePrintServiceTest extends TestCase {
	private const DENIED = 'A large-print part needs a license.';

	/** @var list<array<int, mixed>> */
	private array $audited = [];

	/**
	 * Copy 31 is free (confirmed public domain), 32 protected without a
	 * license, 33 an internal preservation copy; versions = copy - 10.
	 *
	 * @param list<string> $roles roles of the account
	 */
	private function service(array $roles = [Roles::CONDUCTOR]): LargePrintService {
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(function (int $id): Copy {
			if ($id < 31 || $id > 33) {
				throw new DoesNotExistException('');
			}
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id - 10);
			return $copy;
		});
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id): WorkVersion {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setPreservationCopy($id === 23);
			return $version;
		});
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturn(null);
		$policy->method('largePrintBasis')->willReturnCallback(fn (WorkVersion $version): ?string => $version->getId() === 21 ? 'free' : null);
		$policy->method('largePrintDenial')->willReturnCallback(
			fn (WorkVersion $version, string $day, ?Copy $copy = null, bool $accessible = false): ?string => match (true) {
				$version->getId() === 23 => 'Internal preservation copy: no part is printed from it.',
				$version->getId() === 21, $accessible => null,
				default => self::DENIED,
			});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-11-12');
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (...$arguments): void {
			$this->audited[] = $arguments;
		});
		$roleService = $this->createStub(Roles::class);
		$roleService->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => array_intersect($roles, $allowed) !== []);
		$roleService->method('requireRole')->willReturnCallback(function (string $uid, array $allowed) use ($roles): void {
			if (array_intersect($roles, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s');
			}
		});
		return new LargePrintService($copies, $versions, $policy, $rights, $audit, $roleService);
	}

	public function testAFreePieceIsAllowedAndRecordedWithItsVoice(): void {
		$service = $this->service();
		self::assertSame(['allowed' => true, 'basis' => 'free', 'reason' => null, 'canConfirm' => false], $service->check(31, 'carla'));
		self::assertSame(['recorded' => true, 'basis' => 'free'], $service->record(31, ' Alt ', false, 'carla'));
		self::assertSame([['op', 'carla', 'copy', 31, 'large_print_made', 'voice', null, 'Alt', null]], $this->audited);
	}

	public function testWithoutALicenseOnlyTheCatalogLibrarianCanConfirmAnAccessibleCopy(): void {
		$conductor = $this->service([Roles::CONDUCTOR]);
		self::assertSame(['allowed' => false, 'basis' => null, 'reason' => self::DENIED, 'canConfirm' => false], $conductor->check(32, 'carla'));
		try {
			$conductor->record(32, 'Alt', true, 'carla');
			self::fail('the conductor cannot confirm an accessible copy');
		} catch (AccessDeniedException $e) {
			self::assertSame(self::DENIED, $e->getMessage());
		}
		self::assertSame([], $this->audited);

		$librarian = $this->service([Roles::LIBRARIAN]);
		self::assertTrue($librarian->check(32, 'lea')['canConfirm']);
		self::assertSame(['recorded' => true, 'basis' => 'accessible'], $librarian->record(32, 'Alt', true, 'lea'));
		self::assertSame('Accessible copy for a person with a visual impairment (§ 45b UrhG), confirmed by the catalog librarian', $this->audited[0][8]);
		self::assertSame('Alt', $this->audited[0][7], 'the voice is recorded, never a name of the singer');
	}

	public function testTheLibrarianMustConfirmExpressly(): void {
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage(self::DENIED);
		$this->service([Roles::LIBRARIAN])->record(32, 'Alt', false, 'lea');
	}

	public function testAPreservationCopyGivesNoPartAndNothingToConfirm(): void {
		$service = $this->service([Roles::LIBRARIAN]);
		$check = $service->check(33, 'lea');
		self::assertFalse($check['allowed']);
		self::assertFalse($check['canConfirm']);
		$this->expectException(AccessDeniedException::class);
		$service->record(33, 'Alt', true, 'lea');
	}

	public function testOtherRolesMakeNoParts(): void {
		$this->expectException(AccessDeniedException::class);
		$this->service([Roles::READER])->check(31, 'rita');
	}

	public function testOtherRolesRecordNoParts(): void {
		$this->expectException(AccessDeniedException::class);
		$this->service([Roles::OPERATIONS])->record(31, 'Alt', false, 'otto');
	}

	public function testAPartNeedsItsVoice(): void {
		$this->expectException(InvalidInputException::class);
		$this->service()->record(31, '  ', false, 'carla');
	}

	public function testAnUnknownCopyIsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->service()->check(99, 'carla');
	}

	public function testACopyTheAccountMayNotViewGivesNoPart(): void {
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturn('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		$copies = $this->createStub(CopyMapper::class);
		$copy = new Copy();
		$copy->setId(31);
		$copy->setVersionId(21);
		$copies->method('find')->willReturn($copy);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn(new WorkVersion());
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturn(true);
		$service = new LargePrintService($copies, $versions, $policy, $this->createStub(RightsService::class), $this->createStub(AuditLog::class), $roles);
		$this->expectException(AccessDeniedException::class);
		$service->check(31, 'carla');
	}
}
