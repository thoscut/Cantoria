<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\IcsWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * iCalendar writing after RFC 5545: line breaks, folding after 75 octets
 * (also with umlauts and other multi-octet characters), escaping of TEXT
 * values and time zone definitions.
 */
#[CoversClass(IcsWriter::class)]
final class IcsWriterTest extends TestCase {
	/**
	 * Undoes the folding (RFC 5545 section 3.1): a line break followed by
	 * one space or tab is removed.
	 */
	private static function unfold(string $text): string {
		return (string)preg_replace('/\r\n[ \t]/', '', $text);
	}

	/**
	 * Every physical line of a folded text: at most 75 octets, valid UTF-8,
	 * continuation lines start with one space.
	 */
	private static function assertFolded(string $folded): void {
		foreach (explode("\r\n", $folded) as $index => $line) {
			self::assertLessThanOrEqual(75, strlen($line), 'line ' . $index . ' is too long: ' . $line);
			self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'line ' . $index . ' splits a character');
			if ($index > 0) {
				self::assertSame(' ', $line[0], 'continuation line ' . $index . ' starts with a space');
			}
		}
	}

	public function testEscapesTextValues(): void {
		self::assertSame('Bach\\, Händel\\; Mozart', IcsWriter::text('Bach, Händel; Mozart'));
		self::assertSame('C:\\\\Noten\\\\Advent', IcsWriter::text('C:\\Noten\\Advent'));
		self::assertSame('Zeile 1\\nZeile 2\\nZeile 3\\nZeile 4', IcsWriter::text("Zeile 1\r\nZeile 2\nZeile 3\rZeile 4"));
		// The backslash is escaped first, so an escaped comma is not escaped twice.
		self::assertSame('a\\\\\\,b', IcsWriter::text('a\\,b'));
		// Colons and double quotes are allowed in TEXT.
		self::assertSame('Konzert: "Messias"', IcsWriter::text('Konzert: "Messias"'));
	}

	public function testRemovesControlCharactersAndInvalidUtf8(): void {
		self::assertSame("a b\tc", IcsWriter::text("a\x07b\tc"));
		self::assertTrue(mb_check_encoding(IcsWriter::text("Kirche \xC3"), 'UTF-8'));
	}

	public function testShortLinesAreNotFolded(): void {
		$line = 'SUMMARY:' . str_repeat('x', 67);
		self::assertSame(75, strlen($line));
		self::assertSame($line, IcsWriter::fold($line));
	}

	public function testFoldsAfter75Octets(): void {
		$line = 'DESCRIPTION:' . str_repeat('abcdefghij', 20);
		$folded = IcsWriter::fold($line);
		self::assertFolded($folded);
		self::assertSame(75, strlen(explode("\r\n", $folded)[0]));
		self::assertSame(75, strlen(explode("\r\n", $folded)[1]), 'a continuation line holds the space and 74 octets');
		self::assertSame($line, self::unfold($folded));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function multiOctetCharacters(): iterable {
		yield 'umlauts (2 octets)' => ['Ä'];
		yield 'sharp s and umlaut' => ['ßü'];
		yield 'euro sign (3 octets)' => ['€'];
		yield 'musical note (4 octets)' => ['𝄞'];
	}

	#[DataProvider('multiOctetCharacters')]
	public function testNeverSplitsAMultiOctetCharacter(string $character): void {
		// Different offsets, so that the limit falls on every octet of a character.
		for ($prefix = 0; $prefix < 4; $prefix++) {
			$line = 'SUMMARY:' . str_repeat('-', $prefix) . str_repeat($character . ' Grüße', 40);
			$folded = IcsWriter::fold($line);
			self::assertFolded($folded);
			self::assertSame($line, self::unfold($folded));
		}
	}

	public function testADocumentEndsEveryLineWithCrlf(): void {
		$document = IcsWriter::document(['BEGIN:VCALENDAR', 'SUMMARY:' . str_repeat('ö', 60), 'END:VCALENDAR']);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $document);
		self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $document);
		self::assertSame(0, preg_match('/(?<!\r)\n/', $document), 'no line feed without carriage return');
		self::assertSame(0, preg_match('/\r(?!\n)/', $document), 'no carriage return without line feed');
		foreach (explode("\r\n", rtrim($document, "\r\n")) as $line) {
			self::assertLessThanOrEqual(75, strlen($line));
			self::assertTrue(mb_check_encoding($line, 'UTF-8'));
		}
		self::assertSame("BEGIN:VCALENDAR\r\nSUMMARY:" . str_repeat('ö', 60) . "\r\nEND:VCALENDAR\r\n", self::unfold($document));
	}

	public function testQuotesParameterValuesOnlyWhenNeeded(): void {
		self::assertSame('Europe/Berlin', IcsWriter::parameter('Europe/Berlin'));
		self::assertSame('America/Argentina/Buenos_Aires', IcsWriter::parameter('America/Argentina/Buenos_Aires'));
		self::assertSame('"Zone: A, B"', IcsWriter::parameter('Zone: A, B'));
		self::assertSame('"a;b"', IcsWriter::parameter('a;"b'));
	}

	public function testFormatsDatesAndTimes(): void {
		self::assertSame('20261212', IcsWriter::date('2026-12-12'));
		self::assertSame('20261212T193000', IcsWriter::localTime('2026-12-12', '19:30'));
		self::assertSame('20261022T120000Z', IcsWriter::utc(1792670400));
	}

	public function testDefinesATimeZoneWithDaylightSavingTime(): void {
		$lines = IcsWriter::timezone(new \DateTimeZone('Europe/Berlin'), 2026, 2026);
		$text = implode("\n", $lines);
		self::assertSame('BEGIN:VTIMEZONE', $lines[0]);
		self::assertSame('TZID:Europe/Berlin', $lines[1]);
		self::assertSame('END:VTIMEZONE', end($lines));
		// State at the start of the year
		self::assertStringContainsString("BEGIN:STANDARD\nDTSTART:20260101T000000\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0100\nTZNAME:CET\nEND:STANDARD", $text);
		// Clocks go forward at 02:00 local time on the last Sunday in March …
		self::assertStringContainsString("BEGIN:DAYLIGHT\nDTSTART:20260329T020000\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0200\nTZNAME:CEST\nEND:DAYLIGHT", $text);
		// … and back at 03:00 local time on the last Sunday in October.
		self::assertStringContainsString("BEGIN:STANDARD\nDTSTART:20261025T030000\nTZOFFSETFROM:+0200\nTZOFFSETTO:+0100\nTZNAME:CET\nEND:STANDARD", $text);
		self::assertSame(3, substr_count($text, 'BEGIN:STANDARD') + substr_count($text, 'BEGIN:DAYLIGHT'));
	}

	public function testCoversEveryYearOfTheRange(): void {
		$text = implode("\n", IcsWriter::timezone(new \DateTimeZone('Europe/Berlin'), 2026, 2027));
		self::assertStringContainsString('DTSTART:20270328T020000', $text);
		self::assertStringContainsString('DTSTART:20271031T030000', $text);
		self::assertStringNotContainsString('DTSTART:2028', $text);
	}

	public function testDefinesZonesWithoutDaylightSavingTime(): void {
		$utc = IcsWriter::timezone(new \DateTimeZone('UTC'), 2026, 2026);
		self::assertSame(['BEGIN:VTIMEZONE', 'TZID:UTC', 'BEGIN:STANDARD', 'DTSTART:20260101T000000', 'TZOFFSETFROM:+0000',
			'TZOFFSETTO:+0000', 'TZNAME:UTC', 'END:STANDARD', 'END:VTIMEZONE'], $utc);

		$kolkata = implode("\n", IcsWriter::timezone(new \DateTimeZone('Asia/Kolkata'), 2026, 2026));
		self::assertStringContainsString("TZOFFSETFROM:+0530\nTZOFFSETTO:+0530", $kolkata);

		$newYork = implode("\n", IcsWriter::timezone(new \DateTimeZone('America/New_York'), 2026, 2026));
		self::assertStringContainsString("DTSTART:20260308T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400", $newYork);
	}
}
