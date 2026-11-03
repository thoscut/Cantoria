<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the complete backup and restore against a real
 * Nextcloud instance with an imported catalog (and a file sync, so that the
 * file table is filled).
 *
 * Usage (as the web server user):
 *   php tests/Integration/backup_test.php <nextcloud-root> <uid>
 *
 * <uid> must hold the role "librarian". Inside one outer transaction the
 * test first adds at least one record to every table through the services
 * (license, proposal, task, link, concert with an item, person with a
 * contribution, transcription report with a review point, merge, a folder
 * of the folder book issued to a name, the revisions of the versions with a
 * MusicXML; the modules transcription_review, folder_book and version_states
 * are switched on inside the transaction if needed), so that the
 * round trip covers every table with content. It then exports the complete backup, empties all app tables,
 * restores the backup and compares every table row by row; then everything
 * is rolled back.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);

use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\MergeService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\StateService;
use OCA\Cantoria\Service\TaskService;
use OCP\IDBConnection;
use OCP\Server;

$check = IntegrationTest::check(...);
$dump = function (IDBConnection $db, string $table): array {
	$qb = $db->getQueryBuilder();
	$qb->select('*')->from($table)->orderBy('id');
	$rows = $qb->executeQuery()->fetchAllAssociative();
	// Compare values as strings: drivers return numbers and booleans differently.
	return array_map(fn (array $r): array => array_map(fn ($v) => $v === null ? null : (string)(is_bool($v) ? (int)$v : $v), $r), $rows);
};

$db = Server::get(IDBConnection::class);
$backup = Server::get(FullBackupService::class);
$tables = array_values(array_filter(FullBackupService::TABLES, fn (string $t): bool => $db->tableExists($t)));

$db->beginTransaction();
try {
	// At least one record per table, created the way the app creates them.
	$copies = Server::get(CopyMapper::class)->findAll();
	$first = $copies[0] ?? null;
	$other = null;
	foreach ($copies as $copy) {
		if ($first !== null && $copy->getVersionId() !== $first->getVersionId()) {
			$other = $copy;
			break;
		}
	}
	$check('catalog has copies of two versions', $first !== null && $other !== null, count($copies) . ' copies');
	if ($first !== null && $other !== null) {
		$versionId = $first->getVersionId();
		$today = Server::get(RightsService::class)->today($uid);
		Server::get(LicenseService::class)->create($versionId, ['holder' => 'Backup test', 'uses' => ['binder', 'practice_audio'], 'quantity' => 0,
			'valid_from' => $today, 'evidence' => 'invoice 0'], $uid);
		Server::get(ProposalService::class)->propose('version', $versionId, ['rule1_count' => 0, 'scoring' => 'TTBB'], $uid, 'backup test', 'round 0');
		Server::get(TaskService::class)->create($first->getId(), ['addressee' => 'librarian', 'question' => 'Backup test: is page 2 missing?', 'page' => 2], $uid);
		Server::get(LinkService::class)->create($first->getId(), ['measure_from' => '1', 'measure_to' => '8', 'voice' => 'Tenor 1', 'note' => 'backup test'], $uid);
		$events = Server::get(EventService::class);
		$event = $events->create(Event::CONCERT, ['title' => 'Backup test concert', 'event_date' => $today, 'location' => 'Test hall', 'is_public' => true], $uid);
		$events->addItem($event->getId(), $first->getId(), $uid);
		$persons = Server::get(PersonService::class);
		$person = $persons->create(['name' => 'Backup Test Person', 'born' => '1850', 'died' => '1910', 'identification' => 'probable'], $uid);
		$persons->addContribution($versionId, $person->getId(), 'arranger', true, $uid);
		// A transcription report with one review point (module transcription_review)
		$check('the review of transcriptions is active', Server::get(Modules::class)->isEnabled('transcription_review'));
		Server::get(ReviewService::class)->ingest($versionId, json_encode([
			'format' => 'bericht/1', 'signatur' => $first->getShelfmark(), 'basisname' => $first->getShelfmark() . '_Backup-test', 'weg' => 'neuaufbau',
			'vorlage' => ['typ' => 'druck', 'beschreibung' => 'Backup test', 'seiten' => 1], 'umfang' => ['takte' => 1, 'auftakt' => false],
			'gegenprobe' => ['audiveris' => 'keine', 'ersatz' => []], 'geprueft' => new stdClass(), 'vortragszeichen' => ['ausgelassen' => []],
			'unsicher' => [['takt' => '1', 'stimme' => 'alle', 'was' => 'Backup test: first measure uncertain']],
			'rechte' => ['einstufung' => 'offen', 'beteiligte' => []], 'wv' => ['herkunft' => 'Backup test', 'felder' => new stdClass()],
			'lernen' => [], 'scores' => '| backup test |',
		], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $uid);
		// A folder of the folder book, issued to a name (module folder_book)
		// The folder book starts off in "standard"; switched on inside the transaction
		$folderBookModules = Server::get(Modules::class);
		if (!$folderBookModules->isEnabled(Modules::FOLDER_BOOK)) {
			$folderBookModules->change([Modules::FOLDER_BOOK => true]);
		}
		$check('the folder book is active', $folderBookModules->isEnabled(Modules::FOLDER_BOOK));
		$folderBook = Server::get(FolderBookService::class);
		$folder = $folderBook->createFolder(['label' => 'Backup test folder', 'voice' => 'Tenor 1',
			'contents' => [['copyId' => $first->getId(), 'count' => 2]]], $uid);
		$folderBook->issue($folder['id'], ['holderName' => 'Backup Test Person', 'note' => 'backup test'], $uid);
		Server::get(MergeService::class)->moveCopy($other->getId(), $versionId, $uid, 'backup test');
		// A revision of a version (module version_states, milestone 0.8): the
		// file sync records the first ones; switched on inside the transaction
		if ($dump($db, 'cantoria_states') === []) {
			if (!$folderBookModules->isEnabled(Modules::VERSION_STATES)) {
				$folderBookModules->change([Modules::VERSION_STATES => true]);
			}
			Server::get(StateService::class)->recordSync([], time(), $uid);
		}
		$check('a revision of a version exists', $dump($db, 'cantoria_states') !== []);
	}

	$before = [];
	$empty = [];
	foreach ($tables as $table) {
		$before[$table] = $dump($db, $table);
		if ($before[$table] === []) {
			$empty[] = $table;
		}
	}
	$check('every table has records before the export', $empty === [], $empty === [] ? count($tables) . ' tables' : 'empty: ' . implode(', ', $empty));

	$json = $backup->export();
	$parsed = FullBackupService::parse($json);
	$check('backup passes its own format check with all tables', count($parsed['tables']) === count($tables), count($tables) . ' tables, ' . strlen($json) . ' bytes');
	$check('the backup holds no link tokens', ($parsed['tables']['cantoria_links'] ?? []) !== []
		&& array_filter(array_column($parsed['tables']['cantoria_links'], 'token'), fn ($t): bool => $t !== '') === []);
	$snapshot = FullBackupService::parse((string)stream_get_contents($backup->exportStream(true)));
	$check('an annual snapshot leaves out the folder book, the audit log and the links',
		array_intersect(array_keys($snapshot['tables']), ['cantoria_folder_loans', 'cantoria_audit', 'cantoria_links']) === []
		&& isset($snapshot['tables']['cantoria_copies']));

	try {
		$backup->restore($json, $uid);
		$check('restore into a non-empty catalog is refused', false);
	} catch (UserFacingException) {
		$check('restore into a non-empty catalog is refused', true);
	}
	try {
		$backup->restore('{"format":"something else"}', $uid);
		$check('foreign file is refused', false);
	} catch (UserFacingException) {
		$check('foreign file is refused', true);
	}

	$emptyAll = function () use ($db, $tables): void {
		foreach (array_reverse($tables) as $table) {
			$db->getQueryBuilder()->delete($table)->executeStatement();
		}
	};
	$rowCount = function (string $table) use ($db): int {
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		return (int)$qb->executeQuery()->fetchOne();
	};
	$emptyAll();

	// A value that does not fit its column is refused and nothing is written.
	$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	$data['tables']['works'][0]['title'] = str_repeat('x', 501);
	$error = IntegrationTest::throws(fn () => $backup->restore(json_encode($data, JSON_THROW_ON_ERROR), $uid));
	$check('a value longer than its column is refused', $error !== null && str_contains($error, 'invalid value in cantoria_works'), (string)$error);
	$check('a refused restore writes nothing', $rowCount('cantoria_works') === 0 && $rowCount('cantoria_audit') === 0);
	$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	$data['tables']['versions'][0]['preservation_copy'] = 'yes please';
	$error = IntegrationTest::throws(fn () => $backup->restore(json_encode($data, JSON_THROW_ON_ERROR), $uid));
	$check('a boolean column refuses text', $error !== null && str_contains($error, 'preservation_copy'), (string)$error);

	// Column names from the file are never used unchecked: unknown ones are left out.
	$crafted = ['format' => FullBackupService::FORMAT, 'formatVersion' => FullBackupService::FORMAT_VERSION, 'tables' => [
		'works' => [['id' => 1, 'title' => 'Crafted', 'title"; DROP TABLE oc_users; --' => 'x', 'created' => '0', 'modified' => 0]],
	]];
	$restored = $backup->restore(json_encode($crafted, JSON_THROW_ON_ERROR), $uid);
	$check('unknown columns are left out', ($restored['cantoria_works'] ?? 0) === 1 && $rowCount('cantoria_works') === 1);
	$emptyAll();

	$restored = $backup->restore($json, $uid);
	$check('restore reports every table', count($restored) === count($tables));

	foreach ($tables as $table) {
		$after = $dump($db, $table);
		if ($table === 'cantoria_audit') {
			// The restore itself adds one audit entry.
			$check('audit log restored plus one entry', count($after) === count($before[$table]) + 1
				&& array_slice($after, 0, count($before[$table])) === $before[$table]);
			continue;
		}
		if ($table === 'cantoria_links') {
			// Without their tokens, links come back revoked and with new tokens.
			$strip = fn (array $rows): array => array_map(fn (array $row): array => array_diff_key($row, ['token' => 0, 'token_secret' => 0, 'revoked' => 0]), $rows);
			$check('links restored, revoked and with new tokens', $strip($after) === $strip($before[$table])
				&& array_filter($after, fn (array $row): bool => $row['revoked'] === null) === []
				&& array_intersect(array_column($after, 'token'), array_column($before[$table], 'token')) === []);
			continue;
		}
		$check('table ' . $table . ' identical (' . count($after) . ' rows)', $after === $before[$table]);
	}
} finally {
	$db->rollBack();
}

IntegrationTest::finish();
