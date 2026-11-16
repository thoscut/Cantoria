<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

/**
 * The grammar of the free-text fields "composer" and "arranger / text" of a
 * catalog (German source data): segments separated by `;`, ` / ` or `|`,
 * each with an optional role phrase ("Text:", "Satz:", "Deutscher Text und
 * Satz:", "Tonsatz von …") and the person with their life dates in brackets.
 *
 * One table of phrases serves the person import (which only removes them
 * from a name) and the derivation of contributors (which reads the roles).
 * Pure functions without Nextcloud dependencies, so that they can be unit
 * tested.
 */
final class ContributorPhrases {
	/**
	 * Role phrases, lower case and without the colon, and the roles they
	 * name (Fields::CONTRIBUTION_ROLES). A phrase with several roles stands
	 * for several contributions of the same person.
	 */
	public const PREFIX_ROLES = [
		// text
		'text' => ['lyricist'],
		'texte' => ['lyricist'],
		'originaltext' => ['lyricist'],
		'worte' => ['lyricist'],
		'lyrics' => ['lyricist'],
		// translation into German
		'dt. text' => ['translator'],
		'deutscher text' => ['translator'],
		'deutsche nachdichtung' => ['translator'],
		'nachdichtung' => ['translator'],
		'deutsche textfassung' => ['translator'],
		'dt. textfassung' => ['translator'],
		'deutsche fassung' => ['translator'],
		'übersetzung' => ['translator'],
		'deutsche übersetzung' => ['translator'],
		'deutsch' => ['translator'],
		'hochdeutsch' => ['translator'],
		// arrangement
		'satz' => ['arranger'],
		'tonsatz' => ['arranger'],
		'chorsatz' => ['arranger'],
		'klaviersatz' => ['arranger'],
		'bearbeitung' => ['arranger'],
		'bearb.' => ['arranger'],
		'bearb' => ['arranger'],
		'chorbearbeitung' => ['arranger'],
		'chorbearb.' => ['arranger'],
		'einrichtung' => ['arranger'],
		'arr.' => ['arranger'],
		'arr' => ['arranger'],
		'arrangement' => ['arranger'],
		// music
		'musik' => ['composer'],
		'melodie' => ['composer'],
		'weise' => ['composer'],
		'komposition' => ['composer'],
		'vertonung' => ['composer'],
		// two roles in one phrase
		'deutscher text und satz' => ['translator', 'arranger'],
	];

	/**
	 * Role words at the start of a name, as the person import removes them
	 * ("Tonsatz von Michael Prätorius" → "Michael Prätorius"), lower case.
	 */
	public const NAME_PREFIXES = ['tonsatz von', 'satz von', 'satz:', 'übertragen von', 'bearbeitung von', 'bearbeitung:', 'bearb.',
		'text von', 'text:', 'dt. text:', 'deutscher text:', 'deutscher text und satz:', 'einrichtung:', 'musik von', 'musik:', 'weise:',
		'melodie:', 'arr.', 'übersetzung:', 'deutsch von'];

	/** Words in the composer field that name no person but a traditional melody */
	private const TRADITIONAL = '/\b(volkslied|volksweise|volksgut|spiritual|traditionell|trad\.|herkunft|traditional)/iu';
	/** Words that make the attribution uncertain */
	private const UNCERTAIN = '/\b(zugeschrieben|attributed|vermutlich|vielleicht|unbelegt|nicht belegt|umstritten)\b|\?/iu';
	/** Words that stand for "nobody known" instead of a name */
	private const NO_NAME = ['unbekannt', 'unknown', 'anonym', 'anonymous', 'ungenannt', 'ohne angabe', 'nicht genannt', 'n. n.', 'n.n.'];
	/** How the parts of a compound phrase are joined ("Text und Satz", "Satz u. Klavier", "Text, Melodie und Satz") */
	private const PART_SEPARATOR = '/\s*(?:\s(?:und|u\.|&|\+)\s|,)\s*/u';

	/**
	 * The segments of a field: separated by `;`, ` / ` or `|` outside
	 * brackets, trimmed, empty ones left out.
	 *
	 * @return list<string>
	 */
	public static function segments(?string $text): array {
		$text = trim((string)$text);
		if ($text === '') {
			return [];
		}
		$segments = [];
		$current = '';
		$depth = 0;
		$length = mb_strlen($text);
		for ($i = 0; $i < $length; $i++) {
			$char = mb_substr($text, $i, 1);
			if ($char === '(') {
				$depth++;
			} elseif ($char === ')') {
				$depth = max(0, $depth - 1);
			}
			$slash = $char === '/' && $i > 0 && $i < $length - 1 && mb_substr($text, $i - 1, 1) === ' ' && mb_substr($text, $i + 1, 1) === ' ';
			if ($depth === 0 && ($char === ';' || $char === '|' || $slash)) {
				$segments[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$segments[] = $current;
		return array_values(array_filter(array_map('trim', $segments), fn (string $s): bool => $s !== ''));
	}

	/**
	 * Splits a segment into its role phrase and the rest: "Satz: Hans Lang
	 * (1897–1968)" → ["Satz", "Hans Lang (1897–1968)"], "Tonsatz von Hans
	 * Lang" → ["Tonsatz", "Hans Lang"]. A colon inside brackets is no phrase.
	 * Without a phrase the first element is null.
	 *
	 * @return array{0: ?string, 1: string}
	 */
	public static function split(string $segment): array {
		$segment = trim($segment);
		$colon = mb_strpos($segment, ':');
		$bracket = mb_strpos($segment, '(');
		if ($colon !== false && $colon > 0 && $colon <= 60 && ($bracket === false || $colon < $bracket)) {
			return [trim(mb_substr($segment, 0, $colon)), trim(mb_substr($segment, $colon + 1))];
		}
		if (preg_match('/^(?<phrase>[\p{L}.\- ]{2,40}?)\s+von\s+(?<rest>\p{Lu}.*)$/u', $segment, $m) === 1
			&& self::rolesOf($m['phrase']) !== null) {
			return [trim($m['phrase']), trim($m['rest'])];
		}
		return [null, $segment];
	}

	/**
	 * The roles a phrase names, or null when a part of it is unknown: "Text"
	 * → [lyricist], "Deutscher Text und Satz" → [translator, arranger],
	 * "Satz für Männerchor" → [arranger], "Textgestaltung" → null.
	 *
	 * @return ?list<string>
	 */
	public static function rolesOf(string $phrase): ?array {
		$phrase = mb_strtolower(trim($phrase));
		$phrase = (string)preg_replace('/^[a-z]\)\s*/u', '', $phrase);
		$phrase = (string)preg_replace('/\s+(für|for)\s.*$/u', '', $phrase);
		$phrase = (string)preg_replace('/\s+/u', ' ', $phrase);
		if ($phrase === '' || preg_match('/\d/', $phrase) === 1) {
			return null;
		}
		if (isset(self::PREFIX_ROLES[$phrase])) {
			return self::PREFIX_ROLES[$phrase];
		}
		$roles = [];
		foreach (preg_split(self::PART_SEPARATOR, $phrase) ?: [] as $part) {
			$part = trim($part);
			$found = self::PREFIX_ROLES[$part] ?? null;
			if ($found === null && str_contains($part, ' ')) {
				// "Satz TTBB", "Einrichtung T/Bar/B", "Text der Vorlage": the first word says the role.
				$found = self::PREFIX_ROLES[explode(' ', $part, 2)[0]] ?? null;
			}
			if ($found === null) {
				return null;
			}
			foreach ($found as $role) {
				if (!in_array($role, $roles, true)) {
					$roles[] = $role;
				}
			}
		}
		return $roles === [] ? null : $roles;
	}

	/**
	 * Takes the life dates out of a name: "Hans Lang (1897–1968), op. 12" →
	 * ["Hans Lang", "1897–1968"]. The bracket with the dates is the first one
	 * that holds a digit or a birth or death marker; brackets that are part of
	 * the name ("Johann Strauss (Sohn)") stay, other brackets with remarks are
	 * dropped. The name is cleaned like in the person import.
	 *
	 * @return array{0: string, 1: ?string} name and the text of the bracket
	 */
	public static function nameAndDates(string $text): array {
		$dates = null;
		$name = (string)preg_replace_callback('/\(([^()]*)\)/u', function (array $m) use (&$dates): string {
			$inner = trim($m[1]);
			if ($dates === null && preg_match('/\d|\*|†|lebensdaten|\bgeb\.|\bgest\./iu', $inner) === 1) {
				$dates = $inner;
				return ' ';
			}
			// Part of the name: short, starts with a capital, no digits.
			return preg_match('/^\p{Lu}[^\d]{0,19}$/u', $inner) === 1 ? '(' . $inner . ')' : ' ';
		}, $text);
		$name = (string)preg_replace('/\b(zugeschrieben|attributed)\b/iu', ' ', $name);
		$name = (string)preg_replace('/\s+/u', ' ', $name);
		return [PersonImportService::cleanName($name), $dates];
	}

	/**
	 * Whether a text, outside its brackets, says that the melody is
	 * traditional ("Volkslied", "Spiritual", "Herkunft: …").
	 */
	public static function isTraditional(string $text): bool {
		return preg_match(self::TRADITIONAL, (string)preg_replace('/\([^()]*\)/u', ' ', $text)) === 1;
	}

	/**
	 * Whether a text marks the attribution as uncertain ("zugeschrieben",
	 * "vermutlich", "?").
	 */
	public static function isUncertain(string $text): bool {
		return preg_match(self::UNCERTAIN, $text) === 1;
	}

	/**
	 * Whether a cleaned name can be the name of a person: starts with a
	 * capital letter, no digits, no quotation marks, not a word for "unknown".
	 */
	public static function isName(string $name): bool {
		if (in_array(mb_strtolower($name), self::NO_NAME, true)) {
			return false;
		}
		return preg_match('/^\p{Lu}/u', $name) === 1 && preg_match('/\d|["„“”]/u', $name) !== 1 && mb_strlen($name) >= 2;
	}

	/**
	 * The GND number in a text ("GND 134446216"), or null.
	 */
	public static function gnd(?string $text): ?string {
		return $text !== null && preg_match('/\bGND\s*:?\s*(\d{7,10}[\dX])\b/u', $text, $m) === 1 ? $m[1] : null;
	}
}
