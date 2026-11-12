<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ArchiveAccess;
use OCA\Cantoria\Service\Roles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The access check of the archive folder: per role and group, who can open
 * it in their own Nextcloud, who cannot (named), who is not checked; the
 * admins without a role; the person who asks; the limit. Only reads.
 */
#[CoversClass(ArchiveAccess::class)]
final class ArchiveAccessTest extends TestCase {
	private const FOLDER_ID = 4711;
	/** A folder chosen as the new archive folder, not saved yet */
	private const CHOSEN_ID = 815;
	/** @var list<string> who can open the chosen folder */
	private array $chosen = [];

	private int $folderId = self::FOLDER_ID;
	/** @var array<string, list<string>> role => groups */
	private array $roleGroups = [
		'librarian' => ['archive'],
		'operations' => [],
		'conductor' => ['conductors', 'gone'],
		'contributor' => [],
		'reader' => ['board'],
	];
	/** @var array<string, list<string>> group => members */
	private array $groups = [
		'archive' => ['anna', 'bert'],
		'conductors' => ['carla'],
		'board' => ['dora', 'emil', 'frida', 'anna'],
		'admin' => ['root', 'anna'],
	];
	/** @var array<string, bool> who can open the archive folder */
	private array $access = ['anna' => true, 'bert' => false, 'carla' => true, 'dora' => false, 'emil' => true, 'root' => false];
	/** @var list<string> */
	private array $disabled = ['frida'];
	/** @var list<string> */
	private array $neverLoggedIn = [];
	/** @var list<string> accounts whose files cannot be set up */
	private array $broken = [];
	/** @var list<string> accounts whose files were looked at */
	private array $looked = [];

	private function service(): ArchiveAccess {
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturnCallback(fn (): array => ['id' => $this->folderId, 'path' => '/Archive', 'user' => 'anna']);

		$roles = $this->createStub(Roles::class);
		$roles->method('groups')->willReturnCallback(fn (string $role): array => $this->roleGroups[$role] ?? []);
		$roles->method('rolesOf')->willReturnCallback(function (string $uid): array {
			$roles = [];
			foreach ($this->roleGroups as $role => $groups) {
				foreach ($groups as $group) {
					if (in_array($uid, $this->groups[$group] ?? [], true)) {
						$roles[] = $role;
					}
				}
			}
			return array_values(array_unique($roles));
		});

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(function (string $id): ?IGroup {
			if (!isset($this->groups[$id])) {
				return null;
			}
			$group = $this->createStub(IGroup::class);
			$group->method('getDisplayName')->willReturn('Group ' . $id);
			$group->method('count')->willReturn(count($this->groups[$id]));
			$group->method('searchUsers')->willReturnCallback(function (string $search, ?int $limit = null) use ($id): array {
				$users = [];
				foreach (array_slice($this->groups[$id], 0, $limit) as $uid) {
					$users[$uid] = $this->user($uid);
				}
				return $users;
			});
			return $group;
		});

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
			$this->looked[] = $uid;
			if (in_array($uid, $this->broken, true)) {
				throw new NotPermittedException('storage not available');
			}
			$folder = $this->createStub(Folder::class);
			$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?Node => match (true) {
				$id === self::FOLDER_ID && ($this->access[$uid] ?? false) => $this->createStub(Folder::class),
				$id === self::CHOSEN_ID && in_array($uid, $this->chosen, true) => $this->createStub(Folder::class),
				// A file with the id of the folder does not count
				$uid === 'odd' => $this->createStub(File::class),
				default => null,
			});
			return $folder;
		});
		return new ArchiveAccess($settings, $roles, $groupManager, $userManager, $root, $this->createStub(LoggerInterface::class));
	}

	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid) . ' Example');
		$user->method('isEnabled')->willReturn(!in_array($uid, $this->disabled, true));
		$user->method('getLastLogin')->willReturn(in_array($uid, $this->neverLoggedIn, true) ? 0 : 1761150000);
		return $user;
	}

	/**
	 * @param array{roles: list<array{role: string, groups: list<array<string, mixed>>}>, ...} $report
	 * @return array<string, mixed>
	 */
	private static function group(array $report, string $role, string $group): array {
		foreach ($report['roles'] as $entry) {
			foreach ($entry['groups'] as $candidate) {
				if ($entry['role'] === $role && $candidate['id'] === $group) {
					return $candidate;
				}
			}
		}
		self::fail('no group ' . $group . ' for ' . $role);
	}

	public function testCountsWhoCanOpenTheFolderPerRoleAndGroup(): void {
		$report = $this->service()->report('anna');

		self::assertSame(['id' => self::FOLDER_ID, 'path' => '/Archive', 'user' => 'anna'], $report['archive']);
		self::assertSame(['librarian', 'operations', 'conductor', 'contributor', 'reader'], array_column($report['roles'], 'role'));

		$archive = self::group($report, 'librarian', 'archive');
		self::assertSame(['id' => 'archive', 'displayName' => 'Group archive', 'exists' => true, 'members' => 2, 'checked' => 2,
			'canOpen' => 1, 'cannotOpen' => [['uid' => 'bert', 'displayName' => 'Bert Example']], 'notChecked' => 0], $archive);

		$board = self::group($report, 'reader', 'board');
		self::assertSame(4, $board['members']);
		self::assertSame(3, $board['checked']);
		self::assertSame(2, $board['canOpen']);
		self::assertSame([['uid' => 'dora', 'displayName' => 'Dora Example']], $board['cannotOpen']);
		// frida is disabled
		self::assertSame(1, $board['notChecked']);

		self::assertSame(1, self::group($report, 'conductor', 'conductors')['canOpen']);
		self::assertTrue($report['complete']);
	}

	public function testAGroupThatNoLongerExistsIsShown(): void {
		$gone = self::group($this->service()->report('anna'), 'conductor', 'gone');
		self::assertFalse($gone['exists']);
		self::assertSame(0, $gone['members']);
		self::assertSame([], $gone['cannotOpen']);
	}

	public function testEveryAccountIsCheckedOnce(): void {
		$report = $this->service()->report('anna');
		// anna (asking, also in two groups), bert, carla, dora, emil and the admin root
		self::assertSame(6, $report['checked']);
		sort($this->looked);
		self::assertSame(['anna', 'bert', 'carla', 'dora', 'emil', 'root'], $this->looked);
	}

	public function testThePersonWhoAsksIsCheckedToo(): void {
		self::assertSame(['uid' => 'anna', 'displayName' => 'Anna Example', 'canOpen' => true], $this->service()->report('anna')['self']);
		self::assertSame(['uid' => 'root', 'displayName' => 'Root Example', 'canOpen' => false], $this->service()->report('root')['self']);
	}

	public function testListsTheAdminsWithoutARole(): void {
		// anna is an admin with roles; root has none and cannot open the folder
		self::assertSame(['members' => 1, 'checked' => 1, 'canOpen' => 0,
			'cannotOpen' => [['uid' => 'root', 'displayName' => 'Root Example']], 'notChecked' => 0],
			$this->service()->report('anna')['adminsWithoutRole']);
	}

	public function testAccountsThatNeverLoggedInAreNotChecked(): void {
		// Checking them would set up their file storage first.
		$this->neverLoggedIn = ['bert'];
		$archive = self::group($this->service()->report('anna'), 'librarian', 'archive');
		self::assertSame(1, $archive['checked']);
		self::assertSame(1, $archive['notChecked']);
		self::assertSame([], $archive['cannotOpen']);
		self::assertNotContains('bert', $this->looked);
	}

	public function testStopsAtTheLimit(): void {
		$report = $this->service()->report('anna', 3);
		self::assertSame(3, $report['checked']);
		self::assertSame(3, $report['limit']);
		self::assertFalse($report['complete']);
		self::assertCount(3, $this->looked);
		$board = self::group($report, 'reader', 'board');
		self::assertGreaterThan(0, $board['notChecked']);
	}

	public function testAGroupLargerThanTheLimitIsNotComplete(): void {
		$this->groups['board'] = array_map(fn (int $i): string => 'singer' . $i, range(1, 8));
		$report = $this->service()->report('anna', 5);
		$board = self::group($report, 'reader', 'board');
		self::assertSame(8, $board['members']);
		self::assertFalse($report['complete']);
	}

	public function testAnAccountWhoseFilesCannotBeSetUpCannotOpenIt(): void {
		$this->broken = ['emil'];
		$board = self::group($this->service()->report('anna'), 'reader', 'board');
		self::assertSame(['dora', 'emil'], array_column($board['cannotOpen'], 'uid'));
	}

	public function testOnlyAFolderCounts(): void {
		$service = $this->service();
		self::assertTrue($service->canOpen('anna'));
		self::assertFalse($service->canOpen('bert'));
		self::assertFalse($service->canOpen('odd'));
	}

	public function testChecksAChosenFolderBeforeItIsSaved(): void {
		// The chosen folder 815: only anna and dora can open it
		$this->chosen = ['anna', 'dora'];
		$report = $this->service()->report('anna', ArchiveAccess::MAX_ACCOUNTS, self::CHOSEN_ID);
		self::assertSame(self::CHOSEN_ID, $report['archive']['id']);
		self::assertSame('anna', $report['archive']['user']);
		self::assertTrue($report['self']['canOpen']);
		self::assertSame([['uid' => 'bert', 'displayName' => 'Bert Example']], self::group($report, 'librarian', 'archive')['cannotOpen']);
		self::assertSame(['emil'], array_column(self::group($report, 'reader', 'board')['cannotOpen'], 'uid'));
	}

	public function testAChosenFolderMustBeOneThePersonCanOpen(): void {
		$this->chosen = ['dora'];
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The selected archive folder is not a folder or not accessible.');
		$this->service()->report('anna', ArchiveAccess::MAX_ACCOUNTS, self::CHOSEN_ID);
	}

	public function testWithoutArchiveFolder(): void {
		$this->folderId = 0;
		$service = $this->service();
		self::assertFalse($service->canOpen('anna'));
		self::assertSame([], $this->looked);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('No archive folder has been set yet.');
		$service->report('anna');
	}
}
