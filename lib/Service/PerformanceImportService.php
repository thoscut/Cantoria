<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Imports existing performance records and song lists from an Excel workbook
 * (or CSV with the same columns).
 *
 * Recognised sheets by their header row:
 * - programme lines: Datum, Anlass, Ort, Titel laut Programm,
 *   Komponist/Satz laut Programm, Mitwirkung, Signatur, Zuordnung, Stand, Quelle
 * - song list lines: Liste, Stand, Quelle, Nr, Titel wie gedruckt, Signatur,
 *   Zuordnung, Kandidaten, Bemerkung
 *
 * A repeated import replaces the previously imported events of the same kind;
 * events planned in the app are never touched. A dry run rolls back.
 *
 * Counting rule (from the archive's own documentation): a line is a documented
 * performance when it was sung by the choir itself (participation empty or
 * equal to the own choir's name) and comes from a final programme — drafts,
 * reserve pieces and "encore if needed" do not count; ambiguous lines count
 * only once they are resolved.
 *
 * @psalm-type Sheet = array{sheet: string, header: list<string>, rows: list<list<string>>}
 * @psalm-type Report = array{dryRun: bool, source: string, sha256: string, snapshot: ?string, events: int, songLists: int, items: int, certain: int, ambiguous: int, notInCatalog: int, counted: int, keptAssignments: int, ownChoir: ?string, unknownShelfmarks: list<string>, sheets: list<string>}
 */
class PerformanceImportService {
	private const PROGRAMME_HEADER = ['Datum', 'Anlass', 'Titel laut Programm', 'Signatur'];
	private const SONG_LIST_HEADER = ['Liste', 'Titel wie gedruckt', 'Signatur'];

	public function __construct(
		private IDBConnection $db,
		private XlsxReader $xlsx,
		private EventMapper $events,
		private EventItemMapper $items,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private ExportService $export,
		private AuditLog $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return Report
	 */
	public function import(string $content, string $fileName, string $uid, bool $dryRun, ?string $ownChoir): array {
		if (str_ends_with(mb_strtolower($fileName), '.csv')) {
			$csv = CatalogCsv::parse($content);
			$sheets = [['name' => 'CSV', 'rows' => array_merge([$csv['header']], $csv['rows'])]];
		} else {
			$sheets = $this->xlsx->read($content);
		}

		/** @var Sheet|null $programme */
		$programme = null;
		/** @var Sheet|null $songLists */
		$songLists = null;
		foreach ($sheets as ['name' => $name, 'rows' => $rows]) {
			foreach ($rows as $index => $row) {
				$cells = array_map('trim', $row);
				if ($programme === null && self::hasAll($cells, self::PROGRAMME_HEADER)) {
					$programme = ['sheet' => $name, 'header' => $cells, 'rows' => array_slice($rows, $index + 1)];
					break;
				}
				if ($songLists === null && self::hasAll($cells, self::SONG_LIST_HEADER)) {
					$songLists = ['sheet' => $name, 'header' => $cells, 'rows' => array_slice($rows, $index + 1)];
					break;
				}
				if ($index > 5) {
					break;
				}
			}
		}
		if ($programme === null && $songLists === null) {
			throw new InvalidInputException('No sheet with programme lines or song list lines was found.');
		}

		$copies = $this->copies->findAllByShelfmark();
		$copyIds = [];
		foreach ($copies as $copy) {
			$copyIds[$copy->getId()] = true;
		}
		$versions = $this->versions->findAllById();
		$sheetNames = [];
		foreach ([$programme, $songLists] as $found) {
			if ($found !== null) {
				$sheetNames[] = $found['sheet'];
			}
		}
		$report = ['dryRun' => $dryRun, 'source' => $fileName, 'sha256' => hash('sha256', $content), 'snapshot' => null,
			'events' => 0, 'songLists' => 0, 'items' => 0, 'certain' => 0, 'ambiguous' => 0, 'notInCatalog' => 0,
			'counted' => 0, 'keptAssignments' => 0, 'ownChoir' => null, 'unknownShelfmarks' => [], 'sheets' => $sheetNames];

		if (!$dryRun) {
			$report['snapshot'] = $this->export->snapshot('before-performance-import', $uid);
		}
		$operation = $this->audit->newOperation();
		$now = $this->time->getTime();

		$this->db->beginTransaction();
		try {
			if ($programme !== null) {
				$beforeConcerts = $this->removeImported(Event::CONCERT);
				$ownChoir ??= self::mostFrequent($programme, 'Mitwirkung');
				$report['ownChoir'] = $ownChoir;
				/** @var array<string, non-empty-list<array<string, string>>> $groups */
				$groups = [];
				foreach ($programme['rows'] as $row) {
					$r = self::assoc($programme['header'], $row);
					if (trim(implode('', $r)) === '' || trim($r['Titel laut Programm'] ?? '') === '') {
						continue;
					}
					$key = implode("\0", [trim($r['Datum'] ?? ''), trim($r['Anlass'] ?? ''), trim($r['Ort'] ?? '')]);
					$groups[$key][] = $r;
				}
				foreach ($groups as $lines) {
					$first = $lines[0];
					$event = new Event();
					$event->setKind(Event::CONCERT);
					$event->setValue('title', self::cut($first['Anlass'] ?? null, 500));
					$event->setValue('event_date', self::date($first['Datum'] ?? null));
					$event->setValue('location', self::cut($first['Ort'] ?? null, 500));
					$event->setStatus('sung');
					$event->setImported(true);
					$event->setValue('source', $first['Quelle'] ?? null);
					$event->setCreatedBy($uid);
					$event->setCreated($now);
					$event->setModified($now);
					$event = $this->events->insert($event);
					$report['events']++;
					foreach ($lines as $position => $r) {
						$item = $this->item($event->getId(), $position + 1, $r['Signatur'] ?? '', $r['Zuordnung'] ?? '', $copies, $versions, $report);
						$item->setValue('title_text', self::cut($r['Titel laut Programm'] ?? null, 1000));
						$item->setValue('composer_text', self::cut($r['Komponist/Satz laut Programm'] ?? null, 1000));
						$item->setValue('participation', self::cut($r['Mitwirkung'] ?? null, 255));
						$item->setValue('state', self::cut($r['Stand'] ?? null, 255));
						self::keepResolved($event, $item, $beforeConcerts, $copyIds, $report);
						$item->setCounts($item->getAssignment() === EventItem::CERTAIN
							&& self::countsByRule($item->getParticipation(), $item->getState(), $ownChoir));
						$this->items->insert($item);
						$report['counted'] += $item->getCounts() === true ? 1 : 0;
					}
				}
			}

			if ($songLists !== null) {
				$beforeLists = $this->removeImported(Event::SONG_LIST);
				// Lists in order of appearance; the name stays a value (a list "2024" must not become an integer key).
				/** @var list<array{period: string, lines: non-empty-list<array<string, string>>}> $lists */
				$lists = [];
				$position = [];
				foreach ($songLists['rows'] as $row) {
					$r = self::assoc($songLists['header'], $row);
					$period = trim($r['Liste'] ?? '');
					if ($period === '' || trim($r['Titel wie gedruckt'] ?? '') === '') {
						continue;
					}
					if (isset($position[$period])) {
						$lists[$position[$period]]['lines'][] = $r;
					} else {
						$position[$period] = count($lists);
						$lists[] = ['period' => $period, 'lines' => [$r]];
					}
				}
				foreach ($lists as ['period' => $period, 'lines' => $lines]) {
					$event = new Event();
					$event->setKind(Event::SONG_LIST);
					$event->setValue('title', self::cut($period, 500));
					$event->setValue('period', self::cut($period, 32));
					$event->setValue('event_date', self::date($lines[0]['Stand'] ?? null));
					$event->setStatus('fixed');
					$event->setImported(true);
					$event->setValue('source', $lines[0]['Quelle'] ?? null);
					$event->setCreatedBy($uid);
					$event->setCreated($now);
					$event->setModified($now);
					$event = $this->events->insert($event);
					$report['songLists']++;
					foreach ($lines as $index => $r) {
						$nr = filter_var($r['Nr'] ?? '', FILTER_VALIDATE_INT);
						$candidates = trim($r['Kandidaten'] ?? '');
						$item = $this->item($event->getId(), $nr === false ? $index + 1 : $nr,
							$candidates !== '' && trim($r['Signatur'] ?? '') === '' ? $candidates : ($r['Signatur'] ?? ''),
							$r['Zuordnung'] ?? '', $copies, $versions, $report);
						$item->setValue('title_text', self::cut($r['Titel wie gedruckt'] ?? null, 1000));
						$item->setValue('note', $r['Bemerkung'] ?? null);
						self::keepResolved($event, $item, $beforeLists, $copyIds, $report);
						$item->setCounts(false);
						$this->items->insert($item);
					}
				}
			}

			if ($dryRun) {
				$this->db->rollBack();
			} else {
				$this->audit->record($operation, $uid, 'import', null, 'performance_import', null, null, $fileName);
				$this->db->commit();
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$report['unknownShelfmarks'] = array_values(array_unique($report['unknownShelfmarks']));
		return $report;
	}

	/**
	 * The counting rule for documented performances.
	 */
	public static function countsByRule(?string $participation, ?string $state, ?string $ownChoir): bool {
		$participation = trim((string)$participation);
		if ($participation !== '' && $ownChoir !== null && $ownChoir !== '' && mb_strtolower($participation) !== mb_strtolower($ownChoir)) {
			return false;
		}
		$state = mb_strtolower((string)$state);
		foreach (['entwurf', 'draft', 'reserve', 'nach bedarf', 'if needed'] as $excluded) {
			if (str_contains($state, $excluded)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, \OCA\Cantoria\Db\Copy> $copies
	 * @param array<int, \OCA\Cantoria\Db\WorkVersion> $versions
	 * @param Report $report
	 */
	private function item(int $eventId, int $position, string $shelfmarks, string $assignment, array $copies, array $versions, array &$report): EventItem {
		$item = new EventItem();
		$item->setEventId($eventId);
		$item->setPosition($position);
		$marks = array_values(array_filter(array_map(fn (string $s): string => self::normalize($s), preg_split('/[|,]/', $shelfmarks) ?: []),
			fn (string $mark): bool => $mark !== ''));
		$assignmentText = mb_strtolower(trim($assignment));
		if ($marks === [] || str_contains($assignmentText, 'nicht im')) {
			$item->setAssignment(EventItem::NOT_IN_CATALOG);
			$report['notInCatalog']++;
		} elseif (count($marks) > 1 || str_contains($assignmentText, 'mehrdeutig') || str_contains($assignmentText, 'unsicher')) {
			$item->setAssignment(EventItem::AMBIGUOUS);
			$item->setValue('candidates', implode(' | ', $marks));
			$report['ambiguous']++;
		} elseif (!isset($copies[$marks[0]])) {
			$item->setAssignment(EventItem::NOT_IN_CATALOG);
			$item->setValue('candidates', $marks[0]);
			$report['unknownShelfmarks'][] = $marks[0];
			$report['notInCatalog']++;
		} else {
			$copy = $copies[$marks[0]];
			$version = $versions[$copy->getVersionId()] ?? null;
			$item->setAssignment(EventItem::CERTAIN);
			$item->setValue('copy_id', $copy->getId());
			$item->setValue('version_id', $version?->getId());
			$item->setValue('work_id', $version?->getWorkId());
			$report['certain']++;
		}
		$report['items']++;
		return $item;
	}

	/**
	 * Deletes the imported events of a kind and returns their lines by
	 * line key, so that an assignment made by hand afterwards
	 * (EventService::resolveItem) survives a new import of the same file.
	 *
	 * @return array<string, EventItem>
	 */
	private function removeImported(string $kind): array {
		$before = [];
		foreach ($this->events->findImported() as $event) {
			if ($event->getKind() === $kind) {
				foreach ($this->items->findByEvent($event->getId()) as $item) {
					$before[self::lineKey($event, $item)] = $item;
				}
				$this->items->deleteByEvent($event->getId());
				$this->events->delete($event);
			}
		}
		return $before;
	}

	/**
	 * What identifies a line across imports: its event (kind, date, title,
	 * place, list), its position and its title as printed.
	 */
	private static function lineKey(Event $event, EventItem $item): string {
		return implode("\0", [$event->getKind(), (string)$event->getEventDate(), (string)$event->getTitle(), (string)$event->getLocation(),
			(string)$event->getPeriod(), (string)$item->getPosition(), (string)$item->getTitleText()]);
	}

	/**
	 * A line the import could not assign for certain takes over the
	 * assignment of the same line before, if that differs (someone resolved
	 * it by hand) and its copy still exists; the counts of the report follow.
	 *
	 * @param array<string, EventItem> $before
	 * @param array<int, true> $copyIds copies that exist
	 * @param Report $report
	 */
	private static function keepResolved(Event $event, EventItem $item, array $before, array $copyIds, array &$report): void {
		$old = $before[self::lineKey($event, $item)] ?? null;
		if ($old === null || $item->getAssignment() === EventItem::CERTAIN || $old->getAssignment() === $item->getAssignment()) {
			return;
		}
		if ($old->getAssignment() === EventItem::CERTAIN && !isset($copyIds[(int)$old->getCopyId()])) {
			return;
		}
		$report[$item->getAssignment() === EventItem::AMBIGUOUS ? 'ambiguous' : 'notInCatalog']--;
		$report[$old->getAssignment() === EventItem::CERTAIN ? 'certain' : ($old->getAssignment() === EventItem::AMBIGUOUS ? 'ambiguous' : 'notInCatalog')]++;
		$item->setAssignment($old->getAssignment());
		$item->setValue('copy_id', $old->getCopyId());
		$item->setValue('version_id', $old->getVersionId());
		$item->setValue('work_id', $old->getWorkId());
		$report['keptAssignments']++;
	}

	/**
	 * @param list<string> $cells
	 * @param list<string> $required
	 */
	private static function hasAll(array $cells, array $required): bool {
		return array_diff($required, $cells) === [];
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $row
	 * @return array<string, string>
	 */
	private static function assoc(array $header, array $row): array {
		$result = [];
		foreach ($header as $i => $name) {
			if ($name !== '') {
				$result[$name] = $row[$i] ?? '';
			}
		}
		return $result;
	}

	/**
	 * The most frequent value of a column; on a tie the one seen first.
	 *
	 * @param Sheet $sheet
	 */
	public static function mostFrequent(array $sheet, string $column): ?string {
		$index = array_search($column, $sheet['header'], true);
		if ($index === false) {
			return null;
		}
		// Values are kept as values (not as array keys), so "1990" stays a string.
		$values = [];
		$counts = [];
		foreach ($sheet['rows'] as $row) {
			$value = trim($row[$index] ?? '');
			if ($value === '') {
				continue;
			}
			$at = array_search($value, $values, true);
			if ($at === false) {
				$values[] = $value;
				$counts[] = 1;
			} else {
				$counts[$at]++;
			}
		}
		$best = null;
		foreach ($counts as $at => $count) {
			if ($best === null || $count > $counts[$best]) {
				$best = $at;
			}
		}
		return $best === null ? null : $values[$best];
	}

	/**
	 * Keeps YYYY-MM-DD and plain years; converts Excel serial dates.
	 */
	private static function date(?string $value): ?string {
		$value = trim((string)$value);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) && FieldValue::isDate($m[1])) {
			return $m[1];
		}
		if (preg_match('/^\d{4}$/', $value)) {
			return $value;
		}
		if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
			return gmdate('Y-m-d', ((int)$value - 25569) * 86400);
		}
		return null;
	}

	private static function normalize(string $shelfmark): string {
		return preg_match('/^\s*0*(\d+)-0*(\d+)\s*$/', $shelfmark, $m) ? (int)$m[1] . '-' . (int)$m[2] : '';
	}

	private static function cut(?string $value, int $max): ?string {
		$value = $value === null ? null : trim($value);
		return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
	}
}
