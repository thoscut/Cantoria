<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Who can open the archive folder in their own Nextcloud.
 *
 * The app reads files only with the permissions of the person who uses it
 * (scores, scans, practice files). A role holder whose account cannot open
 * the archive folder sees the catalog but none of its files. This check
 * tells the administration who that is, per role and group.
 *
 * It only reads: it shares nothing and changes nothing. Accounts that are
 * disabled or have never logged in are not checked: Nextcloud would first
 * have to set up the file storage of an account that never logged in.
 *
 * @psalm-type Account = array{uid: string, displayName: string}
 * @psalm-type Members = array{members: int, checked: int, canOpen: int, cannotOpen: list<Account>, notChecked: int}
 * @psalm-type GroupReport = array{id: string, displayName: string, exists: bool, members: int, checked: int, canOpen: int, cannotOpen: list<Account>, notChecked: int}
 * @psalm-type RoleReport = array{role: string, groups: list<GroupReport>}
 * @psalm-type Report = array{archive: array{id: int, path: string, user: string}, self: array{uid: string, displayName: string, canOpen: bool}, roles: list<RoleReport>, adminsWithoutRole: Members, checked: int, limit: int, complete: bool}
 */
class ArchiveAccess {
	/** Accounts checked at most per run; each check sets up the account's files. */
	public const MAX_ACCOUNTS = 500;
	/** Nextcloud's group of administrators */
	private const ADMIN_GROUP = 'admin';

	/** The folder checked in the current run */
	private int $folderId = 0;
	/** @var array<string, bool> uid => can open the folder, per run */
	private array $results = [];
	private int $limit = self::MAX_ACCOUNTS;
	private bool $complete = true;

	public function __construct(
		private AppSettings $settings,
		private Roles $roles,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the account can open the archive folder (or the folder given);
	 * false without one.
	 */
	public function canOpen(string $uid, ?int $folderId = null): bool {
		$folderId ??= $this->settings->archiveInfo()['id'];
		if ($folderId <= 0) {
			return false;
		}
		try {
			return $this->rootFolder->getUserFolder($uid)->getFirstNodeById($folderId) instanceof Folder;
		} catch (\Exception $e) {
			// No such account, storage not available, …: the account cannot open it now.
			// Without the account: the log needs no names of people (guideline 15).
			$this->logger->info('Cantoria: access to the archive folder could not be checked for an account: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Who of the role groups, and of the admins without a role, can open the
	 * archive folder. Each account is checked once, at most $limit accounts;
	 * `complete` is false when accounts were left out because of the limit.
	 *
	 * With $folderId, the folder the person who asks has just chosen is
	 * checked instead, before it is saved; that person must be able to open it.
	 *
	 * @param string $uid the person who asks; always checked
	 * @return Report
	 * @throws InvalidInputException without an archive folder, or for a folder the person cannot open
	 */
	public function report(string $uid, int $limit = self::MAX_ACCOUNTS, ?int $folderId = null): array {
		$archive = $folderId === null ? $this->settings->archiveInfo() : $this->chosenFolder($uid, $folderId);
		if ($archive['id'] === 0) {
			throw new InvalidInputException('No archive folder has been set yet.');
		}
		$this->folderId = $archive['id'];
		$this->results = [];
		$this->limit = max(1, $limit);
		$this->complete = true;

		$self = $this->userManager->get($uid);
		$selfCanOpen = $this->canOpen($uid, $this->folderId);
		$this->results[$uid] = $selfCanOpen;

		$roles = [];
		foreach (Roles::ALL as $role) {
			$groups = [];
			foreach ($this->roles->groups($role) as $groupId) {
				$groups[] = $this->groupReport($groupId);
			}
			$roles[] = ['role' => $role, 'groups' => $groups];
		}

		return [
			'archive' => $archive,
			'self' => ['uid' => $uid, 'displayName' => $self?->getDisplayName() ?? $uid, 'canOpen' => $selfCanOpen],
			'roles' => $roles,
			'adminsWithoutRole' => $this->adminsWithoutRole(),
			'checked' => count($this->results),
			'limit' => $this->limit,
			'complete' => $this->complete,
		];
	}

	/**
	 * A folder the person has chosen as the new archive folder, as they see it.
	 *
	 * @return array{id: int, path: string, user: string}
	 * @throws InvalidInputException when it is no folder the person can open
	 */
	private function chosenFolder(string $uid, int $folderId): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $folderId > 0 ? $userFolder->getFirstNodeById($folderId) : null;
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The selected archive folder is not a folder or not accessible.');
		}
		return ['id' => $folderId, 'path' => $userFolder->getRelativePath($folder->getPath()) ?? $folder->getPath(), 'user' => $uid];
	}

	/**
	 * @return GroupReport
	 */
	private function groupReport(string $groupId): array {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			// A group that was deleted after it was given a role
			return ['id' => $groupId, 'displayName' => $groupId, 'exists' => false, 'members' => 0, 'checked' => 0,
				'canOpen' => 0, 'cannotOpen' => [], 'notChecked' => 0];
		}
		$users = $this->members($group);
		return ['id' => $groupId, 'displayName' => $group->getDisplayName(), 'exists' => true]
			+ $this->summary($users, $this->memberCount($group, count($users)));
	}

	/**
	 * @return Members
	 */
	private function adminsWithoutRole(): array {
		$group = $this->groupManager->get(self::ADMIN_GROUP);
		if ($group === null) {
			return $this->summary([], 0);
		}
		$admins = array_values(array_filter($this->members($group),
			fn (IUser $user): bool => $this->roles->rolesOf($user->getUID()) === []));
		return $this->summary($admins, count($admins));
	}

	/**
	 * Members of a group, at most one more than the limit (a group with more
	 * members cannot be checked completely anyway).
	 *
	 * @return list<IUser>
	 */
	private function members(IGroup $group): array {
		return array_values($group->searchUsers('', $this->limit + 1));
	}

	private function memberCount(IGroup $group, int $loaded): int {
		$count = $group->count();
		return is_int($count) ? max($count, $loaded) : $loaded;
	}

	/**
	 * @param list<IUser> $users
	 * @return Members
	 */
	private function summary(array $users, int $members): array {
		$canOpen = 0;
		$cannotOpen = [];
		foreach ($users as $user) {
			$result = $this->check($user);
			if ($result === true) {
				$canOpen++;
			} elseif ($result === false) {
				$cannotOpen[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
			}
		}
		$checked = $canOpen + count($cannotOpen);
		if ($members > count($users)) {
			// Members beyond the ones loaded were not checked.
			$this->complete = false;
		}
		usort($cannotOpen, self::byName(...));
		return ['members' => $members, 'checked' => $checked, 'canOpen' => $canOpen, 'cannotOpen' => $cannotOpen,
			'notChecked' => max(0, $members - $checked)];
	}

	/**
	 * @param Account $a
	 * @param Account $b
	 */
	private static function byName(array $a, array $b): int {
		return strnatcasecmp($a['displayName'], $b['displayName']) ?: strcmp($a['uid'], $b['uid']);
	}

	/**
	 * Whether an account can open the archive folder; null when it is not
	 * checked (disabled, never logged in, or the limit is reached).
	 */
	private function check(IUser $user): ?bool {
		$uid = $user->getUID();
		if (isset($this->results[$uid])) {
			return $this->results[$uid];
		}
		if (!$user->isEnabled() || $user->getLastLogin() === 0) {
			return null;
		}
		if (count($this->results) >= $this->limit) {
			$this->complete = false;
			return null;
		}
		return $this->results[$uid] = $this->canOpen($uid, $this->folderId);
	}
}
