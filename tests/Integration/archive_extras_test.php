<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of milestone 0.8, archive, against a real Nextcloud
 * instance with an imported catalog and a file sync:
 * - the special cases of the protection term (§§ 70, 71 UrhG) in the
 *   proposal, with the missing year and the check of the year,
 * - the search of Nextcloud (module "unified_search"): shelf mark, title and
 *   person, preservation copies, accounts without a role, module off,
 * - the report of gaps in the shelf marks against the next free shelf mark,
 * - the rights notice of a MusicXML file (information, never a status),
 * - two annual snapshots and their comparison,
 * - hired material: lender and return date of a copy, and the count of copies due back.
 *
 * Usage (as the web server user):
 *   php tests/Integration/archive_extras_test.php <nextcloud-root> <uid>
 *
 * <uid> must hold the role "librarian" and see the archive folder, which it
 * may write to; the catalog needs a copy with a MusicXML file (the demo data
 * have several). It runs on any catalog: no fixed titles or figures.
 * Database changes are rolled back, the module switches restored exactly,
 * and the snapshot folders the test writes are deleted again.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Search\CatalogSearchProvider;
use OCA\Cantoria\Service\AnnualSnapshotService;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogSearch;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\CopyAssistantService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\InventoryService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\RightsNoticeService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Search\ISearchQuery;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$catalog = Server::get(CatalogService::class);
$persons = Server::get(PersonService::class);
$copies = Server::get(CopyMapper::class);
$versions = Server::get(WorkVersionMapper::class);
$works = Server::get(WorkMapper::class);
$archiveFiles = Server::get(ArchiveFileMapper::class);
$snapshots = Server::get(AnnualSnapshotService::class);
$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get($uid);
$year = (int)substr(Server::get(RightsService::class)->today($uid), 0, 4);

$check('test account exists', $user !== null);
$check('test account is a catalog librarian', Server::get(Roles::class)->hasAnyRole($uid, [Roles::LIBRARIAN]));

/** The module switches this test changes: key => stored value, or null when not stored. */
$switchKeys = [Modules::UNIFIED_SEARCH, Modules::ANNUAL_SNAPSHOT, 'persons', 'protection_terms', 'inventory', 'rights', 'proposals'];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null)
			? $appConfig->getValueBool(Application::APP_ID, $configKey)
			: null;
	}
	return $values;
};
$original = $switches();
$autoBefore = $appConfig->hasKey(Application::APP_ID, AnnualSnapshotService::AUTO_KEY, null)
	? $appConfig->getValueBool(Application::APP_ID, AnnualSnapshotService::AUTO_KEY) : null;

// Snapshots lie in the backup folder, outside the archive folder (0.10).
$archive = Server::get(AppSettings::class)->archiveFolder($uid);
$backups = Server::get(AppSettings::class)->backupFolder();
$snapshotPath = AnnualSnapshotService::FOLDER;
$hadSnapshotFolder = $backups->nodeExists($snapshotPath);
/** @var list<string> $written names of the snapshot folders written by the test */
$written = [];

$db->beginTransaction();
try {
	if ($user === null) {
		throw new RuntimeException('test account missing');
	}
	$modules->change(array_fill_keys($switchKeys, true));
	$session->setUser($user);

	// A copy with a MusicXML file: its version is used throughout.
	$musicxml = null;
	foreach ($archiveFiles->findFound() as $file) {
		if ($file->getCopyId() !== null && $file->getRole() === 'musicxml') {
			$musicxml = $file;
			break;
		}
	}
	$check('the catalog has a copy with a MusicXML file', $musicxml !== null);
	if ($musicxml === null) {
		throw new RuntimeException('no MusicXML file of a copy; run the file sync first');
	}
	$copy = $copies->find((int)$musicxml->getCopyId());
	$version = $versions->find($copy->getVersionId());
	$work = $works->find($version->getWorkId());
	$statusBefore = $version->getRightsStatus();

	// ---- special cases of the protection term ----
	$composer = $persons->create(['name' => 'Integration Test Composer', 'died' => '1750', 'died_precision' => 'year', 'identification' => 'certain'], $uid);
	$editor = $persons->create(['name' => 'Integration Test Editor', 'died_precision' => 'living', 'identification' => 'certain'], $uid);
	foreach ($persons->forVersion($version->getId()) as $existing) {
		$persons->removeContribution((int)$existing['id'], $uid);
	}
	$persons->addContribution($version->getId(), $composer->getId(), 'composer', false, $uid);
	$persons->addContribution($version->getId(), $editor->getId(), 'editor', false, $uid);
	$proposal = $persons->protection($version->getId(), $year);
	$check('without a special case the living editor blocks', $proposal['status'] === ProtectionTerm::UNKNOWN && $proposal['reason'] === 'living',
		json_encode($proposal) ?: '');

	$catalog->update('version', $version->getId(), ['scientific_edition' => true], $uid, 'integration test');
	$proposal = $persons->protection($version->getId(), $year);
	$check('a special case without the year counts as protected', $proposal['status'] === ProtectionTerm::UNKNOWN
		&& in_array('year_missing', array_column($proposal['blocking'], 'reason'), true), json_encode($proposal) ?: '');

	$published = $year - 5;
	$catalog->update('version', $version->getId(), ['first_published_year' => $published], $uid, 'integration test');
	$proposal = $persons->protection($version->getId(), $year);
	$check('a scientific edition is protected 25 years after it appeared', $proposal['status'] === ProtectionTerm::PROTECTED
		&& $proposal['until'] === sprintf('%04d-12-31', $published + 25) && $proposal['reason'] === 'scientific_edition_running',
		json_encode($proposal) ?: '');
	$check('the editor counts with the special term', ($proposal['special'][0]['case'] ?? '') === ProtectionTerm::SCIENTIFIC_EDITION);

	$catalog->update('version', $version->getId(), ['scientific_edition' => false, 'posthumous_work' => true, 'first_published_year' => 1900, 'text_origin' => 'none'], $uid,
		'integration test');
	$proposal = $persons->protection($version->getId(), $year);
	$check('a posthumous work first published long ago is free', $proposal['status'] === ProtectionTerm::FREE
		&& $proposal['reason'] === 'posthumous_work_ended', json_encode($proposal) ?: '');
	$check('the year must not lie in the future',
		$throws(fn () => $catalog->update('version', $version->getId(), ['first_published_year' => $year + 1], $uid, null)) !== null);
	$check('the year needs four digits', $throws(fn () => $catalog->update('version', $version->getId(), ['first_published_year' => 99], $uid, null)) !== null);
	$review = array_filter($persons->review($uid), fn (array $row): bool => $row['versionId'] === $version->getId());
	$check('the resubmission list uses the special case', $statusBefore !== 'protected' || $review !== []);
	$check('no rights status was set', $versions->find($version->getId())->getRightsStatus() === $statusBefore);

	// ---- search of Nextcloud ----
	$provider = Server::get(CatalogSearchProvider::class);
	$query = function (string $term, int $limit = 20) {
		return new class($term, $limit) implements ISearchQuery {
			public function __construct(
				private string $term,
				private int $limit,
			) {
			}
			public function getTerm(): string {
				return $this->term;
			}
			public function getFilter(string $name): ?\OCP\Search\IFilter {
				return null;
			}
			public function getFilters(): \OCP\Search\IFilterCollection {
				throw new RuntimeException('not used');
			}
			public function getSortOrder(): int {
				return ISearchQuery::SORT_DATE_DESC;
			}
			public function getLimit(): int {
				return $this->limit;
			}
			public function getCursor() {
				return null;
			}
			public function getRoute(): string {
				return '';
			}
			public function getRouteParameters(): array {
				return [];
			}
		};
	};
	$entries = fn (string $term): array => json_decode((string)json_encode($provider->search($user, $query($term))), true)['entries'] ?? [];
	$byShelfmark = $entries($copy->getShelfmark());
	$check('the shelf mark finds the copy', ($byShelfmark[0]['resourceUrl'] ?? '') !== ''
		&& str_ends_with($byShelfmark[0]['resourceUrl'], '?copy=' . $copy->getId()), json_encode($byShelfmark) ?: '');
	$word = '';
	foreach (preg_split('/\s+/u', (string)$work->getTitle()) ?: [] as $candidate) {
		if (mb_strlen($candidate) > mb_strlen($word)) {
			$word = $candidate;
		}
	}
	$check('a word of the title finds the copy', $word !== '' && array_filter($entries($word),
		fn (array $e): bool => str_ends_with($e['resourceUrl'], '?copy=' . $copy->getId())) !== [], $word);
	$check('a person of the register finds the copy', array_filter($entries('Integration Test Composer'),
		fn (array $e): bool => str_ends_with($e['resourceUrl'], '?copy=' . $copy->getId())) !== []);
	$search = Server::get(CatalogSearch::class);
	$preservation = null;
	foreach ($copies->findAll() as $candidate) {
		if ($versions->find($candidate->getVersionId())->getPreservationCopy() === true) {
			$preservation = $candidate;
			break;
		}
	}
	if ($preservation !== null) {
		$check('a preservation copy is found by those who may view it', $search->find($preservation->getShelfmark(), true) !== []);
		$check('and by nobody else', $search->find($preservation->getShelfmark(), false) === []);
	}
	$check('an account without a role finds nothing', !$provider->maySearch('cantoria-no-such-account'));
	$check('the provider is listed for the account', $provider->getOrder('files.view.index', []) !== null);
	$modules->change([Modules::UNIFIED_SEARCH => false]);
	$check('switched off, the provider is hidden', $provider->getOrder('files.view.index', []) === null);
	$check('switched off, nothing is found', $entries($copy->getShelfmark()) === []);
	$modules->change([Modules::UNIFIED_SEARCH => true]);

	// ---- gaps in the shelf marks ----
	$gaps = Server::get(InventoryService::class)->gaps();
	$numbers = [];
	foreach ($copies->findAll() as $candidate) {
		$numbers[$candidate->getBinder()][] = $candidate->getNumber();
	}
	$consistent = true;
	foreach ($gaps as $binder) {
		$own = $numbers[$binder['binder']] ?? [];
		foreach ($binder['ranges'] as $range) {
			for ($n = $range['from']; $n <= $range['to']; $n++) {
				$consistent = $consistent && !in_array($n, $own, true);
			}
		}
		$consistent = $consistent && $binder['highest'] >= ($own === [] ? 0 : max($own))
			&& $binder['highest'] === $binder['missing'] + count(array_unique($own));
	}
	$check('the gaps are exactly the numbers without a copy', $gaps !== [] && $consistent, json_encode($gaps) ?: '');
	$next = Server::get(CopyAssistantService::class)->next();
	$ofBinder = array_values(array_filter($gaps, fn (array $g): bool => $g['binder'] === $next['proposal']['binder']));
	$check('a new accession gets the number after the highest, never a gap',
		$next['proposal']['number'] === ($ofBinder[0]['highest'] ?? 0) + 1, json_encode($next['proposal']) ?: '');

	// ---- rights notice of a MusicXML file ----
	$node = $archive->getFirstNodeById($musicxml->getFileId());
	$check('the MusicXML file is readable', $node instanceof File);
	if ($node instanceof File) {
		$notices = Server::get(RightsNoticeService::class);
		$musicxml->setRightsReadAt(null);
		$counts = $notices->readAll([['file' => $musicxml, 'node' => $node, 'version' => $versions->find($version->getId())]], $uid);
		$stored = $archiveFiles->find($musicxml->getId());
		$check('the notice is read and stored with the file', $counts['read'] === 1 && $stored->getRightsReadAt() !== null,
			json_encode($counts) . ' ' . var_export($stored->getRightsNotice(), true));
		$check('a notice never sets the rights status', $versions->find($version->getId())->getRightsStatus() === $statusBefore);
		$entry = $catalog->entry($copy->getId(), $uid);
		$shown = array_filter($entry['files'], fn ($f): bool => $f->getId() === $musicxml->getId());
		$check('the sidebar gets the notice with the file', $shown !== [] && reset($shown)->getRightsNotice() === $stored->getRightsNotice());
	}

	// ---- annual snapshots ----
	$overview = $snapshots->overview($uid);
	$check('the overview says where and what is written', $overview['next']['year'] === $year
		&& str_ends_with($overview['next']['folder'], $snapshotPath) && $overview['next']['files'] === AnnualSnapshotService::FILES,
		json_encode($overview['next']) ?: '');
	$first = $snapshots->createManually($uid);
	$written[] = $first['name'];
	$check('a snapshot is written', str_starts_with($first['name'], $year . '_') && $first['manifest']['files'] > 0, json_encode($first) ?: '');
	$folder = $backups->get($snapshotPath . '/' . $first['name']);
	$check('it holds the catalog as CSV and JSON and the manifest', $folder instanceof Folder
		&& $folder->nodeExists(AnnualSnapshotService::CATALOG_CSV) && $folder->nodeExists(AnnualSnapshotService::CATALOG_JSON)
		&& $folder->nodeExists(AnnualSnapshotService::MANIFEST));
	if ($folder instanceof Folder) {
		$manifest = $folder->get(AnnualSnapshotService::MANIFEST);
		$entriesOfManifest = $manifest instanceof File ? AnnualSnapshotService::parseManifest($manifest->getContent()) : [];
		$fileEntry = $entriesOfManifest[$musicxml->getPath()] ?? null;
		$check('the manifest has the SHA-256 and size of an archive file', $node instanceof File && $fileEntry !== null
			&& $fileEntry['sha256'] === hash('sha256', $node->getContent()) && $fileEntry['size'] === (int)$node->getSize());
		$json = $folder->get(AnnualSnapshotService::CATALOG_JSON);
		$tablesOfSnapshot = $json instanceof File ? array_keys(FullBackupService::parse($json->getContent())['tables']) : [];
		$check('the snapshot holds no folder book, audit log or links', $tablesOfSnapshot !== []
			&& array_intersect($tablesOfSnapshot, ['cantoria_folder_loans', 'cantoria_audit', 'cantoria_links', 'cantoria_tasks']) === [],
			implode(', ', $tablesOfSnapshot));
		$csv = $folder->get(AnnualSnapshotService::CATALOG_CSV);
		$check('the catalog of the snapshot names no accounts', $csv instanceof File && !str_contains(strtok($csv->getContent(), "\n") ?: '', 'confirmed_by'));
	}
	$check('the snapshot lies outside the archive folder', !str_starts_with($backups->getPath() . '/', rtrim($archive->getPath(), '/') . '/'));

	// Changes between the snapshots
	$kindBefore = $copy->getKind();
	$newKind = $kindBefore === 'photocopy' ? 'original' : 'photocopy';
	$catalog->update('copy', $copy->getId(), ['kind' => $newKind], $uid, 'integration test');
	$newStatus = $statusBefore === 'open' ? 'protected' : 'open';
	$catalog->update('version', $version->getId(), ['rights_status' => $newStatus], $uid, 'integration test');
	Server::get(LicenseService::class)->create($version->getId(), ['holder' => 'Integration test', 'uses' => ['successor']], $uid);

	$second = $snapshots->createManually($uid);
	$written[] = $second['name'];
	$check('a second snapshot of the same year is added, never overwritten', $second['name'] !== $first['name']);
	$comparison = $snapshots->compare($uid, $first['name'], $second['name']);
	$rights = array_values(array_filter($comparison['rights'], fn (array $r): bool => str_contains($r['shelfmark'], $copy->getShelfmark())));
	$check('the comparison shows the changed rights status', $rights !== []
		&& in_array(['field' => 'rights_status', 'old' => $statusBefore, 'new' => $newStatus], $rights[0]['changes'], true),
		json_encode($comparison['rights']) ?: '');
	$licenses = array_filter($comparison['licenses'], fn (array $l): bool => $l['change'] === 'added' && $l['uses'] === 'successor');
	$check('the comparison shows the new license with the use "transfer to a legal successor"', $licenses !== []);
	$changedCopy = array_values(array_filter($comparison['copies']['changed'], fn (array $c): bool => $c['shelfmark'] === $copy->getShelfmark()));
	$check('the comparison shows the changed copy', $changedCopy !== []
		&& in_array(['field' => 'kind', 'old' => $kindBefore, 'new' => $newKind], $changedCopy[0]['changes'], true),
		json_encode($changedCopy) ?: '');
	$check('no archive file changed between the snapshots', $comparison['files']['compared'] && $comparison['files']['changed'] === []
		&& $comparison['files']['added'] === [] && $comparison['files']['removed'] === [], json_encode($comparison['files']) ?: '');
	$listed = array_column($snapshots->overview($uid)['snapshots'], 'complete', 'name');
	$check('both snapshots are listed as complete', ($listed[$first['name']] ?? false) && ($listed[$second['name']] ?? false));
	$check('the same snapshot cannot be compared with itself', $throws(fn () => $snapshots->compare($uid, $first['name'], $first['name'])) !== null);
	$check('an unknown snapshot is refused', $throws(fn () => $snapshots->compare($uid, '1999_19990101-000000', $second['name'])) !== null);
	$check('a reader without the librarian role cannot make one',
		$throws(fn () => $snapshots->createManually('cantoria-no-such-account')) !== null);

	// ---- hired material (round 3, November 2026): the columns of the migration and the count of the start page
	$allCopies = $copies->findAll();
	$hiredBefore = $copies->countReturnDue('2999-12-31');
	$hired = $allCopies[0];
	$hired->setHiredFrom('Verlag Beispiel (Leihmaterial)');
	$hired->setReturnDate('2000-01-15');
	$copies->update($hired);
	$check('a hired copy keeps lender and return date', $copies->find($hired->getId())->getReturnDate() === '2000-01-15'
		&& $copies->find($hired->getId())->getHiredFrom() === 'Verlag Beispiel (Leihmaterial)');
	$check('the database counts it as due back', $copies->countReturnDue('2000-01-15') >= 1
		&& $copies->countReturnDue('2999-12-31') === $hiredBefore + ($hired->getStatus() === 'retired' ? 0 : 1));
	$check('nothing is due back before its day', $copies->countReturnDue('2000-01-14') === 0 || $hiredBefore > 0);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$session->setUser(null);
	// Exactly the switches of before (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($original as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	if ($autoBefore === null) {
		$appConfig->deleteKey(Application::APP_ID, AnnualSnapshotService::AUTO_KEY);
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $original);
	// Delete by name: the rollback also removed the new folders from the file cache.
	foreach ($written as $name) {
		if ($backups->nodeExists($snapshotPath . '/' . $name)) {
			$backups->get($snapshotPath . '/' . $name)->delete();
		}
	}
	if (!$hadSnapshotFolder && $backups->nodeExists($snapshotPath)) {
		$backups->get($snapshotPath)->delete();
	}
	$check('the snapshot folders are deleted again', array_filter($written, fn (string $name): bool => $backups->nodeExists($snapshotPath . '/' . $name)) === []);
}

IntegrationTest::finish();
