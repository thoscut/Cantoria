<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCP\ITempManager;

/**
 * Reads the cell values of an OpenDocument spreadsheet (.ods), as
 * LibreOffice, Nextcloud Office and Excel write it, without external
 * libraries (U32): the text of each cell, numbers and dates as their stored
 * value rather than as formatted (`1`, `2026-11-03`), truth values as `1`
 * and `0`. Formulas are read as their cached values; formatting, comments
 * and hidden state are ignored.
 *
 * Uploaded spreadsheets are untrusted, and the reader keeps the limits of
 * XlsxReader: no document type declarations (no entities, no external
 * loading), a bounded unpacked size per part and in total (ZIP bombs),
 * bounded columns and cells, and the text of all cells together at most as
 * large as a CSV file may be. The format writes runs of equal cells and rows
 * once with a repeat count (LibreOffice repeats the empty cell up to the
 * last column); runs are counted against the limits before they are filled
 * in, and empty cells and rows at the end are left out.
 */
class OdsReader {
	private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/** Content of the entry `mimetype` of a spreadsheet */
	public const MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';
	/** Spaces one `text:s` element may stand for */
	private const MAX_SPACES = 1000;

	private int $cells = 0;
	private int $bytes = 0;

	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * Sheets in document order, like XlsxReader::read().
	 *
	 * @return list<array{name: string, rows: list<list<string>>}>
	 * @throws InvalidInputException when the file is no spreadsheet, damaged or too large
	 */
	public function read(string $content): array {
		$noSpreadsheet = new InvalidInputException('The file is not an OpenDocument spreadsheet (.ods).');
		if ($content === '') {
			throw $noSpreadsheet;
		}
		$this->cells = 0;
		$this->bytes = 0;
		$zip = BoundedZip::open($this->tempManager, $content, XlsxReader::MAX_ENTRIES, XlsxReader::MAX_PART_BYTES, XlsxReader::MAX_TOTAL_BYTES,
			self::tooLarge(), self::damagedException());
		if ($zip === null) {
			throw $noSpreadsheet;
		}
		try {
			$mimetype = $zip->part('mimetype');
			if (($mimetype !== null && trim($mimetype) !== self::MIMETYPE) || !$zip->has('content.xml')) {
				throw $noSpreadsheet;
			}
			$document = self::document($zip->part('content.xml') ?? self::damaged());
			$root = $document->documentElement;
			$spreadsheet = null;
			foreach (self::children($root, self::NS_OFFICE, 'body') as $body) {
				$spreadsheet = self::children($body, self::NS_OFFICE, 'spreadsheet')[0] ?? null;
			}
			if ($root === null || $root->namespaceURI !== self::NS_OFFICE || $spreadsheet === null) {
				throw $noSpreadsheet;
			}
			$sheets = [];
			foreach (self::children($spreadsheet, self::NS_TABLE, 'table') as $table) {
				$sheets[] = ['name' => $table->getAttributeNS(self::NS_TABLE, 'name'), 'rows' => $this->rows($table)];
			}
			return $sheets;
		} finally {
			$zip->close();
		}
	}

	/**
	 * The rows of a sheet, also those in groups of rows and in the rows
	 * repeated on every printed page.
	 *
	 * @return list<list<string>>
	 */
	private function rows(\DOMElement $table): array {
		$rows = [];
		// Empty rows not yet added: an empty run at the end is left out
		$pending = 0;
		foreach (self::rowElements($table) as $element) {
			$cells = $this->cells($element);
			$repeat = self::repeat($element, 'number-rows-repeated');
			if ($cells === []) {
				$pending += $repeat;
				continue;
			}
			// An empty row counts as one cell, as in XlsxReader
			$this->count($pending, 0);
			for ($i = 0; $i < $pending; $i++) {
				$rows[] = [];
			}
			$pending = 0;
			$this->count(count($cells) * $repeat, self::textBytes($cells) * $repeat);
			for ($i = 0; $i < $repeat; $i++) {
				$rows[] = $cells;
			}
		}
		return $rows;
	}

	/**
	 * The row elements of a sheet in their order.
	 *
	 * @return list<\DOMElement>
	 */
	private static function rowElements(\DOMElement $parent): array {
		$rows = [];
		foreach ($parent->childNodes as $child) {
			if (!$child instanceof \DOMElement || $child->namespaceURI !== self::NS_TABLE) {
				continue;
			}
			if ($child->localName === 'table-row') {
				$rows[] = $child;
			} elseif (in_array($child->localName, ['table-header-rows', 'table-rows', 'table-row-group'], true)) {
				array_push($rows, ...self::rowElements($child));
			}
		}
		return $rows;
	}

	/**
	 * The cells of a row up to its last value; empty for a row without one.
	 *
	 * @return list<string>
	 */
	private function cells(\DOMElement $row): array {
		$cells = [];
		// Empty cells not yet added: an empty run at the end is left out
		$pending = 0;
		foreach ($row->childNodes as $cell) {
			if (!$cell instanceof \DOMElement || $cell->namespaceURI !== self::NS_TABLE
				|| !in_array($cell->localName, ['table-cell', 'covered-table-cell'], true)) {
				continue;
			}
			$repeat = self::repeat($cell, 'number-columns-repeated');
			$value = $this->value($cell);
			if ($value === '') {
				$pending += $repeat;
				if ($pending > XlsxReader::MAX_COLUMNS) {
					// nothing is added yet; a value after it would be beyond the last column
					$pending = XlsxReader::MAX_COLUMNS + 1;
				}
				continue;
			}
			if (count($cells) + $pending + $repeat > XlsxReader::MAX_COLUMNS) {
				throw self::tooLarge();
			}
			for ($i = 0; $i < $pending; $i++) {
				$cells[] = '';
			}
			$pending = 0;
			for ($i = 0; $i < $repeat; $i++) {
				$cells[] = $value;
			}
		}
		return $cells;
	}

	/**
	 * The value of a cell: numbers, dates and truth values as stored,
	 * everything else as its text.
	 */
	private function value(\DOMElement $cell): string {
		$stored = match ($cell->getAttributeNS(self::NS_OFFICE, 'value-type')) {
			'float', 'percentage', 'currency' => $cell->getAttributeNS(self::NS_OFFICE, 'value'),
			// a date without a time of day: 2026-11-03T00:00:00 is 2026-11-03
			'date' => preg_replace('/T00:00:00(\.0+)?$/', '', $cell->getAttributeNS(self::NS_OFFICE, 'date-value')) ?? '',
			'boolean' => match (strtolower($cell->getAttributeNS(self::NS_OFFICE, 'boolean-value'))) {
				'true' => '1',
				'false' => '0',
				default => '',
			},
			default => '',
		};
		if ($stored !== '') {
			return $stored;
		}
		$paragraphs = [];
		foreach ($cell->childNodes as $child) {
			// Only the paragraphs of the cell itself, not those of a comment (office:annotation)
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_TEXT && in_array($child->localName, ['p', 'h'], true)) {
				$paragraphs[] = $this->text($child);
			}
		}
		return implode("\n", $paragraphs);
	}

	/**
	 * The text of a paragraph with its spaces, tabs and line breaks, without
	 * notes and comments.
	 */
	private function text(\DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMText) {
				$text .= $child->data;
				continue;
			}
			if (!$child instanceof \DOMElement) {
				continue;
			}
			if ($child->namespaceURI === self::NS_TEXT) {
				$text .= match ($child->localName) {
					's' => $this->spaces($child),
					'tab' => "\t",
					'line-break' => "\n",
					'note', 'bookmark', 'bookmark-start', 'bookmark-end', 'reference-mark', 'soft-page-break' => '',
					default => $this->text($child),
				};
			} elseif ($child->namespaceURI !== self::NS_OFFICE) {
				// office:annotation is a comment; other namespaces may wrap text
				$text .= $this->text($child);
			}
		}
		return $text;
	}

	/**
	 * The spaces of a `text:s` element, at most MAX_SPACES, counted against
	 * the size of all text, so that a few bytes cannot stand for gigabytes.
	 */
	private function spaces(\DOMElement $element): string {
		$count = $element->hasAttributeNS(self::NS_TEXT, 'c') ? (int)$element->getAttributeNS(self::NS_TEXT, 'c') : 1;
		$count = max(1, min(self::MAX_SPACES, $count));
		$this->count(0, $count);
		return str_repeat(' ', $count);
	}

	/**
	 * Counts cells and bytes of text against the limits, before they are
	 * added.
	 *
	 * @throws InvalidInputException
	 */
	private function count(int $cells, int $bytes): void {
		$this->cells += $cells;
		$this->bytes += $bytes;
		if ($this->cells > XlsxReader::MAX_CELLS || $this->bytes > XlsxReader::MAX_TEXT_BYTES) {
			throw self::tooLarge();
		}
	}

	/**
	 * @param list<string> $cells
	 */
	private static function textBytes(array $cells): int {
		return array_sum(array_map('strlen', $cells));
	}

	/**
	 * A repeat count of a cell or row; at least 1, and above the number of
	 * cells allowed only by one, so that counting cannot overflow.
	 */
	private static function repeat(\DOMElement $element, string $attribute): int {
		$value = $element->getAttributeNS(self::NS_TABLE, $attribute);
		return $value === '' ? 1 : max(1, min(XlsxReader::MAX_CELLS + 1, (int)$value));
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function children(?\DOMElement $parent, string $namespace, string $name): array {
		$found = [];
		foreach ($parent?->childNodes ?? [] as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === $namespace && $child->localName === $name) {
				$found[] = $child;
			}
		}
		return $found;
	}

	private static function tooLarge(): InvalidInputException {
		return new InvalidInputException('The OpenDocument spreadsheet is too large to import.');
	}

	private static function damagedException(): InvalidInputException {
		return new InvalidInputException('The OpenDocument spreadsheet is damaged.');
	}

	private static function damaged(): never {
		throw self::damagedException();
	}

	private static function document(string $data): \DOMDocument {
		// Spreadsheets never need a document type declaration; refusing it
		// rules out entity expansion and external entities (XXE) altogether.
		if ($data === '' || stripos($data, '<!DOCTYPE') !== false) {
			self::damaged();
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$loaded = $document->loadXML($data, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded || $document->doctype !== null) {
			self::damaged();
		}
		return $document;
	}
}
