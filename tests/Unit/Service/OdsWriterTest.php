<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\OdsWriter;
use OCA\Cantoria\Tests\Unit\Support\TempFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(OdsWriter::class)]
#[RequiresPhpExtension('zip')]
final class OdsWriterTest extends TestCase {
	use TempFiles;

	private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	private function write(): \ZipArchive {
		$ods = (new OdsWriter($this->tempManager()))->write([
			['name' => 'Catalog', 'rows' => [['Signatur', 'Titel'], ['5-1', 'Ave <verum> & "corpus"'], ['5-2', '']]],
			['name' => '2024', 'rows' => [['Control' . "\x01" . 'character', "line\nbreak"]]],
		]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($this->tempFile($ods)));
		return $zip;
	}

	public function testMimetypeComesFirstAndUncompressed(): void {
		$zip = $this->write();
		$first = $zip->statIndex(0);
		self::assertIsArray($first);
		self::assertSame('mimetype', $first['name']);
		self::assertSame(\ZipArchive::CM_STORE, $first['comp_method']);
		self::assertSame('application/vnd.oasis.opendocument.spreadsheet', $zip->getFromName('mimetype'));
		self::assertNotFalse($zip->getFromName('META-INF/manifest.xml'));
		self::assertNotFalse($zip->getFromName('styles.xml'));
	}

	public function testContentHoldsSheetsAndCellsAsText(): void {
		$content = (string)$this->write()->getFromName('content.xml');
		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($content), 'content.xml is well-formed XML');

		$tables = $document->getElementsByTagNameNS(self::NS_TABLE, 'table');
		self::assertSame(2, $tables->length);
		$names = [];
		$cells = [];
		foreach ($tables as $table) {
			self::assertInstanceOf(\DOMElement::class, $table);
			$names[] = $table->getAttributeNS(self::NS_TABLE, 'name');
			$rows = [];
			foreach ($table->getElementsByTagNameNS(self::NS_TABLE, 'table-row') as $row) {
				self::assertInstanceOf(\DOMElement::class, $row);
				$texts = [];
				foreach ($row->getElementsByTagNameNS(self::NS_TABLE, 'table-cell') as $cell) {
					$texts[] = $cell->textContent;
				}
				$rows[] = $texts;
			}
			$cells[] = $rows;
		}
		self::assertSame(['Catalog', '2024'], $names);
		self::assertSame([['Signatur', 'Titel'], ['5-1', 'Ave <verum> & "corpus"'], ['5-2', '']], $cells[0]);
		self::assertSame([['Controlcharacter', "line\nbreak"]], $cells[1], 'characters not allowed in XML are dropped');
		self::assertStringContainsString('<table:table-cell/>', $content, 'empty cells stay empty');
	}

	/**
	 * U44: a column named in `text` is formatted as text, so that a
	 * spreadsheet program keeps 1-3 as typed.
	 */
	public function testATextColumnIsFormattedAsText(): void {
		$ods = (new OdsWriter($this->tempManager()))->write([['name' => 'Werkverzeichnis', 'rows' => [['Mappe', 'Nr', 'Signatur'], ['1', '1', '1-1']], 'text' => [2]]]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($this->tempFile($ods)));
		$content = (string)$zip->getFromName('content.xml');
		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($content));
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('table', self::NS_TABLE);
		$xpath->registerNamespace('style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
		$xpath->registerNamespace('number', 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0');
		$format = $xpath->evaluate('string(//number:text-style[number:text-content]/@style:name)');
		$style = $xpath->evaluate('string(//style:style[@style:data-style-name="' . $format . '"]/@style:name)');
		self::assertNotSame('', $style, 'a cell style with the text format');
		self::assertSame(['2', $style], [$xpath->evaluate('string(//table:table-column[1]/@table:number-columns-repeated)'),
			$xpath->evaluate('string(//table:table-column[2]/@table:default-cell-style-name)')], 'the third column');
		self::assertSame($style, $xpath->evaluate('string(//table:table-row[2]/table:table-cell[3]/@table:style-name)'));
		self::assertSame('', $xpath->evaluate('string(//table:table-row[2]/table:table-cell[1]/@table:style-name)'));
	}

	public function testRemovesItsTemporaryFile(): void {
		$created = [];
		$manager = $this->createStub(\OCP\ITempManager::class);
		$manager->method('getTemporaryFile')->willReturnCallback(function () use (&$created): string {
			$path = $this->tempFile('');
			$created[] = $path;
			return $path;
		});
		(new OdsWriter($manager))->write([['name' => 'Sheet', 'rows' => [['a']]]]);
		self::assertCount(1, $created);
		self::assertFileDoesNotExist($created[0]);
	}
}
