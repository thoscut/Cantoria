<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ArchiveAccess;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\Input;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Settings of the app (page Administration → Cantoria, see
 * docs/api.md): archive folder, roles and groups (with a search for groups),
 * and the check who can open the archive folder.
 *
 * For admins and for the groups the administration has given the page under
 * Administration privileges: Nextcloud checks #[AuthorizedAdminSetting]
 * before the app, Roles::requireSettingsAccess() checks again. Those groups
 * cannot give a role to a group they belong to, change the archive folder
 * or switch on modules that open the archive to the outside; changes of
 * roles and of the archive folder are logged. The modules
 * (ModuleController) and the repertoire thresholds (EventController) follow
 * the same rule. Restoring a backup does not (WorkflowController::restore).
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends Controller {
	use RunsActions;

	/** Groups found at most by one search */
	private const MAX_GROUPS = 25;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private AppSettings $settings,
		private ArchiveAccess $access,
		private Modules $modules,
		private RepertoireService $repertoire,
		private FullBackupService $backup,
		private AuditLog $audit,
		private IGroupManager $groupManager,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings')]
	public function index(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->data($uid);
		});
	}

	/**
	 * @param array<array-key, mixed>|null $roles role => list of group ids (request data)
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function update(?array $roles = null, ?int $archiveFolderId = null): JSONResponse {
		return $this->run(function (string $uid) use ($roles, $archiveFolderId) {
			$this->roles->requireSettingsAccess($uid);
			$admin = $this->roles->isAdmin($uid);
			$roles ??= [];
			$wanted = [];
			foreach (array_keys($roles) as $role) {
				$role = (string)$role;
				if (!in_array($role, Roles::GROUP_ROLES, true)) {
					throw new InvalidInputException('Unknown role: %s', [$role]);
				}
				$wanted[$role] = array_values(array_unique(Input::stringList($roles[$role], 'Groups')));
				if (!$admin) {
					// Given the settings page, a group may arrange the roles of
					// others, but not give a role to itself.
					foreach (array_diff($wanted[$role], $this->roles->groups($role)) as $group) {
						if ($this->groupManager->isInGroup($uid, $group)) {
							throw new AccessDeniedException('Only an administrator can give a role to a group you belong to.');
						}
					}
				}
			}
			if ($archiveFolderId !== null && !$admin) {
				if ($archiveFolderId !== $this->settings->archiveInfo()['id']) {
					throw new AccessDeniedException('Only an administrator can change the archive folder: background jobs and backups run as the account that sets it.');
				}
				// Saved again unchanged: it stays with the account that set it.
				$archiveFolderId = null;
			}
			$operation = $this->audit->newOperation();
			foreach ($wanted as $role => $groups) {
				$old = $this->roles->groups($role);
				if ($old !== $groups) {
					$this->roles->setGroups($role, $groups);
					$this->audit->record($operation, $uid, 'settings', null, 'changed', 'groups_' . $role,
						implode(', ', $old), implode(', ', $groups));
				}
			}
			if ($archiveFolderId !== null) {
				$old = $this->settings->archiveInfo();
				$path = $this->settings->setArchiveFolder($uid, $archiveFolderId);
				if ($old['id'] !== $archiveFolderId || $old['user'] !== $uid) {
					$this->audit->record($operation, $uid, 'settings', null, 'changed', 'archive_folder',
						$old['id'] === 0 ? null : $old['path'] . ' (' . $old['user'] . ')', $path . ' (' . $uid . ')');
				}
			}
			return $this->data($uid);
		});
	}

	/**
	 * Groups to choose for a role: id and display name, at most 25. Nextcloud's
	 * own list of groups with display names needs further administration
	 * privileges (sharing or accounts), which the groups given the settings
	 * page do not have.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/groups')]
	public function groups(string $search = ''): JSONResponse {
		return $this->run(function (string $uid) use ($search) {
			$this->roles->requireSettingsAccess($uid);
			$groups = [];
			foreach ($this->groupManager->search(mb_substr(trim($search), 0, 100), self::MAX_GROUPS) as $group) {
				$groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
			}
			return $groups;
		});
	}

	/**
	 * Who of the role groups (and of the admins without a role) can open the
	 * archive folder, or with $folderId a folder just chosen and not saved
	 * yet. Only reads; each check sets up the files of an account, hence the
	 * rate limit.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: 20, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/access')]
	public function access(?int $folderId = null): JSONResponse {
		return $this->run(function (string $uid) use ($folderId) {
			$this->roles->requireSettingsAccess($uid);
			return $this->access->report($uid, ArchiveAccess::MAX_ACCOUNTS, $folderId);
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function data(string $uid): array {
		$roles = [];
		$groupNames = [];
		$groupsMissing = [];
		foreach (Roles::GROUP_ROLES as $role) {
			$roles[$role] = $this->roles->groups($role);
			foreach ($roles[$role] as $groupId) {
				if (array_key_exists($groupId, $groupNames)) {
					continue;
				}
				$group = $this->groupManager->get($groupId);
				// A group deleted after it was given the role keeps its id.
				$groupNames[$groupId] = $group?->getDisplayName() ?? $groupId;
				if ($group === null) {
					$groupsMissing[] = $groupId;
				}
			}
		}
		$admin = $this->roles->isAdmin($uid);
		$counts = $this->backup->counts();
		return [
			'roles' => $roles,
			// Display names of the groups above; an object also when empty
			'groupNames' => (object)$groupNames,
			'groupsMissing' => $groupsMissing,
			'roleLabels' => Roles::LABELS,
			'archive' => $this->settings->archiveInfo(),
			// Backups and annual snapshots: a folder of the account above,
			// outside the archive folder
			'backups' => [
				'path' => $this->settings->backupFolderPath(),
			],
			// The thresholds belong to the module "repertoire".
			'repertoire' => $this->modules->isEnabled('repertoire') ? $this->repertoire->thresholds() : null,
			// Sections of modules that start off (milestone 0.7)
			'folderBook' => $this->modules->isEnabled('folder_book'),
			'printMirror' => $this->modules->isEnabled('print_mirror'),
			'choirHandover' => $this->modules->isEnabled('choir_handover'),
			// For the setup steps: imported copies and files found by the file sync
			'catalog' => ['copies' => $counts['cantoria_copies'] ?? 0, 'files' => $counts['cantoria_files'] ?? 0],
			// ... and whether the functions for the choir were chosen (milestone 0.19)
			'modulesChosen' => $this->modules->chosen(),
			// Restoring a backup stays with admins (and the catalog role in the app).
			'admin' => $admin,
			'catalogEmpty' => $admin ? array_sum($counts) === 0 : null,
		];
	}
}
