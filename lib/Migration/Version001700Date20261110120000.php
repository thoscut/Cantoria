<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hired material (round 3, November 2026): a copy records who it is hired
 * from (`hired_from`) and the day it must be back (`return_date`, YYYY-MM-DD).
 * Nullable without a default: the copies of the choir itself have neither.
 */
class Version001700Date20261110120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('cantoria_copies')) {
			return null;
		}
		$table = $schema->getTable('cantoria_copies');
		$changed = false;
		if (!$table->hasColumn('hired_from')) {
			$table->addColumn('hired_from', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}
		if (!$table->hasColumn('return_date')) {
			$table->addColumn('return_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
