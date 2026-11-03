<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the settings under Administration → Cantoria and
 * of the access check of the archive folder, against a real Nextcloud
 * instance with an imported catalog and a file sync.
 *
 * Usage (as the web server user):
 *   php tests/Integration/settings_access_test.php <nextcloud-root> <uid>
 *
 * <uid> must be the configured archive user (the account that chose the
 * archive folder). The test creates two accounts in two groups of its own,
 * gives both groups the role "reader", shares the archive folder (read only)
 * with the first group and gives the settings page to the second group under
 * Administration privileges. It checks
 *   - the access check: the first account can open the archive folder, the
 *     second cannot and is named; it only reads (no share is added); a
 *     folder chosen before saving is checked the same way, and a folder the
 *     person cannot open is refused,
 *   - the meta data of the UI (archiveAccessible, canChangeSettings),
 *   - the score view: the second account gets the message that the archive
 *     folder is not shared with it (reason "not_shared"),
 *   - the settings rights: the account with the delegated settings page may
 *     read the settings and run the check, the other one gets 403.
 * Afterwards the share, the delegation, the accounts, the groups and the
 * role groups are removed or set back.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\SettingsController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Exception\NotSharedException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ArchiveAccess;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Settings\Admin;
use OCA\Settings\Service\AuthorizedGroupService;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);

$users = Server::get(IUserManager::class);
$groups = Server::get(IGroupManager::class);
$roles = Server::get(Roles::class);
$settings = Server::get(AppSettings::class);
$shares = Server::get(IShareManager::class);

$archive = $settings->archiveInfo();
$check('archive folder is set', $archive['id'] > 0, $archive['path']);
$check('<uid> is the archive user', $archive['user'] === $uid, $archive['user']);

$suffix = strtolower(Server::get(ISecureRandom::class)->generate(8, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
$sharedGroup = 'na-test-shared-' . $suffix;
$otherGroup = 'na-test-other-' . $suffix;
$sharedUid = 'na-test-shared-' . $suffix;
$otherUid = 'na-test-other-' . $suffix;
$readers = $roles->groups(Roles::READER);

/** @var list<IUser> $created */
$created = [];
$share = null;
$delegation = null;
$delegations = class_exists(AuthorizedGroupService::class) ? Server::get(AuthorizedGroupService::class) : null;

/**
 * A new account in a group, marked as logged in once (the check leaves out
 * accounts that never logged in).
 */
$account = function (string $accountUid, string $groupId, string $displayName) use ($users, $groups, &$created): IUser {
	$password = Server::get(ISecureRandom::class)->generate(32);
	$user = $users->createUser($accountUid, $password);
	if (!$user instanceof IUser) {
		throw new RuntimeException('The test account ' . $accountUid . ' could not be created.');
	}
	$created[] = $user;
	$user->setDisplayName($displayName);
	$user->updateLastLoginTimestamp();
	$groups->createGroup($groupId)?->addUser($user);
	return $user;
};

try {
	$sharedUser = $account($sharedUid, $sharedGroup, 'Access Test Shared');
	$otherUser = $account($otherUid, $otherGroup, 'Access Test Other');
	$roles->setGroups(Roles::READER, [...$readers, $sharedGroup, $otherGroup]);

	// The archive folder, read only, for the first group: the way the admin
	// guide recommends it.
	$folder = $settings->archiveFolder($uid);
	$newShare = $shares->newShare();
	$newShare->setNode($folder)
		->setShareType(IShare::TYPE_GROUP)
		->setSharedWith($sharedGroup)
		->setSharedBy($uid)
		->setPermissions(Constants::PERMISSION_READ);
	$share = $shares->createShare($newShare);
	$check('archive folder shared with the first test group', $share->getId() !== '');

	if ($delegations !== null) {
		$delegation = $delegations->create($otherGroup, Admin::class);
	}
	$check('settings page given to the second test group', $delegation !== null, $delegations === null ? 'settings app not available' : '');

	// ---- the access check ----------------------------------------------------
	$sharesBefore = count($shares->getSharesBy($uid, IShare::TYPE_GROUP, $folder, false, -1));
	$report = Server::get(ArchiveAccess::class)->report($uid);
	$sharesAfter = count($shares->getSharesBy($uid, IShare::TYPE_GROUP, $folder, false, -1));
	$check('the check adds no share', $sharesBefore === $sharesAfter, $sharesBefore . ' / ' . $sharesAfter);
	$check('the archive user can open the archive folder', $report['self']['canOpen'] === true);

	$byGroup = [];
	foreach ($report['roles'] as $role) {
		if ($role['role'] === Roles::READER) {
			foreach ($role['groups'] as $group) {
				$byGroup[$group['id']] = $group;
			}
		}
	}
	$shared = $byGroup[$sharedGroup] ?? null;
	$other = $byGroup[$otherGroup] ?? null;
	$check('both test groups are listed under the role "reader"', $shared !== null && $other !== null);
	if ($shared !== null && $other !== null) {
		$check('shared group: its member can open the folder', $shared['members'] === 1 && $shared['canOpen'] === 1 && $shared['cannotOpen'] === [],
			json_encode($shared, JSON_THROW_ON_ERROR));
		$check('other group: its member cannot and is named', $other['members'] === 1 && $other['canOpen'] === 0
			&& $other['cannotOpen'] === [['uid' => $otherUid, 'displayName' => 'Access Test Other']], json_encode($other, JSON_THROW_ON_ERROR));
	}
	$check('the report is complete', $report['complete'] && $report['checked'] <= $report['limit']);

	// The same folder checked as a newly chosen folder, before saving
	$chosen = Server::get(ArchiveAccess::class)->report($uid, ArchiveAccess::MAX_ACCOUNTS, $archive['id']);
	$chosenOther = [];
	foreach ($chosen['roles'] as $role) {
		foreach ($role['groups'] as $group) {
			if ($group['id'] === $otherGroup) {
				$chosenOther = $group['cannotOpen'];
			}
		}
	}
	$check('a chosen folder is checked the same way', $chosen['archive']['id'] === $archive['id']
		&& array_column($chosenOther, 'uid') === [$otherUid]);
	$check('a folder the person cannot open is refused', IntegrationTest::throws(
		fn () => Server::get(ArchiveAccess::class)->report($otherUid, ArchiveAccess::MAX_ACCOUNTS, $archive['id'])) !== null);

	// ---- what the UI gets ------------------------------------------------------
	$meta = Server::get(MetaService::class);
	$sharedMeta = $meta->forUser($sharedUid);
	$otherMeta = $meta->forUser($otherUid);
	$check('meta: the shared account can open the archive folder', $sharedMeta['archiveAccessible'] === true);
	$check('meta: the other account cannot', $otherMeta['archiveAccessible'] === false);
	$check('meta: only the delegated account may change the settings',
		$sharedMeta['canChangeSettings'] === false && $otherMeta['canChangeSettings'] === ($delegation !== null));
	$check('the delegated settings give no role beyond the group roles', $otherMeta['roles'] === [Roles::READER]);

	// ---- the score view --------------------------------------------------------
	$scores = Server::get(ScoreService::class);
	$fileId = null;
	foreach (Server::get(ArchiveFileMapper::class)->findFound() as $file) {
		if ($file->getRole() !== 'musicxml' || $file->getCopyId() === null) {
			continue;
		}
		try {
			$scores->content($file->getId(), $sharedUid);
			$fileId = $file->getId();
			break;
		} catch (UserFacingException) {
			// e.g. an internal preservation copy, which readers may not view
		}
	}
	$check('the shared account can view a score', $fileId !== null);
	if ($fileId !== null) {
		$reason = null;
		try {
			$scores->content($fileId, $otherUid);
		} catch (NotSharedException $e) {
			$reason = $e->getMessage();
		}
		$check('the other account is told that the archive folder is not shared with it',
			$reason !== null && str_contains($reason, 'not shared with your account'), (string)$reason);
	}

	// ---- the settings rights -----------------------------------------------------
	$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
	$controller = fn (string $accountUid): SettingsController => new SettingsController(Server::get(IRequest::class), $accountUid,
		$roles, $settings, Server::get(ArchiveAccess::class), Server::get(Modules::class), Server::get(RepertoireService::class),
		Server::get(FullBackupService::class), Server::get(AuditLog::class), $groups, $l, Server::get(LoggerInterface::class),
		Server::get(ISubAdmin::class), Server::get(IUserManager::class));
	$refused = $controller($sharedUid)->access();
	$check('an account without the settings page is refused', $refused->getStatus() === Http::STATUS_FORBIDDEN);
	if ($delegation !== null) {
		$allowed = $controller($otherUid)->index();
		$data = $allowed->getData();
		$check('the delegated account reads the settings', $allowed->getStatus() === Http::STATUS_OK && is_array($data)
			&& $data['admin'] === false && $data['catalogEmpty'] === null, json_encode($data, JSON_THROW_ON_ERROR));
		$check('the delegated account runs the access check', $controller($otherUid)->access()->getStatus() === Http::STATUS_OK);
		$found = $controller($otherUid)->groups('na-test-shared-' . $suffix)->getData();
		$check('the delegated account finds groups to choose', $found === [['id' => $sharedGroup, 'displayName' => $sharedGroup]],
			json_encode($found, JSON_THROW_ON_ERROR));
	}
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	if ($share !== null) {
		$shares->deleteShare($share);
	}
	if ($delegation !== null && $delegations !== null) {
		$delegations->delete($delegation->getId());
	}
	$roles->setGroups(Roles::READER, $readers);
	foreach ($created as $user) {
		$user->delete();
	}
	foreach ([$sharedGroup, $otherGroup] as $groupId) {
		$groups->get($groupId)?->delete();
	}
	$check('role groups set back', $roles->groups(Roles::READER) === $readers);
	$check('test accounts and groups removed', !$users->userExists($sharedUid) && !$users->userExists($otherUid)
		&& !$groups->groupExists($sharedGroup) && !$groups->groupExists($otherGroup));
}

IntegrationTest::finish();
