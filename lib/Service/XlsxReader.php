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
 * Reads the cell values of an .xlsx workbook (Office Open XML) without
 * external libraries: shared strings, inline strings and numbers. Formulas
 * are read as their cached values. Of the formatting only one thing counts
 * (W B24): a number in a cell formatted as a date is a day, and comes back
 * as ISO date (`2026-11-03`, with ` 14:30` when the format shows a time of
 * day), as the OpenDocument reader does; Excel stores it as a serial number.
 *
 * Uploaded workbooks are untrusted: the reader refuses document type
 * declarations (no entities, no external loading), unpacks at most a bounded
 * size per part and in total (ZIP bombs), and limits columns, cells and the
 * text of all cells together (one shared string can fill many cells), so
 * that a small file cannot exhaust the memory of the server.
 */
class XlsxReader {
	private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/** Entries of the ZIP archive */
	public const MAX_ENTRIES = 10000;
	/** Unpacked size of one part that is read (bytes) */
	public const MAX_PART_BYTES = 16 * 1024 * 1024;
	/** Unpacked size of all parts read together (bytes) */
	public const MAX_TOTAL_BYTES = 48 * 1024 * 1024;
	/** Columns of a sheet, as in Excel (A … XFD) */
	public const MAX_COLUMNS = 16384;
	/** Cells of all sheets together, including filled gaps; an empty row counts as one */
	public const MAX_CELLS = 1000000;
	/** Text of all cells together (bytes): as much as a CSV file for an import may hold */
	public const MAX_TEXT_BYTES = UserFiles::MAX_IMPORT_BYTES;

	/**
	 * Built-in number formats that show a date (ECMA-376, 18.8.30): true
	 * with a time of day. 14 to 17 and 22 everywhere; 27 to 31, 36, 50 to 54,
	 * 57 and 58 are dates in East Asian versions of Excel.
	 */
	private const BUILT_IN_DATE_FORMATS = [14 => false, 15 => false, 16 => false, 17 => false, 22 => true,
		27 => false, 28 => false, 29 => false, 30 => false, 31 => false, 36 => false,
		50 => false, 51 => false, 52 => false, 53 => false, 54 => false, 57 => false, 58 => false];

	private int $cells = 0;
	private int $bytes = 0;

	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * Sheets in workbook order. Names are kept as a value, not as an array
	 * key, so that a sheet named "2024" stays a string.
	 *
	 * @return list<array{name: string, rows: list<list<string>>}>
	 * @throws InvalidInputException when the file is no workbook, damaged or too large
	 */
	public function read(string $content): array {
		if ($content === '') {
			throw new InvalidInputException('The file is not an Excel workbook (.xlsx).');
		}
		$this->cells = 0;
		$this->bytes = 0;
		$zip = BoundedZip::open($this->tempManager, $content, self::MAX_ENTRIES, self::MAX_PART_BYTES, self::MAX_TOTAL_BYTES,
			self::tooLarge(), new InvalidInputException('The Excel workbook is damaged.'));
		if ($zip === null) {
			throw new InvalidInputException('The file is not an Excel workbook (.xlsx).');
		}
		try {
			if (!$zip->has('xl/workbook.xml')) {
				throw new InvalidInputException('The file is not an Excel workbook (.xlsx).');
			}
			$shared = $this->sharedStrings($zip);
			$workbook = self::xpath($zip->part('xl/workbook.xml') ?? self::damaged());
			$dates = $this->dateStyles($zip);
			$date1904 = in_array(strtolower((self::elements($workbook, '/m:workbook/m:workbookPr')[0] ?? null)?->getAttribute('date1904') ?? ''), ['1', 'true'], true);
			$rels = self::xpath($zip->part('xl/_rels/workbook.xml.rels') ?? self::damaged());
			$targets = [];
			foreach (self::elements($rels, '/p:Relationships/p:Relationship') as $rel) {
				$targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
			}
			$sheets = [];
			foreach (self::elements($workbook, '/m:workbook/m:sheets/m:sheet') as $sheet) {
				$target = ltrim($targets[$sheet->getAttributeNS(self::NS_REL, 'id')] ?? '', '/');
				$target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
				$data = $zip->part($target);
				if ($data !== null) {
					$sheets[] = ['name' => $sheet->getAttribute('name'), 'rows' => $this->rows(self::xpath($data), $shared, $dates, $date1904)];
				}
			}
			return $sheets;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Zero-based column index from a cell reference like "C12"; null for
	 * anything else, including columns with more than three letters.
	 */
	public static function columnIndex(string $reference): ?int {
		if (preg_match('/^([A-Z]{1,3})\d+$/', $reference, $m) !== 1) {
			return null;
		}
		$index = 0;
		foreach (str_split($m[1]) as $char) {
			$index = $index * 26 + (ord($char) - 64);
		}
		return $index - 1;
	}

	/**
	 * @return list<string>
	 */
	private function sharedStrings(BoundedZip $zip): array {
		$data = $zip->part('xl/sharedStrings.xml');
		if ($data === null) {
			return [];
		}
		$xpath = self::xpath($data);
		$strings = [];
		foreach (self::elements($xpath, '/m:sst/m:si') as $si) {
			$strings[] = self::text($xpath, $si);
		}
		return $strings;
	}

	/**
	 * The cell styles (`s` of a cell, an index into `cellXfs`) whose number
	 * format shows a date: true when it shows a time of day, too.
	 *
	 * @return array<int, bool>
	 */
	private function dateStyles(BoundedZip $zip): array {
		$data = $zip->part('xl/styles.xml');
		if ($data === null) {
			return [];
		}
		$xpath = self::xpath($data);
		$formats = self::BUILT_IN_DATE_FORMATS;
		foreach (self::elements($xpath, '/m:styleSheet/m:numFmts/m:numFmt') as $format) {
			$kind = self::dateFormat($format->getAttribute('formatCode'));
			$id = (int)$format->getAttribute('numFmtId');
			if ($kind === null) {
				unset($formats[$id]);
			} else {
				$formats[$id] = $kind;
			}
		}
		$styles = [];
		foreach (self::elements($xpath, '/m:styleSheet/m:cellXfs/m:xf') as $index => $xf) {
			$kind = $formats[(int)$xf->getAttribute('numFmtId')] ?? null;
			if ($kind !== null) {
				$styles[$index] = $kind;
			}
		}
		return $styles;
	}

	/**
	 * Whether a number format code shows a date (false) or a date with a
	 * time of day (true); null for anything else, a time alone included.
	 * Text in quotes, escaped characters and sections in brackets (colors,
	 * locales, elapsed hours) do not count.
	 */
	public static function dateFormat(string $code): ?bool {
		$code = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]|_.|\*./', '', explode(';', $code)[0]) ?? '';
		$code = strtolower($code);
		if (preg_match('/[dy]/', $code) !== 1) {
			return null;
		}
		return preg_match('/[hs]/', $code) === 1;
	}

	/**
	 * A serial number of Excel as ISO date: days since 30 December 1899
	 * (Excel counts a 29 February 1900 that never was, so the days before
	 * 1 March 1900 are one day off), or since 1 January 1904 in a workbook
	 * of the 1904 date system; the fraction is the time of day.
	 */
	public static function serialDate(string $value, bool $withTime, bool $date1904 = false): ?string {
		if (preg_match('/^\d+(\.\d+)?([eE][-+]?\d+)?$/', $value) !== 1) {
			return null;
		}
		$serial = (float)$value;
		$days = (int)floor($serial);
		if ($date1904) {
			$base = new \DateTimeImmutable('1904-01-01', new \DateTimeZone('UTC'));
		} else {
			if ($days < 1 || $days === 60) {
				return null;
			}
			$base = new \DateTimeImmutable($days < 60 ? '1899-12-31' : '1899-12-30', new \DateTimeZone('UTC'));
		}
		$date = $base->modify('+' . $days . ' days');
		if (!$withTime) {
			return $date->format('Y-m-d');
		}
		$minutes = (int)round(($serial - floor($serial)) * 1440.0);
		return $date->modify('+' . $minutes . ' minutes')->format($minutes === 0 ? 'Y-m-d' : 'Y-m-d H:i');
	}

	/**
	 * @param list<string> $shared
	 * @param array<int, bool> $dates cell styles that show a date (dateStyles)
	 * @return list<list<string>>
	 */
	private function rows(\DOMXPath $sheet, array $shared, array $dates = [], bool $date1904 = false): array {
		$rows = [];
		foreach (self::elements($sheet, '/m:worksheet/m:sheetData/m:row') as $row) {
			$cells = [];
			foreach (self::elements($sheet, 'm:c', $row) as $cell) {
				$index = self::columnIndex($cell->getAttribute('r')) ?? count($cells);
				if ($index >= self::MAX_COLUMNS) {
					throw self::tooLarge();
				}
				$cells[$index] = match ($cell->getAttribute('t')) {
					's' => $shared[(int)self::firstText($sheet, 'm:v', $cell)] ?? '',
					'inlineStr' => implode('', array_map(fn (\DOMElement $is): string => self::text($sheet, $is), self::elements($sheet, 'm:is', $cell))),
					'', 'n' => self::number(self::firstText($sheet, 'm:v', $cell),
						$cell->hasAttribute('s') ? ($dates[(int)$cell->getAttribute('s')] ?? null) : null, $date1904),
					default => self::firstText($sheet, 'm:v', $cell),
				};
				$this->bytes += strlen($cells[$index]);
				if ($this->bytes > self::MAX_TEXT_BYTES) {
					throw self::tooLarge();
				}
			}
			$max = $cells === [] ? -1 : max(array_keys($cells));
			// Counted before the gaps are filled, so that one far-away cell
			// cannot make the reader allocate millions of empty ones.
			$this->cells += max(1, $max + 1);
			if ($this->cells > self::MAX_CELLS) {
				throw self::tooLarge();
			}
			$filled = [];
			for ($i = 0; $i <= $max; $i++) {
				$filled[] = $cells[$i] ?? '';
			}
			$rows[] = $filled;
		}
		return $rows;
	}

	/**
	 * A number as it is stored, or as ISO date in a cell formatted as a date
	 * (`$date`: whether with the time of day; null: no date).
	 */
	private static function number(string $value, ?bool $date, bool $date1904): string {
		return $date === null || $value === '' ? $value : (self::serialDate($value, $date, $date1904) ?? $value);
	}

	/**
	 * Text of a string item: plain text or rich text runs, without phonetic
	 * hints.
	 */
	private static function text(\DOMXPath $xpath, \DOMElement $node): string {
		return implode('', array_map(fn (\DOMElement $t): string => $t->textContent, self::elements($xpath, 'm:t | m:r/m:t', $node)));
	}

	private static function firstText(\DOMXPath $xpath, string $query, \DOMElement $context): string {
		return (self::elements($xpath, $query, $context)[0] ?? null)?->textContent ?? '';
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function elements(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): array {
		$nodes = $xpath->query($query, $context);
		$elements = [];
		if ($nodes !== false) {
			for ($i = 0; $i < $nodes->length; $i++) {
				$node = $nodes->item($i);
				if ($node instanceof \DOMElement) {
					$elements[] = $node;
				}
			}
		}
		return $elements;
	}

	private static function tooLarge(): InvalidInputException {
		return new InvalidInputException('The Excel workbook is too large to import.');
	}

	private static function damaged(): never {
		throw new InvalidInputException('The Excel workbook is damaged.');
	}

	private static function xpath(string $data): \DOMXPath {
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
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('m', self::NS_MAIN);
		$xpath->registerNamespace('p', self::NS_PACKAGE_REL);
		return $xpath;
	}
}
