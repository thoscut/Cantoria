<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Folder;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The folder book: one loan per folder at a time, plausible days, the
 * person as account or name, the hint for versions that no longer apply,
 * and the retention period after which names are removed.
 */
#[CoversClass(FolderBookService::class)]
final class FolderBookServiceTest extends TestCase {
	/** @var list<array{0: string, 1: ?int, 2: string, 3: ?string, 4: mixed}> audit entries: type, id, action, field, new value */
	private array $recorded = [];

	private function folder(int $id = 7, string $status = Folder::ACTIVE): Folder {
		$folder = new Folder();
		$folder->setId($id);
		$folder->setLabel('12');
		$folder->setStatus($status);
		$folder->setWear('worn');
		$folder->resetUpdatedFields();
		return $folder;
	}

	private function loan(?string $returnedOn = null): FolderLoan {
		$loan = new FolderLoan();
		$loan->setId(3);
		$loan->setFolderId(7);
		$loan->setOpenFolderId($returnedOn === null ? 7 : null);
		$loan->setHolderName('Alex Example');
		$loan->setIssuedOn('2026-09-01');
		$loan->setReturnedOn($returnedOn);
		return $loan;
	}

	/**
	 * @param array<string, mixed> $mappers replacements: folders, loans, users, entries, config
	 */
	private function service(array $mappers = []): FolderBookService {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireRole')->willReturnCallback(function (string $uid, array $allowed): void {
			if (!in_array($uid, $allowed, true)) {
				throw new \OCA\Cantoria\Exception\AccessDeniedException('This requires one of these roles: %s', [implode(', ', $allowed)]);
			}
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-22');
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action,
			?string $field = null, mixed $old = null, mixed $new = null): void {
			$this->recorded[] = [$type, $id, $action, $field, $new];
		});
		$folders = $mappers['folders'] ?? $this->createStub(FolderMapper::class);
		if (!isset($mappers['folders'])) {
			$folders->method('find')->willReturn($this->folder());
			$folders->method('findAll')->willReturn([$this->folder()]);
		}
		return new FolderBookService($this->createStub(IDBConnection::class), $folders,
			$mappers['loans'] ?? $this->createStub(FolderLoanMapper::class), $mappers['copies'] ?? $this->createStub(CopyMapper::class),
			$this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class), $audit,
			$mappers['entries'] ?? $this->createStub(AuditEntryMapper::class), $roles, $rights,
			$mappers['users'] ?? $this->createStub(IUserManager::class), $mappers['config'] ?? $this->createStub(IAppConfig::class),
			$this->createStub(ITimeFactory::class));
	}

	private static function message(callable $action): ?string {
		try {
			$action();
		} catch (InvalidInputException $e) {
			return vsprintf($e->getMessage(), $e->getParameters());
		}
		return null;
	}

	public function testAFolderIsIssuedOnlyOnceAtATime(): void {
		$loans = $this->createStub(FolderLoanMapper::class);
		$loans->method('findOpen')->willReturn($this->loan());
		self::assertSame('This folder is already out with someone. Take it back first.',
			self::message(fn () => $this->service(['loans' => $loans])->issue(7, ['holderName' => 'Kim Example'], 'librarian')));
	}

	public function testARetiredFolderIsNotIssued(): void {
		$folders = $this->createStub(FolderMapper::class);
		$folders->method('find')->willReturn($this->folder(7, Folder::RETIRED));
		self::assertSame('This folder is out of service. Put it back into service before you issue it.',
			self::message(fn () => $this->service(['folders' => $folders])->issue(7, ['holderName' => 'Kim Example'], 'operations')));
	}

	public function testThePersonIsAnAccountOrAName(): void {
		$service = $this->service();
		$expected = 'Please choose a Nextcloud account or enter the name of the person (one of both).';
		self::assertSame($expected, self::message(fn () => $service->issue(7, [], 'librarian')));
		self::assertSame($expected, self::message(fn () => $service->issue(7, ['holderName' => '  '], 'librarian')));
		self::assertSame($expected, self::message(fn () => $service->issue(7, ['holderName' => 'Kim', 'holderUid' => 'kim'], 'librarian')));
		self::assertSame('This Nextcloud account does not exist. Please choose it from the list or enter the name instead.',
			self::message(fn () => $service->issue(7, ['holderUid' => 'nobody'], 'librarian')));
		self::assertSame('Unknown field: phone', self::message(fn () => $service->issue(7, ['holderName' => 'Kim', 'phone' => '1'], 'librarian')));
	}

	public function testAnAccountIsStoredWithItsDisplayName(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('kim');
		$user->method('getDisplayName')->willReturn('Kim Example');
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($user);
		$loans = $this->createMock(FolderLoanMapper::class);
		$loans->method('findOpen')->willReturn(null);
		$loans->expects(self::once())->method('insert')->willReturnCallback(function (FolderLoan $loan): FolderLoan {
			self::assertSame('kim', $loan->getHolderUid());
			self::assertSame('Kim Example', $loan->getHolderName());
			self::assertSame(7, $loan->getOpenFolderId(), 'marks the folder as out');
			self::assertSame('2026-10-22', $loan->getIssuedOn(), 'today by default');
			$loan->setId(11);
			return $loan;
		});
		self::assertSame(['id' => 11], $this->service(['users' => $users, 'loans' => $loans])->issue(7, ['holderUid' => 'kim'], 'operations'));
		self::assertContains(['folder_loan', 11, 'folder_issued', 'holder_name', 'Kim Example'], $this->recorded);
	}

	public function testDaysMustBeValidAndNotInTheFuture(): void {
		$service = $this->service();
		self::assertSame('"Handed out on" needs a valid date (YYYY-MM-DD).',
			self::message(fn () => $service->issue(7, ['holderName' => 'Kim', 'issuedOn' => '2026-02-31'], 'librarian')));
		self::assertSame('The date lies in the future. Please enter the day the folder changed hands.',
			self::message(fn () => $service->issue(7, ['holderName' => 'Kim', 'issuedOn' => '2026-10-23'], 'librarian')));
	}

	public function testTakingBackFillsInTheFolderAndFreesIt(): void {
		$loans = $this->createMock(FolderLoanMapper::class);
		$loans->method('find')->willReturn($this->loan());
		$loans->expects(self::once())->method('update')->willReturnCallback(function (FolderLoan $loan): FolderLoan {
			self::assertNull($loan->getOpenFolderId(), 'the folder can be issued again');
			self::assertSame('2026-10-22', $loan->getReturnedOn());
			self::assertSame('damaged', $loan->getReturnWear());
			return $loan;
		});
		$folder = $this->folder();
		$folders = $this->createMock(FolderMapper::class);
		$folders->method('find')->willReturn($folder);
		$folders->expects(self::once())->method('update')->willReturnArgument(0);
		$this->service(['loans' => $loans, 'folders' => $folders])->takeBack(3, ['wear' => 'damaged', 'missing' => 'p. 3 of 2-35'], 'librarian');
		self::assertSame('damaged', $folder->getWear());
		self::assertSame('p. 3 of 2-35', $folder->getMissing(), 'what is missing now');
	}

	public function testAFolderComesBackOnlyOnceAndNotBeforeItLeft(): void {
		$loans = $this->createStub(FolderLoanMapper::class);
		$loans->method('find')->willReturn($this->loan('2026-10-01'));
		self::assertSame('This folder has already been taken back. Please reload the folder book.',
			self::message(fn () => $this->service(['loans' => $loans])->takeBack(3, ['wear' => 'good'], 'librarian')));
		$loans = $this->createStub(FolderLoanMapper::class);
		$loans->method('find')->willReturn($this->loan());
		self::assertSame('The folder cannot come back before it was handed out (2026-09-01). Please check the date.',
			self::message(fn () => $this->service(['loans' => $loans])->takeBack(3, ['wear' => 'good', 'returnedOn' => '2026-08-31'], 'librarian')));
		self::assertSame('Please choose the condition of the folder: good, worn or damaged.',
			self::message(fn () => $this->service(['loans' => $loans])->takeBack(3, ['wear' => 'excellent'], 'librarian')));
	}

	public function testAnIssuedFolderIsNotRetired(): void {
		$loans = $this->createStub(FolderLoanMapper::class);
		$loans->method('findOpen')->willReturn($this->loan());
		$service = $this->service(['loans' => $loans]);
		self::assertSame('Please give a reason for taking the folder out of service.',
			self::message(fn () => $service->updateFolder(7, ['status' => 'retired'], 'librarian')));
		self::assertSame('This folder is out with someone. Take it back first, then take it out of service.',
			self::message(fn () => $service->updateFolder(7, ['status' => 'retired', 'statusReason' => 'torn'], 'librarian')));
	}

	public function testAFolderComesBackIntoServiceOnlyWithAFreeLabel(): void {
		$folders = $this->createStub(FolderMapper::class);
		$folders->method('find')->willReturn($this->folder(7, Folder::RETIRED));
		// meanwhile a new folder 12 is in service
		$folders->method('findAll')->willReturn([$this->folder(7, Folder::RETIRED), $this->folder(8)]);
		self::assertSame('A folder in service is already called "12". Please choose another number or name, or take the other folder out of service first.',
			self::message(fn () => $this->service(['folders' => $folders])->updateFolder(7, ['status' => 'active'], 'librarian')));
		self::assertNull(self::message(fn () => $this->service(['folders' => $folders])->updateFolder(7, ['status' => 'active', 'label' => '12a'], 'librarian')),
			'with a new label it may come back');
	}

	public function testContentsAreCopiesWithANumber(): void {
		$copy = new Copy();
		$copy->setId(35);
		$copy->setShelfmark('2-35');
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($copy);
		$folders = $this->createMock(FolderMapper::class);
		$folders->method('find')->willReturn($this->folder());
		$folders->method('findAll')->willReturn([]);
		$stored = null;
		$folders->method('update')->willReturnCallback(function (Folder $folder) use (&$stored): Folder {
			$stored = $folder->getContents();
			return $folder;
		});
		$service = $this->service(['copies' => $copies, 'folders' => $folders]);
		$service->updateFolder(7, ['contents' => [['copyId' => 35, 'count' => 2], ['copyId' => 35]]], 'operations');
		self::assertSame('[{"copyId":35,"count":3}]', $stored, 'the same copy twice is added up');
		self::assertSame([['copyId' => 35, 'count' => 3]], FolderBookService::decodeContents($stored));
		self::assertSame('The number of 2-35 must be between 1 and 999.',
			self::message(fn () => $service->updateFolder(7, ['contents' => [['copyId' => 35, 'count' => 0]]], 'operations')));
		self::assertSame('The contents must be a list of copies with their number.',
			self::message(fn () => $service->updateFolder(7, ['contents' => [['copyId' => 35, 'shelf' => 'x']]], 'operations')));
		self::assertSame([], FolderBookService::decodeContents('not json'));
	}

	public function testAHintForVersionsThatNoLongerApply(): void {
		$copy = new Copy();
		$copy->setStatus('active');
		$version = new WorkVersion();
		$version->setId(4);
		$version->setWorkId(2);
		self::assertNull(FolderBookService::outdated($copy, $version, []), 'no valid version chosen');
		self::assertNull(FolderBookService::outdated($copy, $version, [2 => 4]), 'this one is valid');
		self::assertSame('other_version_valid', FolderBookService::outdated($copy, $version, [2 => 5]));
		$copy->setStatus('retired');
		self::assertSame('retired', FolderBookService::outdated($copy, $version, [2 => 4]));
		self::assertSame('retired', FolderBookService::outdated(null, null, []));
	}

	public function testTheRetentionPeriodIsCountedInMonthsAfterTheReturn(): void {
		self::assertSame('2025-10-22', FolderBookService::monthsBefore('2026-10-22', 12));
		self::assertSame('2026-02-28', FolderBookService::monthsBefore('2026-03-31', 1), 'the end of a shorter month');
		self::assertSame('2024-02-29', FolderBookService::monthsBefore('2024-03-31', 1));
		self::assertSame('2016-10-22', FolderBookService::monthsBefore('2026-10-22', 120));
	}

	public function testExpiredLoansLoseTheirPersonalDataAlsoInTheAuditLog(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueInt')->willReturn(6);
		$loan = $this->loan('2026-04-01');
		$loan->setHolderUid('kim');
		$loan->setIssueNote('Kim will bring it back after the summer');
		$loans = $this->createMock(FolderLoanMapper::class);
		$loans->expects(self::once())->method('findToAnonymize')->with('2026-04-22')->willReturn([$loan]);
		$loans->expects(self::once())->method('update')->willReturnArgument(0);
		$entries = $this->createMock(AuditEntryMapper::class);
		$entries->expects(self::once())->method('anonymizeFields')
			->with('folder_loan', 3, ['holder_uid', 'holder_name', 'issue_note', 'return_note'], '(anonymised)');
		$this->service(['loans' => $loans, 'entries' => $entries, 'config' => $config])->anonymizeExpired('2026-10-22');
		self::assertNull($loan->getHolderName());
		self::assertNull($loan->getHolderUid());
		self::assertNull($loan->getIssueNote());
		self::assertNotNull($loan->getAnonymized());
		self::assertSame('2026-04-01', $loan->getReturnedOn(), 'the loan itself stays');
		self::assertSame([['folder_loan', 3, 'anonymized', null, null]], $this->recorded, 'noted without the name');
	}

	public function testFoldersThatNeverComeBackLoseTheHolderAfterThreeYears(): void {
		$loan = $this->loan();
		$loan->setIssuedOn('2023-10-01');
		$loan->setHolderUid('kim');
		$loans = $this->createMock(FolderLoanMapper::class);
		$loans->method('findToAnonymize')->willReturn([]);
		$loans->expects(self::once())->method('findOpenToAnonymize')->with('2023-10-22')->willReturn([$loan]);
		$loans->method('update')->willReturnArgument(0);
		$this->service(['loans' => $loans])->anonymizeExpired('2026-10-22');
		self::assertNull($loan->getHolderName());
		self::assertNull($loan->getHolderUid());
		self::assertNotNull($loan->getAnonymized());
		self::assertSame(7, $loan->getOpenFolderId(), 'the folder stays recorded as issued');
		self::assertSame([['folder_loan', 3, 'anonymized', null, null]], $this->recorded);
	}

	public function testTheRetentionPeriodHasLimits(): void {
		$service = $this->service();
		self::assertSame('Please enter the retention period as a whole number of months between 1 and 120.',
			self::message(fn () => $service->setRetentionMonths(0, 'admin')));
		self::assertSame('Please enter the retention period as a whole number of months between 1 and 120.',
			self::message(fn () => $service->setRetentionMonths(121, 'admin')));
		self::assertSame(1, $service->retentionMonths(), 'a stored 0 counts as the shortest period');
	}
}
