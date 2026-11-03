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
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotPermittedException;
use OCP\IDateTimeZone;
use OCP\IDBConnection;

/**
 * Imports persons from an existing rights research list (CSV, `;`, UTF-8,
 * optionally with BOM) with the columns
 * Name; Werke; Signaturen; Lebensdaten laut Verzeichnis; Lebensdaten
 * recherchiert; Schutz bis; Status; Quelle; Geprueft am.
 *
 * - Life dates are read with their precision (see LifeDates); the raw text is
 *   always kept. Researched dates win over the catalog's; the other columns
 *   of the list are kept in the notes.
 * - Role words in the name column ("Tonsatz von …", ", op. 62") are removed;
 *   the report lists every changed name.
 * - Identification is at most "probable": a research list is no check by a
 *   person. "NICHT ERMITTELBAR" or missing dates give "uncertain".
 * - Each shelf mark becomes a contribution to the version of that copy with
 *   the role "not specified"; the librarian sets the roles later.
 * - A repeated import finds persons by name. It never changes a person that
 *   was checked, only fills empty values, and adds missing contributions.
 *   Nothing is deleted.
 *
 * A dry run performs the whole import and rolls it back. Before a real
 * import into a non-empty register, a snapshot of the register is stored in
 * the export folder.
 *
 * @psalm-import-type Warning from ImportService
 * @psalm-type Report = array{dryRun: bool, source: string, sha256: string, operation: ?string, snapshot: ?string, rows: int, created: int,
 *     existing: int, updated: int, skipped: int, contributions: int, contributionsExisting: int, precision: array<string, int>,
 *     identification: array<string, int>, renamed: list<array{from: string, to: string}>, unknownShelfmarks: list<string>,
 *     protectionDiffers: list<array{name: string, list: string, computed: string}>, warnings: list<Warning>}
 */
class PersonImportService {
	private const REQUIRED_COLUMNS = ['Name', 'Signaturen'];
	/** Role words at the start of the name column of the research list (German source data) */
	private const NAME_PREFIXES = ['tonsatz von', 'satz von', 'satz:', 'übertragen von', 'bearbeitung von', 'bearbeitung:', 'bearb.',
		'text von', 'text:', 'musik von', 'musik:', 'weise:', 'melodie:', 'arr.', 'übersetzung:', 'deutsch von'];
	/** Columns kept in the notes of a new person, as "column: value" */
	private const NOTE_COLUMNS = ['Lebensdaten laut Verzeichnis', 'Schutz bis', 'Status', 'Geprueft am'];

	public function __construct(
		private IDBConnection $db,
		private PersonMapper $persons,
		private ContributionMapper $contributions,
		private CopyMapper $copies,
		private PersonService $personService,
		private AppSettings $settings,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * @return Report `warnings` holds [message, parameters] pairs
	 */
	public function import(string $content, string $source, string $uid, bool $dryRun): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$csv = CatalogCsv::parse($content);
		$columns = array_flip($csv['header']);
		foreach (self::REQUIRED_COLUMNS as $column) {
			if (!isset($columns[$column])) {
				throw new InvalidInputException('The CSV has no column "%s". Is it the list of persons?', [$column]);
			}
		}

		$copies = $this->copies->findAllByShelfmark();
		$byName = [];
		foreach ($this->persons->findAll() as $person) {
			$byName[mb_strtolower($person->getName())][] = $person;
		}
		$existingContributions = [];
		foreach ($this->contributions->findAllByVersion() as $versionId => $list) {
			foreach ($list as $contribution) {
				$existingContributions[$versionId . '/' . $contribution->getPersonId()] = true;
			}
		}

		/** @var Report $report */
		$report = ['dryRun' => $dryRun, 'source' => $source, 'sha256' => hash('sha256', $content), 'operation' => null, 'snapshot' => null,
			'rows' => 0, 'created' => 0, 'existing' => 0, 'updated' => 0, 'skipped' => 0, 'contributions' => 0, 'contributionsExisting' => 0,
			'precision' => [], 'identification' => [], 'renamed' => [], 'unknownShelfmarks' => [], 'protectionDiffers' => [], 'warnings' => []];
		if (!$dryRun && $byName !== []) {
			$report['snapshot'] = $this->snapshot($uid);
		}

		$operation = $this->audit->newOperation();
		$now = $this->time->getTime();
		/** @var list<Warning> $warnings */
		$warnings = [];
		$this->db->beginTransaction();
		try {
			$row = 0;
			foreach ($csv['rows'] as $fields) {
				if (trim(implode('', $fields)) === '') {
					continue;
				}
				$row++;
				$report['rows']++;
				$r = [];
				foreach ($csv['header'] as $i => $header) {
					$r[$header] = trim($fields[$i] ?? '');
				}
				$raw = $r['Name'] ?? '';
				$name = self::cleanName($raw);
				if ($name === '' || !preg_match('/^\p{L}/u', ltrim($raw, "+ \t"))) {
					$warnings[] = ['Row %s: "%s" is not a name and was skipped.', [$row, mb_substr($raw, 0, 60)]];
					$report['skipped']++;
					continue;
				}
				if (mb_strlen($name) > 255) {
					$warnings[] = ['Row %s: the name is too long and was skipped.', [$row]];
					$report['skipped']++;
					continue;
				}
				if ($name !== $raw) {
					$report['renamed'][] = ['from' => $raw, 'to' => $name];
				}
				$values = $this->values($r);

				$matches = $byName[mb_strtolower($name)] ?? [];
				if (count($matches) > 1) {
					$warnings[] = ['Row %s: there are several persons named "%s". The row was skipped; please assign it by hand.', [$row, $name]];
					$report['skipped']++;
					continue;
				}
				if ($matches === []) {
					$person = new Person();
					$person->setName($name);
					foreach ($values as $field => $value) {
						$person->setValue($field, $value);
					}
					$person->setCreated($now);
					$person->setModified($now);
					$person = $this->persons->insert($person);
					$byName[mb_strtolower($name)] = [$person];
					$this->audit->record($operation, $uid, 'person', $person->getId(), 'created', 'name', null, $name, 'import');
					$report['created']++;
					self::tally($report['precision'], (string)$person->getDiedPrecision());
					self::tally($report['identification'], (string)$person->getIdentification());
				} else {
					$person = $matches[0];
					$report['existing']++;
					if ($this->fill($person, $values, $operation, $uid, $now)) {
						$report['updated']++;
					}
				}

				foreach (preg_split('/[\s,;|]+/u', $r['Signaturen'] ?? '') ?: [] as $mark) {
					if ($mark === '') {
						continue;
					}
					$copy = preg_match('/^0*(\d+)-0*(\d+)$/', $mark, $m) ? ($copies[(int)$m[1] . '-' . (int)$m[2]] ?? null) : null;
					if ($copy === null) {
						$report['unknownShelfmarks'][] = $mark;
						continue;
					}
					$key = $copy->getVersionId() . '/' . $person->getId();
					if (isset($existingContributions[$key])) {
						$report['contributionsExisting']++;
						continue;
					}
					$contribution = new Contribution();
					$contribution->setVersionId($copy->getVersionId());
					$contribution->setPersonId($person->getId());
					$contribution->setRole('unspecified');
					$contribution->setTextForComposition(false);
					$contribution->setCreated($now);
					$this->contributions->insert($contribution);
					$existingContributions[$key] = true;
					$this->audit->record($operation, $uid, Fields::VERSION, $copy->getVersionId(), 'contribution_added', 'contribution', null,
						$person->getName() . ' (unspecified)', 'import');
					$report['contributions']++;
				}

				// Cross-check with the end of protection in the list.
				$deathYear = $person->securedDeathYear();
				if ($deathYear !== null && preg_match('/^31\.12\.(\d{4})$/', $r['Schutz bis'] ?? '', $m)
					&& (int)$m[1] !== $deathYear + ProtectionTerm::YEARS_AFTER_DEATH) {
					$report['protectionDiffers'][] = ['name' => $person->getName(), 'list' => $r['Schutz bis'],
						'computed' => '31.12.' . ($deathYear + ProtectionTerm::YEARS_AFTER_DEATH)];
				}
			}

			if ($dryRun) {
				$this->db->rollBack();
			} else {
				$this->audit->record($operation, $uid, 'import', null, 'person_import', null, null, $source);
				$this->db->commit();
				$report['operation'] = $operation;
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$report['unknownShelfmarks'] = array_values(array_unique($report['unknownShelfmarks']));
		$report['warnings'] = $warnings;
		ksort($report['precision']);
		ksort($report['identification']);
		return $report;
	}

	/**
	 * Removes role words and additions that are not part of the name, e.g.
	 * "Tonsatz von Michael Prätorius" → "Michael Prätorius",
	 * "Friedrich Silcher, op. 26" → "Friedrich Silcher",
	 * "Franz Schubert, 1826" → "Franz Schubert".
	 */
	public static function cleanName(string $raw): string {
		$name = trim(ltrim(trim($raw), "+ \t"));
		do {
			$before = $name;
			foreach (self::NAME_PREFIXES as $prefix) {
				if (mb_stripos($name, $prefix) === 0) {
					$name = trim(mb_substr($name, mb_strlen($prefix)));
				}
			}
		} while ($name !== $before);
		$name = (string)preg_replace('/,\s*(op\.?\s*\d+.*|\d{3,4}|d\s*\d+)\s*$/iu', '', $name);
		$name = (string)preg_replace('/\s*\(\s*\d{3,4}\s*[-\x{2013}]\s*\d{3,4}\s*\)\s*$/u', '', $name);
		return trim($name, " \t,;");
	}

	/**
	 * Person values from one row of the list.
	 *
	 * @param array<string, string> $r
	 * @return array<string, ?string>
	 */
	private function values(array $r): array {
		$researched = $r['Lebensdaten recherchiert'] ?? '';
		$listed = $r['Lebensdaten laut Verzeichnis'] ?? '';
		$text = $researched !== '' ? $researched : $listed;
		$dates = LifeDates::parse($text);
		$notFound = str_contains(mb_strtoupper($r['Status'] ?? ''), 'NICHT ERMITTELBAR');
		$dated = in_array($dates['died_precision'], ['exact', 'year', 'living'], true) || in_array($dates['born_precision'], ['exact', 'year'], true);
		$notes = [];
		foreach (self::NOTE_COLUMNS as $column) {
			$value = $r[$column] ?? '';
			if ($value !== '' && !($column === 'Lebensdaten laut Verzeichnis' && $value === $text)) {
				$notes[] = $column . ': ' . $value;
			}
		}
		$notes = $notes === [] ? null : implode("\n", $notes);
		if ($dates['died'] === null) {
			// Without a known death only the year of birth (data minimisation)
			$dates = LifeDates::birthYearOnly(['born' => $dates['born'], 'born_precision' => $dates['born_precision'], 'died' => null]) + $dates;
			$text = LifeDates::yearsOnly($text);
			$notes = $notes === null ? null : LifeDates::yearsOnly($notes);
		}
		return [
			'born' => $dates['born'],
			'born_precision' => $dates['born_precision'],
			'died' => $dates['died'],
			'died_precision' => $dates['died_precision'],
			'life_dates_text' => $text === '' ? null : mb_substr($text, 0, 255),
			'source' => ($r['Quelle'] ?? '') === '' ? null : mb_substr($r['Quelle'], 0, 1000),
			'identification' => !$notFound && $dated ? 'probable' : 'uncertain',
			'notes' => $notes,
		];
	}

	/**
	 * Fills empty values of an existing person that nobody has checked yet.
	 *
	 * @param array<string, ?string> $values
	 */
	private function fill(Person $person, array $values, string $operation, string $uid, int $now): bool {
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
		foreach (['life_dates_text', 'source', 'notes'] as $field) {
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
			$this->audit->record($operation, $uid, 'person', $person->getId(), 'import', $field, $old, $value);
		}
		if ($changes === []) {
			return false;
		}
		$person->setModified($now);
		$this->persons->update($person);
		return true;
	}

	/**
	 * Stores the register of persons as CSV in the export folder, without
	 * accounts (every role group reads the archive folder).
	 */
	private function snapshot(string $uid): string {
		[$header, $rows] = $this->personService->table(false);
		$folder = $this->settings->exportFolder($uid);
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid));
		$base = $now->format('Ymd-His') . '_persons_before-person-import';
		$name = $base . '.csv';
		for ($i = 2; $folder->nodeExists($name); $i++) {
			$name = $base . '-' . $i . '.csv';
		}
		try {
			$folder->newFile($name, CatalogCsv::writeForPeople($header, $rows));
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
		return AppSettings::EXPORT_FOLDER . '/' . $name;
	}

	/**
	 * @param array<string, int> $counter
	 */
	private static function tally(array &$counter, string $key): void {
		$counter[$key] = ($counter[$key] ?? 0) + 1;
	}
}
