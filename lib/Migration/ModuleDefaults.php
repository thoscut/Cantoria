<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use OCA\Cantoria\Service\Modules;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Writes every module switch that has no stored value yet (appinfo/info.xml:
 * on installation and after every update): a new installation gets the
 * preset "standard", and a module added by a later version gets its
 * default. Switches that exist already are never changed, so that a later
 * change of the defaults switches nothing at an existing installation.
 */
class ModuleDefaults implements IRepairStep {
	public function __construct(
		private Modules $modules,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Store the module switches of the music archive';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$written = $this->modules->writeBaseline();
		if ($written !== []) {
			$output->info(count($written) . ' module switches stored.');
		}
	}
}
