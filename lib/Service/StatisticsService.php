<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\Row;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDateTimeZone;
use OCP\IDBConnection;

/**
 * Statistics for the board (annual report, general meeting), the archive
 * management (state of maintenance) and the conductor (repertoire).
 *
 * All archive roles may read them. They contain figures, works and persons of
 * the register (composers), but no personal data of choir members and no
 * account names. Internal preservation copies are counted, but never listed
 * by title or shelf mark: versions marked as one and copies of the kind
 * "Preservation copy", the rule of RightsPolicy::isPreservationCopy (S N17).
 *
 * The data is read with a fixed number of queries (about fifteen),
 * independent of the size of the archive (no query per copy or work): only
 * the columns needed (versions, works and programme lines become entities
 * for the rules of other services) or grouped counts. The figures are then
 * aggregated by the pure static functions below, which the unit tests cover.
 *
 * Year boundaries of timestamps (audit log, creation dates) follow the time
 * zone of the user; dates (concerts, licenses) are calendar days.
 *
 * @psalm-import-type Proposal from ProtectionTerm as ProtectionProposal
 * @psalm-type CopyRow = array{id: int, versionId: int, binder: int, number: int, shelfmark: string, status: string, created: int, kind?: ?string}
 * @psalm-type CreatedRow = array{copyId: int, time: int, reason: ?string}
 * @psalm-type TaskRow = array{addressee: string, status: string, category: ?string, created: int}
 * @psalm-type CopyRef = array{id: int, shelfmark: string, status: string, kind?: ?string}
 * @psalm-type BinderRow = array{binder: int, active: int, retired: int, missing: int, lent: int, other: int, total: int}
 * @psalm-type AdditionRow = array{year: int, accession: int, import: int, other: int, total: int}
 * @psalm-type StockFigures = array{copies: int, status: array<string, int>, binders: list<BinderRow>, active: int, activeWithFile: int,
 *     activeWithMusicxml: int, preservationVersions: int, preservationCopies: int}
 * @psalm-type Stock = array{works: int, versions: int, copies: int, status: array<string, int>, binders: list<BinderRow>, active: int,
 *     activeWithFile: int, activeWithMusicxml: int, preservationVersions: int, preservationCopies: int, additions: list<AdditionRow>}
 * @psalm-type RightsRow = array{status: string, confirmed: int, unconfirmed: int, total: int}
 * @psalm-type ValidVersions = array{works: int, withValid: int, withoutValid: int, withoutValidSeveral: int, currentWithoutValid: int}
 * @psalm-type LicenseCounts = array{total: int, active: int, ending: int, ended: int, future: int, endingDays: int}
 * @psalm-type Rights = array{status: list<RightsRow>, validVersions: ValidVersions, licenses: LicenseCounts, portal: array<string, int>,
 *     printStation: array<string, int>, publication: array<string, int>}
 * @psalm-type YearRow = array{year: int, concerts: int, planned: int, pieces: int, works: int}
 * @psalm-type TopWork = array{workId: int, title: ?string, composer: ?string, performances: int, last: string}
 * @psalm-type TopComposer = array{composer: string, performances: int, works: int}
 * @psalm-type Performances = array{years: list<YearRow>, undated: int, topWorks: list<TopWork>, topComposers: list<TopComposer>}
 * @psalm-type LongRow = array{workId: int, title: ?string, composer: ?string, class: string, performances: ?int, last: ?string, copyId: int, shelfmark: string}
 * @psalm-type Repertoire = array{years: list<YearRow>, undated: int, topWorks: list<TopWork>, topComposers: list<TopComposer>,
 *     classes: array<string, int>, sources: array{performances: int, import: int}, thresholds: array{current: int, dormant: int},
 *     longNotSung: list<LongRow>, longNotSungTotal: int}
 * @psalm-type TaskAgeRow = array{addressee: string, open: int, answered: int, days30: int, days90: int, days365: int, older: int}
 * @psalm-type ProtectionRow = array{versionId: int, title: ?string, composer: ?string, until: string, rightsStatus: ?string, confirmed: bool, copies: list<CopyRef>}
 * @psalm-type ProtectionEnding = array{years: list<array{year: int, versions: int}>, total: int, rows: list<ProtectionRow>, omitted: int}
 * @psalm-type Checksums = array{files: int, hashed: int, pending: int, mismatches: int}
 * @psalm-type Maintenance = array{tasks: list<TaskAgeRow>, proposals: array{open: int, olderThan30Days: int}, checksums: Checksums, protection: ProtectionEnding}
 * @psalm-type Overview = array{period: array{from: int, to: int}, today: string, range: array{first: int, last: int}, stock: Stock,
 *     rights: Rights, repertoire: Repertoire, maintenance: Maintenance}
 * @psalm-type ReportConcert = array{id: int, date: ?string, title: ?string, location: ?string, status: string, imported: bool, pieces: int}
 * @psalm-type ReportAccession = array{copyId: int, shelfmark: string, title: ?string, composer: ?string, date: string}
 * @psalm-type ReportLicense = array{licenseId: int, title: ?string, composer: ?string, holder: ?string, uses: list<string>,
 *     validFrom: ?string, validUntil: ?string, shelfmarks: list<string>}
 * @psalm-type ReportLicenses = array{validOn: string, valid: int, started: list<ReportLicense>, ended: list<ReportLicense>, omitted: int}
 * @psalm-type OpenRights = array{open: int, none: int, publicDomainUnconfirmed: int, protectedUnconfirmed: int, protectionEnded: int,
 *     conflicts: int, rightsTasks: int}
 * @psalm-type Report = array{year: int, today: string, concerts: list<ReportConcert>, totals: YearRow, additions: AdditionRow,
 *     accessions: list<ReportAccession>, accessionsOmitted: int, licenses: ReportLicenses, openRights: OpenRights,
 *     stock: array{works: int, versions: int, copies: int, active: int}}
 */
class StatisticsService {
	/** Earliest year of a period */
	public const FIRST_YEAR = 1800;
	/** Latest year of a period: this many years after the current one */
	public const YEARS_AHEAD = 50;
	/** Longest period in years */
	public const MAX_YEARS = 100;
	/** Default period: the current year and the years before it */
	public const DEFAULT_YEARS = 10;
	/** A license ending within this many days counts as "ending" (as the start page card) */
	public const ENDING_DAYS = 30;
	/** Rows of the lists of the most performed works and composers */
	public const TOP = 10;
	/** Rows of the list of works not sung for a long time */
	public const LONG_NOT_SUNG = 20;
	/** Rows of the list of protection terms ending in the period */
	public const PROTECTION_ROWS = 50;

	/** Copy states in their order; anything else counts as `other` */
	public const COPY_STATUSES = ['active', 'retired', 'missing', 'lent'];
	/** Rights states in their order; `none` = no rights status */
	public const RIGHTS_STATUSES = ['public_domain', 'protected', 'open', 'none'];
	/** Publication states in their order; `none` = not set */
	public const PUBLICATION_STATUSES = ['free', 'blocked', 'internal', 'to_clarify', 'none'];
	/** States of the release lists (ReleaseListService) in their order */
	public const RELEASE_STATUSES = ['ok', 'remove', 'add', 'not_decided', 'other_version_valid', 'blocked'];
	/** Repertoire classes in their order */
	public const REPERTOIRE_CLASSES = ['current', 'dormant', 'earlier', 'none'];
	/** Age groups of open tasks: key => highest age in days (the rest is `older`) */
	public const TASK_AGES = ['days30' => 30, 'days90' => 90, 'days365' => 365];

	public function __construct(
		private IDBConnection $db,
		private LicenseMapper $licenses,
		private EventMapper $events,
		private ArchiveFileMapper $files,
		private RepertoireService $repertoire,
		private ReleaseListService $releaseLists,
		private RightsService $rights,
		private PersonService $persons,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * All statistics of the overview for a period of years (default: the
	 * last ten years including the current one).
	 *
	 * @return Overview
	 */
	public function overview(?int $from, ?int $to, string $uid): array {
		$today = $this->rights->today($uid);
		$currentYear = (int)substr($today, 0, 4);
		[$from, $to] = self::period($from, $to, $currentYear);
		$zone = $this->timeZone->getTimeZone(false, $uid);

		$allWorks = $this->workRows();
		$works = self::listedWorks($allWorks);
		$versions = $this->versionRows();
		$copies = $this->copyRows();
		$created = $this->createdRows();
		$events = $this->events->findAllById();
		$items = $this->itemRows();
		$licenses = $this->licenses->findAllByVersion();
		$derived = $this->repertoire->perWorkFrom($events, $items);
		$protection = $this->persons->protectionForVersions(array_keys($versions), $currentYear);

		$performances = self::performances($events, $items, $allWorks, $from, $to);
		$classes = self::repertoireClasses($works, $derived);
		$longNotSung = self::longNotSung($works, $derived, self::singableCopies($copies, $versions, $allWorks), self::LONG_NOT_SUNG);
		$current = [];
		foreach ($works as $workId => $work) {
			if (CatalogService::repertoireFigures($work, $derived)['repertoire_class'] === 'current') {
				$current[$workId] = true;
			}
		}

		return [
			'period' => ['from' => $from, 'to' => $to],
			'today' => $today,
			'range' => ['first' => self::firstYear($events, $copies, $created, $zone, $currentYear), 'last' => $currentYear],
			'stock' => ['works' => count($works), 'versions' => count($versions)]
				+ self::stock($copies, $versions, $this->files->countPerCopy())
				+ ['additions' => self::additions($copies, $created, $from, $to, $zone)],
			'rights' => [
				'status' => self::rightsStatus($versions),
				'validVersions' => self::validVersions($works, $versions, $allWorks, $current),
				'licenses' => self::licenseCounts($licenses, $today),
			] + $this->releases($versions, $licenses, $today),
			'repertoire' => $performances + [
				'classes' => $classes['classes'],
				'sources' => $classes['sources'],
				'thresholds' => $this->repertoire->thresholds(),
				'longNotSung' => $longNotSung['rows'],
				'longNotSungTotal' => $longNotSung['total'],
			],
			'maintenance' => [
				'tasks' => self::tasksByRole($this->taskRows(), $this->time->getTime()),
				'proposals' => $this->proposalCounts(),
				'checksums' => $this->checksums(),
				'protection' => self::protectionEnding($protection, $versions, $allWorks, self::copiesByVersion($copies), $from, $to,
					self::PROTECTION_ROWS),
			],
		];
	}

	/**
	 * The annual report of one year (default: the current one): concerts
	 * with their pieces, new copies, licenses and open rights questions (the
	 * latter as of today).
	 *
	 * @return Report
	 */
	public function report(?int $year, string $uid): array {
		$today = $this->rights->today($uid);
		$currentYear = (int)substr($today, 0, 4);
		[$year] = self::period($year ?? $currentYear, $year ?? $currentYear, $currentYear);
		$zone = $this->timeZone->getTimeZone(false, $uid);

		$allWorks = $this->workRows();
		$versions = $this->versionRows();
		$copies = $this->copyRows();
		$created = $this->createdRows();
		$events = $this->events->findAllById();
		$items = $this->itemRows();
		$licenses = $this->licenses->findAllByVersion();
		$protection = $this->persons->protectionForVersions(array_keys($versions), $currentYear);
		$byVersion = self::copiesByVersion($copies);
		$accessions = self::accessions($copies, $created, $versions, $allWorks, $year, $zone);
		$active = count(array_filter($copies, fn (array $copy): bool => $copy['status'] === 'active'));

		return [
			'year' => $year,
			'today' => $today,
			'concerts' => self::concerts($events, $items, $year),
			'totals' => self::performances($events, $items, $allWorks, $year, $year, 0)['years'][0],
			'additions' => self::additions($copies, $created, $year, $year, $zone)[0],
			'accessions' => $accessions['rows'],
			'accessionsOmitted' => $accessions['omitted'],
			'licenses' => self::licenseReport($licenses, $versions, $allWorks, $byVersion, $year, $today, $zone),
			'openRights' => self::openRights($versions, $protection, $byVersion, $this->taskRows()),
			'stock' => ['works' => count(self::listedWorks($allWorks)), 'versions' => count($versions), 'copies' => count($copies),
				'active' => $active],
		];
	}

	// ---- period ----------------------------------------------------------------

	/**
	 * Checks a period of years and fills in the defaults: without an end the
	 * current year, without a start the ten years up to the end.
	 *
	 * @return array{0: int, 1: int} first and last year
	 */
	public static function period(?int $from, ?int $to, int $currentYear): array {
		$to ??= $currentYear;
		$from ??= $to - self::DEFAULT_YEARS + 1;
		$last = $currentYear + self::YEARS_AHEAD;
		if ($from < self::FIRST_YEAR || $to > $last || $from > $to || $to - $from >= self::MAX_YEARS) {
			throw new InvalidInputException('Please choose a period between %1$s and %2$s of at most %3$s years, the first year not after the last.',
				[self::FIRST_YEAR, $last, self::MAX_YEARS]);
		}
		return [$from, $to];
	}

	/**
	 * Year of a date ("YYYY-MM-DD" or only "YYYY"), or null.
	 */
	public static function yearOf(?string $date): ?int {
		return $date !== null && preg_match('/^(\d{4})/', $date, $match) === 1 ? (int)$match[1] : null;
	}

	/**
	 * A date as a number for sorting: "2025-12-06" => 20251206, "2003" =>
	 * 20030000; free text with a year counts as that year; otherwise 0.
	 */
	public static function dateKey(?string $date): int {
		if ($date === null) {
			return 0;
		}
		if (preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $match) === 1) {
			return (int)$match[1] * 10000 + (int)($match[2] ?? 0) * 100 + (int)($match[3] ?? 0);
		}
		return preg_match('/\b(\d{4})\b/', $date, $match) === 1 ? (int)$match[1] * 10000 : 0;
	}

	private static function yearOfTime(int $time, \DateTimeZone $zone): int {
		return (int)(new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('Y');
	}

	// ---- stock -----------------------------------------------------------------

	/**
	 * Works that are listed: not merged into another work.
	 *
	 * @param array<int, Work> $works all works keyed by id
	 * @return array<int, Work>
	 */
	public static function listedWorks(array $works): array {
		return array_filter($works, fn (Work $work): bool => $work->getMergedInto() === null);
	}

	/**
	 * Copies by state and binder, active copies with a file and with
	 * MusicXML (the rule of the catalog list), internal preservation copies.
	 *
	 * @param list<CopyRow> $copies
	 * @param array<int, WorkVersion> $versions keyed by id
	 * @param array<int, array<string, int>> $fileCounts copy id => file type => found files
	 * @return StockFigures
	 */
	public static function stock(array $copies, array $versions, array $fileCounts): array {
		$status = array_fill_keys([...self::COPY_STATUSES, 'other'], 0);
		$perBinder = [];
		$active = 0;
		$withFile = 0;
		$withMusicxml = 0;
		$preservationCopies = 0;
		foreach ($copies as $copy) {
			$key = in_array($copy['status'], self::COPY_STATUSES, true) ? $copy['status'] : 'other';
			$status[$key]++;
			$perBinder[$copy['binder']] ??= array_fill_keys([...self::COPY_STATUSES, 'other'], 0);
			$perBinder[$copy['binder']][$key]++;
			$version = $versions[$copy['versionId']] ?? null;
			if (self::copyIsPreservation($version, $copy)) {
				$preservationCopies++;
			}
			if ($key !== 'active') {
				continue;
			}
			$active++;
			$counts = $fileCounts[$copy['id']] ?? [];
			if (array_sum($counts) > 0) {
				$withFile++;
			}
			if (CatalogService::hasMusicxml($counts, $version)) {
				$withMusicxml++;
			}
		}
		ksort($perBinder);
		$binders = [];
		foreach ($perBinder as $binder => $counts) {
			$binders[] = ['binder' => $binder, 'active' => $counts['active'] ?? 0, 'retired' => $counts['retired'] ?? 0,
				'missing' => $counts['missing'] ?? 0, 'lent' => $counts['lent'] ?? 0, 'other' => $counts['other'] ?? 0,
				'total' => array_sum($counts)];
		}
		$byVersion = self::copiesByVersion($copies);
		$preservationVersions = count(array_filter($versions,
			fn (WorkVersion $v): bool => self::versionIsPreservation($v, $byVersion[$v->getId()] ?? [])));
		return ['copies' => count($copies), 'status' => $status, 'binders' => $binders, 'active' => $active,
			'activeWithFile' => $withFile, 'activeWithMusicxml' => $withMusicxml, 'preservationVersions' => $preservationVersions,
			'preservationCopies' => $preservationCopies];
	}

	/**
	 * New copies per year of the period, by where they came from: the year
	 * the audit log records the creation of the copy (accession assistant or
	 * catalog import); for copies without such an entry, their creation date
	 * (`other`). Every copy counts once, in the year of its first record.
	 *
	 * @param list<CopyRow> $copies
	 * @param list<CreatedRow> $created creations of copies in the audit log
	 * @return list<AdditionRow> one row per year of the period
	 */
	public static function additions(array $copies, array $created, int $from, int $to, \DateTimeZone $zone): array {
		$rows = [];
		for ($year = $from; $year <= $to; $year++) {
			$rows[$year] = ['year' => $year, 'accession' => 0, 'import' => 0, 'other' => 0, 'total' => 0];
		}
		foreach (self::creations($copies, $created) as ['time' => $time, 'source' => $source]) {
			$year = self::yearOfTime($time, $zone);
			if (isset($rows[$year])) {
				$rows[$year][$source]++;
				$rows[$year]['total']++;
			}
		}
		return array_values($rows);
	}

	/**
	 * When and how each existing copy was created.
	 *
	 * @param list<CopyRow> $copies
	 * @param list<CreatedRow> $created
	 * @return array<int, array{time: int, source: 'accession'|'import'|'other'}> copy id => first creation
	 */
	private static function creations(array $copies, array $created): array {
		$exists = [];
		foreach ($copies as $copy) {
			$exists[$copy['id']] = $copy['created'];
		}
		$result = [];
		foreach ($created as $entry) {
			$copyId = $entry['copyId'];
			if (!isset($exists[$copyId]) || (isset($result[$copyId]) && $result[$copyId]['time'] <= $entry['time'])) {
				continue;
			}
			$source = match ($entry['reason']) {
				'accession' => 'accession',
				'import' => 'import',
				default => 'other',
			};
			$result[$copyId] = ['time' => $entry['time'], 'source' => $source];
		}
		foreach ($exists as $copyId => $time) {
			if (!isset($result[$copyId]) && $time > 0) {
				$result[$copyId] = ['time' => $time, 'source' => 'other'];
			}
		}
		return $result;
	}

	/**
	 * Copies of the accession assistant recorded in a year, in the order of
	 * recording. Internal preservation copies are only counted (`omitted`).
	 *
	 * @param list<CopyRow> $copies
	 * @param list<CreatedRow> $created
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $works all works keyed by id
	 * @return array{rows: list<ReportAccession>, omitted: int}
	 */
	public static function accessions(array $copies, array $created, array $versions, array $works, int $year, \DateTimeZone $zone): array {
		$byId = [];
		foreach ($copies as $copy) {
			$byId[$copy['id']] = $copy;
		}
		$rows = [];
		$keys = [];
		$omitted = 0;
		foreach (self::creations($copies, $created) as $copyId => ['time' => $time, 'source' => $source]) {
			$copy = $byId[$copyId];
			if ($source !== 'accession' || self::yearOfTime($time, $zone) !== $year) {
				continue;
			}
			$version = $versions[$copy['versionId']] ?? null;
			if ($version !== null && self::copyIsPreservation($version, $copy)) {
				$omitted++;
				continue;
			}
			$work = $version === null ? null : CatalogService::listedWork($works[$version->getWorkId()] ?? null, $works);
			$rows[] = ['copyId' => $copyId, 'shelfmark' => $copy['shelfmark'], 'title' => $work?->getTitle(), 'composer' => $work?->getComposer(),
				'date' => (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('Y-m-d')];
			$keys[] = [$time, $copy['shelfmark']];
		}
		return ['rows' => Sort::byKeys($rows, $keys), 'omitted' => $omitted];
	}

	/**
	 * First year with data (a concert or a new copy), for the choice of the
	 * period; at most the current year.
	 *
	 * @param array<int, Event> $events
	 * @param list<CopyRow> $copies
	 * @param list<CreatedRow> $created
	 */
	private static function firstYear(array $events, array $copies, array $created, \DateTimeZone $zone, int $currentYear): int {
		$first = $currentYear;
		foreach ($events as $event) {
			$year = self::yearOf($event->getEventDate());
			if ($event->getKind() === Event::CONCERT && $year !== null && $year >= self::FIRST_YEAR) {
				$first = min($first, $year);
			}
		}
		foreach (self::creations($copies, $created) as ['time' => $time]) {
			$first = min($first, max(self::FIRST_YEAR, self::yearOfTime($time, $zone)));
		}
		return $first;
	}

	// ---- rights ----------------------------------------------------------------

	/**
	 * Versions by rights status, confirmed and not confirmed.
	 *
	 * @param array<int, WorkVersion> $versions
	 * @return list<RightsRow>
	 */
	public static function rightsStatus(array $versions): array {
		$rows = [];
		foreach (self::RIGHTS_STATUSES as $status) {
			$rows[$status] = ['status' => $status, 'confirmed' => 0, 'unconfirmed' => 0, 'total' => 0];
		}
		foreach ($versions as $version) {
			$status = self::rightsKey($version);
			$rows[$status][$version->isConfirmed() ? 'confirmed' : 'unconfirmed']++;
			$rows[$status]['total']++;
		}
		return array_values($rows);
	}

	private static function rightsKey(WorkVersion $version): string {
		$status = $version->getRightsStatus();
		return $status !== null && isset(Fields::RIGHTS_STATUS[$status]) ? $status : 'none';
	}

	/**
	 * Works with and without a version chosen as valid by the conductor;
	 * of those without, how many have several versions to choose from, and
	 * how many belong to the current repertoire.
	 *
	 * @param array<int, Work> $works listed works
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $allWorks all works keyed by id (for merged works)
	 * @param array<int, true> $current ids of works in the current repertoire
	 * @return ValidVersions
	 */
	public static function validVersions(array $works, array $versions, array $allWorks, array $current): array {
		$count = [];
		$valid = [];
		foreach ($versions as $version) {
			$workId = CatalogService::listedWork($allWorks[$version->getWorkId()] ?? null, $allWorks)?->getId() ?? $version->getWorkId();
			$count[$workId] = ($count[$workId] ?? 0) + 1;
			if ($version->getValidSince() !== null) {
				$valid[$workId] = true;
			}
		}
		$result = ['works' => count($works), 'withValid' => 0, 'withoutValid' => 0, 'withoutValidSeveral' => 0, 'currentWithoutValid' => 0];
		foreach (array_keys($works) as $workId) {
			if (isset($valid[$workId])) {
				$result['withValid']++;
				continue;
			}
			$result['withoutValid']++;
			if (($count[$workId] ?? 0) > 1) {
				$result['withoutValidSeveral']++;
			}
			if (isset($current[$workId])) {
				$result['currentWithoutValid']++;
			}
		}
		return $result;
	}

	/**
	 * Licenses by validity on a day; the groups do not overlap:
	 * - `ended`: ended before the day
	 * - `future`: not valid yet
	 * - `ending`: valid, ends within $days days
	 * - `active`: valid beyond that, or without end
	 *
	 * @param array<int, list<License>> $licenses version id => licenses
	 * @return LicenseCounts
	 */
	public static function licenseCounts(array $licenses, string $today, int $days = self::ENDING_DAYS): array {
		$limit = (new \DateTimeImmutable($today))->modify('+' . $days . ' days')->format('Y-m-d');
		$result = ['total' => 0, 'active' => 0, 'ending' => 0, 'ended' => 0, 'future' => 0, 'endingDays' => $days];
		foreach ($licenses as $list) {
			foreach ($list as $license) {
				$from = $license->getValidFrom();
				$until = $license->getValidUntil();
				$result['total']++;
				$group = match (true) {
					$until !== null && $until < $today => 'ended',
					$from !== null && $from > $today => 'future',
					$until !== null && $until <= $limit => 'ending',
					default => 'active',
				};
				$result[$group]++;
			}
		}
		return $result;
	}

	/**
	 * Versions per state of the release lists (singers' portal and print
	 * station, the rules of ReleaseListService) and per publication status.
	 *
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, list<License>> $licenses
	 * @return array{portal: array<string, int>, printStation: array<string, int>, publication: array<string, int>}
	 */
	private function releases(array $versions, array $licenses, string $today): array {
		$validPerWork = [];
		foreach ($versions as $version) {
			if ($version->getValidSince() !== null) {
				$validPerWork[$version->getWorkId()] = $version->getId();
			}
		}
		$portal = array_fill_keys(self::RELEASE_STATUSES, 0);
		$printStation = array_fill_keys(self::RELEASE_STATUSES, 0);
		$publication = array_fill_keys(self::PUBLICATION_STATUSES, 0);
		foreach ($versions as $versionId => $version) {
			$list = $licenses[$versionId] ?? [];
			$valid = $validPerWork[$version->getWorkId()] ?? null;
			$portal[$this->releaseLists->status($version, $list, $today, 'portal', $valid)[0]]++;
			$printStation[$this->releaseLists->status($version, $list, $today, 'print_station', $valid)[0]]++;
			$status = $version->getPublicationStatus();
			$publication[$status !== null && isset($publication[$status]) ? $status : 'none']++;
		}
		return ['portal' => $portal, 'printStation' => $printStation, 'publication' => $publication];
	}

	/**
	 * Licenses in the annual report: how many were valid on the last day of
	 * the year (today for the current year), which started and which ended
	 * in the year. Licenses of internal preservation copies are only counted.
	 *
	 * @param array<int, list<License>> $licenses version id => licenses
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $works all works keyed by id
	 * @param array<int, list<CopyRef>> $copiesByVersion
	 * @return ReportLicenses
	 */
	public static function licenseReport(array $licenses, array $versions, array $works, array $copiesByVersion, int $year,
		string $today, \DateTimeZone $zone): array {
		$validOn = min(sprintf('%04d-12-31', $year), $today);
		$valid = 0;
		$omitted = 0;
		$started = [];
		$ended = [];
		$startKeys = [];
		$endKeys = [];
		foreach ($licenses as $versionId => $list) {
			$version = $versions[$versionId] ?? null;
			foreach ($list as $license) {
				$from = $license->getValidFrom();
				$until = $license->getValidUntil();
				if (($from === null || $from <= $validOn) && ($until === null || $until >= $validOn)) {
					$valid++;
				}
				// Without a start date the license starts when it was recorded
				$start = $from ?? ($license->getCreated() > 0
					? (new \DateTimeImmutable('@' . $license->getCreated()))->setTimezone($zone)->format('Y-m-d') : null);
				$isStart = self::yearOf($start) === $year;
				$isEnd = self::yearOf($until) === $year;
				if (!$isStart && !$isEnd) {
					continue;
				}
				if ($version === null || self::versionIsPreservation($version, $copiesByVersion[$versionId] ?? [])) {
					$omitted++;
					continue;
				}
				$work = CatalogService::listedWork($works[$version->getWorkId()] ?? null, $works);
				$row = ['licenseId' => $license->getId(), 'title' => $work?->getTitle(), 'composer' => $work?->getComposer(),
					'holder' => $license->getHolder(), 'uses' => $license->useList(), 'validFrom' => $from, 'validUntil' => $until,
					'shelfmarks' => array_column(array_values(array_filter($copiesByVersion[$versionId] ?? [],
						fn (array $copy): bool => $copy['status'] === 'active')), 'shelfmark')];
				if ($isStart) {
					$started[] = $row;
					$startKeys[] = [(string)$start, $license->getId()];
				}
				if ($isEnd) {
					$ended[] = $row;
					$endKeys[] = [$until ?? '', $license->getId()];
				}
			}
		}
		return ['validOn' => $validOn, 'valid' => $valid, 'started' => Sort::byKeys($started, $startKeys),
			'ended' => Sort::byKeys($ended, $endKeys), 'omitted' => $omitted];
	}

	/**
	 * Open rights questions as of today: versions without a clear or a
	 * confirmed status, protection proposals that contradict the status
	 * (the rules of the resubmission list, versions with copies only) and
	 * open tasks about rights.
	 *
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, ProtectionProposal> $proposals version id => protection proposal
	 * @param array<int, list<CopyRef>> $copiesByVersion
	 * @param list<TaskRow> $tasks open and answered tasks
	 * @return OpenRights
	 */
	public static function openRights(array $versions, array $proposals, array $copiesByVersion, array $tasks): array {
		$result = ['open' => 0, 'none' => 0, 'publicDomainUnconfirmed' => 0, 'protectedUnconfirmed' => 0, 'protectionEnded' => 0,
			'conflicts' => 0, 'rightsTasks' => 0];
		foreach ($versions as $versionId => $version) {
			$status = self::rightsKey($version);
			if ($status === 'open' || $status === 'none') {
				$result[$status]++;
			} elseif (!$version->isConfirmed()) {
				$result[$status === 'public_domain' ? 'publicDomainUnconfirmed' : 'protectedUnconfirmed']++;
			}
			$proposal = $proposals[$versionId] ?? null;
			if ($proposal === null || ($copiesByVersion[$versionId] ?? []) === []) {
				continue;
			}
			if ($status === 'protected' && $proposal['status'] === ProtectionTerm::FREE) {
				$result['protectionEnded']++;
			} elseif ($status === 'public_domain' && ($proposal['persons'] ?? 0) > 0 && $proposal['status'] !== ProtectionTerm::FREE) {
				$result['conflicts']++;
			}
		}
		$result['rightsTasks'] = count(array_filter($tasks, fn (array $task): bool => $task['category'] === 'rights'));
		return $result;
	}

	// ---- repertoire and performances -------------------------------------------

	/**
	 * Whether a programme line is a documented performance: a line that
	 * counts, of a concert confirmed as sung, assigned to a work (the rule of
	 * RepertoireService).
	 */
	private static function isPerformance(Event $event, EventItem $item): bool {
		return $event->getKind() === Event::CONCERT && $event->getStatus() === 'sung' && $item->getCounts() === true
			&& $item->getAssignment() === EventItem::CERTAIN && $item->getWorkId() !== null;
	}

	/**
	 * Concerts and pieces per year of the period, and the works and
	 * composers performed most often in it.
	 *
	 * - `concerts`: concerts confirmed as sung (also imported ones);
	 *   `planned`: concerts not confirmed yet; `undated`: sung concerts
	 *   without a date (not in any year)
	 * - `pieces`: documented performances (programme lines that count);
	 *   `works`: different works among them
	 * - a work's `performances` are its performance days, as in the
	 *   repertoire figures; a composer's are those of all their works
	 *
	 * @param array<int, Event> $events keyed by id
	 * @param iterable<EventItem> $items
	 * @param array<int, Work> $works all works keyed by id
	 * @return Performances
	 */
	public static function performances(array $events, iterable $items, array $works, int $from, int $to, int $top = self::TOP): array {
		$years = [];
		for ($year = $from; $year <= $to; $year++) {
			$years[$year] = ['year' => $year, 'concerts' => 0, 'planned' => 0, 'pieces' => 0, 'works' => 0];
		}
		$undated = 0;
		// Sung concerts of the period: event id => [year, date]
		$sungConcerts = [];
		foreach ($events as $event) {
			if ($event->getKind() !== Event::CONCERT) {
				continue;
			}
			$sung = $event->getStatus() === 'sung';
			$date = $event->getEventDate();
			$year = self::yearOf($date);
			if ($year === null) {
				$undated += $sung ? 1 : 0;
			} elseif (isset($years[$year])) {
				$years[$year][$sung ? 'concerts' : 'planned']++;
				if ($sung) {
					$sungConcerts[$event->getId()] = [$year, (string)$date];
				}
			}
		}

		// The documented performances (the rule of isPerformance(), with the
		// concert checked once per event)
		$worksPerYear = [];
		$days = [];
		$listed = [];
		foreach ($items as $item) {
			$concert = $sungConcerts[$item->getEventId()] ?? null;
			$workId = $item->getWorkId();
			if ($concert === null || $workId === null || $item->getCounts() !== true || $item->getAssignment() !== EventItem::CERTAIN) {
				continue;
			}
			[$year, $date] = $concert;
			$workId = $listed[$workId] ??= CatalogService::listedWork($works[$workId] ?? null, $works)?->getId() ?? $workId;
			$years[$year]['pieces']++;
			$worksPerYear[$year][$workId] = true;
			$days[$workId][$date] = true;
		}
		foreach ($worksPerYear as $year => $list) {
			$years[$year]['works'] = count($list);
		}

		$counts = array_map('count', $days);
		// Only works that can reach the top list are sorted in full
		$sortedCounts = array_values($counts);
		rsort($sortedCounts);
		$topCounts = array_slice($sortedCounts, 0, max(0, $top));
		$threshold = $topCounts === [] ? PHP_INT_MAX : min($topCounts);
		$topWorks = [];
		$workKeys = [];
		$composers = [];
		foreach ($days as $workId => $dates) {
			$count = $counts[$workId];
			$work = $works[$workId] ?? null;
			$name = trim((string)preg_replace('/\s+/u', ' ', $work?->getComposer() ?? ''));
			if ($name !== '') {
				$key = mb_strtolower($name);
				$composers[$key] ??= ['composer' => $name, 'performances' => 0, 'works' => 0];
				$composers[$key]['performances'] += $count;
				$composers[$key]['works']++;
			}
			if ($count < $threshold) {
				continue;
			}
			$last = '';
			foreach (array_keys($dates) as $date) {
				/**
				 * Array keys like "2003" (a year only) become integers in PHP; cast back.
				 * @psalm-suppress RedundantCastGivenDocblockType
				 */
				$date = (string)$date;
				$last = strcmp($date, $last) > 0 ? $date : $last;
			}
			$topWorks[] = ['workId' => $workId, 'title' => $work?->getTitle(), 'composer' => $work?->getComposer(),
				'performances' => $count, 'last' => $last];
			$workKeys[] = [-$count, -self::dateKey($last), mb_strtolower($work?->getTitle() ?? '')];
		}
		$composers = array_values($composers);
		$composerKeys = array_map(fn (array $c): array => [-$c['performances'], -$c['works'], mb_strtolower($c['composer'])], $composers);

		return [
			'years' => array_values($years),
			'undated' => $undated,
			'topWorks' => array_slice(Sort::byKeys($topWorks, $workKeys), 0, $top),
			'topComposers' => $top > 0 ? array_slice(Sort::byKeys($composers, $composerKeys), 0, $top) : [],
		];
	}

	/**
	 * Concerts of a year for the annual report, by date. `pieces`: the
	 * documented performances of a sung concert, the lines not struck of a
	 * planned one.
	 *
	 * @param array<int, Event> $events keyed by id
	 * @param iterable<EventItem> $items
	 * @return list<ReportConcert>
	 */
	public static function concerts(array $events, iterable $items, int $year): array {
		$pieces = [];
		foreach ($items as $item) {
			$event = $events[$item->getEventId()] ?? null;
			if ($event === null) {
				continue;
			}
			$counts = $event->getStatus() === 'sung' ? self::isPerformance($event, $item) : $item->getStruck() !== true;
			if ($counts) {
				$pieces[$event->getId()] = ($pieces[$event->getId()] ?? 0) + 1;
			}
		}
		$rows = [];
		$keys = [];
		foreach ($events as $event) {
			if ($event->getKind() !== Event::CONCERT || self::yearOf($event->getEventDate()) !== $year) {
				continue;
			}
			$rows[] = ['id' => $event->getId(), 'date' => $event->getEventDate(), 'title' => $event->getTitle(),
				'location' => $event->getLocation(), 'status' => $event->getStatus(), 'imported' => $event->getImported() === true,
				'pieces' => $pieces[$event->getId()] ?? 0];
			$keys[] = [(string)$event->getEventDate(), $event->getId()];
		}
		return Sort::byKeys($rows, $keys);
	}

	/**
	 * Listed works per repertoire class (as in the catalog list: derived
	 * from documented performances, otherwise from the imported catalog;
	 * without either `none`) and where the class comes from.
	 *
	 * @param array<int, Work> $works listed works
	 * @param array<int, array{performances: int, first: ?string, last: ?string, class: string, songLists: list<string>}> $derived
	 * @return array{classes: array<string, int>, sources: array{performances: int, import: int}}
	 */
	public static function repertoireClasses(array $works, array $derived): array {
		$classes = array_fill_keys(self::REPERTOIRE_CLASSES, 0);
		$sources = ['performances' => 0, 'import' => 0];
		foreach ($works as $work) {
			$figures = CatalogService::repertoireFigures($work, $derived);
			$class = $figures['repertoire_class'];
			$classes[$class !== null && isset($classes[$class]) ? $class : 'none']++;
			if ($figures['repertoire_source'] === 'performances') {
				$sources['performances']++;
			} elseif ($class !== null) {
				$sources['import']++;
			}
		}
		return ['classes' => $classes, 'sources' => $sources];
	}

	/**
	 * Works sung before, but not within the years of the current
	 * repertoire (class dormant or earlier), that can be sung from the
	 * archive (an active copy that is no internal preservation copy). The
	 * most often sung first, then the longest not sung.
	 *
	 * @param array<int, Work> $works listed works
	 * @param array<int, array{performances: int, first: ?string, last: ?string, class: string, songLists: list<string>}> $derived
	 * @param array<int, list<array{id: int, shelfmark: string}>> $singable work id => singable copies in shelf order
	 * @return array{total: int, rows: list<LongRow>}
	 */
	public static function longNotSung(array $works, array $derived, array $singable, int $limit): array {
		$rows = [];
		$keys = [];
		foreach ($works as $workId => $work) {
			$figures = CatalogService::repertoireFigures($work, $derived);
			$class = $figures['repertoire_class'];
			$copy = $singable[$workId][0] ?? null;
			if ($copy === null || ($class !== 'dormant' && $class !== 'earlier')) {
				continue;
			}
			$rows[] = ['workId' => $workId, 'title' => $work->getTitle(), 'composer' => $work->getComposer(), 'class' => $class,
				'performances' => $figures['performances'], 'last' => $figures['last_performed_on'], 'copyId' => $copy['id'],
				'shelfmark' => $copy['shelfmark']];
			$keys[] = [-($figures['performances'] ?? 0), self::dateKey($figures['last_performed_on']), $copy['shelfmark']];
		}
		return ['total' => count($rows), 'rows' => array_slice(Sort::byKeys($rows, $keys), 0, $limit)];
	}

	/**
	 * Active copies of versions that are no internal preservation copies,
	 * per listed work, in shelf order.
	 *
	 * @param list<CopyRow> $copies in shelf order
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $works all works keyed by id
	 * @return array<int, list<array{id: int, shelfmark: string}>>
	 */
	public static function singableCopies(array $copies, array $versions, array $works): array {
		$result = [];
		foreach ($copies as $copy) {
			$version = $versions[$copy['versionId']] ?? null;
			if ($copy['status'] !== 'active' || $version === null || self::copyIsPreservation($version, $copy)) {
				continue;
			}
			$workId = CatalogService::listedWork($works[$version->getWorkId()] ?? null, $works)?->getId() ?? $version->getWorkId();
			$result[$workId][] = ['id' => $copy['id'], 'shelfmark' => $copy['shelfmark']];
		}
		return $result;
	}

	// ---- maintenance -----------------------------------------------------------

	/**
	 * Open and answered tasks per addressed role, by age (days since they
	 * were created). Every role a task can be addressed to has a row.
	 *
	 * @param list<TaskRow> $tasks
	 * @return list<TaskAgeRow>
	 */
	public static function tasksByRole(array $tasks, int $now): array {
		$rows = [];
		$empty = ['open' => 0, 'answered' => 0, 'days30' => 0, 'days90' => 0, 'days365' => 0, 'older' => 0];
		foreach (TaskService::ADDRESSEES as $role) {
			$rows[$role] = ['addressee' => $role] + $empty;
		}
		foreach ($tasks as $task) {
			$role = $task['addressee'];
			$rows[$role] ??= ['addressee' => $role] + $empty;
			$rows[$role][$task['status'] === Task::ANSWERED ? 'answered' : 'open']++;
			$age = intdiv(max(0, $now - $task['created']), 86400);
			$group = 'older';
			foreach (self::TASK_AGES as $key => $days) {
				if ($age <= $days) {
					$group = $key;
					break;
				}
			}
			$rows[$role][$group]++;
		}
		return array_values($rows);
	}

	/**
	 * Versions whose proposed protection ends (or ended) in the period, by
	 * the life dates of the persons involved; versions with copies only,
	 * the soonest first. Internal preservation copies are counted, not
	 * listed (`omitted`).
	 *
	 * @param array<int, ProtectionProposal> $proposals version id => proposal
	 * @param array<int, WorkVersion> $versions
	 * @param array<int, Work> $works all works keyed by id
	 * @param array<int, list<CopyRef>> $copiesByVersion
	 * @return ProtectionEnding
	 */
	public static function protectionEnding(array $proposals, array $versions, array $works, array $copiesByVersion, int $from, int $to,
		int $limit): array {
		$years = [];
		for ($year = $from; $year <= $to; $year++) {
			$years[$year] = ['year' => $year, 'versions' => 0];
		}
		$rows = [];
		$keys = [];
		$total = 0;
		$omitted = 0;
		foreach ($proposals as $versionId => $proposal) {
			$until = $proposal['until'];
			$year = self::yearOf($until);
			$version = $versions[$versionId] ?? null;
			$copies = $copiesByVersion[$versionId] ?? [];
			if ($until === null || $year === null || !isset($years[$year]) || $version === null || $copies === []) {
				continue;
			}
			$years[$year]['versions']++;
			$total++;
			if (self::versionIsPreservation($version, $copies)) {
				$omitted++;
				continue;
			}
			$work = CatalogService::listedWork($works[$version->getWorkId()] ?? null, $works);
			$rows[] = ['versionId' => $versionId, 'title' => $work?->getTitle(), 'composer' => $work?->getComposer(), 'until' => $until,
				'rightsStatus' => $version->getRightsStatus(), 'confirmed' => $version->isConfirmed(),
				'copies' => array_map(fn (array $copy): array => ['id' => $copy['id'], 'shelfmark' => $copy['shelfmark'], 'status' => $copy['status']],
					$copies)];
			$keys[] = [$until, $copies[0]['shelfmark']];
		}
		return ['years' => array_values($years), 'total' => $total, 'rows' => array_slice(Sort::byKeys($rows, $keys), 0, $limit),
			'omitted' => $omitted];
	}

	/**
	 * @param list<CopyRow> $copies in shelf order
	 * @return array<int, list<CopyRef>> version id => copies
	 */
	public static function copiesByVersion(array $copies): array {
		$result = [];
		foreach ($copies as $copy) {
			$result[$copy['versionId']][] = ['id' => $copy['id'], 'shelfmark' => $copy['shelfmark'], 'status' => $copy['status'],
				'kind' => $copy['kind'] ?? null];
		}
		return $result;
	}

	/**
	 * Whether a copy is an internal preservation copy: its version is marked
	 * as one, or the copy is of the kind "Preservation copy" (the rule of
	 * RightsPolicy::isPreservationCopy on the rows of the statistics).
	 *
	 * @param CopyRow|CopyRef $copy
	 */
	private static function copyIsPreservation(?WorkVersion $version, array $copy): bool {
		return $version?->getPreservationCopy() === true || ($copy['kind'] ?? null) === RightsPolicy::PRESERVATION_COPY_KIND;
	}

	/**
	 * Whether anything of a version is an internal preservation copy (the rule
	 * of RightsPolicy::versionHasPreservationCopy), for figures that belong to
	 * the version as a whole (licenses, end of protection).
	 *
	 * @param list<CopyRef> $copies the copies of the version
	 */
	private static function versionIsPreservation(WorkVersion $version, array $copies): bool {
		if ($version->getPreservationCopy() === true) {
			return true;
		}
		foreach ($copies as $copy) {
			if (self::copyIsPreservation($version, $copy)) {
				return true;
			}
		}
		return false;
	}

	// ---- queries ---------------------------------------------------------------

	/** Columns of a version that the statistics and the release rules read */
	private const VERSION_COLUMNS = ['id', 'work_id', 'rights_status', 'confirmed_at', 'preservation_copy', 'portal_since',
		'portal_removed_at', 'valid_since', 'publication_status', 'musicxml_valid'];

	/** Columns of a work that the statistics and the repertoire figures read */
	private const WORK_COLUMNS = ['id', 'title', 'composer', 'merged_into', 'repertoire_import', 'performances_import',
		'last_performed_import'];

	/** Columns of a programme line that the statistics and the repertoire figures read */
	private const ITEM_COLUMNS = ['id', 'event_id', 'work_id', 'assignment', 'counts', 'struck'];

	/**
	 * All works with the columns the statistics need (the other properties
	 * stay empty), keyed by id. Reading only these columns keeps the
	 * statistics fast with thousands of works.
	 *
	 * @return array<int, Work>
	 */
	private function workRows(): array {
		$works = [];
		foreach ($this->partialRows('cantoria_works', self::WORK_COLUMNS) as $row) {
			$work = Work::fromRow($row);
			$works[$work->getId()] = $work;
		}
		return $works;
	}

	/**
	 * All versions with the columns the statistics and the release rules
	 * need, keyed by id.
	 *
	 * @return array<int, WorkVersion>
	 */
	private function versionRows(): array {
		$versions = [];
		foreach ($this->partialRows('cantoria_versions', self::VERSION_COLUMNS) as $row) {
			$version = WorkVersion::fromRow($row);
			$versions[$version->getId()] = $version;
		}
		return $versions;
	}

	/**
	 * All programme lines with the columns the statistics need.
	 *
	 * @return list<EventItem>
	 */
	private function itemRows(): array {
		return array_map(fn (array $row): EventItem => EventItem::fromRow($row), $this->partialRows('cantoria_event_items', self::ITEM_COLUMNS));
	}

	/**
	 * @param list<string> $columns
	 * @return list<array<string, mixed>>
	 */
	private function partialRows(string $table, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)->from($table)->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * All copies with the columns the statistics need, in shelf order.
	 *
	 * @return list<CopyRow>
	 */
	private function copyRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'version_id', 'binder', 'number', 'shelfmark', 'status', 'created', 'kind')
			->from('cantoria_copies')
			->orderBy('binder')
			->addOrderBy('number');
		$result = $qb->executeQuery();
		$rows = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$rows[] = ['id' => Row::int($row['id']), 'versionId' => Row::int($row['version_id']), 'binder' => Row::int($row['binder']),
				'number' => Row::int($row['number']), 'shelfmark' => Row::string($row['shelfmark']), 'status' => Row::string($row['status']),
				'created' => Row::int($row['created']), 'kind' => Row::stringOrNull($row['kind'])];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Creations of copies recorded in the audit log.
	 *
	 * @return list<CreatedRow>
	 */
	private function createdRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_id', 'time', 'reason')
			->from('cantoria_audit')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter(Fields::COPY)))
			->andWhere($qb->expr()->isNotNull('object_id'))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('created')));
		$result = $qb->executeQuery();
		$rows = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$rows[] = ['copyId' => Row::int($row['object_id']), 'time' => Row::int($row['time']), 'reason' => Row::stringOrNull($row['reason'])];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Open and answered tasks (addressee, state, category and age only).
	 *
	 * @return list<TaskRow>
	 */
	private function taskRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('addressee', 'status', 'category', 'created')
			->from('cantoria_tasks')
			->where($qb->expr()->in('status', $qb->createNamedParameter([Task::OPEN, Task::ANSWERED], IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$rows = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$rows[] = ['addressee' => Row::string($row['addressee']), 'status' => Row::string($row['status']),
				'category' => Row::stringOrNull($row['category']), 'created' => Row::int($row['created'])];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return array{open: int, olderThan30Days: int}
	 */
	private function proposalCounts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created')
			->from('cantoria_proposals')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Proposal::OPEN)));
		$result = $qb->executeQuery();
		$limit = $this->time->getTime() - 30 * 86400;
		$open = 0;
		$old = 0;
		foreach ($result->fetchAllAssociative() as $row) {
			$open++;
			$old += Row::int($row['created']) < $limit ? 1 : 0;
		}
		$result->closeCursor();
		return ['open' => $open, 'olderThan30Days' => $old];
	}

	/**
	 * Files found in the archive folder, with a checksum and with a
	 * checksum mismatch (the figures of the checksum view), in one grouped
	 * query.
	 *
	 * @return Checksums
	 */
	private function checksums(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sha256_mismatch')
			->selectAlias($qb->func()->count('id'), 'files')
			->selectAlias($qb->func()->count('sha256'), 'hashed')
			->from('cantoria_files')
			->where($qb->expr()->eq('found', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->groupBy('sha256_mismatch');
		$result = $qb->executeQuery();
		$counts = ['files' => 0, 'hashed' => 0, 'pending' => 0, 'mismatches' => 0];
		foreach ($result->fetchAllAssociative() as $row) {
			$files = Row::int($row['files']);
			$counts['files'] += $files;
			$counts['hashed'] += Row::int($row['hashed']);
			if (in_array($row['sha256_mismatch'], [true, 1, '1', 't'], true)) {
				$counts['mismatches'] += $files;
			}
		}
		$result->closeCursor();
		$counts['pending'] = $counts['files'] - $counts['hashed'];
		return $counts;
	}
}
