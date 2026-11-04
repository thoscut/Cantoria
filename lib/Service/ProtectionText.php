<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCP\IL10N;

/**
 * Texts for the protection proposal in the user's language. The proposal
 * itself carries only reason keys, so that it can be computed without a
 * request (background jobs, tests).
 *
 * @psalm-import-type Proposal from ProtectionTerm
 */
final class ProtectionText {
	/**
	 * Adds `reasonText` (and `reasonText` of every blocking person) plus the
	 * `notice` that this is a proposal and no legal advice; with special
	 * cases also `specialTexts`, one line per case.
	 *
	 * @param Proposal $proposal result of ProtectionTerm::propose()
	 * @return array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int,
	 *     blocking: list<array{personId: ?int, name: string, reason: string, reasonText: string}>, persons?: int,
	 *     special?: list<array{case: string, year: ?int, until: ?string}>, specialTexts?: list<string>, reasonText: string, notice: string}
	 */
	public static function apply(IL10N $l, array $proposal): array {
		$blocking = [];
		foreach ($proposal['blocking'] as $person) {
			$blocking[] = $person + ['reasonText' => self::reason($l, $person['reason'], [$person['name']])];
		}
		$proposal['blocking'] = $blocking;
		$proposal['reasonText'] = self::reason($l, $proposal['reason'], $proposal['parameters']);
		$proposal['notice'] = $l->t('Proposal only, based on German law (protection ends 70 years after the end of the year in which the last person involved died). It is no legal advice and does not change the rights status.');
		if (isset($proposal['special'])) {
			$proposal['specialTexts'] = array_map(fn (array $term): string => self::special($l, $term['case'], $term['year'], $term['until']),
				$proposal['special']);
			$proposal['notice'] .= ' ' . $l->t('With a special case (§§ 70, 71 UrhG) the edition or first publication is protected for 25 years after the end of the year in which it appeared; persons involved only as editors count with this term instead. The catalog librarian decides whether a special case applies.');
		}
		return $proposal;
	}

	private static function special(IL10N $l, string $case, ?int $year, ?string $until): string {
		$scientific = $case === ProtectionTerm::SCIENTIFIC_EDITION;
		if ($year === null || $until === null) {
			return $scientific
				? $l->t('Scientific edition (§ 70 UrhG): the year in which the edition appeared is missing.')
				: $l->t('Posthumous work (§ 71 UrhG): the year in which the work first appeared is missing.');
		}
		$end = substr($until, 0, -6);
		return $scientific
			? $l->t('Scientific edition (§ 70 UrhG): appeared in %1$s, protected until the end of %2$s.', [(string)$year, $end])
			: $l->t('Posthumous work (§ 71 UrhG): first appeared in %1$s, protected until the end of %2$s.', [(string)$year, $end]);
	}

	/**
	 * @param list<string|int> $p
	 */
	private static function reason(IL10N $l, string $key, array $p): string {
		$p = array_map(fn ($v): string => str_replace('%', '%%', (string)$v), $p);
		return match ($key) {
			'no_persons' => $l->t('No persons are recorded for this version. Unknown means protected.'),
			'living' => $l->t('%s is alive. The version is protected.', [$p[0] ?? '']),
			'death_unknown' => $l->t('The year of death of %s is not known. Unknown means protected.', [$p[0] ?? '']),
			'death_circa' => $l->t('The year of death of %s is only approximate. Without a secured year of death there is no "public domain" proposal.', [$p[0] ?? '']),
			'identification_uncertain' => $l->t('It is uncertain who %s is. Without a secured identification there is no "public domain" proposal.', [$p[0] ?? '']),
			'ended' => $l->t('Protection ended at the end of %3$s: the last of the persons involved, %1$s, died in %2$s.', [$p[0] ?? '', $p[1] ?? '', $p[2] ?? '']),
			'running' => $l->t('Protected until the end of %3$s: the last of the persons involved, %1$s, died in %2$s.', [$p[0] ?? '', $p[1] ?? '', $p[2] ?? '']),
			'composer_missing' => $l->t('No composer is recorded for this version. Add the composer under "Persons involved and protection" (tab Rights), or mark the melody as traditional with evidence; until then unknown means protected.'),
			'lyricist_missing' => $l->t('No lyricist is recorded for this version. Add the lyricist under "Persons involved and protection" (tab Rights), or mark the text as traditional or the version as without text; until then unknown means protected.'),
			'arranger_missing' => $l->t('No arranger is recorded, although the version is arranged or its melody is traditional. Add the arranger on the tab "Rights"; until then unknown means protected. "Original" means: not arranged.'),
			'traditional' => $l->t('Melody and text are marked as traditional and no other person is recorded: no one has rights of their own. Check that no arranger or translator is missing.'),
			'only_editors' => $l->t('Only editors are recorded for this version. Add the composer and the other persons involved; until then unknown means protected.'),
			'year_missing' => $l->t('The year in which it first appeared is missing. Enter it in the field "Year first published" (tab Rights); until then the version counts as protected.'),
			'scientific_edition_running' => $l->t('Protected as a scientific edition (§ 70 UrhG) until the end of %2$s: it appeared in %1$s.', [$p[0] ?? '', $p[1] ?? '']),
			'scientific_edition_ended' => $l->t('The protection as a scientific edition (§ 70 UrhG) ended at the end of %2$s: it appeared in %1$s.', [$p[0] ?? '', $p[1] ?? '']),
			'posthumous_work_running' => $l->t('Protected as a posthumous work (§ 71 UrhG) until the end of %2$s: it first appeared in %1$s.', [$p[0] ?? '', $p[1] ?? '']),
			'posthumous_work_ended' => $l->t('The protection as a posthumous work (§ 71 UrhG) ended at the end of %2$s: it first appeared in %1$s.', [$p[0] ?? '', $p[1] ?? '']),
			default => $key,
		};
	}
}
