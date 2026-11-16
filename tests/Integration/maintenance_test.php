<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of "maintaining the stock" (milestone 0.5) against a real
 * Nextcloud instance with an imported catalog: person register and import,
 * protection proposal and resubmission, new accessions with manual file
 * links, merges with undo, inventory and checksums.
 *
 * Usage (as the web server user):
 *   php tests/Integration/maintenance_test.php <nextcloud-root> <uid> <persons-csv> <shelfmark-a> <shelfmark-b> <shelfmark-free>
 *
 * <persons-csv> is relative to the user's files. <shelfmark-a> and
 * <shelfmark-b> are two copies of the same print (e.g. 1-23 and 6-8);
 * <shelfmark-free> is a copy whose persons all died more than 70 years ago
 * (e.g. 2-35). <uid> must hold the role "librarian" and be the archive user.
 * Everything runs inside one database transaction that is rolled back; app
 * settings and snapshot files are restored or removed.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $personsCsv, $shelfA, $shelfB, $shelfFree] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'persons-csv', 'shelfmark-a', 'shelfmark-b', 'shelfmark-free']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\BackgroundJob\DailyChecks;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Notification\Notifier;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\ChecksumService;
use OCA\Cantoria\Service\CopyAssistantService;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\InventoryService;
use OCA\Cantoria\Service\LifeDates;
use OCA\Cantoria\Service\MergeService;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\PersonImportService;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\ProtectionText;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\RunLock;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);
$runJob = function (object $job): void {
	(new ReflectionMethod($job, 'run'))->invoke($job, null);
};
$notificationCount = function (string $user, string $subject): int {
	$qb = Server::get(IDBConnection::class)->getQueryBuilder();
	$qb->select('notification_id')->from('notifications')
		->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
		->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($user)))
		->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
	return count($qb->executeQuery()->fetchAllAssociative());
};
$nobody = 'cantoria-test-nobody';

// Pure functions first: life dates, protection term, name cleaning
$lifeCases = [
	'1789-1860' => ['1789', 'year', '1860', 'year'],
	'1789–1860' => ['1789', 'year', '1860', 'year'],
	'(1789 - 1860)' => ['1789', 'year', '1860', 'year'],
	// a birth without a death means living, as catalogs write it, unless it is more than 120 years ago
	'geb. 1928' => ['1928', 'year', null, 'living'],
	'geb. 1850' => ['1850', 'year', null, 'unknown'],
	'† 1955' => [null, 'unknown', '1955', 'year'],
	'*1944, lebend' => ['1944', 'year', null, 'living'],
	'20./21. Jahrhundert' => [null, 'unknown', null, 'unknown'],
	'ca. 1850-1910' => ['1850', 'circa', '1910', 'circa'],
	'1850-ca. 1910' => ['1850', 'year', '1910', 'circa'],
	'1850?-1910' => ['1850', 'circa', '1910', 'year'],
	'65-8 v. Chr.' => ['-65', 'year', '-8', 'year'],
	'12.03.1850–05.11.1910' => ['1850-03-12', 'exact', '1910-11-05', 'exact'],
	'185?' => [null, 'unknown', null, 'unknown'],
	'-65--8' => ['-65', 'year', '-8', 'year'],
	'1910-1850' => [null, 'unknown', null, 'unknown'],
	'' => [null, 'unknown', null, 'unknown'],
];
$wrong = [];
foreach ($lifeCases as $text => $expected) {
	$r = LifeDates::parse($text);
	if ([$r['born'], $r['born_precision'], $r['died'], $r['died_precision']] !== $expected) {
		$wrong[] = $text . ' → ' . json_encode($r, JSON_UNESCAPED_UNICODE);
	}
}
$check('life dates are read with their precision', $wrong === [], implode('; ', $wrong));

$person = function (string $name, ?string $died, string $precision, string $identification = 'probable'): Person {
	$p = new Person();
	$p->setName($name);
	$p->setValue('died', $died);
	$p->setValue('died_precision', $precision);
	$p->setValue('identification', $identification);
	return $p;
};
$t = ProtectionTerm::propose([], 2026);
$check('no persons: unknown means protected', $t['status'] === 'unknown' && $t['reason'] === 'no_persons');
$t = ProtectionTerm::propose([$person('A', '1847', 'year'), $person('B', '1857', 'year')], 2026);
$check('last death counts: free since 1928', $t['status'] === 'free' && $t['until'] === '1927-12-31' && $t['freeSince'] === '1928-01-01', json_encode($t));
$check('70 years after the end of the year of death', ProtectionTerm::propose([$person('A', '1955', 'year')], 2025)['status'] === 'protected'
	&& ProtectionTerm::propose([$person('A', '1955', 'year')], 2026)['status'] === 'free');
$check('a living person blocks', ProtectionTerm::propose([$person('A', '1847', 'year'), $person('B', null, 'living')], 2026)['reason'] === 'living');
$check('an approximate death blocks', ProtectionTerm::propose([$person('A', '1800', 'circa')], 2026)['status'] === 'unknown');
$check('an uncertain identification blocks', ProtectionTerm::propose([$person('A', '1800', 'year', 'uncertain')], 2026)['reason'] === 'identification_uncertain');
$check('an exact date counts', ProtectionTerm::propose([$person('A', '1847-05-04', 'exact')], 2026)['status'] === 'free');
$check('role words are removed from names', PersonImportService::cleanName('Tonsatz von Michael Prätorius') === 'Michael Prätorius'
	&& PersonImportService::cleanName('Friedrich Silcher, op. 26') === 'Friedrich Silcher'
	&& PersonImportService::cleanName('übertragen von Carl Loewe') === 'Carl Loewe'
	&& PersonImportService::cleanName('Franz Schubert, 1826') === 'Franz Schubert');

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$settings = Server::get(AppSettings::class);
$check('archive user is the test user', $settings->archiveInfo()['user'] === $uid);
$userFolder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$copyMapper = Server::get(CopyMapper::class);
$copies = $copyMapper->findAllByShelfmark();
$versions = Server::get(WorkVersionMapper::class);
$works = Server::get(WorkMapper::class);
$fileMapper = Server::get(ArchiveFileMapper::class);
$catalog = Server::get(CatalogService::class);
$persons = Server::get(PersonService::class);
$personImport = Server::get(PersonImportService::class);
$assistant = Server::get(CopyAssistantService::class);
$merges = Server::get(MergeService::class);
$inventory = Server::get(InventoryService::class);
$checksums = Server::get(ChecksumService::class);
$rights = Server::get(RightsService::class);
$today = $rights->today($uid);
$year = (int)substr($today, 0, 4);

$restore = [];
foreach ([[ChecksumService::CURSOR_KEY, true], [CopyAssistantService::OPEN_BINDERS_KEY, false]] as [$key, $lazy]) {
	$restore[$key] = $appConfig->hasKey(Application::APP_ID, $key, $lazy)
		? ($lazy ? $appConfig->getValueInt(Application::APP_ID, $key, 0, true) : $appConfig->getValueArray(Application::APP_ID, $key, []))
		: null;
}
$exportBefore = array_map(fn ($n) => $n->getName(), $settings->exportFolder($uid)->getDirectoryListing());
$snapshots = [];
$copyA = $copies[$shelfA];
$copyB = $copies[$shelfB];
$versionFree = $copies[$shelfFree]->getVersionId();

$db->beginTransaction();
try {
	// Person import: dry run, real run, repeated run
	$csv = $userFolder->get($personsCsv);
	$personsBefore = count($persons->list());
	$dry = $personImport->import($csv->getContent(), $csv->getName(), $uid, true);
	$check('dry run reads the list', $dry['created'] > 0 && $dry['contributions'] > 0,
		json_encode(['rows' => $dry['rows'], 'created' => $dry['created'], 'existing' => $dry['existing'], 'contributions' => $dry['contributions']]));
	$check('dry run leaves no persons', count($persons->list()) === $personsBefore);
	$report = $personImport->import($csv->getContent(), $csv->getName(), $uid, false);
	$snapshots[] = $report['snapshot'];
	$check('persons imported', $report['created'] === $dry['created'] && $report['contributions'] === $dry['contributions'] && $report['unknownShelfmarks'] === [],
		json_encode(['precision' => $report['precision'], 'identification' => $report['identification'], 'unknown' => $report['unknownShelfmarks']]));
	$check('names cleaned and duplicates found', in_array('Michael Prätorius', array_column($report['renamed'], 'to'), true) && $report['existing'] >= 1,
		count($report['renamed']) . ' renamed, ' . $report['existing'] . ' existing');
	$check('research lists never give a certain identification', !isset($report['identification']['certain']) && ($report['identification']['uncertain'] ?? 0) >= 1);
	$check('list and computed end of protection agree', $report['protectionDiffers'] === [], json_encode($report['protectionDiffers'], JSON_UNESCAPED_UNICODE));
	$again = $personImport->import($csv->getContent(), $csv->getName(), $uid, false);
	$snapshots[] = $again['snapshot'];
	$check('repeated import adds nothing', $again['created'] === 0 && $again['contributions'] === 0 && $again['contributionsExisting'] === $report['contributions']
		&& $again['snapshot'] !== null, (string)$again['snapshot']);

	// The import leaves the roles open; a "free" proposal needs composer and lyricist.
	// Names of the demo data (CI) and of other test data (a hyphen in a name is read as a blank).
	$importedRoles = ['Friedrich Silcher' => 'composer', 'Simon Dach' => 'lyricist', 'Johann Gottfried Herder' => 'translator',
		'Felix Mendelssohn Bartholdy' => 'composer', 'Joseph von Eichendorff' => 'lyricist'];
	foreach ($persons->forVersion($versionFree) as $row) {
		$role = $importedRoles[str_replace('-', ' ', $row['person']['name'] ?? '')] ?? null;
		if ($role !== null) {
			$persons->updateContribution((int)$row['id'], ['role' => $role], $uid);
		}
	}

	// Protection proposal per version, in the entry, translated
	$proposal = $persons->protection($versionFree, $year);
	$check('protection proposal: free', $proposal['status'] === 'free' && $proposal['freeSince'] !== null, json_encode($proposal, JSON_UNESCAPED_UNICODE));
	$proposalA = $persons->protection($copyA->getVersionId(), $year);
	$check('unidentified person: no "free" proposal', $proposalA['status'] === 'unknown' && $proposalA['blocking'] !== [],
		$proposalA['reason'] . ' ' . json_encode(array_column($proposalA['blocking'], 'name'), JSON_UNESCAPED_UNICODE));
	$entry = $catalog->entry($copies[$shelfFree]->getId(), $uid);
	$check('entry lists persons and proposal', count($entry['contributions']) >= 1 && $entry['protection']['status'] === 'free');
	$text = ProtectionText::apply(Server::get(IFactory::class)->get(Application::APP_ID, 'de'), $proposal);
	$check('proposal has a reason and a notice', $text['reasonText'] !== '' && $text['notice'] !== '', $text['reasonText']);
	$review = $persons->review($uid);
	$conflicts = array_filter($review, fn ($r) => $r['kind'] === 'conflict');
	$check('resubmission lists "public domain" versions whose persons do not allow it', $conflicts !== []
		&& array_filter($conflicts, fn ($r) => $r['rightsStatus'] !== 'public_domain' || $r['protection']['status'] === 'free') === [],
		json_encode(array_map(fn ($r) => [$r['copies'][0]['shelfmark'] ?? null, $r['kind'], $r['rightsStatus'], $r['protection']['status']], $review)));

	// Person register: validation, contributions, check stamp, delete
	$check('person without name refused', $throws(fn () => $persons->create(['name' => ' '], $uid)) !== null);
	$check('person without a name field refused', $throws(fn () => $persons->create(['notes' => 'x'], $uid)) === 'Please enter a name.');
	$check('death before birth refused', $throws(fn () => $persons->create(['name' => 'X', 'born' => '1850', 'died' => '1800'], $uid)) !== null);
	$check('exact date needs a full date', $throws(fn () => $persons->create(['name' => 'X', 'died' => '1850', 'died_precision' => 'exact'], $uid)) !== null);
	$check('invalid Wikidata ID refused', $throws(fn () => $persons->create(['name' => 'X', 'wikidata' => 'abc'], $uid)) !== null);
	$check('only librarians write persons', $throws(fn () => $persons->create(['name' => 'X'], $nobody)) !== null);
	$p = $persons->create(['name' => 'Test Person', 'died' => '1900', 'identification' => 'probable'], $uid);
	$check('year without precision is a year', $p->getDiedPrecision() === 'year' && $p->getBornPrecision() === 'unknown');
	$contribution = $persons->addContribution($versionFree, $p->getId(), 'arranger', false, $uid);
	$check('same role twice refused', $throws(fn () => $persons->addContribution($versionFree, $p->getId(), 'arranger', false, $uid)) !== null);
	$check('person with contributions cannot be deleted', $throws(fn () => $persons->delete($p->getId(), $uid)) !== null);
	$persons->updateContribution($contribution->getId(), ['role' => 'translator', 'text_for_composition' => true], $uid);
	$persons->markChecked($p->getId(), $uid);
	$check('check stamp set', $persons->load($p->getId())->getCheckedBy() === $uid);
	$persons->update($p->getId(), ['died' => '1901'], $uid);
	$check('changed life dates remove the check stamp', $persons->load($p->getId())->getCheckedBy() === null);
	$persons->removeContribution($contribution->getId(), $uid);
	$persons->delete($p->getId(), $uid);
	$check('person without contributions deleted', $throws(fn () => $persons->load($p->getId())) !== null);

	// New accession: next free shelf mark, never reused, manual file link
	$highest = $assistant->highestNumbers();
	$next = $assistant->next();
	$binder = $next['proposal']['binder'];
	$check('next shelf mark in the highest binder', $binder === max(array_keys($highest)) && $next['proposal']['number'] === $highest[$binder] + 1,
		$next['proposal']['shelfmark']);
	$base = ['title' => 'Test accession', 'composer' => 'Test composer', 'title_source' => 'Integration test',
		'accession_date' => $today, 'origin' => 'Integration test'];
	$check('used number refused', $throws(fn () => $assistant->create($base + ['binder' => $binder, 'number' => $highest[$binder]], null, $uid)) !== null);
	$closedBinder = min(array_keys($highest)) === $binder ? $binder + 1 : min(array_keys($highest));
	$check('closed binder refused', $throws(fn () => $assistant->create($base + ['binder' => $closedBinder], null, $uid)) !== null);
	$check('missing title refused', $throws(fn () => $assistant->create(['title' => ''] + $base, null, $uid)) !== null);
	$check('future accession date refused', $throws(fn () => $assistant->create(['accession_date' => '2099-01-01'] + $base, null, $uid)) !== null);
	$check('only librarians add copies', $throws(fn () => $assistant->create($base, null, $nobody)) !== null);
	$linkFile = null;
	foreach ($fileMapper->findByCopy($copyA->getId()) as $file) {
		if ($file->getType() === 'pdf') {
			$linkFile = $file;
			break;
		}
	}
	$created = $assistant->create($base, $linkFile?->getFileId(), $uid);
	$check('accession created', $created['shelfmark'] === $next['proposal']['shelfmark'] && $created['label']['label'] === $binder . ' / ' . $next['proposal']['number']);
	$newEntry = $catalog->entry($created['copyId'], $uid);
	$check('accession: rights open, notes, file linked by hand', $newEntry['version']->getRightsStatus() === 'open'
		&& str_contains((string)$newEntry['version']->getValue('notes'), 'Integration test')
		&& count($newEntry['files']) === 1 && $newEntry['files'][0]->getLinkedManually() === true);
	$catalog->update('copy', $created['copyId'], ['status' => 'retired', 'status_reason' => 'test'], $uid, 'test');
	$check('numbers of retired copies are not reused', $assistant->next()['proposal']['number'] === $next['proposal']['number'] + 1);
	$sync = Server::get(FileSyncService::class)->sync($uid);
	$row = $fileMapper->findByFileId((int)$linkFile?->getFileId());
	$check('file sync keeps the manual link', $row->getCopyId() === $created['copyId'] && $sync['linkedManually'] >= 1, json_encode(['linkedManually' => $sync['linkedManually']]));
	$assistant->unlinkFile($row->getId(), $uid);
	Server::get(FileSyncService::class)->sync($uid);
	$check('after unlinking the name decides again', $fileMapper->findByFileId((int)$linkFile?->getFileId())->getCopyId() === $copyA->getId());
	$again = Server::get(FileSyncService::class)->sync($uid);
	$check('a sync without changes writes no row', $again['changed'] === 0 && $again['new'] === 0, json_encode(['changed' => $again['changed']]));
	$refused = Server::get(RunLock::class)->run(RunLock::FILE_SYNC, $uid,
		fn () => $throws(fn () => Server::get(FileSyncService::class)->sync($uid)));
	$check('a second file sync is refused while one runs', is_string($refused) && str_starts_with($refused, 'A file sync is already running.'), (string)$refused);
	$status = Server::get(FileSyncService::class)->status();
	$check('the status keeps the last sync and shows no run', $status['running'] === null && $status['last']['by'] === $uid);
	$assistant->setOpenBinders([$binder, $binder + 1], $uid);
	$check('a new binder starts at 1', $assistant->next($binder + 1)['proposal']['number'] === 1);
	$labels = $assistant->labels([$copyA->getId()]);
	$check('label data', $labels[0]['label'] === $copyA->getBinder() . ' / ' . $copyA->getNumber() && $labels[0]['title'] !== null);

	// Moving a copy to another version, with undo
	$versionA = $versions->find($copyA->getVersionId());
	$versionB = $versions->find($copyB->getVersionId());
	$rightsBefore = [$versionA->getId() => $versionA->getRightsStatus(), $versionB->getId() => $versionB->getRightsStatus()];
	$rightsNow = fn (): array => [$versionA->getId() => $versions->find($versionA->getId())->getRightsStatus(),
		$versionB->getId() => $versions->find($versionB->getId())->getRightsStatus()];
	$check('only librarians merge', $throws(fn () => $merges->moveCopy($copyB->getId(), $versionA->getId(), $nobody, null)) !== null);
	$move = $merges->moveCopy($copyB->getId(), $versionA->getId(), $uid, 'same print');
	$check('copy moved to the version', $copyMapper->find($copyB->getId())->getVersionId() === $versionA->getId()
		&& $catalog->entry($copyB->getId(), $uid)['version']->getId() === $versionA->getId());
	$check('former version kept without copies', $move['emptyVersionId'] === $versionB->getId());
	$check('moving does not change rights', $rightsNow() === $rightsBefore);
	$check('moving twice refused', $throws(fn () => $merges->moveCopy($copyB->getId(), $versionA->getId(), $uid, null)) !== null);
	$merges->undo($move['merge']->getId(), $uid, 'test');
	$check('move undone', $copyMapper->find($copyB->getId())->getVersionId() === $versionB->getId());
	$check('undo twice refused', $throws(fn () => $merges->undo($move['merge']->getId(), $uid, null)) !== null);

	// Merging works, with undo
	$workA = $versionA->getWorkId();
	$workB = $versionB->getWorkId();
	$candidates = $merges->candidates();
	$pair = array_filter($candidates, fn ($g) => count(array_intersect([$workA, $workB], array_column($g['works'], 'workId'))) === 2);
	$check('merge proposed for the same title and composer', $pair !== [], count($candidates) . ' groups');
	$check('merging into itself refused', $throws(fn () => $merges->mergeWorks($workA, $workA, $uid, null)) !== null);
	$merge = $merges->mergeWorks($workB, $workA, $uid, 'duplicate');
	$rowB = array_values(array_filter($catalog->entries(), fn ($r) => $r['id'] === $copyB->getId()))[0];
	$check('versions moved, copies stay visible under the target work', $versions->find($versionB->getId())->getWorkId() === $workA
		&& $rowB['work_id'] === $workA && !in_array($workB, array_column($catalog->entries(), 'work_id'), true));
	$check('merged work kept and marked', $works->find($workB)->getMergedInto() === $workA);
	$check('work number given', preg_match('/^W-\d{4,}$/', (string)$works->find($workA)->getWorkNo()) === 1, (string)$works->find($workA)->getWorkNo());
	$check('merging does not change rights', $rightsNow() === $rightsBefore);
	$check('merged work cannot be merged again', $throws(fn () => $merges->mergeWorks($workB, $workA, $uid, null)) !== null);
	$entryMerges = $catalog->entry($copyA->getId(), $uid)['merges'];
	$check('entry offers the undo', in_array($merge->getId(), array_column($entryMerges, 'id'), true));
	$entryMerge = array_values(array_filter($entryMerges, fn ($m) => $m['id'] === $merge->getId()))[0] ?? [];
	$check('entry merge has context and names', ($entryMerge['source']['title'] ?? null) !== null && ($entryMerge['created_by_name'] ?? '') !== '',
		json_encode(['source' => $entryMerge['source'] ?? null, 'by' => $entryMerge['created_by_name'] ?? null]));
	$merges->undo($merge->getId(), $uid, 'test');
	$check('merge undone', $versions->find($versionB->getId())->getWorkId() === $workB && $works->find($workB)->getMergedInto() === null);
	$check('merges are listed', count($merges->list(true)) >= 2 && $merges->list(false) === array_values(array_filter($merges->list(false), fn ($m) => $m['undone'] === null)));

	// Inventory
	$list = $inventory->list($copyA->getBinder(), false);
	$check('inventory list of the binder', in_array($copyA->getId(), array_column($list['rows'], 'copyId'), true) && $list['binders'] !== []);
	$check('count in the future refused', $throws(fn () => $inventory->recordCounts([['copyId' => $copyA->getId()]], '2099-01-01', $uid)) !== null);
	$inventory->recordCounts([['copyId' => $copyA->getId(), 'originals' => 2, 'copies' => 30]], null, $uid);
	$counted = $copyMapper->find($copyA->getId());
	$check('count recorded', $counted->getValue('last_counted') === $today && $counted->getValue('originals_count') === 2 && $counted->getValue('copies_count') === 30);

	// Checksums: first checksum, unchanged, silent change, accept, intended change
	$archive = $settings->archiveFolder($uid);
	$found = $fileMapper->findFound();
	usort($found, fn ($a, $b) => (int)$a->getSize() <=> (int)$b->getSize());
	$file = $found[0];
	$file->setSha256(null);
	$file = $fileMapper->update($file);
	$check('first checksum stored', $checksums->check($file, $archive) === 'stored' && strlen((string)$fileMapper->find($file->getId())->getSha256()) === 64);
	$check('unchanged file', $checksums->check($fileMapper->find($file->getId()), $archive) === 'unchanged');
	$file = $fileMapper->find($file->getId());
	$file->setSha256(str_repeat('0', 64));
	$file = $fileMapper->update($file);
	$state = $checksums->check($file, $archive);
	$file = $fileMapper->find($file->getId());
	$check('silent change detected and notified', $state === 'mismatch' && $file->getSha256Mismatch() === true && $file->getSha256() === str_repeat('0', 64)
		&& $notificationCount($uid, NotificationService::CHECKSUM_MISMATCH) === 1);
	$checksums->check($file, $archive);
	$check('notified once', $notificationCount($uid, NotificationService::CHECKSUM_MISMATCH) === 1);
	$check('summary lists the difference', in_array($file->getId(), array_column($checksums->summary()['mismatches'], 'id'), true));
	$check('only librarians accept', $throws(fn () => $checksums->accept($file->getId(), $nobody, null)) !== null);
	$checksums->accept($file->getId(), $uid, 'checked');
	$check('accepted: mark and notification gone', $fileMapper->find($file->getId())->getSha256Mismatch() === false
		&& $notificationCount($uid, NotificationService::CHECKSUM_MISMATCH) === 0);
	$check('new checksum after accepting', $checksums->check($fileMapper->find($file->getId()), $archive) === 'stored');
	$file = $fileMapper->find($file->getId());
	$file->setSha256(str_repeat('1', 64));
	$file->setMtime((int)$file->getMtime() - 10);
	$file = $fileMapper->update($file);
	$check('intended change is no difference', $checksums->check($file, $archive) === 'changed' && $fileMapper->find($file->getId())->getSha256Mismatch() === false);
	$runResult = $checksums->run(3);
	$check('background run checks a limited number', $runResult['checked'] >= 1 && $runResult['checked'] <= 3, json_encode($runResult));

	// Resubmission: protected, but protection ended by the life dates
	$catalog->update('version', $versionFree, ['rights_status' => 'protected'], $uid, 'test resubmission');
	$daily = Server::get(DailyChecks::class);
	$runJob($daily);
	$runJob($daily);
	$check('protection end notified once', $notificationCount($uid, NotificationService::PROTECTION_ENDED) === 1);
	$check('the job does not change the rights status', $versions->find($versionFree)->getRightsStatus() === 'protected');
	$catalog->update('version', $versionFree, ['rights_status' => 'public_domain'], $uid, 'test resubmission');
	$runJob($daily);
	$check('notification withdrawn after the decision', $notificationCount($uid, NotificationService::PROTECTION_ENDED) === 0);

	// Notifications are rendered
	$notifier = Server::get(Notifier::class);
	foreach ([NotificationService::PROTECTION_ENDED => ['shelfmark' => '2-35', 'title' => 'x', 'date' => '1928-01-01', 'copyId' => 1],
		NotificationService::CHECKSUM_MISMATCH => ['shelfmark' => '2-35', 'title' => 'x', 'type' => 'pdf', 'copyId' => 1]] as $subject => $params) {
		$n = Server::get(IManager::class)->createNotification()->setApp(Application::APP_ID)->setUser($uid)
			->setObject('version', '1')->setSubject($subject, $params)->setDateTime(new DateTime());
		$prepared = $notifier->prepare($n, 'de');
		$check('notification rendered: ' . $subject, $prepared->getParsedSubject() !== '' && str_contains($prepared->getLink(), 'copy=1'), $prepared->getParsedSubject());
	}

	// Backup contains the register of persons
	$ods = Server::get(ExportService::class)->ods();
	$tmp = tempnam(sys_get_temp_dir(), 'ods');
	file_put_contents($tmp, $ods);
	$zip = new ZipArchive();
	$content = $zip->open($tmp) === true ? (string)$zip->getFromName('content.xml') : '';
	$zip->close();
	unlink($tmp);
	$check('backup contains the persons', str_contains($content, 'table:name="Persons"') && str_contains($content, 'Zelter'));
} catch (\Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	foreach ($restore as $key => $value) {
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $key);
		} elseif (is_int($value)) {
			$appConfig->setValueInt(Application::APP_ID, $key, $value, true);
		} else {
			$appConfig->setValueArray(Application::APP_ID, $key, $value);
		}
	}
	$appConfig->deleteKey(Application::APP_ID, 'notified_protection_' . $versionFree);
	$folder = $settings->exportFolder($uid);
	foreach ($snapshots as $path) {
		$name = basename((string)$path);
		if ($name !== '' && !in_array($name, $exportBefore, true) && $folder->nodeExists($name)) {
			$folder->get($name)->delete();
		}
	}
}

IntegrationTest::finish();
