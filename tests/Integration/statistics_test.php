<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the statistics and the annual report against a real
 * Nextcloud instance with an imported catalog (the internal test data or the
 * demo data of tools/demo).
 *
 * No figure of a particular catalog is fixed: the checks compare the
 * statistics with each other (sums, states that add up, plausible years) and
 * with the other views (catalog list, release lists, resubmission list,
 * checksums), then change the catalog (a concert, an accession, a license)
 * and check the difference. Finally the catalog is filled up to 2,000 copies
 * and the time of the whole call is measured.
 *
 * Usage (as the web server user):
 *   php tests/Integration/statistics_test.php <nextcloud-root> <uid> [<programme-csv>]
 *
 * <uid> must hold the role "librarian". With <programme-csv> (relative to
 * the user's files) the performance records are imported first, so that the
 * figures of concerts and repertoire have data. Everything runs inside one
 * database transaction that is rolled back; snapshot files are removed.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);
$programmeCsv = $argv[3] ?? null;

use OCA\Cantoria\Controller\StatisticsController;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\ChecksumService;
use OCA\Cantoria\Service\CopyAssistantService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\StatisticsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$statistics = Server::get(StatisticsService::class);
$catalog = Server::get(CatalogService::class);
$settings = Server::get(AppSettings::class);
$today = Server::get(RightsService::class)->today($uid);
$year = (int)substr($today, 0, 4);
$exportBefore = array_map(fn ($n) => $n->getName(), $settings->exportFolder($uid)->getDirectoryListing());
$snapshots = [];

/**
 * Sum of a column of a list of rows.
 *
 * @param list<array<string, mixed>> $rows
 */
$sum = fn (array $rows, string $key): int => (int)array_sum(array_column($rows, $key));

/**
 * The row of a year in a list of rows with `year`.
 *
 * @param list<array<string, mixed>> $rows
 */
$yearRow = function (array $rows, int $year): ?array {
	foreach ($rows as $row) {
		if ($row['year'] === $year) {
			return $row;
		}
	}
	return null;
};

$db->beginTransaction();
try {
	if ($programmeCsv !== null && $programmeCsv !== '') {
		$file = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($programmeCsv);
		$report = Server::get(PerformanceImportService::class)->import($file->getContent(), $file->getName(), $uid, false);
		$snapshots[] = $report['snapshot'];
		$check('performance records imported for the statistics', $report['events'] > 0, (string)$report['events']);
	}

	// ---- period ----------------------------------------------------------------
	$check('invalid period refused', $throws(fn () => $statistics->overview(2020, 2010, $uid)) !== null
		&& $throws(fn () => $statistics->overview(1500, 1510, $uid)) !== null
		&& $throws(fn () => $statistics->report(1700, $uid)) !== null);
	$overview = $statistics->overview(null, null, $uid);
	$check('default period: the last ten years', $overview['period'] === ['from' => $year - 9, 'to' => $year], json_encode($overview['period']));
	$check('range of years is plausible', $overview['range']['last'] === $year && $overview['range']['first'] <= $year
		&& $overview['range']['first'] >= StatisticsService::FIRST_YEAR, json_encode($overview['range']));
	// All years with data (at most the longest period)
	$full = $statistics->overview(max($overview['range']['first'], $year - StatisticsService::MAX_YEARS + 1), $year, $uid);

	// ---- stock -----------------------------------------------------------------
	$entries = $catalog->entries();
	$stock = $overview['stock'];
	$active = array_values(array_filter($entries, fn (array $e): bool => $e['status'] === 'active'));
	$check('copies as in the catalog list', $stock['copies'] === count($entries) && $stock['copies'] > 0, $stock['copies'] . ' / ' . count($entries));
	$check('copy states add up', array_sum($stock['status']) === $stock['copies'] && $stock['status']['active'] === $stock['active'],
		json_encode($stock['status']));
	$check('binders add up', $sum($stock['binders'], 'total') === $stock['copies']
		&& array_reduce($stock['binders'], fn (bool $ok, array $b): bool => $ok
			&& $b['total'] === $b['active'] + $b['retired'] + $b['missing'] + $b['lent'] + $b['other'], true)
		&& $sum($stock['binders'], 'active') === $stock['status']['active']
		&& $sum($stock['binders'], 'retired') === $stock['status']['retired']);
	$check('active copies as in the catalog list', $stock['active'] === count($active));
	$check('active copies with a file and with MusicXML as in the catalog list',
		$stock['activeWithFile'] === count(array_filter($active, fn (array $e): bool => $e['files'] > 0))
		&& $stock['activeWithMusicxml'] === count(array_filter($active, fn (array $e): bool => $e['has_musicxml'] === true))
		&& $stock['activeWithMusicxml'] <= $stock['active'] && $stock['activeWithFile'] <= $stock['active'],
		$stock['activeWithFile'] . ' / ' . $stock['activeWithMusicxml']);
	$check('preservation copies counted as in the catalog list',
		$stock['preservationCopies'] === count(array_filter($entries, fn (array $e): bool => $e['preservation_copy'] === true))
		&& $stock['preservationVersions'] <= $stock['versions'], (string)$stock['preservationCopies']);
	$check('works and versions are plausible', $stock['works'] >= count(array_unique(array_column($entries, 'work_id')))
		&& $stock['versions'] >= count(array_unique(array_column($entries, 'version_id'))) && $stock['works'] <= $stock['versions']);
	$check('additions: one row per year, parts add up', count($stock['additions']) === 10
		&& array_column($stock['additions'], 'year') === range($year - 9, $year)
		&& array_reduce($stock['additions'], fn (bool $ok, array $a): bool => $ok && $a['total'] === $a['accession'] + $a['import'] + $a['other'], true));
	$check('every copy is a new copy of one year', $sum($full['stock']['additions'], 'total') === $stock['copies'],
		$sum($full['stock']['additions'], 'total') . ' / ' . $stock['copies']);

	// ---- rights ----------------------------------------------------------------
	$rights = $overview['rights'];
	$check('rights states add up to the versions', $sum($rights['status'], 'total') === $stock['versions']
		&& array_reduce($rights['status'], fn (bool $ok, array $r): bool => $ok && $r['total'] === $r['confirmed'] + $r['unconfirmed'], true)
		&& array_column($rights['status'], 'status') === StatisticsService::RIGHTS_STATUSES, json_encode($rights['status']));
	$valid = $rights['validVersions'];
	$check('valid versions add up to the works', $valid['works'] === $stock['works'] && $valid['works'] === $valid['withValid'] + $valid['withoutValid']
		&& $valid['withoutValidSeveral'] <= $valid['withoutValid'] && $valid['currentWithoutValid'] <= $valid['withoutValid'], json_encode($valid));
	$licenses = $rights['licenses'];
	$check('license groups add up', $licenses['total'] === $licenses['active'] + $licenses['ending'] + $licenses['ended'] + $licenses['future'],
		json_encode($licenses));
	$releaseLists = Server::get(ReleaseListService::class);
	$portal = array_count_values(array_column($releaseLists->portal($uid, true), 'status'));
	$printStation = array_count_values(array_column($releaseLists->printStation($uid, true), 'status'));
	$check('portal states as in the release list', array_filter($rights['portal']) == $portal && array_sum($rights['portal']) === $stock['versions'],
		json_encode($rights['portal']));
	$check('print station states as in the release list', array_filter($rights['printStation']) == $printStation
		&& array_sum($rights['printStation']) === $stock['versions'] && $rights['printStation']['ok'] === 0 && $rights['printStation']['remove'] === 0);
	$check('publication states add up', array_sum($rights['publication']) === $stock['versions'], json_encode($rights['publication']));

	// ---- repertoire ------------------------------------------------------------
	$repertoire = $overview['repertoire'];
	$check('concerts: one row per year, plausible', array_column($repertoire['years'], 'year') === range($year - 9, $year)
		&& array_reduce($repertoire['years'], fn (bool $ok, array $y): bool => $ok && $y['works'] <= $y['pieces']
			&& ($y['pieces'] === 0 || $y['concerts'] > 0), true), json_encode($repertoire['years']));
	$ahead = $statistics->overview($year + 1, $year + 10, $uid);
	$check('no concert in a future year is confirmed as sung', $sum($ahead['repertoire']['years'], 'concerts') === 0
		&& $sum($ahead['repertoire']['years'], 'pieces') === 0 && $sum($ahead['stock']['additions'], 'total') === 0,
		json_encode(array_filter($ahead['repertoire']['years'], fn (array $y): bool => $y['concerts'] > 0)));
	$perWork = Server::get(RepertoireService::class)->perWork();
	$top = $full['repertoire']['topWorks'];
	$counts = array_column($top, 'performances');
	$sorted = $counts;
	rsort($sorted);
	$check('top works: at most ten, the most performed first, within the documented performances', count($top) <= StatisticsService::TOP
		&& $counts === $sorted
		&& array_reduce($top, fn (bool $ok, array $w): bool => $ok && $w['performances'] <= ($perWork[$w['workId']]['performances'] ?? 0), true),
		json_encode(array_map(fn ($w) => [$w['title'], $w['performances']], $top), JSON_UNESCAPED_UNICODE));
	$check('top composers: at most ten, sorted', count($full['repertoire']['topComposers']) <= StatisticsService::TOP
		&& array_column($full['repertoire']['topComposers'], 'performances') === (function (array $c): array {
			rsort($c);
			return $c;
		})(array_column($full['repertoire']['topComposers'], 'performances')));
	if (($repertoire['topWorks'][0] ?? null) !== null) {
		$check('the most performed work of the period is not performed more often than all pieces', $repertoire['topWorks'][0]['performances']
			<= $sum($repertoire['years'], 'pieces'));
	}
	$check('repertoire classes add up to the works', array_sum($repertoire['classes']) === $stock['works']
		&& $repertoire['sources']['performances'] + $repertoire['sources']['import'] <= $stock['works'], json_encode($repertoire['classes']));
	$classOf = [];
	foreach ($entries as $entry) {
		$classOf[(int)$entry['work_id']] = $entry['repertoire_class'] ?? 'none';
	}
	$check('current repertoire as in the catalog list', $repertoire['classes']['current']
		=== count(array_filter($classOf, fn ($class) => $class === 'current')) || count($classOf) < $stock['works']);
	$check('works not sung for a long time: dormant or earlier, singable', count($repertoire['longNotSung']) <= StatisticsService::LONG_NOT_SUNG
		&& count($repertoire['longNotSung']) <= $repertoire['longNotSungTotal']
		&& $repertoire['longNotSungTotal'] <= $repertoire['classes']['dormant'] + $repertoire['classes']['earlier']
		&& array_reduce($repertoire['longNotSung'], fn (bool $ok, array $w): bool => $ok && in_array($w['class'], ['dormant', 'earlier'], true)
			&& ($classOf[$w['workId']] ?? null) === $w['class'], true));

	// ---- maintenance -----------------------------------------------------------
	$maintenance = $overview['maintenance'];
	$check('tasks: every addressed role, ages add up', array_column($maintenance['tasks'], 'addressee') === ['conductor', 'librarian', 'operations', 'reader']
		&& array_reduce($maintenance['tasks'], fn (bool $ok, array $t): bool => $ok
			&& $t['open'] + $t['answered'] === $t['days30'] + $t['days90'] + $t['days365'] + $t['older'], true));
	$check('proposals as in the inbox', $maintenance['proposals']['open'] === Server::get(ProposalMapper::class)->countOpen()
		&& $maintenance['proposals']['olderThan30Days'] <= $maintenance['proposals']['open']);
	$checksums = Server::get(ChecksumService::class)->summary();
	$figures = $maintenance['checksums'];
	$check('checksums as in the checksum view', $figures['files'] === $checksums['files'] && $figures['hashed'] === $checksums['hashed']
		&& $figures['pending'] === $checksums['pending'] && $figures['mismatches'] === count($checksums['mismatches']), json_encode($figures));
	$protection = $full['maintenance']['protection'];
	$check('protection terms: years add up, rows within the period', $sum($protection['years'], 'versions') === $protection['total']
		&& count($protection['rows']) <= $protection['total'] - $protection['omitted']
		&& array_reduce($protection['rows'], fn (bool $ok, array $r): bool => $ok && (int)substr($r['until'], 0, 4) >= $overview['range']['first']
			&& (int)substr($r['until'], 0, 4) <= $year && $r['copies'] !== [], true), json_encode(['total' => $protection['total'], 'omitted' => $protection['omitted']]));

	// ---- annual report ---------------------------------------------------------
	$concertYears = array_values(array_filter($full['repertoire']['years'], fn (array $y): bool => $y['concerts'] > 0));
	$reportYear = $concertYears === [] ? $year : $concertYears[count($concertYears) - 1]['year'];
	$report = $statistics->report($reportYear, $uid);
	$single = $statistics->overview($reportYear, $reportYear, $uid);
	$check('report totals as in the statistics of ' . $reportYear, $report['totals'] === $single['repertoire']['years'][0]
		&& $report['totals'] === ($yearRow($full['repertoire']['years'], $reportYear) ?? $report['totals']), json_encode($report['totals']));
	$sung = array_values(array_filter($report['concerts'], fn (array $c): bool => $c['status'] === 'sung'));
	$check('report: concerts with dates, sung pieces add up', count($sung) === $report['totals']['concerts']
		&& count($report['concerts']) - count($sung) === $report['totals']['planned'] && $sum($sung, 'pieces') === $report['totals']['pieces']
		&& array_reduce($report['concerts'], fn (bool $ok, array $c): bool => $ok && str_starts_with((string)$c['date'], (string)$reportYear), true),
		count($report['concerts']) . ' concerts');
	$check('report: additions as in the statistics', $report['additions'] === $single['stock']['additions'][0]
		&& count($report['accessions']) + $report['accessionsOmitted'] === $report['additions']['accession']);
	$open = $report['openRights'];
	$byStatus = array_column($rights['status'], null, 'status');
	$check('report: open rights questions as in the rights states', $open['open'] === $byStatus['open']['total']
		&& $open['none'] === $byStatus['none']['total'] && $open['publicDomainUnconfirmed'] === $byStatus['public_domain']['unconfirmed']
		&& $open['protectedUnconfirmed'] === $byStatus['protected']['unconfirmed'], json_encode($open));
	$review = array_count_values(array_column(Server::get(PersonService::class)->review($uid), 'kind'));
	$check('report: protection questions as in the resubmission list', $open['protectionEnded'] === ($review['ended'] ?? 0)
		&& $open['conflicts'] === ($review['conflict'] ?? 0), json_encode($review));
	$check('report: stock as in the statistics', $report['stock'] === ['works' => $stock['works'], 'versions' => $stock['versions'],
		'copies' => $stock['copies'], 'active' => $stock['active']]);
	$check('report: licenses plausible', $report['licenses']['valid'] <= $licenses['total']
		&& $report['licenses']['validOn'] <= $today);

	// ---- no account names, no preservation copies by name ----------------------
	$json = json_encode([$overview, $full, $report], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	$displayName = Server::get(IUserManager::class)->getDisplayName($uid) ?? $uid;
	$check('no account names in the statistics', !str_contains($json, '"' . $uid . '"') && !str_contains($json, '"' . $displayName . '"'));
	$preservation = array_column(array_filter($entries, fn (array $e): bool => $e['preservation_copy'] === true), 'shelfmark');
	$listed = array_merge(array_column($full['repertoire']['longNotSung'], 'shelfmark'), array_column($report['accessions'], 'shelfmark'),
		...array_map(fn (array $r): array => array_column($r['copies'], 'shelfmark'), $protection['rows']));
	$check('internal preservation copies are not listed', array_intersect($preservation, $listed) === [], implode(', ', array_intersect($preservation, $listed)));

	// ---- controller: access and CSV --------------------------------------------
	$l = Server::get(IFactory::class)->get('cantoria');
	$controller = fn (string $user): StatisticsController => new StatisticsController(Server::get(IRequest::class), $user, Server::get(Roles::class),
		$statistics, $l, Server::get(LoggerInterface::class));
	$check('without a role no statistics', $controller('cantoria-test-nobody')->overview()->getStatus() === Http::STATUS_FORBIDDEN
		&& $controller('cantoria-test-nobody')->csv()->getStatus() === Http::STATUS_FORBIDDEN);
	$check('invalid period: 400', $controller($uid)->overview(2020, 2010)->getStatus() === Http::STATUS_BAD_REQUEST
		&& $controller($uid)->reportCsv(1700)->getStatus() === Http::STATUS_BAD_REQUEST);
	$csv = $controller($uid)->csv();
	$reportCsv = $controller($uid)->reportCsv($reportYear);
	$check('CSV downloads', $csv instanceof DataDownloadResponse && $reportCsv instanceof DataDownloadResponse
		&& str_starts_with($csv->render(), CatalogCsv::BOM) && str_starts_with($reportCsv->render(), CatalogCsv::BOM)
		&& count(CatalogCsv::parse($csv->render())['rows']) > 50);

	// ---- changes: a concert, an accession, a license ---------------------------
	$singable = array_values(array_filter($active, fn (array $e): bool => $e['preservation_copy'] !== true));
	$before = $statistics->overview($year, $year, $uid);
	$beforeReport = $statistics->report($year, $uid);
	$events = Server::get(EventService::class);
	$concert = $events->create('concert', ['title' => 'Statistics test concert', 'event_date' => $today], $uid);
	$events->addItem($concert->getId(), $singable[0]['id'], $uid);
	$events->addItem($concert->getId(), $singable[1]['id'], $uid);
	$events->addItem($concert->getId(), $singable[1]['id'], $uid);
	$events->confirmSung($concert->getId(), [], $uid);
	$assistant = Server::get(CopyAssistantService::class);
	$accession = $assistant->create(['title' => 'Statistics test accession', 'composer' => 'Test composer', 'title_source' => 'Integration test',
		'accession_date' => $today, 'origin' => 'Integration test'], null, $uid);
	$ends = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
	Server::get(LicenseService::class)->create($singable[0]['version_id'], ['holder' => 'Test publisher', 'uses' => ['binder'],
		'valid_from' => $today, 'valid_until' => $ends, 'evidence' => 'Integration test'], $uid);
	$after = $statistics->overview($year, $year, $uid);
	$afterReport = $statistics->report($year, $uid);
	$b = $before['repertoire']['years'][0];
	$a = $after['repertoire']['years'][0];
	$check('a sung concert counts', $a['concerts'] === $b['concerts'] + 1 && $a['pieces'] === $b['pieces'] + 3 && $a['works'] >= $b['works'],
		json_encode([$b, $a]));
	$listedConcert = array_values(array_filter($afterReport['concerts'], fn (array $c): bool => $c['id'] === $concert->getId()));
	$check('the concert is in the annual report with its pieces', count($listedConcert) === 1 && $listedConcert[0]['pieces'] === 3
		&& $listedConcert[0]['date'] === $today && $listedConcert[0]['status'] === 'sung');
	$check('an accession counts', $after['stock']['copies'] === $before['stock']['copies'] + 1
		&& $after['stock']['additions'][0]['accession'] === $before['stock']['additions'][0]['accession'] + 1
		&& $after['stock']['works'] === $before['stock']['works'] + 1 && $after['rights']['status'][2]['total'] === $before['rights']['status'][2]['total'] + 1,
		json_encode($after['stock']['additions']));
	$check('the accession is in the annual report', in_array($accession['shelfmark'], array_column($afterReport['accessions'], 'shelfmark'), true)
		&& count($afterReport['accessions']) === count($beforeReport['accessions']) + 1);
	$check('a license ending soon counts', $after['rights']['licenses']['ending'] === $before['rights']['licenses']['ending'] + 1
		&& $after['rights']['licenses']['total'] === $before['rights']['licenses']['total'] + 1
		&& count($afterReport['licenses']['started']) === count($beforeReport['licenses']['started']) + 1);

	// ---- 2,000 copies: time of the whole call ----------------------------------
	$now = time();
	$insert = function (string $table, array $values) use ($db): int {
		$qb = $db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
				: (is_bool($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	};
	$missing = max(0, 2000 - count($catalog->entries()));
	$added = [];
	for ($i = 1; $i <= $missing; $i++) {
		$workId = $insert('cantoria_works', ['title' => 'Load test ' . $i, 'composer' => 'Composer ' . ($i % 150), 'created' => $now, 'modified' => $now]);
		$versionId = $insert('cantoria_versions', ['work_id' => $workId, 'rights_status' => ['public_domain', 'protected', 'open'][$i % 3],
			'preservation_copy' => false, 'created' => $now, 'modified' => $now]);
		$copyId = $insert('cantoria_copies', ['version_id' => $versionId, 'binder' => 9000 + intdiv($i, 100), 'number' => $i % 100 + 1,
			'shelfmark' => (9000 + intdiv($i, 100)) . '-' . ($i % 100 + 1), 'sort_order' => 100000 + $i, 'status' => 'active',
			'created' => $now, 'modified' => $now]);
		$added[] = [$copyId, $versionId, $workId];
	}
	for ($e = 1; $e <= 100 && $added !== []; $e++) {
		$eventId = $insert('cantoria_events', ['kind' => 'concert', 'title' => 'Load test ' . $e,
			'event_date' => sprintf('%04d-%02d-15', $year - intdiv($e, 10), $e % 12 + 1), 'status' => 'sung', 'imported' => true,
			'created' => $now, 'modified' => $now]);
		for ($p = 1; $p <= 15; $p++) {
			[$copyId, $versionId, $workId] = $added[($e * 17 + $p * 31) % count($added)];
			$insert('cantoria_event_items', ['event_id' => $eventId, 'position' => $p, 'copy_id' => $copyId, 'version_id' => $versionId,
				'work_id' => $workId, 'assignment' => 'certain', 'counts' => true]);
		}
	}
	$times = [];
	for ($run = 0; $run < 2; $run++) {
		$start = hrtime(true);
		$large = $statistics->overview(null, null, $uid);
		$times[] = (hrtime(true) - $start) / 1e9;
	}
	$start = hrtime(true);
	$statistics->report($year, $uid);
	$reportTime = (hrtime(true) - $start) / 1e9;
	$check('2,000 copies: statistics in well under a second', $large['stock']['copies'] >= 2000 && min($times) < 1.0 && $reportTime < 1.0,
		sprintf('%d copies, overview %.3f s / %.3f s, report %.3f s', $large['stock']['copies'], $times[0], $times[1], $reportTime));
} catch (\Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$folder = $settings->exportFolder($uid);
	foreach ($snapshots as $path) {
		$name = basename((string)$path);
		if ($name !== '' && !in_array($name, $exportBefore, true) && $folder->nodeExists($name)) {
			$folder->get($name)->delete();
		}
	}
}

IntegrationTest::finish();
