<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\ModuleSettings;
use OCA\Cantoria\Service\PersonalView;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Modules and presets (a setting like the others: admins and the groups the
 * administration has given the settings page) and the personal view of an
 * account (see docs/api.md). These routes belong to the core.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ModuleController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private ModuleSettings $moduleSettings,
		private PersonalView $personalView,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/modules')]
	public function settings(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->moduleSettings->describe($this->l);
		});
	}

	/**
	 * Switches modules on and off, or applies a preset or a scenario; one of
	 * them.
	 *
	 * @param array<array-key, mixed>|null $modules module key => on (request data)
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings/modules')]
	public function update(?array $modules = null, ?string $preset = null, ?string $scenario = null): JSONResponse {
		return $this->run(function (string $uid) use ($modules, $preset, $scenario) {
			$this->roles->requireSettingsAccess($uid);
			if (count(array_filter([$modules, $preset, $scenario], fn (mixed $value): bool => $value !== null)) !== 1) {
				throw new InvalidInputException('Please choose a module or a preset.');
			}
			$admin = $this->roles->isAdmin($uid);
			$changes = match (true) {
				$preset !== null => $this->moduleSettings->applyPreset($preset, $uid),
				$scenario !== null => $this->moduleSettings->applyScenario($scenario, $uid, $admin),
				default => $this->moduleSettings->change($modules ?? [], $uid, $admin),
			};
			return $this->moduleSettings->describe($this->l) + ['changes' => $changes];
		});
	}

	/**
	 * Replaces the personal view of the account (hidden navigation areas and
	 * start page cards). Changes no permission.
	 *
	 * @param array<array-key, mixed> $navigation
	 * @param array<array-key, mixed> $cards
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/personal-view')]
	public function personalView(array $navigation = [], array $cards = []): JSONResponse {
		return $this->run(function (string $uid) use ($navigation, $cards) {
			$this->roles->requireAccess($uid);
			return $this->personalView->set($uid, $navigation, $cards);
		});
	}
}
