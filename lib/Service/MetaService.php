<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCP\Defaults;

/**
 * What the UI needs on start: own roles, field registry, archive folder
 * (and whether the account can open it), the active modules, the personal
 * view of the account and the name of the Nextcloud (Theming) for the header
 * of printouts.
 */
class MetaService {
	public function __construct(
		private Roles $roles,
		private AppSettings $settings,
		private Modules $modules,
		private PersonalView $personalView,
		private ArchiveAccess $archiveAccess,
		private Defaults $defaults,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function forUser(string $uid): array {
		$archive = $this->settings->archiveInfo();
		return [
			'uid' => $uid,
			'admin' => $this->roles->isAdmin($uid),
			'canChangeSettings' => $this->roles->canChangeSettings($uid),
			'roles' => $this->roles->rolesOf($uid),
			'roleLabels' => Roles::LABELS,
			'fields' => Fields::all(),
			'licenseUses' => Fields::LICENSE_USES,
			// ways out that only confirmed public domain opens, never a license (src/rightsHints.ts)
			'freeOnlyWays' => RightsPolicy::FREE_ONLY_WAYS,
			'personFields' => Fields::person(),
			'contributionRoles' => Fields::CONTRIBUTION_ROLES,
			'archive' => $archive,
			// null without an archive folder
			'archiveAccessible' => $archive['id'] === 0 ? null : $this->archiveAccess->canOpen($uid),
			'modules' => $this->modules->states(),
			'personalView' => $this->personalView->get($uid),
			'siteName' => $this->defaults->getName(),
		];
	}
}
