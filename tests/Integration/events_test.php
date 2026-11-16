<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of performances, song lists, concerts and the derived
 * repertoire (milestone 0.4) against a real Nextcloud instance.
 *
 * Usage (as the web server user):
 *   php tests/Integration/events_test.php <nextcloud-root> <uid> <programme-csv> <song-list-csv> <shelfmark-a> <shelfmark-b>
 *
 * The CSV paths are relative to the user's files. <uid> must hold the role
 * "librarian". Database changes are rolled back; snapshot files are removed.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $programmeCsv, $songListCsv, $shelfmarkA, $shelfmarkB] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'programme-csv', 'song-list-csv', 'shelfmark-a', 'shelfmark-b']);

use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$userFolder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$import = Server::get(PerformanceImportService::class);
$events = Server::get(EventService::class);
$repertoire = Server::get(RepertoireService::class);
$copies = Server::get(CopyMapper::class)->findAllByShelfmark();
$versions = Server::get(WorkVersionMapper::class);
$roles = Server::get(Roles::class);
$conductorGroups = $roles->groups(Roles::CONDUCTOR);
$settings = Server::get(AppSettings::class);
$exportBefore = array_map(fn ($n) => $n->getName(), $settings->exportFolder($uid)->getDirectoryListing());
$snapshots = [];

$db->beginTransaction();
try {
	// Dry run first: nothing stays
	$programme = $userFolder->get($programmeCsv);
	$dry = $import->import($programme->getContent(), $programme->getName(), $uid, true);
	$check('dry run reads the programme lines', $dry['items'] > 0 && $dry['events'] > 0, json_encode(['events' => $dry['events'], 'items' => $dry['items'], 'ownChoir' => $dry['ownChoir']]));
	$check('dry run leaves no events', $events->list(null) === []);

	$report = $import->import($programme->getContent(), $programme->getName(), $uid, false);
	$snapshots[] = $report['snapshot'];
	$check('programme imported', $report['events'] === $dry['events'] && $report['certain'] > 0,
		json_encode(['certain' => $report['certain'], 'ambiguous' => $report['ambiguous'], 'counted' => $report['counted'], 'notInCatalog' => $report['notInCatalog']]));
	$check('own choir detected', $report['ownChoir'] !== null, (string)$report['ownChoir']);

	$lists = $userFolder->get($songListCsv);
	$listReport = $import->import($lists->getContent(), $lists->getName(), $uid, false);
	$snapshots[] = $listReport['snapshot'];
	$check('song lists imported', $listReport['songLists'] > 0, (string)$listReport['songLists']);

	// Derived repertoire for a work of the current repertoire
	$workA = $versions->find($copies[$shelfmarkA]->getVersionId())->getWorkId();
	$figures = $repertoire->perWork()[$workA] ?? null;
	$check('derived figures for ' . $shelfmarkA, $figures !== null && $figures['performances'] > 0, json_encode($figures));
	$row = array_values(array_filter(Server::get(CatalogService::class)->entries(), fn ($r) => $r['shelfmark'] === $shelfmarkA))[0];
	$check('list shows derived repertoire', $row['repertoire_source'] === 'performances' && $row['repertoire_class'] !== null, $row['repertoire_class'] . ' / ' . $row['last_performed_on']);

	// Ambiguous line can be resolved
	$ambiguous = Server::get(EventItemMapper::class)->findByAssignment(EventItem::AMBIGUOUS);
	$check('ambiguous lines kept with candidates', $ambiguous !== [] && $ambiguous[0]->getCandidates() !== null, (string)count($ambiguous));
	$events->resolveItem($ambiguous[0]->getId(), $copies[$shelfmarkB]->getId(), $uid);
	$check('ambiguous line resolved', Server::get(EventItemMapper::class)->find($ambiguous[0]->getId())->getAssignment() === 'certain');

	// Imported programmes are history
	$importedEvent = $events->list('concert')[0];
	$check('imported programme cannot be changed', $throws(fn () => $events->update($importedEvent['id'], ['title' => 'x'], $uid)) !== null);

	// A planned programme needs a title, also when it is changed
	$check('programme without title refused', $throws(fn () => $events->create('concert', ['location' => 'Hall'], $uid)) === 'Please enter a title.');
	$check('programme title cannot be emptied', $throws(function () use ($events, $uid) {
		$draft = $events->create('concert', ['title' => 'Draft'], $uid);
		$events->update($draft->getId(), ['title' => ' '], $uid);
	}) === 'Please enter a title.');

	// Plan a concert: draft → fixed → sung
	$day = Server::get(RightsService::class)->today($uid);
	$concert = $events->create('concert', ['title' => 'Test concert', 'event_date' => $day, 'location' => 'Hall'], $uid);
	$itemA = $events->addItem($concert->getId(), $copies[$shelfmarkA]->getId(), $uid);
	$itemB = $events->addItem($concert->getId(), $copies[$shelfmarkB]->getId(), $uid);
	$events->updateItem($itemA->getId(), ['duration' => 185], $uid);
	$detail = $events->detail($concert->getId(), $uid);
	$check('programme lists items with warnings', count($detail['items']) === 2 && $detail['totalDuration'] === 185 && $detail['missingDurations'] === 1,
		json_encode(array_column($detail['items'], 'warnings')));
	$events->reorder($concert->getId(), [$itemB->getId(), $itemA->getId()], $uid);
	$check('reordered', $events->detail($concert->getId(), $uid)['items'][0]['id'] === $itemB->getId());
	$csv = $events->songOrderCsv($concert->getId(), $uid, Server::get(IFactory::class)->get('cantoria', 'en'));
	$check('song order CSV: four rows of the event, an empty one, the header, two pieces', substr_count($csv, "\r\n") === 8
		&& str_contains($csv, '3:05') && str_contains($csv, 'Work number (GEMA)') && str_contains($csv, 'Lyricist'), $csv);
	$events->setFixed($concert->getId(), $uid);
	$check('future concert cannot be confirmed', $throws(function () use ($events, $uid) {
		$future = $events->create('concert', ['title' => 'Later', 'event_date' => '2099-01-01'], $uid);
		$events->confirmSung($future->getId(), [], $uid);
	}) !== null);
	$before = $repertoire->perWork()[$workA]['performances'] ?? 0;
	$events->confirmSung($concert->getId(), [$itemB->getId()], $uid);
	$after = $repertoire->perWork()[$workA]['performances'] ?? 0;
	$check('confirmed concert counts as performance', $after === $before + ($before > 0 && in_array($day, [], true) ? 0 : 1), "$before → $after");
	$check('sung concert is history', $throws(fn () => $events->addItem($concert->getId(), $copies[$shelfmarkA]->getId(), $uid)) !== null);
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::CONDUCTOR, $conductorGroups);
	$folder = $settings->exportFolder($uid);
	foreach ($snapshots as $path) {
		$name = basename((string)$path);
		if ($name !== '' && !in_array($name, $exportBefore, true) && $folder->nodeExists($name)) {
			$folder->get($name)->delete();
		}
	}
}

IntegrationTest::finish();
