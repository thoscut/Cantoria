<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the mirror folder for the print station (module
 * "print_mirror") against a real Nextcloud instance with an imported
 * catalog and a file sync:
 * - the archive folder and folders inside it are refused as target folder,
 * - the preview writes nothing, and the daily sync waits for the first sync
 *   by hand,
 * - a released scan is copied (same content, name "<shelf mark> <title>.pdf",
 *   LIESMICH.txt), a second sync changes nothing, a changed scan is replaced,
 *   a scan no longer released is removed,
 * - only files the app wrote are replaced or removed: a foreign file, a
 *   foreign file with the name of a scan and a file changed by someone stay
 *   as they are,
 * - every write and removal is in the audit log; nothing is written into the
 *   archive folder,
 * - the routes answer 404 while the module is off.
 *
 * Usage (as the web server user):
 *   php tests/Integration/print_mirror_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <uid> must hold the role "librarian" and see the archive folder (it is
 * made an administrator inside the transaction, to choose the target
 * folder). <shelfmark> is an active copy with a PDF scan whose version is
 * no internal preservation copy and has no other valid version; the test
 * confirms it as public domain if it is not released for the print
 * station. The test creates a target folder in the files of <uid> and
 * deletes it with everything written at the end; database changes are
 * rolled back, the module switches are exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\PrintMirrorController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PrintMirrorService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$mirror = Server::get(PrintMirrorService::class);
$manifest = Server::get(MirrorFileMapper::class);
$auditEntries = Server::get(AuditEntryMapper::class);
$userFolder = Server::get(IRootFolder::class)->getUserFolder($uid);
$archive = Server::get(AppSettings::class)->archiveFolder($uid);

const INSIDE = 'The target folder must be neither the archive folder nor inside it. Please choose a folder outside the archive folder, for example one shared with the print station.';

$switchKeys = [Modules::PRINT_MIRROR, 'print_station', 'releases', 'rights'];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null) ? $appConfig->getValueBool(Application::APP_ID, $configKey) : null;
	}
	return $values;
};
/** The stored settings, state and report of the mirror folder. */
$settings = function () use ($appConfig): array {
	$values = $appConfig->getAllValues(Application::APP_ID, 'print_mirror_');
	ksort($values);
	return $values;
};
$originalSwitches = $switches();
$originalSettings = $settings();
// sorted: the order of a directory listing is not fixed (PostgreSQL returns it in any order)
$archiveListing = function () use ($archive): array {
	$listed = array_map(fn ($node): string => $node->getName(), $archive->getDirectoryListing());
	sort($listed);
	return $listed;
};
$archiveBefore = $archiveListing();
$targetName = 'Cantoria print station test ' . bin2hex(random_bytes(3));
$sha = fn (File $file): string => hash('sha256', $file->getContent());
$names = fn (array $entries): array => array_map(fn (array $entry): string => $entry['name'], $entries);
$reasons = fn (array $report, string $name): array => array_values(array_map(fn (array $e): string => (string)$e['reason'],
	array_filter($report['skipped'], fn (array $e): bool => $e['name'] === $name)));

$db->beginTransaction();
try {
	$modules->change([Modules::PRINT_MIRROR => true]);
	$user = Server::get(IUserManager::class)->get($uid);
	$adminGroup = Server::get(IGroupManager::class)->get('admin');
	if ($user !== null && $adminGroup !== null && !$adminGroup->inGroup($user)) {
		// Only inside this transaction: the target folder is chosen by administrators.
		$adminGroup->addUser($user);
	}
	$check('the account may choose the target folder', Server::get(Roles::class)->isAdmin($uid));

	// ---- the copy and its scan -----------------------------------------------------------
	$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark] ?? null;
	$scanRow = null;
	foreach (Server::get(ArchiveFileMapper::class)->findFound() as $file) {
		if ($copy !== null && $file->getCopyId() === $copy->getId() && $file->getRole() === 'score_pdf') {
			$scanRow = $scanRow ?? $file;
		}
	}
	$scan = $scanRow === null ? null : $userFolder->getFirstNodeById($scanRow->getFileId());
	$check('the copy has a PDF scan the account can read', $copy !== null && $scan instanceof File, $shelfmark);
	if ($copy === null || !$scan instanceof File || $scanRow === null) {
		throw new RuntimeException('no readable PDF scan for ' . $shelfmark);
	}
	$version = Server::get(WorkVersionMapper::class)->find($copy->getVersionId());
	$title = Server::get(WorkMapper::class)->find($version->getWorkId())->getTitle();
	$expected = PrintMirrorService::fileBase($shelfmark, $title) . '.pdf';
	$rights = Server::get(RightsService::class);
	$releases = Server::get(ReleaseListService::class);
	$onList = fn (): bool => in_array($version->getId(), array_column($releases->printStation($uid), 'versionId'), true);
	if (!$onList()) {
		$rights->confirm($version->getId(), $uid, 'public_domain', 'print station test', 'test');
	}
	$check('the version is on the list of the print station', $onList(), $shelfmark);

	// ---- the target folder ----------------------------------------------------------------
	$check('the archive folder is refused as target folder', $throws(fn () => $mirror->checkTargetFolder($uid, $archive->getId())) === INSIDE);
	$inside = null;
	foreach ($archive->getDirectoryListing() as $node) {
		$inside ??= $node instanceof Folder ? $node : null;
	}
	$check('the archive has a sub folder', $inside !== null);
	if ($inside !== null) {
		$check('a folder inside the archive folder is refused', $throws(fn () => $mirror->setTargetFolder($uid, $inside->getId())) === INSIDE);
	}
	$check('nothing was saved', $settings() === $originalSettings);

	$target = $userFolder->newFolder($targetName);
	$target->newFile('Notes of the print station.txt', 'foreign file');
	$saved = $mirror->setTargetFolder($uid, $target->getId());
	$check('a folder outside the archive is saved', $saved['id'] === $target->getId() && $saved['user'] === $uid, json_encode($saved) ?: '');

	// ---- preview, then the first sync by hand -------------------------------------------------
	$preview = $mirror->sync($uid, true);
	$check('the preview lists the scan and the note', in_array($expected, $names($preview['copied']), true)
		&& in_array(PrintMirrorService::README, $names($preview['copied']), true), json_encode($names($preview['copied'])) ?: '');
	$check('the preview writes nothing', count($target->getDirectoryListing()) === 1 && $manifest->findAllByName('print') === []);
	$mirror->runDaily();
	$check('the daily sync waits for the first sync by hand', count($target->getDirectoryListing()) === 1);

	$report = $mirror->sync($uid, false);
	$check('the scan is copied', in_array($expected, $names($report['copied']), true) && $target->nodeExists($expected),
		json_encode($report) ?: '');
	$copied = $target->nodeExists($expected) ? $target->get($expected) : null;
	$check('with the content of the scan', $copied instanceof File && $sha($copied) === $sha($scan));
	$check('LIESMICH.txt is written', $target->nodeExists(PrintMirrorService::README));
	$check('the foreign file stays', $target->nodeExists('Notes of the print station.txt')
		&& $target->get('Notes of the print station.txt') instanceof File);
	$check('the manifest knows the file', ($manifest->findAllByName('print')[$expected] ?? null)?->getSha256() === $sha($scan));
	$copiedEntries = array_filter($auditEntries->findLatest(200), fn ($e): bool => $e->getObjectType() === PrintMirrorService::OBJECT_TYPE
		&& $e->getAction() === 'mirror_copied' && $e->getNewValue() === $expected);
	$check('the copy is in the audit log', $copiedEntries !== []);
	$check('the sync by hand starts the daily sync', $mirror->status($uid)['started'] === true);

	$again = $mirror->sync($uid, false);
	$check('a second sync changes nothing', $again['copied'] === [] && $again['replaced'] === [] && $again['removed'] === []
		&& $again['unchanged'] >= 1, json_encode($again) ?: '');

	// ---- a changed scan is replaced ----------------------------------------------------------
	$row = $manifest->findAllByName('print')[$expected];
	$row->setSourceEtag('changed-' . bin2hex(random_bytes(4)));
	$manifest->update($row);
	$replaced = $mirror->sync($uid, false);
	$check('a changed scan is replaced', $names($replaced['replaced']) === [$expected, PrintMirrorService::README], json_encode($replaced) ?: '');

	// ---- no longer released: removed ---------------------------------------------------------
	$rights->setOpen($version->getId(), $uid, 'print station test');
	$check('the version is no longer on the list', !$onList());
	$removed = $mirror->sync($uid, false);
	$check('the scan is removed', in_array($expected, $names($removed['removed']), true) && !$target->nodeExists($expected),
		json_encode($removed) ?: '');
	$check('the foreign file still stays', $target->nodeExists('Notes of the print station.txt'));
	$removedEntries = array_filter($auditEntries->findLatest(200), fn ($e): bool => $e->getObjectType() === PrintMirrorService::OBJECT_TYPE
		&& $e->getAction() === 'mirror_removed' && $e->getOldValue() === $expected);
	$check('the removal is in the audit log', $removedEntries !== []);

	// ---- a file changed by someone stays --------------------------------------------------------
	$rights->confirm($version->getId(), $uid, 'public_domain', 'print station test', 'test');
	$mirror->sync($uid, false);
	$copied = $target->get($expected);
	if ($copied instanceof File) {
		$copied->putContent('changed by someone');
	}
	$rights->setOpen($version->getId(), $uid, 'print station test');
	$kept = $mirror->sync($uid, false);
	$check('a file changed by someone is not removed', $reasons($kept, $expected) === [PrintMirrorService::CHANGED]
		&& $target->nodeExists($expected) && $target->get($expected) instanceof File, json_encode($kept) ?: '');
	$check('it keeps the content written by someone', ($target->get($expected) instanceof File) && $target->get($expected)->getContent() === 'changed by someone');

	// ---- a foreign file with the name of the scan stays ------------------------------------------
	$rights->confirm($version->getId(), $uid, 'public_domain', 'print station test', 'test');
	$foreign = $mirror->sync($uid, false);
	$check('a foreign file with the name of the scan is not overwritten', $reasons($foreign, $expected) === [PrintMirrorService::FOREIGN]
		&& ($target->get($expected) instanceof File) && $target->get($expected)->getContent() === 'changed by someone', json_encode($foreign) ?: '');

	$archiveAfter = $archiveListing();
	$check('nothing was written into the archive folder', $archiveAfter === $archiveBefore, json_encode([
		'new' => array_values(array_diff($archiveAfter, $archiveBefore)), 'gone' => array_values(array_diff($archiveBefore, $archiveAfter)),
	]) ?: '');

	// ---- switched off --------------------------------------------------------------------------
	$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
	$controller = new PrintMirrorController(Server::get(IRequest::class), $uid, Server::get(Roles::class), $mirror, $l,
		Server::get(LoggerInterface::class));
	$middleware = new ModuleMiddleware($modules, $l);
	$modules->change([Modules::PRINT_MIRROR => false]);
	try {
		$middleware->beforeController($controller, 'sync');
		$check('the routes answer 404 while the module is off', false);
	} catch (ModuleDisabledException $e) {
		$check('the routes answer 404 while the module is off', $middleware->afterException($controller, 'sync', $e)->getStatus() === 404);
	}
	$before = count($target->getDirectoryListing());
	$mirror->runDaily();
	$check('no daily sync while the module is off', count($target->getDirectoryListing()) === $before);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$appConfig->clearCache(true);
	foreach ($originalSwitches as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $originalSwitches);
	$check('the settings of the print station are as before', $settings() === $originalSettings);
	// Delete by name: the rollback also removed the written files from the
	// file cache, so they no longer show up in a directory listing.
	if ($userFolder->nodeExists($targetName)) {
		$userFolder->get($targetName)->delete();
	}
	$check('the test folder and everything written are deleted', !$userFolder->nodeExists($targetName));
}

IntegrationTest::finish();
