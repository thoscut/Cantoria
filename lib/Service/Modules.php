<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCP\IAppConfig;

/**
 * Functions outside the core that the administration can switch off
 * (ROADMAP, guideline 12 and milestone 0.5.1).
 *
 * The core is always on: catalog (works, versions, copies, fields, search,
 * status), start page, file sync, roles, audit log, CSV import and export,
 * backup and restore, settings.
 *
 * Rules:
 * - Switching off never makes anything less safe. The rights policy
 *   (playback, practice audio, preservation copies) applies whatever is
 *   switched on; a switched-off module only removes functions.
 * - Switching off never deletes data. Switching on shows everything again.
 * - A module is active when its own switch is on and every module it
 *   requires is active. Switching a module off leaves the switches of the
 *   modules that need it as they are: they are inactive until it is on
 *   again. Switching a module on also switches on what it requires.
 *
 * Each switch is a typed boolean in the app config (`module_<key>`). The
 * repair step ModuleDefaults writes all of them on installation and update,
 * so that a later change of the defaults switches nothing at existing
 * installations. A missing value falls back to the preset "standard".
 */
class Modules {
	public const SIMPLE = 'simple';
	public const STANDARD = 'standard';
	public const COMPLETE = 'complete';
	public const PRESETS = [self::SIMPLE, self::STANDARD, self::COMPLETE];

	/** The interface for agents (OCS API v1, milestone 0.6; see docs/api-v1.md) */
	public const AGENT_API = 'agent_api';

	/** Links for choir members and their practice audio, public links and the public repertoire page (milestone 0.7) */
	public const MEMBER_LINKS = 'member_links';
	public const MEMBER_PRACTICE_AUDIO = 'member_practice_audio';
	public const PUBLIC_LINKS = 'public_links';
	public const PUBLIC_REPERTOIRE = 'public_repertoire';
	/** Pieces shared with choir members kept on their device, for practising without a network (milestone 0.15) */
	public const OFFLINE = 'offline';
	/** The concert folder of the conductor, also without a network (milestone 0.15) */
	public const CONCERT_FOLDER = 'concert_folder';
	/** The mirror folder of the print station (milestone 0.7): writes files outside the archive folder */
	public const PRINT_MIRROR = 'print_mirror';
	/** The hand-over folder for a choir system (milestone 0.12): writes files outside the archive folder */
	public const CHOIR_HANDOVER = 'choir_handover';
	/** The folder book (milestone 0.7): issuing and taking back choir folders */
	public const FOLDER_BOOK = 'folder_book';

	/**
	 * Modules that no preset switches on or off; only their own switch
	 * changes them. The interface for agents is an access path for tools,
	 * not a function for people: the administration switches it on on
	 * purpose, and a preset chosen later neither opens nor closes it. The
	 * same holds for every function that opens the archive beyond its roles
	 * (choir members, the public) and for the mirror folder of the print
	 * station and the hand-over folder for a choir system, which write files
	 * (an exception to guideline 4): the choir decides on it, then the
	 * administration switches it on.
	 */
	public const OUTSIDE_PRESETS = [self::AGENT_API, self::MEMBER_LINKS, self::MEMBER_PRACTICE_AUDIO, self::PUBLIC_LINKS, self::PUBLIC_REPERTOIRE,
		self::OFFLINE, self::CONCERT_FOLDER, self::PRINT_MIRROR, self::CHOIR_HANDOVER];
	/** The action "Open in Cantoria" in the Files app */
	public const FILES_INTEGRATION = 'files_integration';
	/** Copies in the search of Nextcloud (milestone 0.8) */
	public const UNIFIED_SEARCH = 'unified_search';
	/** Annual snapshots of the archive and their comparison (milestone 0.8) */
	public const ANNUAL_SNAPSHOT = 'annual_snapshot';
	/** Revisions of a version: corrections and changed MusicXML, the current revision, comparing two (milestone 0.8) */
	public const VERSION_STATES = 'version_states';

	/** Switches of the notifications: this prefix plus the subject (NotificationService) */
	public const NOTIFY_PREFIX = 'notify_';

	public const CONFIG_PREFIX = 'module_';
	/** Set once the administration changed the modules (a scenario, a preset or a switch): the step of the setup is done */
	public const CHOSEN_KEY = 'modules_chosen';

	/** Reasons in the score view (English source strings, translated by the controller) */
	public const PLAYBACK_OFF = 'Playback is switched off.';
	public const PRACTICE_AUDIO_OFF = 'Practice audio files are switched off.';

	/** Groups of the admin settings, in this order (English labels) */
	public const GROUPS = [
		'rights' => 'Rights and persons',
		'releases' => 'Releases',
		'scores' => 'Score view',
		'collaboration' => 'Working together',
		'concerts' => 'Concerts and repertoire',
		'maintenance' => 'Maintaining the catalog',
		'overview' => 'Overview',
		'notifications' => 'Notifications',
		'sharing' => 'Sharing beyond the archive roles',
		'interfaces' => 'Interfaces',
		'planned' => 'Planned',
	];

	/** The core, always on (English labels for the settings and the documentation) */
	public const CORE = [
		'Catalog: works, versions, copies, fields, search and status',
		'Start page with what is due',
		'File sync with the archive folder',
		'Roles and groups',
		'Audit log',
		'CSV import and export',
		'Backup and restore',
		'Settings',
	];

	/** English labels and descriptions of the presets */
	public const PRESET_LABELS = [
		self::SIMPLE => ['label' => 'Simple', 'description' => 'Catalog, files and the score view with playback.'],
		self::STANDARD => ['label' => 'Standard', 'description' => 'For most choirs: everything except practice audio files, the print station, the proposals inbox, checksums, the folder book, annual snapshots and statistics.'],
		self::COMPLETE => ['label' => 'Complete', 'description' => 'All functions. The interface for agents, the sharing with choir members and the public, practising without a network, the concert folder and the folders for the print station and a choir system are switched on separately.'],
	];

	/**
	 * What Cantoria should do for a choir (milestone 0.19), in words instead
	 * of module names: a preset, and the modules outside the presets that the
	 * scenario switches on or off. Choosing one also switches on what they
	 * require. `rights` says what the rights must allow for it to work.
	 * English source strings.
	 *
	 * @var array<string, array{preset: string, modules: array<string, bool>, label: string, description: string, rights: ?string}>
	 */
	public const SCENARIOS = [
		'archive' => ['preset' => self::SIMPLE,
			'modules' => [self::CONCERT_FOLDER => false, self::MEMBER_LINKS => false, self::MEMBER_PRACTICE_AUDIO => false, self::OFFLINE => false,
				self::PUBLIC_LINKS => false, self::PUBLIC_REPERTOIRE => false],
			'label' => 'Keep the archive in order',
			'description' => 'Catalog, files and the score view with playback, for the music librarians.',
			'rights' => null],
		'concerts' => ['preset' => self::STANDARD,
			'modules' => [self::CONCERT_FOLDER => true, self::MEMBER_LINKS => false, self::MEMBER_PRACTICE_AUDIO => false, self::OFFLINE => false,
				self::PUBLIC_LINKS => false, self::PUBLIC_REPERTOIRE => false],
			'label' => 'Archive, rights and concerts',
			'description' => 'Adds rights and licenses, releases, concerts and song lists, and the concert folder for the conductor. Nothing leaves the archive roles.',
			'rights' => null],
		'singers' => ['preset' => self::STANDARD,
			'modules' => [self::CONCERT_FOLDER => true, self::MEMBER_LINKS => true, self::MEMBER_PRACTICE_AUDIO => true, self::OFFLINE => true,
				self::PUBLIC_LINKS => false, self::PUBLIC_REPERTOIRE => false],
			'label' => 'Also practising for the singers',
			'description' => 'Adds the page "Shared with me" for the choir members: the pieces shared with them, to play and practise on their phone, also without a network.',
			'rights' => 'Choir members see only versions released for the singers\' portal. Keeping a piece on a device needs confirmed public domain or a license for download or self-printing. The role "Choir members" needs a group.'],
		'public' => ['preset' => self::STANDARD,
			'modules' => [self::CONCERT_FOLDER => true, self::MEMBER_LINKS => true, self::MEMBER_PRACTICE_AUDIO => true, self::OFFLINE => true,
				self::PUBLIC_LINKS => true, self::PUBLIC_REPERTOIRE => true],
			'label' => 'Also public pages',
			'description' => 'Adds public links without login and a public page with the repertoire.',
			'rights' => 'Only for confirmed public domain or a license for online publication. Enter the imprint and the privacy policy under Administration → Theming first.'],
	];

	/**
	 * The register, in the order of the settings. `requires`: modules that
	 * must be active; `simple` and `standard`: switched on by these presets
	 * ("complete" switches on everything except OUTSIDE_PRESETS); `planned`:
	 * not built yet (none at the moment). Labels and descriptions are English
	 * source strings.
	 */
	public const MODULES = [
		'rights' => ['group' => 'rights', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Rights confirmation and licenses',
			'description' => 'Confirming the rights status of a version with reason and evidence, licenses with their uses and term, the list of ending licenses. Switched off, nothing is played or released without a license or confirmation all the same.'],
		'valid_version' => ['group' => 'rights', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Valid version',
			'description' => 'The conductor chooses the version that is valid for the choir, with its history.'],
		'persons' => ['group' => 'rights', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Register of persons',
			'description' => 'Persons with life dates and their contributions to versions (composer, lyrics, arrangement, translation); import of a research list.'],
		'protection_terms' => ['group' => 'rights', 'requires' => ['persons'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Protection terms',
			'description' => 'Proposal for the end of the protection term from the life dates of all persons involved, with the special cases scientific edition (§ 70 UrhG) and posthumous work (§ 71 UrhG) and a resubmission list. A proposal only, no legal advice.'],
		'releases' => ['group' => 'releases', 'requires' => ['rights'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Releases',
			'description' => 'Separate releases of a version for the singers\' portal, the print station and publication, derived from the confirmed rights and the licenses.'],
		'portal' => ['group' => 'releases', 'requires' => ['releases'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Singers\' portal',
			'description' => 'Target and actual list of the singers\' portal: what to add and what to remove.'],
		'print_station' => ['group' => 'releases', 'requires' => ['releases'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Print station',
			'description' => 'Target list of the print station.'],
		self::PRINT_MIRROR => ['group' => 'releases', 'requires' => ['print_station'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Mirror folder for the print station',
			'description' => 'Copies the PDF scans of the versions on the list of the print station into a target folder of its own, daily and on request, and removes those no longer released. Writes only into this folder, never into the archive folder, and removes only files it wrote itself. Not part of any preset.'],
		self::CHOIR_HANDOVER => ['group' => 'releases', 'requires' => ['portal'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Hand-over to the choir system',
			'description' => 'Puts the scans (PDF), MusicXML and practice audio files of the versions released for the singers\' portal into a hand-over folder of its own, with a list (version line and license notice per file) and the notes of the conductor per passage as text, daily and on request. Someone of the choir uploads them to the choir system; the app uploads nothing to a provider. Writes only into this folder and removes only files it wrote itself. Not part of any preset.'],
		'publication' => ['group' => 'releases', 'requires' => ['releases'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Publication',
			'description' => 'Publication status of a version with its reasoning.'],
		'score_viewer' => ['group' => 'scores', 'requires' => [], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Score view',
			'description' => 'Shows MusicXML in the browser with printed measure numbers, measure ranges, the chosen voice highlighted and a text description.'],
		'playback' => ['group' => 'scores', 'requires' => ['score_viewer'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Playback',
			'description' => 'Plays the score in the browser with the chosen voice emphasised, only for versions whose rights allow it.'],
		'practice_audio' => ['group' => 'scores', 'requires' => ['playback'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Practice audio files',
			'description' => 'Makes WAV or MIDI files per voice in the browser. The server checks and records every export; the files leave the app.'],
		'excerpt_links' => ['group' => 'scores', 'requires' => ['score_viewer'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Excerpt links',
			'description' => 'Internal links to a range of measures and a voice for logged-in archive roles; they expire and can be revoked.'],
		'transcription_review' => ['group' => 'scores', 'requires' => ['score_viewer'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Reviewing transcriptions',
			'description' => 'Reads the reports of transcriptions (bericht.json) into review points with measures and voice, decided in the score view by the conductor and the catalog librarian; corrections larger than a note become orders for the transcription tools.'],
		'corrections' => ['group' => 'scores', 'requires' => ['score_viewer'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Small corrections',
			'description' => 'The catalog librarian and the conductor correct pitches, accidentals and syllables of a MusicXML file in the score view. The app saves the result as a new file next to the old one, which stays unchanged.'],
		self::VERSION_STATES => ['group' => 'scores', 'requires' => ['score_viewer'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Revisions of a version',
			'description' => 'Every correction and every new or changed MusicXML found by the file sync becomes a revision with a checksum and a note. The catalog librarian sets the current revision; two revisions can be compared measure by measure and voice by voice, with a short note for choir members. Concerts keep the revision sung.'],
		'files_integration' => ['group' => 'scores', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Opening from the Files app',
			'description' => 'The action "Open in Cantoria" for files of the archive folder in the Files app: a click on MusicXML opens the score view, other files of a copy open the copy. Rights are checked as in the app.'],
		'tasks' => ['group' => 'collaboration', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Questions and tasks',
			'description' => 'Questions and checks with a location in the score (page, measures, voice), addressed to a role.'],
		'proposals' => ['group' => 'collaboration', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Proposals inbox',
			'description' => 'Changes proposed by tools and transcription rounds wait in an inbox until the catalog librarian accepts them.'],
		'concerts' => ['group' => 'concerts', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Concerts and song lists',
			'description' => 'Programmes with rights warnings and deadlines, confirmation of what was sung, song order and lists.'],
		self::CONCERT_FOLDER => ['group' => 'concerts', 'requires' => ['concerts', 'score_viewer'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Concert folder',
			'description' => 'The conductor and the catalog librarian open the programmes still to be sung in their order, with score and scan of each item, also in a stage view that keeps the screen on. With "Practising without a network" they make a whole programme available on their device for the rehearsal and the concert, where the rights allow a copy. Internal preservation copies are not opened here. Only reads. Not part of any preset.'],
		'performance_import' => ['group' => 'concerts', 'requires' => ['concerts'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Import of performance lists',
			'description' => 'Imports an existing list of performances and song lists (.xlsx or .csv); unclear programme lines are resolved by hand.'],
		'repertoire' => ['group' => 'concerts', 'requires' => ['concerts'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Repertoire figures',
			'description' => 'Derives the repertoire class, the number of performances and the last performance from the concerts confirmed as sung. Switched off, the values of the imported catalog apply.'],
		'calendar' => ['group' => 'concerts', 'requires' => ['concerts'], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Concerts in the calendar',
			'description' => 'Calendar files of the concerts and entries in the own Nextcloud calendars.'],
		'accession' => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'New accessions and shelf labels',
			'description' => 'Assistant for new copies with the next free shelf mark and the required fields; shelf labels to print.'],
		'merge' => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Merging works',
			'description' => 'Proposals for works recorded twice; merging works and moving copies, with undo.'],
		'inventory' => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Inventory',
			'description' => 'Inventory lists per binder to tick on paper, and recording the counts.'],
		self::FOLDER_BOOK => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Folder book',
			'description' => 'Which choir folder is out with whom since when, its contents and what is missing; issuing and taking back. Names are anonymised after a retention period. Choir members do not see it.'],
		'checksums' => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Checksums',
			'description' => 'Reads the archive files hourly in the background and reports files that changed without a visible modification.'],
		self::ANNUAL_SNAPSHOT => ['group' => 'maintenance', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Annual snapshots',
			'description' => 'Freezes the state of the archive once a year, on request or at the turn of the year: the catalog as CSV and JSON and a list of checksums of the archive files, in a dated folder in the archive folder. Two snapshots can be compared and printed.'],
		'statistics' => ['group' => 'overview', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Statistics and annual report',
			'description' => 'Figures on stock, rights, repertoire and maintenance and the annual report, also as CSV.'],
		'dashboard_widget' => ['group' => 'overview', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Dashboard widget',
			'description' => 'The widget "Cantoria – due" on the Nextcloud dashboard.'],
		self::UNIFIED_SEARCH => ['group' => 'overview', 'requires' => [], 'simple' => false, 'standard' => true, 'planned' => false,
			'label' => 'Nextcloud search',
			'description' => 'Finds copies by shelf mark, title and person in the search of Nextcloud, for accounts with access to the music archive. Internal preservation copies only for the roles that may view them.'],
		'notifications' => ['group' => 'notifications', 'requires' => [], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Notifications',
			'description' => 'Notifications to the roles concerned (never to singers), also as an e-mail summary through Nextcloud. Each occasion can be switched off below.'],
		'notify_proposals_waiting' => ['group' => 'notifications', 'requires' => ['notifications', 'proposals'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Proposals are waiting',
			'description' => 'To the catalog librarian: open proposals in the inbox.'],
		'notify_portal_remove' => ['group' => 'notifications', 'requires' => ['notifications', 'portal'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Remove from the singers\' portal',
			'description' => 'To the catalog librarian, daily: a version is in the portal but no longer released.'],
		'notify_license_expiring' => ['group' => 'notifications', 'requires' => ['notifications', 'rights'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'License ends soon',
			'description' => 'To the catalog librarian, daily: a license ends within 30 days.'],
		'notify_license_expired' => ['group' => 'notifications', 'requires' => ['notifications', 'rights'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'License has ended',
			'description' => 'To the catalog librarian, daily: a license has ended.'],
		'notify_export_failed' => ['group' => 'notifications', 'requires' => ['notifications'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Weekly backup failed',
			'description' => 'To the catalog librarian and the administrators.'],
		'notify_task_new' => ['group' => 'notifications', 'requires' => ['notifications', 'tasks'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'New question or task',
			'description' => 'To the role a question or task is addressed to.'],
		'notify_task_answered' => ['group' => 'notifications', 'requires' => ['notifications', 'tasks'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Question answered',
			'description' => 'To the catalog librarian: an answer to apply.'],
		'notify_concert_deadline' => ['group' => 'notifications', 'requires' => ['notifications', 'concerts'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Concert deadlines',
			'description' => 'To the conductor and the catalog librarian, 10, 8 and 6 weeks before a concert.'],
		'notify_concert_confirm' => ['group' => 'notifications', 'requires' => ['notifications', 'concerts'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Confirm what was sung',
			'description' => 'To the conductor, after a concert.'],
		'notify_protection_ended' => ['group' => 'notifications', 'requires' => ['notifications', 'protection_terms'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Protection term ended',
			'description' => 'To the catalog librarian, daily: a version marked as protected is public domain by the life dates of the persons involved.'],
		'notify_protection_conflict' => ['group' => 'notifications', 'requires' => ['notifications', 'protection_terms'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Public domain in doubt',
			'description' => 'To the catalog librarian, daily: a version confirmed as public domain no longer is by the protection proposal, after a person or life dates were changed.'],
		'notify_checksum_mismatch' => ['group' => 'notifications', 'requires' => ['notifications', 'checksums'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'File changed unnoticed',
			'description' => 'To the catalog librarian: an archive file changed without a visible modification.'],
		'notify_review_points' => ['group' => 'notifications', 'requires' => ['notifications', 'transcription_review'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'New review points',
			'description' => 'To the role responsible, once per report: musical points to the conductor, points about the material to the catalog librarian.'],
		'notify_correction_received' => ['group' => 'notifications', 'requires' => ['notifications', 'score_viewer'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Correction received',
			'description' => 'To the catalog librarian: a corrected MusicXML file was saved in the app, or a new transcription has done review points.'],
		self::MEMBER_LINKS => ['group' => 'sharing', 'requires' => ['excerpt_links', 'playback', 'releases'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Links for choir members',
			'description' => 'The role "Choir members" sees only the excerpts shared with it: measures with the voice emphasised, to view and play. Only for versions released for the singers\' portal; the server sends only the measures of the excerpt. Not part of any preset.'],
		self::MEMBER_PRACTICE_AUDIO => ['group' => 'sharing', 'requires' => [self::MEMBER_LINKS, 'practice_audio'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Practice audio for choir members',
			'description' => 'Choir members make a WAV or MIDI file of one voice of a shared excerpt, where the rights allow practice audio files. Every export is recorded. Not part of any preset.'],
		self::OFFLINE => ['group' => 'sharing', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Practising without a network',
			'description' => 'Pieces are kept on a device and viewed, played and practised without a network: by choir members (with the sharing with choir members), by visitors of a public link to a piece whose public domain is confirmed (with public links) and in the concert folder. Only where the rights allow a copy. Kept pieces are checked at every start with a network and locked after 30 days without one. Without this module nothing is kept on a device. Not part of any preset.'],
		self::PUBLIC_LINKS => ['group' => 'sharing', 'requires' => ['excerpt_links', 'playback', 'publication'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Public links',
			'description' => 'Links that show and play an excerpt without login, for versions released for publication that are confirmed public domain or licensed for online publication. They expire, can be revoked and count their calls, and name no accounts. What a browser shows can be saved; the scan is added only where the rights allow it. Not part of any preset.'],
		self::PUBLIC_REPERTOIRE => ['group' => 'sharing', 'requires' => ['rights'], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Public repertoire page',
			'description' => 'A public page with the works the catalog librarian chooses among those with a version confirmed as public domain: title, composer and lyricist, life dates, scoring and links to CPDL and IMSLP. Not part of any preset.'],
		'notify_print_mirror_failed' => ['group' => 'notifications', 'requires' => ['notifications', 'print_mirror'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Print station sync failed',
			'description' => 'To the catalog librarian and the administrators: the sync of the mirror folder for the print station failed.'],
		'notify_choir_handover_failed' => ['group' => 'notifications', 'requires' => ['notifications', 'choir_handover'], 'simple' => true, 'standard' => true, 'planned' => false,
			'label' => 'Hand-over to the choir system failed',
			'description' => 'To the catalog librarian and the administrators: the sync of the hand-over folder for the choir system failed.'],
		self::AGENT_API => ['group' => 'interfaces', 'requires' => [], 'simple' => false, 'standard' => false, 'planned' => false,
			'label' => 'Interface for agents',
			'description' => 'A fixed, versioned interface (OCS API v1) for agents and tools that sign in with an app password of an account of their own. They can do only what the roles of that account allow and never take decisions: they read, propose changes and ask or answer questions. Not part of any preset.'],
	];

	/** @var array<string, bool>|null active modules, computed once per request */
	private ?array $active = null;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public static function exists(string $key): bool {
		return isset(self::MODULES[$key]);
	}

	/**
	 * Whether a module is planned but not built yet: always off, cannot be
	 * switched and is not shown in the settings. No module is planned at the
	 * moment; the mechanism stays for the next one.
	 *
	 * @psalm-suppress TypeDoesNotContainType
	 */
	public static function isPlanned(string $key): bool {
		return (self::MODULES[$key]['planned'] ?? false) === true;
	}

	/**
	 * Keys of the modules that can be switched, in the order of the settings.
	 *
	 * @return list<string>
	 */
	public static function switchable(): array {
		return array_values(array_filter(array_keys(self::MODULES), fn (string $key): bool => !self::isPlanned($key)));
	}

	/**
	 * English label of a module.
	 */
	public static function label(string $key): string {
		return self::MODULES[$key]['label'] ?? $key;
	}

	/**
	 * Modules a module needs, directly and indirectly (nearest first).
	 *
	 * @return list<string>
	 */
	public static function requirements(string $key): array {
		$result = [];
		$queue = self::MODULES[$key]['requires'] ?? [];
		while ($queue !== []) {
			$next = array_shift($queue);
			if (!in_array($next, $result, true)) {
				$result[] = $next;
				array_push($queue, ...(self::MODULES[$next]['requires'] ?? []));
			}
		}
		return $result;
	}

	/**
	 * The switch of a module for a notification subject, or null when the
	 * subject has none.
	 */
	public static function notificationKey(string $subject): ?string {
		$key = self::NOTIFY_PREFIX . $subject;
		return self::exists($key) ? $key : null;
	}

	/**
	 * Whether the presets set the switch of a module: every switchable module
	 * except OUTSIDE_PRESETS.
	 */
	public static function inPresets(string $key): bool {
		return self::exists($key) && !self::isPlanned($key) && !in_array($key, self::OUTSIDE_PRESETS, true);
	}

	/**
	 * What a preset switches on and off (planned modules and OUTSIDE_PRESETS
	 * are not part of a preset).
	 *
	 * @return array<string, bool>
	 * @throws InvalidInputException for an unknown preset
	 */
	public static function presetValues(string $preset): array {
		if (!in_array($preset, self::PRESETS, true)) {
			throw new InvalidInputException('Unknown preset: %s', [$preset]);
		}
		$values = [];
		foreach (array_filter(self::switchable(), self::inPresets(...)) as $key) {
			$values[$key] = match ($preset) {
				self::SIMPLE => self::MODULES[$key]['simple'],
				self::STANDARD => self::MODULES[$key]['standard'],
				default => true,
			};
		}
		return $values;
	}

	/**
	 * What a scenario switches on and off: its preset, its own modules and
	 * everything they require.
	 *
	 * @return array<string, bool>
	 * @throws InvalidInputException for an unknown scenario
	 */
	public static function scenarioValues(string $scenario): array {
		$definition = self::SCENARIOS[$scenario] ?? null;
		if ($definition === null) {
			throw new InvalidInputException('Unknown scenario: %s', [$scenario]);
		}
		$values = $definition['modules'] + self::presetValues($definition['preset']);
		foreach (array_keys(array_filter($definition['modules'])) as $key) {
			foreach (self::requirements($key) as $required) {
				$values[$required] = true;
			}
		}
		return $values;
	}

	/**
	 * Whether the administration has chosen what Cantoria does for the
	 * choir: the step "Functions for your choir" of the setup.
	 */
	public function chosen(): bool {
		try {
			return $this->appConfig->getValueBool(Application::APP_ID, self::CHOSEN_KEY);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Whether a module is active: its own switch is on and every module it
	 * requires is active.
	 *
	 * @throws \InvalidArgumentException for an unknown key (a programming error)
	 */
	public function isEnabled(string $key): bool {
		if (!self::exists($key)) {
			throw new \InvalidArgumentException('Unknown module: ' . $key);
		}
		return $this->states()[$key];
	}

	/**
	 * @throws ModuleDisabledException when the module is not active
	 */
	public function requireEnabled(string $key): void {
		if (!$this->isEnabled($key)) {
			throw new ModuleDisabledException($key);
		}
	}

	/**
	 * Whether notifications of a subject are sent and shown. A subject
	 * without a switch of its own follows the switch "notifications".
	 */
	public function notificationEnabled(string $subject): bool {
		return $this->isEnabled(self::notificationKey($subject) ?? 'notifications');
	}

	/**
	 * Active state of every module, planned ones included (always false).
	 *
	 * @return array<string, bool>
	 */
	public function states(): array {
		if ($this->active === null) {
			$active = [];
			foreach (array_keys(self::MODULES) as $key) {
				$this->resolve($key, $active);
			}
			$this->active = $active;
		}
		return $this->active;
	}

	/**
	 * The module's own switch, regardless of what it requires.
	 */
	public function isSwitchedOn(string $key): bool {
		if (!self::exists($key) || self::isPlanned($key)) {
			return false;
		}
		$default = self::MODULES[$key]['standard'];
		try {
			return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_PREFIX . $key, $default);
		} catch (\Throwable) {
			// A value of another type (e.g. set by hand): the default applies.
			return $default;
		}
	}

	/**
	 * Switches modules on and off. Switching on also switches on what a
	 * module requires; switching off leaves the modules that need it as they
	 * are (they become inactive). Keys must be switchable.
	 *
	 * @param array<string, bool> $wanted key => on
	 * @return array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
	 * @throws InvalidInputException for an unknown or planned module, or when a module to switch on needs one to switch off
	 */
	public function change(array $wanted): array {
		foreach (array_keys($wanted) as $key) {
			self::assertSwitchable($key);
		}
		$on = array_keys(array_filter($wanted));
		$off = array_keys(array_filter($wanted, fn (bool $value): bool => !$value));
		$needed = [];
		foreach ($on as $key) {
			foreach (self::requirements($key) as $required) {
				if (in_array($required, $off, true)) {
					throw new InvalidInputException('"%1$s" needs "%2$s".', [self::label($key), self::label($required)]);
				}
				$needed[] = $required;
			}
		}
		$also = array_values(array_diff(array_unique($needed), $on));
		return $this->write($wanted + array_fill_keys($also, true), $also);
	}

	/**
	 * Sets every module of the presets as the preset says; OUTSIDE_PRESETS
	 * keep their switch. A preset may leave a switch on whose module it
	 * switches off (e.g. the notifications of tasks in "simple"): it stays
	 * inactive until that module is on.
	 *
	 * @return array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
	 * @throws InvalidInputException for an unknown preset
	 */
	public function applyPreset(string $preset): array {
		return $this->write(self::presetValues($preset), []);
	}

	/**
	 * Sets the switches of a scenario (SCENARIOS); the modules outside it
	 * keep their switch.
	 *
	 * @return array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
	 * @throws InvalidInputException for an unknown scenario
	 */
	public function applyScenario(string $scenario): array {
		return $this->write(self::scenarioValues($scenario), []);
	}

	/**
	 * Writes every switch that has no stored value yet, so that a later
	 * change of the defaults switches nothing (repair step on installation
	 * and update): the value of the preset "standard", off for modules
	 * outside the presets.
	 *
	 * @return list<string> the keys written
	 */
	public function writeBaseline(): array {
		$standard = self::presetValues(self::STANDARD);
		$written = [];
		foreach (array_keys(self::MODULES) as $key) {
			if ($this->appConfig->hasKey(Application::APP_ID, self::CONFIG_PREFIX . $key, null)) {
				continue;
			}
			$this->appConfig->setValueBool(Application::APP_ID, self::CONFIG_PREFIX . $key, self::inPresets($key) && $standard[$key]);
			$written[] = $key;
		}
		$this->active = null;
		return $written;
	}

	/**
	 * Adds a module and everything it requires to the active states.
	 *
	 * @param array<string, bool> $active modules resolved so far
	 */
	private function resolve(string $key, array &$active): void {
		if (isset($active[$key])) {
			return;
		}
		$on = $this->isSwitchedOn($key);
		foreach (self::MODULES[$key]['requires'] as $required) {
			$this->resolve($required, $active);
			$on = $on && $active[$required];
		}
		$active[$key] = $on;
	}

	/**
	 * Stores switches and reports what changed: `enabled`/`disabled` the
	 * switches that changed, `alsoEnabled` the requirements switched on with
	 * them, `inactive` the modules that stopped working without being
	 * switched off themselves, `activated` those that work again without
	 * being switched on themselves.
	 *
	 * @param array<string, bool> $values switchable key => on
	 * @param list<string> $also keys of $values switched on as requirements
	 * @return array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
	 */
	private function write(array $values, array $also): array {
		$activeBefore = $this->states();
		$switchedBefore = [];
		foreach (self::switchable() as $key) {
			$switchedBefore[$key] = $this->isSwitchedOn($key);
		}
		foreach ($values as $key => $on) {
			$this->appConfig->setValueBool(Application::APP_ID, self::CONFIG_PREFIX . $key, $on);
		}
		$this->active = null;
		$activeAfter = $this->states();

		$on = array_values(array_diff(array_keys(array_filter($values)), $also));
		$off = array_keys(array_filter($values, fn (bool $value): bool => !$value));
		$enabled = self::changed($on, $switchedBefore, true);
		$alsoEnabled = self::changed($also, $switchedBefore, true);
		$disabled = self::changed($off, $switchedBefore, false);
		$side = fn (bool $before, array $except): array => array_values(array_filter(array_keys(self::MODULES),
			fn (string $key): bool => !in_array($key, $except, true) && $activeBefore[$key] === $before && $activeAfter[$key] !== $before));
		return [
			'enabled' => $enabled,
			'alsoEnabled' => $alsoEnabled,
			'disabled' => $disabled,
			'inactive' => $side(true, $disabled),
			'activated' => $side(false, [...$enabled, ...$alsoEnabled]),
		];
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function assertSwitchable(string $key): void {
		if (!self::exists($key)) {
			throw new InvalidInputException('Unknown module: %s', [$key]);
		}
		if (self::isPlanned($key)) {
			throw new InvalidInputException('This function is not available yet: %s', [self::label($key)]);
		}
	}

	/**
	 * The keys whose switch was not yet at the value, in the order of the
	 * register.
	 *
	 * @param list<string> $keys
	 * @param array<string, bool> $before switches before the change
	 * @return list<string>
	 */
	private static function changed(array $keys, array $before, bool $value): array {
		return array_values(array_filter(array_keys(self::MODULES),
			fn (string $key): bool => in_array($key, $keys, true) && ($before[$key] ?? false) !== $value));
	}
}
