<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\AccountRecordFinder;
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
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
	/** Stored retention period of the audit log, null: not set */
	private ?int $retentionYears = null;

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
		$records = $this->createStub(AccountRecordFinder::class);
		$records->method('find')->willReturnCallback(fn (array $fields, string $who): array => $who === 'kim'
			? [['table' => 'cantoria_tasks', 'id' => 8, 'fields' => ['created_by' => 'kim']]] : []);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $this->retentionYears ?? $default);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793000000);
		$time->method('getDateTime')->willReturnCallback(fn (string $when): \DateTime => new \DateTime($when));
		return new PrivacyService($loanMapper, $folders, $folderBook, $entries, $persons, $audit, $records, $config, $time);
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
		$byAccount = $this->service($loans, $this->createStub(FolderBookService::class), $entries)->report('kim');
		self::assertSame([1], array_column($byAccount['loans'], 'id'), 'by account');
		self::assertSame([['table' => 'cantoria_tasks', 'id' => 8, 'fields' => ['created_by' => 'kim']]], $byAccount['records'],
			'what the account created or decided');
	}

	/**
	 * Every field of an entity that holds an account or its name is in the
	 * access report (Art. 15 GDPR): new ones cannot be forgotten. The folder
	 * book and the audit log have reports of their own.
	 */
	public function testTheReportKnowsEveryFieldWithAnAccount(): void {
		$own = ['FolderLoan' => true, 'AuditEntry' => true];
		$dir = __DIR__ . '/../../../lib/Db/';
		$checked = 0;
		foreach (glob($dir . '*Mapper.php') ?: [] as $mapperFile) {
			$mapper = (string)file_get_contents($mapperFile);
			if (preg_match("/parent::__construct\\(\\\$db, '([a-z_]+)', ([A-Za-z]+)::class/", $mapper, $m) !== 1 || isset($own[$m[2]])) {
				continue;
			}
			$entity = (string)file_get_contents($dir . $m[2] . '.php');
			preg_match_all('/protected \\??string \\$([a-zA-Z]+(?:By|ByName|Uid|UserId|UserName))\\b/', $entity, $fields);
			foreach ($fields[1] as $field) {
				$column = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
				self::assertContains($column, PrivacyService::ACCOUNT_FIELDS[$m[1]] ?? [], $m[2] . '::$' . $field . ' (' . $m[1] . ')');
				$checked++;
			}
		}
		self::assertGreaterThan(15, $checked);
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

	/**
	 * The audit log keeps who made a change for ten years by default (the
	 * longest period of limitation, Art. 17 (3) (e) GDPR), then only the role.
	 */
	public function testTheAuditLogLosesAccountsAfterTheRetentionPeriod(): void {
		$entries = $this->createMock(AuditEntryMapper::class);
		// Ten years before now
		$entries->expects(self::once())->method('pseudonymizeBefore')->with((new \DateTime('@1793000000'))->modify('-10 years')->getTimestamp())->willReturn(3);
		self::assertSame(3, $this->service([], $this->createStub(FolderBookService::class), $entries)->applyRetention());
		self::assertSame([['pseudonymised', null]], $this->recorded, 'recorded without a name');

		$this->retentionYears = 0;
		$never = $this->createMock(AuditEntryMapper::class);
		$never->expects(self::never())->method('pseudonymizeBefore');
		self::assertSame(0, $this->service([], $this->createStub(FolderBookService::class), $never)->applyRetention(), '0: kept for good');
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
