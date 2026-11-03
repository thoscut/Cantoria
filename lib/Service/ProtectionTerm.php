<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Person;

/**
 * Proposal for the end of protection of a version (German law: §§ 64, 65
 * UrhG): protection ends at the end of the 70th calendar year after the
 * death of the last of all persons involved.
 *
 * The proposal is for display only. It never sets the rights status; the
 * catalog librarian decides. Rules from the expert report of the rights
 * holders:
 * - Unknown means protected: without persons, or when any person lacks a
 *   secured year of death (living, unknown, approximate) or is not securely
 *   identified, there is no "public domain" proposal.
 * - Unnamed is not anonymous: missing names never lead to the term for
 *   anonymous works (§ 66 UrhG).
 * - All roles count, including arrangers, translators and editors. The flag
 *   "text written for this composition" (§ 65 (3) UrhG) does not shorten
 *   anything, because both parts are needed to sing the version anyway.
 *
 * Special cases (milestone 0.8), switched on per version by the catalog
 * librarian; both protect for 25 years after the end of the year in which
 * the edition or the work first appeared (§ 69 UrhG):
 * - scientific edition (§ 70 UrhG): an edition of works or texts that are
 *   not protected, made by scholarly work and clearly different from
 *   earlier editions (e.g. an urtext or a critical edition);
 * - posthumous work (§ 71 UrhG): a work whose protection has ended and that
 *   appeared (or was performed in public) for the first time afterwards.
 * Who wrote it (milestone 0.11): when the roles are given, "public domain"
 * is only proposed when a composer is recorded and, for a version with text,
 * a lyricist, unless the catalog librarian marked the melody or the text as
 * traditional (with evidence) or the version as without text; otherwise a
 * missing person with rights of their own could be overlooked. A version
 * whose melody and text are both traditional (or without text) is proposed
 * by the other persons, and as public domain when there are none (an
 * arrangement of a folk song still counts with its arranger). A traditional
 * melody is only taken as unarranged when the kind of the version is
 * "Original": most choral settings of folk songs are arrangements with a
 * protection of their own (§ 3 UrhG), so an arrangement, an adaptation or
 * a version of unknown kind without a recorded arranger is `unknown` with
 * the reason `arranger_missing` (unknown means protected).
 *
 * With a special case, persons involved only as editors count with these
 * 25 years instead of 70 years after their death; every other person still
 * counts as usual, and the later of both ends decides. Without the year the
 * special term is unknown, and unknown means protected.
 *
 * Result:
 * - `status`: `free` (protection has ended), `protected` (ends at a known
 *   date) or `unknown` (to be treated as protected)
 * - `until`: last day of protection (YYYY-12-31) when known
 * - `freeSince`: first free day (YYYY-01-01) when known
 * - `reason`: key of the explanation, `parameters`: values for it
 * - `blocking`: persons (or the missing year, without a person) that
 *   prevent a date, each with its reason key
 * - `special`: only with special cases: each case with the year and the
 *   end of its term
 *
 * Pure function without Nextcloud dependencies, so that it can be unit
 * tested.
 *
 * @psalm-type Blocking = array{personId: ?int, name: string, reason: string}
 * @psalm-type SpecialTerm = array{case: string, year: ?int, until: ?string}
 * @psalm-type Proposal = array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int, blocking: list<Blocking>, persons?: int, special?: list<SpecialTerm>}
 */
final class ProtectionTerm {
	public const YEARS_AFTER_DEATH = 70;
	/** Term of the special cases after the year of the first appearance (§§ 70, 71 UrhG) */
	public const YEARS_AFTER_PUBLICATION = 25;

	public const FREE = 'free';
	public const PROTECTED = 'protected';
	public const UNKNOWN = 'unknown';

	/** Scientific edition, § 70 UrhG (version field of the same name) */
	public const SCIENTIFIC_EDITION = 'scientific_edition';
	/** Posthumous work, § 71 UrhG (version field of the same name) */
	public const POSTHUMOUS_WORK = 'posthumous_work';
	/** The special cases in the order of the act */
	public const SPECIAL_CASES = [self::SCIENTIFIC_EDITION, self::POSTHUMOUS_WORK];

	/** Role of a contribution that a special case counts with its own term */
	public const EDITOR_ROLE = 'editor';
	/** Roles that must be recorded unless the part is traditional */
	public const COMPOSER_ROLE = 'composer';
	public const LYRICIST_ROLE = 'lyricist';
	/** Values of melody_origin and text_origin */
	public const TRADITIONAL = 'traditional';
	public const NO_TEXT = 'none';
	/** Role of the arranger, and the kind of version (Fields::VERSION_KIND) that needs none */
	public const ARRANGER_ROLE = 'arranger';
	public const ORIGINAL_KIND = 'original';

	/** Reasons why no date can be given, most serious first */
	private const BLOCKING_ORDER = ['living', 'death_unknown', 'death_circa', 'identification_uncertain', 'year_missing'];

	/**
	 * @param list<Person> $persons all persons involved in the version
	 * @param int $currentYear the current calendar year
	 * @param list<string> $cases special cases switched on for the version (SPECIAL_CASES)
	 * @param ?int $publishedYear year in which the edition or the work first appeared
	 * @param list<int> $editorIds persons involved in the version only as editors
	 * @param list<string>|null $roles roles recorded in the version (Fields::CONTRIBUTION_ROLES); null: no check of who wrote it
	 * @param ?string $melodyOrigin `traditional` or null (melody_origin)
	 * @param ?string $textOrigin `traditional`, `none` or null (text_origin)
	 * @param ?string $versionKind kind of the version (Fields::VERSION_KIND), null when not recorded
	 * @return Proposal
	 */
	public static function propose(array $persons, int $currentYear, array $cases = [], ?int $publishedYear = null, array $editorIds = [],
		?array $roles = null, ?string $melodyOrigin = null, ?string $textOrigin = null, ?string $versionKind = null): array {
		$proposal = self::byPersonsAndCases($persons, $currentYear, $cases, $publishedYear, $editorIds);
		if ($roles === null) {
			return $proposal;
		}
		$proposal = self::byAuthors($proposal, $persons, $cases, $roles, $melodyOrigin, $textOrigin);
		if ($proposal['status'] === self::FREE && $melodyOrigin === self::TRADITIONAL && $versionKind !== self::ORIGINAL_KIND
			&& !in_array(self::ARRANGER_ROLE, $roles, true)) {
			// A traditional melody in a setting whose arranger is not recorded:
			// the arranger may still be protected (§ 3 UrhG).
			return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => 'arranger_missing', 'parameters' => [],
				'decisivePersonId' => null, 'blocking' => []] + (isset($proposal['special']) ? ['special' => $proposal['special']] : []);
		}
		return $proposal;
	}

	/**
	 * The proposal checked for the composer and the lyricist (see propose()).
	 *
	 * @param Proposal $proposal
	 * @param list<Person> $persons
	 * @param list<string> $cases
	 * @param list<string> $roles
	 * @return Proposal
	 */
	private static function byAuthors(array $proposal, array $persons, array $cases, array $roles, ?string $melodyOrigin, ?string $textOrigin): array {
		$melodyFree = $melodyOrigin === self::TRADITIONAL;
		$textFree = in_array($textOrigin, [self::TRADITIONAL, self::NO_TEXT], true);
		if ($persons === [] && $melodyFree && $textFree && array_intersect(self::SPECIAL_CASES, $cases) === []) {
			// Nothing but traditional parts: no person has rights of their own.
			return ['status' => self::FREE, 'until' => null, 'freeSince' => null, 'reason' => 'traditional',
				'parameters' => [], 'decisivePersonId' => null, 'blocking' => []];
		}
		if ($proposal['status'] !== self::FREE) {
			return $proposal;
		}
		$missing = match (true) {
			!$melodyFree && !in_array(self::COMPOSER_ROLE, $roles, true) => 'composer_missing',
			!$textFree && !in_array(self::LYRICIST_ROLE, $roles, true) => 'lyricist_missing',
			default => null,
		};
		if ($missing === null) {
			return $proposal;
		}
		return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => $missing, 'parameters' => [],
			'decisivePersonId' => null, 'blocking' => []] + (isset($proposal['special']) ? ['special' => $proposal['special']] : []);
	}

	/**
	 * The proposal by the persons and the special cases.
	 *
	 * @param list<Person> $persons
	 * @param list<string> $cases
	 * @param list<int> $editorIds
	 * @return Proposal
	 */
	private static function byPersonsAndCases(array $persons, int $currentYear, array $cases, ?int $publishedYear, array $editorIds): array {
		$cases = array_values(array_intersect(self::SPECIAL_CASES, $cases));
		if ($cases === []) {
			return self::byLifeDates($persons, $currentYear);
		}
		$counted = array_values(array_filter($persons, fn (Person $person): bool => !in_array($person->getId(), $editorIds, true)));
		$proposal = $counted === [] && $persons !== []
			? ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => 'only_editors',
				'parameters' => [], 'decisivePersonId' => null, 'blocking' => []]
			: self::byLifeDates($counted, $currentYear);

		$endYear = $publishedYear === null ? null : $publishedYear + self::YEARS_AFTER_PUBLICATION;
		$special = array_map(fn (string $case): array => ['case' => $case, 'year' => $publishedYear,
			'until' => $endYear === null ? null : self::yearString($endYear) . '-12-31'], $cases);

		if ($endYear === null) {
			// Without the year the special term is unknown: unknown means protected.
			$blocking = self::sorted([...$proposal['blocking'], ['personId' => null, 'name' => '', 'reason' => 'year_missing']]);
			// No persons (or only editors) stays the main reason; the missing year is listed.
			$keepReason = $proposal['status'] === self::UNKNOWN && $proposal['blocking'] === [];
			return [
				'status' => self::UNKNOWN,
				'until' => null,
				'freeSince' => null,
				'reason' => $keepReason ? $proposal['reason'] : $blocking[0]['reason'],
				'parameters' => $keepReason ? $proposal['parameters'] : [$blocking[0]['name']],
				'decisivePersonId' => null,
				'blocking' => $blocking,
				'special' => $special,
			];
		}
		if ($proposal['status'] === self::UNKNOWN || $endYear <= self::untilYear((string)$proposal['until'])) {
			// The persons decide: their protection is unknown or ends later.
			return $proposal + ['special' => $special];
		}
		$free = $currentYear > $endYear;
		return [
			'status' => $free ? self::FREE : self::PROTECTED,
			'until' => self::yearString($endYear) . '-12-31',
			'freeSince' => self::yearString($endYear + 1) . '-01-01',
			'reason' => $cases[0] . ($free ? '_ended' : '_running'),
			'parameters' => [(int)$publishedYear, $endYear],
			'decisivePersonId' => null,
			'blocking' => [],
			'special' => $special,
		];
	}

	/**
	 * Why a person prevents a secured date, or null when the year of death
	 * is secured.
	 */
	public static function blockingReason(Person $person): ?string {
		return match (true) {
			$person->getDiedPrecision() === 'living' => 'living',
			$person->getDiedPrecision() === 'circa' => 'death_circa',
			$person->securedDeathYear() === null => 'death_unknown',
			($person->getIdentification() ?? 'uncertain') === 'uncertain' => 'identification_uncertain',
			default => null,
		};
	}

	/**
	 * The proposal by the life dates of the persons alone (§§ 64, 65 UrhG).
	 *
	 * @param list<Person> $persons
	 * @return Proposal
	 */
	private static function byLifeDates(array $persons, int $currentYear): array {
		if ($persons === []) {
			return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => 'no_persons',
				'parameters' => [], 'decisivePersonId' => null, 'blocking' => []];
		}
		$blocking = [];
		$decisive = null;
		$lastYear = null;
		foreach ($persons as $person) {
			$reason = self::blockingReason($person);
			if ($reason !== null) {
				$blocking[] = ['personId' => $person->getId(), 'name' => $person->getName(), 'reason' => $reason];
				continue;
			}
			$year = (int)$person->securedDeathYear();
			if ($lastYear === null || $year > $lastYear) {
				$lastYear = $year;
				$decisive = $person;
			}
		}
		if ($blocking !== [] || $decisive === null || $lastYear === null) {
			$blocking = self::sorted($blocking);
			return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => $blocking[0]['reason'] ?? 'no_persons',
				'parameters' => [$blocking[0]['name'] ?? ''], 'decisivePersonId' => null, 'blocking' => $blocking];
		}
		$endYear = $lastYear + self::YEARS_AFTER_DEATH;
		$free = $currentYear > $endYear;
		return [
			'status' => $free ? self::FREE : self::PROTECTED,
			'until' => self::yearString($endYear) . '-12-31',
			'freeSince' => self::yearString($endYear + 1) . '-01-01',
			'reason' => $free ? 'ended' : 'running',
			'parameters' => [$decisive->getName(), $lastYear, $endYear],
			'decisivePersonId' => $decisive->getId(),
			'blocking' => [],
		];
	}

	/**
	 * @param list<array{personId: ?int, name: string, reason: string}> $blocking
	 * @return list<array{personId: ?int, name: string, reason: string}> most serious reason first
	 */
	private static function sorted(array $blocking): array {
		return Sort::byKeys($blocking, array_map(
			fn (array $b): array => [(int)array_search($b['reason'], self::BLOCKING_ORDER, true)], $blocking));
	}

	/**
	 * The year of a last day of protection ("1861-12-31", "-0008-12-31").
	 */
	private static function untilYear(string $until): int {
		return (int)substr($until, 0, -6);
	}

	private static function yearString(int $year): string {
		return $year < 0 ? (string)$year : sprintf('%04d', $year);
	}
}
