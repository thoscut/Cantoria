<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\Fields;
use OCA\Cantoria\Service\FieldValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValue::class)]
final class FieldValueTest extends TestCase {
	/**
	 * @return array{type: string, label: string, write: list<string>, options?: array<string, string>, max?: int}
	 */
	private static function info(string $type, array $extra = []): array {
		return ['type' => $type, 'label' => 'Label', 'write' => []] + $extra;
	}

	/**
	 * @return iterable<string, array{0: string, 1: mixed, 2: ?string}>
	 */
	public static function cases(): iterable {
		foreach ([Fields::TYPE_TEXT, Fields::TYPE_LONGTEXT, Fields::TYPE_NUMBER, Fields::TYPE_DATE, Fields::TYPE_CHOICE, Fields::TYPE_BOOLEAN] as $type) {
			yield 'null is always valid: ' . $type => [$type, null, null];
		}

		yield 'number: int' => [Fields::TYPE_NUMBER, 42, null];
		yield 'number: zero' => [Fields::TYPE_NUMBER, 0, null];
		yield 'number: negative' => [Fields::TYPE_NUMBER, -3, '"%s" must be a whole number from 0 to 2147483647.'];
		yield 'number: largest 32-bit' => [Fields::TYPE_NUMBER, 2147483647, null];
		yield 'number: beyond 32 bits' => [Fields::TYPE_NUMBER, 2147483648, '"%s" must be a whole number from 0 to 2147483647.'];
		yield 'number: numeric string' => [Fields::TYPE_NUMBER, '42', '"%s" must be a whole number.'];
		yield 'number: float' => [Fields::TYPE_NUMBER, 4.0, '"%s" must be a whole number.'];
		yield 'number: bool' => [Fields::TYPE_NUMBER, true, '"%s" must be a whole number.'];

		yield 'boolean: true' => [Fields::TYPE_BOOLEAN, true, null];
		yield 'boolean: false' => [Fields::TYPE_BOOLEAN, false, null];
		yield 'boolean: int' => [Fields::TYPE_BOOLEAN, 1, '"%s" must be yes or no.'];
		yield 'boolean: string' => [Fields::TYPE_BOOLEAN, 'yes', '"%s" must be yes or no.'];

		yield 'choice: known key' => [Fields::TYPE_CHOICE, 'open', null];
		yield 'choice: label instead of key' => [Fields::TYPE_CHOICE, 'Open', '"%s": value not allowed.'];
		yield 'choice: unknown key' => [Fields::TYPE_CHOICE, 'maybe', '"%s": value not allowed.'];
		yield 'choice: non-string' => [Fields::TYPE_CHOICE, 1, '"%s": value not allowed.'];

		yield 'date: valid' => [Fields::TYPE_DATE, '2026-10-22', null];
		yield 'date: leap day' => [Fields::TYPE_DATE, '2024-02-29', null];
		yield 'date: 29 February outside a leap year' => [Fields::TYPE_DATE, '2026-02-29', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: 31 February' => [Fields::TYPE_DATE, '2026-02-31', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: month 13' => [Fields::TYPE_DATE, '2026-13-01', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: day zero' => [Fields::TYPE_DATE, '2026-10-00', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: without leading zeros' => [Fields::TYPE_DATE, '2026-1-5', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: German format' => [Fields::TYPE_DATE, '22.10.2026', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: with time' => [Fields::TYPE_DATE, '2026-10-22 12:00', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: surrounding space' => [Fields::TYPE_DATE, ' 2026-10-22', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: empty string' => [Fields::TYPE_DATE, '', '"%s" needs a valid date (YYYY-MM-DD).'];
		yield 'date: non-string' => [Fields::TYPE_DATE, 20261022, '"%s" needs a valid date (YYYY-MM-DD).'];

		yield 'text: string' => [Fields::TYPE_TEXT, 'Ave verum corpus', null];
		yield 'text: empty string' => [Fields::TYPE_TEXT, '', null];
		yield 'text: int' => [Fields::TYPE_TEXT, 5, '"%s" must be text.'];
		yield 'longtext: array' => [Fields::TYPE_LONGTEXT, ['x'], '"%s" must be text.'];
		yield 'longtext: long text without limit' => [Fields::TYPE_LONGTEXT, str_repeat('x', 100000), null];
	}

	#[DataProvider('cases')]
	public function testCheck(string $type, mixed $value, ?string $error): void {
		$extra = $type === Fields::TYPE_CHOICE ? ['options' => Fields::RIGHTS_STATUS] : [];
		self::assertSame($error, FieldValue::check(self::info($type, $extra), $value));
	}

	public function testMaxLengthCountsCharactersNotBytes(): void {
		$info = self::info(Fields::TYPE_TEXT, ['max' => 5]);
		self::assertNull(FieldValue::check($info, 'äöüßé'));
		self::assertNull(FieldValue::check($info, '12345'));
		self::assertSame('"%s" is too long.', FieldValue::check($info, 'äöüßéx'));
		self::assertSame('"%s" is too long.', FieldValue::check($info, '123456'));
	}

	public function testErrorMessagesHaveOnePlaceholderForTheLabel(): void {
		foreach (self::cases() as [$type, $value, $error]) {
			if ($error !== null) {
				self::assertSame(1, substr_count($error, '%s'), $error);
			}
		}
	}

	/**
	 * @return iterable<string, array{0: string, 1: bool}>
	 */
	public static function dates(): iterable {
		yield 'valid' => ['2026-02-28', true];
		yield 'leap year' => ['2000-02-29', true];
		yield 'no leap year (century)' => ['1900-02-29', false];
		yield 'impossible day' => ['2026-02-31', false];
		yield 'April 31' => ['2026-04-31', false];
		yield 'trailing text' => ['2026-02-28x', false];
		yield 'slashes' => ['2026/02/28', false];
		yield 'two-digit year' => ['26-02-28', false];
	}

	#[DataProvider('dates')]
	public function testIsDate(string $value, bool $valid): void {
		self::assertSame($valid, FieldValue::isDate($value));
	}
}
