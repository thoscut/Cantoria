<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RightsService::class)]
final class RightsServiceTest extends TestCase {
	private const TODAY = '2026-10-22';
	private const NOW = 1761134400;
	private const UID = 'anna';
	private const VERSION_ID = 10;

	private const NOT_CONFIRMED = 'Public domain is not confirmed yet.';
	private const NOT_RELEASED = 'Neither confirmed public domain nor a valid license for this use.';
	private const PRESERVATION = 'Internal preservation copy: never given to singers.';

	private IDBConnection&MockObject $db;
	private WorkVersionMapper&MockObject $versions;
	private LicenseMapper&MockObject $licenses;
	private AuditLog&MockObject $audit;
	private Roles&MockObject $roles;
	private RightsService $service;

	/** @var list<string> */
	private array $transaction = [];
	/** @var list<array{field: ?string, old: mixed, new: mixed, action: string, reason: ?string, uid: ?string}> */
	private array $recorded = [];

	#[\Override]
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$this->db->method($method)->willReturnCallback(function () use ($method): void {
				$this->transaction[] = $method;
			});
		}
		$this->versions = $this->createMock(WorkVersionMapper::class);
		$this->licenses = $this->createMock(LicenseMapper::class);
		$this->audit = $this->createMock(AuditLog::class);
		$this->audit->method('newOperation')->willReturn('op-1');
		$this->audit->method('record')->willReturnCallback(
			function (string $operation, ?string $uid, string $objectType, ?int $objectId, string $action, ?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
				self::assertSame('op-1', $operation);
				self::assertSame('version', $objectType);
				self::assertSame(self::VERSION_ID, $objectId);
				$this->recorded[] = ['field' => $field, 'old' => $old, 'new' => $new, 'action' => $action, 'reason' => $reason, 'uid' => $uid];
			});
		$this->roles = $this->createMock(Roles::class);

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getDisplayName')->willReturnMap([[self::UID, 'Anna Archiv']]);
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(
			fn (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime => new \DateTime(self::TODAY . ' 12:00:00', $zone));

		$this->service = new RightsService($this->db, $this->versions, $this->licenses, $this->audit, $this->roles,
			$userManager, $time, $timeZone);
	}

	/**
	 * @param array<string, mixed> $values column => value
	 */
	private static function version(array $values = []): WorkVersion {
		$version = new WorkVersion();
		$version->setId(self::VERSION_ID);
		foreach ($values as $column => $value) {
			$version->setValue($column, $value);
		}
		$version->resetUpdatedFields();
		return $version;
	}

	private static function license(string $uses, ?string $from = null, ?string $until = null): License {
		return License::fromParams(['versionId' => self::VERSION_ID, 'uses' => $uses, 'validFrom' => $from, 'validUntil' => $until]);
	}

	private function givenVersion(WorkVersion $version): void {
		$this->versions->method('find')->with(self::VERSION_ID)->willReturn($version);
	}

	// ---- releases ----

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string}>
	 */
	public static function releases(): iterable {
		$publicDomain = ['rights_status' => 'public_domain'];
		$protected = ['rights_status' => 'protected'];
		$confirmed = ['confirmed_at' => '2026-01-10', 'confirmed_by' => self::UID];

		// version, licenses [uses, from, until], portal denial, print station denial
		yield 'confirmed public domain' => [$publicDomain + $confirmed, [], null, null];
		yield 'unconfirmed public domain' => [$publicDomain, [], self::NOT_CONFIRMED, self::NOT_CONFIRMED];
		yield 'unconfirmed public domain with a portal license' => [$publicDomain, [['portal']], null, self::NOT_CONFIRMED];
		yield 'protected without license' => [$protected, [], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'confirmed protected without license' => [$protected + $confirmed, [], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'protected, license for the portal' => [$protected, [['portal']], null, self::NOT_RELEASED];
		yield 'protected, license for the print station' => [$protected, [['print_station']], self::NOT_RELEASED, null];
		yield 'protected, one license for both' => [$protected, [['portal,print_station']], null, null];
		yield 'protected, license for other uses' => [$protected, [['binder,self_print,practice_audio']], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'protected, license ended yesterday' => [$protected, [['portal', null, '2026-10-21']], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'protected, license ends today' => [$protected, [['portal', null, self::TODAY]], null, self::NOT_RELEASED];
		yield 'protected, license starts tomorrow' => [$protected, [['portal', '2026-10-23']], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'protected, ended and renewed license' => [$protected, [['portal', null, '2020-12-31'], ['portal', '2021-01-01']], null, self::NOT_RELEASED];
		yield 'open' => [['rights_status' => 'open'], [], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'open with license' => [['rights_status' => 'open'], [['portal']], null, self::NOT_RELEASED];
		yield 'no rights status' => [[], [], self::NOT_RELEASED, self::NOT_RELEASED];
		yield 'preservation copy, confirmed public domain' => [$publicDomain + $confirmed + ['preservation_copy' => true], [], self::PRESERVATION, self::PRESERVATION];
		yield 'preservation copy with licenses' => [$protected + ['preservation_copy' => true], [['portal,print_station']], self::PRESERVATION, self::PRESERVATION];
		yield 'preservation flag unknown (null) is no preservation copy' => [$publicDomain + $confirmed + ['preservation_copy' => null], [], null, null];
	}

	/**
	 * @param array<string, mixed> $versionValues
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('releases')]
	public function testReleaseDenials(array $versionValues, array $licenseSpecs, ?string $portal, ?string $printStation): void {
		$version = self::version($versionValues);
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		self::assertSame($portal, $this->service->portalDenial($version, $licenses, self::TODAY));
		self::assertSame($printStation, $this->service->printStationDenial($version, $licenses, self::TODAY));
	}

	public function testHasLicense(): void {
		$licenses = [self::license('binder', null, '2025-12-31'), self::license('practice_audio,portal', '2026-01-01')];
		self::assertFalse($this->service->hasLicense([], 'portal', self::TODAY));
		self::assertTrue($this->service->hasLicense($licenses, 'portal', self::TODAY));
		self::assertTrue($this->service->hasLicense($licenses, 'practice_audio', self::TODAY));
		self::assertFalse($this->service->hasLicense($licenses, 'binder', self::TODAY), 'the binder license has ended');
		self::assertTrue($this->service->hasLicense($licenses, 'binder', '2025-12-31'));
		self::assertFalse($this->service->hasLicense($licenses, 'print_station', self::TODAY));
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function portalStates(): iterable {
		yield 'never added' => [[], false];
		yield 'added' => [['portal_since' => '2025-04-01'], true];
		yield 'added and removed' => [['portal_since' => '2025-04-01', 'portal_removed_at' => '2026-01-01'], false];
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[DataProvider('portalStates')]
	public function testIsOnPortal(array $values, bool $onPortal): void {
		self::assertSame($onPortal, $this->service->isOnPortal(self::version($values)));
	}

	public function testTodayIsTheDateInTheTimeZoneOfTheUser(): void {
		$zone = new \DateTimeZone('Pacific/Auckland');
		$timeZone = $this->createMock(IDateTimeZone::class);
		$timeZone->expects(self::once())->method('getTimeZone')->with(false, 'bob')->willReturn($zone);
		$time = $this->createMock(ITimeFactory::class);
		$time->expects(self::once())->method('getDateTime')->with('now', $zone)
			->willReturn(new \DateTime('2026-10-22 23:30:00', new \DateTimeZone('UTC')));
		$service = new RightsService($this->db, $this->versions, $this->licenses, $this->audit, $this->roles,
			$this->createStub(IUserManager::class), $time, $timeZone);

		self::assertSame('2026-10-22', $service->today('bob'));
	}

	// ---- confirmation ----

	public function testConfirmRequiresTheLibrarianRole(): void {
		$this->roles->expects(self::once())->method('requireRole')->with('bob', [Roles::LIBRARIAN])
			->willThrowException(new AccessDeniedException('This requires one of these roles: %s'));
		$this->versions->expects(self::never())->method('find');

		$this->expectException(AccessDeniedException::class);
		$this->service->confirm(self::VERSION_ID, 'bob', 'public_domain', 'reason', 'evidence');
	}

	public function testOnlyPublicDomainOrProtectedCanBeConfirmed(): void {
		$this->versions->expects(self::never())->method('find');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Use "set open" when in doubt.');
		$this->service->confirm(self::VERSION_ID, self::UID, 'open', 'reason', 'evidence');
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function incompleteConfirmations(): iterable {
		yield 'no reason' => ['', 'GND entry'];
		yield 'blank reason' => ['  ', 'GND entry'];
		yield 'no evidence' => ['Composer died 1827', ''];
		yield 'blank evidence' => ['Composer died 1827', "\t"];
	}

	#[DataProvider('incompleteConfirmations')]
	public function testConfirmationNeedsReasonAndEvidence(string $reason, string $evidence): void {
		$this->versions->expects(self::never())->method('find');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('A confirmation needs both a reason and evidence.');
		$this->service->confirm(self::VERSION_ID, self::UID, 'public_domain', $reason, $evidence);
	}

	public function testConfirmStoresWhoWhenWhyAndRecordsEachChange(): void {
		$version = self::version(['rights_status' => 'open']);
		$this->givenVersion($version);
		$this->versions->expects(self::once())->method('update')->with($version)->willReturnArgument(0);

		$this->service->confirm(self::VERSION_ID, self::UID, 'public_domain', 'Composer died 1827', 'GND entry');

		self::assertSame('public_domain', $version->getRightsStatus());
		self::assertTrue($version->isConfirmed());
		self::assertSame(self::UID, $version->getValue('confirmed_by'));
		self::assertSame('Anna Archiv', $version->getValue('confirmed_by_name'));
		self::assertSame(self::TODAY, $version->getValue('confirmed_at'));
		self::assertSame('Composer died 1827', $version->getValue('confirmed_reason'));
		self::assertSame('GND entry', $version->getValue('confirmed_evidence'));
		self::assertSame(self::NOW, $version->getModified());
		self::assertSame(['beginTransaction', 'commit'], $this->transaction);

		self::assertSame(['rights_status', 'confirmed_by', 'confirmed_by_name', 'confirmed_at', 'confirmed_reason', 'confirmed_evidence'],
			array_column($this->recorded, 'field'));
		self::assertSame(['field' => 'rights_status', 'old' => 'open', 'new' => 'public_domain', 'action' => 'confirmed', 'reason' => 'Composer died 1827', 'uid' => self::UID],
			$this->recorded[0]);
	}

	public function testConfirmUsesTheAccountNameWithoutDisplayName(): void {
		$version = self::version();
		$this->givenVersion($version);
		$this->service->confirm(self::VERSION_ID, 'nodisplayname', 'protected', 'Arrangement 1990', 'Imprint');
		self::assertSame('nodisplayname', $version->getValue('confirmed_by_name'));
	}

	public function testConfirmOfAnUnknownVersionFails(): void {
		$this->versions->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Not found.');
		$this->service->confirm(self::VERSION_ID, self::UID, 'public_domain', 'reason', 'evidence');
	}

	public function testFailedSaveRollsBackAndRethrows(): void {
		$this->givenVersion(self::version());
		$this->versions->method('update')->willThrowException(new \RuntimeException('database gone'));

		try {
			$this->service->confirm(self::VERSION_ID, self::UID, 'public_domain', 'reason', 'evidence');
			self::fail('The exception must be rethrown');
		} catch (\RuntimeException $e) {
			self::assertSame('database gone', $e->getMessage());
		}
		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
	}

	public function testSetOpenRemovesTheConfirmation(): void {
		$version = self::version(['rights_status' => 'public_domain', 'confirmed_by' => 'bob', 'confirmed_by_name' => 'Bob',
			'confirmed_at' => '2026-01-10', 'confirmed_reason' => 'r', 'confirmed_evidence' => 'e']);
		$this->givenVersion($version);

		$this->service->setOpen(self::VERSION_ID, self::UID, 'New edition found');

		self::assertSame('open', $version->getRightsStatus());
		self::assertFalse($version->isConfirmed());
		foreach (array_keys(RightsService::noConfirmation()) as $field) {
			self::assertNull($version->getValue($field), $field);
		}
		self::assertSame(['set_open'], array_values(array_unique(array_column($this->recorded, 'action'))));
		self::assertCount(6, $this->recorded);
	}

	public function testSetOpenNeedsAReason(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->setOpen(self::VERSION_ID, self::UID, ' ');
	}

	// ---- singers' portal ----

	public function testAddToPortalNeedsAValidDate(): void {
		$this->versions->expects(self::never())->method('find');
		try {
			$this->service->addToPortal(self::VERSION_ID, self::UID, '2026-02-31');
			self::fail('An invalid date must be refused');
		} catch (InvalidInputException $e) {
			self::assertSame('"%s" needs a valid date (YYYY-MM-DD).', $e->getMessage());
			self::assertSame(['In singers\' portal since'], $e->getParameters());
		}
	}

	public function testAddToPortalIsRefusedWithoutRelease(): void {
		$this->givenVersion(self::version(['rights_status' => 'public_domain']));
		$this->licenses->expects(self::once())->method('findByVersion')->with(self::VERSION_ID)->willReturn([]);
		$this->versions->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage(self::NOT_CONFIRMED);
		$this->service->addToPortal(self::VERSION_ID, self::UID, self::TODAY);
	}

	public function testAddToPortalWithLicenseClearsAnEarlierRemoval(): void {
		$version = self::version(['rights_status' => 'protected', 'portal_since' => '2024-01-01',
			'portal_removed_at' => '2025-01-01', 'portal_removed_reason' => 'license ended']);
		$this->givenVersion($version);
		$this->licenses->method('findByVersion')->willReturn([self::license('portal', '2026-01-01', '2027-12-31')]);

		$this->service->addToPortal(self::VERSION_ID, self::UID, '2026-10-01');

		self::assertSame('2026-10-01', $version->getPortalSince());
		self::assertNull($version->getPortalRemovedAt());
		self::assertNull($version->getValue('portal_removed_reason'));
		self::assertTrue($this->service->isOnPortal($version));
		self::assertSame(['portal_added'], array_values(array_unique(array_column($this->recorded, 'action'))));
	}

	public function testRemoveFromPortalRecordsDateAndReason(): void {
		$version = self::version(['portal_since' => '2025-04-01']);
		$this->givenVersion($version);

		$this->service->removeFromPortal(self::VERSION_ID, self::UID, 'Downgraded to protected');

		self::assertSame(self::TODAY, $version->getPortalRemovedAt());
		self::assertSame('Downgraded to protected', $version->getValue('portal_removed_reason'));
		self::assertFalse($this->service->isOnPortal($version));
		self::assertSame('Downgraded to protected', $this->recorded[0]['reason']);
	}

	public function testRemoveFromPortalNeedsAReason(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->removeFromPortal(self::VERSION_ID, self::UID, '');
	}

	public function testUnchangedValuesAreNotRecorded(): void {
		$version = self::version(['rights_status' => 'protected', 'confirmed_by' => self::UID, 'confirmed_by_name' => 'Anna Archiv',
			'confirmed_at' => self::TODAY, 'confirmed_reason' => 'r', 'confirmed_evidence' => 'e']);
		$this->givenVersion($version);

		$this->service->confirm(self::VERSION_ID, self::UID, 'protected', 'r', 'new evidence');

		self::assertSame(['confirmed_evidence'], array_column($this->recorded, 'field'));
	}
}
