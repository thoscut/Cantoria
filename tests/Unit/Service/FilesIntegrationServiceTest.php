<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Opening archive files from the Files app: the initial state of the script
 * (archive folder as the account sees it) and the copy of a Nextcloud file,
 * answered only for files the account can see.
 */
#[CoversClass(FilesIntegrationService::class)]
final class FilesIntegrationServiceTest extends TestCase {
	use ModuleSwitches;

	private const ARCHIVE_FOLDER = 900;

	/** @var array<int, Node> Nextcloud file id => node the account sees */
	private array $visible = [];
	/** @var array<int, ArchiveFile> Nextcloud file id => archive file */
	private array $archiveFiles = [];
	/** @var array<int, Copy> */
	private array $copyRows = [];
	/** @var list<string> modules switched off */
	private array $off = [];
	private int $archiveFolderId = self::ARCHIVE_FOLDER;
	/** @var list<int> Nextcloud file ids looked up in the archive */
	private array $lookedUp = [];

	#[\Override]
	protected function setUp(): void {
		$copy = new Copy();
		$copy->setId(35);
		$copy->setShelfmark('2-1');
		$this->copyRows = [35 => $copy];
		$this->archiveFiles = [
			4711 => $this->archiveFile(4711, 35, '2-01_Demo.mxl', 'mxl', 'musicxml'),
			4712 => $this->archiveFile(4712, 35, '2-01_Demo.pdf', 'pdf', 'score_pdf'),
			4713 => $this->archiveFile(4713, null, '9-99_Unassigned.pdf', 'pdf', 'score_pdf'),
			4714 => $this->archiveFile(4714, 35, '2-01_Demo.xml', 'xml', 'musicxml'),
		];
		foreach ([4711, 4712, 4713, 4714, 5000] as $fileId) {
			$this->visible[$fileId] = $this->createStub(File::class);
		}
	}

	private function archiveFile(int $fileId, ?int $copyId, string $name, string $type, string $role): ArchiveFile {
		$file = new ArchiveFile();
		$file->setId($fileId - 4000);
		$file->setFileId($fileId);
		$file->setCopyId($copyId);
		$file->setName($name);
		$file->setType($type);
		$file->setRole($role);
		return $file;
	}

	private function service(): FilesIntegrationService {
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturnCallback(fn (): array => ['id' => $this->archiveFolderId, 'path' => '/Notenarchiv', 'user' => 'admin']);
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByFileId')->willReturnCallback(function (int $fileId): ArchiveFile {
			$this->lookedUp[] = $fileId;
			return $this->archiveFiles[$fileId] ?? throw new DoesNotExistException('not in the archive');
		});
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(fn (int $id): Copy => $this->copyRows[$id] ?? throw new DoesNotExistException('no copy'));
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?Node => $this->visible[$id] ?? null);
		$userFolder->method('getRelativePath')->willReturnCallback(fn (string $path): ?string
			=> str_starts_with($path, '/carla/files/') ? substr($path, strlen('/carla/files')) : null);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		return new FilesIntegrationService($settings, $files, $copies, $this->modulesWithout($this->off), $root);
	}

	public function testAListedMusicXmlFileOpensTheScoreView(): void {
		self::assertSame(['copyId' => 35, 'shelfmark' => '2-1', 'role' => 'musicxml', 'score' => true],
			$this->service()->copyOf(4711, 'carla'));
		self::assertTrue($this->service()->copyOf(4714, 'carla')['score'], 'MusicXML named .xml');
	}

	public function testOtherFilesOfACopyOpenTheCopy(): void {
		self::assertSame(['copyId' => 35, 'shelfmark' => '2-1', 'role' => 'score_pdf', 'score' => false],
			$this->service()->copyOf(4712, 'carla'));
	}

	public function testWithoutTheScoreViewMusicXmlOpensTheCopy(): void {
		$this->off = ['score_viewer'];
		self::assertFalse($this->service()->copyOf(4711, 'carla')['score']);
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function unlistedFiles(): array {
		return [
			'not in the archive' => [5000],
			'in the archive folder, but no copy' => [4713],
			'unknown file id' => [6000],
		];
	}

	#[DataProvider('unlistedFiles')]
	public function testFilesWithoutACopyAreNotListed(int $fileId): void {
		$this->expectExceptionObject(new NotFoundException('This file is not listed in the music archive.'));
		$this->service()->copyOf($fileId, 'carla');
	}

	public function testAFileTheAccountCannotSeeIsNotListedEvenIfItIsInTheArchive(): void {
		unset($this->visible[4711]);
		$service = $this->service();
		try {
			$service->copyOf(4711, 'carla');
			self::fail('a file the account cannot see was answered');
		} catch (NotFoundException $e) {
			self::assertSame('This file is not listed in the music archive.', $e->getMessage(), 'the same answer as for an unlisted file');
		}
		self::assertSame([], $this->lookedUp, 'the archive is not even asked');
	}

	public function testAFolderIsNoFile(): void {
		$this->visible[4711] = $this->createStub(Folder::class);
		$this->expectException(NotFoundException::class);
		$this->service()->copyOf(4711, 'carla');
	}

	public function testTheScriptLearnsTheArchiveFolderAsTheAccountSeesIt(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn('/carla/files/Shared/Notenarchiv');
		$this->visible[self::ARCHIVE_FOLDER] = $folder;
		self::assertSame([
			'archivePath' => '/Shared/Notenarchiv',
			'extensions' => array_keys(FileSyncService::ROLE_BY_TYPE),
			'scoreExtensions' => ['musicxml', 'mxl'],
			'scoreView' => true,
		], $this->service()->clientState('carla'));

		$this->off = ['score_viewer'];
		self::assertFalse($this->service()->clientState('carla')['scoreView'] ?? true);
	}

	public function testNoStateWithoutAnArchiveFolderTheAccountCanSee(): void {
		self::assertNull($this->service()->clientState('carla'), 'the account cannot see the folder');

		$this->visible[self::ARCHIVE_FOLDER] = $this->createStub(File::class);
		self::assertNull($this->service()->clientState('carla'), 'a file is no folder');

		$this->archiveFolderId = 0;
		self::assertNull($this->service()->clientState('carla'), 'no archive folder set');
	}

	public function testTheExtensionsAreThoseOfTheFileSync(): void {
		$extensions = array_keys(FileSyncService::ROLE_BY_TYPE);
		foreach (['pdf', 'mxl', 'musicxml', 'mscz', 'mp3'] as $extension) {
			self::assertContains($extension, $extensions);
		}
		foreach (FilesIntegrationService::SCORE_EXTENSIONS as $extension) {
			self::assertSame('musicxml', FileSyncService::ROLE_BY_TYPE[$extension]);
		}
	}
}
