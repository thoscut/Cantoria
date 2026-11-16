<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Command;

use OCA\Cantoria\Command\Restore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `occ cantoria:restore` reads the backup as a stream that can be rewound
 * (milestone 0.20): a file as it is, a pipe through a temporary copy, so
 * that `gunzip -c backup.json.gz | occ cantoria:restore /dev/stdin` works.
 */
#[CoversClass(Restore::class)]
final class RestoreCommandTest extends TestCase {
	private string $directory = '';

	#[\Override]
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/cantoria-restore-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	public function testOpensAFileAsItIs(): void {
		$path = $this->directory . '/backup.json';
		file_put_contents($path, '{"tables":{}}');
		$stream = Restore::open($path);
		self::assertIsResource($stream);
		self::assertSame('{"tables":{}}', stream_get_contents($stream));
		self::assertTrue(rewind($stream));
		fclose($stream);
	}

	public function testRefusesWhatCannotBeRead(): void {
		self::assertNull(Restore::open($this->directory . '/missing.json'));
		self::assertNull(Restore::open($this->directory), 'a folder');
	}

	public function testCopiesAPipeSoThatItCanBeReadTwice(): void {
		if (!function_exists('posix_mkfifo') || !function_exists('proc_open')) {
			self::markTestSkipped('needs a named pipe');
		}
		$pipe = $this->directory . '/pipe';
		self::assertTrue(posix_mkfifo($pipe, 0600));
		// larger than the 2 MB the copy keeps in memory
		$content = '{"tables":{"works":[' . rtrim(str_repeat('{"id":1,"title":"Ave verum corpus"},', 80_000), ',') . ']}}';
		$source = $this->directory . '/source.json';
		file_put_contents($source, $content);
		$writer = proc_open(['sh', '-c', 'cat "$1" > "$2"', 'sh', $source, $pipe], [], $pipes);
		self::assertIsResource($writer);
		try {
			$stream = Restore::open($pipe);
		} finally {
			proc_close($writer);
		}
		self::assertIsResource($stream);
		self::assertSame(hash('sha256', $content), hash('sha256', (string)stream_get_contents($stream)));
		self::assertTrue(rewind($stream), 'the restore reads it twice');
		self::assertSame('{"tables"', fread($stream, 9));
		fclose($stream);
	}
}
