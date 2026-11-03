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
 * A link for choir members knows the programme it was shared with as a
 * whole (`cantoria_links.event_id`, review of 3 November 2026, U24), so
 * that "Shared with me" lists the pieces by programme and in programme
 * order. Nullable without a default: links made on their own, and all
 * links made before, have none.
 */
class Version001700Date20261103140000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('cantoria_links')) {
			return null;
		}
		$table = $schema->getTable('cantoria_links');
		if ($table->hasColumn('event_id')) {
			return null;
		}
		$table->addColumn('event_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		return $schema;
	}
}
