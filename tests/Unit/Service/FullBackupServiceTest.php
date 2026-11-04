<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\PrivacyService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Format check of the full backup (FullBackupService::parse()). Export and
 * restore themselves need a database and are covered by the integration test
 * tests/Integration/backup_test.php.
 */
#[CoversClass(FullBackupService::class)]
final class FullBackupServiceTest extends TestCase {
	/**
	 * @param array<string, mixed> $overrides
	 */
	private static function backup(array $overrides = []): string {
		return json_encode($overrides + [
			'format' => FullBackupService::FORMAT,
			'formatVersion' => FullBackupService::FORMAT_VERSION,
			'app' => 'cantoria',
			'appVersion' => '0.1.11',
			'created' => '2026-10-22T12:00:00+00:00',
			'tables' => [
				'works' => [['id' => 1, 'title' => 'Ave verum corpus', 'merged_into' => null]],
				'versions' => [['id' => 2, 'work_id' => 1, 'preservation_copy' => false, 'duration' => 180]],
				'audit' => [],
			],
		], JSON_THROW_ON_ERROR);
	}

	public function testParsesAValidBackup(): void {
		$backup = FullBackupService::parse(self::backup());
		self::assertSame(FullBackupService::FORMAT_VERSION, $backup['formatVersion']);
		self::assertSame('2026-10-22T12:00:00+00:00', $backup['created']);
		self::assertSame('0.1.11', $backup['appVersion']);
		self::assertSame([
			'cantoria_works' => [['id' => 1, 'title' => 'Ave verum corpus', 'merged_into' => null]],
			'cantoria_versions' => [['id' => 2, 'work_id' => 1, 'preservation_copy' => false, 'duration' => 180]],
			'cantoria_audit' => [],
		], $backup['tables']);
	}

	public function testUnknownTablesAreDropped(): void {
		$content = self::backup(['tables' => ['oc_users' => [['uid' => 'admin']], 'works' => [], 'cantoria_versions' => []]]);
		self::assertSame(['cantoria_works' => []], FullBackupService::parse($content)['tables']);
	}

	public function testABackupOfFormatVersionOneIsStillRead(): void {
		$content = self::backup(['formatVersion' => 1, 'tables' => ['cantoria_works' => [['id' => 1, 'title' => 'Ave']], 'cantoria_mirror_files' => []]]);
		self::assertSame(['cantoria_works' => [['id' => 1, 'title' => 'Ave']], 'cantoria_mirror_files' => []],
			FullBackupService::parse($content)['tables']);
	}

	public function testEveryTableHasALogicalNameWithoutThePrefix(): void {
		self::assertSame(FullBackupService::TABLES, array_values(array_intersect(FullBackupService::TABLES, FullBackupService::TABLE_NAMES)));
		self::assertCount(count(FullBackupService::TABLES), FullBackupService::TABLE_NAMES);
		foreach (FullBackupService::TABLE_NAMES as $name => $table) {
			self::assertSame('cantoria_' . $name, $table);
			self::assertSame($name, FullBackupService::logicalName($table));
		}
		self::assertContains('cantoria_mirror_files', FullBackupService::TABLES);
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function noKnownTable(): iterable {
		yield 'no tables' => [self::backup(['tables' => new \stdClass()])];
		yield 'tables of another app' => [self::backup(['tables' => ['oc_users' => [['uid' => 'admin']]]])];
		yield 'names of version 1 in version 2' => [self::backup(['tables' => ['cantoria_works' => [['id' => 1]]]])];
	}

	#[DataProvider('noKnownTable')]
	public function testRefusesABackupWithoutAKnownTable(string $content): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file contains no table of the music archive.');
		FullBackupService::parse($content);
	}

	public function testMissingDescriptionsAreReplaced(): void {
		$data = json_decode(self::backup(), true, 512, JSON_THROW_ON_ERROR);
		unset($data['created'], $data['appVersion']);
		$backup = FullBackupService::parse(json_encode($data, JSON_THROW_ON_ERROR));
		self::assertSame(['?', '?'], [$backup['created'], $backup['appVersion']]);
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function notABackup(): iterable {
		yield 'not JSON' => ['Mappe;Nr;Signatur'];
		yield 'empty' => [''];
		yield 'JSON list' => ['[1, 2, 3]'];
		yield 'JSON scalar' => ['"cantoria-backup"'];
		yield 'other format' => [self::backup(['format' => 'something-else'])];
		yield 'version missing' => [json_encode(['format' => FullBackupService::FORMAT, 'tables' => []], JSON_THROW_ON_ERROR)];
		yield 'version as text' => [self::backup(['formatVersion' => '1'])];
		yield 'tables missing' => [json_encode(['format' => FullBackupService::FORMAT, 'formatVersion' => 1], JSON_THROW_ON_ERROR)];
		yield 'tables as list' => [self::backup(['tables' => [[]]])];
		yield 'rows as object' => [self::backup(['tables' => ['works' => ['a' => ['id' => 1]]]])];
		yield 'row as list' => [self::backup(['tables' => ['works' => [[1, 'Ave']]]])];
		yield 'row as scalar' => [self::backup(['tables' => ['works' => ['Ave']]])];
		yield 'empty row' => [self::backup(['tables' => ['works' => [new \stdClass()]]])];
		yield 'nested value' => [self::backup(['tables' => ['works' => [['id' => 1, 'title' => ['de' => 'Ave']]]]])];
	}

	#[DataProvider('notABackup')]
	public function testRefusesWhatIsNoBackup(string $content): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not a backup of the music archive.');
		FullBackupService::parse($content);
	}

	public function testRefusesABackupOfANewerFormat(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The backup was made with a newer version of the app.');
		FullBackupService::parse(self::backup(['formatVersion' => FullBackupService::FORMAT_VERSION + 1]));
	}

	public function testRefusesDeeplyNestedJson(): void {
		$deep = str_repeat('[', 100) . str_repeat(']', 100);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not a backup of the music archive.');
		FullBackupService::parse(self::backup(['tables' => ['works' => [['id' => 1, 'title' => 'x']]], 'extra' => json_decode($deep)]));
	}

	/**
	 * @return iterable<string, array{0: FullBackupService::KIND_*, 1: ?int, 2: bool, 3: string|int|float|bool|null, 4: array{0: string|int|bool|null, 1: string|int}|null}>
	 */
	public static function columnValues(): iterable {
		$int = IQueryBuilder::PARAM_INT;
		$bool = IQueryBuilder::PARAM_BOOL;
		$str = IQueryBuilder::PARAM_STR;
		yield 'integer' => [FullBackupService::KIND_INTEGER, null, true, 42, [42, $int]];
		yield 'integer as digits (MySQL export)' => [FullBackupService::KIND_INTEGER, null, true, '-7', [-7, $int]];
		yield 'integer from a boolean' => [FullBackupService::KIND_INTEGER, null, false, true, [1, $int]];
		yield 'integer: text refused' => [FullBackupService::KIND_INTEGER, null, true, '7; DROP TABLE x', null];
		yield 'integer: fraction refused' => [FullBackupService::KIND_INTEGER, null, true, 1.5, null];
		yield 'integer: too many digits refused' => [FullBackupService::KIND_INTEGER, null, true, '1234567890123456789012', null];
		yield 'boolean true' => [FullBackupService::KIND_BOOLEAN, null, false, true, [true, $bool]];
		yield 'boolean false (PostgreSQL export)' => [FullBackupService::KIND_BOOLEAN, null, false, false, [false, $bool]];
		yield 'boolean as 0 (MySQL export)' => [FullBackupService::KIND_BOOLEAN, null, false, 0, [false, $bool]];
		yield 'boolean as "t"' => [FullBackupService::KIND_BOOLEAN, null, false, 't', [true, $bool]];
		yield 'boolean: other text refused' => [FullBackupService::KIND_BOOLEAN, null, false, 'yes please', null];
		yield 'boolean: 2 refused' => [FullBackupService::KIND_BOOLEAN, null, false, 2, null];
		yield 'text' => [FullBackupService::KIND_TEXT, 20, false, 'Ave', ['Ave', $str]];
		yield 'text from a number' => [FullBackupService::KIND_TEXT, 20, false, 12, ['12', $str]];
		yield 'text at its length (characters)' => [FullBackupService::KIND_TEXT, 3, false, 'Äöü', ['Äöü', $str]];
		yield 'text: too long refused' => [FullBackupService::KIND_TEXT, 3, false, 'Ave!', null];
		yield 'text without limit' => [FullBackupService::KIND_TEXT, null, false, str_repeat('x', 100000), [str_repeat('x', 100000), $str]];
		yield 'text: boolean refused' => [FullBackupService::KIND_TEXT, null, false, true, null];
		yield 'null where allowed' => [FullBackupService::KIND_TEXT, 20, false, null, [null, $str]];
		yield 'null: not null column refused' => [FullBackupService::KIND_INTEGER, null, true, null, null];
	}

	/**
	 * @param FullBackupService::KIND_* $kind
	 * @param array{0: string|int|bool|null, 1: string|int}|null $expected
	 */
	#[DataProvider('columnValues')]
	public function testColumnValue(string $kind, ?int $length, bool $notNull, string|int|float|bool|null $value, ?array $expected): void {
		self::assertSame($expected, FullBackupService::columnValue($kind, $length, $notNull, $value));
	}

	public function testOnlyFixedLengthColumnsHaveALengthLimit(): void {
		self::assertSame(500, FullBackupService::columnLength(new Column('title', Type::getType(Types::STRING), ['length' => 500])));
		// MySQL and MariaDB report long text columns with the length 0.
		self::assertNull(FullBackupService::columnLength(new Column('notes', Type::getType(Types::TEXT), ['length' => 0])));
		self::assertNull(FullBackupService::columnLength(new Column('notes', Type::getType(Types::TEXT), ['length' => 65535])));
		self::assertNull(FullBackupService::columnLength(new Column('id', Type::getType(Types::BIGINT))));
	}

	public function testTablesAreInRestoreOrder(): void {
		$tables = FullBackupService::TABLES;
		$position = array_flip($tables);
		// Referenced tables come first.
		self::assertLessThan($position['cantoria_versions'], $position['cantoria_works']);
		self::assertLessThan($position['cantoria_copies'], $position['cantoria_versions']);
		self::assertLessThan($position['cantoria_files'], $position['cantoria_copies']);
		self::assertLessThan($position['cantoria_event_items'], $position['cantoria_events']);
		self::assertLessThan($position['cantoria_contributions'], $position['cantoria_persons']);
		self::assertLessThan($position['cantoria_folder_loans'], $position['cantoria_folders']);
		// Revisions point to files; programme items to the revision sung (milestone 0.8)
		self::assertLessThan($position['cantoria_states'], $position['cantoria_files']);
		self::assertLessThan($position['cantoria_event_items'], $position['cantoria_states']);
		// The manifest of the print station and the hand-over points to copies and files (A3).
		self::assertLessThan($position['cantoria_mirror_files'], $position['cantoria_files']);
		// A link points to its copy and to the programme it is shared with (A27)
		self::assertLessThan($position['cantoria_links'], $position['cantoria_copies']);
		self::assertLessThan($position['cantoria_links'], $position['cantoria_events']);
		self::assertSame(array_values(array_unique($tables)), $tables);
	}

	/**
	 * One list of the columns with accounts (A27): an annual snapshot
	 * empties exactly those of PrivacyService::ACCOUNT_FIELDS for its tables.
	 */
	public function testTheSnapshotEmptiesTheAccountColumnsOfThePrivacyList(): void {
		$expected = [];
		foreach (FullBackupService::SNAPSHOT_TABLES as $table) {
			foreach (PrivacyService::ACCOUNT_FIELDS[$table] ?? [] as $column) {
				$expected[$column] = true;
			}
		}
		$columns = array_values(array_unique(FullBackupService::ACCOUNT_COLUMNS));
		sort($columns);
		$expected = array_keys($expected);
		sort($expected);
		self::assertSame($expected, $columns);
	}
}
