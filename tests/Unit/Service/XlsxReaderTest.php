<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\XlsxReader;
use OCA\Cantoria\Tests\Unit\Support\TempFiles;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Reads a small synthetic workbook in the structure Excel and LibreOffice
 * write: shared strings (plain and rich text), inline strings, numbers,
 * gaps between cells and a sheet named like a year.
 */
#[CoversClass(XlsxReader::class)]
#[RequiresPhpExtension('zip')]
final class XlsxReaderTest extends TestCase {
	use TempFiles;

	private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * @return array<string, string>
	 */
	private static function workbook(): array {
		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="' . self::MAIN . '" xmlns:r="' . self::REL . '"><sheets>'
			. '<sheet name="Programme" sheetId="1" r:id="rId1"/>'
			. '<sheet name="2024" sheetId="2" r:id="rId2"/>'
			. '</sheets></workbook>';
		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="' . self::PACKAGE_REL . '">'
			. '<Relationship Id="rId1" Type="' . self::REL . '/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="' . self::REL . '/worksheet" Target="/xl/worksheets/sheet2.xml"/>'
			. '<Relationship Id="rId3" Type="' . self::REL . '/sharedStrings" Target="sharedStrings.xml"/>'
			. '</Relationships>';
		$shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<sst xmlns="' . self::MAIN . '" count="4" uniqueCount="4">'
			. '<si><t>Datum</t></si>'
			. '<si><t>Titel laut Programm</t></si>'
			. '<si><r><rPr><b/></rPr><t>Ave </t></r><r><t xml:space="preserve">verum corpus</t></r></si>'
			. '<si><t>漢字</t><rPh sb="0" eb="2"><t>かんじ</t></rPh></si>'
			. '</sst>';
		$sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="' . self::MAIN . '"><sheetData>'
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
			. '<row r="2"><c r="A2"><v>45951</v></c><c r="B2" t="s"><v>2</v></c><c r="D2" t="inlineStr"><is><t>inline &amp; text</t></is></c></row>'
			. '<row r="3"><c r="B3" t="s"><v>3</v></c><c r="C3" t="b"><v>1</v></c><c r="E3"><f>1+1</f><v>2</v></c></row>'
			. '<row r="4"/>'
			. '</sheetData></worksheet>';
		$sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="' . self::MAIN . '"><sheetData>'
			. '<row r="1"><c t="inlineStr"><is><t>first</t></is></c><c t="inlineStr"><is><t>second</t></is></c></row>'
			. '</sheetData></worksheet>';
		return [
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'xl/workbook.xml' => $workbook,
			'xl/_rels/workbook.xml.rels' => $rels,
			'xl/sharedStrings.xml' => $shared,
			'xl/worksheets/sheet1.xml' => $sheet1,
			'xl/worksheets/sheet2.xml' => $sheet2,
		];
	}

	public function testReadsSheetsInWorkbookOrder(): void {
		$sheets = (new XlsxReader($this->tempManager()))->read($this->zip(self::workbook()));

		self::assertSame(['Programme', '2024'], array_column($sheets, 'name'), 'a sheet named like a year stays a string');
		self::assertSame([
			['Datum', 'Titel laut Programm'],
			['45951', 'Ave verum corpus', '', 'inline & text'],
			['', '漢字', '1', '', '2'],
			[],
		], $sheets[0]['rows']);
		self::assertSame([['first', 'second']], $sheets[1]['rows'], 'cells without reference are placed one after the other');
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
		try {
			(new XlsxReader($manager))->read($valid ? $this->zip(self::workbook()) : 'no workbook');
		} catch (InvalidInputException) {
		}
		self::assertCount(1, $created);
		self::assertFileDoesNotExist($created[0]);
	}

	public function testWorkbookWithoutSharedStrings(): void {
		$entries = self::workbook();
		unset($entries['xl/sharedStrings.xml']);
		$sheets = (new XlsxReader($this->tempManager()))->read($this->zip($entries));
		self::assertSame(['', ''], $sheets[0]['rows'][0], 'unknown shared strings are empty');
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function brokenFiles(): iterable {
		yield 'not a ZIP archive' => ['Mappe;Nr;Signatur', 'The file is not an Excel workbook (.xlsx).'];
		yield 'empty' => ['', 'The file is not an Excel workbook (.xlsx).'];
	}

	#[DataProvider('brokenFiles')]
	public function testRefusesFilesThatAreNoWorkbook(string $content, string $message): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage($message);
		(new XlsxReader($this->tempManager()))->read($content);
	}

	public function testRefusesAZipWithoutWorkbook(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not an Excel workbook (.xlsx).');
		(new XlsxReader($this->tempManager()))->read($this->zip(['word/document.xml' => '<document/>']));
	}

	public function testRefusesDamagedXml(): void {
		$entries = self::workbook();
		$entries['xl/workbook.xml'] = '<workbook><sheets>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is damaged.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testRefusesAWorkbookWithoutRelationships(): void {
		$entries = self::workbook();
		unset($entries['xl/_rels/workbook.xml.rels']);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is damaged.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	// ---- untrusted workbooks ----

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function doctypes(): iterable {
		yield 'external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<sst xmlns="' . self::MAIN . '"><si><t>&xxe;</t></si></sst>'];
		yield 'entity expansion' => ['<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
			. '<sst xmlns="' . self::MAIN . '"><si><t>&b;</t></si></sst>'];
		yield 'lower-case doctype' => ['<?xml version="1.0"?><!doctype sst><sst xmlns="' . self::MAIN . '"/>'];
	}

	#[DataProvider('doctypes')]
	public function testRefusesDocumentTypeDeclarations(string $sharedStrings): void {
		$entries = self::workbook();
		$entries['xl/sharedStrings.xml'] = $sharedStrings;
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is damaged.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testRefusesAPartThatUnpacksTooLarge(): void {
		$entries = self::workbook();
		// Compresses to a few kilobytes, unpacks to more than the limit (ZIP bomb).
		$entries['xl/sharedStrings.xml'] = '<sst xmlns="' . self::MAIN . '">' . str_repeat(' ', XlsxReader::MAX_PART_BYTES) . '</sst>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is too large to import.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testRefusesACellBeyondTheLastColumn(): void {
		$entries = self::workbook();
		$entries['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="' . self::MAIN . '"><sheetData>'
			. '<row r="1"><c r="XFE1" t="inlineStr"><is><t>far away</t></is></c></row></sheetData></worksheet>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is too large to import.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testAFarCellCannotInflateTheWorkbook(): void {
		// Each row holds one cell in the last column: a few bytes per row,
		// but the gaps would add up to more cells than allowed.
		$rows = (int)ceil(XlsxReader::MAX_CELLS / XlsxReader::MAX_COLUMNS) + 1;
		$entries = self::workbook();
		$entries['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="' . self::MAIN . '"><sheetData>'
			. str_repeat('<row><c r="XFD1"><v>1</v></c></row>', $rows) . '</sheetData></worksheet>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is too large to import.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testOneSharedStringCannotFillTheMemory(): void {
		// One long shared string in many cells: a small file, but more text
		// than a CSV file for an import may hold.
		$entries = self::workbook();
		$entries['xl/sharedStrings.xml'] = '<sst xmlns="' . self::MAIN . '"><si><t>' . str_repeat('x', 1024 * 1024) . '</t></si></sst>';
		$cells = (int)ceil(XlsxReader::MAX_TEXT_BYTES / (1024 * 1024)) + 1;
		$entries['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="' . self::MAIN . '"><sheetData><row>'
			. str_repeat('<c t="s"><v>0</v></c>', $cells) . '</row></sheetData></worksheet>';
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The Excel workbook is too large to import.');
		(new XlsxReader($this->tempManager()))->read($this->zip($entries));
	}

	public function testReadsACellInTheLastColumn(): void {
		$entries = self::workbook();
		$entries['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="' . self::MAIN . '"><sheetData>'
			. '<row r="1"><c r="XFD1"><v>7</v></c></row></sheetData></worksheet>';
		$rows = (new XlsxReader($this->tempManager()))->read($this->zip($entries))[1]['rows'];
		self::assertCount(XlsxReader::MAX_COLUMNS, $rows[0]);
		self::assertSame('7', $rows[0][XlsxReader::MAX_COLUMNS - 1]);
	}

	/**
	 * @return iterable<string, array{0: string, 1: ?int}>
	 */
	public static function references(): iterable {
		yield 'A1' => ['A1', 0];
		yield 'C12' => ['C12', 2];
		yield 'Z3' => ['Z3', 25];
		yield 'AA1' => ['AA1', 26];
		yield 'AZ9' => ['AZ9', 51];
		yield 'XFD1, the last column of Excel' => ['XFD1', 16383];
		yield 'more than three letters' => ['AAAA1', null];
		yield 'lower case' => ['a1', null];
		yield 'no row' => ['B', null];
		yield 'empty' => ['', null];
	}

	#[DataProvider('references')]
	public function testColumnIndex(string $reference, ?int $index): void {
		self::assertSame($index, XlsxReader::columnIndex($reference));
	}

	/**
	 * W B24: number formats that show a date, and Excel's serial numbers.
	 */
	public function testDateFormatsAndSerialNumbers(): void {
		self::assertFalse(XlsxReader::dateFormat('dd/mm/yyyy'));
		self::assertFalse(XlsxReader::dateFormat('[$-407]dddd, d. mmmm yyyy'));
		self::assertTrue(XlsxReader::dateFormat('d.m.yy h:mm'));
		self::assertNull(XlsxReader::dateFormat('[h]:mm'), 'a time alone');
		self::assertNull(XlsxReader::dateFormat('General'));
		self::assertNull(XlsxReader::dateFormat('0 "days"'), 'text in quotes does not count');
		self::assertNull(XlsxReader::dateFormat('@'));
		self::assertSame('2026-11-03', XlsxReader::serialDate('46329', false));
		self::assertSame('2026-11-03 14:30', XlsxReader::serialDate('46329.6041667', true));
		self::assertSame('2026-11-03', XlsxReader::serialDate('46329', true), 'midnight without a time');
		self::assertSame(['1900-01-01', '1900-02-28', '1900-03-01'], [XlsxReader::serialDate('1', false), XlsxReader::serialDate('59', false),
			XlsxReader::serialDate('61', false)], 'around the 29 February 1900 that Excel counts');
		self::assertSame('2030-11-04', XlsxReader::serialDate('46329', false, true), 'the 1904 date system');
		self::assertNull(XlsxReader::serialDate('-3', false));
		self::assertNull(XlsxReader::serialDate('abc', false));
	}
}
