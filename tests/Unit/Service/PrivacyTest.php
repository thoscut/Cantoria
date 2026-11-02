<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\AuditEntry;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\Folder;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\LifeDates;
use OCA\Cantoria\Service\PrivacyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Data protection (milestone 0.11): access and erasure for one person, a
 * deleted account, and only the year of birth of persons without a known
 * death.
 */
#[CoversClass(PrivacyService::class)]
#[CoversClass(LifeDates::class)]
#[CoversClass(Person::class)]
final class PrivacyTest extends TestCase {
	/** @var list<array{string, ?string}> */
	private array $recorded = [];

	private static function loan(int $id, ?string $uid, ?string $name, ?string $returnedOn): FolderLoan {
		$loan = new FolderLoan();
		$loan->setId($id);
		$loan->setFolderId(7);
		$loan->setHolderUid($uid);
		$loan->setHolderName($name);
		$loan->setIssuedOn('2026-01-10');
		$loan->setReturnedOn($returnedOn);
		$loan->setOpenFolderId($returnedOn === null ? 7 : null);
		return $loan;
	}

	/**
	 * @param list<FolderLoan> $loans
	 */
	private function service(array $loans, FolderBookService $folderBook, AuditEntryMapper $entries): PrivacyService {
		$loanMapper = $this->createStub(FolderLoanMapper::class);
		$loanMapper->method('findAll')->willReturn($loans);
		$loanMapper->method('update')->willReturnArgument(0);
		$folder = new Folder();
		$folder->setId(7);
		$folder->setLabel('Alto 3');
		$folders = $this->createStub(FolderMapper::class);
		$folders->method('findAll')->willReturn([$folder]);
		$person = new Person();
		$person->setName('Kim Example');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAll')->willReturn([$person]);
		$audit = $this->createStub(AuditLog::class);
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action,
			?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
			$this->recorded[] = [$action, $uid];
		});
		return new PrivacyService($loanMapper, $folders, $folderBook, $entries, $persons, $audit);
	}

	public function testTheReportNamesLoansEntriesAndPersonsOfAnAccountOrAName(): void {
		$entry = new AuditEntry();
		$entry->setUserId('kim');
		$entry->setUserName('Kim Example');
		$entries = $this->createStub(AuditEntryMapper::class);
		$entries->method('findByActor')->willReturn([$entry]);
		$loans = [self::loan(1, 'kim', null, '2026-03-01'), self::loan(2, null, 'kim example ', null), self::loan(3, null, 'Robin', null)];
		$report = $this->service($loans, $this->createStub(FolderBookService::class), $entries)->report('Kim Example');
		self::assertSame([2], array_column($report['loans'], 'id'), 'by name, whatever the case');
		self::assertSame('Alto 3', $report['loans'][0]['folder']);
		self::assertCount(1, $report['auditEntries']);
		self::assertSame(['Kim Example'], array_column($report['persons'], 'name'));
		self::assertSame([1], array_column($this->service($loans, $this->createStub(FolderBookService::class), $entries)->report('kim')['loans'], 'id'),
			'by account');
	}

	public function testErasureAnonymisesTheLoansAndTheAuditEntriesAndRecordsNoName(): void {
		$loans = [self::loan(1, 'kim', 'Kim Example', '2026-03-01'), self::loan(2, null, 'Kim Example', null), self::loan(3, null, 'Robin', null)];
		$folderBook = $this->createMock(FolderBookService::class);
		$folderBook->expects(self::exactly(2))->method('anonymizeLoan')
			->with(self::callback(fn (FolderLoan $loan): bool => in_array($loan->getId(), [1, 2], true)), 'erased on request');
		$entries = $this->createMock(AuditEntryMapper::class);
		$entries->expects(self::once())->method('removeAccount')->with('Kim Example', '(anonymised)')->willReturn(0);
		$entries->expects(self::once())->method('replaceName')->with('Kim Example', '(anonymised)')->willReturn(4);
		$result = $this->service($loans, $folderBook, $entries)->erase('Kim Example');
		self::assertSame(['loans' => 2, 'auditEntries' => 4, 'persons' => 1], $result, 'persons of the register are only counted');
		self::assertSame([['erased', null]], $this->recorded);
	}

	public function testADeletedAccountLeavesNoAccountBehind(): void {
		$returned = self::loan(1, 'kim', 'Kim Example', '2026-03-01');
		$open = self::loan(2, 'kim', null, null);
		$other = self::loan(3, 'robin', 'Robin', '2026-03-01');
		$folderBook = $this->createMock(FolderBookService::class);
		$folderBook->expects(self::once())->method('anonymizeLoan')->with($returned, 'account deleted');
		$entries = $this->createMock(AuditEntryMapper::class);
		$entries->expects(self::once())->method('anonymizeFields')->with('folder_loan', 2, ['holder_uid'], '(anonymised)');
		$entries->expects(self::once())->method('removeAccount')->with('kim', null);
		$this->service([$returned, $open, $other], $folderBook, $entries)->forgetAccount('kim', 'Kim Example');
		self::assertNull($open->getHolderUid());
		self::assertSame('Kim Example', $open->getHolderName(), 'the folder is still out: the name stays until its own term ends');
		self::assertSame('robin', $other->getHolderUid());
		self::assertSame([['changed', null]], $this->recorded);
	}

	public function testPersonsWithoutAKnownDeathKeepOnlyTheYearOfBirth(): void {
		self::assertSame(['born' => '1960', 'born_precision' => 'year'],
			LifeDates::birthYearOnly(['born' => '1960-03-12', 'born_precision' => 'exact', 'died' => null]));
		self::assertSame(['born' => '1860-03-12', 'born_precision' => 'exact'],
			LifeDates::birthYearOnly(['born' => '1860-03-12', 'born_precision' => 'exact', 'died' => '1930']), 'the dead are no data subjects');
		self::assertSame('* 1960, lebend; laut Verlag 1960; 1960', LifeDates::yearsOnly('* 12.03.1960, lebend; laut Verlag 1960-03-12; 12. März 1960'));

		$person = new Person();
		$person->setBorn('1960-03-12');
		$person->setBornPrecision('exact');
		$person->setDiedPrecision('living');
		$person->setLifeDatesText('geb. 12.3.1960');
		$person->keepBirthYearOnly();
		self::assertSame(['1960', 'year', 'geb. 1960'], [$person->getBorn(), $person->getBornPrecision(), $person->getLifeDatesText()]);
		$person->keepBirthYearOnly();
		self::assertSame(['1960', 'year', 'geb. 1960'], [$person->getBorn(), $person->getBornPrecision(), $person->getLifeDatesText()], 'nothing left to shorten');
	}
}
