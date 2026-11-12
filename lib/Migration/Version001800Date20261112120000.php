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
 * Facts read from the scores (November 2026): `cantoria_score_facts` keeps,
 * per MusicXML file of the archive, what ScoreFacts read from it (ranges of
 * the voices, first tones, keys, incipit, lyrics) with the checksum of the
 * content it was read from. The table can be rebuilt from the files at any
 * time and is not part of the backup.
 *
 * A version gets a translation of its lyrics for the programme booklet
 * (`lyrics_translation`) and who translated it and where it comes from
 * (`translation_source`). Nullable without a default.
 */
class Version001800Date20261112120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('cantoria_score_facts')) {
			$t = $schema->createTable('cantoria_score_facts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('archive_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('format', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('analyzed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('facts', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['archive_file_id'], 'cantoria_facts_file');
			$changed = true;
		}
		if ($schema->hasTable('cantoria_versions')) {
			$table = $schema->getTable('cantoria_versions');
			if (!$table->hasColumn('lyrics_translation')) {
				$table->addColumn('lyrics_translation', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
			if (!$table->hasColumn('translation_source')) {
				$table->addColumn('translation_source', Types::STRING, ['notnull' => false, 'length' => 500]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
