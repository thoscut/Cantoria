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
 * Imports the catalog from CSV. Each row is a shelf mark; a new row creates
 * one work, one version and one copy.
 *
 * A repeated import matches rows by shelf mark and takes over changed values
 * while the CSV is still the leading source. Nothing is deleted: shelf marks
 * missing from the CSV are only listed in the report.
 *
 * Rules that keep people in charge:
 * - Invalid values are reported and skipped; the stored value stays.
 * - Decision fields (e.g. rights confirmed) are filled while empty but never
 *   overwritten; a differing CSV value is reported instead.
 * - The preservation copy flag is derived from the legacy publication note
 *   only when a version is created, so manual corrections survive.
 * - Read-only fields outside the legacy columns are only taken over when
 *   restoring a backup into an empty catalog.
 *
 * A dry run performs the whole import and rolls the database back afterwards.
 * Before a real import into a non-empty catalog, a snapshot is stored in the
 * export folder.
 *
 * @psalm-import-type FieldInfo from Fields
 * @psalm-type Warning = array{0: string, 1: list<string|int>}
 * @psalm-type Report = array{dryRun: bool, source: string, sha256: string, operation: ?string, restore: bool, snapshot: ?string,
 *     rows: int, created: int, changed: int, unchanged: int, fieldsChanged: int, notInCsv: list<string>, withMusicxml: int,
 *     preservationCopies: int, distribution: array{rightsStatus: array<string, int>, repertoire: array<string, int>, publication: array<string, int>},
 *     warnings: list<Warning>}
 */
class ImportService {
	public function __construct(
		private IDBConnection $db,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ExportService $export,
		private AuditLog $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return Report `warnings` holds [message, parameters] pairs
	 */
	public function import(string $content, string $source, string $uid, bool $dryRun): array {
		$csv = CatalogCsv::parse($content);
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
			throw new InvalidInputException('The CSV has no column "Signatur". Is it the catalog?');
		}
		$missingColumns = array_values(array_filter(array_keys(CatalogCsv::LEGACY_COLUMNS), fn (string $c): bool => !isset($present[$c])));

		$existing = $this->copies->findAllByShelfmark();
		$restore = $existing === [];

		/** @var list<Warning> $warnings */
		$warnings = [];
		if ($unknown !== []) {
			$warnings[] = ['Unknown columns are ignored: %s', [implode(', ', $unknown)]];
		}
		if ($missingColumns !== []) {
			$warnings[] = ['Catalog columns are missing: %s', [implode(', ', $missingColumns)]];
		}

		$snapshot = null;
		if (!$dryRun && !$restore) {
			$snapshot = $this->export->snapshot('before-import', $uid);
		}

		$operation = $this->audit->newOperation();
		$now = $this->time->getTime();
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();

		$counts = ['rows' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0, 'fieldsChanged' => 0];
		/** @var array{rightsStatus: array<string, int>, repertoire: array<string, int>, publication: array<string, int>} $distribution */
		$distribution = ['rightsStatus' => [], 'repertoire' => [], 'publication' => []];
		$withMusicxml = 0;
		$preservationCopies = 0;
		$seen = [];

		$this->db->beginTransaction();
		try {
			$row = 0;
			foreach ($csv['rows'] as $fields) {
				if (trim(implode('', $fields)) === '') {
					continue;
				}
				$row++;
				$counts['rows']++;
				/** @var array<string, array<string, string|int|bool|null>> $values */
				$values = [Fields::WORK => [], Fields::VERSION => [], Fields::COPY => []];
				foreach ($mapping as $i => [$level, $field]) {
					$info = Fields::info($level, $field);
					if ($info === null) {
						continue;
					}
					$header = $csv['header'][$i];
					if ($info['write'] === [] && !CatalogCsv::isLegacyColumn($header) && !$restore) {
						continue;
					}
					[$valid, $value] = self::convert($fields[$i] ?? '', $header, $info);
					if (!$valid) {
						$warnings[] = ['Row %s, column "%s": the value "%s" is not valid there and was not imported.', [$row, $header, mb_substr($fields[$i] ?? '', 0, 60)]];
						continue;
					}
					$values[$level][$field] = $value;
				}

				$rawShelfmark = trim((string)($values[Fields::COPY]['shelfmark'] ?? ''));
				if (!preg_match('/^0*(\d+)-0*(\d+)$/', $rawShelfmark, $parts)) {
					$warnings[] = ['Row %s: shelf mark "%s" is invalid and was skipped.', [$row, $rawShelfmark]];
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
				if (($values[Fields::VERSION]['musicxml_file'] ?? null) !== null) {
					$withMusicxml++;
				}

				$copy = $existing[$shelfmark] ?? null;
				if ($copy === null) {
					if (!array_key_exists('preservation_copy', $values[Fields::VERSION])) {
						$values[Fields::VERSION]['preservation_copy'] = CatalogCsv::isLegacyPreservationCopy($publication);
					}
					if ($values[Fields::VERSION]['preservation_copy'] === true) {
						$preservationCopies++;
					}
					if (!array_key_exists('publication_status', $values[Fields::VERSION])) {
						$values[Fields::VERSION]['publication_status'] = CatalogCsv::legacyPublicationStatus($publication);
					}
					$this->create($values, $row, $now, $operation, $uid);
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
				}
				$changedFields = 0;
				$changedFields += $this->apply($work, Fields::WORK, $values[Fields::WORK], $now, $operation, $uid, $shelfmark, $warnings);
				$changedFields += $this->apply($version, Fields::VERSION, $values[Fields::VERSION], $now, $operation, $uid, $shelfmark, $warnings);
				$copy->setSortOrder($row);
				$changedFields += $this->apply($copy, Fields::COPY, $values[Fields::COPY], $now, $operation, $uid, $shelfmark, $warnings);
				if ($copy->getUpdatedFields() !== []) {
					$this->copies->update($copy);
				}
				$counts['fieldsChanged'] += $changedFields;
				$counts[$changedFields > 0 ? 'changed' : 'unchanged']++;
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
			...$counts,
			'notInCsv' => array_values(array_diff(array_map('strval', array_keys($existing)), array_keys($seen))),
			'withMusicxml' => $withMusicxml,
			'preservationCopies' => $preservationCopies,
			'distribution' => $distribution,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array<string, array<string, string|int|bool|null>> $values
	 */
	private function create(array $values, int $sortOrder, int $now, string $operation, string $uid): void {
		$work = new Work();
		foreach ($values[Fields::WORK] as $field => $value) {
			$work->setValue($field, $value);
		}
		$work->setCreated($now);
		$work->setModified($now);
		$work = $this->works->insert($work);

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
	 * Converts a CSV cell into the stored value of a field.
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
			Fields::TYPE_CHOICE => $header === 'Rechtestatus' ? (CatalogCsv::LEGACY_RIGHTS_STATUS[trim($text)] ?? $text) : trim($text),
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
