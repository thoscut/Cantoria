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
 * The tables of the app as of the first release (milestone 0.17). The
 * migrations of the internal builds up to 0.16.1 are folded into this one
 * step; an instance that ran them already has every table and skips it.
 *
 * From the first release on, a migration that was released is never changed
 * again: a change of the schema is a new migration
 * (tests/Unit/Migration/ReleasedMigrationsTest.php).
 *
 * Text columns are nullable (empty = NULL) because Oracle treats empty strings
 * as NULL. Boolean columns must be nullable for the same reason.
 */
class Version001700Date20261030000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('cantoria_audit')) {
			$t = $schema->createTable('cantoria_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('operation', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('time', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('user_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('roles', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('object_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('field', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('old_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('new_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['object_type', 'object_id'], 'cantoria_audit_obj');
			$t->addIndex(['operation'], 'cantoria_audit_op');
		}

		if (!$schema->hasTable('cantoria_contributions')) {
			$t = $schema->createTable('cantoria_contributions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'unspecified']);
			$t->addColumn('text_for_composition', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id'], 'cantoria_contrib_ver');
			$t->addIndex(['person_id'], 'cantoria_contrib_person');
		}

		if (!$schema->hasTable('cantoria_copies')) {
			$t = $schema->createTable('cantoria_copies');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('binder', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('shelfmark', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'active']);
			$t->addColumn('status_reason', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('status_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('originals_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('copies_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('last_counted', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('title_source', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('pages', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('versions_count', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('pdf_files', Types::TEXT, ['notnull' => false]);
			$t->addColumn('scan_formats', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('scan_files', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['shelfmark'], 'cantoria_copy_shelf');
			$t->addIndex(['version_id'], 'cantoria_copy_ver');
		}

		if (!$schema->hasTable('cantoria_event_items')) {
			$t = $schema->createTable('cantoria_event_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('event_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('work_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('title_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('composer_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('participation', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('assignment', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'certain']);
			$t->addColumn('candidates', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('state', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('counts', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sung', Types::BOOLEAN, ['notnull' => false]);
			$t->addColumn('struck', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('encore', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('duration', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('state_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['event_id', 'position'], 'cantoria_item_event');
			$t->addIndex(['work_id'], 'cantoria_item_work');
			$t->addIndex(['assignment'], 'cantoria_item_assign');
		}

		if (!$schema->hasTable('cantoria_events')) {
			$t = $schema->createTable('cantoria_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'concert']);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('event_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('period', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('is_public', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('board_agreed', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('imported', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('source', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('start_time', Types::STRING, ['notnull' => false, 'length' => 5]);
			$t->addColumn('end_time', Types::STRING, ['notnull' => false, 'length' => 5]);
			$t->addColumn('time_zone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['kind', 'event_date'], 'cantoria_event_date');
		}

		if (!$schema->hasTable('cantoria_files')) {
			$t = $schema->createTable('cantoria_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$t->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('mtime', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('listed', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('musicxml_valid', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('found', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('linked_manually', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sha256_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('sha256_mismatch', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('rights_notice', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('rights_read_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['file_id'], 'cantoria_file_fileid');
			$t->addIndex(['copy_id'], 'cantoria_file_copy');
		}

		if (!$schema->hasTable('cantoria_folder_loans')) {
			$t = $schema->createTable('cantoria_folder_loans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('open_folder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('holder_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('holder_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('issued_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('issue_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('returned_on', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('return_wear', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('return_missing', Types::TEXT, ['notnull' => false]);
			$t->addColumn('return_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('anonymized', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['folder_id'], 'cantoria_floan_folder');
			$t->addUniqueIndex(['open_folder_id'], 'cantoria_floan_open');
			$t->addIndex(['returned_on'], 'cantoria_floan_returned');
		}

		if (!$schema->hasTable('cantoria_folders')) {
			$t = $schema->createTable('cantoria_folders');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('contents', Types::TEXT, ['notnull' => false]);
			$t->addColumn('wear', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'good']);
			$t->addColumn('missing', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('status_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('cantoria_licenses')) {
			$t = $schema->createTable('cantoria_licenses');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('holder', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('uses', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('quantity', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('valid_from', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_until', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('share_notice', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id'], 'cantoria_lic_ver');
		}

		if (!$schema->hasTable('cantoria_links')) {
			$t = $schema->createTable('cantoria_links');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('tempo', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('circle', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'internal']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('revoked', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('hits', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('scan_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('token_secret', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token'], 'cantoria_link_token');
			$t->addIndex(['copy_id'], 'cantoria_link_copy');
		}

		if (!$schema->hasTable('cantoria_merges')) {
			$t = $schema->createTable('cantoria_merges');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('moved_ids', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('undone', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('undone_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['kind', 'source_id'], 'cantoria_merge_source');
			$t->addIndex(['kind', 'target_id'], 'cantoria_merge_target');
		}

		if (!$schema->hasTable('cantoria_mirror_files')) {
			$t = $schema->createTable('cantoria_mirror_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('source_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('written', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('mirror', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'print']);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['mirror', 'name'], 'cantoria_mirr_mirror_name');
		}

		if (!$schema->hasTable('cantoria_persons')) {
			$t = $schema->createTable('cantoria_persons');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('gnd', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('wikidata', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('born', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('born_precision', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('died', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('died_precision', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('life_dates_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('identification', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('checked_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('checked_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['name'], 'cantoria_person_name');
		}

		if (!$schema->hasTable('cantoria_proposals')) {
			$t = $schema->createTable('cantoria_proposals');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('object_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('new_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('decided', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('decision_reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status'], 'cantoria_prop_status');
			$t->addIndex(['object_type', 'object_id'], 'cantoria_prop_obj');
		}

		if (!$schema->hasTable('cantoria_review_points')) {
			$t = $schema->createTable('cantoria_review_points');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('report_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('measures', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('page', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('point_key', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('correction', Types::TEXT, ['notnull' => false]);
			$t->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('decided_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('decided', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('decision_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('resolved_report_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id', 'status'], 'cantoria_rpt_version');
			$t->addIndex(['copy_id', 'status'], 'cantoria_rpt_copy');
			$t->addIndex(['report_id'], 'cantoria_rpt_report');
			$t->addIndex(['status'], 'cantoria_rpt_status');
		}

		if (!$schema->hasTable('cantoria_review_reports')) {
			$t = $schema->createTable('cantoria_review_reports');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('report_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'api']);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('round', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('report_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('basename', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('musicxml_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('musicxml_sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('method', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('origin', Types::TEXT, ['notnull' => false]);
			$t->addColumn('review', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reasoning', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_class', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$t->addColumn('raw', Types::TEXT, ['notnull' => false]);
			$t->addColumn('superseded_by', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['copy_id', 'sha256'], 'cantoria_rrep_copy');
			$t->addIndex(['version_id'], 'cantoria_rrep_version');
			$t->addIndex(['report_file_id'], 'cantoria_rrep_file');
		}

		if (!$schema->hasTable('cantoria_states')) {
			$t = $schema->createTable('cantoria_states');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'sync']);
			$t->addColumn('measures', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('voices', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('changes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('valid_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_set_by', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['version_id', 'number'], 'cantoria_state_number');
			$t->addIndex(['file_id'], 'cantoria_state_file');
		}

		if (!$schema->hasTable('cantoria_tasks')) {
			$t = $schema->createTable('cantoria_tasks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'question']);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('page', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('addressee', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('origin', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('question', Types::TEXT, ['notnull' => false]);
			$t->addColumn('answer', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('due', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('answered_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('answered_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('answered', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('closed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('closed', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('closing_note', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status', 'addressee'], 'cantoria_task_status');
			$t->addIndex(['copy_id'], 'cantoria_task_copy');
		}

		if (!$schema->hasTable('cantoria_versions')) {
			$t = $schema->createTable('cantoria_versions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('work_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('arranger_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('relation', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_assessment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_status_import', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rights_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rights_confirmed', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('portal_since', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('publisher', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('license', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('proof_of_purchase', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('preservation_copy', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('musicxml_file', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('musicxml_valid', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('musicxml_date', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('musicxml_origin', Types::TEXT, ['notnull' => false]);
			$t->addColumn('musicxml_review', Types::TEXT, ['notnull' => false]);
			$t->addColumn('publication', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('publication_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('valid_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_set_by', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('confirmed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('confirmed_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('confirmed_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('confirmed_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('confirmed_evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('portal_removed_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('portal_removed_reason', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('publication_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('review_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rule1_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rule1_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('rule1_checked', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('valid_reason', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('key_signature', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('scoring', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('duration', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('scientific_edition', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('posthumous_work', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('first_published_year', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('melody_origin', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('text_origin', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('society_work_no', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['work_id'], 'cantoria_ver_work');
		}

		if (!$schema->hasTable('cantoria_works')) {
			$t = $schema->createTable('cantoria_works');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('work_no', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('composer', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('repertoire_import', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('performances_import', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_performed_import', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('song_lists_import', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('merged_into', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('public_repertoire', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('cpdl_url', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('imslp_url', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->setPrimaryKey(['id']);
		}

		// Information on a person (art. 15 GDPR) searches the log by account;
		// also for an instance that ran the migrations of the internal builds
		$audit = $schema->getTable('cantoria_audit');
		if (!$audit->hasIndex('cantoria_audit_user')) {
			$audit->addIndex(['user_id'], 'cantoria_audit_user');
		}

		return $schema;
	}
}
