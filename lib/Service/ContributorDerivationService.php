<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Derives persons and their roles from the free-text fields of the catalog:
 * `work.composer` ("Komponist") and `version.arranger_text` ("Bearbeiter /
 * Text"). The texts follow a grammar (ContributorPhrases): segments
 * separated by `;` or ` / `, each with a role phrase ("Text:", "Satz:",
 * "Deutscher Text und Satz:") and the life dates in brackets.
 *
 * - A segment of the composer field without a phrase names the composer;
 *   "Volkslied", "Spiritual" and the like name no person but set
 *   `melody_origin` to traditional (only when empty). "zugeschrieben" and "?"
 *   make the identification uncertain.
 * - A segment of the arranger field without a phrase, or with a phrase that
 *   is not understood, is listed as unassigned; nothing is derived from it.
 * - Persons are found by name (case-insensitive). A new person gets the life
 *   dates and the GND number of the bracket and is at most "probable". An
 *   existing person is never changed once checked, otherwise only empty
 *   values are filled; life dates that differ from the catalog are reported.
 * - A contribution of the person with the role "unspecified" (from the
 *   person import) gets the derived role; another role is kept and the
 *   derived one added. Nothing is deleted.
 *
 * A dry run performs everything and rolls it back.
 *
 * @psalm-import-type Warning from ImportService
 * @psalm-type DerivationReport = array{dryRun: bool, operation: ?string, versions: int, segments: int, personsCreated: int,
 *     personsExisting: int, personsUpdated: int, contributionsCreated: int, rolesSet: int, traditionalMelodies: int, uncertain: int,
 *     differs: list<array{name: string, person: string, catalog: string, versions: list<string>}>,
 *     unassigned: list<array{shelfmark: string, text: string}>, roles: array<string, int>, warnings: list<Warning>}
 */
class ContributorDerivationService {
	public const REASON = 'derived';
	private const SOURCE = 'catalog';

	/** @var array<string, list<Person>> persons by lower-case name */
	private array $byName = [];
	/** @var array<string, list<Contribution>> contributions by "versionId/personId" */
	private array $contributionsOf = [];
	/** @var array<int, array{name: string, person: string, catalog: string, versions: list<string>}> differences by person id */
	private array $differs = [];
	/** @var list<Warning> */
	private array $warnings = [];
	private const EMPTY_REPORT = ['dryRun' => true, 'operation' => null, 'versions' => 0, 'segments' => 0, 'personsCreated' => 0,
		'personsExisting' => 0, 'personsUpdated' => 0, 'contributionsCreated' => 0, 'rolesSet' => 0, 'traditionalMelodies' => 0,
		'uncertain' => 0, 'differs' => [], 'unassigned' => [], 'roles' => [], 'warnings' => []];

	/** @var DerivationReport */
	private array $report = self::EMPTY_REPORT;
	private string $operation = '';
	private string $uid = '';
	private int $now = 0;

	public function __construct(
		private IDBConnection $db,
		private PersonMapper $persons,
		private ContributionMapper $contributions,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return DerivationReport `warnings` holds [message, parameters] pairs
	 */
	public function derive(string $uid, bool $dryRun): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$this->uid = $uid;
		$this->now = $this->time->getTime();
		$this->operation = $this->audit->newOperation();
		$this->report = ['dryRun' => $dryRun] + self::EMPTY_REPORT;
		$this->byName = [];
		$this->contributionsOf = [];
		$this->differs = [];
		$this->warnings = [];

		foreach ($this->persons->findAll() as $person) {
			$this->byName[mb_strtolower($person->getName())][] = $person;
		}
		foreach ($this->contributions->findAllByVersion() as $versionId => $list) {
			foreach ($list as $contribution) {
				$this->contributionsOf[$versionId . '/' . $contribution->getPersonId()][] = $contribution;
			}
		}
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		// Versions in shelf order, each with its first shelf mark; versions without a copy last.
		$shelfmarks = [];
		foreach ($this->copies->findAllByShelfmark() as $copy) {
			$shelfmarks[$copy->getVersionId()] ??= $copy->getShelfmark();
		}
		foreach (array_keys($versions) as $versionId) {
			$shelfmarks[$versionId] ??= '#' . $versionId;
		}

		$this->db->beginTransaction();
		try {
			foreach ($shelfmarks as $versionId => $shelfmark) {
				$version = $versions[$versionId] ?? null;
				$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
				if ($version === null || $work === null) {
					continue;
				}
				$composer = ContributorPhrases::segments($work->getComposer());
				$arranger = ContributorPhrases::segments($version->getArrangerText());
				if ($composer === [] && $arranger === []) {
					continue;
				}
				$this->report['versions']++;
				foreach ($composer as $segment) {
					$this->composerSegment($version, $shelfmark, $segment);
				}
				foreach ($arranger as $segment) {
					$this->arrangerSegment($version, $shelfmark, $segment);
				}
			}

			if ($dryRun) {
				$this->db->rollBack();
			} else {
				$this->audit->record($this->operation, $uid, 'import', null, 'contributor_derivation', null, null,
					$this->report['versions'] . ' versions');
				$this->db->commit();
				$this->report['operation'] = $this->operation;
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->finish();
	}

	/**
	 * The report with the differences, the warnings and the roles in order.
	 *
	 * @return DerivationReport
	 */
	private function finish(): array {
		$report = $this->report;
		$report['differs'] = array_values($this->differs);
		$report['warnings'] = $this->warnings;
		$roles = $report['roles'];
		ksort($roles);
		$report['roles'] = $roles;
		return $report;
	}

	/**
	 * A segment of the composer field: without a phrase the composer, a
	 * traditional melody, or a phrase like in the arranger field.
	 */
	private function composerSegment(WorkVersion $version, string $shelfmark, string $segment): void {
		$this->report['segments']++;
		[$phrase, $rest] = ContributorPhrases::split($segment);
		if ($phrase === null && ContributorPhrases::isTraditional($segment)) {
			$this->report['traditionalMelodies']++;
			if ($version->getMelodyOrigin() === null) {
				$version->setMelodyOrigin(ProtectionTerm::TRADITIONAL);
				$version->setModified($this->now);
				$this->versions->update($version);
				$this->audit->record($this->operation, $this->uid, Fields::VERSION, $version->getId(), self::REASON, 'melody_origin', null,
					ProtectionTerm::TRADITIONAL);
			}
			return;
		}
		$roles = $phrase === null ? [ProtectionTerm::COMPOSER_ROLE] : ContributorPhrases::rolesOf($phrase);
		if ($roles === null) {
			$this->report['unassigned'][] = ['shelfmark' => $shelfmark, 'text' => $segment];
			return;
		}
		$this->contribute($version, $shelfmark, $rest, $roles);
	}

	/**
	 * A segment of the arranger field: only with a phrase that names roles.
	 */
	private function arrangerSegment(WorkVersion $version, string $shelfmark, string $segment): void {
		$this->report['segments']++;
		[$phrase, $rest] = ContributorPhrases::split($segment);
		$roles = $phrase === null ? null : ContributorPhrases::rolesOf($phrase);
		if ($roles === null) {
			$this->report['unassigned'][] = ['shelfmark' => $shelfmark, 'text' => $segment];
			return;
		}
		$this->contribute($version, $shelfmark, $rest, $roles);
	}

	/**
	 * The person of a segment (found or created) and their contributions.
	 *
	 * @param list<string> $roles
	 */
	private function contribute(WorkVersion $version, string $shelfmark, string $text, array $roles): void {
		[$name, $datesText] = ContributorPhrases::nameAndDates($text);
		if (!ContributorPhrases::isName($name) || mb_strlen($name) > 255) {
			$this->warnings[] = ['Shelf mark %s: "%s" is not a name and was skipped.', [$shelfmark, mb_substr($text, 0, 60)]];
			return;
		}
		$uncertain = ContributorPhrases::isUncertain($text);
		if ($uncertain) {
			$this->report['uncertain']++;
		}
		$dates = LifeDates::parse($datesText);
		$dated = in_array($dates['died_precision'], ['exact', 'year', 'living'], true) || in_array($dates['born_precision'], ['exact', 'year'], true);
		if ($dates['died'] === null) {
			// Without a known death only the year of birth (data minimisation).
			$short = LifeDates::birthYearOnly(['born' => $dates['born'], 'born_precision' => $dates['born_precision'], 'died' => null]);
			$dates['born'] = $short['born'];
			$dates['born_precision'] = $short['born_precision'] ?? $dates['born_precision'];
			$datesText = $datesText === null ? null : LifeDates::yearsOnly($datesText);
		}
		$values = [
			'born' => $dates['born'],
			'born_precision' => $dates['born_precision'],
			'died' => $dates['died'],
			'died_precision' => $dates['died_precision'],
			'life_dates_text' => $datesText === null ? null : mb_substr($datesText, 0, 255),
			'gnd' => $uncertain ? null : ContributorPhrases::gnd($datesText),
			'source' => self::SOURCE . ' ' . $shelfmark,
			'identification' => !$uncertain && $dated ? 'probable' : 'uncertain',
		];

		$matches = $this->byName[mb_strtolower($name)] ?? [];
		if (count($matches) > 1) {
			$this->warnings[] = ['Shelf mark %s: there are several persons named "%s". The segment was skipped; please assign it by hand.',
				[$shelfmark, $name]];
			return;
		}
		if ($matches === []) {
			$person = new Person();
			$person->setName($name);
			foreach ($values as $field => $value) {
				$person->setValue($field, $value);
			}
			$person->setCreated($this->now);
			$person->setModified($this->now);
			$person = $this->persons->insert($person);
			$this->byName[mb_strtolower($name)] = [$person];
			$this->audit->record($this->operation, $this->uid, 'person', $person->getId(), 'created', 'name', null, $name, self::REASON);
			$this->report['personsCreated']++;
		} else {
			$person = $matches[0];
			$this->report['personsExisting']++;
			$this->compareDates($person, $dates, $datesText, $shelfmark);
			if ($this->fill($person, $values)) {
				$this->report['personsUpdated']++;
			}
		}

		foreach ($roles as $role) {
			$this->contribution($version, $person, $role);
		}
	}

	/**
	 * Records a contribution: an existing one with the role "unspecified"
	 * gets the role, one with the role already is kept, otherwise a new one.
	 */
	private function contribution(WorkVersion $version, Person $person, string $role): void {
		$key = $version->getId() . '/' . $person->getId();
		$existing = $this->contributionsOf[$key] ?? [];
		foreach ($existing as $contribution) {
			if ($contribution->getRole() === $role) {
				return;
			}
		}
		foreach ($existing as $contribution) {
			if ($contribution->getRole() === 'unspecified') {
				$contribution->setRole($role);
				$this->contributions->update($contribution);
				$this->audit->record($this->operation, $this->uid, Fields::VERSION, $version->getId(), 'contribution_role_set', 'contribution',
					$person->getName() . ' (unspecified)', $person->getName() . ' (' . $role . ')', self::REASON);
				$this->report['rolesSet']++;
				self::tally($this->report['roles'], $role);
				return;
			}
		}
		$contribution = new Contribution();
		$contribution->setVersionId($version->getId());
		$contribution->setPersonId($person->getId());
		$contribution->setRole($role);
		$contribution->setTextForComposition(false);
		$contribution->setCreated($this->now);
		$contribution = $this->contributions->insert($contribution);
		$this->contributionsOf[$key][] = $contribution;
		$this->audit->record($this->operation, $this->uid, Fields::VERSION, $version->getId(), 'contribution_added', 'contribution', null,
			$person->getName() . ' (' . $role . ')', self::REASON);
		$this->report['contributionsCreated']++;
		self::tally($this->report['roles'], $role);
	}

	/**
	 * Life dates of an existing person against the catalog: a year that
	 * differs, or a death recorded for someone the catalog calls living, is
	 * reported (not changed).
	 *
	 * @param array{born: ?string, born_precision: string, died: ?string, died_precision: string} $dates
	 */
	private function compareDates(Person $person, array $dates, ?string $datesText, string $shelfmark): void {
		if ($datesText === null) {
			return;
		}
		$known = fn (?string $precision): bool => in_array($precision, ['exact', 'year', 'circa'], true);
		$recorded = [
			'born' => [LifeDates::year($person->getBorn()), $person->getBornPrecision()],
			'died' => [LifeDates::year($person->getDied()), $person->getDiedPrecision()],
		];
		$differs = false;
		foreach (['born', 'died'] as $which) {
			$catalog = LifeDates::year($dates[$which]);
			[$year, $precision] = $recorded[$which];
			if ($catalog !== null && $known($dates[$which . '_precision']) && $year !== null && $known($precision) && $catalog !== $year) {
				$differs = true;
			}
		}
		if ($dates['died_precision'] === 'living' && $recorded['died'][0] !== null && $known($recorded['died'][1])) {
			$differs = true;
		}
		if (!$differs) {
			return;
		}
		$id = $person->getId();
		$this->differs[$id] ??= ['name' => $person->getName(), 'person' => self::describeDates($person), 'catalog' => $datesText, 'versions' => []];
		if (!in_array($shelfmark, $this->differs[$id]['versions'], true)) {
			$this->differs[$id]['versions'][] = $shelfmark;
		}
	}

	/**
	 * Fills empty values of an existing person that nobody has checked yet
	 * (like the person import).
	 *
	 * @param array<string, ?string> $values
	 */
	private function fill(Person $person, array $values): bool {
		if ($person->getCheckedBy() !== null) {
			return false;
		}
		$changes = [];
		$datesUnknown = fn (string $which): bool => in_array($person->getValue($which . '_precision'), [null, 'unknown'], true);
		foreach (['born', 'died'] as $which) {
			if ($datesUnknown($which) && $values[$which . '_precision'] !== 'unknown') {
				$changes[$which] = $values[$which];
				$changes[$which . '_precision'] = $values[$which . '_precision'];
			}
		}
		foreach (['life_dates_text', 'gnd', 'source'] as $field) {
			if ($person->getValue($field) === null && $values[$field] !== null) {
				$changes[$field] = $values[$field];
			}
		}
		if (isset($changes['died_precision']) && $person->getIdentification() === 'uncertain' && $values['identification'] === 'probable') {
			$changes['identification'] = 'probable';
		}
		foreach ($changes as $field => $value) {
			$old = $person->getValue($field);
			if ($old === $value) {
				unset($changes[$field]);
				continue;
			}
			$person->setValue($field, $value);
			$this->audit->record($this->operation, $this->uid, 'person', $person->getId(), self::REASON, $field, $old, $value);
		}
		if ($changes === []) {
			return false;
		}
		$person->setModified($this->now);
		$this->persons->update($person);
		return true;
	}

	/**
	 * The recorded life dates of a person in short: the free text, else
	 * "born–died" with a "*" for a living person.
	 */
	private static function describeDates(Person $person): string {
		$text = $person->getLifeDatesText();
		if ($text !== null) {
			return $text;
		}
		$born = $person->getBorn() ?? '?';
		$died = $person->getDiedPrecision() === 'living' ? '' : ($person->getDied() ?? '?');
		return $died === '' ? '* ' . $born : $born . "\u{2013}" . $died;
	}

	/**
	 * @param array<string, int> $counter
	 */
	private static function tally(array &$counter, string $key): void {
		$counter[$key] = ($counter[$key] ?? 0) + 1;
	}
}
