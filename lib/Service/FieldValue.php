<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

/**
 * Validation of single field values against the field registry, shared by
 * the import and by edits in the UI.
 *
 * `check()` returns null when the value is valid, otherwise an error message
 * (English source string with `%s` for the field label).
 *
 * @psalm-import-type FieldInfo from Fields
 */
final class FieldValue {
	/** Largest whole number a field holds: the columns are 32-bit INTEGER */
	public const MAX_NUMBER = 2147483647;

	/**
	 * @param FieldInfo $info
	 */
	public static function check(array $info, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		switch ($info['type']) {
			case Fields::TYPE_NUMBER:
				if (!is_int($value)) {
					return '"%s" must be a whole number.';
				}
				// counts, seconds, years and binders are never negative, and
				// a larger number would fail in the database (PostgreSQL,
				// MySQL in strict mode) with an error nobody understands
				return $value >= 0 && $value <= self::MAX_NUMBER ? null : '"%s" must be a whole number from 0 to 2147483647.';
			case Fields::TYPE_BOOLEAN:
				return is_bool($value) ? null : '"%s" must be yes or no.';
			case Fields::TYPE_CHOICE:
				return is_string($value) && isset($info['options'][$value]) ? null : '"%s": value not allowed.';
			case Fields::TYPE_DATE:
				if (!is_string($value) || !self::isDate($value)) {
					return '"%s" needs a valid date (YYYY-MM-DD).';
				}
				return null;
			default:
				if (!is_string($value)) {
					return '"%s" must be text.';
				}
				if (isset($info['max']) && mb_strlen($value) > $info['max']) {
					return '"%s" is too long.';
				}
				if (isset($info['hosts']) && !self::isLinkTo($value, $info['hosts'])) {
					return '"%s" must be an https link to the page of the work on cpdl.org or imslp.org.';
				}
				return null;
		}
	}

	/**
	 * Whether a text is an https link to one of the hosts: no user name, no
	 * port, nothing but printable characters (it ends up in a public page).
	 *
	 * @param list<string> $hosts
	 */
	public static function isLinkTo(string $value, array $hosts): bool {
		if (preg_match('/^https:\/\/([a-z0-9.-]+)(\/[!-~]*)?$/iD', $value, $match) !== 1) {
			return false;
		}
		return in_array(strtolower($match[1]), $hosts, true);
	}

	/**
	 * Strict calendar date: rejects e.g. 2026-02-31.
	 */
	public static function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}
}
