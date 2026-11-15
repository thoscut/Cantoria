<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCP\IL10N;

/**
 * Reads what the choir system has (milestone 0.12): an export of the choir
 * system (CSV or XLSX), compared with the list of the singers' portal.
 * The app changes nothing by itself (guideline 6): what differs becomes a
 * proposal in the inbox, which the catalog librarian accepts or rejects.
 *
 * - A piece in the export that is released but not recorded as in the
 *   portal: proposal "in the singers' portal since today".
 * - A piece recorded as in the portal that is not in the export: proposal
 *   "removed from the singers' portal".
 * - A piece in the export that is not released for the portal: no proposal
 *   (the archive is right), but listed so that it is removed there.
 *
 * Pieces are recognised by the shelf mark at the start of a cell, so that
 * a column with the shelf mark, the title as "2-3 Am Brunnen vor dem Tore"
 * or the file names of the hand-over folder all work.
 *
 * @psalm-type ComparedPiece = array{versionId: int, shelfmark: string, title: ?string}
 * @psalm-type CompareReport = array{dryRun: bool, file: string, rows: int, matched: int,
 *     added: list<ComparedPiece>, removed: list<ComparedPiece>, alreadyProposed: int,
 *     removeThere: list<ComparedPiece&array{reason: ?string}>, unknown: list<string>, unknownCount: int}
 */
class ChoirSystemCompareService {
	/** Rows without a shelf mark shown in the report (the count has them all) */
	public const UNKNOWN_SHOWN = 20;

	public function __construct(
		private ReleaseListService $releases,
		private ProposalService $proposals,
		private RightsService $rights,
		private XlsxReader $xlsx,
		private Roles $roles,
		private IL10N $l,
	) {
	}

	/**
	 * @return CompareReport
	 */
	public function compare(string $content, string $fileName, string $uid, bool $dryRun): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$isXlsx = str_ends_with(mb_strtolower($fileName), '.xlsx');
		if (!$isXlsx) {
			self::refuseOtherFormats($content, $fileName);
		}
		$rows = $isXlsx
			? array_merge(...array_map(fn (array $sheet): array => $sheet['rows'], $this->xlsx->read($content)))
			: self::parseCsv($content);

		$list = $this->releases->portal($uid, true);
		$byShelfmark = [];
		foreach ($list as $index => $row) {
			foreach ($row['copies'] as $copy) {
				$byShelfmark[mb_strtolower($copy['shelfmark'])] = $index;
			}
		}

		$present = [];
		$unknown = [];
		$unknownCount = 0;
		$counted = 0;
		$header = true;
		foreach ($rows as $cells) {
			$cells = array_values(array_filter(array_map('trim', $cells), fn (string $cell): bool => $cell !== ''));
			if ($cells === []) {
				continue;
			}
			$counted++;
			$found = null;
			foreach ($cells as $cell) {
				$found = $byShelfmark[mb_strtolower(preg_split('/\s+/u', $cell)[0] ?? '')] ?? null;
				if ($found !== null) {
					break;
				}
			}
			$first = $header;
			$header = false;
			if ($found === null && $first) {
				// The header row of the export
				$counted--;
				continue;
			}
			if ($found === null) {
				$unknownCount++;
				if (count($unknown) < self::UNKNOWN_SHOWN) {
					$unknown[] = mb_substr(implode(' · ', $cells), 0, 200);
				}
				continue;
			}
			$present[$found] = true;
		}
		if ($present === [] && $counted > 0) {
			throw new InvalidInputException('The file names no shelf mark of the archive. The export needs a column with the shelf mark, or titles that start with it, e.g. "2-3 Am Brunnen vor dem Tore".');
		}

		$today = $this->rights->today($uid);
		$source = mb_substr($this->l->t('Choir system: %s', [$fileName]), 0, 255);
		$added = [];
		$removed = [];
		$removeThere = [];
		$already = 0;
		foreach ($list as $index => $row) {
			$piece = ['versionId' => $row['versionId'], 'shelfmark' => $row['copies'][0]['shelfmark'] ?? '', 'title' => $row['title']];
			$inExport = isset($present[$index]);
			$onPortal = in_array($row['status'], ['ok', 'remove'], true);
			$adding = $inExport && in_array($row['status'], ['add', 'not_decided'], true);
			if ($inExport && !$adding && $row['status'] !== 'ok') {
				$removeThere[] = $piece + ['reason' => $row['reason']];
				continue;
			}
			if (!$adding && ($inExport || !$onPortal)) {
				continue;
			}
			if ($this->proposals->hasOpenPortalProposal($row['versionId'], $adding)) {
				$already++;
				continue;
			}
			if (!$dryRun) {
				$this->proposals->proposePortal($row['versionId'], $adding ? $today : null, $uid, $adding
					? $this->l->t('The choir system has this piece (export %s).', [$fileName])
					: $this->l->t('The choir system no longer has this piece (export %s).', [$fileName]), $source);
			}
			if ($adding) {
				$added[] = $piece;
			} else {
				$removed[] = $piece;
			}
		}
		return ['dryRun' => $dryRun, 'file' => $fileName, 'rows' => $counted, 'matched' => count($present), 'added' => $added,
			'removed' => $removed, 'alreadyProposed' => $already, 'removeThere' => $removeThere, 'unknown' => $unknown,
			'unknownCount' => $unknownCount];
	}

	/**
	 * Refuses what is neither CSV nor XLSX with a way out, instead of the
	 * later "no shelf mark": the "Excel" button of many web tables gives an
	 * old Excel file (.xls) or a web page, an office program may give .ods.
	 */
	private static function refuseOtherFormats(string $content, string $fileName): void {
		$start = ltrim(str_replace("\xEF\xBB\xBF", '', substr($content, 0, 64)));
		$name = mb_strtolower($fileName);
		if (str_ends_with($name, '.xls') || str_ends_with($name, '.ods') || str_starts_with($start, "\xD0\xCF\x11\xE0")
			|| str_starts_with($start, "PK\x03\x04") || str_starts_with($start, '<')) {
			throw new InvalidInputException('This file is neither CSV nor XLSX. Export the list from the choir system as CSV, or save the file as XLSX or as CSV (UTF-8) in a spreadsheet program.');
		}
	}

	/**
	 * The rows of a CSV file, separated by semicolon, comma or tab (the one
	 * that occurs most in the first line), UTF-8 with or without BOM.
	 *
	 * @return list<list<string>>
	 */
	public static function parseCsv(string $content): array {
		while (str_starts_with($content, "\xEF\xBB\xBF")) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			throw new InvalidInputException('The CSV file is not UTF-8 encoded. Was it saved with Excel?');
		}
		$first = strtok($content, "\n");
		$first = $first === false ? '' : $first;
		$counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
		arsort($counts);
		$separator = array_key_first($counts);
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			throw new \RuntimeException('Temporary stream not available');
		}
		fwrite($stream, $content);
		rewind($stream);
		$rows = [];
		while (is_array($fields = fgetcsv($stream, null, $separator, '"', ''))) {
			if ($fields !== [null]) {
				$rows[] = array_map(fn (?string $field): string => (string)$field, $fields);
			}
		}
		fclose($stream);
		return $rows;
	}
}
