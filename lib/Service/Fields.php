<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

/**
 * Field registry: which fields exist on each level, who may write them and
 * how they are presented.
 *
 * Single source of truth for server-side validation, the generic edit forms
 * in the UI, and import/export. Fields without write roles are read-only:
 * they come from the import or are maintained by the app itself.
 *
 * Labels are English source strings; the UI translates them via l10n.
 * `max` is the column length from the migration (characters); text without
 * `max` is stored as TEXT. `decision` marks fields that record a decision
 * taken by a person: an import may fill them while empty but never
 * overwrites them. `hint` explains a field below its input (English source
 * string).
 *
 * `hosts` limits a text field to an https link to one of these hosts (the
 * links to CPDL and IMSLP shown on the public repertoire page).
 *
 * @psalm-type FieldInfo = array{type: string, label: string, write: list<string>, options?: array<string, string>, max?: int, decision?: bool, hosts?: list<string>, hint?: string}
 */
final class Fields {
	public const WORK = 'work';
	public const VERSION = 'version';
	public const COPY = 'copy';
	public const LEVELS = [self::WORK, self::VERSION, self::COPY];

	public const TYPE_TEXT = 'text';
	public const TYPE_LONGTEXT = 'longtext';
	public const TYPE_NUMBER = 'number';
	public const TYPE_DATE = 'date';
	public const TYPE_CHOICE = 'choice';
	public const TYPE_BOOLEAN = 'boolean';

	public const RIGHTS_STATUS = [
		'public_domain' => 'Public domain',
		'protected' => 'Protected',
		'open' => 'Open',
	];

	public const COPY_STATUS = [
		'active' => 'Active',
		'retired' => 'Retired',
		'missing' => 'Missing',
		'lent' => 'Lent',
	];

	public const VERSION_KIND = [
		'original' => 'Original',
		'adaptation' => 'Adaptation',
		'arrangement' => 'Arrangement',
		'new_engraving' => 'New engraving',
		'manuscript_copy' => 'Manuscript copy',
		'transposition' => 'Transposition',
		'excerpt' => 'Excerpt',
	];

	public const PUBLICATION_STATUS = [
		'free' => 'Released for publication',
		'blocked' => 'Blocked',
		'internal' => 'Internal only',
		'to_clarify' => 'To be clarified',
	];

	public const REVIEW_STATUS = [
		'unchecked' => 'Not reviewed',
		'checked' => 'Reviewed',
		'uncertain' => 'Reviewed with open questions',
	];

	public const RULE1_STATUS = [
		'unchecked' => 'Not checked',
		'board_assumption' => 'Working assumption of the board',
		'original_present' => 'Original present',
		'original_missing' => 'Original missing',
	];

	/** Uses a license can grant, each listed separately */
	public const LICENSE_USES = [
		'binder' => 'Copies for the binders',
		'portal' => 'Singers\' portal',
		'self_print' => 'Self-printing by singers',
		'print_station' => 'Print station',
		'practice_audio' => 'Practice audio',
		'recording' => 'Recording',
		'publication' => 'Publication',
		'online_publication' => 'Online publication (public links)',
		'online_audio' => 'Online playback (audio on public links)',
		'online_download' => 'Download of the scan on public links',
		'member_download' => 'Download, printing and copies on devices by choir members (scan in links, concert folder, hand-over folder)',
		'digital_scores' => 'Passing on digital score files (MusicXML) to choir members (hand-over folder)',
		'recording_sharing' => 'Passing on recordings to choir members (performers and producer agree; hand-over folder)',
		'digitisation' => 'Digitisation (scans, MusicXML transcriptions)',
		'successor' => 'Transfer to a legal successor',
	];

	/**
	 * One-sentence explanations shown below the input of a field: when the
	 * special cases of the protection term apply and what they need.
	 */
	public const HINTS = [
		self::VERSION => [
			'scientific_edition' => 'Switch on for an edition of works that are no longer protected, made by scholarly work and clearly different from earlier editions, e.g. an urtext or critical edition (§ 70 UrhG): it is protected for 25 years after it appeared.',
			'posthumous_work' => 'Switch on for a work whose protection had already ended when it first appeared or was first performed in public, e.g. a rediscovered manuscript (§ 71 UrhG): whoever first published or performed it has a right for 25 years after the earlier of the two.',
			'first_published_year' => 'Year in which the edition appeared (§ 70) or in which the work first appeared or was first performed in public (§ 71), e.g. 1998. Needed for both special cases.',
			'melody_origin' => 'Choose "Traditional" only with evidence, e.g. a folk song collection, and note it under "Notes". Then the protection proposal needs no composer; the arranger still counts, and unless the kind of version is "Original" an arranger must be recorded.',
			'society_work_no' => 'The number of the work at the collecting society, e.g. from the GEMA repertoire search. It goes into the song order of a concert.',
			'text_origin' => 'Choose "Traditional" only with evidence and note it under "Notes", or "Without text" for a version without words. Otherwise the protection proposal needs a lyricist.',
		],
	];

	/** A traditional melody (milestone 0.11); empty: the persons recorded wrote it */
	public const MELODY_ORIGIN = [
		'traditional' => 'Traditional (no known composer)',
	];

	/** A traditional text or none (milestone 0.11); empty: the persons recorded wrote it */
	public const TEXT_ORIGIN = [
		'traditional' => 'Traditional (public domain)',
		'none' => 'Without text',
	];

	public const COPY_KIND = [
		'original' => 'Original print',
		'photocopy' => 'Photocopy',
		'preservation_copy' => 'Preservation copy',
		'licensed_print' => 'Licensed print',
		'manuscript' => 'Manuscript',
	];

	/** Roles of a person in a version (milestone 0.5) */
	public const CONTRIBUTION_ROLES = [
		'composer' => 'Composer',
		'lyricist' => 'Lyricist',
		'arranger' => 'Arranger',
		'translator' => 'Translator',
		'text_editor' => 'Text editor',
		'editor' => 'Editor',
		'unspecified' => 'Role not specified',
	];

	/** Precision of a date of birth or death */
	public const DATE_PRECISION = [
		'exact' => 'Exact date',
		'year' => 'Year',
		'circa' => 'Approximate',
		'living' => 'Living',
		'unknown' => 'Unknown',
	];

	/** How sure it is that a name means this person */
	public const IDENTIFICATION = [
		'certain' => 'Identified for certain',
		'probable' => 'Probably identified',
		'uncertain' => 'Identification uncertain',
	];

	/**
	 * Fields of a person in the register. Not part of all(): persons are no
	 * level of the catalog and not part of the catalog CSV.
	 *
	 * @return array<string, FieldInfo>
	 */
	public static function person(): array {
		$librarian = [Roles::LIBRARIAN];
		$readOnly = [];
		$precisionBorn = array_diff_key(self::DATE_PRECISION, ['living' => true]);
		return [
			'name' => self::field(self::TYPE_TEXT, 'Name', $librarian) + ['max' => 255],
			'gnd' => self::field(self::TYPE_TEXT, 'GND number', $librarian) + ['max' => 20],
			'wikidata' => self::field(self::TYPE_TEXT, 'Wikidata ID', $librarian) + ['max' => 20],
			'born' => self::field(self::TYPE_TEXT, 'Born', $librarian) + ['max' => 10],
			'born_precision' => self::field(self::TYPE_CHOICE, 'Precision of the date of birth', $librarian, $precisionBorn),
			'died' => self::field(self::TYPE_TEXT, 'Died', $librarian) + ['max' => 10],
			'died_precision' => self::field(self::TYPE_CHOICE, 'Precision of the date of death', $librarian, self::DATE_PRECISION),
			'life_dates_text' => self::field(self::TYPE_TEXT, 'Life dates as found', $librarian) + ['max' => 255],
			'source' => self::field(self::TYPE_TEXT, 'Source of the life dates', $librarian) + ['max' => 1000],
			'identification' => self::field(self::TYPE_CHOICE, 'Identification', $librarian, self::IDENTIFICATION),
			'checked_by' => self::field(self::TYPE_TEXT, 'Checked by', $readOnly),
			'checked_at' => self::field(self::TYPE_DATE, 'Checked on', $readOnly),
			'notes' => self::field(self::TYPE_LONGTEXT, 'Notes', $librarian),
		];
	}

	/** Column lengths from the migration: level => field => characters */
	private const MAX_LENGTH = [
		self::WORK => [
			'work_no' => 20, 'title' => 500, 'composer' => 500, 'repertoire_import' => 40,
			'performances_import' => 20, 'last_performed_import' => 20, 'song_lists_import' => 1000,
			'cpdl_url' => 500, 'imslp_url' => 500,
		],
		self::VERSION => [
			'kind' => 32, 'arranger_text' => 1000, 'relation' => 1000, 'rights_status_import' => 32,
			'rights_status' => 32, 'rights_confirmed' => 500, 'portal_since' => 40, 'publisher' => 1000,
			'license' => 1000, 'proof_of_purchase' => 1000, 'musicxml_file' => 1000, 'musicxml_valid' => 1000,
			'musicxml_date' => 40, 'publication' => 200, 'valid_since' => 10, 'valid_set_by' => 200,
			'publication_status' => 32, 'review_status' => 32, 'rule1_status' => 32, 'rule1_checked' => 255,
			'confirmed_by' => 64, 'confirmed_by_name' => 255, 'confirmed_at' => 10, 'portal_removed_at' => 10,
			'portal_removed_reason' => 1000, 'valid_reason' => 1000, 'key_signature' => 40, 'scoring' => 200,
			'language' => 100, 'melody_origin' => 16, 'text_origin' => 16, 'society_work_no' => 32,
		],
		self::COPY => [
			'shelfmark' => 20, 'status' => 20, 'status_reason' => 1000, 'status_since' => 10, 'kind' => 32,
			'last_counted' => 10, 'title_source' => 500, 'pages' => 20, 'versions_count' => 20, 'scan_formats' => 100,
		],
	];

	/** Fields that record decisions taken by people */
	private const DECISIONS = [
		self::VERSION => ['rights_confirmed', 'portal_since', 'valid_since', 'valid_set_by'],
	];

	/** @var array<string, array<string, FieldInfo>>|null */
	private static ?array $cache = null;

	/** Fields that must never be NULL in the database */
	public const NOT_NULL = [
		self::COPY => ['binder', 'number', 'shelfmark', 'status'],
	];

	/**
	 * @return array<string, array<string, FieldInfo>>
	 */
	public static function all(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}
		$all = self::definitions();
		foreach ($all as $level => &$fields) {
			foreach ($fields as $field => &$info) {
				if (isset(self::MAX_LENGTH[$level][$field])) {
					$info['max'] = self::MAX_LENGTH[$level][$field];
				}
				if (in_array($field, self::DECISIONS[$level] ?? [], true)) {
					$info['decision'] = true;
				}
				if (isset(self::HINTS[$level][$field])) {
					$info['hint'] = self::HINTS[$level][$field];
				}
			}
			unset($info);
		}
		unset($fields);
		return self::$cache = $all;
	}

	public static function isNotNull(string $level, string $field): bool {
		return in_array($field, self::NOT_NULL[$level] ?? [], true);
	}

	/**
	 * @return array<string, array<string, FieldInfo>>
	 */
	private static function definitions(): array {
		$librarian = [Roles::LIBRARIAN];
		$librarianOperations = [Roles::LIBRARIAN, Roles::OPERATIONS];
		$librarianConductor = [Roles::LIBRARIAN, Roles::CONDUCTOR];
		$readOnly = [];

		return [
			self::WORK => [
				'work_no' => self::field(self::TYPE_TEXT, 'Work no.', $librarian),
				'title' => self::field(self::TYPE_TEXT, 'Title', $librarian),
				'composer' => self::field(self::TYPE_TEXT, 'Composer', $librarian),
				'notes' => self::field(self::TYPE_LONGTEXT, 'Notes on the work', $librarian),
				'repertoire_import' => self::field(self::TYPE_TEXT, 'Repertoire', $readOnly),
				'performances_import' => self::field(self::TYPE_TEXT, 'Documented performances', $readOnly),
				'last_performed_import' => self::field(self::TYPE_TEXT, 'Last performed', $readOnly),
				'song_lists_import' => self::field(self::TYPE_TEXT, 'Song lists', $readOnly),
				// Public repertoire page (milestone 0.7): shown there only while a
				// version of the work is confirmed public domain (CatalogService
				// refuses it otherwise); links only, nothing is loaded from there
				'public_repertoire' => self::field(self::TYPE_BOOLEAN, 'On the public repertoire page', $librarian),
				'cpdl_url' => self::field(self::TYPE_TEXT, 'Link to CPDL', $librarian) + ['hosts' => ['cpdl.org', 'www.cpdl.org']],
				'imslp_url' => self::field(self::TYPE_TEXT, 'Link to IMSLP', $librarian) + ['hosts' => ['imslp.org', 'www.imslp.org']],
			],
			self::VERSION => [
				'kind' => self::field(self::TYPE_CHOICE, 'Kind of version', $librarian, self::VERSION_KIND),
				'arranger_text' => self::field(self::TYPE_TEXT, 'Arranger / lyrics', $librarian),
				'relation' => self::field(self::TYPE_TEXT, 'Relation to other shelf marks', $librarian),
				'notes' => self::field(self::TYPE_LONGTEXT, 'Notes', $librarian),
				'rights_assessment' => self::field(self::TYPE_LONGTEXT, 'Initial rights assessment', $readOnly),
				'rights_status_import' => self::field(self::TYPE_CHOICE, 'Rights status at first import', $readOnly, self::RIGHTS_STATUS),
				'rights_status' => self::field(self::TYPE_CHOICE, 'Rights status', $librarian, self::RIGHTS_STATUS),
				'rights_confirmed' => self::field(self::TYPE_TEXT, 'Rights confirmed', $readOnly),
				'portal_since' => self::field(self::TYPE_TEXT, 'In singers\' portal since', $readOnly),
				'publisher' => self::field(self::TYPE_TEXT, 'Publisher / order no.', $librarian),
				'society_work_no' => self::field(self::TYPE_TEXT, 'Work number (GEMA)', $librarian),
				'license' => self::field(self::TYPE_TEXT, 'License', $librarian),
				'proof_of_purchase' => self::field(self::TYPE_TEXT, 'Proof of purchase', $librarian),
				'preservation_copy' => self::field(self::TYPE_BOOLEAN, 'Internal preservation copy', $librarian),
				'musicxml_file' => self::field(self::TYPE_TEXT, 'MusicXML', $librarian),
				'musicxml_valid' => self::field(self::TYPE_TEXT, 'Valid MusicXML', $librarian),
				'musicxml_date' => self::field(self::TYPE_TEXT, 'MusicXML date', $librarian),
				'musicxml_origin' => self::field(self::TYPE_LONGTEXT, 'MusicXML origin', $librarian),
				'musicxml_review' => self::field(self::TYPE_LONGTEXT, 'MusicXML review', $librarian),
				'publication' => self::field(self::TYPE_TEXT, 'Publication', $librarian),
				'publication_status' => self::field(self::TYPE_CHOICE, 'Publication status', $librarian, self::PUBLICATION_STATUS),
				'publication_reason' => self::field(self::TYPE_LONGTEXT, 'Publication reasoning', $librarian),
				'review_status' => self::field(self::TYPE_CHOICE, 'Review of the transcription', $librarianConductor, self::REVIEW_STATUS),
				'rule1_status' => self::field(self::TYPE_CHOICE, 'Original for the preservation copy', $librarian, self::RULE1_STATUS),
				'rule1_count' => self::field(self::TYPE_NUMBER, 'Number of originals in the archive', $librarian),
				'rule1_checked' => self::field(self::TYPE_TEXT, 'Original checked', $readOnly),
				'confirmed_by' => self::field(self::TYPE_TEXT, 'Confirmed by (account)', $readOnly),
				'confirmed_by_name' => self::field(self::TYPE_TEXT, 'Confirmed by', $readOnly),
				'confirmed_at' => self::field(self::TYPE_DATE, 'Confirmed on', $readOnly),
				'confirmed_reason' => self::field(self::TYPE_LONGTEXT, 'Reason for the confirmation', $readOnly),
				'confirmed_evidence' => self::field(self::TYPE_LONGTEXT, 'Evidence for the confirmation', $readOnly),
				'portal_removed_at' => self::field(self::TYPE_DATE, 'Removed from singers\' portal on', $readOnly),
				'portal_removed_reason' => self::field(self::TYPE_TEXT, 'Reason for removal from singers\' portal', $readOnly),
				'valid_since' => self::field(self::TYPE_DATE, 'Valid for the choir since', $readOnly),
				'valid_set_by' => self::field(self::TYPE_TEXT, 'Valid version set by', $readOnly),
				'valid_reason' => self::field(self::TYPE_TEXT, 'Reason for the valid version', $readOnly),
				'key_signature' => self::field(self::TYPE_TEXT, 'Key', $librarianConductor),
				'scoring' => self::field(self::TYPE_TEXT, 'Scoring', $librarianConductor),
				'language' => self::field(self::TYPE_TEXT, 'Language', $librarianConductor),
				'duration' => self::field(self::TYPE_NUMBER, 'Duration (seconds)', $librarianConductor),
				// Special cases of the protection term (milestone 0.8, module protection_terms)
				'scientific_edition' => self::field(self::TYPE_BOOLEAN, 'Scientific edition (§ 70 UrhG)', $librarian),
				'posthumous_work' => self::field(self::TYPE_BOOLEAN, 'Posthumous work (§ 71 UrhG)', $librarian),
				'first_published_year' => self::field(self::TYPE_NUMBER, 'Year first published', $librarian),
				// Traditional parts (milestone 0.11, module protection_terms)
				'melody_origin' => self::field(self::TYPE_CHOICE, 'Melody', $librarian, self::MELODY_ORIGIN),
				'text_origin' => self::field(self::TYPE_CHOICE, 'Text', $librarian, self::TEXT_ORIGIN),
			],
			self::COPY => [
				'binder' => self::field(self::TYPE_NUMBER, 'Binder', $readOnly),
				'number' => self::field(self::TYPE_NUMBER, 'No.', $readOnly),
				'shelfmark' => self::field(self::TYPE_TEXT, 'Shelf mark', $readOnly),
				'status' => self::field(self::TYPE_CHOICE, 'Status', $librarianOperations, self::COPY_STATUS),
				'status_reason' => self::field(self::TYPE_TEXT, 'Reason for status', $librarianOperations),
				'status_since' => self::field(self::TYPE_DATE, 'Status since', $readOnly),
				'kind' => self::field(self::TYPE_CHOICE, 'Kind of copy', $librarianOperations, self::COPY_KIND),
				'originals_count' => self::field(self::TYPE_NUMBER, 'Number of originals', $librarianOperations),
				'copies_count' => self::field(self::TYPE_NUMBER, 'Number of copies', $librarianOperations),
				'last_counted' => self::field(self::TYPE_DATE, 'Last counted', $librarianOperations),
				'title_source' => self::field(self::TYPE_TEXT, 'Title source', $librarian),
				'pages' => self::field(self::TYPE_TEXT, 'Pages', $librarian),
				'versions_count' => self::field(self::TYPE_TEXT, 'Versions (catalog)', $readOnly),
				'pdf_files' => self::field(self::TYPE_LONGTEXT, 'PDF files (catalog)', $readOnly),
				'scan_formats' => self::field(self::TYPE_TEXT, 'Formats of original scans', $readOnly),
				'scan_files' => self::field(self::TYPE_LONGTEXT, 'Original scans', $readOnly),
			],
		];
	}

	/**
	 * @return FieldInfo|null
	 */
	public static function info(string $level, string $field): ?array {
		return self::all()[$level][$field] ?? null;
	}

	/**
	 * @param list<string> $write
	 * @param array<string, string> $options
	 * @return FieldInfo
	 */
	private static function field(string $type, string $label, array $write, array $options = []): array {
		$info = ['type' => $type, 'label' => $label, 'write' => $write];
		if ($options !== []) {
			$info['options'] = $options;
		}
		return $info;
	}
}
