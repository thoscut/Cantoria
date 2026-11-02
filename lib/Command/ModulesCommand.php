<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ModuleSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Modules on the command line, the same as in the settings:
 *   occ cantoria:modules list [--json]
 *   occ cantoria:modules enable <module>…   (also switches on what they need)
 *   occ cantoria:modules disable <module>…  (names what stops working)
 *   occ cantoria:modules preset simple|standard|complete
 *   occ cantoria:modules scenario archive|concerts|singers|public
 * Every changed switch is recorded in the audit log (without an account).
 *
 * @psalm-import-type ModuleChanges from ModuleSettings
 */
class ModulesCommand extends Command {
	private const ACTIONS = ['list', 'enable', 'disable', 'preset', 'scenario'];

	public function __construct(
		private Modules $modules,
		private ModuleSettings $moduleSettings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:modules')
			->setDescription('List, switch on and off the modules of the music archive, or apply a preset or a scenario')
			->addArgument('action', InputArgument::REQUIRED, implode('|', self::ACTIONS))
			->addArgument('names', InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
				'modules (enable, disable), the preset (' . implode(', ', Modules::PRESETS) . ') or the scenario ('
				. implode(', ', array_keys(Modules::SCENARIOS)) . ')')
			->addOption('json', null, InputOption::VALUE_NONE, 'list: print JSON');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$names = self::texts($input->getArgument('names'));
		if ($action === 'list') {
			$this->list($output, $input->getOption('json') === true);
			return 0;
		}
		if (!in_array($action, ['enable', 'disable', 'preset', 'scenario'], true)) {
			$output->writeln('<error>Unknown action: ' . $action . ' (' . implode(', ', self::ACTIONS) . ')</error>');
			return 1;
		}
		$single = match ($action) {
			'preset' => Modules::PRESETS,
			'scenario' => array_keys(Modules::SCENARIOS),
			default => null,
		};
		if ($single !== null ? count($names) !== 1 : $names === []) {
			$output->writeln($single !== null
				? '<error>Please name one ' . $action . ': ' . implode(', ', $single) . '</error>'
				: '<error>Please name at least one module.</error>');
			return 1;
		}
		try {
			$changes = match ($action) {
				'preset' => $this->moduleSettings->applyPreset($names[0], null),
				'scenario' => $this->moduleSettings->applyScenario($names[0], null),
				default => $this->moduleSettings->change(array_fill_keys($names, $action === 'enable'), null),
			};
		} catch (InvalidInputException $e) {
			$output->writeln('<error>' . vsprintf($e->getMessage(), array_map('strval', $e->getParameters())) . '</error>');
			return 1;
		}
		$this->report($output, $changes);
		return 0;
	}

	private function list(OutputInterface $output, bool $json): void {
		$active = $this->modules->states();
		$rows = [];
		foreach (array_keys(Modules::MODULES) as $key) {
			$rows[$key] = [
				'enabled' => $this->modules->isSwitchedOn($key),
				'active' => $active[$key],
				'requires' => Modules::MODULES[$key]['requires'],
				'planned' => Modules::isPlanned($key),
			];
		}
		if ($json) {
			$output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		$table = new Table($output);
		$table->setHeaders(['Module', 'Switch', 'Active', 'Requires', 'Name']);
		foreach ($rows as $key => $row) {
			$table->addRow([
				$key,
				$row['planned'] ? 'planned' : ($row['enabled'] ? 'on' : 'off'),
				$row['active'] ? 'yes' : 'no',
				implode(', ', $row['requires']),
				Modules::label($key),
			]);
		}
		$table->render();
	}

	/**
	 * @param ModuleChanges $changes
	 */
	private function report(OutputInterface $output, array $changes): void {
		$lines = [
			'Switched on' => $changes['enabled'],
			'Also switched on (needed)' => $changes['alsoEnabled'],
			'Switched off' => $changes['disabled'],
			'Inactive now, their switches stay on (they need a module that is off)' => $changes['inactive'],
			'Working again' => $changes['activated'],
		];
		$any = false;
		foreach ($lines as $label => $keys) {
			if ($keys !== []) {
				$output->writeln($label . ': ' . implode(', ', $keys));
				$any = true;
			}
		}
		if (!$any) {
			$output->writeln('Nothing changed.');
		}
	}

	/**
	 * @return list<string>
	 */
	private static function texts(mixed $value): array {
		$texts = [];
		if (is_array($value)) {
			foreach (array_keys($value) as $key) {
				if (is_string($value[$key]) && $value[$key] !== '') {
					$texts[] = $value[$key];
				}
			}
		}
		return $texts;
	}
}
