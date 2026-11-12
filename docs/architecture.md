# Architecture

This page is for developers. It shows how Cantoria is put together, where
a change belongs and which rules the code keeps. Setup, checks and style are
in [CONTRIBUTING.md](../CONTRIBUTING.md), the tests in
[tests/README.md](../tests/README.md).

## The parts

Cantoria is a Nextcloud app: a PHP back end in `lib/` and a Vue 3 front end
in `src/`, built with Vite into `js/`. The catalog lives in the Nextcloud
database (MariaDB, MySQL or PostgreSQL). The files (scans, MusicXML) stay in
the archive folder in Nextcloud Files; the app reads them with the rights of
the account that asks.

| Folder | What is in it |
|---|---|
| `lib/Controller` | the routes: thin controllers that check the module, call one service and turn its result into JSON |
| `lib/Service` | the domain logic, one service per subject (catalog, rights, concerts, tasks, backups, songbooks …) |
| `lib/Db` | entities and mappers (`QBMapper`), one per table; queries live here and nowhere else |
| `lib/Migration` | the database schema; released migrations never change (`ReleasedMigrationsTest`) |
| `lib/BackgroundJob` | the weekly backup, the daily checks and deadline reminders, checksums, annual snapshots |
| `lib/Middleware` | `ModuleMiddleware`: a route of a switched-off module answers 404 |
| `lib/Service/ApiV1`, `ApiV1*Controller` | the stable OCS API for agents ([api-v1.md](api-v1.md), `openapi.json`) |
| `src/` | the pages of the app, one entry point per page (below) |
| `src/score` | the score viewer: Verovio, MusicXML, playback, practice tools, WAV and MIDI |
| `src/sharing` | the pages for choir members and public links |
| `src/offline` | the service worker and the rules for pieces kept on a device |
| `tools/` | demo data, screenshots, translations, release, MCP server, CI scripts |

## Pages of the front end

Each page loads only what it needs; `vite.config.ts` lists them.

| Entry point | Page |
|---|---|
| `src/main.ts` | the app for librarians, conductors, board and auditors |
| `src/files.ts` | the action "Open in Cantoria" in the Files app (no Vue, no score viewer) |
| `src/adminSettings.ts` | Administration → Cantoria |
| `src/member.ts` | "Shared with me" for choir members: excerpts only, no catalog |
| `src/public.ts` | the page of a public link: one excerpt, no further requests |
| `src/concertFolder.ts` | the concert folder, also without a network |

Large libraries are loaded on first use in chunks of their own: Verovio for
the score, pdf-lib for the songbook.

Logic that can be tested without a browser is in plain TypeScript modules
(`catalog.ts`, `maintenance.ts`, `score/*.ts`, `songbook/build.ts` …) with
tests in `src/__tests__`; the components stay thin.

## A request, from the click to the database

1. The component calls a function in `src/api.ts`, which sends the request
   with the CSRF token and turns an error into a translated message.
2. The route is an attribute on the controller method (`#[FrontpageRoute]`).
   `#[RequiresModule('…')]` names the module it belongs to;
   `ControllerSecurityTest` fails for a route that is neither core nor in a
   module.
3. The controller runs the action through `RunsActions::run()`, which maps
   the domain exceptions to a status and a translated message: 403 for
   `AccessDeniedException`, 404 for `NotFoundException` or a switched-off
   module, 409 for a run already in progress, 400 for any other
   `UserFacingException`, and 500 with the request id for everything else.
4. The service checks the role (`Roles::requireRole`), validates the input,
   changes the data in a transaction and writes the audit log.
5. The mapper builds the query with the query builder.

## Rules the code keeps

- **Rights first.** `RightsPolicy` alone decides what may be shown, played,
  shared, printed or exported, from the confirmed rights and the licenses.
  Internal preservation copies are never passed on. Every new way out of the
  archive (a songbook, an export, a link) asks `RightsPolicy`, and its
  tests name the case.
- **Everything is logged.** `AuditLog::record()` writes who, when, what, old,
  new and why; changes that belong together share one operation id. Choir
  members appear only by their role.
- **Roles, not accounts.** Rights in the app follow the roles of the
  Nextcloud groups (`Roles`): catalog, operations, conductor, proposals
  (tools), reader (board and auditors) and choir members. Files are read
  with the account's own rights.
- **Modules.** Every feature beyond the core is a module (`Modules::MODULES`)
  that the administration can switch off; the user interface hides it and
  the routes answer 404.
- **Read what a request needs.** Count in the database, read the rows of
  one event, copy or work, not whole tables, unless the request is about
  the whole catalog.
- **No lost input.** Dialogs keep what was typed when a save fails, and the
  page warns before it is left with unsaved changes (`src/unsaved.ts`).
- **Both languages, both forms of address.** Every text of the interface is
  English in the code and German in `tools/l10n/translations.mjs`, informal
  and formal. The guides exist in English and German with the same
  sections; `docs.test.ts` compares them and checks quoted messages
  against the interface.

## Data

The catalog follows the archive: a **work** has **versions** (editions,
arrangements), a version has **copies** with shelf marks; the rights and
licenses belong to the version. Concerts and song lists are **events** with
**items**. Tasks, proposals, merges, folders of the concert folder, links
and the audit log have tables of their own. The complete backup
(`FullBackupService`) writes all of it as JSON and restores it into an empty
catalog; `FullBackupService::TABLES` lists the tables, and the integration
test `backup_test.php` fills every table, restores the backup and compares
them row by row.

## Where to start

- A new field: `Fields`, the entity, a migration, the form in
  `fieldGroups.ts`, the backup, the translations.
- A new route: the controller method with its module, the service, a unit
  test, `docs/api.md`.
- A new card on the start page: `DueService`, `dueLabels.ts`,
  `dueTargets.ts`.
- A new way to pass scores on: `RightsPolicy` and its tests first.
