<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\Fields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogCsv::class)]
final class CatalogCsvTest extends TestCase {
	private const HEADER = ['Mappe', 'Nr', 'Signatur', 'Titel', 'Anmerkung'];

	/**
	 * Cells covering every quoting rule: separator, quote, CR, LF and CRLF in
	 * a cell, spaces (never quoted), backslash (no escape character), empty
	 * cells and an empty row.
	 */
	private const ROWS = [
		['5', '1', '5-1', 'Ave verum corpus', 'Mozart, KV 618 – äöü ß'],
		['5', '2', '5-2', 'Titel; mit Semikolon', 'He said "hello"'],
		['6', '8', '6-8', "Line one\r\nline two", "only LF\nhere"],
		['7', '1', '7-1', '', '  leading and trailing spaces  '],
		['7', '2', '7-2', "CR only\rx", 'quote at end"'],
		['8', '1', '8-1', "comma, tab\tand backslash \\", '"'],
		['', '', '', '', ''],
	];

	/**
	 * Reference output of Python 3.14:
	 * csv.writer(f, delimiter=';', quoting=csv.QUOTE_MINIMAL, lineterminator='\r\n')
	 * for HEADER and ROWS, encoded as UTF-8 with BOM ("utf-8-sig").
	 */
	private const PYTHON_HEX = 'efbbbf4d617070653b4e723b5369676e617475723b546974656c3b416e6d65726b756e670d0a353b313b352d313b41766520766572756d20636f727075733b4d6f7a6172742c204b562036313820e2809320c3a4c3b6c3bc20c39f0d0a353b323b352d323b22546974656c3b206d69742053656d696b6f6c6f6e223b224865207361696420222268656c6c6f2222220d0a363b383b362d383b224c696e65206f6e650d0a6c696e652074776f223b226f6e6c79204c460a68657265220d0a373b313b372d313b3b20206c656164696e6720616e6420747261696c696e672073706163657320200d0a373b323b372d323b224352206f6e6c790d78223b2271756f746520617420656e642222220d0a383b313b382d313b636f6d6d612c2074616209616e64206261636b736c617368205c3b222222220d0a3b3b3b3b0d0a';

	private const PYTHON_TEXT = "\xEF\xBB\xBFMappe;Nr;Signatur;Titel;Anmerkung\r\n"
		. "5;1;5-1;Ave verum corpus;Mozart, KV 618 – äöü ß\r\n"
		. "5;2;5-2;\"Titel; mit Semikolon\";\"He said \"\"hello\"\"\"\r\n"
		. "6;8;6-8;\"Line one\r\nline two\";\"only LF\nhere\"\r\n"
		. "7;1;7-1;;  leading and trailing spaces  \r\n"
		. "7;2;7-2;\"CR only\rx\";\"quote at end\"\"\"\r\n"
		. "8;1;8-1;comma, tab\tand backslash \\;\"\"\"\"\r\n"
		. ";;;;\r\n";

	// ---- writing ----

	public function testWriteMatchesPythonQuoteMinimalByteForByte(): void {
		$csv = CatalogCsv::write(self::HEADER, self::ROWS);
		self::assertSame(self::PYTHON_HEX, bin2hex($csv));
		self::assertSame(self::PYTHON_TEXT, $csv);
	}

	public function testWriteStartsWithBomAndEndsEveryRecordWithCrlf(): void {
		$csv = CatalogCsv::write(['a', 'b'], [['1', '2'], ['3', '4']]);
		self::assertSame("\xEF\xBB\xBFa;b\r\n1;2\r\n3;4\r\n", $csv);
	}

	public function testWriteWithoutRowsWritesTheHeaderOnly(): void {
		self::assertSame("\xEF\xBB\xBFa;b\r\n", CatalogCsv::write(['a', 'b'], []));
	}

	public function testWriteAcceptsAGenerator(): void {
		$rows = (function (): \Generator {
			yield ['1', 'x y'];
		})();
		self::assertSame("\xEF\xBB\xBFa;b\r\n1;x y\r\n", CatalogCsv::write(['a', 'b'], $rows));
	}

	// ---- parsing ----

	public function testParseReadsHeaderAndRows(): void {
		$parsed = CatalogCsv::parse(self::PYTHON_TEXT);
		self::assertSame(self::HEADER, $parsed['header']);
		self::assertSame(self::ROWS, $parsed['rows']);
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function boms(): iterable {
		yield 'no BOM' => [''];
		yield 'BOM' => ["\xEF\xBB\xBF"];
		yield 'double BOM (saved twice by a spreadsheet)' => ["\xEF\xBB\xBF\xEF\xBB\xBF"];
	}

	#[DataProvider('boms')]
	public function testParseStripsLeadingBoms(string $bom): void {
		$parsed = CatalogCsv::parse($bom . "Signatur;Titel\r\n5-1;Ave\r\n");
		self::assertSame(['Signatur', 'Titel'], $parsed['header']);
		self::assertSame([['5-1', 'Ave']], $parsed['rows']);
	}

	public function testParseKeepsCrlfAndQuotesInsideCells(): void {
		$parsed = CatalogCsv::parse("A;B\r\n\"two\r\nlines\";\"say \"\"hi\"\"; twice\"\r\n");
		self::assertSame([["two\r\nlines", 'say "hi"; twice']], $parsed['rows']);
	}

	public function testParseTreatsBackslashAsOrdinaryCharacter(): void {
		$parsed = CatalogCsv::parse("A;B\r\n\"C:\\Noten\\\";x\r\n");
		self::assertSame([['C:\\Noten\\', 'x']], $parsed['rows']);
	}

	public function testParseSkipsEmptyLines(): void {
		$parsed = CatalogCsv::parse("\r\nA;B\r\n\r\n1;2\n\n3;4\r\n\r\n");
		self::assertSame(['A', 'B'], $parsed['header']);
		self::assertSame([['1', '2'], ['3', '4']], $parsed['rows']);
	}

	public function testParseKeepsRowsOfEmptyCells(): void {
		// A row of separators is not an empty line; the import decides to skip it.
		self::assertSame([['', '']], CatalogCsv::parse("A;B\r\n;\r\n")['rows']);
	}

	public function testParseAcceptsLfAndMissingFinalLineBreak(): void {
		$parsed = CatalogCsv::parse("A;B\n1;2\n3;4");
		self::assertSame([['1', '2'], ['3', '4']], $parsed['rows']);
	}

	public function testParseTrimsHeaderNamesButNotCells(): void {
		$parsed = CatalogCsv::parse(" Signatur ;\tTitel\r\n 5-1 ; Ave \r\n");
		self::assertSame(['Signatur', 'Titel'], $parsed['header']);
		self::assertSame([[' 5-1 ', ' Ave ']], $parsed['rows']);
	}

	public function testParseKeepsRaggedRowsAsTheyAre(): void {
		$parsed = CatalogCsv::parse("A;B;C\r\n1\r\n1;2;3;4\r\n");
		self::assertSame([['1'], ['1', '2', '3', '4']], $parsed['rows']);
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function emptyFiles(): iterable {
		yield 'empty' => [''];
		yield 'BOM only' => ["\xEF\xBB\xBF"];
		yield 'empty lines only' => ["\r\n\r\n\n"];
	}

	#[DataProvider('emptyFiles')]
	public function testParseRejectsEmptyFiles(string $content): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The CSV file is empty.');
		CatalogCsv::parse($content);
	}

	public function testParseRejectsNonUtf8(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('not UTF-8');
		CatalogCsv::parse("Signatur;Titel\r\n5-1;Gr\xFC\xDF Gott\r\n");
	}

	// ---- round trip ----

	public function testRoundTripReproducesTheFileByteForByte(): void {
		$parsed = CatalogCsv::parse(self::PYTHON_TEXT);
		self::assertSame(self::PYTHON_TEXT, CatalogCsv::write($parsed['header'], $parsed['rows']));
	}

	public function testRoundTripOfArbitraryCells(): void {
		$alphabet = [';', '"', "\r", "\n", "\r\n", ' ', "\t", '\\', 'a', 'ä', '–', ',', "'", ''];
		mt_srand(20261022);
		$rows = [];
		for ($r = 0; $r < 200; $r++) {
			$row = [];
			for ($c = 0; $c < 4; $c++) {
				$cell = '';
				for ($n = mt_rand(0, 6); $n > 0; $n--) {
					$cell .= $alphabet[mt_rand(0, count($alphabet) - 1)];
				}
				$row[] = $cell;
			}
			$rows[] = $row;
		}
		$csv = CatalogCsv::write(['a', 'b', 'c', 'd'], $rows);
		self::assertSame($rows, CatalogCsv::parse($csv)['rows']);
		self::assertSame($csv, CatalogCsv::write(['a', 'b', 'c', 'd'], CatalogCsv::parse($csv)['rows']));
	}

	// ---- formula injection ----

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function formulaCells(): iterable {
		yield 'equals' => ['=HYPERLINK("http://example.org","x")', '\'=HYPERLINK("http://example.org","x")'];
		yield 'plus' => ['+1+1', "'+1+1"];
		yield 'minus' => ['-2+3', "'-2+3"];
		yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
		yield 'tab' => ["\t=1", "'\t=1"];
		yield 'carriage return' => ["\r=1", "'\r=1"];
		yield 'plain text' => ['Ave verum corpus', 'Ave verum corpus'];
		yield 'formula later in the cell' => ['a=b', 'a=b'];
		yield 'apostrophe' => ["'s Dirndl", "'s Dirndl"];
		yield 'empty' => ['', ''];
		yield 'number' => ['3:05', '3:05'];
	}

	#[DataProvider('formulaCells')]
	public function testNeutralize(string $cell, string $expected): void {
		self::assertSame($expected, CatalogCsv::neutralize($cell));
	}

	public function testWriteForPeopleNeutralizesHeaderAndCells(): void {
		self::assertSame("\xEF\xBB\xBF'=a;b\r\n'=cmd|' /C calc'!A0;\"'@x;y\"\r\n",
			CatalogCsv::writeForPeople(['=a', 'b'], [["=cmd|' /C calc'!A0", '@x;y']]));
	}

	public function testCatalogCsvKeepsFormulaLikeCellsForTheRoundTrip(): void {
		$rows = [['=1+1', '-5', '@home', "\tindented"]];
		$csv = CatalogCsv::write(['a', 'b', 'c', 'd'], $rows);
		self::assertSame("\xEF\xBB\xBFa;b;c;d\r\n=1+1;-5;@home;\tindented\r\n", $csv);
		self::assertSame($rows, CatalogCsv::parse($csv)['rows']);
	}

	// ---- values and columns ----

	/**
	 * @return iterable<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function csvValues(): iterable {
		yield 'null' => ['Titel', null, ''];
		yield 'string' => ['Titel', 'Ave', 'Ave'];
		yield 'int' => ['Mappe', 5, '5'];
		yield 'true' => ['x', true, '1'];
		yield 'false' => ['x', false, '0'];
		yield 'rights status: public domain' => ['Rechtestatus', 'public_domain', 'gemeinfrei'];
		yield 'rights status: protected' => ['Rechtestatus', 'protected', 'geschützt'];
		yield 'rights status: open' => ['Rechtestatus', 'open', 'offen'];
		yield 'rights status: unknown value passes' => ['Rechtestatus', 'something', 'something'];
		yield 'rights key in another column stays' => ['version.rights_status_import', 'public_domain', 'public_domain'];
	}

	#[DataProvider('csvValues')]
	public function testToCsv(string $header, mixed $value, string $expected): void {
		self::assertSame($expected, CatalogCsv::toCsv($header, $value));
	}

	public function testLegacyColumnsAreTheLegacyCatalogFormat(): void {
		self::assertSame(CatalogCsv::LEGACY_COLUMNS, CatalogCsv::columns(false));
		self::assertSame('Mappe', array_key_first(CatalogCsv::LEGACY_COLUMNS));
		self::assertTrue(CatalogCsv::isLegacyColumn('Signatur'));
		self::assertFalse(CatalogCsv::isLegacyColumn('copy.status'));
	}

	public function testExtendedColumnsAppendEveryOtherFieldOnce(): void {
		$columns = CatalogCsv::columns(true);
		$legacy = CatalogCsv::LEGACY_COLUMNS;
		self::assertSame($legacy, array_slice($columns, 0, count($legacy), true), 'legacy columns come first, in order');

		$targets = array_map(fn (array $c): string => $c[0] . '.' . $c[1], array_values($columns));
		self::assertSame(array_values(array_unique($targets)), $targets, 'every field appears once');

		$expected = [];
		foreach (Fields::all() as $level => $fields) {
			foreach (array_keys($fields) as $field) {
				$expected[] = $level . '.' . $field;
			}
		}
		sort($expected);
		$actual = $targets;
		sort($actual);
		self::assertSame($expected, $actual, 'every field of the registry is exported');

		foreach (array_slice($columns, count($legacy), null, true) as $header => [$level, $field]) {
			self::assertSame($level . '.' . $field, $header);
		}
	}

	// ---- legacy rules ----

	/**
	 * @return iterable<string, array{0: ?string, 1: ?string}>
	 */
	public static function publicationNotes(): iterable {
		yield 'null' => [null, null];
		yield 'empty' => ['', null];
		yield 'blank' => ['   ', null];
		yield 'frei' => ['frei', 'free'];
		yield 'frei with reason' => ['frei (gemeinfrei, Ausgabe 1890)', 'free'];
		yield 'Frei, upper case and spaces' => ['  Frei', 'free'];
		yield 'gesperrt' => ['gesperrt – Verlag', 'blocked'];
		yield 'zu klären' => ['zu klären', 'to_clarify'];
		yield 'Zu Klären, upper-case umlaut' => ['ZU KLÄREN bis Herbst', 'to_clarify'];
		yield 'internal preservation copy' => ['nein – interne Erhaltungskopie', 'internal'];
		yield 'Nein, ERHALTUNGSKOPIE' => ['Nein, ERHALTUNGSKOPIE', 'internal'];
		yield 'nein without preservation copy' => ['nein', null];
		yield 'preservation copy without nein' => ['Erhaltungskopie', null];
		yield 'unfrei is not frei' => ['unfrei', null];
		yield 'something else' => ['ja', null];
	}

	#[DataProvider('publicationNotes')]
	public function testLegacyPublicationStatus(?string $note, ?string $status): void {
		self::assertSame($status, CatalogCsv::legacyPublicationStatus($note));
		if ($status !== null) {
			self::assertArrayHasKey($status, Fields::PUBLICATION_STATUS);
		}
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: bool}>
	 */
	public static function preservationNotes(): iterable {
		yield 'null' => [null, false];
		yield 'empty' => ['', false];
		yield 'nein, Erhaltungskopie' => ['nein (Erhaltungskopie)', true];
		yield 'leading space and capitals' => ['  Nein – interne ERHALTUNGSKOPIE', true];
		yield 'no separator' => ['neinErhaltungskopie', true];
		yield 'Erhaltungskopie first' => ['Erhaltungskopie, nein', false];
		yield 'keine Erhaltungskopie' => ['keine Erhaltungskopie', false];
		yield 'nein only' => ['nein', false];
		yield 'frei' => ['frei', false];
	}

	#[DataProvider('preservationNotes')]
	public function testIsLegacyPreservationCopy(?string $note, bool $expected): void {
		self::assertSame($expected, CatalogCsv::isLegacyPreservationCopy($note));
	}

	public function testTheTemplateHasTheCatalogColumnsAndOneExampleRow(): void {
		$template = CatalogCsv::template();
		self::assertSame(array_keys(CatalogCsv::LEGACY_COLUMNS), $template['header']);
		self::assertCount(1, $template['rows']);
		self::assertCount(count($template['header']), $template['rows'][0]);
		$row = array_combine($template['header'], $template['rows'][0]);
		self::assertSame('1-1', $row['Signatur']);
		self::assertSame('gemeinfrei', $row['Rechtestatus'], 'a value the import knows');
		self::assertSame([], array_diff(array_keys(CatalogCsv::TEMPLATE_EXAMPLE), $template['header']), 'every example value has its column');

		// Written as the import reads it
		$parsed = CatalogCsv::parse(CatalogCsv::write($template['header'], $template['rows']));
		self::assertSame($template['header'], $parsed['header']);
		self::assertSame($template['rows'], $parsed['rows']);
	}
}
