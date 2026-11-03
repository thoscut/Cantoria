<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Settings\Admin;
use OCA\Cantoria\Settings\AdminSection;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Log\LoggerInterface;

/**
 * App roles and their mapping to Nextcloud groups.
 *
 * Permissions are bound to groups, never to persons, so a change of office is
 * a change of group membership. Admins may read and change settings, but may
 * only write catalog data if they also hold a role. The administration can
 * give the settings (not a role) to further groups under Administration
 * privileges.
 *
 * The archive roles (ALL) open the music archive. Choir members (MEMBER, milestone
 * 0.7) are no archive role: rolesOf() and hasAccess() leave them out, so every
 * route that checks access refuses them. They only reach the page "Shared with
 * me" and its routes (MemberController), and only while the module
 * "member_links" is on.
 */
class Roles {
	/** Music librarian (catalog): works, versions, rights, import, file sync */
	public const LIBRARIAN = 'librarian';
	/** Music librarian (operations): copy status and counts */
	public const OPERATIONS = 'operations';
	/** Conductor: decides which version is valid for the choir */
	public const CONDUCTOR = 'conductor';
	/** Tools and transcription rounds: proposals only */
	public const CONTRIBUTOR = 'contributor';
	/** Board, auditors: read everything */
	public const READER = 'reader';
	/** Choir members: only the excerpts shared with them (module member_links); no archive role */
	public const MEMBER = 'member';

	/** The archive roles */
	public const ALL = [self::LIBRARIAN, self::OPERATIONS, self::CONDUCTOR, self::CONTRIBUTOR, self::READER];
	/** Roles that are given to groups in the settings: the archive roles and the choir members */
	public const GROUP_ROLES = [...self::ALL, self::MEMBER];

	public const LABELS = [
		self::LIBRARIAN => 'Catalog (music librarian)',
		self::OPERATIONS => 'Operations (music librarian)',
		self::CONDUCTOR => 'Conductor',
		self::CONTRIBUTOR => 'Proposals (tools)',
		self::READER => 'Read only (board, auditors)',
		self::MEMBER => 'Choir members (shared excerpts only)',
	];

	/** @var array<string, list<string>> */
	private array $cache = [];
	/** @var array<string, bool> uid => settings given by the administration */
	private array $delegated = [];
	/** accounts in the groups of the choir members, once counted */
	private ?int $memberCount = null;

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ISettingsManager $settingsManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function groups(string $role): array {
		$groups = $this->appConfig->getValueArray(Application::APP_ID, 'groups_' . $role, []);
		return array_values(array_filter($groups, 'is_string'));
	}

	/**
	 * @param list<string> $groups
	 */
	public function setGroups(string $role, array $groups): void {
		if (!in_array($role, self::GROUP_ROLES, true)) {
			throw new \InvalidArgumentException('Unknown role: ' . $role);
		}
		$this->appConfig->setValueArray(Application::APP_ID, 'groups_' . $role, array_values(array_unique($groups)));
		$this->cache = [];
		$this->memberCount = null;
	}

	/**
	 * The archive roles of an account (never MEMBER).
	 *
	 * @return list<string>
	 */
	public function rolesOf(string $uid): array {
		if (!isset($this->cache[$uid])) {
			$roles = [];
			foreach (self::ALL as $role) {
				foreach ($this->groups($role) as $group) {
					if ($this->groupManager->isInGroup($uid, $group)) {
						$roles[] = $role;
						break;
					}
				}
			}
			$this->cache[$uid] = $roles;
		}
		return $this->cache[$uid];
	}

	/**
	 * Whether the account belongs to a group of the choir members. This
	 * opens nothing of the archive (see hasAccess()).
	 */
	public function isMember(string $uid): bool {
		foreach ($this->groups(self::MEMBER) as $group) {
			if ($this->groupManager->isInGroup($uid, $group)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many accounts are in the groups of the choir members, each counted
	 * once; for the hint when there are more of them than licenses (W B17).
	 */
	public function memberCount(): int {
		if ($this->memberCount === null) {
			$accounts = [];
			foreach ($this->groups(self::MEMBER) as $gid) {
				foreach ($this->groupManager->get($gid)?->getUsers() ?? [] as $user) {
					$accounts[$user->getUID()] = true;
				}
			}
			$this->memberCount = count($accounts);
		}
		return $this->memberCount;
	}

	public function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}

	/**
	 * Whether the account may use the music archive: an archive role or admin
	 * rights. Choir members without an archive role have no access.
	 */
	public function hasAccess(string $uid): bool {
		return $this->rolesOf($uid) !== [] || $this->isAdmin($uid);
	}

	public function requireAccess(string $uid): void {
		if (!$this->hasAccess($uid)) {
			throw new AccessDeniedException('You have no access to the music archive.');
		}
	}

	/**
	 * @param list<string> $allowed
	 */
	public function hasAnyRole(string $uid, array $allowed): bool {
		return array_intersect($this->rolesOf($uid), $allowed) !== [];
	}

	/**
	 * @param list<string> $allowed
	 */
	public function requireRole(string $uid, array $allowed): void {
		if (!$this->hasAnyRole($uid, $allowed)) {
			$labels = array_map(fn (string $role): string => self::LABELS[$role] ?? $role, $allowed);
			throw new AccessDeniedException('This requires one of these roles: %s', [implode(', ', $labels)]);
		}
	}

	/**
	 * Whether the account may change the settings of the app: admins, and
	 * members of the groups that the administration has given the page
	 * "Administration → Cantoria" under Administration privileges. This
	 * gives no role and no access to the catalog.
	 */
	public function canChangeSettings(string $uid): bool {
		if ($this->isAdmin($uid)) {
			return true;
		}
		if (!isset($this->delegated[$uid])) {
			$this->delegated[$uid] = $this->isDelegated($uid);
		}
		return $this->delegated[$uid];
	}

	/**
	 * Second check of the settings routes: they carry
	 * #[AuthorizedAdminSetting(settings: Admin::class)], so Nextcloud checks
	 * the same before the app.
	 */
	public function requireSettingsAccess(string $uid): void {
		if (!$this->canChangeSettings($uid)) {
			throw new AccessDeniedException('Only administrators may change this.');
		}
	}

	/**
	 * Whether the administration has given the settings of the app to one of
	 * the account's groups. Nextcloud keeps that list; it is read through the
	 * public settings API. Fails closed.
	 */
	private function isDelegated(string $uid): bool {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return false;
		}
		try {
			foreach ($this->settingsManager->getAllowedAdminSettings(AdminSection::ID, $user) as $settings) {
				foreach ($settings as $setting) {
					if ($setting instanceof Admin) {
						return true;
					}
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Cantoria: the delegated settings could not be read: ' . $e->getMessage(), ['exception' => $e]);
		}
		return false;
	}
}
