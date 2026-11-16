<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\BackupReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A complete backup read from a stream, row by row (milestone 0.20): the
 * same rows as PHP's own JSON reader gives, also where a text or an escape
 * lies across two blocks of the stream, in memory that does not grow with
 * the file, and nothing that is not a backup.
 */
#[CoversClass(BackupReader::class)]
final class BackupReaderTest extends TestCase {
	/**
	 * @return resource
	 */
	private static function stream(string $content) {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	public function testReadsTheHeadAndEveryRowInItsOrder(): void {
		$content = '{"format":"cantoria-backup","formatVersion":2,"later":{"a":[1,{"b":null}]},' . "\n"
			. '"tables": {' . "\n" . '"works": [' . "\n" . '{"id":1,"title":"Ave ä \"verum\"\\\\","merged_into":null,"ok":true,"length":1.5}'
			. ",\n" . '{"id":2,"title":""}],' . "\n" . '"audit": []' . "\n" . '}, "created": "2026-11-16"}' . "\n";
		$rows = [];
		$read = BackupReader::read(self::stream($content), function (string $table, array $row, int $index) use (&$rows): void {
			$rows[] = [$table, $index, $row];
		});
		self::assertSame(['format' => 'cantoria-backup', 'formatVersion' => 2, 'created' => '2026-11-16'], $read['head'], 'nested values beside the tables are skipped');
		self::assertSame(['works' => 2, 'audit' => 0], $read['tables']);
		self::assertSame([
			['works', 0, ['id' => 1, 'title' => 'Ave ä "verum"\\', 'merged_into' => null, 'ok' => true, 'length' => 1.5]],
			['works', 1, ['id' => 2, 'title' => '']],
		], $rows);
	}

	/**
	 * 4000 rows with quotes, backslashes, line breaks and letters of several
	 * bytes: about 600 kB, so every kind of text lies across the 64 kB
	 * blocks of the reader somewhere.
	 */
	public function testGivesTheSameRowsAsTheJsonReaderAcrossTheBlocks(): void {
		$works = [];
		for ($i = 0; $i < 4000; $i++) {
			$works[] = ['id' => $i, 'title' => str_repeat('ä"\\' . "\n\t€𝄞/", $i % 7 + 1) . $i, 'notes' => $i % 3 === 0 ? null : str_repeat('x', $i % 97),
				'duration' => $i * 1.25, 'counts' => $i % 2 === 0, 'negative' => -$i];
		}
		foreach ([JSON_THROW_ON_ERROR, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT] as $flags) {
			$content = json_encode(['format' => 'cantoria-backup', 'tables' => ['works' => $works, 'versions' => []]], $flags);
			self::assertGreaterThan(300_000, strlen($content));
			/** @var array{tables: array{works: list<array<string, mixed>>}} $decoded */
			$decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
			$expected = $decoded['tables']['works'];
			self::assertSame('ä"\\' . "\n\t€𝄞/0", $expected[0]['title']);
			$rows = [];
			$read = BackupReader::read(self::stream($content), function (string $table, array $row) use (&$rows): void {
				$rows[] = $row;
			});
			self::assertSame(['works' => 4000, 'versions' => 0], $read['tables']);
			self::assertCount(4000, $rows);
			foreach ($expected as $index => $row) {
				// row by row: a difference names its row instead of printing the whole file
				self::assertSame($row, $rows[$index], 'row ' . $index);
			}
		}
	}

	/**
	 * Load test: a backup of 120 000 rows (about 20 MB, far more than an
	 * archive of 10 000 copies with its audit log) is read in memory that
	 * does not grow with the file.
	 */
	public function testALargeBackupIsReadInLittleMemory(): void {
		$stream = fopen('php://temp/maxmemory:1048576', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, '{"format":"cantoria-backup","formatVersion":2,' . "\n" . '"tables": {' . "\n" . '"audit": [');
		$line = json_encode(['id' => 0, 'object_type' => 'copy', 'object_id' => 4711, 'action' => 'update', 'field' => 'location',
			'old_value' => str_repeat('Schrank 3, Fach 2 ', 4), 'new_value' => str_repeat('Schrank 4, Fach 1 ', 4), 'user_id' => 'lena'], JSON_THROW_ON_ERROR);
		for ($i = 0; $i < 120_000; $i++) {
			fwrite($stream, ($i === 0 ? '' : ',') . "\n" . $line);
		}
		fwrite($stream, "]\n}}\n");
		self::assertGreaterThan(20 * 1024 * 1024, ftell($stream));
		rewind($stream);

		$count = 0;
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$read = BackupReader::read($stream, function (string $table, array $row) use (&$count): void {
			$count += $row['object_id'] === 4711 ? 1 : 0;
		});
		$grown = memory_get_peak_usage() - $before;
		fclose($stream);
		self::assertSame(120_000, $count);
		self::assertSame(['audit' => 120_000], $read['tables']);
		self::assertLessThan(2 * 1024 * 1024, $grown, 'the reader keeps one block and one row, not the file');
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function notABackup(): iterable {
		yield 'empty' => [''];
		yield 'a list' => ['[]'];
		yield 'no tables' => ['{"format":"cantoria-backup"}'];
		yield 'tables twice' => ['{"tables":{},"tables":{}}'];
		yield 'a table twice' => ['{"tables":{"works":[],"works":[]}}'];
		yield 'text after the end' => ['{"tables":{}} {}'];
		yield 'cut off in a row' => ['{"tables":{"works":[{"id":1,"title":"Ave'];
		yield 'cut off after an escape' => ['{"tables":{"works":[{"id":1,"title":"Ave\\'];
		yield 'cut off after the rows' => ['{"tables":{"works":[{"id":1}]'];
		yield 'a comma too many' => ['{"tables":{"works":[{"id":1},]}}'];
		yield 'a row without a comma' => ['{"tables":{"works":[{"id":1}{"id":2}]}}'];
		yield 'an empty row' => ['{"tables":{"works":[{}]}}'];
		yield 'a nested value' => ['{"tables":{"works":[{"id":[1]}]}}'];
		yield 'a name without quotes' => ['{"tables":{"works":[{id:1}]}}'];
		yield 'a word that is no value' => ['{"tables":{"works":[{"id":nil}]}}'];
		yield 'a number that is none' => ['{"tables":{"works":[{"id":1.2.3}]}}'];
		yield 'a line break in a text' => ["{\"tables\":{\"works\":[{\"title\":\"Ave\nverum\"}]}}"];
		yield 'an escape that is none' => ['{"tables":{"works":[{"title":"Ave\\q"}]}}'];
		yield 'text that is not UTF-8' => ["{\"tables\":{\"works\":[{\"title\":\"\xff\"}]}}"];
		yield 'nested too deep beside the tables' => ['{"extra":' . str_repeat('[', 20) . str_repeat(']', 20) . ',"tables":{}}'];
	}

	#[DataProvider('notABackup')]
	public function testRefusesWhatIsNoBackup(string $content): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not a backup of the music archive.');
		BackupReader::read(self::stream($content));
	}
}
