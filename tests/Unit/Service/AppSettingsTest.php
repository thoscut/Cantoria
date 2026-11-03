<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The backup folder holds the folder book, the audit log and the register
 * of persons: the app writes into it only while only the archive account
 * can open it. A folder of the same name that someone shared with that
 * account, or that it shared itself, is never taken over.
 */
#[CoversClass(AppSettings::class)]
final class AppSettingsTest extends TestCase {
	/** @var array<string, int> stored app config */
	private array $config = [];
	/** @var list<string> folders created in the home of the account */
	private array $created = [];

	/**
	 * @param array<string, Folder> $existing folders in the home of the account by name
	 * @param array<int, array{users?: list<string>, public?: bool, remote?: bool}> $access access list per folder id
	 * @param array<int, array<int, list<IShare>>> $inside shares below a folder per folder id (file id => shares)
	 */
	private function settings(array $existing, array $access = [], int $storedId = 0, array $inside = []): AppSettings {
		$this->config = [AppSettings::BACKUP_FOLDER_KEY => $storedId];
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => match ($key) {
			'archive_folder_id' => 3,
			default => $this->config[$key] ?? $default,
		});
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $key === 'archive_user' ? 'lena' : 'Archive');
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$home = $this->createStub(Folder::class);
		$home->method('getId')->willReturn(1);
		$home->method('getById')->willReturn([$this->folder(3, '/lena/files/Archive', 'lena')]);
		$home->method('getFirstNodeById')->willReturnCallback(function (int $id) use ($existing): ?Folder {
			foreach ($existing as $folder) {
				if ($folder->getId() === $id) {
					return $folder;
				}
			}
			return $id === 3 ? $this->folder(3, '/lena/files/Archive', 'lena') : null;
		});
		$home->method('getRelativePath')->willReturnCallback(fn (string $path): string => substr($path, strlen('/lena/files')));
		$home->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($existing[$name]));
		$home->method('get')->willReturnCallback(fn (string $name): Folder => $existing[$name]);
		$home->method('newFolder')->willReturnCallback(function (string $name): Folder {
			$this->created[] = $name;
			return $this->folder(90, '/lena/files/' . $name, 'lena');
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l);
		$shares = $this->createStub(IShareManager::class);
		$shares->method('getAccessList')->willReturnCallback(fn (Folder $folder): array => $access[$folder->getId()] ?? ['users' => ['lena'], 'public' => false, 'remote' => false]);
		$shares->method('getSharesInFolder')->willReturnCallback(fn (string $user, Folder $folder, bool $reshares, bool $shallow): array
			=> $user === 'lena' && $reshares && !$shallow ? ($inside[$folder->getId()] ?? []) : []);
		return new AppSettings($appConfig, $root, $factory, $this->createStub(IUserManager::class), $shares);
	}

	/**
	 * @param array<string, Folder> $children sub folders by name
	 */
	private function folder(int $id, string $path, string $owner, bool $incomingShare = false, array $children = [], bool $mounted = false): Folder {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($owner);
		$folder = $this->createStub(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getOwner')->willReturn($user);
		$folder->method('isShared')->willReturn($incomingShare);
		$folder->method('isMounted')->willReturn($mounted);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($children[$name]));
		$folder->method('get')->willReturnCallback(fn (string $name): Folder => $children[$name]);
		return $folder;
	}

	public function testAPrivateFolderOfTheAccountIsTakenOver(): void {
		$own = $this->folder(20, '/lena/files/Music archive backups', 'lena');
		self::assertSame(20, $this->settings(['Music archive backups' => $own])->backupFolder()->getId());
		self::assertSame([], $this->created);
		self::assertSame(20, $this->config[AppSettings::BACKUP_FOLDER_KEY]);
	}

	public function testAFolderSharedWithTheAccountIsNeverTakenOver(): void {
		$foreign = $this->folder(20, '/lena/files/Music archive backups', 'mallory', true);
		$folder = $this->settings(['Music archive backups' => $foreign])->backupFolder();
		self::assertSame(90, $folder->getId());
		self::assertSame(['Music archive backups (2)'], $this->created);
	}

	public function testAFolderOfTheAccountThatIsSharedIsNotTakenOver(): void {
		$shared = $this->folder(20, '/lena/files/Music archive backups', 'lena');
		$byLink = $this->folder(21, '/lena/files/Music archive backups (2)', 'lena');
		$this->settings(['Music archive backups' => $shared, 'Music archive backups (2)' => $byLink],
			[20 => ['users' => ['lena', 'kim']], 21 => ['users' => ['lena'], 'public' => true]])->backupFolder();
		self::assertSame(['Music archive backups (3)'], $this->created);
	}

	public function testTheArchiveFolderIsNotTakenOverByItsName(): void {
		$this->settings(['Music archive backups' => $this->folder(3, '/lena/files/Archive/Music archive backups', 'lena')])->backupFolder();
		self::assertSame(['Music archive backups (2)'], $this->created);
	}

	public function testTheStoredFolderStopsTheBackupOnceItIsShared(): void {
		$stored = $this->folder(20, '/lena/files/Music archive backups', 'lena');
		$settings = $this->settings(['Music archive backups' => $stored], [20 => ['users' => ['lena', 'kim']]], 20);
		$this->expectException(InvalidInputException::class);
		$settings->backupFolder();
	}

	/**
	 * A share of a single backup or of the folder of the annual snapshots
	 * makes the folder no longer private: the stored folder stops the
	 * backup, a folder of the same name is not taken over (S N16).
	 */
	public function testASharedFileInsideTheFolderStopsTheBackup(): void {
		$stored = $this->folder(20, '/lena/files/Music archive backups', 'lena');
		$inside = [20 => [41 => [$this->createStub(IShare::class)]]];
		$settings = $this->settings(['Music archive backups' => $stored], [], 20, $inside);
		try {
			$settings->backupFolder();
			self::fail('a shared backup inside the folder was not noticed');
		} catch (InvalidInputException $e) {
			self::assertStringContainsString('something in it is shared', $e->getMessage());
		}

		$this->settings(['Music archive backups' => $this->folder(21, '/lena/files/Music archive backups', 'lena')], [], 0,
			[21 => [42 => [$this->createStub(IShare::class)]]])->backupFolder();
		self::assertSame(['Music archive backups (2)'], $this->created, 'not taken over by its name');

		$this->created = [];
		self::assertSame(22, $this->settings(['Music archive backups' => $this->folder(22, '/lena/files/Music archive backups', 'lena')], [], 0,
			[22 => [43 => []]])->backupFolder()->getId(), 'no share below it: private');
	}

	/**
	 * A folder another account shared, moved into the backup folder under the
	 * name of the annual snapshots, would receive them: the backup stops.
	 */
	public function testAForeignFolderOfTheAnnualSnapshotsStopsTheBackup(): void {
		$snapshots = $this->folder(30, '/lena/files/Music archive backups/annual-snapshots', 'mallory', true, [], true);
		$stored = $this->folder(20, '/lena/files/Music archive backups', 'lena', false, ['annual-snapshots' => $snapshots]);
		$this->expectException(InvalidInputException::class);
		$this->settings(['Music archive backups' => $stored], [], 20)->backupFolder();
	}

	public function testTheOwnFolderOfTheAnnualSnapshotsIsPrivate(): void {
		$snapshots = $this->folder(30, '/lena/files/Music archive backups/annual-snapshots', 'lena');
		$stored = $this->folder(20, '/lena/files/Music archive backups', 'lena', false, ['annual-snapshots' => $snapshots]);
		self::assertSame(20, $this->settings(['Music archive backups' => $stored], [], 20)->backupFolder()->getId());
	}
}
