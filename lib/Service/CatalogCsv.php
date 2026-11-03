<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;

/**
 * Reading and writing the catalog as CSV.
 *
 * The legacy catalog format ("Werkverzeichnis") has German column headers and
 * German values for the rights status. It is kept exactly: UTF-8 with BOM,
 * `;` as separator, CRLF line endings and quotes only where needed, so that an
 * import followed by an export reproduces the same file byte for byte (with
 * the current header of a renamed column, FORMER_HEADERS). PHP's fputcsv is
 * not suitable because it also quotes fields containing spaces.
 *
 * The extended format appends every other field as `level.field` columns.
 */
final class CatalogCsv {
	public const BOM = "\xEF\xBB\xBF";

	/** First characters that make spreadsheet programs read a cell as a formula */
	private const FORMULA_START = "=+-@\t\r";

	/**
	 * Columns of the legacy catalog in their order: header => [level, field].
	 */
	public const LEGACY_COLUMNS = [
		'Mappe' => [Fields::COPY, 'binder'],
		'Nr' => [Fields::COPY, 'number'],
		'Signatur' => [Fields::COPY, 'shelfmark'],
		'Titel' => [Fields::WORK, 'title'],
		'Komponist' => [Fields::WORK, 'composer'],
		'Bearbeiter/Text' => [Fields::VERSION, 'arranger_text'],
		'Anmerkung' => [Fields::VERSION, 'notes'],
		'Rechte-Ersteinschätzung' => [Fields::VERSION, 'rights_assessment'],
		'Rechtestatus' => [Fields::VERSION, 'rights_status'],
		'Rechte bestätigt' => [Fields::VERSION, 'rights_confirmed'],
		'Im Chorportal seit' => [Fields::VERSION, 'portal_since'],
		'Verlag / Bestellnummer' => [Fields::VERSION, 'publisher'],
		'Lizenz' => [Fields::VERSION, 'license'],
		'Erwerbsbeleg' => [Fields::VERSION, 'proof_of_purchase'],
		'Fassungen' => [Fields::COPY, 'versions_count'],
		'PDF-Dateien' => [Fields::COPY, 'pdf_files'],
		'Titelquelle' => [Fields::COPY, 'title_source'],
		'Seiten' => [Fields::COPY, 'pages'],
		'Formate' => [Fields::COPY, 'scan_formats'],
		'Dateien' => [Fields::COPY, 'scan_files'],
		'MusicXML' => [Fields::VERSION, 'musicxml_file'],
		'MusicXML-gilt' => [Fields::VERSION, 'musicxml_valid'],
		'MusicXML-Stand' => [Fields::VERSION, 'musicxml_date'],
		'MusicXML-Herkunft' => [Fields::VERSION, 'musicxml_origin'],
		'MusicXML-Prüfstand' => [Fields::VERSION, 'musicxml_review'],
		'Veröffentlichung' => [Fields::VERSION, 'publication'],
		'Veröffentlichung-Begründung' => [Fields::VERSION, 'publication_reason'],
		'Repertoire' => [Fields::WORK, 'repertoire_import'],
		'Aufführungen belegt' => [Fields::WORK, 'performances_import'],
		'Zuletzt aufgeführt' => [Fields::WORK, 'last_performed_import'],
		'Notenliste' => [Fields::WORK, 'song_lists_import'],
	];

	/**
	 * The example row of the import template (template()): an invented copy
	 * of a public domain work, header => value. Every other column stays empty.
	 * Its note says how a shelf mark is formed (W B25). The import leaves the
	 * row out while title, composer and note are those of the example
	 * (isTemplateExample(), W B28), so that a forgotten example neither
	 * enters the catalog nor pushes out a real copy 1-1.
	 */
	public const TEMPLATE_EXAMPLE = [
		'Mappe' => '1',
		'Nr' => '1',
		'Signatur' => '1-1',
		'Titel' => 'Abendlied',
		'Komponist' => 'Josef Rheinberger',
		'Bearbeiter/Text' => 'Text: Lukas 24,29',
		'Anmerkung' => 'Beispielzeile, wird nicht importiert. Signatur immer Mappe-Nummer, z. B. 12-3',
		'Rechte-Ersteinschätzung' => 'Komponist gestorben 1901',
		'Rechtestatus' => 'gemeinfrei',
		'Seiten' => '4',
	];

	/**
	 * Former headers that the import still reads as the current one: the
	 * column of the singers' portal had the name of one provider.
	 */
	public const FORMER_HEADERS = [
		'Auf Singste seit' => 'Im Chorportal seit',
	];

	/** Legacy values of the rights status column => choice key */
	public const LEGACY_RIGHTS_STATUS = [
		'gemeinfrei' => 'public_domain',
		'geschützt' => 'protected',
		'offen' => 'open',
	];

	/**
	 * Columns for import and export: the legacy columns in their order and, in
	 * the extended format, every other field as `level.field`.
	 *
	 * @return array<string, array{0: string, 1: string}> header => [level, field]
	 */
	public static function columns(bool $extended): array {
		$columns = self::LEGACY_COLUMNS;
		if (!$extended) {
			return $columns;
		}
		$covered = [];
		foreach (self::LEGACY_COLUMNS as [$level, $field]) {
			$covered[$level . '.' . $field] = true;
		}
		foreach (Fields::all() as $level => $fields) {
			foreach (array_keys($fields) as $field) {
				if (!isset($covered[$level . '.' . $field])) {
					$columns[$level . '.' . $field] = [$level, $field];
				}
			}
		}
		return $columns;
	}

	/**
	 * The import template: the columns of the catalog in their order and one
	 * example row (TEMPLATE_EXAMPLE), for a catalog kept in another program
	 * so far. An import needs only the column "Signatur"; missing columns are
	 * named in the report of the dry run.
	 *
	 * @return array{header: list<string>, rows: list<list<string>>}
	 */
	public static function template(): array {
		$header = array_keys(self::LEGACY_COLUMNS);
		return [
			'header' => $header,
			'rows' => [array_map(fn (string $column): string => self::TEMPLATE_EXAMPLE[$column] ?? '', $header)],
		];
	}

	/**
	 * Whether a row is the example row of the template (TEMPLATE_EXAMPLE),
	 * of this version or an earlier one: title and composer of the example,
	 * and a note that starts like its note.
	 *
	 * @param array<string, string> $cells header => cell of the row
	 */
	public static function isTemplateExample(array $cells): bool {
		$cell = fn (string $header): string => trim($cells[$header] ?? '');
		return $cell('Titel') === self::TEMPLATE_EXAMPLE['Titel']
			&& $cell('Komponist') === self::TEMPLATE_EXAMPLE['Komponist']
			&& str_starts_with($cell('Anmerkung'), 'Beispielzeile');
	}

	/**
	 * The current name of a header (FORMER_HEADERS), or the header itself.
	 */
	public static function currentHeader(string $header): string {
		return self::FORMER_HEADERS[$header] ?? $header;
	}

	public static function isLegacyColumn(string $header): bool {
		return isset(self::LEGACY_COLUMNS[$header]);
	}

	/**
	 * Converts a stored value to its CSV representation.
	 */
	public static function toCsv(string $header, mixed $value): string {
		if ($value === null) {
			return '';
		}
		if ($header === 'Rechtestatus') {
			$legacy = array_search($value, self::LEGACY_RIGHTS_STATUS, true);
			return $legacy === false ? (string)$value : $legacy;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		return (string)$value;
	}

	/**
	 * Reads a CSV file, UTF-8 with or without BOM. The separator is the
	 * semicolon of the catalog format; a file whose first line has no
	 * semicolon but commas or tabs is read with those (W B30), as Excel
	 * saves CSV outside the German-speaking countries. `separator` says
	 * which one was used.
	 *
	 * @return array{header: list<string>, rows: list<list<string>>, separator: string}
	 */
	public static function parse(string $content): array {
		while (str_starts_with($content, self::BOM)) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			// the way out in the message (U32): Excel saves CSV in the code page of the system unless told otherwise
			throw new InvalidInputException('The CSV file is not UTF-8 encoded. In Excel: "Save as" → "CSV UTF-8"; in LibreOffice: "Save as" → "Text CSV" with the character set UTF-8.');
		}
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			throw new \RuntimeException('Temporary stream not available');
		}
		fwrite($stream, $content);
		rewind($stream);
		$separator = self::separator($content);

		$header = null;
		$rows = [];
		while (is_array($fields = fgetcsv($stream, null, $separator, '"', ''))) {
			if ($fields === [null]) {
				continue;
			}
			$fields = array_map(fn (?string $f): string => (string)$f, $fields);
			if ($header === null) {
				$header = array_map('trim', $fields);
				continue;
			}
			$rows[] = $fields;
		}
		fclose($stream);

		if ($header === null) {
			throw new InvalidInputException('The CSV file is empty.');
		}
		return ['header' => $header, 'rows' => $rows, 'separator' => $separator];
	}

	/**
	 * The separator of a CSV file: `;` unless the first line (after empty
	 * ones) has none, then the comma or the tab, whichever occurs more.
	 */
	private static function separator(string $content): string {
		$start = strspn($content, "\r\n");
		$line = substr($content, $start, strcspn($content, "\r\n", $start));
		if (str_contains($line, ';')) {
			return ';';
		}
		$commas = substr_count($line, ',');
		$tabs = substr_count($line, "\t");
		return $commas === 0 && $tabs === 0 ? ';' : ($tabs > $commas ? "\t" : ',');
	}

	/**
	 * @param list<string> $header
	 * @param iterable<list<string>> $rows
	 */
	public static function write(array $header, iterable $rows): string {
		$text = self::BOM . self::line($header);
		foreach ($rows as $row) {
			$text .= self::line($row);
		}
		return $text;
	}

	/**
	 * Like write(), for files that people open in a spreadsheet program and
	 * that are never imported again (song order, snapshot of the register of
	 * persons): a cell that a spreadsheet program would read as a formula gets
	 * a leading apostrophe (protection against CSV/formula injection).
	 *
	 * The catalog CSV (legacy and extended) deliberately keeps every cell
	 * unchanged: it must survive an import followed by an export byte for
	 * byte, and the extended format is a backup that is imported again. Its
	 * cells come from the catalog itself; see SECURITY.md.
	 *
	 * @param list<string> $header
	 * @param iterable<list<string>> $rows
	 */
	public static function writeForPeople(array $header, iterable $rows): string {
		$text = self::BOM . self::line(array_map(self::neutralize(...), $header));
		foreach ($rows as $row) {
			$text .= self::line(array_map(self::neutralize(...), $row));
		}
		return $text;
	}

	/**
	 * Prefixes cells that start like a formula (=, +, -, @, tab, carriage
	 * return) with an apostrophe, so that spreadsheet programs show them as
	 * text instead of evaluating them.
	 */
	public static function neutralize(string $value): string {
		return $value !== '' && str_contains(self::FORMULA_START, $value[0]) ? "'" . $value : $value;
	}

	/**
	 * Legacy rule of the source catalog: a version counts as an internal
	 * preservation copy when its publication note starts with "nein" and
	 * mentions "Erhaltungskopie".
	 */
	public static function isLegacyPreservationCopy(?string $publication): bool {
		return $publication !== null
			&& str_starts_with(mb_strtolower(trim($publication)), 'nein')
			&& mb_stripos($publication, 'erhaltungskopie') !== false;
	}

	/**
	 * Legacy rule of the source catalog: maps the free-text publication note
	 * to a publication status key, or null when it says nothing clear.
	 */
	public static function legacyPublicationStatus(?string $publication): ?string {
		if ($publication === null || trim($publication) === '') {
			return null;
		}
		$text = mb_strtolower(trim($publication));
		return match (true) {
			self::isLegacyPreservationCopy($publication) => 'internal',
			str_starts_with($text, 'frei') => 'free',
			str_starts_with($text, 'gesperrt') => 'blocked',
			str_starts_with($text, 'zu klären') => 'to_clarify',
			default => null,
		};
	}

	/**
	 * @param list<string> $fields
	 */
	private static function line(array $fields): string {
		return implode(';', array_map(self::field(...), $fields)) . "\r\n";
	}

	private static function field(string $value): string {
		if (strpbrk($value, ";\"\r\n") === false) {
			return $value;
		}
		return '"' . str_replace('"', '""', $value) . '"';
	}
}
