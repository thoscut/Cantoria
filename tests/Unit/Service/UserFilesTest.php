<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\UserFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Files chosen for an import or a restore: read through the user's own view
 * and never more than the limit.
 */
#[CoversClass(UserFiles::class)]
final class UserFilesTest extends TestCase {
	private ?Node $node = null;
	private ?int $requestedId = null;

	private function userFiles(): UserFiles {
		$folder = $this->createStub(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(function (int $id): ?Node {
			$this->requestedId = $id;
			return $this->node;
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		return new UserFiles($root);
	}

	private function file(string $content, ?int $size = null, bool $readable = true): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn('catalog.csv');
		$file->method('getSize')->willReturn($size ?? strlen($content));
		$file->method('fopen')->willReturnCallback(function () use ($content, $readable) {
			if (!$readable) {
				throw new NotPermittedException('no read permission');
			}
			$handle = fopen('php://memory', 'r+');
			self::assertIsResource($handle);
			fwrite($handle, $content);
			rewind($handle);
			return $handle;
		});
		return $file;
	}

	public function testReadsContentAndName(): void {
		$this->node = $this->file("Signatur\r\n5-1\r\n");
		self::assertSame(['content' => "Signatur\r\n5-1\r\n", 'name' => 'catalog.csv'],
			$this->userFiles()->read('librarian', 4711, 100, new InvalidInputException('The selected CSV file is not accessible.')));
		self::assertSame(4711, $this->requestedId);
	}

	public function testAcceptsAFileOfExactlyTheLimit(): void {
		$this->node = $this->file(str_repeat('x', 100));
		self::assertSame(100, strlen($this->userFiles()->read('librarian', 1, 100, new InvalidInputException('x'))['content']));
	}

	public function testRefusesAFileTheUserCannotSee(): void {
		$this->node = null;
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The selected CSV file is not accessible.');
		$this->userFiles()->read('librarian', 4711, 100, new InvalidInputException('The selected CSV file is not accessible.'));
	}

	public function testRefusesAFolder(): void {
		$this->node = $this->createStub(Folder::class);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The selected file is not accessible.');
		$this->userFiles()->read('librarian', 4711, 100, new InvalidInputException('The selected file is not accessible.'));
	}

	public function testRefusesAnUnreadableFile(): void {
		$this->node = $this->file('x', null, false);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The selected file is not accessible.');
		$this->userFiles()->read('librarian', 4711, 100, new InvalidInputException('The selected file is not accessible.'));
	}

	public function testRefusesALargeFileBeforeReadingIt(): void {
		$this->node = $this->file('', 2 * 1024 * 1024 + 1, false);
		try {
			$this->userFiles()->read('librarian', 4711, 2 * 1024 * 1024, new InvalidInputException('x'));
			self::fail('no exception');
		} catch (InvalidInputException $e) {
			self::assertSame('The file is too large (at most %s MB).', $e->getMessage());
			self::assertSame([2], $e->getParameters());
		}
	}

	public function testRefusesContentAboveTheLimitWhenTheRecordedSizeIsWrong(): void {
		$this->node = $this->file(str_repeat('x', 101), 50);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is too large (at most %s MB).');
		$this->userFiles()->read('librarian', 4711, 100, new InvalidInputException('x'));
	}

	/**
	 * A complete backup is read as a stream (milestone 0.20): a copy that
	 * can be rewound, with the same limits and refusals as read().
	 */
	public function testGivesALargeFileAsAStreamThatCanBeRewound(): void {
		// more than the 2 MB the copy keeps in memory: the rest lies on disk
		$content = str_repeat("0123456789abcdef\n", 200_000);
		$this->node = $this->file($content);
		$stream = $this->userFiles()->stream('librarian', 4711, strlen($content), new InvalidInputException('x'));
		self::assertSame(4711, $this->requestedId);
		self::assertSame(0, ftell($stream), 'at its start');
		self::assertSame(hash('sha256', $content), hash('sha256', (string)stream_get_contents($stream)));
		self::assertTrue(rewind($stream), 'the restore reads it twice');
		self::assertSame(substr($content, 0, 34), fread($stream, 34));
		fclose($stream);
	}

	public function testRefusesAStreamOfAFileThatIsMissingAFolderOrUnreadable(): void {
		$unreadable = $this->file('x', null, false);
		foreach ([null, $this->createStub(Folder::class), $unreadable] as $node) {
			$this->node = $node;
			try {
				$this->userFiles()->stream('librarian', 4711, 100, new InvalidInputException('The selected file is not accessible.'));
				self::fail('no exception');
			} catch (InvalidInputException $e) {
				self::assertSame('The selected file is not accessible.', $e->getMessage());
			}
		}
	}

	public function testRefusesAStreamAboveTheLimit(): void {
		$cases = [
			'by its recorded size, before it is opened' => $this->file('', 101, false),
			'by its content, when the recorded size lags behind' => $this->file(str_repeat('x', 101), 50),
		];
		foreach ($cases as $case => $file) {
			$this->node = $file;
			try {
				$this->userFiles()->stream('librarian', 4711, 100, new InvalidInputException('x'));
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame('The file is too large (at most %s MB).', $e->getMessage(), $case);
			}
		}
		$this->node = $this->file(str_repeat('x', 100));
		$stream = $this->userFiles()->stream('librarian', 4711, 100, new InvalidInputException('x'));
		self::assertSame(100, strlen((string)stream_get_contents($stream)), 'exactly the limit is accepted');
		fclose($stream);
	}
}
