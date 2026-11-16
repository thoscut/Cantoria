<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
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
 * performance when
 * - it was sung by the choir itself: participation empty or one of the
 *   counting participations (default: the most frequent value of the column,
 *   e.g. the own choir; a second value such as "joint" lets concerts sung
 *   together with other choirs count as well),
 * - it comes from a final programme: a state that contains one of the
 *   excluded words (drafts, reserve pieces, "encore if needed", uncertain
 *   lines, mere work or song lists; DEFAULT_EXCLUDED_STATES) does not count,
 * - it is assigned to a copy with certainty; ambiguous lines count only once
 *   they are resolved,
 * - and it is not a duplicate of a programme with a full date: a line of an
 *   event dated only by a year (`YYYY`, e.g. an annual list) or by a month
 *   (`YYYY-MM`) does not count when the same work (or the same shelf mark,
 *   when the work is unknown) counts already in an event with a day date in
 *   that year (or month). Otherwise the same concert would be counted twice.
 *
 * Dates: `event_date` keeps `YYYY-MM-DD`, `YYYY-MM` and `YYYY` as the file
 * has them (the column is a string of 10 characters). Every reader of the
 * date checks `FieldValue::isDate()` before treating it as a calendar day,
 * or takes the year from the first four characters.
 *
 * @psalm-type Sheet = array{sheet: string, header: list<string>, rows: list<list<string>>}
 * @psalm-type Report = array{dryRun: bool, source: string, sha256: string, snapshot: ?string, events: int, songLists: int, items: int, certain: int, ambiguous: int, notInCatalog: int, counted: int, keptAssignments: int, deduplicated: int, ownChoir: ?string, countingParticipations: list<string>, excludedStates: list<string>, unknownShelfmarks: list<string>, sheets: list<string>}
 * @psalm-type Resolved = array{assignment: string, marks: list<string>, copy: ?Copy, version: ?WorkVersion, key: string, unknown: bool}
 */
class PerformanceImportService {
	/**
	 * Words in the state of a programme line (lower case, substring) that
	 * mark it as no documented performance: drafts, reserve pieces, encores
	 * "if needed", uncertain lines and mere work or song lists.
	 */
	public const DEFAULT_EXCLUDED_STATES = ['entwurf', 'draft', 'reserve', 'nach bedarf', 'if needed',
		'fraglich', 'werkliste', 'notenliste', 'unsicher', 'uncertain'];

	private const PROGRAMME_HEADER = ['Datum', 'Anlass', 'Titel laut Programm', 'Signatur'];
	private const SONG_LIST_HEADER = ['Liste', 'Titel wie gedruckt', 'Signatur'];

	/** Words of a composer text that name no person (lower case). */
	private const NOT_A_NAME = ['satz', 'arr', 'bearb', 'bearbeitung', 'text', 'worte', 'words', 'melodie', 'weise', 'musik', 'music',
		'und', 'von', 'der', 'die', 'das', 'nach', 'aus', 'für', 'the', 'and', 'for', 'volkslied', 'volksweise', 'trad', 'anonym',
		'chor', 'männerchor', 'frauenchor', 'gemischter', 'arrangement', 'arranged'];

	public function __construct(
		private IDBConnection $db,
		private XlsxReader $xlsx,
		private EventMapper $events,
		private EventItemMapper $items,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ExportService $export,
		private AuditLog $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param list<string> $countingParticipations participations that count (compared case-insensitively and trimmed); empty: the most frequent one
	 * @param list<string>|null $excludedStates words in the state that exclude a line (case-insensitive substrings); null: DEFAULT_EXCLUDED_STATES
	 * @return Report
	 */
	public function import(string $content, string $fileName, string $uid, bool $dryRun, array $countingParticipations = [], ?array $excludedStates = null): array {
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
		$works = $this->works->findAllById();
		$sheetNames = [];
		foreach ([$programme, $songLists] as $found) {
			if ($found !== null) {
				$sheetNames[] = $found['sheet'];
			}
		}
		$counting = self::cleanList($countingParticipations);
		$excluded = $excludedStates === null ? self::DEFAULT_EXCLUDED_STATES : self::cleanList($excludedStates);
		$report = ['dryRun' => $dryRun, 'source' => $fileName, 'sha256' => hash('sha256', $content), 'snapshot' => null,
			'events' => 0, 'songLists' => 0, 'items' => 0, 'certain' => 0, 'ambiguous' => 0, 'notInCatalog' => 0,
			'counted' => 0, 'keptAssignments' => 0, 'deduplicated' => 0, 'ownChoir' => null, 'countingParticipations' => $counting, 'excludedStates' => $excluded,
			'unknownShelfmarks' => [], 'sheets' => $sheetNames];

		if (!$dryRun) {
			$report['snapshot'] = $this->export->snapshot('before-performance-import', $uid);
		}
		$operation = $this->audit->newOperation();
		$now = $this->time->getTime();

		$this->db->beginTransaction();
		try {
			if ($programme !== null) {
				$beforeConcerts = $this->removeImported(Event::CONCERT);
				if ($counting === []) {
					$detected = self::mostFrequent($programme, 'Mitwirkung');
					$counting = $detected === null ? [] : [$detected];
					$report['countingParticipations'] = $counting;
				}
				$report['ownChoir'] = $counting[0] ?? null;
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
				// First pass: which work (or shelf mark) counts in which year and
				// month on an event with a day date. Lines of events dated only by
				// the year or the month must not count twice for the same concert.
				$dated = [];
				foreach ($groups as $lines) {
					$date = self::date($lines[0]['Datum'] ?? null);
					if ($date === null || strlen($date) !== 10) {
						continue;
					}
					foreach ($lines as $r) {
						$resolved = self::resolve($r['Signatur'] ?? '', $r['Zuordnung'] ?? '', $copies, $versions);
						if ($resolved['assignment'] === EventItem::CERTAIN
							&& self::countsByRule($r['Mitwirkung'] ?? null, $r['Stand'] ?? null, $counting, $excluded)) {
							$dated[$resolved['key'] . "\0" . substr($date, 0, 4)] = true;
							$dated[$resolved['key'] . "\0" . substr($date, 0, 7)] = true;
						}
					}
				}
				foreach ($groups as $lines) {
					$first = $lines[0];
					$date = self::date($first['Datum'] ?? null);
					$event = new Event();
					$event->setKind(Event::CONCERT);
					$event->setValue('title', self::cut($first['Anlass'] ?? null, 500));
					$event->setValue('event_date', $date);
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
						$resolved = self::resolve($r['Signatur'] ?? '', $r['Zuordnung'] ?? '', $copies, $versions);
						$item = $this->item($event->getId(), $position + 1, $resolved, $report);
						$item->setValue('title_text', self::cut($r['Titel laut Programm'] ?? null, 1000));
						$item->setValue('composer_text', self::cut($r['Komponist/Satz laut Programm'] ?? null, 1000));
						$item->setValue('participation', self::cut($r['Mitwirkung'] ?? null, 255));
						$item->setValue('state', self::cut($r['Stand'] ?? null, 255));
						self::keepResolved($event, $item, $beforeConcerts, $copyIds, $report);
						if ($item->getAssignment() === EventItem::AMBIGUOUS) {
							self::composerHint($item, $resolved['marks'], $r['Komponist/Satz laut Programm'] ?? null, $copies, $versions, $works);
						}
						$counts = $item->getAssignment() === EventItem::CERTAIN
							&& self::countsByRule($item->getParticipation(), $item->getState(), $counting, $excluded);
						if ($counts && $date !== null && strlen($date) < 10 && isset($dated[$resolved['key'] . "\0" . $date])) {
							$counts = false;
							$report['deduplicated']++;
						}
						$item->setCounts($counts);
						$this->items->insert($item);
						$report['counted'] += $counts ? 1 : 0;
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
						$resolved = self::resolve(
							$candidates !== '' && trim($r['Signatur'] ?? '') === '' ? $candidates : ($r['Signatur'] ?? ''),
							$r['Zuordnung'] ?? '', $copies, $versions);
						$item = $this->item($event->getId(), $nr === false ? $index + 1 : $nr, $resolved, $report);
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
	 * The counting rule for documented performances (participation and
	 * state; the assignment and the deduplication of annual lists are
	 * checked by the import itself).
	 *
	 * @param list<string> $countingParticipations participations that count besides an empty one; empty: every participation counts
	 * @param list<string>|null $excludedStates words that exclude a line (lower case, substring); null: DEFAULT_EXCLUDED_STATES
	 */
	public static function countsByRule(?string $participation, ?string $state, array $countingParticipations = [], ?array $excludedStates = null): bool {
		$participation = mb_strtolower(trim((string)$participation));
		$counting = array_map('mb_strtolower', self::cleanList($countingParticipations));
		if ($participation !== '' && $counting !== [] && !in_array($participation, $counting, true)) {
			return false;
		}
		$state = mb_strtolower((string)$state);
		foreach (array_map('mb_strtolower', $excludedStates === null ? self::DEFAULT_EXCLUDED_STATES : self::cleanList($excludedStates)) as $excluded) {
			if (str_contains($state, $excluded)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Splits a parameter "a | b, c" into a list, as the API takes it.
	 *
	 * @return list<string>
	 */
	public static function splitList(?string $value): array {
		return array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string)$value) ?: []), fn (string $s): bool => $s !== ''));
	}

	/**
	 * Trimmed, without empty values and without duplicates (compared
	 * case-insensitively; the first spelling is kept for the report).
	 *
	 * @param list<string> $values
	 * @return list<string>
	 */
	private static function cleanList(array $values): array {
		$clean = [];
		$seen = [];
		foreach ($values as $value) {
			$value = trim($value);
			$lower = mb_strtolower($value);
			if ($value !== '' && !in_array($lower, $seen, true)) {
				$clean[] = $value;
				$seen[] = $lower;
			}
		}
		return $clean;
	}

	/**
	 * What the shelf mark(s) and the assignment of a line say: the assignment
	 * class, the normalized marks, the copy and version when certain, and a
	 * key naming the work (or the shelf mark when the work is unknown) for
	 * the deduplication.
	 *
	 * @param array<string, Copy> $copies
	 * @param array<int, WorkVersion> $versions
	 * @return Resolved
	 */
	private static function resolve(string $shelfmarks, string $assignment, array $copies, array $versions): array {
		$marks = array_values(array_filter(array_map(fn (string $s): string => self::normalize($s), preg_split('/[|,]/', $shelfmarks) ?: []),
			fn (string $mark): bool => $mark !== ''));
		$assignmentText = mb_strtolower(trim($assignment));
		$result = ['assignment' => EventItem::NOT_IN_CATALOG, 'marks' => $marks, 'copy' => null, 'version' => null, 'key' => '', 'unknown' => false];
		if ($marks === [] || str_contains($assignmentText, 'nicht im')) {
			return $result;
		}
		if (count($marks) > 1 || str_contains($assignmentText, 'mehrdeutig') || str_contains($assignmentText, 'unsicher')) {
			$result['assignment'] = EventItem::AMBIGUOUS;
			return $result;
		}
		if (!isset($copies[$marks[0]])) {
			$result['unknown'] = true;
			return $result;
		}
		$copy = $copies[$marks[0]];
		$version = $versions[$copy->getVersionId()] ?? null;
		$workId = $version?->getWorkId();
		return ['assignment' => EventItem::CERTAIN, 'marks' => $marks, 'copy' => $copy, 'version' => $version,
			'key' => $workId === null ? 'mark:' . $marks[0] : 'work:' . $workId, 'unknown' => false];
	}

	/**
	 * @param Resolved $resolved
	 * @param Report $report
	 */
	private function item(int $eventId, int $position, array $resolved, array &$report): EventItem {
		$item = new EventItem();
		$item->setEventId($eventId);
		$item->setPosition($position);
		$item->setAssignment($resolved['assignment']);
		$marks = $resolved['marks'];
		if ($resolved['assignment'] === EventItem::AMBIGUOUS) {
			$item->setValue('candidates', implode(' | ', $marks));
			$report['ambiguous']++;
		} elseif ($resolved['assignment'] === EventItem::CERTAIN && $resolved['copy'] !== null) {
			$item->setValue('copy_id', $resolved['copy']->getId());
			$item->setValue('version_id', $resolved['version']?->getId());
			$item->setValue('work_id', $resolved['version']?->getWorkId());
			$report['certain']++;
		} else {
			if ($resolved['unknown']) {
				// A single shelf mark that is not in the catalog
				$item->setValue('candidates', $marks[0]);
				$report['unknownShelfmarks'][] = $marks[0];
			}
			$report['notInCatalog']++;
		}
		$report['items']++;
		return $item;
	}

	/**
	 * Composer rule, as a suggestion only (the assignment stays ambiguous):
	 * when the composer text of the programme names the composer or the
	 * arranger of exactly one candidate, that candidate comes first in
	 * `candidates` and the line gets a note. Names are compared by their
	 * words of at least three letters, so "Schubert" matches "F. Schubert"
	 * and "Schubert, arr. Smith"; words that name no person are ignored.
	 *
	 * @param list<string> $marks
	 * @param array<string, Copy> $copies
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $works
	 */
	private static function composerHint(EventItem $item, array $marks, ?string $composerText, array $copies, array $versions, array $works): void {
		$named = self::nameWords($composerText);
		if ($named === []) {
			return;
		}
		$matching = [];
		foreach ($marks as $mark) {
			$copy = $copies[$mark] ?? null;
			$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$words = self::nameWords(($work?->getComposer() ?? '') . ' ' . ($version?->getArrangerText() ?? ''));
			if (array_intersect($words, $named) !== []) {
				$matching[] = $mark;
			}
		}
		if (count($matching) !== 1) {
			return;
		}
		$item->setValue('candidates', implode(' | ', array_merge($matching, array_values(array_diff($marks, $matching)))));
		$item->setValue('note', 'Composer rule: ' . $matching[0] . ' matches "' . trim((string)$composerText) . '"');
	}

	/**
	 * The words of at least three letters of a composer text that may be
	 * names, in lower case.
	 *
	 * @return list<string>
	 */
	private static function nameWords(?string $text): array {
		preg_match_all('/\p{L}{3,}/u', mb_strtolower((string)$text), $matches);
		return array_values(array_diff(array_unique($matches[0]), self::NOT_A_NAME));
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
	 * Keeps YYYY-MM-DD, YYYY-MM (a month) and plain years; converts Excel
	 * serial dates. Anything else is no date (null).
	 */
	public static function date(?string $value): ?string {
		$value = trim((string)$value);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) && FieldValue::isDate($m[1])) {
			return $m[1];
		}
		if (preg_match('/^\d{4}(-(0[1-9]|1[0-2]))?$/', $value)) {
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
