<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the hand-over folder for a choir system (module
 * "choir_handover", milestone 0.12) against a real Nextcloud instance with
 * an imported catalog and a file sync:
 * - the archive folder and the folder of the print station are refused as
 *   hand-over folder,
 * - the preview writes nothing,
 * - a version on the list of the singers' portal is handed over with its
 *   PDF scan and MusicXML file, Liste.csv (shelf mark and license notice),
 *   the notes of the conductor of a link for choir members and LIESMICH.txt,
 * - its manifest is apart from the one of the print station,
 * - a second sync changes nothing,
 * - an export of the choir system with the piece becomes a proposal "in the
 *   singers' portal" (not twice), accepted it records the version; an export
 *   without the piece proposes the removal,
 * - a version no longer released leaves the folder; nothing is written into
 *   the archive folder,
 * - the routes answer 404 while the module is off.
 *
 * Usage (as the web server user):
 *   php tests/Integration/handover_test.php <nextcloud-root> <uid> <shelfmark> [<other-shelfmark>]
 *
 * <other-shelfmark> is a second shelf mark of the archive for the export
 * without the piece (default 2-3, or 1-1 when <shelfmark> is 2-3).
 *
 * <uid> must hold the role "librarian" and see the archive folder (it is
 * made an administrator inside the transaction). <shelfmark> is an active
 * copy with a PDF scan and a MusicXML file whose version is no internal
 * preservation copy; the test confirms it as public domain if it is not on
 * the list of the singers' portal. The two target folders the test creates
 * are deleted with everything written at the end; database changes are
 * rolled back, the module switches are exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);
$otherShelfmark = $argv[4] ?? ($shelfmark === '2-3' ? '1-1' : '2-3');

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\HandoverController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ChoirSystemCompareService;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PrintMirrorService;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\Files\File;
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
$handover = Server::get(HandoverService::class);
$printMirror = Server::get(PrintMirrorService::class);
$manifest = Server::get(MirrorFileMapper::class);
$auditEntries = Server::get(AuditEntryMapper::class);
$userFolder = Server::get(IRootFolder::class)->getUserFolder($uid);
$archive = Server::get(AppSettings::class)->archiveFolder($uid);

const HANDOVER_INSIDE = 'The hand-over folder must be neither the archive folder nor inside it. Please choose a folder outside the archive folder.';
const TAKEN = 'This folder is already the target folder of the print station or of the hand-over to the choir system. Please choose a folder of its own.';

$switchKeys = [Modules::CHOIR_HANDOVER, Modules::PRINT_MIRROR, Modules::MEMBER_LINKS, 'portal', 'print_station', 'releases', 'rights', 'proposals'];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null) ? $appConfig->getValueBool(Application::APP_ID, $configKey) : null;
	}
	return $values;
};
/** The stored settings, state and report of both folders. */
$settings = function () use ($appConfig): array {
	$values = $appConfig->getAllValues(Application::APP_ID, 'choir_handover_') + $appConfig->getAllValues(Application::APP_ID, 'print_mirror_');
	ksort($values);
	return $values;
};
$originalSwitches = $switches();
$originalSettings = $settings();
$archiveListing = fn (): array => array_map(fn ($node): string => $node->getName(), $archive->getDirectoryListing());
$archiveBefore = $archiveListing();
$suffix = bin2hex(random_bytes(3));
$targetName = 'Cantoria hand-over test ' . $suffix;
$printName = 'Cantoria print station test ' . $suffix;
$names = fn (array $entries): array => array_map(fn (array $entry): string => $entry['name'], $entries);

$db->beginTransaction();
try {
	$modules->change([Modules::CHOIR_HANDOVER => true, Modules::PRINT_MIRROR => true, Modules::MEMBER_LINKS => true,
		'portal' => true, 'print_station' => true, 'releases' => true, 'rights' => true, 'proposals' => true]);
	$user = Server::get(IUserManager::class)->get($uid);
	$adminGroup = Server::get(IGroupManager::class)->get('admin');
	if ($user !== null && $adminGroup !== null && !$adminGroup->inGroup($user)) {
		// Only inside this transaction: the target folder is chosen by administrators.
		$adminGroup->addUser($user);
	}

	// ---- the copy, its scan and its MusicXML file ---------------------------------------------
	$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark] ?? null;
	$roles = [];
	foreach (Server::get(ArchiveFileMapper::class)->findFound() as $file) {
		if ($copy !== null && $file->getCopyId() === $copy->getId()) {
			$roles[$file->getRole()] ??= $file;
		}
	}
	$check('the copy has a PDF scan and a MusicXML file', isset($roles['score_pdf'], $roles['musicxml']), $shelfmark);
	if ($copy === null || !isset($roles['score_pdf'], $roles['musicxml'])) {
		throw new RuntimeException('no PDF scan and MusicXML file for ' . $shelfmark);
	}
	$version = Server::get(WorkVersionMapper::class)->find($copy->getVersionId());
	$title = Server::get(WorkMapper::class)->find($version->getWorkId())->getTitle();
	$base = PrintMirrorService::fileBase($shelfmark, $title);
	$pdf = $base . '.pdf';
	// Written uncompressed and cleaned, also from an .mxl file (S N4)
	$musicxml = $base . '.musicxml';
	$notes = $base . HandoverService::NOTES;
	$rights = Server::get(RightsService::class);
	$releases = Server::get(ReleaseListService::class);
	// Released for the portal: rows "ok", "add" and "not_decided" ("remove" is recorded but no longer released)
	$onList = fn (): bool => in_array($version->getId(), array_column(array_filter($releases->portal($uid),
		fn (array $row): bool => $row['status'] !== 'remove'), 'versionId'), true);
	if (!$onList() || !$version->isConfirmed()) {
		$rights->confirm($version->getId(), $uid, 'public_domain', 'hand-over test', 'test');
	}
	$check('the version is on the list of the singers\' portal', $onList(), $shelfmark);

	// ---- a note of the conductor on a link for choir members --------------------------------------
	Server::get(LinkService::class)->create($copy->getId(), ['circle' => 'members', 'measure_from' => '1', 'measure_to' => '2',
		'voice' => 'Alto', 'note' => "Breathe after the first phrase.\nSoftly."], $uid);

	// ---- the target folder -------------------------------------------------------------------
	$check('the archive folder is refused', $throws(fn () => $handover->checkTargetFolder($uid, $archive->getId())) === HANDOVER_INSIDE);
	$printFolder = $userFolder->newFolder($printName);
	$printMirror->setTargetFolder($uid, $printFolder->getId());
	$check('the folder of the print station is refused', $throws(fn () => $handover->checkTargetFolder($uid, $printFolder->getId())) === TAKEN);

	$target = $userFolder->newFolder($targetName);
	$check('before, the print station would accept the folder', $throws(fn () => $printMirror->checkTargetFolder($uid, $target->getId())) === null);
	$saved = $handover->setTargetFolder($uid, $target->getId());
	$check('a folder of its own is saved', $saved['id'] === $target->getId() && $saved['user'] === $uid, json_encode($saved) ?: '');
	$check('then the print station refuses it', $throws(fn () => $printMirror->checkTargetFolder($uid, $target->getId())) === TAKEN);

	// ---- preview, then the first sync -------------------------------------------------------------
	$preview = $handover->sync($uid, true);
	$wanted = [$pdf, $musicxml, HandoverService::LIST, HandoverService::README, $notes];
	$check('the preview lists scan, MusicXML, list, notes and read-me', array_diff($wanted, $names($preview['copied'])) === [],
		json_encode($names($preview['copied'])) ?: '');
	$check('the preview writes nothing', $target->getDirectoryListing() === [] && $manifest->findAllByName(HandoverService::HANDOVER_MIRROR) === []);

	$report = $handover->sync($uid, false);
	$check('everything is written', array_filter($wanted, fn (string $name): bool => !$target->nodeExists($name)) === [], json_encode($report) ?: '');
	$list = $target->nodeExists(HandoverService::LIST) ? $target->get(HandoverService::LIST) : null;
	$listText = $list instanceof File ? $list->getContent() : '';
	$check('Liste.csv names the file and its shelf mark', str_contains($listText, $pdf) && str_contains($listText, $shelfmark));
	// English in the CI, German on instances whose account speaks German
	$check('Liste.csv gives the license notice', str_contains($listText, 'Public domain (confirmed)') || str_contains($listText, 'Gemeinfrei (bestätigt)'), $listText);
	$scoreFile = $target->nodeExists($musicxml) ? $target->get($musicxml) : null;
	$scoreText = $scoreFile instanceof File ? $scoreFile->getContent() : '';
	$check('the MusicXML file is cleaned like the score of a link', str_contains($scoreText, '<score-partwise')
		&& !str_contains($scoreText, '<!--') && !str_contains($scoreText, '<encoder') && !str_contains($scoreText, '<miscellaneous'));
	$notesFile = $target->nodeExists($notes) ? $target->get($notes) : null;
	$notesText = $notesFile instanceof File ? $notesFile->getContent() : '';
	$check('the notes give the passage, the voice and the note on one line', preg_match('/(m\.|T\.) 1–2, Alto: Breathe after the first phrase\. Softly\./u', $notesText) === 1, $notesText);
	$check('the manifest is apart from the print station', isset($manifest->findAllByName(HandoverService::HANDOVER_MIRROR)[$pdf])
		&& !isset($manifest->findAllByName('print')[$pdf]));
	$copiedEntries = array_filter($auditEntries->findLatest(200), fn ($e): bool => $e->getObjectType() === HandoverService::HANDOVER_OBJECT_TYPE
		&& $e->getAction() === 'mirror_copied' && $e->getNewValue() === $pdf);
	$check('the copy is in the audit log', $copiedEntries !== []);

	$again = $handover->sync($uid, false);
	$check('a second sync changes nothing', $again['copied'] === [] && $again['replaced'] === [] && $again['removed'] === [],
		json_encode($again) ?: '');

	// ---- the export of the choir system: differences become proposals --------------------------------
	$compare = Server::get(ChoirSystemCompareService::class);
	$proposals = Server::get(ProposalService::class);
	$wasOnPortal = $rights->isOnPortal(Server::get(WorkVersionMapper::class)->find($version->getId()));
	if ($wasOnPortal) {
		$rights->removeFromPortal($version->getId(), $uid, 'hand-over test');
	}
	$export = "Titel;Datei\n" . $title . ';' . $pdf . "\n";
	$dry = $compare->compare($export, 'Singste.csv', $uid, true);
	$check('the dry run finds the piece that the portal does not record', in_array($shelfmark, array_column($dry['added'], 'shelfmark'), true)
		&& !$proposals->hasOpenPortalProposal($version->getId(), true), json_encode($dry) ?: '');
	$compare->compare($export, 'Singste.csv', $uid, false);
	$open = array_values(array_filter($proposals->open(), fn ($p): bool => $p->getObjectId() === $version->getId()
		&& $p->getField() === ProposalService::PORTAL_FIELD));
	$check('a proposal waits in the inbox, the catalog is unchanged', count($open) === 1
		&& !$rights->isOnPortal(Server::get(WorkVersionMapper::class)->find($version->getId())));
	$check('comparing again proposes nothing twice', $compare->compare($export, 'Singste.csv', $uid, false)['alreadyProposed'] >= 1);
	if ($open !== []) {
		$proposals->accept($open[0]->getId(), $uid, null);
	}
	$check('accepted, the version is recorded as in the portal', $rights->isOnPortal(Server::get(WorkVersionMapper::class)->find($version->getId())));
	$without = $compare->compare("Titel\n" . $shelfmark . "-none\n" . $otherShelfmark . " other\n", 'Singste.csv', $uid, true);
	$check('missing in the export, removal is proposed', in_array($shelfmark, array_column($without['removed'], 'shelfmark'), true),
		json_encode($without) ?: '');

	// ---- no longer released: the files leave the folder ---------------------------------------------
	$rights->setOpen($version->getId(), $uid, 'hand-over test');
	$check('the version is no longer on the list', !$onList());
	$removed = $handover->sync($uid, false);
	$check('scan, MusicXML and notes are removed', array_diff([$pdf, $musicxml, $notes], $names($removed['removed'])) === []
		&& !$target->nodeExists($pdf) && !$target->nodeExists($notes), json_encode($removed) ?: '');
	$check('nothing was written into the archive folder', $archiveListing() === $archiveBefore);

	// ---- switched off ------------------------------------------------------------------------------
	$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
	$controller = new HandoverController(Server::get(IRequest::class), $uid, Server::get(Roles::class), $handover, $l,
		Server::get(LoggerInterface::class));
	$middleware = new ModuleMiddleware($modules, $l);
	$modules->change([Modules::CHOIR_HANDOVER => false]);
	try {
		$middleware->beforeController($controller, 'sync');
		$check('the routes answer 404 while the module is off', false);
	} catch (ModuleDisabledException $e) {
		$check('the routes answer 404 while the module is off', $middleware->afterException($controller, 'sync', $e)->getStatus() === 404);
	}
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
	$check('the settings of both folders are as before', $settings() === $originalSettings);
	foreach ([$targetName, $printName] as $name) {
		if ($userFolder->nodeExists($name)) {
			$userFolder->get($name)->delete();
		}
	}
	$check('the test folders and everything written are deleted', !$userFolder->nodeExists($targetName) && !$userFolder->nodeExists($printName));
}

IntegrationTest::finish();
