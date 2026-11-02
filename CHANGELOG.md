<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this app are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Changed

- The German guides show every screenshot in the German interface
  (`docs/screenshots/de/`); `tools/screenshots` takes a German twin of each
  picture of the English guides, and a test keeps the German guides free of
  English pictures.

## [0.16.1] - 2026-10-29

### Changed

- The database tables carry the prefix `cantoria_` instead of `notarch_`.
  The migrations create them under the new names; there is no migration
  of the old tables, as the app was never published (the test instances
  started with new data).

## [0.16.0] - 2026-10-29

### Changed

- **The app is called Cantoria.** The app id is `cantoria` and the PHP
  namespace `OCA\Cantoria`: routes `/apps/cantoria/…` and
  `/ocs/v2.php/apps/cantoria/api/v1/…`, `occ cantoria:*`, the environment
  variables `CANTORIA_*` of the MCP server (`cantoria-mcp`) and the agent,
  the header `X-Cantoria-Page`, the folder `_cantoria-app` in the archive
  folder and the demo folder `Cantoria-Demo`. The interface names the app
  Cantoria in every language (Administration → Cantoria, "Open in
  Cantoria", the widget "Cantoria – due"); in German, "Notenarchiv" stays
  the word for the archive of a choir. The database tables keep their
  prefix `notarch_`. Before, the app ran as "Notenarchiv" (`notenarchiv`)
  on test instances only and was never published; links under
  `/apps/notenarchiv/…` no longer work.

## [0.15.0] - 2026-10-29

### Added

- **Practising without a network** (milestone 0.15, module "Practising
  without a network", off and part of no preset). On "Shared with me" a
  choir member keeps a piece on the device with **Keep on this device**,
  optionally **With the scan (PDF)**, and then views, plays and practises it
  without a network, with all tools of 0.13 and 0.14; practice files still
  need the network. No app to install: a service worker of the app in the
  browser. Only where the rights allow saving the notes; every start with a
  network checks the kept pieces, removes expired and revoked links and
  locks them after 30 days without a check. **On this device** shows the
  space used and deletes everything kept, passages and settings included.
  New routes `GET /api/member/links` and `GET /offline-worker` (public, as
  public links register the worker, too).
- **Concert folder** (milestone 0.15, module "Concert folder", off and part
  of no preset): the conductor and the catalog librarian open the programmes
  still to be sung on a page of their own, view, play and practise each
  piece with its scan, turn to the next piece, and keep a whole programme on
  the device for the rehearsal and the concert without a network. Rights as
  in the score view; internal preservation copies are never kept. New
  routes `GET /concert-folder`, `GET /api/concert-folder` and
  `GET /api/concert-folder/items/{id}/score` and `…/scan`.
- **Public links without a network** (milestone 0.15): a public link to a
  piece whose public domain is confirmed can be kept in the browser, too;
  each visit with a network checks it.
- **The scan inside the page** (milestone 0.15): on "Shared with me", the
  concert folder and public links, **Show the scan (PDF)** shows the scan in
  the page, also without a network where it was kept; on an iPhone or iPad a
  new tab would fail without a network.
- **Keep all pieces on this device** on "Shared with me" (milestone 0.15).
- **Practice settings remembered per piece** (milestone 0.15): tempo,
  pitch, metronome and mixer of a shared piece stay in the browser, also
  for a new link to the same version. Passages of shared pieces move from
  title and composer to a key of the version (`practiceKey`), so that a
  corrected title keeps them.
- **Guide: the hand-over folder in the Nextcloud app** for choir members
  with Nextcloud accounts, made available offline there (administration
  guide, "Hand-over to a choir system").

- **Practising in the score view and for shared pieces** (milestone 0.13,
  from a comparison with practice apps). Under "Tempo, volume and
  practising":
  - **Starting tones** play the first tone of every voice from the start of
    the playback, from the lowest up, and announce their names.
  - **Metronome** clicks the beats while the music plays.
  - **Pitch** moves the sound up to six semitones; the notes stay as
    written. Practice files take the pitch, MIDI files with the matching key.
  - **Mixer**: a level per voice on top of "Voices during playback"; the
    practice file of a shared piece sounds like it.
  - **Passages**: remember the shown measures with a name, per piece in
    this browser; a click shows the passage and plays it in a loop.
  - **Record myself**: sing along with the playback, listen to the
    recording alone or with the accompaniment, save or delete it. The pages
    allow the microphone for themselves and media from the browser's memory;
    the recording never leaves the browser.
  - **Page-turn pedals**: Page Down and Page Up turn the score by one
    system.
  Everything happens in the browser; nothing about practising is stored.
- **Read the lyrics along** (milestone 0.14): the line of the focus voice
  above the score, the sung syllable bold and underlined, the next line
  below and the verse of the current pass. **Learn the lyrics** hides
  letters in three steps down to the first letter of every word.
- **Show my pitch** (milestone 0.14): with a focus voice, the microphone
  listens while the switch is on; its tones are shown as boxes and the
  singing as a line, and in words how far off it is. The pitch is found in
  the browser; nothing is recorded, kept or scored.

### Changed

- **The module "Practising without a network" no longer needs "Links for
  choir members"**: it also acts for public links. The service worker moved
  to a route of its own that is always on and needs no login.
- **Backups outside the archive folder.** The weekly and manual backups
  (CSV, ODS with the audit log, complete JSON backup) and the annual
  snapshots are written into a backup folder "Music archive backups"
  ("Notenarchiv-Sicherungen") in the files of the account that set the
  archive folder, no longer into `_notenarchiv-app` inside it, which all
  role groups read. The first backup after the update moves the old backups
  and snapshots there; the settings page names what is left. Exports of the
  catalog stay in `_notenarchiv-app`.
- Backups hold **no link tokens**: after a restore every link is revoked.
- **Retention:** of the weekly backups the newest eight are kept. Annual
  snapshots name nobody: no accounts, no folder book, audit log, tasks,
  proposals, links or reviews. The checksums of a snapshot come from the
  checksum job while a file is unchanged since.
- The complete backup is written piece by piece instead of as a whole in
  memory. The daily check notifies the catalog role when the last complete
  backup is older than eight days.
- **Delegated settings:** a group given the settings page can no longer give
  a role to a group it belongs to, set or change the archive folder, or
  switch on the modules that open the archive to the outside (agents,
  choir members, the public, the print station). Changes of roles, groups
  and the archive folder are in the audit log.
- The administration guide names the trash bin and the file versions of
  Nextcloud, which keep deleted backups.
- **Runs never twice at the same time:** the file sync, the sync of the
  print station and the annual snapshot are refused (409) while the same
  run is in progress, whether it came from the app, `occ` or a background
  job; the jobs skip and try again later. A run started in the app keeps
  going when the page is closed. Archive management shows a running file
  sync and the counts of the last one.
- The file sync only writes the rows of files that changed.
- **Preservation copies checked in one place.** A copy of the kind
  "Preservation copy" now counts as an internal preservation copy
  everywhere, like a version marked as one: in the list, the search, the
  inventory, scores, scans, links, corrections, the print station and the
  API for agents. The app's own pages leave preservation copies out of the
  list for roles that may not view them and refuse to open them or to file
  a task on them (403), as the API for agents already did.
- **Link tokens stored as hashes.** The database holds the hash of each
  link token and the token encrypted with the secret of the instance;
  existing links keep working. Only the creator and the catalog librarian
  see and copy the address of a link for choir members or a public link;
  link lists name creators by display name, not by account, and leave out
  links to preservation copies for roles that may not view them.
  `SECURITY.md` names the web server logs, which record the addresses of
  public links.
- **New license uses:** "Online playback (audio on public links)",
  "Download and printing by choir members (scan in links)" and
  "Digitisation (scans, MusicXML transcriptions)". A public link plays a
  protected version only with a license for online playback; a license for
  practice audio covers the choir only. The scan in a link for choir
  members needs confirmed public domain or a license for download and
  printing by members (or self-printing); the dialog says why it is
  missing and that the whole PDF is shared.
- **Copies without a license:** the rights tab warns when photocopies of a
  version that is not confirmed public domain have no license for copies
  for the binders, outnumber the licensed quantity, or when scans and
  transcriptions have no license for digitisation. The start page counts
  the current repertoire, a catalog filter lists the versions, concert
  programmes show the warning. Nothing is blocked.
- **Public domain needs composer and lyricist.** The protection proposal
  says "unknown" instead of public domain when no composer or no lyricist
  is recorded; the new version fields "Melody" (Traditional) and "Text"
  (Traditional, Without text) cover parts without a known author.
  Confirming public domain against the proposal asks to tick that it was
  checked. A new notification "Public domain in doubt" reports confirmed
  public domain that the proposal now says is protected.
- **Attribution on shared pages:** the pages of links for choir members and
  the public name lyricists, arrangers and translators from the person
  register (names only) and show the new license field "Notice on shared
  pages".
- **Data protection package.** Practice audio of choir members is recorded
  with the role only, without account or name (older entries are
  anonymised on the update). Persons without a known death keep only the
  year of birth, also in the free text. A deleted Nextcloud account is
  removed from the folder book and the audit log. A folder that never comes
  back loses its holder 36 months after the issue; the folder book shows
  the day 90 days ahead. New commands `occ notenarchiv:privacy-report` and
  `notenarchiv:privacy-erase` for access and erasure. The module settings
  warn before public pages go online without imprint or privacy policy in
  Nextcloud's theming. Templates for the register of processing activities,
  the information of choir members and the privacy policy of the public
  pages (`docs/privacy-templates.md`, `docs/de/datenschutz-vorlagen.md`);
  agents are recommended the role "Proposals (tools)" only.
- **Legal background** (`docs/legal-background.md`,
  `docs/de/rechtlicher-hintergrund.md`): protection terms, photocopies of
  sheet music, preservation copies and their limits for digital copies,
  transcriptions, sharing (§ 19a UrhG, no § 60a for choirs), GEMA and
  publishers, imprint and privacy policy of public pages, agents. The guides
  no longer give the scan of a protected print as an example of a
  preservation copy and explain §§ 70 and 71 UrhG more precisely.
- **Song order like the GEMA music report:** rows with the event first,
  separate columns for composer, lyricist and arranger (from the register
  of persons), the new version field "Work number (GEMA)", the rights
  status; headers in the language of the account.
- **One word, one meaning** in German: "Stand" is only the revision of a
  version (with help at its place), "Ausgabe" only the loan of a folder,
  "Freigabe" only the release by the rights; sharing is "teilen" ("Mit mir
  geteilt"), the loop of the playback is "Schleife", no longer
  "Wiederholen". A check in `tools/l10n` keeps it so. The correction form
  names the note B "H" in German and Polish.
- **Error messages stay** until they are closed and say what to do, also
  for errors of the server: log in again, ask for the role, reload, try
  again with the request ID for the Nextcloud log, instead of "HTTP 500".
  The inventory names the rows it could not record (shelf mark and reason)
  and records nothing until they are corrected.
- **Focus of the sidebar:** opened from a row or a link, the focus moves to
  the name of the copy in the sidebar; closing it takes the focus back to
  the row.
- **On the phone:** the page "Shared with me" shows the list before the
  notes (on wide screens beside them); the score views remember the size
  of the notes in the browser; the score view of the archive shows only
  play and stop above the notes until "Measures, voice and size" is chosen.
- **Printouts** carry a header with the name of the Nextcloud (usually the
  choir), the view, the filters that are on and the day, and page numbers
  where the browser supports them.
- **Hand-over to a choir system** (module `choir_handover`, part of no
  preset): like the mirror folder of the print station, a folder of its
  own gets what the singers' portal releases, the scans and MusicXML files
  choir members may save and the practice recordings the rights allow,
  with `Liste.csv` (version, revision and license notice per file) and the
  notes of the conductor per passage from the links for choir members as
  `… Hinweise.txt`. Preview, first sync by hand, then daily; the app
  uploads nothing to the choir system. New routes `/api/choir-handover…`,
  `/api/settings/choir-handover…`; the table `notarch_mirror_files` gets
  the column `mirror`.
- **Compare with the choir system:** an export of the choir system (CSV or
  Excel) is compared with the list of the singers' portal; pieces there but
  not recorded, and pieces recorded but missing there, become proposals in
  the inbox for "In singers' portal since", pieces there but not released
  are listed to be removed there. New route `POST /api/lists/portal/compare`.
- New guides **Notenarchiv alongside a choir system** (who keeps what, what
  never goes there, concerts as calendar files) and an **accessibility
  statement** with the known limitations, in English and German.
- Excerpts and whole pieces for choir members and the public no longer
  carry the encoder, the miscellaneous fields, XML comments or processing
  instructions of the MusicXML file.
- `SECURITY.md` starts with a table of what the app promises and which test
  checks it; a test makes sure every named test exists.

### Fixed

- Continuous integration: the unit tests get enough memory in PHP images
  without a php.ini, Psalm knows the PHP extensions the app needs
  (`ext-dom`, `ext-intl`, `ext-mbstring`, `ext-xmlreader` and `ext-zip` in
  `composer.json`), the integration job reads the checksum of its own
  Nextcloud archive, the tests of the MCP server are found again, and the
  translation files come out in the same order on every machine.
- Reading a file of a user (MusicXML, spreadsheets) no longer reserves the
  whole size limit at once; the file is read in blocks.
- The documentation checks also run when only documentation changed (own
  workflow `Docs`).
- The print station reads scans only from the archive folder.
- The rights notice of a MusicXML file is not read when an internal DTD
  subset follows a long prolog.
- The integration job builds the PHP image extension `gd` with FreeType,
  which Nextcloud needs to draw the avatar of a new account.

## [0.9.1] - 2026-10-23

### Fixed

- Phones and small screens: the page of a public link and the public
  repertoire page could not be scrolled at all. Nextcloud's public layout
  holds the content to the height of the window and clips it; the pages
  now scroll in their own element (and are the main landmark of the page).
- The score of a shared piece (page **Shared with me** and public links)
  and the score viewer of the archive no longer sit in a box of their own
  on small screens: a swipe on the notes moves the page, instead of
  scrolling the box first, through all pages of a whole piece. On large
  screens the box stays.
- **Play**, **Pause** and **Stop** stay at the top while the notes scroll
  past (shared pieces also with **Smaller** and **Larger**); on shared
  pieces the other choices are under **Settings**, folded on phones, so
  that the notes start high up. The measure being played is kept in view
  below this bar.
- A tap on a note on a phone starts the playback there: the browser may
  name the whole score as the target of a tap, so the point is looked at
  again and the nearest note within 12 pixels is taken.
- Touch screens: buttons of the score views and of **Shared with me** are
  at least 44 pixels; the summaries **MusicXML review** and **Description of
  the piece** and the sort buttons and shelfmarks of the catalog table are
  at least 24 pixels high.

## [0.9.0] - 2026-10-22

Milestones 0.7 to 0.9 of the roadmap: sharing with choir members and the
public (whole pieces and excerpts), folder book and print station mirror,
archive extras, revisions of a version, playback additions, user guidance,
settings under Administration, the Files app action, guides in German and
English and the interface in seven languages. New functions that open the
archive or write files are modules that start switched off. Not yet
published in the app store; tested against Nextcloud 33.0.9 and 35.0.2
with MariaDB.

### Added

- Whole pieces in links for choir members and public links: the dialog
  **Share …** asks **What is shared?** with **Whole piece** (the default)
  or **Excerpt (measures)**; it starts with the excerpt when the score
  viewer shows a range. A link without measures is the whole piece: the
  server sends the complete MusicXML of the copy with its full playing
  order (repeats, endings, jumps), read as before through the account of
  the archive folder, `.mxl` unpacked, never the file itself
  (`MusicXmlExcerpt::whole`). The same rights as for an excerpt (guideline
  2). The page "Shared with me" and the public page show all pages, play
  with focus voice, count-in and start at a note, and can show and loop a
  chosen range of measures (**From measure**, **To measure**, **Show**,
  **Loop**). Practice files of choir members work for the whole piece too,
  with the same rights checks and audit entry; for a whole piece the
  request names the measures of the file.
- Optional scan in links for choir members and public links: **Include the
  scan (PDF)** when the copy has a PDF scan, with the same release as the
  notes (singers' portal for choir members; release for publication plus
  confirmed public domain or a license for online publication for the
  public), never for preservation copies or internal links. Shown inline by
  the browser's PDF viewer (`GET /api/member/links/{token}/scan`,
  `GET /p/{token}/scan`; `nosniff`, the policy of the scan beside the
  score); unknown, expired and refused public tokens get the same 404. The
  dialog and SECURITY.md say plainly that whoever sees the scan can save
  it. Migration `Version000830Date20261028100000` adds
  `notarch_links.scan_file_id`; `GET /api/copies/{id}/link-options` lists
  the scans of the copy.
- The dialog shows under **What the recipients see** whole piece or
  measures, voice, playback, practice files, scan and expiry; the list of
  links shows **Whole piece** or the measures and **with scan (PDF)**.

- Settings under **Administration → Music archive** instead of a dialog in
  the app: archive folder, roles and groups, modules, repertoire and (for
  admins) complete backup and restore, on a page with its own small bundle
  (no catalog, no score viewer). "Settings" in the app links there. Admins
  can give the page to a group under Administration privileges; the
  settings routes accept admins and that group
  (`#[AuthorizedAdminSetting]`, checked again by the app). This gives no
  role; restoring a backup stays with admins and the catalog role. New route
  `GET /api/settings/groups` to choose groups, also for that group.
- The settings page guides through the setup: a list "Setting up" at the
  top shows the five steps (archive folder, roles and groups, access to the
  archive folder, import, file sync) as done, still to do or needing
  attention, derived from data the server already has, with a link to each
  place. Mistakes are caught before saving: a chosen archive folder is
  checked at once for role holders who cannot open it, the page says what
  saving does, the roles form lists which groups get or lose a role and
  warns when the catalog role would have no group. Each section and each
  hint links its part of the administration guide, which has new sections
  "Setting up in five steps" and "Common problems".
- Access to the archive folder: the settings page checks who of the role
  groups and of the admins without a role can open the archive folder in
  their own Nextcloud, names those who cannot and says what to do (share
  with the groups of the roles or use a team folder; reading is enough). It
  only reads (`GET /api/settings/access`, at most 500 accounts).
  `occ notenarchiv:setup` prints the same in short.
- When the archive folder is not shared with an account, the app says so at
  the top of the page, on the tab "Files" and in the score view ("The file
  lies in a folder that is not shared with your account …", response
  `reason: "not_shared"`), with a link to the access check for those who may
  change the settings. The meta data tell the UI `archiveAccessible` and
  `canChangeSettings`.
- Sharing beyond the archive roles (milestone 0.7), four modules that are
  off and part of no preset, not even Complete:
  - **Links for choir members**: a new role "Choir members" (groups as for
    the other roles, also `occ notenarchiv:setup --role member=…`) that is
    no archive role and only sees the page "Shared with me": the excerpts
    shared with the choir, to view and play with a voice emphasised. Only
    for versions released for the singers' portal.
  - **Practice audio for choir members**: a WAV or MIDI file of one voice of
    a shared excerpt, where the rights allow practice audio; recorded in the
    audit log like every export.
  - **Public links**: an excerpt to view and play without login at
    `/apps/notenarchiv/p/{token}`, for versions released for publication
    that are confirmed public domain or licensed with the new use "Online
    publication (public links)"; it expires, can be revoked and counts its
    calls; no downloads, no names, no note.
  - **Public repertoire page** at `/apps/notenarchiv/repertoire`: works
    chosen by the catalog librarian (new work field "On the public
    repertoire page", only with a version confirmed as public domain) with
    life dates, scoring and the new fields "Link to CPDL" and "Link to
    IMSLP"; nothing is loaded from there.
  - Links of all circles are made in the score view with **Share excerpt …**:
    the dialog asks who can open the link and shows beforehand what the
    recipients see and, if a link is not possible, why and what to do
    (`GET /api/copies/{id}/link-options`). The view **Internal links** is now
    **Links** and names the circle of each link.
  - The server sends choir members and the public only the measures of the
    excerpt, cut from the score with the attributes and tempo valid at its
    start; repeats and jumps that lead out of it are left out.
  - Integration test `sharing_test.php`; migration
    `Version000710Date20261025100000` (three work columns).
- Folder book (milestone 0.7, module `folder_book`, off in Standard, on in
  Complete): choir folders with number or name, voice, contents (copies of
  the catalog with a number), condition and missing sheets; issuing to a
  Nextcloud account or a name and taking back with the condition and the
  missing sheets filled in. A folder is out at most once at a time (also a
  unique index in the database); folders are never deleted, only taken out
  of service. A hint marks folders holding a copy that was retired or a
  version that is no longer the valid one. The list filters, searches and
  prints on A4. Catalog and operations roles maintain it, conductor and
  readers read it; contributors and choir members never see it, nor its
  audit entries. After a retention period set by the administration (default
  12 months after the return) the daily checks remove names, accounts and
  notes of the loan, also from its audit entries, and note the removal
  without the name; this runs also while the module is off. New routes
  `/api/folder-book…`, `/api/settings/folder-book`; tables
  `notarch_folders`, `notarch_folder_loans` (part of the complete backup).
- Mirror folder for the print station (milestone 0.7, module
  `print_mirror`, part of no preset): the PDF scans of the versions on the
  list of the print station (never preservation copies) are copied into a
  target folder chosen by an administrator, as `<shelf mark> <title>.pdf`
  with a `LIESMICH.txt`, daily and on request. The target folder is checked
  when it is chosen and before every sync (not the archive folder, not
  inside it, write permission). Every sync can be previewed; the daily sync
  starts after the first sync by hand. Changed scans are replaced and scans
  no longer released removed, but only files the app wrote itself and
  nobody changed since (manifest with SHA-256 in `notarch_mirror_files`);
  other files stay. Every write and removal is in the audit log; the report
  lists what was copied, replaced, removed or skipped and why. Notification
  "Print station sync failed" to the catalog role and the administrators.
  New routes `/api/print-mirror…`, `/api/settings/print-mirror…`. Guideline
  4 of the roadmap names this exception.
- Milestone 0.8, archive:
  - Special cases of the protection term per version (module "Protection
    terms"): scientific edition (§ 70 UrhG) and posthumous work (§ 71 UrhG)
    with the year first published. The suggestion adds 25 years after that
    year; editors count with this term instead of 70 years after their death;
    without the year the version counts as protected. Each switch explains in
    one sentence when it applies. A suggestion only; the rights status is
    never set.
  - License use "Transfer to a legal successor" with an explanation.
  - Search of Nextcloud (new module "Nextcloud search", on in Standard and
    Complete): copies by shelf mark, title and person, opening the app with
    `?copy=`; only for accounts with a role of the catalog and admins,
    preservation copies only for the roles that may view them; pages of at
    most 50, 300 searches per account in 10 minutes.
  - Gaps in the shelf marks per binder (module "Inventory",
    `?view=shelfmark-gaps`, `GET /api/inventory/gaps`), printable in A4; for
    information only, numbers are never reused.
  - Annual snapshots (new module "Annual snapshots", on in Complete): the
    catalog as CSV and JSON and a SHA-256 manifest of the archive files in a
    dated folder `_notenarchiv-app/annual-snapshots/`, on request (the dialog
    says what is written where) or in January for the year before
    (`AnnualSnapshotJob`); comparison of two snapshots (copies, rights,
    licenses, files), printable in A4 (`?view=annual-snapshots`,
    `/api/annual-snapshots…`).
  - Rights notice of MusicXML files (`<identification><rights>`), read by
    the file sync and shown with the file as "Not checked"; when it names
    the public domain or a license, at most a proposal in the inbox.
  - Templates for outreach material (`tools/outreach`, not part of the app or
    the release archive): flyer DIN lang, business card and two banners with
    a sender per choir, built with Node and puppeteer-core.
  - Migration `Version000810Date20261026100000`: five columns in existing
    tables, no new table.
- Playback (milestone 0.8), in the score viewer and in the view of shared
  excerpts:
  - Count-in of one or two bars (**Count-in** under "Tempo and volume"), in
    the time signature and tempo of the place where the playback starts; a
    pickup comes in on its last beats. Remembered per person in the browser.
    Practice files can start with it, too (WAV: clicks; MIDI: a drum track of
    its own).
  - A–B loop: the switch **Loop** next to "From measure" and "To measure"
    plays the shown measures again and again, without a gap; in a shared
    excerpt the whole excerpt. Announced to screen readers.
  - A click on a note or a measure starts the playback there (at its next
    pass in a repeat), with one handler on the score container; the cleaned
    SVG carries no handlers. Keyboard alternative: the arrow keys left and
    right in the score go to the previous or next measure in playing order.
- The interface in French, Spanish, Italian, Dutch and Polish (`fr`, `es`,
  `it`, `nl`, `pl`), with plural rules that agree between server and
  browser (three forms in Polish) and a glossary of the terms per language
  (`tools/l10n/glossary.md`). These translations were made with the help of
  AI and need a review by native speakers; please open an issue or pull
  request. A missing translation of these languages falls back to English;
  German stays complete. `npm test` checks placeholders and plural forms.
- The user guide and the administration guide in German
  (`docs/de/benutzerhandbuch.md`, `docs/de/administrationshandbuch.md`),
  complete and with the same sections as the English guides; each guide
  links its counterpart at the top, and the German quick start leads to
  both. In a German interface every link of the app ("Help", the **?** next
  to a term, the hints and the settings page, the page "Access forbidden")
  opens the matching section of the German guide, otherwise of the English
  one; `src/docs.ts` holds both anchors of each section. `npm test` checks
  that the anchors exist in both languages, that both versions have the same
  headings in the same order, and that every link of the guides leads to an
  existing file and section.

### Changed

- The description in the App Store names the functions of milestones 0.7
  to 0.9, the modules that start switched off and the seven languages.
- Screenshots of the documentation and the App Store taken again with the
  demo data, among them the new pictures of sharing, the folder book, the
  mirror folder, revisions, the review of transcriptions, annual snapshots
  and the Nextcloud search; `tools/screenshots --prepare` creates their
  state.

### Fixed

- Cleaner sound of the playback and the practice audio files (it sounded
  a little scratchy), measured on rendered demo pieces:
  - No dynamics compressor any more: its fast gain changes distorted chords
    (intermodulation −33 dB below the signal, now −65 dB). A static soft
    limiter only bends peaks above 0.7; dense scores (more than four voices
    at full balance) are turned down as a whole, the balance of the voices
    stays.
  - Stop, pause, a jump and the end of the view fade out from the level
    each note has (30 ms). Before, a note about to start could sound as a
    short burst, and a pause during an attack jumped.
  - Softer tone: fewer upper harmonics, the vibrato sets in after the
    attack; the count-in click starts in 4 ms; notes end at silence.
  - A late timer no longer plays notes that are already over.
- Found in the browser acceptance of milestones 0.7 to 0.9 in Nextcloud 33:
  - The public repertoire page has a background of its own: its text stood
    directly on the background picture of the public layout with too little
    contrast. A composer written in the catalog (e.g. "Volksweise") is shown
    when the register of persons names none.
  - Buttons whose accessible name did not contain their visible text (WCAG
    2.5.3): "Set as valid" in "Choose valid versions" and the loans of a
    folder in the folder book.
  - Folder book: the buttons of a folder stay in its table row.
  - Labels and values no longer run together in the box "What the
    recipients see" of the share dialog and in the figures of the import and
    file sync reports; without a voice the box says "No focus voice".
  - The role selects in the settings have one width.
- Playing order checked against the test cases of the parallel development
  (own MusicXML test files, `src/__tests__/playOrder.test.ts`):
  - A backward repeat goes back to the last forward repeat or the beginning,
    as MuseScore 4 plays it, no longer to the measure after an earlier
    backward repeat.
  - `times="3"` with an ending "1, 2, 3" and a fourth ending plays four
    passes (before: three, and the fourth ending was left out).
  - Jump marks (segno, coda, To Coda, D.C., D.S., Fine) count from every
    part, not only from the first one; a D.C. or Coda written only in a
    lower voice was ignored before.
  - Printed segno and coda signs stand in for missing playback marks.
- Milestone 0.8, revisions of a version (new module "Revisions of a
  version", `version_states`, needs the score view, on in Standard and
  Complete):
  - Every correction in the app and every new or changed MusicXML found by
    the file sync becomes a revision of the version: a record that points to
    the file, with its SHA-256, the measures and voices changed, a note
    (reason and review points of a correction, or the transcription report
    of the sync), who and when. No file is copied or changed. The first file
    sync after the module is switched on records revision 1 of every
    version; there is no repair step.
  - The catalog librarian sets the current revision of a version (tab
    "Files", "Revisions of this version"), with a reason, recorded in the
    audit log; nothing sets it automatically.
  - Two revisions are compared in the browser on their files, measure by
    measure and voice by voice (pitches, accidentals, note values, lyrics,
    measures and parts added or removed), with a plain-text note for choir
    members to copy; in German with German note names (H, B). A file that
    was overwritten in place is recognised by its checksum.
  - The version line (shelf mark · version · revision · valid since) in the
    score view and its text reference, as a comment in practice files (MIDI
    text event, WAV INFO chunk), in the programme, the printed lists and the
    song order (columns "Revision", "Revision valid since"). Concerts keep
    the revision sung when they are confirmed as sung.
  - Routes `GET /api/versions/{id}/states`, `PUT /api/states/{id}/current`
    (catalog librarian, rate limited); API for agents
    `GET /api/v1/copies/{id}/states` and the MCP tool `list_revisions`.
  - Migration `Version000820Date20261027100000`: table `notarch_states` (part
    of the complete backup) and the column `notarch_event_items.state_id`.
- Guidance (milestone 0.9):
  - Help at the place: a small "?" next to the terms version, copy, shelf
    mark, rights status, release, valid version and internal preservation
    copy opens one or two sentences and a link into the guide (the German
    quick start in German). One component (`HelpTerm.vue`), all texts in
    `src/helpTerms.ts`; keyboard operable, Escape closes it and the focus
    returns to the button.
  - Errors say whom to ask: accounts without a role get a page "Access
    forbidden" (and in the app a message) that asks to have the account
    added to the group of a role, with a link to the guide. Empty views name
    the next step: tasks (show all tasks or open the catalog), inbox, links,
    release lists and the start page; the register of persons can be loaded
    again after an error.
  - Before confirming rights, the dialog says what the confirmation makes
    possible (singers' portal, print station, practice files, sharing,
    public links; only switched-on modules) or that a protected version needs
    licenses.
  - New view for the conductor "Choose valid versions"
    (`?view=valid-versions`): the works with several versions and none
    valid, the valid version set right in the list with the existing route
    `POST /api/versions/{id}/valid` (no new rule, no new route).
  - Catalog: search, rights status and repertoire stay visible, the other
    filters fold away under "More filters" (remembered in the browser,
    opened by a card of the start page, with the number of active filters).
    From a list filtered for rights to confirm or a missing valid version a
    copy opens on the tab Rights.
  - Shorter sidebar tabs that are no longer cut off: Details, Rights,
    Queries, Files, History.
  - The tab Files shows the folder of each file and an icon per kind.
  - Dates in text fields (e.g. "last performed" from the import) and months
    without a day are shown like every other date.
  - The inbox names who proposed with the display name
    (`GET /api/proposals` adds `created_by_name`).
  - Notices about the whole app (archive folder, administrator without a
    role) stand under the heading of the view, the one for administrators
    without a role with a link to the guide.
  - Dashboard widget: tasks with the shelf mark as short title and the work
    in the second line; an icon per kind (concert, card, task).
  - User guide reorganised by tasks per role ("As a conductor", "As a music
    librarian", …) with the terms and a collected "Common problems"; the
    German quick start with "Begriffe" and "Häufige Probleme".

### Security

- The public pages of the sharing modules (a public link, the public
  repertoire page) are the only routes without a login; a unit test lists
  them and the routes choir members reach. They are limited per address,
  protected against guessing tokens and answer every failure with the same
  404 page; choir members and the public never receive a file of the archive
  (see `SECURITY.md`).
- The complete backup can no longer be downloaded by the role "Proposals
  (tools)": it contains the folder book with names of choir members.

## [0.6.0] - 2026-10-22

Milestones 0.1 to 0.6 of the roadmap; not yet published in the app store.
Developed and tested against Nextcloud 33.0.9 and 35.0.2 with MariaDB; the
app requires MySQL or MariaDB. The internal builds before this version were
numbered 0.1.x.

### Added

- Catalog with works, versions and copies (shelf marks), field registry with
  per-field write roles, audit log of every change, status instead of deletion.
- Import of an existing catalog from CSV (dry run, report, snapshot, repeated
  imports that keep decisions taken in the app) and byte-exact export.
- File sync of the archive folder by shelf mark; files are only read.
- Roles bound to groups: catalog and operations librarian, conductor,
  contributor (proposals), read only; admins configure.
- Rights per version: recommendation kept, confirmation with reason and
  evidence, licenses with separate uses, three separate releases (singers'
  portal, print station, publication), preservation copies never shared or
  played, valid version chosen by the conductor only.
- Proposals inbox for tools and transcription rounds.
- Score viewer: MusicXML rendered with Verovio (bundled), printed bar ranges,
  voice focus also within a staff, playback with emphasised voice, own
  repeat/ending/D.C. handling, keyboard operation, live bar announcements and a
  text description of the excerpt.
- Questions and tasks with a location in the score, internal excerpt links
  (expiring, revocable, rights checked on every call, anonymous call count),
  text references, start page "due".
- Concerts and song lists with rights warnings and deadlines, confirmation of
  what was sung, derived repertoire figures, song order export, import of
  performance records and song lists from CSV or Excel.
- Maintaining the catalog: accession assistant with shelf mark suggestion and
  printable labels, register of persons with contributions per version and a
  protection-term suggestion with its reasoning, merging works and copies with
  undo, inventory lists per binder with counts, background checksums of the
  archive files with mismatch warnings.
- Statistics for board, archive management and conductor (`?view=statistics`):
  stock per binder, new copies per year, rights and releases, licenses,
  concerts and pieces per year, most performed works and composers,
  repertoire classes, works not sung for a long time, open tasks by age,
  checksums, protection terms ending; a printable annual report (A4, also in
  large print) and CSV downloads of both. No personal data of choir members.
- Licenses ending within 30 days or already ended, with the copies concerned;
  merges show who merged or undid them and what was moved.
- Notifications to role holders (never to singers).
- Weekly backup into the archive folder (CSV, ODS with audit log, complete JSON
  backup of all tables) and manual backups; restore of the complete backup
  into an empty catalog (UI and `occ notenarchiv:restore`), `occ
  notenarchiv:export --full`; daily checks for licenses, portal releases,
  concert deadlines and ended protection terms.
- File sync re-links files whose id changed (e.g. after a restore or a move
  back) by their path; manual links are kept.
- German translation in informal and formal address, inclusive wording.
- Demo archive of a fictitious choir (`tools/demo`, CC0) for trying out the
  app, screenshots and end-to-end tests.
- Unit tests (PHPUnit), integration tests against real Nextcloud instances,
  static analysis (Psalm), coding standard, CI workflow.
- CI on GitHub Actions also runs the integration tests with the demo data
  against fresh Nextcloud 33 and 35 instances with MariaDB.
- Practice files per voice from the score view (librarian and conductor): WAV
  rendered in the browser with the playback's synthesizer and playing order,
  or MIDI with one track per voice; one file or a ZIP with every voice plus a
  tutti. The server allows each export only for confirmed public domain or
  versions licensed for practice audio, never for preservation copies, and
  records it in the audit log (`POST /api/versions/{id}/practice-audio`).
- Dashboard widget "Music archive – due" for accounts with an archive role:
  the start page's concert deadlines, cards and tasks with links into the app
  (new start parameter `?due=` opens a card with its filters).
- Concerts in the calendar: optional start and end time of a concert,
  calendar files (iCalendar, RFC 5545) of a concert and of all upcoming
  concerts, "Add to my calendar" into a writable Nextcloud calendar of the
  account (adding again updates the entries instead of duplicating them),
  the preparation deadlines optionally as separate all-day entries. No public
  subscription link.
- Concerts can be cancelled and reopened; the start page lists concerts with
  a deadline now.
- Modules and presets (milestone 0.5.1): every function outside the core
  (catalog, files, roles, audit log, import, export, backup, settings) is a
  module that admins switch off under Administration → Music archive →
  Modules or with
  `occ notenarchiv:modules list|enable|disable|preset`, grouped, with
  descriptions and dependencies (switching on also switches on what a module
  needs; switching off names what stops working). A switched-off module is
  off on the server, too: its routes answer 404 ("This function is switched
  off."), background jobs, notifications, start page cards and the dashboard
  widget leave it out. Data are never deleted and no rights check is relaxed.
  Notifications can be switched off per occasion. Presets "Simple",
  "Standard" (new installations) and "Complete"; installations updated from
  before 0.1.27 keep every function. The interface for agents starts off
  and is part of no preset.
- Interface for agents (milestone 0.6, first part): a stable, versioned API
  (OCS, `/ocs/v2.php/apps/notenarchiv/api/v1/…`, `docs/api-v1.md`) with its
  OpenAPI description `openapi.json` (generated with
  nextcloud/openapi-extractor, checked in CI, part of the release archive).
  Agents read the catalog (search with filters and cursor pages, a copy with
  version, work and rights), the due list of their roles, questions and
  tasks, concerts with their programmes and statistics, build text
  references, create and answer questions and tasks, and propose changes
  and read their own proposals. Nothing decides: there are no routes to
  confirm rights, change licenses, set the valid version, accept proposals,
  delete, restore, back up or change settings. Every route needs the module
  "Interface for agents" (`agent_api`, off, in no preset; "Complete" leaves
  it alone) and the module of its function, a role, and is rate limited; a
  switched-off route answers 404 with OCS meta. Answers use snake_case,
  ISO dates and contain no account names; internal preservation copies stay
  hidden from roles that may not view them.
- MCP server for agents (`tools/mcp`, TypeScript, official MCP SDK 1.32.1,
  stdio, own package not in the release archive) with one tool per use case
  (`search_catalog`, `get_copy`, `list_due`, `list_tasks`, `create_task`,
  `answer_task`, `propose_change`, `list_my_proposals`, `text_reference`,
  `list_concerts`, `get_concert`, `statistics`, `modules`), MCP annotations,
  a read-only mode and no secret in any output. CI runs an agent without a
  language model against the demo data (`tools/ci/integration-demo.sh`,
  step 7, PHP's built-in web server).
- A proposal request is checked as a whole before anything is stored: one
  field that cannot be proposed refuses the whole request.
- Personal view: every account hides navigation areas and start page cards
  it does not need ("Customize view", stored on the server); permissions do
  not change.
- Reviewing transcriptions (milestone 0.6, module `transcription_review`):
  the report of a transcription (`bericht.json`, format `bericht/1`) comes
  in through the file sync (`… - Bericht.json` next to the score, counted as
  a report, not as a score file) or `POST /api/versions/{id}/transcription-reports`.
  It is checked strictly (size, nesting, types, shelf mark and base name),
  kept unchanged as evidence with its checksum, and read only once.
  Uncertain readings, omitted marks, material findings and questions become
  review points with measures and voice, for the conductor (musical) or the
  catalog librarian (material); rights and catalog fields of the report go
  to the proposals inbox, the catalog never changes by itself. A new round
  closes the points it no longer names when the MusicXML has changed. In
  the score view a review panel lists the points; choosing one shows its
  measures with the voice highlighted and plays the passage (where playback
  is allowed). Decisions: reading is right, correction order for the tool
  chain (`GET /api/review/orders`), question (becomes a task with the
  location), dismissed; status instead of deletion, with decider, time and
  reason. Open review points per copy in the catalog with a filter;
  notifications "new review points" and "correction received".
- Small corrections in the app (module `corrections`): pitch, accidental and
  syllables of a measure range and voice, with a preview; the server
  compares old and new MusicXML element by element and saves only these
  changes as a new file next to the old one (`… (korrigiert 2026-10-22).mxl`)
  with the permissions of the person who corrects; the old file stays
  unchanged, the change list goes into the audit log
  (`POST /api/versions/{id}/corrections`).
- Scan beside the score: a linked PDF scan opens next to the score or in a
  second window at a page, in the browser's own PDF viewer
  (`GET /api/files/{id}/scan`).
- Demo data: two invented transcription reports (1-1, 2-1) with review
  points of every kind; integration test `review_test.php`.
- Review of transcriptions in the interface for agents (milestone 0.6
  complete): `GET /api/v1/copies/{id}/review` (reports without the received
  text, counts per status, review points with place, role and status in
  cursor pages), `GET /api/v1/correction_orders` (cursor pages) and
  `POST /api/v1/copies/{id}/transcription_reports` (201; 200 for the same
  report again; roles contributor and librarian, 30 per 10 minutes; the
  report must name the shelf mark of that copy), each needing `agent_api`
  and `transcription_review`; `GET /api/v1/modules` tells
  `submit_transcription_reports`. No route decides a review point or saves
  a correction; answers name no accounts (`own` instead of `created_by`,
  `decided_by`), internal preservation copies stay hidden from roles that
  may not view them. MCP tools `get_review`, `list_correction_orders` and
  `submit_transcription_report` (not offered in read-only mode). The CI
  agent reads the review of 1-1, hands in a new round of its demo report,
  and is refused when it tries to decide a point. The correction orders of
  the internal API name `preservation_copy`.
- Documentation with screenshots of the demo data: README, user and
  administration guides, German quick start, contributing guide, issue and
  pull request templates; a script recreates the screenshots
  (`tools/screenshots`).
- Licensing after the REUSE specification 3.3, as in Nextcloud's own apps:
  every file names its copyright and license in an SPDX header or in
  `REUSE.toml`, the license texts are in `LICENSES/` (also in the release
  archive), and CI checks it with `reuse lint`.
- Opening from the Files app (module "Opening from the Files app", on in
  Standard and Complete): files of the archive folder get the action "Open
  in music archive". A click on MusicXML opens the score view of its copy
  (unless another app opens MusicXML through Nextcloud's viewer), scans,
  MuseScore and audio files open their copy from the actions menu; PDFs keep
  Nextcloud's viewer. The small script (`@nextcloud/files` 4, Nextcloud 33
  to 35) is only loaded for accounts with access; the server answers which
  copy a file belongs to only for files the account can see
  (`GET /api/nextcloud-files/{fileId}/copy`, otherwise 404) and the score
  view checks the rights as always. New start parameter `?copy={id}&score=1`
  opens the score view of a copy.

### Fixed

Found in an end-to-end acceptance test in Nextcloud 33 (all roles, axe-core,
keyboard only, 320 px, 200 % zoom, text spacing, 2,000 copies):

- Every view has its own page title; Nextcloud showed "[object Object]".
- Nextcloud's own styles for tables and definition lists no longer squeeze
  the app: tables wrap and fit the content area, labels stay next to their
  values.
- The focus stays where the user works: after saving or cancelling a form,
  after the file picker, after imports, restores, merges, counts, answers and
  programme actions, and below sticky headers when tabbing backwards.
- Escape closes dialogs and the score view also while a form field has the
  focus.
- Field errors are marked as invalid and linked to their inputs; the first
  field with an error gets the focus.
- Valid navigation markup, accessible names of the start page cards that
  contain their visible text, no second banner landmark in the score view.
- The inbox from a link without a matching role shows the start page instead
  of an error; repertoire details no longer show "%n" for song lists.
- Large catalogs render in steps: clearing a search over 2,000 copies blocks
  the page for about 0.1 s instead of 0.7 s.
- The server refuses persons without a name (was an internal error) and
  planned programmes without a title.

Found in the end-to-end acceptance test of 0.1.20:

- Own app icon (folder with an eighth note) instead of a generic cloud.
- The focus voice in the score view no longer stands out by its color alone:
  bolder note heads and stems, the other voices with their marks in a lighter
  gray (still 4.5:1); the lyrics of its staff stay dark.
- Start page cards use singular and plural ("1 Proposal in the inbox").
- Buttons in the rights section name the action ("Take out of the singers'
  portal", "Set as no longer valid for the choir", "When in doubt: set to
  open"), not the resulting state.
- A link with an unknown copy (`?copy=`) says that the copy does not exist or
  is no longer available, and the parameter leaves the URL.
- Admins without a role: the inbox stays hidden (the server refuses it), and
  the note and the administration guide say exactly what they may read.
- `npm run stylelint` works (Nextcloud's shared configuration) and runs in CI;
  `package.json` has the app version again and the release script stops when
  it differs from `appinfo/info.xml`.
- `appinfo/info.xml` is valid against the app store schema again: the repair
  step after migrations (`post-migration`) comes before the one at
  installation (`install`), in the order the schema requires.

Found when checking the prints in Firefox 158 and the icon at small sizes:

- The printed inventory list fits the width of A4 with all its columns;
  Firefox cut off the last two, Chromium squeezed the note column.
- The app icon is drawn on a 16 px grid: folder and eighth note stay legible
  at 16 px.
- The label sheet asks to print without the browser's headers and footers,
  which Firefox adds by default.

Found in a design review in Nextcloud 33 (all roles, light and dark theme,
320 px, tablet, 200 % zoom):

- Score view: the score starts much higher up. Playback, measures and voice,
  review and scan are three compact bars; tempo and volume and the actions for
  the passage (text reference, internal link, task) open on request; file and
  page of the scan only show while the scan is shown. On wide screens score,
  scan and review fill the window side by side.
- Score view: the scan beside the score follows the page of a review point;
  the PDF viewers of Chrome and Edge ignored a change of only `#page=`.
- Score view: the fields "From measure", "To measure" and "Page of the scan"
  have their intended width instead of the full width.
- Score view: without access to the file there is no "Try again" but a link to
  the explanation of the archive folder in the administration guide.
- Rights badges are as high as a line of text (the icon kept the size of a
  button): catalog rows are about half as high.
- Catalog: a labelled select for the sort order fits every width (the three
  buttons ran out of the window at 320 px); the tag with open review points
  no longer runs out of the table; "from the imported catalog" no longer
  stands in nearly every row.
- View headers keep their actions beside the heading, also with a long
  description.
- Empty states say what to do next (concerts, song lists, singers' portal,
  print station, ending licenses) and use the icons of the navigation.
- A "Help" entry in the navigation opens the user guide (in German the quick
  start).
- Practice files: labels and values of the summary no longer run together;
  review panel: its facts stack in a narrow panel and the list of points no
  longer collapses; person dialog: each date sits beside its precision;
  inventory: the archive binder is no longer cut off.
- German texts: correct grammar and consistent terms ("Programmpunkt",
  "Archivmappe", "Vorschlag" instead of "Empfehlung", "Lesart", "(Pflichtfeld)"),
  less technical wording for links and checksums.

### Security

- Every route checks the caller's role on the server; a unit test guards the
  route attributes (no public pages, CSRF skipped only for plain downloads).
- Score files are delivered only if they are real MusicXML, as attachments
  with `nosniff`; the rendered notation is sanitised before it is shown.
- Limits against oversized uploads, ZIP bombs and huge spreadsheets; no
  document type declarations in parsed XML.
- Restore checks every value against its column and runs entirely in one
  transaction.
- Brute-force protection for excerpt links, rate limits for expensive
  endpoints, formula injection neutralised in CSV files meant for people.
- The app page allows WebAssembly for the bundled notation engine and nothing
  else beyond Nextcloud's default policy.
- Vulnerabilities are reported privately through GitHub Security Advisories;
  `SECURITY.md` names the supported versions.
