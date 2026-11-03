<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\BoundedZip;
use OCA\Cantoria\Service\OdsReader;
use OCA\Cantoria\Service\OdsWriter;
use OCA\Cantoria\Service\XlsxReader;
use OCA\Cantoria\Tests\Unit\Support\TempFiles;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Reads a small synthetic spreadsheet in the structure LibreOffice writes
 * (U32): runs of repeated cells and rows, empty runs up to the last column
 * and row, typed values, paragraphs with spaces, tabs and comments, rows in
 * groups; and refuses what an untrusted upload may hold.
 */
#[CoversClass(OdsReader::class)]
#[UsesClass(BoundedZip::class)]
#[UsesClass(OdsWriter::class)]
#[RequiresPhpExtension('zip')]
final class OdsReaderTest extends TestCase {
	use TempFiles;

	private const NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
		. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
		. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"';

	/**
	 * content.xml with the given sheets (table:name => XML of the rows).
	 *
	 * @param array<string, string> $tables
	 */
	private static function content(array $tables): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NAMESPACES . ' office:version="1.3">'
			. '<office:body><office:spreadsheet>';
		foreach ($tables as $name => $rows) {
			$xml .= '<table:table table:name="' . $name . '">' . $rows . '</table:table>';
		}
		return $xml . '</office:spreadsheet></office:body></office:document-content>';
	}

	/**
	 * @param array<string, string> $tables
	 */
	private function ods(array $tables, string $mimetype = OdsReader::MIMETYPE): string {
		return $this->zip(['mimetype' => $mimetype, 'content.xml' => self::content($tables)]);
	}

	private static function cell(string $text, string $attributes = ''): string {
		return '<table:table-cell office:value-type="string"' . $attributes . '><text:p>' . $text . '</text:p></table:table-cell>';
	}

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	private function read(string $content): array {
		return (new OdsReader($this->tempManager()))->read($content);
	}

	public function testReadsSheetsAsLibreOfficeWritesThem(): void {
		$catalog = '<table:table-column table:number-columns-repeated="3"/>'
			. '<table:table-header-rows><table:table-row>' . self::cell('Signatur') . self::cell('Titel')
			. '<table:table-cell table:number-columns-repeated="1022"/></table:table-row></table:table-header-rows>'
			. '<table:table-row>' . self::cell('5-1')
			. '<table:table-cell office:value-type="string"><text:p>Ave <text:span>verum</text:span><text:s text:c="2"/>corpus</text:p>'
			. '<text:p>zweite<text:tab/>Zeile<text:line-break/>dritte</text:p>'
			. '<office:annotation><text:p>a comment</text:p></office:annotation></table:table-cell></table:table-row>'
			. '<table:table-row table:number-rows-repeated="2"><table:table-cell table:number-columns-repeated="1024"/></table:table-row>'
			. '<table:table-row-group><table:table-row><table:table-cell table:number-columns-repeated="2"/>'
			. '<table:table-cell office:value-type="float" office:value="12.5"><text:p>12,50</text:p></table:table-cell>'
			. '<table:table-cell office:value-type="date" office:date-value="2026-11-03T00:00:00"><text:p>03.11.26</text:p></table:table-cell>'
			. '<table:table-cell office:value-type="date" office:date-value="2026-11-03T18:30:00"><text:p>18:30</text:p></table:table-cell>'
			. '<table:table-cell office:value-type="boolean" office:boolean-value="true"><text:p>WAHR</text:p></table:table-cell>'
			. '<table:table-cell office:value-type="percentage" office:value="0.5"><text:p>50 %</text:p></table:table-cell>'
			. '</table:table-row></table:table-row-group>'
			. '<table:table-row table:number-rows-repeated="2">' . self::cell('x', ' table:number-columns-repeated="2"') . '<table:covered-table-cell/>'
			. self::cell('y') . '</table:table-row>'
			. '<table:table-row table:number-rows-repeated="1048570"><table:table-cell table:number-columns-repeated="1024"/></table:table-row>';
		$sheets = $this->read($this->ods(['Werkverzeichnis' => $catalog, '2024' => '<table:table-row>' . self::cell('first') . '</table:table-row>']));

		self::assertSame(['Werkverzeichnis', '2024'], array_column($sheets, 'name'), 'a sheet named like a year stays a string');
		self::assertSame([
			['Signatur', 'Titel'],
			['5-1', "Ave verum  corpus\nzweite\tZeile\ndritte"],
			[],
			[],
			['', '', '12.5', '2026-11-03', '2026-11-03T18:30:00', '1', '0.5'],
			['x', 'x', '', 'y'],
			['x', 'x', '', 'y'],
		], $sheets[0]['rows'], 'empty runs at the end of a row and of the sheet are left out, a comment is no text');
		self::assertSame([['first']], $sheets[1]['rows']);
	}

	public function testReadsWhatOdsWriterWrites(): void {
		$rows = [['Signatur', 'Titel', 'Anmerkung'], ['5-1', 'Ave <verum> & "corpus"', "line\nbreak"], ['5-2', '', '']];
		$ods = (new OdsWriter($this->tempManager()))->write([['name' => 'Catalog', 'rows' => $rows]]);
		self::assertSame([['name' => 'Catalog', 'rows' => [$rows[0], $rows[1], ['5-2']]]], $this->read($ods));
	}

	/**
	 * @return iterable<string, array{0: bool}>
	 */
	public static function validity(): iterable {
		yield 'after reading' => [true];
		yield 'after a refused file' => [false];
	}

	#[DataProvider('validity')]
	public function testRemovesItsTemporaryFile(bool $valid): void {
		$created = [];
		$manager = $this->createStub(ITempManager::class);
		$manager->method('getTemporaryFile')->willReturnCallback(function () use (&$created): string {
			$path = $this->tempFile('');
			$created[] = $path;
			return $path;
		});
		$content = $valid ? $this->ods(['Sheet' => '<table:table-row>' . self::cell('a') . '</table:table-row>']) : 'no spreadsheet';
		try {
			(new OdsReader($manager))->read($content);
		} catch (InvalidInputException) {
		}
		self::assertCount(1, $created);
		self::assertFileDoesNotExist($created[0]);
	}

	public function testRefusesFilesThatAreNoSpreadsheet(): void {
		$cases = [
			'not a ZIP archive' => 'Mappe;Nr;Signatur',
			'empty' => '',
			'a text document' => $this->ods(['Sheet' => ''], 'application/vnd.oasis.opendocument.text'),
			'a ZIP without content' => $this->zip(['mimetype' => OdsReader::MIMETYPE]),
			'an Excel workbook' => $this->zip(['xl/workbook.xml' => '<workbook/>']),
			'content of another kind' => $this->zip(['content.xml' => '<?xml version="1.0"?><office:document-content ' . self::NAMESPACES
				. '><office:body><office:text/></office:body></office:document-content>']),
		];
		foreach ($cases as $case => $content) {
			try {
				$this->read($content);
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame('The file is not an OpenDocument spreadsheet (.ods).', $e->getMessage(), $case);
			}
		}
	}

	public function testRefusesDamagedXml(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The OpenDocument spreadsheet is damaged.');
		$this->read($this->zip(['mimetype' => OdsReader::MIMETYPE, 'content.xml' => '<office:document-content><office:body>']));
	}

	// ---- untrusted spreadsheets ----

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function doctypes(): iterable {
		yield 'external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE office:document-content [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'];
		yield 'entity expansion' => ['<?xml version="1.0"?><!DOCTYPE office:document-content [<!ENTITY a "aaaaaaaaaa"><!ENTITY xxe "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'];
		yield 'lower-case doctype' => ['<?xml version="1.0"?><!doctype office:document-content [<!ENTITY xxe "x">]>'];
	}

	#[DataProvider('doctypes')]
	public function testRefusesDocumentTypeDeclarations(string $prolog): void {
		$content = $prolog . '<office:document-content ' . self::NAMESPACES . '><office:body><office:spreadsheet><table:table table:name="S">'
			. '<table:table-row>' . self::cell('&xxe;') . '</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The OpenDocument spreadsheet is damaged.');
		$this->read($this->zip(['mimetype' => OdsReader::MIMETYPE, 'content.xml' => $content]));
	}

	public function testRefusesAPartThatUnpacksTooLarge(): void {
		// Compresses to a few kilobytes, unpacks to more than the limit (ZIP bomb).
		$content = self::content(['S' => str_repeat(' ', XlsxReader::MAX_PART_BYTES)]);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The OpenDocument spreadsheet is too large to import.');
		$this->read($this->zip(['mimetype' => OdsReader::MIMETYPE, 'content.xml' => $content]));
	}

	/**
	 * Many empty elements in one cell: within the size of a part and of the
	 * text, but the DOM of libxml would take hundreds of megabytes outside
	 * the memory limit of PHP. Refused before the DOM is built (S N18).
	 */
	public function testRefusesAPartWithTooManyElements(): void {
		$content = self::content(['S' => '<table:table-row><table:table-cell><text:p>' . str_repeat('<text:span/>', XlsxReader::MAX_ELEMENTS)
			. '</text:p></table:table-cell></table:table-row>']);
		self::assertLessThan(XlsxReader::MAX_PART_BYTES, strlen($content));
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The OpenDocument spreadsheet is too large to import.');
		$this->read($this->zip(['mimetype' => OdsReader::MIMETYPE, 'content.xml' => $content]));
	}

	/**
	 * A few bytes that would stand for more cells or text than allowed:
	 * refused before anything is filled in.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function repeats(): iterable {
		yield 'a value beyond the last column' => ['<table:table-row><table:table-cell table:number-columns-repeated="16384"/>' . self::cell('far') . '</table:table-row>'];
		yield 'a value repeated beyond the last column' => ['<table:table-row>' . self::cell('x', ' table:number-columns-repeated="16385"') . '</table:table-row>'];
		yield 'a row repeated into millions of cells' => ['<table:table-row table:number-rows-repeated="999999999999">' . self::cell('x', ' table:number-columns-repeated="16384"') . '</table:table-row>'];
		yield 'millions of empty rows before a value' => ['<table:table-row table:number-rows-repeated="2000000"><table:table-cell/></table:table-row>'
			. '<table:table-row>' . self::cell('x') . '</table:table-row>'];
		yield 'spaces standing for gigabytes' => ['<table:table-row><table:table-cell><text:p>' . str_repeat('<text:s text:c="1000000000"/>', 25000)
			. '</text:p></table:table-cell></table:table-row>'];
		yield 'a long text in many cells' => ['<table:table-row table:number-rows-repeated="100">' . self::cell(str_repeat('x', 300000), ' table:number-columns-repeated="1000"') . '</table:table-row>'];
	}

	#[DataProvider('repeats')]
	public function testRepeatsCannotInflateTheSpreadsheet(string $rows): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The OpenDocument spreadsheet is too large to import.');
		$this->read($this->ods(['S' => $rows]));
	}

	public function testReadsACellInTheLastColumn(): void {
		$rows = $this->read($this->ods(['S' => '<table:table-row><table:table-cell table:number-columns-repeated="16383"/>' . self::cell('7') . '</table:table-row>']))[0]['rows'];
		self::assertCount(XlsxReader::MAX_COLUMNS, $rows[0]);
		self::assertSame('7', $rows[0][XlsxReader::MAX_COLUMNS - 1]);
	}
}
