<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\MirrorFile;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\PrintMirrorService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\RunLock;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\StateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The hand-over folder and the mirror folder of the print station remove a
 * file that the rights no longer allow, also while its version stays on the
 * list (A22): a license for download, for digital score files or for
 * recordings that ended. A file skipped for a technical reason (not
 * readable at the moment, an invalid name) stays, and a file someone
 * changed is never removed.
 */
#[CoversClass(PrintMirrorService::class)]
#[CoversClass(HandoverService::class)]
final class MirrorRightsTest extends TestCase {
	private const FOLDER_ID = 99;

	/** @var array<string, string> the files in the target folder: name => content */
	private array $folder = [];
	/** @var list<string> names of the files the app deleted */
	private array $deleted = [];
	/** @var list<string> names forgotten in the manifest */
	private array $forgotten = [];

	/**
	 * A hand-over whose list of files is given (what wanted() found), with
	 * the manifest of the files it wrote before.
	 *
	 * @param list<array{name: string, skip: ?string}> $wanted
	 * @param array<string, string> $written name => content the app wrote
	 */
	private function handover(array $wanted, array $written): HandoverService {
		$this->folder = $written;
		$manifest = [];
		$id = 500;
		foreach ($written as $name => $content) {
			$row = new MirrorFile();
			$row->setMirror(HandoverService::HANDOVER_MIRROR);
			$row->setName($name);
			$row->setFileId(++$id);
			$row->setSha256(hash('sha256', $content));
			$row->setCopyId(35);
			$manifest[$name] = $row;
		}
		$ids = array_combine(array_keys($manifest), array_map(fn (MirrorFile $row): int => $row->getFileId(), $manifest));

		$target = $this->createStub(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->folder[$name]));
		$target->method('get')->willReturnCallback(fn (string $name): File => $this->file($name, $ids[$name] ?? 1));
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($target);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key): int => $key === 'choir_handover_folder_id' ? self::FOLDER_ID : 0);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $key === 'choir_handover_user' ? 'archive' : '');
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 0, 'path' => '', 'user' => '']);
		$mirrorFiles = $this->createStub(MirrorFileMapper::class);
		$mirrorFiles->method('findAllByName')->willReturn($manifest);
		$mirrorFiles->method('delete')->willReturnCallback(function (MirrorFile $row): MirrorFile {
			$this->forgotten[] = $row->getName();
			return $row;
		});
		$runLock = $this->createStub(RunLock::class);
		$runLock->method('run')->willReturnCallback(fn (string $name, ?string $uid, callable $run): mixed => $run());

		$entries = [];
		foreach ($wanted as $index => $entry) {
			$source = new ArchiveFile();
			$source->setId(10 + $index);
			$entries[] = ['name' => $entry['name'], 'shelfmark' => '1-03', 'copyId' => 35, 'archiveFile' => $source, 'node' => null, 'skip' => $entry['skip']];
		}
		return new class($entries, $config, $root, $settings, $this->createStub(ReleaseListService::class), $this->createStub(ArchiveFileMapper::class), $this->createStub(CopyMapper::class), $mirrorFiles, $this->createStub(AuditLog::class), $this->createStub(Roles::class), $this->createStub(Modules::class), $this->createStub(NotificationService::class), $this->createStub(IFilenameValidator::class), $this->createStub(IUserManager::class), $this->createStub(IFactory::class), $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $this->createStub(LoggerInterface::class), $runLock, $this->createStub(WorkVersionMapper::class), $this->createStub(RightsPolicy::class), $this->createStub(RightsService::class), $this->createStub(LicenseMapper::class), $this->createStub(ExcerptLinkMapper::class), $this->createStub(StateService::class), $this->createStub(ScoreService::class)) extends HandoverService {
			/**
			 * @param list<array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string}> $entries
			 */
			public function __construct(
				private array $entries,
				mixed ...$dependencies,
			) {
				parent::__construct(...$dependencies);
			}

			#[\Override]
			protected function wanted(string $account): array {
				return $this->entries;
			}

			#[\Override]
			protected function textFiles(string $account, int $files): array {
				return [];
			}
		};
	}

	private function file(string $name, int $id): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('fopen')->willReturnCallback(function () use ($name) {
			$handle = fopen('php://memory', 'r+');
			self::assertIsResource($handle);
			fwrite($handle, $this->folder[$name] ?? '');
			rewind($handle);
			return $handle;
		});
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			$this->deleted[] = $name;
			unset($this->folder[$name]);
		});
		return $file;
	}

	public function testAFileTheRightsNoLongerAllowIsRemovedThoughItsVersionStaysOnTheList(): void {
		$wanted = [
			['name' => '1-03 Abendlied.pdf', 'skip' => HandoverService::NO_DOWNLOAD],
			['name' => '1-03 Abendlied.musicxml', 'skip' => HandoverService::NO_DIGITAL_SCORES],
			['name' => '1-03 Abendlied - Alt.mp3', 'skip' => HandoverService::NO_PRACTICE_AUDIO],
			['name' => '1-04 Ave verum.pdf', 'skip' => HandoverService::NOT_ACCESSIBLE],
			['name' => '1-05 Ubi caritas.pdf', 'skip' => HandoverService::INVALID_NAME],
		];
		$written = array_fill_keys(array_column($wanted, 'name'), '%PDF-1.7 as written');
		$report = $this->handover($wanted, $written)->sync(null, false);

		self::assertSame(['1-03 Abendlied.pdf', '1-03 Abendlied.musicxml', '1-03 Abendlied - Alt.mp3'], $this->deleted,
			'a license that ended removes the file, as when the version leaves the list');
		self::assertSame(['1-03 Abendlied.pdf', '1-03 Abendlied.musicxml', '1-03 Abendlied - Alt.mp3'], array_column($report['removed'], 'name'));
		self::assertSame($this->deleted, $this->forgotten, 'and out of the manifest');
		self::assertSame(['1-04 Ave verum.pdf', '1-05 Ubi caritas.pdf'], array_keys($this->folder), 'a technical reason leaves the file as it is');
		self::assertCount(5, $report['skipped'], 'every file not written says why');
	}

	public function testThePreviewSaysWhatWouldBeRemovedAndRemovesNothing(): void {
		$report = $this->handover([['name' => '1-03 Abendlied.musicxml', 'skip' => HandoverService::NO_DIGITAL_SCORES]],
			['1-03 Abendlied.musicxml' => '<score-partwise/>'])->sync(null, true);
		self::assertSame(['1-03 Abendlied.musicxml'], array_column($report['removed'], 'name'));
		self::assertSame([[], []], [$this->deleted, $this->forgotten]);
	}

	public function testAFileSomeoneChangedIsNeverRemoved(): void {
		$service = $this->handover([['name' => '1-03 Abendlied.pdf', 'skip' => HandoverService::NO_DOWNLOAD]], ['1-03 Abendlied.pdf' => 'as written']);
		$this->folder['1-03 Abendlied.pdf'] = 'changed by the choir office';
		$report = $service->sync(null, false);
		self::assertSame([], $this->deleted);
		self::assertSame([HandoverService::NO_DOWNLOAD, PrintMirrorService::CHANGED], array_column($report['skipped'], 'reason'));
		self::assertSame(['1-03 Abendlied.pdf'], $this->forgotten, 'no longer the app\'s file');
	}

	public function testAPreservationCopyIsRemovedByTheRuleOfThePrintStation(): void {
		$report = $this->handover([['name' => '1-03 Abendlied.pdf', 'skip' => PrintMirrorService::PRESERVATION]], ['1-03 Abendlied.pdf' => '%PDF'])
			->sync(null, false);
		self::assertSame(['1-03 Abendlied.pdf'], array_column($report['removed'], 'name'), 'PrintMirrorService::isRightsReason, which the hand-over extends');
	}
}
