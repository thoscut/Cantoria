<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Changing the module switches from the settings or the command line, with
 * an entry in the audit log for every switch that changed (object type
 * `settings`, field `module_<key>`, values `on`/`off`), and the description
 * of all modules for the settings.
 *
 * @psalm-type ModuleChanges = array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
 */
class ModuleSettings {
	/** Modules whose pages anyone can open without an account */
	public const PUBLIC_MODULES = [Modules::PUBLIC_LINKS, Modules::PUBLIC_REPERTOIRE];

	public function __construct(
		private Modules $modules,
		private AuditLog $audit,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Whether the addresses of the imprint and the privacy policy are set in
	 * Nextcloud's theming: the public pages show them in their footer
	 * (milestone 0.11). The settings warn before public pages go online
	 * without them.
	 *
	 * @return array{imprint: bool, privacy: bool}
	 */
	public function legalPages(): array {
		$set = function (string $key): bool {
			try {
				return trim($this->appConfig->getValueString('theming', $key)) !== '';
			} catch (\Throwable) {
				return false;
			}
		};
		return ['imprint' => $set('imprintUrl'), 'privacy' => $set('privacyUrl')];
	}

	/**
	 * @param array<array-key, mixed> $wanted module key => on (request data)
	 * @return ModuleChanges
	 * @throws InvalidInputException
	 */
	public function change(array $wanted, ?string $uid, bool $admin = true): array {
		if ($wanted === []) {
			throw new InvalidInputException('Please choose a module or a preset.');
		}
		$values = [];
		foreach (array_keys($wanted) as $key) {
			$values[(string)$key] = Input::bool($wanted[$key], (string)$key);
		}
		if (!$admin) {
			$this->requireAdminFor($values);
		}
		return $this->record($this->modules->change($values), $uid, null);
	}

	/**
	 * The modules that open the archive to the outside (agents, choir
	 * members, the public, the print station) are switched on by an
	 * administrator only, also when another module needs them.
	 *
	 * @param array<string, bool> $values
	 * @throws AccessDeniedException
	 */
	private function requireAdminFor(array $values): void {
		foreach (array_keys(array_filter($values)) as $key) {
			foreach ([$key, ...Modules::requirements($key)] as $module) {
				if (in_array($module, Modules::OUTSIDE_PRESETS, true) && !$this->modules->isSwitchedOn($module)) {
					throw new AccessDeniedException('Only an administrator can switch on the modules that open the archive to agents, choir members, the public or the print station.');
				}
			}
		}
	}

	/**
	 * @return ModuleChanges
	 * @throws InvalidInputException
	 */
	public function applyPreset(string $preset, ?string $uid): array {
		return $this->record($this->modules->applyPreset($preset), $uid, $preset);
	}

	/**
	 * @return ModuleChanges
	 * @throws InvalidInputException
	 */
	public function applyScenario(string $scenario, ?string $uid, bool $admin = true): array {
		if (!$admin) {
			$this->requireAdminFor(Modules::scenarioValues($scenario));
		}
		return $this->record($this->modules->applyScenario($scenario), $uid, 'scenario ' . $scenario);
	}

	/**
	 * Everything the settings show, translated: the modules with their
	 * switch (`enabled`) and whether they are active (`active`), the groups,
	 * the presets and the core.
	 *
	 * @return array{modules: list<array{key: string, group: string, label: string, description: string, requires: list<string>, enabled: bool, active: bool}>, groups: list<array{key: string, label: string}>, presets: list<array{key: string, label: string, description: string, modules: array<string, bool>}>, scenarios: list<array{key: string, label: string, description: string, rights: ?string, modules: array<string, bool>}>, core: list<string>, publicModules: list<string>, legalPages: array{imprint: bool, privacy: bool}}
	 */
	public function describe(IL10N $l): array {
		$active = $this->modules->states();
		$modules = [];
		$groups = [];
		foreach (Modules::switchable() as $key) {
			$module = Modules::MODULES[$key];
			$modules[] = [
				'key' => $key,
				'group' => $module['group'],
				'label' => $l->t($module['label']),
				'description' => $l->t($module['description']),
				'requires' => $module['requires'],
				'enabled' => $this->modules->isSwitchedOn($key),
				'active' => $active[$key],
			];
			$groups[$module['group']] = true;
		}
		$presets = [];
		foreach (Modules::PRESETS as $preset) {
			$presets[] = [
				'key' => $preset,
				'label' => $l->t(Modules::PRESET_LABELS[$preset]['label']),
				'description' => $l->t(Modules::PRESET_LABELS[$preset]['description']),
				'modules' => Modules::presetValues($preset),
			];
		}
		$scenarios = [];
		foreach (Modules::SCENARIOS as $key => $scenario) {
			$scenarios[] = [
				'key' => $key,
				'label' => $l->t($scenario['label']),
				'description' => $l->t($scenario['description']),
				'rights' => $scenario['rights'] === null ? null : $l->t($scenario['rights']),
				'modules' => Modules::scenarioValues($key),
			];
		}
		return [
			'modules' => $modules,
			'scenarios' => $scenarios,
			'groups' => array_map(fn (string $group): array => ['key' => $group, 'label' => $l->t(Modules::GROUPS[$group] ?? $group)],
				array_keys($groups)),
			'presets' => $presets,
			'core' => array_map(fn (string $text): string => $l->t($text), Modules::CORE),
			'publicModules' => self::PUBLIC_MODULES,
			'legalPages' => $this->legalPages(),
		];
	}

	/**
	 * @param ModuleChanges $changes
	 * @return ModuleChanges
	 */
	private function record(array $changes, ?string $uid, ?string $preset): array {
		$this->appConfig->setValueBool(Application::APP_ID, Modules::CHOSEN_KEY, true);
		$operation = $this->audit->newOperation();
		$reason = $preset === null || str_starts_with($preset, 'scenario ') ? $preset : 'preset ' . $preset;
		foreach ([...$changes['enabled'], ...$changes['alsoEnabled']] as $key) {
			$this->audit->record($operation, $uid, 'settings', null, 'changed', Modules::CONFIG_PREFIX . $key, 'off', 'on', $reason);
		}
		foreach ($changes['disabled'] as $key) {
			$this->audit->record($operation, $uid, 'settings', null, 'changed', Modules::CONFIG_PREFIX . $key, 'on', 'off', $reason);
		}
		return $changes;
	}
}
