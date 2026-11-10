<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Tests

There are two kinds of tests. They are kept apart on purpose:

| | Unit tests | Integration tests |
|---|---|---|
| Location | `tests/Unit/` | `tests/Integration/` |
| Needs a Nextcloud server | no | yes, with the app installed |
| Runner | PHPUnit (`tests/phpunit.xml`) | plain PHP scripts |
| Runs in CI | yes | yes, with the demo data |

## Unit tests

The unit tests run without a Nextcloud server and without a database. The
public Nextcloud API (`OCP`) comes from the `nextcloud/ocp` package in the
version of the **oldest supported Nextcloud** (see `composer.json`), so a test
fails when the app uses an API that this version does not have. Nextcloud
services (`IDBConnection`, `ITimeFactory`, `IUserManager`, …) and the mappers
are replaced by PHPUnit test doubles; entities and the app's own logic are
real.

Requirements: PHP 8.2 or newer with the extensions `ctype`, `dom`, `json`,
`mbstring`, `tokenizer`, `xml` and `zip` (without `zip` the spreadsheet tests
are skipped, and a skipped test fails the suite), and Composer.

```sh
composer install                 # app dev dependencies and the tools in vendor-bin/
composer test:unit               # all unit tests
vendor/bin/phpunit -c tests/phpunit.xml --filter ImportServiceTest
vendor/bin/phpunit -c tests/phpunit.xml --testdox
```

The suite fails on warnings, notices, deprecations, risky, skipped and
incomplete tests (`tests/phpunit.xml`), so a check that depends on something
the CI job lacks cannot pass unnoticed: what needs a build of `js/` is tested
on a fixed text instead (`OfflineController::fillWorker`).

What is covered:

| Test | Subject |
|---|---|
| `Command/ModulesCommandTest` | `occ cantoria:modules`: the list as table and JSON, enable and disable through the settings with the changes reported, preset and scenario by exactly one name, a wrong call changes nothing and says why |
| `Service/LinkListTest` | the list of links: links that ended in the last 30 days stay listed, each link names the latest day it can be extended to and why (six months, one year, the license), only for accounts that may extend it |
| `Service/CatalogCsvTest` | CSV parsing (BOM, double BOM, `;`, quotes, CRLF in cells, empty lines), writing byte for byte like Python's `csv` module with `QUOTE_MINIMAL` and CRLF (reference output embedded), round trip, legacy publication rules |
| `Service/FieldValueTest` | value checks per field type, length in characters, strict calendar dates (e.g. `2026-02-31`) |
| `Service/FieldsTest` | the field registry against the schema of the migrations: every column has a field or is listed as internal, `max` equals the column length, types and NOT NULL fit, every legacy CSV column maps to a field |
| `Db/EntitySchemaTest` | every entity against the table of its mapper: columns, types, nullability |
| `Db/LicenseTest` | `License::covers()` (uses, first and last day) |
| `Service/RightsServiceTest` | releases for the singers' portal and the print station, licenses, confirmation, portal workflow, transactions |
| `Service/RightsPolicyTest` | viewing and playback of scores, practice audio files (roles, confirmed public domain or license), preservation copies |
| `Service/PracticeAudioServiceTest` | the gatekeeper for practice audio files: an allowed export is recorded with measures, voice, mode and format; refused and invalid requests are not |
| `Service/ImportServiceTest` | CSV import: invalid values skipped, decision fields never overwritten, shelf marks normalized, dry run rolled back, snapshot before a real import |
| `Service/ProposalServiceTest` | proposed values stored exactly (also `0`, `false`, `""`), accepting applies them |
| `Service/LifeDatesTest` | life dates from free text with their precision (ranges, full dates, "ca.", "?", living, before Christ) |
| `Service/ProtectionTermTest` | protection proposal: 70 years after the end of the year of death, the last person decides, "unknown means protected" |
| `Service/FullBackupServiceTest` | format check of the complete backup before a restore |
| `Service/XlsxReaderTest` | reading a synthetic .xlsx: shared and rich text, inline strings, gaps, sheet order, damaged files |
| `Service/OdsReaderTest` | reading a synthetic .ods as LibreOffice writes it (runs of repeated cells and rows, typed values, paragraphs, groups), what `OdsWriter` writes; refused: no spreadsheet, damaged XML, document type declarations, a part that unpacks too large, repeats that would inflate the sheet |
| `Service/OdsWriterTest` | the written .ods: package structure, sheets, escaping |
| `Service/PerformanceImportServiceTest` | counting rule for documented performances, detection of the own choir |
| `Service/CatalogServiceTest` | repertoire figures (documented or imported), works after merges |
| `Service/StatisticsServiceTest` | aggregation of the statistics without a database: period, copies per binder, new copies per year (audit log or creation date, time zone of the user), rights states, valid versions, license groups, concerts and pieces per year, most performed works and composers, repertoire classes, works not sung for a long time, tasks by age, protection terms, open rights questions, licenses and accessions of the annual report; 2,000 copies aggregated in well under a second |
| `Service/StatisticsCsvTest` | CSV of the statistics and the annual report: every table under its heading, translated labels, formula cells neutralized |
| `Service/InputTest`, `Service/SortTest`, `Db/MergeTest` | request data checks, typed sorting, what a merge recorded |
| `Controller/ControllerSecurityTest` | route attributes of every controller (public only the pages of the sharing modules and the service worker, CSRF skipped only for plain downloads, the settings only for admins and the groups given the settings page, restore not delegable, brute-force and rate limits), headers of the score file response and of the scan beside the score (PDF viewer only, framed by the app only), `reason: not_shared`, the app page's content security policy, the service worker (version and only a plain path of this server in it, checked without a build); every route of the API for agents is an OCS route under `/api/v1/` with `agent_api` plus the module of the same function in the UI, `NoAdminRequired`, a rate limit, no `NoCSRFRequired` and no CORS, and answers 404 with OCS meta when switched off; the routes of choir members; the folder book (read by librarians, operations, conductor and readers, maintained by librarians and operations, never by contributors or unknown roles such as choir members; its audit entries hidden from contributors) and the print station (sync only by the catalog librarian and administrators, the target folder only by administrators) with real role checks |
| `Controller/LinkRolesTest` | sharing a programme and extending links through their routes with the real roles, link service and rights policy (only the mappers are test doubles): only the roles that make links, the rights of today for every circle and the scan, the license end kept |
| `Controller/PreservationCopyParityTest` | the app's pages and the API for agents list and refuse the same internal preservation copies for every role; the same for the CSV export, the files in the archive folder, the task lists and programmes |
| `Controller/FullBackupDownloadTest` | every download of the complete backup is in the audit log, a refused one is not |
| `Controller/ApiV1ControllerTest` | the API for agents with the services as test doubles: search with filters and cursor pages in shelf order, the fixed fields, preservation copies only for the roles that may view them, no account names, errors as OCS exceptions (400, 403, 404, 500 without internal details), tasks and proposals answer 201, own proposals, concerts by date, module status and permissions |
| `Service/ApiV1ViewsTest`, `Service/TextReferenceTest` | cursors (opaque, checked, stable when entries are added), snake_case statistics, values without JSON; the server's text reference follows the rules and examples of the UI (`src/__tests__/references.test.ts`) |
| `Settings/AdminTest`, `Settings/AdminSectionTest` | the page Administration → Cantoria: delegable setting without generic app config, its section, template and registration in `appinfo/info.xml` |
| `Service/RolesTest` | who may change the settings: admins and the groups given the settings page, without a role; fails closed |
| `Service/ArchiveAccessTest` | the access check of the archive folder: per role and group, accounts named that cannot open it, disabled and never logged-in accounts left out, the limit, admins without a role, the person who asks |
| `Service/ScoreServiceTest` | only real MusicXML reaches the browser (root element, document type declarations, `.mxl` contents, size limit) |
| `Service/UserFilesTest`, `Service/RequestBoundaryTest` | reading uploaded files with a size limit, limits of request data (unknown fields, list sizes) |
| `Notification/NotifierTest` | notification texts, no internal error texts |
| `Service/IcsWriterTest`, `Service/ConcertIcsTest` | calendar files after RFC 5545: CRLF, folding after 75 octets without splitting umlauts or other multi-octet characters, escaping of `,;\` and line breaks, time zone definitions (daylight saving time, half-hour offsets), all-day entries, stable UIDs, deadlines as separate entries, cancelled concerts |
| `Service/ConcertDeadlinesTest`, `Service/DueServiceTest` | the deadlines 10, 8 and 6 weeks before a concert and the confirmation after it; which roles see them on the start page |
| `Service/ConcertCalendarServiceTest` | entries in the account's own calendars (test double in `Support/FakeCalendar`): only writable calendars, roles, adding again updates instead of duplicating, cancelled concerts are only updated |
| `Controller/PageControllerTest` | the page "No access yet" links the section of the guide about a missing role, in the language of the account |
| `Dashboard/DueWidgetTest` | the dashboard widget: only for accounts with a role, items and deep links of the start page, short titles and an icon per kind, no personal data |
| `Service/TranscriptionReportParserTest` | reports of transcriptions (`bericht/1`): fields and review points with their roles, measures ("12–14", "T. 5"), questions as objects, `erledigt`, warnings for unknown and missing fields; refused: invalid JSON, too large, nested too deeply, wrong types, paths and control characters in the base name, a base name of another shelf mark |
| `Service/MusicXmlCorrectionTest` | small corrections: pitch, alteration, accidental (placed in schema order) and syllable are applied to the stored document, which is kept otherwise; refused: other note values, added or removed notes and measures, renumbered measures, other attributes and elements, invalid values, markup or line breaks in a syllable, no change, a document type with an internal subset |
| `Service/ReviewServiceTest` | who decides a review point (librarian everything, conductor the musical points), allowed decisions (`corrected` only by the app, text for an order, reason for dismissing), questions need the tasks, catalog fields of a report |
| `Service/CorrectionServiceTest` | name of the corrected file: dated, in the account's language, numbered when it exists, an earlier suffix replaced, long names shortened without cutting a character |
| `Service/FilesIntegrationServiceTest`, `Listener/FilesScriptListenerTest` | opening from the Files app: the copy of a Nextcloud file only for files the account sees (the same 404 for unlisted and foreign files), the archive folder as the account sees it; the script only for accounts with a role or admins, while the module is on |
| `Service/FolderBookServiceTest` | the folder book: one loan per folder at a time, account or name, days not in the future and not before the issue, condition and missing sheets taken over on the return, no retiring while out, back into service only with a free label, contents as copies with a number, the hint for a retired copy or another valid version, the retention period in months (end of shorter months) and the anonymisation of the loan and its audit entries |
| `Service/MirrorRightsTest` | the print station and the hand-over folder remove a file whose rights ended while its version stays listed, also a preservation copy; the preview only names it; a file someone changed stays |
| `Service/PageKeysTest` | the keys of programmes and pieces on a device: an HMAC with the secret of the instance, stable for the same input, never a plain hash of the id |
| `Service/PrintMirrorServiceTest` | file names in the target folder of the print station: shelf mark and title, without characters Windows or Nextcloud refuse, shortened without cutting a character |
| `Service/HandoverContentTest` | the hand-over folder: MusicXML cleaned like the score of a link, a file that is no score left out, scans and recordings as they are |
| `Service/ChoirSystemCompareServiceTest` | the export of a choir system against the list of the singers' portal: differences become proposals, dry run, no proposal twice, separator of the first line |
| `Service/SharingTest` | links for choir members and the public: making, opening, extending (creator or librarian, rights of today, license end, a new token for an expired public link), practice audio of choir members, the public repertoire page and its links to CPDL and IMSLP |
| `Service/ProgrammeShareTest` | sharing a whole programme: one link per piece through the same checks, an existing link extended instead of a second one, one link for a piece sung twice, never shortened, a reason for every piece left out |
| `Service/LinkTokensTest` | link tokens only as hash and encrypted, read back only with the secret of the instance, shown only to the creator and the catalog librarian |
| `Service/MusicXmlExcerptTest` | the measures of a link in every part with the attributes and tempo valid at its start, repeats and endings, nothing outside the excerpt (demo scores of `tools/demo`) |
| `Service/RightsNoticeServiceTest` | the rights notice of a MusicXML file: read from the header only, no internal DTD subset, cut when long, at most a proposal |
| `Service/LicenseCoverageTest` | warnings for photocopies, scans and transcriptions without a license that covers them |
| `Service/ConcertFolderTest` | the concert folder: programmes still to be sung in their order, the rights of the score view, kept on a device only with the module `offline` and the rights for a copy, no preservation copy, only conductor and librarian |
| `Service/PrivacyTest` | access and erasure for one person, a deleted account, the retention period of the audit log, the year of birth of persons without a known death, every field with an account in the report |
| `Service/PersonTableTest` | the register of persons in the export folder without the account that checked a person |
| `Service/SongbookServiceTest` | songbooks: the pieces of a programme in its order without struck ones, a reason for every piece left out (rights, no scan, no copy, preservation copy), scans only after the same checks, only for the catalog librarian and the conductor, recorded in the audit log |
| `Service/ContributionRolesTest` | a person has each role at most once in a version, also when a role is changed |
| `Service/MergeCandidatesTest` | proposals for merging works: titles compared without accents and in every Unicode form, works without a composer as a group of their own next to several composers |
| `Service/PerformanceReimportTest` | importing the performances again keeps the lines assigned to a copy by hand, unless the line changed or the copy is gone |
| `Service/ExportServiceTest` | the complete backup in the backup folder, the newest eight weekly backups kept, no link tokens, no accounts in an annual snapshot, the import template |
| `Service/AnnualSnapshotServiceTest` | comparing two annual snapshots: copies, rights, licenses and files |
| `Service/AppSettingsTest` | the backup folder is taken over only while only the archive account can open it; a shared folder stops the backup |
| `Service/ModulesTest`, `Service/ModuleSettingsTest` | the module register (dependencies, presets, scenarios, a switch per notification) and changing modules from the settings and the command line, recorded in the audit log, only administrators switch on what opens the archive, the switches on installation and update |
| `Service/NotificationServiceTest`, `BackgroundJob/JobsTest` | a switched-off occasion sends nothing, withdrawing always works; the background jobs leave out what is switched off (backup reminder, concert deadlines, protection terms, annual snapshot in January, folder book) |
| `Service/RunLockTest` | a run never runs twice at the same time, the lock is given back when it fails, the status names who started it |
| `Service/StateServiceTest` | revisions of a version: changed measures, the current revision, the revision of a concert, what a correction records |
| `Service/ShelfmarkGapsTest`, `Service/SongOrderTest`, `Service/PersonalViewTest` | gaps in the shelf marks as ranges; the song order for the music report (event, composer, lyricist and arranger, work number); the personal view per account |
| `Search/CatalogSearchProviderTest` | the search of Nextcloud: only catalog roles, preservation copies only for the roles that may view them, nothing with the module off, results in pages |
| `Migration/ReleasedMigrationsTest` | every migration in `lib/Migration` is listed in `RELEASED` with its checksum and unchanged, and a new one sorts after the released ones |

`Support/MigratedSchema` runs all migrations in `lib/Migration` against an
in-memory Doctrine schema. `EntitySchemaTest` discovers the mappers in
`lib/Db`. New migrations, mappers and entities are therefore checked without
changing the tests; a new column of a work, version or copy needs a field in
`Service/Fields` or an entry in `FieldsTest::INTERNAL_COLUMNS`.

`tests/stubs/` holds stubs of private server classes that the public API refers
to, and of the Files app event the app listens to (used by the unit tests and
by Psalm).

`src/__tests__/testsReadme.test.ts` checks that this table names every test
class in `tests/Unit` and no class that does not exist.

## Frontend tests

`npm test` runs Vitest (`src/**/*.test.ts`, `tools/l10n/*.test.mjs`,
`.github/scripts/*.test.mjs`): the
logic of the score view, catalog, links, devices and translations in Node;
files that start with `@vitest-environment jsdom` run in a simulated
browser, and some of them mount Vue components with `@vue/test-utils`
(`screen.test.ts`, `stage.test.ts`, `concertFolder.test.ts`,
`keepOffline.test.ts`, `playbackSettings.test.ts`, `sharedDevice.test.ts`).
`docs.test.ts` and `security.test.ts` check the documentation and the table
of promises in `SECURITY.md`, `testsReadme.test.ts` this file;
`.github/scripts/ci-scripts.test.mjs` runs the two scripts of the CI that
decide which jobs run and whether the CI as a whole passed.

## Integration tests

The scripts in `tests/Integration/` run against a real Nextcloud instance with
the app installed and a catalog imported. They change data inside a database
transaction that is rolled back at the end and remove the files they export.
Run them only on a test instance, never on a production system.

Run them as the user that owns the Nextcloud data (the web server user). The
classes are loaded from the app installed in Nextcloud; the scripts
themselves may lie anywhere, together with `harness.php`:

```sh
sudo -u www-data php tests/Integration/workflow_test.php      /var/www/nextcloud <uid> <shelfmark-a> <shelfmark-b>
sudo -u www-data php tests/Integration/notifications_test.php /var/www/nextcloud <uid> <shelfmark>
sudo -u www-data php tests/Integration/tasks_links_test.php   /var/www/nextcloud <uid> <shelfmark>
sudo -u www-data php tests/Integration/events_test.php        /var/www/nextcloud <uid> <programme-csv> <song-list-csv> <shelfmark-a> <shelfmark-b>
sudo -u www-data php tests/Integration/maintenance_test.php   /var/www/nextcloud <uid> <persons-csv> <shelfmark-a> <shelfmark-b> <shelfmark-free>
sudo -u www-data php tests/Integration/practice_audio_test.php /var/www/nextcloud <uid> <shelfmark-protected> <shelfmark-preservation-copy> <shelfmark-public-domain>
sudo -u www-data php tests/Integration/statistics_test.php    /var/www/nextcloud <uid> [<programme-csv>]
sudo -u www-data php tests/Integration/backup_test.php        /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/calendar_test.php      /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/modules_test.php       /var/www/nextcloud <uid> <shelfmark-protected>
sudo -u www-data php tests/Integration/api_v1_test.php        /var/www/nextcloud <uid> <shelfmark> <shelfmark-preservation-copy>
sudo -u www-data php tests/Integration/review_test.php        /var/www/nextcloud <uid> <shelfmark-with-musicxml>
sudo -u www-data php tests/Integration/files_integration_test.php /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/sharing_test.php       /var/www/nextcloud <uid> <shelfmark-public-domain> <shelfmark-preservation-copy>
sudo -u www-data php tests/Integration/settings_access_test.php /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/folder_book_test.php   /var/www/nextcloud <uid> <shelfmark>
sudo -u www-data php tests/Integration/print_mirror_test.php  /var/www/nextcloud <uid> <shelfmark-with-pdf>
sudo -u www-data php tests/Integration/handover_test.php      /var/www/nextcloud <uid> <shelfmark-with-pdf-and-musicxml>
sudo -u www-data php tests/Integration/archive_extras_test.php /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/version_states_test.php /var/www/nextcloud <uid> <shelfmark-with-musicxml>
```

`version_states_test.php` covers the revisions of a version (milestone 0.8,
module `version_states`): the file sync records the score file of the copy
with its checksum (the first revision of a version that has none), an
unchanged file makes no new revision, a small correction in the app makes
exactly one new revision with measure, voice, reason and changes, the
catalog librarian sets the current revision (the conductor may not), the
copy names the current revision and the one of the score shown, and a
concert of today confirmed as sung keeps its revision, also in the song
order. It switches the modules it needs on (and checks the module switched
off), gives the account's groups the roles `librarian` and `conductor` for
the time of the test, and runs on any catalog: the copy needs a MusicXML
file the account may read, in a folder it may write to. The corrected file
is deleted, roles and switches restored, everything else rolled back.

`archive_extras_test.php` covers milestone 0.8, archive: the special cases
of the protection term (§§ 70, 71 UrhG) with a person and an editor it
creates, the search of Nextcloud (shelf mark, title, person, preservation
copies, an account without a role, module off), the gaps in the shelf marks
against the next free shelf mark, the rights notice of a MusicXML file
(never a rights status) and two annual snapshots with their comparison. It
finds its copies itself and runs on any catalog with a MusicXML file; the
account must be able to write into the archive folder. Everything is rolled
back, the module switches are restored and the snapshot folders it wrote
are deleted again.

`statistics_test.php` fixes no figures of a catalog, so it runs on any
imported catalog: it checks that sums and states add up, compares the
statistics with the catalog list, the release lists, the resubmission list
and the checksums, books a concert, an accession and a license and checks
the difference, and finally fills the catalog up to 2,000 copies to measure
the time of the whole call (rolled back like everything else).

`calendar_test.php` needs the dav app (calendars). It creates a calendar of
its own for `<uid>`, adds a concert with its deadlines through Nextcloud's
calendar interface, adds it again after a change and after the cancellation
(updates instead of duplicates), checks the calendar files with Nextcloud's
iCalendar parser (Sabre VObject) and the dashboard widget; the calendar is
deleted and everything is rolled back.

`modules_test.php` switches all modules on, then concerts, the dashboard
widget and the rights off and on again: the routes answer 404, the daily
checks, the start page and the widget leave the concerts out and withdraw
their notification, the concert and its programme stay, and playback and
practice audio of a protected version stay refused. Everything is rolled
back, and at the end the module switches are exactly as before (also those
that were not stored).

`api_v1_test.php` takes the controllers of the API for agents from
Nextcloud's dependency injection container with `<uid>` as the account of the
session. It reads (module status, search with filters and every page,
a copy, a text reference, the due list, tasks, concerts, statistics, the
review of a copy page by page and the correction orders), creates a
proposal and a task, hands in transcription reports it writes itself (so
the catalog needs no demo report; one point is decided as a correction
order through the service, as the score view does), checks what is refused
(proposing a decision, answering for another role, unknown objects, invalid
cursors, a report naming another copy, writing as reader, the preservation
copy for reader and contributor, deciding a review point or saving a
correction as contributor through the routes of the UI) and that every
route answers 404 without `agent_api`, the task routes without `tasks` and
the review routes without `transcription_review`. The second shelf mark is
made an internal preservation copy inside the transaction when it is not
one, so any two copies of different versions do. For the other roles it
gives the account's groups the role `reader` or `contributor` for a while.
Everything is rolled back; the module switches and the role groups are
restored exactly.
`review_test.php` writes a transcription report (`bericht/1`) from the
shelf mark, the name and the measures and parts of the copy's score, reads
it, decides its review points (confirmed, correction order, question as a
task, dismissed; the conductor only musical points), saves a small
correction in the app as a new file next to the old one (the old file stays
unchanged; changed note values, added notes and a changed number of
measures are refused), runs the file sync and reads a second round that
closes the points no longer named. The copy needs a MusicXML file the
account may read, in a folder it may write to; the new file is deleted at
the end and everything else rolled back. It runs on any catalog: no fixed
titles or figures.
`files_integration_test.php` checks the action "Open in Cantoria" of
the Files app: a listed MusicXML file names its copy and opens the score
view, a PDF opens its copy, an unlisted file of the archive folder, an
unknown file id and a file of another account all get the same 404 (the
latter also when it is entered in the archive), the route answers 404 while
the module is off, and only an account with a role gets the script. It
creates a second account without a role (random password, never printed)
with one file and deletes it at the end; everything else is rolled back and
the module switches are restored exactly.
`settings_access_test.php` needs the settings app of Nextcloud
(Administration privileges) and `<uid>` as the archive user. It creates two
accounts in two groups of its own, gives both groups the role `reader`,
shares the archive folder (read only) with the first group and gives the
settings page to the second. The access check must name the second account
and add no share; the meta data, the score view (message "not shared") and
the settings routes (403 without the settings page) must agree. The share,
the delegation, the accounts and the groups are removed and the role groups
set back afterwards.

`folder_book_test.php` puts the copy into a folder of the folder book,
issues it to a name and to the account, refuses a second issue (in the
service and through the unique index of the database), takes it back with
condition and missing sheets, marks the folder when the copy is retired,
and runs the retention period with a staged day: a day before it ends
nothing changes, then name, account and notes disappear from the loans and
from their entries in the audit log, which notes the anonymisation without
a name. It also checks the roles (contributors see nothing, conductor and
reader only read, operations maintains) and the switched-off routes.
Everything is rolled back; the module switch and the role groups are
restored exactly.
`print_mirror_test.php` makes `<uid>` an administrator inside the
transaction, refuses the archive folder and a folder inside it as target
folder, creates a target folder in the files of `<uid>` with a foreign file
in it, and checks the preview (writes nothing), that the daily sync waits
for the first sync by hand, copying (same content, `LIESMICH.txt`), a
second sync without changes, replacing a changed scan, removing a scan no
longer released, and that a file changed by someone and a foreign file
with the name of a scan stay as they are; every write and removal is in the
audit log and nothing is written into the archive folder. The target folder
and everything written are deleted at the end, the rest is rolled back.

`handover_test.php` does the same for the hand-over folder for a choir
system (milestone 0.12): it refuses the archive folder and the folder of the
print station, writes a note on a link for choir members, and checks the
preview, that scan, MusicXML file, `Liste.csv` (shelf mark and license
notice), the notes file and `LIESMICH.txt` are written, that its manifest is
apart from the one of the print station, a second sync without changes and
that the files leave the folder when the version is no longer released.

`<uid>` must hold the role `librarian`; each script's header lists further
requirements (CSV paths are relative to the user's files). `backup_test.php`
first adds a record to every app table (license, proposal, task, link,
concert with an item, person with a contribution, transcription report with a
review point, merge), so that the backup round trip covers every table
with content.

All scripts use `harness.php`: it prints one line per check and ends with
exit code 0 only when every check passed. A failed check, an uncaught
exception, a fatal error or a script that stops early ends with exit code 1
(Nextcloud's own error handling would otherwise end such a run with 0);
missing arguments end with exit code 2.

### Integration tests in CI (demo data)

The CI job `integration` runs all twenty scripts against a freshly installed
Nextcloud 33 and 35 (release archives from download.nextcloud.com, checked
against their SHA-512 checksum), each with MariaDB 11.8 and with PostgreSQL 17,
Nextcloud 33 also with MySQL 8.0, and PHP 8.3. The job
installs the app with its built frontend, builds the public demo data
(`node tools/demo/build.mjs`, see `tools/demo/README.md`) and calls

```sh
bash tools/ci/integration-demo.sh /path/to/nextcloud
```

The script refuses to run when the catalog is not empty or the test account
exists. It creates a test account (random password, never printed) in its
own group, gives the group the roles `librarian` and `reader` and no other
role, copies the demo folder into the account's files (`occ files:scan`),
sets it as archive folder, imports `_Import/catalog.csv` (dry run, then for
real) and runs the file sync. It checks the figures of
`tools/demo/README.md`: 23 rows, 7 with MusicXML, 1 preservation copy,
19 × public domain, 2 × protected, 2 × open; 30 files (23 PDF, 4 MXL,
3 MusicXML), 29 assigned, `Mappe_4/4-04_…pdf` not assignable, `LIESMICH.txt`
ignored, `_Import` skipped, 4-3 without a file; the two transcription reports
of the demo (1-1, 2-1) are counted apart (`reports`: 2 files, 2 assigned, 2
imported; 32 new file rows); the rights notices of the 7 MusicXML files are
read (`rightsNotices`: 7 read, 7 with a notice, 1 proposal for 3-1, which is
marked protected while its file names CC0). It switches every module on
(`occ cantoria:modules preset complete`; a new installation starts with
the preset "standard") and, after the import, the interface for agents
(`occ cantoria:modules enable agent_api`, which no preset touches). Then
it runs the tests with these shelf marks of the demo catalog:

| Script | Arguments | Why |
|---|---|---|
| `workflow_test.php` | `2-2 1-3` | A is confirmed as public domain, B stays unconfirmed |
| `notifications_test.php` | `2-1` | |
| `tasks_links_test.php` | `1-2` | |
| `events_test.php` | `_Import/performances.csv _Import/song-lists.csv 2-1 3-1` | 2-1 is current repertoire; the first ambiguous programme line (1-1 or 3-1) is resolved to 3-1 |
| `calendar_test.php` | – | creates its own calendar and concert (rolled back) |
| `maintenance_test.php` | `_Import/persons.csv 2-4 3-2 2-1` | 2-4 and 3-2 are two copies of the same print with an uncertainly identified editor; all persons of 2-1 died more than 70 years ago |
| `practice_audio_test.php` | `2-6 3-1 2-2` | 2-6 is protected without a license for practice audio, 3-1 an internal preservation copy, 2-2 public domain but not confirmed |
| `statistics_test.php` | `_Import/performances.csv` | the performance list gives the statistics concerts to count; no fixed figures |
| `modules_test.php` | `2-6` | 2-6 is protected without a license for practice audio |
| `api_v1_test.php` | `1-1 3-1` | 1-1 is public domain with MusicXML (and a demo report, which the test's own reports do not touch), 3-1 the internal preservation copy |
| `review_test.php` | `1-2` | MXL with four staves and without a demo report, so the review points of the demo reports do not interfere |
| `version_states_test.php` | `1-2` | MXL in a folder the CI account may write to; the corrected file is deleted again |
| `files_integration_test.php` | – | finds a MusicXML file and a PDF of copies itself; `LIESMICH.txt` is the unlisted file; creates and deletes a second account |
| `archive_extras_test.php` | – | finds a copy with MusicXML and the preservation copy itself; writes two annual snapshots into the archive folder and deletes them again |
| `backup_test.php` | – | |
| `sharing_test.php` | `1-3 3-1` | 1-3 is public domain with MusicXML (16 measures) and a PDF scan, not confirmed yet; 3-1 the internal preservation copy; creates and deletes a group and an account "member" |
| `folder_book_test.php` | `2-1` | issuing, taking back, the retention period with a staged day, the roles |
| `print_mirror_test.php` | `2-3` | the scan of 2-3, confirmed as public domain inside the test; the target folder is deleted |
| `handover_test.php` | `1-3` | public domain with MusicXML and a PDF scan, confirmed inside the test; both target folders are deleted |
| `settings_access_test.php` | – | creates and removes its own accounts, groups, share and delegation |

Finally (step 7) an **agent without a language model** uses the MCP server
(`tools/mcp`, built by the CI job; the script builds it when
`tools/mcp/dist` is missing). The script creates an account with the role
`contributor` (group `demo-agents`, random password passed only through the
environment) and, unless `NC_URL` names the web server of the instance,
starts PHP's built-in web server for the Nextcloud directory
(`php -S 127.0.0.1:8080`). `tools/mcp/ci/agent-demo.mjs` gets an app password
from `/ocs/v2.php/core/getapppassword` without printing it, starts the MCP
server over stdio like Claude Desktop would and calls `tools/list`,
`modules`, `search_catalog` (1-1, and 3-1 hidden), `list_due`,
`text_reference`, `propose_change` (the key of the version of 1-1, to be
rejected), `list_my_proposals`, `create_task`, `answer_task` (refused:
another role), `get_review` (the points of the demo report of 1-1, read by
the file sync), `list_correction_orders` and `submit_transcription_report`
(a new round of `tools/demo/reports/1-1.json` with one more point; the same
report again changes nothing; a report naming 3-1 is refused). It checks
that confirming rights, deciding a review point and saving a correction
with the app password are refused with 403 (internal API) and have no
route in api/v1, that
`occ cantoria:modules disable agent_api` makes the API answer 404 with
OCS meta, that the server log holds no secret, and that read-only mode
offers no writing tools; then it deletes the app password and checks that
it no longer signs in. `tools/ci/agent-demo-cleanup.php` rejects the
agent's proposals, discards its tasks and dismisses the review points of its
report as the librarian (the report stays as evidence); the account, its
group and the role are removed again.

To run the whole script against an existing test instance with a web
server, pass its URL:

```sh
NC_URL=https://nc33.example.org bash tools/ci/integration-demo.sh /var/www/nextcloud
```

The person and performance lists are imported only inside the tests'
rolled-back transactions. `events_test.php` expects no concert at all and
`maintenance_test.php` expects new persons in its dry run, so a real import
of these lists must never come before them.

Environment variables `CI_USER`, `CI_GROUP`, `DEMO_DIR`, `PHP`,
`AGENT_USER`, `AGENT_GROUP`, `NC_URL` and `NC_PORT` change the defaults. The script ends with exit code 0 only when every check and every
test passed and prints the lines of a failed test. On an instance that
already holds a catalog, back it up first (`occ cantoria:export --full`),
empty it, run the script with an account of its own, then empty the catalog
again, restore the backup (`occ cantoria:restore`), delete the account and
set the archive folder and the roles back.

### Upgrade from the base branch in CI

The CI job `upgrade` (Nextcloud 35, MariaDB and PostgreSQL) installs the
app as it is on the base branch: for a pull request its base commit, for a
push to `main` the commit before. It builds that version with its own
`tools/release/package.sh --unreleased` and runs its own
`tools/ci/integration-demo.sh`, so the instance holds the demo catalog and
everything the tests of that version left behind. Then the release archive
of the run replaces the app, and

```sh
bash tools/ci/upgrade-check.sh /path/to/nextcloud cantoria-<version>.tar.gz demo-librarian
```

saves a complete backup, runs `occ upgrade` and the pending migrations
(`occ migrations:migrate cantoria`, since a pull request usually keeps the
version number), checks that the app is enabled with the new version,
compares a second backup table by table (no table may disappear or lose
rows) and runs the file sync and the module list on the migrated data. A
new migration thus meets real data before it reaches an instance.

## Coverage

```sh
npm run test:coverage                                   # src/, summary in the terminal
vendor/bin/phpunit -c tests/phpunit.xml --coverage-text # lib/, needs pcov or Xdebug
```

The CI writes both summaries into the summary of the jobs "Frontend" and
"Unit tests 8.3" and keeps the PHP report (Clover) as the artifact
`coverage-php` for 14 days. There is no threshold: the figure shows where
tests are missing; the tests themselves are measured by mutations of the
code they guard (see the reviews).

## Static analysis and coding standard

```sh
composer psalm      # Psalm, see psalm.xml (no baseline)
composer psalm:tests # Psalm over tests/Unit, see psalm-tests.xml (errorLevel 5)
composer cs:check   # PHP-CS-Fixer with the Nextcloud coding standard, dry run
composer cs:fix     # apply the coding standard
composer lint       # php -l on all PHP files
```

The CI workflow (`.github/workflows/ci.yml`) runs all of these, the unit
tests on PHP 8.2, 8.3 and 8.4, the frontend checks, the REUSE check, the
check that `openapi.json` is current (`composer run openapi`, then
`git diff --exit-code`), the build and tests of the MCP server and the
integration tests with the demo data and the agent run on Nextcloud 33 and
35, and the upgrade from the base branch. Its first job, `changes`, skips build and tests when only
documentation changed (`docs/`, Markdown, `tools/screenshots/`, the GitHub
templates); the checks of the documentation and REUSE always run. Its last
job, **CI result** (`ci-ok`), is the required check of `main`: green only
when every job passed or was skipped for documentation alone
(`docs/release.md`).

## Licensing (REUSE)

The repository follows the [REUSE specification](https://reuse.software) 3.3,
like Nextcloud's own apps: every file states its copyright and license, the
license texts are in `LICENSES/`. A new file gets an SPDX header in the form
of its neighbours, for example in PHP after `declare(strict_types=1);`:

```php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
```

Vue files and Markdown start with `<!--` and `  - SPDX-…` lines, TypeScript
and JavaScript with a `/** … */` block, shell scripts with `#` lines after
the shebang. Code, templates, tests, icons, translations and screenshots are
`AGPL-3.0-or-later`; the demo data (`tools/demo/catalog.json`,
`tools/demo/scores/`) and trivial configuration (lock files, package
manifests, linter and tool configuration) are `CC0-1.0`. Files that cannot
hold a comment (JSON, images) or must stay unchanged (`appinfo/info.xml`,
generated `l10n/`) are listed in `REUSE.toml` instead; a new file in a folder
listed there needs nothing. Check with

```sh
pipx run reuse==6.3.0 lint   # or: pip install reuse==6.3.0 && reuse lint
```

The CI job `reuse` runs the same check with reuse 6.3.0, installed with the
hashes of all its dependencies (`tools/release/install-reuse.sh`,
`tools/release/reuse-requirements.txt`); the job `archive` checks the
content of the release archive with the same version.
