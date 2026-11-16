<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Imports the catalog from CSV, from an Excel workbook (.xlsx) or from an
 * OpenDocument spreadsheet (.ods), with the same columns (U32, W B15). Each
 * row is a shelf mark; a new row creates one work, one version and one copy.
 * Of a workbook, the first sheet with the column "Signatur" in its first
 * rows is read; rows above that header are left out. A CSV file may be
 * separated by commas or tabs instead of semicolons (W B30).
 *
 * A shelf mark is binder and number, `12-3` (W B25); a row with an empty
 * "Signatur" takes it from "Mappe" and "Nr". The example row of the
 * template is left out with a note in the report (W B28).
 *
 * A repeated import matches rows by shelf mark and takes over changed values
 * while the file is still the leading source. Nothing is deleted: shelf marks
 * missing from the file are only listed in the report (`notInCsv`, named
 * after the first format).
 *
 * Rules that keep people in charge:
 * - Invalid values are reported and skipped; the stored value stays.
 * - Decision fields (e.g. rights confirmed) are filled while empty but never
 *   overwritten; a differing CSV value is reported instead.
 * - A rights status that a person confirmed in the app (`confirmed_at`) is
 *   not overwritten either; a differing CSV value is reported.
 * - The column "Gilt für den Chor" (CatalogCsv::VALID_COLUMN) never chooses
 *   a valid version: the conductor does that in the app. It is read only
 *   when a backup is restored into an empty catalog.
 * - The preservation copy flag is derived from the legacy publication note
 *   only when a version is created, so manual corrections survive. A
 *   preservation copy whose reasoning says "ZU PRÜFEN" (to be checked) starts
 *   with the original as a working assumption of the board (`rule1_status`).
 * - Read-only fields outside the legacy columns are only taken over when
 *   restoring a backup into an empty catalog.
 *
 * Work groups (column "Werkgruppe", CatalogCsv::GROUP_COLUMN): rows with the
 * same value are versions of one work. The value is the shelf mark of the
 * main copy (or a work number): the work is created once, its title and
 * composer come from the row of that shelf mark, and every other row of the
 * group becomes a version and a copy of the same work. A row of a group whose
 * copy already belongs to another work is reported: the librarian merges the
 * works in the app (MergeService), the import never moves versions.
 *
 * A dry run performs the whole import and rolls the database back afterwards.
 * Before a real import into a non-empty catalog, a snapshot is stored in the
 * export folder.
 *
 * @psalm-import-type FieldInfo from Fields
 * @psalm-type Warning = array{0: string, 1: list<string|int>}
 * @psalm-type Report = array{dryRun: bool, source: string, sha256: string, operation: ?string, restore: bool, snapshot: ?string, snapshotOmitted: int,
 *     rows: int, created: int, changed: int, unchanged: int, fieldsChanged: int, notInCsv: list<string>, withMusicxml: int,
 *     preservationCopies: int, rule1Assumed: int, workGroups: int, groupedCopies: int,
 *     distribution: array{rightsStatus: array<string, int>, repertoire: array<string, int>, publication: array<string, int>, status: array<string, int>},
 *     warnings: list<Warning>}
 */
class ImportService {
	/** Rows of a sheet in which the header with "Signatur" is looked for */
	public const HEADER_ROWS = 10;
	/** The first bytes of a ZIP archive (.xlsx, .ods) and of an Excel 97–2003 file (.xls) */
	private const ZIP_START = "PK\x03\x04";
	private const OLE_START = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	public function __construct(
		private IDBConnection $db,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ExportService $export,
		private AuditLog $audit,
		private ITimeFactory $time,
		private XlsxReader $xlsx,
		private OdsReader $ods,
	) {
	}

	/**
	 * @param string $source name of the file; its extension says the format (.xlsx, .ods, otherwise CSV)
	 * @return Report `warnings` holds [message, parameters] pairs
	 */
	public function import(string $content, string $source, string $uid, bool $dryRun): array {
		$csv = $this->table($content, $source);
		// A renamed column is read under its former name too
		$csv['header'] = array_map(CatalogCsv::currentHeader(...), $csv['header']);
		$allColumns = CatalogCsv::columns(true);

		/** @var array<int, array{0: string, 1: string}> $mapping column index => [level, field] */
		$mapping = [];
		$unknown = [];
		foreach ($csv['header'] as $i => $name) {
			if (isset($allColumns[$name])) {
				$mapping[$i] = $allColumns[$name];
			} elseif ($name !== '') {
				$unknown[] = $name;
			}
		}
		$present = array_flip($csv['header']);
		if (!isset($present['Signatur'])) {
			throw new InvalidInputException('The file has no column "Signatur". Is it the catalog?');
		}
		$missingColumns = array_values(array_filter(array_keys(CatalogCsv::LEGACY_COLUMNS), fn (string $c): bool => !isset($present[$c])));

		$existing = $this->copies->findAllByShelfmark();
		$restore = $existing === [];

		/** @var list<Warning> $warnings */
		$warnings = [];
		if ($csv['sheet'] !== null) {
			$warnings[] = ['Only the sheet "%s" was read; the other sheets of the file are left out.', [$csv['sheet']]];
		}
		if (($csv['separator'] ?? ';') === ',') {
			$warnings[] = ['The CSV file is separated by commas; it was read that way.', []];
		} elseif (($csv['separator'] ?? ';') === "\t") {
			$warnings[] = ['The CSV file is separated by tabs; it was read that way.', []];
		}
		if ($unknown !== []) {
			$warnings[] = ['Unknown columns are ignored: %s', [implode(', ', $unknown)]];
		}
		if ($missingColumns !== []) {
			$warnings[] = ['Catalog columns are missing: %s', [implode(', ', $missingColumns)]];
		}

		$snapshot = null;
		$snapshotOmitted = 0;
		if (!$dryRun && !$restore) {
			$snapshot = $this->export->snapshot('before-import', $uid);
			$snapshotOmitted = $this->export->omittedPreservationCopies();
		}

		$operation = $this->audit->newOperation();
		$now = $this->time->getTime();
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();

		$counts = ['rows' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0, 'fieldsChanged' => 0];
		/** @var array{rightsStatus: array<string, int>, repertoire: array<string, int>, publication: array<string, int>, status: array<string, int>} $distribution */
		$distribution = ['rightsStatus' => [], 'repertoire' => [], 'publication' => [], 'status' => []];
		$withMusicxml = 0;
		$preservationCopies = 0;
		$rule1Assumed = 0;
		$groupedCopies = 0;
		$validCellsSkipped = 0;
		$seen = [];
		/** @var array<string, Work> $groupWorks the work of each work group met so far */
		$groupWorks = [];
		/** @var array<string, Work> $workOfShelfmark the work of every copy in the catalog, to find the work of a group by its main shelf mark */
		$workOfShelfmark = [];
		foreach ($existing as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			if ($version !== null && isset($works[$version->getWorkId()])) {
				$workOfShelfmark[$copy->getShelfmark()] = $works[$version->getWorkId()];
			}
		}

		$this->db->beginTransaction();
		try {
			$row = 0;
			foreach ($csv['rows'] as $fields) {
				if (trim(implode('', $fields)) === '') {
					continue;
				}
				$row++;
				$cells = [];
				foreach ($csv['header'] as $i => $name) {
					$cells[$name] ??= $fields[$i] ?? '';
				}
				if (CatalogCsv::isTemplateExample($cells)) {
					$warnings[] = ['Row %s is the example row of the template and was not imported.', [$row]];
					continue;
				}
				$counts['rows']++;
				/** @var array<string, array<string, string|int|bool|null>> $values */
				$values = [Fields::WORK => [], Fields::VERSION => [], Fields::COPY => []];
				foreach ($mapping as $i => [$level, $field]) {
					$info = Fields::info($level, $field);
					if ($info === null) {
						continue;
					}
					$header = $csv['header'][$i];
					$cell = $fields[$i] ?? '';
					if ($header === CatalogCsv::VALID_COLUMN) {
						// never chooses a valid version; read back only into an empty catalog
						if (trim($cell) === '') {
							continue;
						}
						if (!$restore) {
							$validCellsSkipped++;
							continue;
						}
						$valid = CatalogCsv::parseValidForChoir($cell);
						if ($valid === null) {
							$warnings[] = ['Row %s, column "%s": the value "%s" is not valid there and was not imported.', [$row, $header, mb_substr($cell, 0, 60)]];
							continue;
						}
						$values[Fields::VERSION]['valid_since'] = $valid[0];
						if ($valid[1] !== null) {
							$values[Fields::VERSION] += ['valid_set_by' => mb_substr($valid[1], 0, Fields::info(Fields::VERSION, 'valid_set_by')['max'] ?? 200)];
						}
						continue;
					}
					if ($info['write'] === [] && !CatalogCsv::isLegacyColumn($header) && !$restore) {
						continue;
					}
					if ($header === 'Rechtestatus' && mb_strtolower(trim($cell)) === CatalogCsv::LEGACY_RIGHTS_STATUS_LICENSED) {
						// "lizenziert" is no status: protected, and the license is recorded in the app
						$warnings[] = ['Row %s: the rights status "%s" is no status; the version was imported as "protected". Please record the license in the app.', [$row, trim($cell)]];
						$cell = 'protected';
					}
					[$valid, $value] = self::convert($cell, $header, $info);
					if (!$valid) {
						$warnings[] = ['Row %s, column "%s": the value "%s" is not valid there and was not imported.', [$row, $header, mb_substr($fields[$i] ?? '', 0, 60)]];
						continue;
					}
					$values[$level][$field] = $value;
				}

				$rawShelfmark = trim((string)($values[Fields::COPY]['shelfmark'] ?? ''));
				$binder = $values[Fields::COPY]['binder'] ?? null;
				$number = $values[Fields::COPY]['number'] ?? null;
				if ($rawShelfmark === '' && is_int($binder) && is_int($number)) {
					// from "Mappe" and "Nr" (W B25)
					$rawShelfmark = $binder . '-' . $number;
				}
				// at most nine digits each: binder and number are 32-bit columns
				if (!preg_match('/^0*(\d{1,9})-0*(\d{1,9})$/', $rawShelfmark, $parts)) {
					if (FieldValue::isDate($rawShelfmark)) {
						// a spreadsheet program made a date of 1-3
						$warnings[] = ['Row %s: shelf mark "%s" is a date, not binder and number, and was skipped. Please format the column "Signatur" as text and type the shelf mark again.', [$row, $rawShelfmark]];
					} else {
						$warnings[] = ['Row %s: shelf mark "%s" is invalid and was skipped. A shelf mark is binder and number, for example 12-3.', [$row, $rawShelfmark]];
					}
					continue;
				}
				$shelfmark = (int)$parts[1] . '-' . (int)$parts[2];
				if ($shelfmark !== $rawShelfmark) {
					$warnings[] = ['Row %s: shelf mark "%s" was normalized to %s.', [$row, $rawShelfmark, $shelfmark]];
				}
				if (isset($seen[$shelfmark])) {
					$warnings[] = ['Row %s: shelf mark %s occurs twice and was skipped.', [$row, $shelfmark]];
					continue;
				}
				$seen[$shelfmark] = true;
				$values[Fields::COPY] = self::withShelfmark($values[Fields::COPY], $shelfmark, (int)$parts[1], (int)$parts[2]);

				$publication = self::text($values[Fields::VERSION]['publication'] ?? null);
				self::tally($distribution['rightsStatus'], $values[Fields::VERSION]['rights_status'] ?? null);
				self::tally($distribution['repertoire'], $values[Fields::WORK]['repertoire_import'] ?? null);
				self::tally($distribution['publication'], self::shorten($publication));
				$musicxml = ($values[Fields::VERSION]['musicxml_file'] ?? null) !== null;
				if ($musicxml) {
					$withMusicxml++;
				}

				// The work group: the shelf mark of the main copy or a work number
				$group = trim(self::text($values[Fields::WORK]['work_no'] ?? null) ?? '');
				$group = $group === '' ? null : (preg_match('/^0*(\d+)-0*(\d+)$/', $group, $g) ? (int)$g[1] . '-' . (int)$g[2] : $group);
				if ($group !== null) {
					$values[Fields::WORK]['work_no'] = $group;
				}
				$isMainOfGroup = $group === null || $group === $shelfmark;

				$copy = $existing[$shelfmark] ?? null;
				if ($copy === null) {
					if (!array_key_exists('preservation_copy', $values[Fields::VERSION])) {
						$values[Fields::VERSION]['preservation_copy'] = CatalogCsv::isLegacyPreservationCopy($publication);
					}
					$preservation = $values[Fields::VERSION]['preservation_copy'] === true;
					if ($preservation) {
						$preservationCopies++;
					}
					if (!array_key_exists('publication_status', $values[Fields::VERSION])) {
						$values[Fields::VERSION]['publication_status'] = CatalogCsv::legacyPublicationStatus($publication);
					}
					if ($preservation && ($values[Fields::VERSION]['rule1_status'] ?? null) === null
						&& self::saysToBeChecked(self::text($values[Fields::VERSION]['publication_reason'] ?? null))) {
						// "ZU PRÜFEN": the original has not been looked for yet, the board assumes it is there
						$values[Fields::VERSION]['rule1_status'] = 'board_assumption';
						$rule1Assumed++;
					}
					if (!$preservation && $musicxml && ($values[Fields::VERSION]['rights_status'] ?? null) === 'protected') {
						$warnings[] = ['Shelf mark %s: protected, with a MusicXML file, but not marked as internal preservation copy. Please check the column "Veröffentlichung".', [$shelfmark]];
					}
					self::tally($distribution['status'], $values[Fields::COPY]['status'] ?? 'active');

					$groupWork = $group === null ? null : ($groupWorks[$group] ?? $workOfShelfmark[$group] ?? null);
					if ($groupWork === null) {
						$groupWork = $this->createWork($values[Fields::WORK] ?? [], $now);
						if ($group !== null) {
							$groupWorks[$group] = $groupWork;
						}
					} elseif ($isMainOfGroup) {
						// the main copy of the group names the work, whichever row came first
						$this->apply($groupWork, Fields::WORK, $values[Fields::WORK] ?? [], $now, $operation, $uid, $shelfmark, $warnings);
						$groupedCopies++;
					} else {
						$groupedCopies++;
					}
					if ($group !== null) {
						$groupWorks[$group] ??= $groupWork;
					}
					$this->createVersionAndCopy($groupWork, $values, $row, $now, $operation, $uid);
					$workOfShelfmark[$shelfmark] = $groupWork;
					$counts['created']++;
					continue;
				}

				$version = $versions[$copy->getVersionId()] ?? null;
				$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
				if ($version === null || $work === null) {
					$warnings[] = ['Shelf mark %s: version or work missing in the database, skipped.', [$shelfmark]];
					continue;
				}
				if ($version->getPreservationCopy() === true) {
					$preservationCopies++;
				} elseif ($musicxml && ($values[Fields::VERSION]['rights_status'] ?? $version->getRightsStatus()) === 'protected') {
					$warnings[] = ['Shelf mark %s: protected, with a MusicXML file, but not marked as internal preservation copy. Please check the column "Veröffentlichung".', [$shelfmark]];
				}
				self::tally($distribution['status'], $values[Fields::COPY]['status'] ?? $copy->getStatus());

				$workValues = $values[Fields::WORK] ?? [];
				if ($group !== null) {
					$groupWork = $groupWorks[$group] ?? $workOfShelfmark[$group] ?? null;
					if ($groupWork !== null && $groupWork->getId() !== $work->getId()) {
						$warnings[] = ['Shelf mark %s belongs to the work group %s, but is a separate work in the catalog. Merge the works in the app ("Merge"); the import does not move versions.', [$shelfmark, $group]];
						$workValues = [];
					} else {
						$groupWorks[$group] ??= $work;
						if (!$isMainOfGroup) {
							// title, composer and figures of the work come from its main copy
							$workValues = [];
						}
					}
				}
				$changedFields = 0;
				$changedFields += $this->apply($work, Fields::WORK, $workValues, $now, $operation, $uid, $shelfmark, $warnings);
				$changedFields += $this->apply($version, Fields::VERSION, $values[Fields::VERSION], $now, $operation, $uid, $shelfmark, $warnings);
				$copy->setSortOrder($row);
				$changedFields += $this->apply($copy, Fields::COPY, $values[Fields::COPY], $now, $operation, $uid, $shelfmark, $warnings);
				if ($copy->getUpdatedFields() !== []) {
					$this->copies->update($copy);
				}
				$counts['fieldsChanged'] += $changedFields;
				$counts[$changedFields > 0 ? 'changed' : 'unchanged']++;
			}
			if ($validCellsSkipped > 0) {
				$warnings[] = ['The column "%s" was not imported (%s filled cells): which version is valid for the choir is set by the conductor in the app.', [CatalogCsv::VALID_COLUMN, $validCellsSkipped]];
			}

			if ($dryRun) {
				$this->db->rollBack();
			} else {
				$this->audit->record($operation, $uid, 'import', null, $restore ? 'restore' : 'import', null, null, $source);
				$this->db->commit();
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		foreach ($distribution as &$counter) {
			arsort($counter);
		}
		unset($counter);

		return [
			'dryRun' => $dryRun,
			'source' => $source,
			'sha256' => hash('sha256', $content),
			'operation' => $dryRun ? null : $operation,
			'restore' => $restore,
			'snapshot' => $snapshot,
			'snapshotOmitted' => $snapshotOmitted,
			...$counts,
			'notInCsv' => array_values(array_diff(array_map('strval', array_keys($existing)), array_keys($seen))),
			'withMusicxml' => $withMusicxml,
			'preservationCopies' => $preservationCopies,
			'rule1Assumed' => $rule1Assumed,
			'workGroups' => count($groupWorks),
			'groupedCopies' => $groupedCopies,
			'distribution' => $distribution,
			'warnings' => $warnings,
		];
	}

	/**
	 * Whether the reasoning of the publication note says the original is
	 * still to be checked ("ZU PRÜFEN" in the source catalog).
	 */
	public static function saysToBeChecked(?string $reason): bool {
		return $reason !== null && mb_stripos($reason, 'zu prüfen') !== false;
	}

	/**
	 * @param array<string, string|int|bool|null> $values
	 */
	private function createWork(array $values, int $now): Work {
		$work = new Work();
		foreach ($values as $field => $value) {
			$work->setValue($field, $value);
		}
		$work->setCreated($now);
		$work->setModified($now);
		return $this->works->insert($work);
	}

	/**
	 * A version and its copy of a work, from one row.
	 *
	 * @param array<string, array<string, string|int|bool|null>> $values
	 */
	private function createVersionAndCopy(Work $work, array $values, int $sortOrder, int $now, string $operation, string $uid): void {
		$version = new WorkVersion();
		foreach ($values[Fields::VERSION] as $field => $value) {
			$version->setValue($field, $value);
		}
		$version->setWorkId($work->getId());
		if (!array_key_exists('rights_status_import', $values[Fields::VERSION])) {
			$version->setValue('rights_status_import', $values[Fields::VERSION]['rights_status'] ?? null);
		}
		$version->setCreated($now);
		$version->setModified($now);
		$version = $this->versions->insert($version);

		$copy = new Copy();
		foreach ($values[Fields::COPY] as $field => $value) {
			if ($value === null && Fields::isNotNull(Fields::COPY, $field)) {
				continue;
			}
			$copy->setValue($field, $value);
		}
		$copy->setVersionId($version->getId());
		$copy->setSortOrder($sortOrder);
		$copy->setCreated($now);
		$copy->setModified($now);
		$copy = $this->copies->insert($copy);

		$this->audit->record($operation, $uid, Fields::COPY, $copy->getId(), 'created', 'shelfmark', null, $copy->getShelfmark(), 'import');
	}

	/**
	 * Takes over changed values into an existing entity and records each
	 * change. Copies are saved by the caller because their sort order changes
	 * too.
	 *
	 * @param array<string, string|int|bool|null> $values
	 * @param list<Warning> $warnings
	 * @return int number of changed fields
	 */
	private function apply(Work|WorkVersion|Copy $entity, string $level, array $values, int $now, string $operation,
		string $uid, string $shelfmark, array &$warnings): int {
		$changed = 0;
		foreach ($values as $field => $new) {
			$old = $entity->getValue($field);
			if ($old === $new || ($old !== null && $new !== null && (string)$old === (string)$new)) {
				continue;
			}
			if ($new === null && Fields::isNotNull($level, $field)) {
				continue;
			}
			$info = Fields::info($level, $field);
			if (($info['decision'] ?? false) && $old !== null) {
				$warnings[] = ['Shelf mark %s: "%s" is already set and is not overwritten by the import.', [$shelfmark, $info['label'] ?? $field]];
				continue;
			}
			if ($field === 'rights_status' && $entity instanceof WorkVersion && $entity->getValue('confirmed_at') !== null) {
				// confirmed by a person in the app, with reason and evidence: the file does not undo that
				$warnings[] = ['Shelf mark %s: the rights status was confirmed in the app and is not overwritten by the import.', [$shelfmark]];
				continue;
			}
			if ($field === 'work_no' && $new === null) {
				// an empty "Werkgruppe" does not take the work number away that a merge gave the work
				continue;
			}
			$entity->setValue($field, $new);
			$this->audit->record($operation, $uid, $level, $entity->getId(), 'import', $field, $old, $new);
			$changed++;
		}
		if ($changed > 0) {
			$entity->setModified($now);
			if ($entity instanceof Work) {
				$this->works->update($entity);
			} elseif ($entity instanceof WorkVersion) {
				$this->versions->update($entity);
			}
		}
		return $changed;
	}

	/**
	 * The header and the rows of the import file, by the extension of its
	 * name: an Excel workbook (.xlsx), an OpenDocument spreadsheet (.ods) or
	 * CSV (UTF-8, semicolons, commas or tabs). Of a workbook the first sheet whose first rows
	 * hold a column "Signatur", from that row on, otherwise the first row of
	 * the first sheet; `sheet` names the sheet read when the file has more
	 * than one.
	 *
	 * @return array{header: list<string>, rows: list<list<string>>, sheet: ?string, separator?: string}
	 * @throws InvalidInputException
	 */
	private function table(string $content, string $source): array {
		$sheets = match (strtolower(pathinfo($source, PATHINFO_EXTENSION))) {
			'xlsx' => $this->xlsx->read($content),
			'ods' => $this->ods->read($content),
			default => null,
		};
		if ($sheets === null) {
			if (str_starts_with($content, self::ZIP_START) || str_starts_with($content, self::OLE_START)) {
				// a workbook under another name, or an Excel 97–2003 file (.xls)
				throw new InvalidInputException('The file is neither CSV nor .xlsx or .ods. Please save it in one of these formats, for example in Excel with "Save as" → "Excel workbook (.xlsx)".');
			}
			return CatalogCsv::parse($content) + ['sheet' => null];
		}
		foreach ($sheets as ['name' => $name, 'rows' => $rows]) {
			foreach (array_slice($rows, 0, self::HEADER_ROWS) as $index => $row) {
				$header = array_map('trim', $row);
				if (in_array('Signatur', $header, true)) {
					return ['header' => $header, 'rows' => array_slice($rows, $index + 1), 'sheet' => count($sheets) > 1 ? $name : null];
				}
			}
		}
		return ['header' => array_map('trim', $sheets[0]['rows'][0] ?? []), 'rows' => [], 'sheet' => null];
	}

	/**
	 * Converts a cell into the stored value of a field.
	 *
	 * @param FieldInfo $info
	 * @return array{0: bool, 1: string|int|bool|null} whether the field accepts the cell, and the value
	 */
	private static function convert(string $text, string $header, array $info): array {
		if (trim($text) === '') {
			return [true, null];
		}
		$value = match ($info['type']) {
			Fields::TYPE_NUMBER => preg_match('/^-?\d+$/', trim($text)) === 1 ? (int)trim($text) : $text,
			Fields::TYPE_BOOLEAN => filter_var(trim($text), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $text,
			Fields::TYPE_CHOICE => CatalogCsv::legacyValue($header, $text),
			Fields::TYPE_DATE => trim($text),
			default => $text,
		};
		return [FieldValue::check($info, $value) === null, $value];
	}

	/**
	 * @param array<string, int> $counter
	 */
	private static function tally(array &$counter, string|int|bool|null $value): void {
		$key = $value === null ? '' : (string)$value;
		$counter[$key] = ($counter[$key] ?? 0) + 1;
	}

	/**
	 * Sets the normalized shelf mark; binder and number default to its parts.
	 *
	 * @param array<string, string|int|bool|null> $copy
	 * @return array<string, string|int|bool|null>
	 */
	private static function withShelfmark(array $copy, string $shelfmark, int $binder, int $number): array {
		$copy['shelfmark'] = $shelfmark;
		$copy['binder'] ??= $binder;
		$copy['number'] ??= $number;
		return $copy;
	}

	private static function text(string|int|bool|null $value): ?string {
		return is_string($value) ? $value : null;
	}

	private static function shorten(?string $text): ?string {
		if ($text === null) {
			return null;
		}
		$text = trim($text);
		return mb_strlen($text) > 40 ? mb_substr($text, 0, 40) . '…' : $text;
	}
}
