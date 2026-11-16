<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

/**
 * Reads life dates written as free text, as they appear in catalogs and
 * research lists, into dates with a precision.
 *
 * Recognised, among others: "1789-1860", "1789–1860", "(1789 - 1860)",
 * "12.03.1850–05.11.1910", "geb. 1928", "* 1944", "* 1944, lebend",
 * "† 1955", "gest. 1955", "ca. 1850-1910", "1850-ca. 1910", "1850?-1910",
 * "185?", "20./21. Jahrhundert", "-65--8" and "65-8 v. Chr.".
 *
 * Precision: `exact` (full date, stored as YYYY-MM-DD), `year` (YYYY),
 * `circa` (approximate; the year may be missing), `living` (death only) and
 * `unknown`. Whatever cannot be read safely becomes `unknown`; the caller
 * always keeps the raw text. A plausible but approximate value ("ca.") is
 * never turned into a secured year. A birth marker without a death ("* 1944")
 * means living, as catalogs write it, unless the birth is too long ago.
 *
 * Pure function without Nextcloud dependencies, so that it can be unit
 * tested.
 */
final class LifeDates {
	/** Nobody born more than this many years ago counts as living without a word saying so */
	private const MAX_AGE = 120;
	private const TOKEN = '/(?<prefix>\b(?:ca\.?|um|circa|c\.)\s*|~\s*)?(?<!\d)(?<value>\d{4}-\d{2}-\d{2}|\d{1,2}\.\s?\d{1,2}\.\s?\d{3,4}|\d{3,4})(?!\d)(?<suffix>\?)?/u';

	/**
	 * @return array{born: ?string, born_precision: string, died: ?string, died_precision: string}
	 */
	public static function parse(?string $text): array {
		$unknown = ['born' => null, 'born_precision' => 'unknown', 'died' => null, 'died_precision' => 'unknown'];
		$t = mb_strtolower(trim((string)$text));
		if ($t === '') {
			return $unknown;
		}
		// Dashes of all kinds become "-", runs of white space one blank.
		$t = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $t);
		$t = (string)preg_replace('/\s+/u', ' ', $t);

		$living = preg_match('/\b(lebend|lebt|living|alive)\b/u', $t) === 1;
		$beforeChrist = preg_match('/v\.\s?chr|\bbce?\b/u', $t) === 1;

		// Years before Christ, e.g. "-65--8" or "65-8 v. Chr.".
		if (preg_match('/^\(?\s*-(\d{1,4})\s*-\s*-(\d{1,4})\s*\)?$/u', $t, $m)
			|| ($beforeChrist && preg_match('/^\(?\s*(\d{1,4})\s*-\s*(\d{1,4})\s*v\.\s?chr/u', $t, $m))) {
			return (int)$m[2] > (int)$m[1] ? $unknown
				: ['born' => '-' . (int)$m[1], 'born_precision' => 'year', 'died' => '-' . (int)$m[2], 'died_precision' => 'year'];
		}

		preg_match_all(self::TOKEN, $t, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$tokens = [];
		$previousEnd = 0;
		foreach ($matches as $match) {
			$start = $match[0][1];
			$end = $start + strlen($match[0][0]);
			$token = self::token($match['value'][0], ($match['prefix'][0] ?? '') !== '', ($match['suffix'][0] ?? '') === '?', $beforeChrist);
			$token['prefixed'] = ($match['prefix'][0] ?? '') !== '';
			$token['before'] = substr($t, $previousEnd, $start - $previousEnd);
			$token['after'] = substr($t, $end);
			$token['start'] = $start;
			$tokens[] = $token;
			$previousEnd = $end;
		}

		$born = null;
		$bornMarked = false;
		$died = null;
		$unassigned = [];
		foreach ($tokens as $token) {
			if (preg_match('/(\*|\bgeb\b|geb\.|\bborn\b|\bb\.)\s*$/u', rtrim($token['before'])) === 1) {
				$born ??= $token;
				$bornMarked = true;
			} elseif (preg_match('/(†|\+|\bgest\b|gest\.|\bdied\b|\bd\.)\s*$/u', rtrim($token['before'])) === 1) {
				$died ??= $token;
			} else {
				$unassigned[] = $token;
			}
		}
		if ($born === null && $died === null) {
			if (count($unassigned) >= 2 && preg_match('/^\s*(-|bis|to)\s*/u', $unassigned[1]['before']) === 1) {
				[$born, $died] = [$unassigned[0], $unassigned[1]];
				// "ca. 1850-1910": the approximation at the start covers the whole range.
				if ($born['prefixed'] && trim(substr($t, 0, $born['start'])) === '' && $died['precision'] !== 'exact') {
					$died['precision'] = 'circa';
				}
			} elseif (count($unassigned) === 1) {
				$single = $unassigned[0];
				if ($living || preg_match('/^\s*-/u', $single['after']) === 1) {
					$born = $single;
				} elseif (preg_match('/-\s*$/u', $single['before']) === 1) {
					$died = $single;
				}
				// A single year without a marker is ambiguous and stays unknown.
			}
		} elseif ($born !== null && $died === null && $unassigned !== [] && preg_match('/^\s*(-|bis|to)\s*/u', $unassigned[0]['before']) === 1) {
			$died = $unassigned[0];
		} elseif ($died !== null && $born === null && $unassigned !== [] && $unassigned[0]['start'] < $died['start']) {
			$born = $unassigned[0];
		}

		// A birth marker without any death ("* 1944", "geb. 1928") means that
		// the person is living: catalogs write a year of death as soon as it
		// is known. Not for someone born more than 120 years ago.
		if ($bornMarked && $died === null && preg_match('/†|\+|\bgest\b|gest\.|\bdied\b|\bd\./u', $t) !== 1) {
			$bornYear = self::year($born['value'] ?? null);
			$living = $living || $bornYear === null || $bornYear > (int)date('Y') - self::MAX_AGE;
		}

		$result = $unknown;
		if ($born !== null) {
			$result['born'] = $born['value'];
			$result['born_precision'] = $born['precision'];
		}
		if ($died !== null && !$living) {
			$result['died'] = $died['value'];
			$result['died_precision'] = $died['precision'];
		} elseif ($living && $died === null) {
			$result['died_precision'] = 'living';
		}

		// Implausible: death before birth. Nothing of it is trusted.
		$bornYear = self::year($result['born']);
		$diedYear = self::year($result['died']);
		if ($bornYear !== null && $diedYear !== null && $diedYear < $bornYear) {
			return $unknown;
		}
		return $result;
	}

	/**
	 * Data minimisation for persons without a known death (milestone 0.11):
	 * only the year of birth, also in free text. A full date of birth is not
	 * needed for the protection term (Art. 5 (1) c GDPR).
	 *
	 * @param array{born: ?string, born_precision: ?string, died: ?string} $dates
	 * @return array{born: ?string, born_precision: ?string}
	 */
	public static function birthYearOnly(array $dates): array {
		$born = $dates['born'];
		if ($dates['died'] !== null || $dates['born_precision'] !== 'exact' || $born === null
			|| preg_match('/^(-?\d{1,4})-\d{2}-\d{2}$/', $born, $m) !== 1) {
			return ['born' => $born, 'born_precision' => $dates['born_precision']];
		}
		return ['born' => (string)(int)$m[1], 'born_precision' => 'year'];
	}

	/**
	 * Free text with every full date reduced to its year: "12.03.1960",
	 * "1960-03-12" and "12. März 1960" become "1960".
	 */
	public static function yearsOnly(string $text): string {
		$months = 'januar|jänner|februar|märz|april|mai|juni|juli|august|september|oktober|november|dezember|january|february|march|may|june|july|october|december'
			. '|jan|feb|mär|mar|apr|jun|jul|aug|sep|sept|okt|oct|nov|dez|dec';
		$text = (string)preg_replace('/(?<!\d)\d{1,2}\.\s?\d{1,2}\.\s?(\d{4})(?!\d)/u', '$1', $text);
		$text = (string)preg_replace('/(?<!\d)(\d{4})-\d{2}-\d{2}(?!\d)/u', '$1', $text);
		return (string)preg_replace('/(?<!\d)\d{1,2}\.?\s+(?:' . $months . ')\.?\s+(\d{4})(?!\d)/iu', '$1', $text);
	}

	/**
	 * The year of a stored date or year value ("1850", "1850-03-12", "-8").
	 */
	public static function year(?string $value): ?int {
		if ($value === null || !preg_match('/^(-?\d{1,4})(?:-\d{2}-\d{2})?$/', $value, $m)) {
			return null;
		}
		return (int)$m[1];
	}

	/**
	 * @return array{value: ?string, precision: string}
	 */
	private static function token(string $raw, bool $circaPrefix, bool $questionMark, bool $beforeChrist): array {
		$sign = $beforeChrist ? '-' : '';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
			$date = FieldValue::isDate($raw) ? $raw : null;
			$year = substr($raw, 0, 4);
		} elseif (preg_match('/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{3,4})$/', $raw, $m)) {
			$candidate = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
			$date = FieldValue::isDate($candidate) ? $candidate : null;
			$year = (string)(int)$m[3];
		} else {
			$date = null;
			$year = (string)(int)$raw;
		}
		if ($circaPrefix || $questionMark) {
			// "185?" names a decade, not a year.
			$value = $questionMark && strlen($raw) === 3 ? null : $sign . $year;
			return ['value' => $value, 'precision' => 'circa'];
		}
		if ($date !== null && !$beforeChrist) {
			return ['value' => $date, 'precision' => 'exact'];
		}
		return ['value' => $sign . $year, 'precision' => 'year'];
	}
}
