<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\LifeDates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifeDates::class)]
final class LifeDatesTest extends TestCase {
	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: string, 3: ?string, 4: string}>
	 */
	public static function texts(): iterable {
		// text => born, born precision, died, died precision
		yield 'years with hyphen' => ['1789-1860', '1789', 'year', '1860', 'year'];
		yield 'years with en dash' => ["1789\u{2013}1860", '1789', 'year', '1860', 'year'];
		yield 'in brackets with blanks' => ['(1789 - 1860)', '1789', 'year', '1860', 'year'];
		yield 'with "bis"' => ['1850 bis 1910', '1850', 'year', '1910', 'year'];
		yield 'full dates' => ["12.03.1850\u{2013}05.11.1910", '1850-03-12', 'exact', '1910-11-05', 'exact'];
		yield 'ISO dates' => ['1850-03-12 - 1910-11-05', '1850-03-12', 'exact', '1910-11-05', 'exact'];
		yield 'impossible date counts as year' => ['31.02.1850-1910', '1850', 'year', '1910', 'year'];
		// A birth marker without a death means living (catalogs write the
		// death as soon as it is known); before the change it was "unknown".
		yield 'born only' => ['geb. 1928', '1928', 'year', null, 'living'];
		yield 'born with asterisk, catalog style' => ['(* 1944)', '1944', 'year', null, 'living'];
		yield 'born with asterisk and GND' => ['* 1944, GND 134490509', '1944', 'year', null, 'living'];
		yield 'born with asterisk, uncertain year' => ['* 1926?', '1926', 'circa', null, 'living'];
		yield 'born with asterisk and open range' => ["* 1950 \u{2013}", '1950', 'year', null, 'living'];
		yield 'born with asterisk and death' => ["* 1850 \u{2013} 1910", '1850', 'year', '1910', 'year'];
		yield 'born with asterisk and cross' => ["* 1850, \u{2020} 1910", '1850', 'year', '1910', 'year'];
		yield 'born too long ago to be living' => ['geb. um 1600', '1600', 'circa', null, 'unknown'];
		yield 'born, living' => ['* 1944, lebend', '1944', 'year', null, 'living'];
		yield 'range with GND' => ["1819\u{2013}1885, GND 134446216", '1819', 'year', '1885', 'year'];
		yield 'circa alone is ambiguous' => ['um 1600', null, 'unknown', null, 'unknown'];
		yield 'living without year' => ['lebt', null, 'unknown', null, 'living'];
		yield 'died with cross' => ["\u{2020} 1955", null, 'unknown', '1955', 'year'];
		yield 'died with "gest."' => ['gest. 1955', null, 'unknown', '1955', 'year'];
		yield 'born and died with markers' => ['geb. 12.03.1850, gest. 1910', '1850-03-12', 'exact', '1910', 'year'];
		yield 'circa covers the whole range' => ['ca. 1850-1910', '1850', 'circa', '1910', 'circa'];
		yield 'circa only for death' => ['1850-ca. 1910', '1850', 'year', '1910', 'circa'];
		yield 'question mark' => ['1850?-1910', '1850', 'circa', '1910', 'year'];
		yield 'open end' => ['1850-', '1850', 'year', null, 'unknown'];
		yield 'open start' => ['-1910', null, 'unknown', '1910', 'year'];
		yield 'single year is ambiguous' => ['1850', null, 'unknown', null, 'unknown'];
		yield 'decade is unknown' => ['185?', null, 'unknown', null, 'unknown'];
		yield 'century is unknown' => ['20./21. Jahrhundert', null, 'unknown', null, 'unknown'];
		yield 'before Christ, minus signs' => ['-65--8', '-65', 'year', '-8', 'year'];
		yield 'before Christ, written out' => ['65-8 v. Chr.', '-65', 'year', '-8', 'year'];
		yield 'death before birth is not trusted' => ['1860-1789', null, 'unknown', null, 'unknown'];
		yield 'empty' => ['', null, 'unknown', null, 'unknown'];
		yield 'null' => [null, null, 'unknown', null, 'unknown'];
		yield 'text without dates' => ['unbekannt', null, 'unknown', null, 'unknown'];
	}

	#[DataProvider('texts')]
	public function testParse(?string $text, ?string $born, string $bornPrecision, ?string $died, string $diedPrecision): void {
		self::assertSame(
			['born' => $born, 'born_precision' => $bornPrecision, 'died' => $died, 'died_precision' => $diedPrecision],
			LifeDates::parse($text),
		);
	}

	public function testParsedPrecisionsAreKnownValues(): void {
		foreach (self::texts() as [$text]) {
			$result = LifeDates::parse($text);
			self::assertContains($result['born_precision'], ['exact', 'year', 'circa', 'unknown'], (string)$text);
			self::assertContains($result['died_precision'], ['exact', 'year', 'circa', 'living', 'unknown'], (string)$text);
		}
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: ?int}>
	 */
	public static function years(): iterable {
		yield 'year' => ['1850', 1850];
		yield 'date' => ['1850-03-12', 1850];
		yield 'before Christ' => ['-8', -8];
		yield 'early year' => ['65', 65];
		yield 'null' => [null, null];
		yield 'text' => ['about 1850', null];
		yield 'incomplete date' => ['1850-3-1', null];
	}

	#[DataProvider('years')]
	public function testYear(?string $value, ?int $year): void {
		self::assertSame($year, LifeDates::year($value));
	}
}
