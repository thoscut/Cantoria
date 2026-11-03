<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCP\ITempManager;

/**
 * Writes a minimal OpenDocument spreadsheet (.ods) with one or more sheets of
 * plain text cells. Enough for LibreOffice and Nextcloud Office to open, edit
 * and keep the catalog without the app. Columns named in `text` are formatted
 * as text (`@`), so that a spreadsheet program keeps what is typed into them
 * later, `1-3` as a shelf mark instead of the 3rd of January (U44).
 */
class OdsWriter {
	/** Names of the text format and of the cell style that uses it */
	private const TEXT_FORMAT = 'N100';
	private const TEXT_CELL = 'ce1';

	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * @param list<array{name: string, rows: list<list<string>>, text?: list<int>}> $sheets in order; the first row is the header;
	 *                                                                             `text`: indexes of the columns formatted as text
	 * @return string binary .ods content
	 */
	public function write(array $sheets): string {
		$path = $this->tempManager->getTemporaryFile('.ods');
		$zip = new \ZipArchive();
		if ($path === false || $zip->open($path, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Cannot create ODS file');
		}
		// The mimetype entry must come first and be stored uncompressed.
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
		$zip->addFromString('META-INF/manifest.xml', self::manifest());
		$zip->addFromString('styles.xml', self::styles());
		$zip->addFromString('content.xml', self::content($sheets));
		$zip->close();
		$data = file_get_contents($path);
		@unlink($path);
		if ($data === false) {
			throw new \RuntimeException('Cannot read ODS file');
		}
		return $data;
	}

	/**
	 * @param list<array{name: string, rows: list<list<string>>, text?: list<int>}> $sheets
	 */
	private static function content(array $sheets): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
			. ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
			. ' xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.3">'
			// the number format "@" (text) and a cell style with it
			. '<office:automatic-styles><number:text-style style:name="' . self::TEXT_FORMAT . '"><number:text-content/></number:text-style>'
			. '<style:style style:name="' . self::TEXT_CELL . '" style:family="table-cell" style:parent-style-name="Default"'
			. ' style:data-style-name="' . self::TEXT_FORMAT . '"/></office:automatic-styles>'
			. '<office:body><office:spreadsheet>';
		foreach ($sheets as $sheet) {
			['name' => $name, 'rows' => $rows] = $sheet;
			$text = array_flip($sheet['text'] ?? []);
			$xml .= '<table:table table:name="' . self::esc($name) . '">';
			if ($text !== []) {
				$xml .= self::columns(array_keys($text));
			}
			foreach ($rows as $row) {
				$xml .= '<table:table-row>';
				foreach ($row as $index => $cell) {
					$style = isset($text[$index]) ? ' table:style-name="' . self::TEXT_CELL . '"' : '';
					$xml .= $cell === ''
						? '<table:table-cell' . $style . '/>'
						: '<table:table-cell' . $style . ' office:value-type="string"><text:p>' . self::esc($cell) . '</text:p></table:table-cell>';
				}
				$xml .= '</table:table-row>';
			}
			$xml .= '</table:table>';
		}
		return $xml . '</office:spreadsheet></office:body></office:document-content>';
	}

	/**
	 * The column declarations of a sheet up to the last text column: the
	 * text columns with the text style as their default, the others plain.
	 *
	 * @param list<int> $text indexes of the text columns
	 */
	private static function columns(array $text): string {
		sort($text);
		$xml = '';
		$next = 0;
		foreach ($text as $index) {
			if ($index > $next) {
				$xml .= '<table:table-column table:number-columns-repeated="' . ($index - $next) . '"/>';
			}
			$xml .= '<table:table-column table:default-cell-style-name="' . self::TEXT_CELL . '"/>';
			$next = $index + 1;
		}
		return $xml;
	}

	private static function manifest(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>';
	}

	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.3"/>';
	}

	/**
	 * Escapes text for XML and drops characters XML 1.0 does not allow.
	 */
	private static function esc(string $text): string {
		$text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
