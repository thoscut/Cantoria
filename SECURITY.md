<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Security policy

## Supported versions

The app is in its 0.x series and no release has been published yet. Security
fixes go into the `main` branch and into the next release; once releases are
published, only the latest release receives fixes. Please update to it
before you report an issue.

| Version | Security fixes |
|---|---|
| `main` | yes |
| latest 0.x release | yes |
| older releases | no |

## Reporting a vulnerability

Please report security issues **privately** through
[GitHub Security Advisories](https://github.com/thoscut/choircoresmanager/security/advisories/new)
("Report a vulnerability" in the Security tab of the repository), not in
public issues, pull requests or discussions.

Please include:

- the affected version of the app and of Nextcloud,
- what an attacker can do and which role or account they need,
- steps to reproduce, ideally with the public demo data from `tools/demo`
  instead of real data.

You will receive an answer within a week. Fixes are released as soon as
possible and mentioned in the changelog.

## Promises and their tests

What the app promises and where that is tested. `CS` is
`tests/Unit/Controller/ControllerSecurityTest`; integration tests
(`tests/Integration/…_test.php`) run against a real Nextcloud in CI
(`tools/ci/integration-demo.sh`). A change that breaks a promise must fail
one of these tests; a new promise gets a row and a test. The design notes
below explain the reasons.

| Promise | Tested by |
|---|---|
| Every route needs a logged-in account, except the public pages of the sharing modules | CS `testOnlyTheSharingPagesArePublic` |
| Every route belongs to the core or names its module; a switched-off module answers 404 | CS `testEveryRouteIsCoreOrBelongsToAModule`, `testRoutesOfASwitchedOffModuleAnswer404`, `testASwitchedOffPartOfAnActionAnswers404` |
| Only the settings are restricted to administrators, and only they can be delegated | CS `testOnlyTheSettingsLackNoAdminRequired`, `testTheSettingsCanBeDelegatedAndNothingElse`, `testTheSettingsRefuseWhomTheyWereNotGiven`; `settings_access_test` |
| Delegated settings give no role to an own group, do not move the archive folder and switch on no module that opens the archive; such changes are logged | CS `testDelegatedSettingsCannotGrantRolesOrMoveTheArchiveFolder`; `ModuleSettingsTest::testOnlyAdministratorsSwitchOnWhatOpensTheArchive`, `testEveryChangedSwitchIsRecorded` |
| Only administrators learn whether a restore is possible; the restore cannot be delegated | CS `testTheSettingsTellWhetherARestoreIsPossibleOnlyToAdmins` |
| Writing requests keep the CSRF check; only plain downloads skip it | CS `testCsrfIsOnlySkippedForPlainDownloads` |
| Expensive endpoints are rate limited | CS `testExpensiveEndpointsAreRateLimited` |
| Decisions (rights confirmation, valid version, portal release) are no generic fields; unknown fields are refused | `FieldsTest::testDecisionFieldsAreMarked`; `RequestBoundaryTest::testLinkRefusesUnknownFields`, `testTaskRefusesUnknownFields`; `workflow_test` |
| Choir members hold no archive role and reach only their page and the routes of their links | CS `testChoirMembersHoldNoArchiveRole`, `testOnlyTheMemberRoutesLetChoirMembersIn`, `testChoirMembersGetOnlyTheirPage`, `testMemberRoutesRefuseOthersAndThrottleUnknownTokens` |
| A link carries no rights of its own: release and rights are checked on every call, preservation copies never | `RightsPolicyTest::testLinksForChoirMembers`, `testPublicLinks`, `testPreservationCopiesAreNeverPlayed`; `SharingTest::testOthersAndWithdrawnLinksGetNothing`, `testALinkOffersTheScanOnlyUnderTheSameRelease` |
| Every failure of a public link gets the same 404 and counts for the brute-force protection | CS `testPublicLinksAnswerTheSame404ForEveryFailure`, `testResolvingALinkHasBruteForceProtection`, `testAnUnknownTokenIsThrottled` |
| A public link shows title, composer and the measures of the link, no account names and no note | `SharingTest::testAPublicLinkShowsOnlyTheExcerptAndNothingInternal`, `testChoirMembersSeeTheirLinksAndOnlyTheMeasures`; `sharing_test` |
| A scan is shown only by the browser's PDF viewer, after the same checks as the link | CS `testScansAreOnlyShownByThePdfViewer`, `testTheScanOfAMemberLinkIsShownInlineAfterTheSameChecks`, `testAScanIsRefusedWithoutTheRightToView` |
| Practice audio of choir members stays within the link and is recorded with the role only, without account or name | `SharingTest::testChoirMembersMakePracticeAudioOfTheirExcerptOnly`, `testTheAuditLogNamesOnlyTheRoleOfAChoirMember` |
| The repertoire page lists only public-domain works and links only to `cpdl.org` and `imslp.org` over https | `SharingTest::testTheRepertoirePageShowsChosenPublicDomainWorksOnly`, `testLinksToCpdlAndImslpMustBeHttpsToTheirSite` |
| The API for agents needs its module, a role and a limit on every route, and decides nothing | CS `testEveryApiV1RouteNeedsTheInterfaceItsModuleARoleAndALimit`, `testNoApiV1RouteDecidesOrCorrects`, `testASwitchedOffApiV1RouteAnswers404WithOcsMeta`; agent run in `tools/ci/integration-demo.sh` |
| The scan in links for choir members needs a license for copies unless public domain is confirmed; public playback of protected versions a license for online playback | `RightsPolicyTest::testTheScanForChoirMembersNeedsALicenseForCopies`, `testPublicLinks`; `SharingTest::testTheDialogLearnsWhatTheRecipientsGet` |
| Internal preservation copies (a version marked as one, or a copy of that kind) are listed and refused alike by the app's pages and the API for agents, for every role | `PreservationCopyParityTest::testBothInterfacesListTheSameCopies`, `testBothInterfacesRefuseTheSameCopies` |
| Answers of the API for agents name no accounts; preservation copies only for the roles that may view them | `ApiV1ControllerTest::testACopyWithoutAccountNamesAndAPreservationCopyOnlyForItsRoles`, `testTheReviewOfACopyHasNoAccountNamesAndComesInPages`, `testPreservationCopiesOnlyForRolesThatMayViewThem` |
| The MCP server never shows the app password and sends nothing in read-only mode | `tools/mcp/test`: "never appear in tool results" (secrets), "send nothing in read-only mode", "leaves out the writing tools in read-only mode" |
| Files are read through the user's own view; nobody gets a file they could not open | `UserFilesTest::testRefusesAFileTheUserCannotSee`; `ScoreServiceTest::testAScoreInAnArchiveFolderThatIsNotSharedSaysWhatToDo`; `files_integration_test` |
| Size limits are enforced while reading, not only by the size in the file cache | `UserFilesTest::testRefusesContentAboveTheLimitWhenTheRecordedSizeIsWrong`; `ScoreServiceTest::testReadsNoMoreThanTheLimitWhenTheRecordedSizeIsWrong`, `testRefusesACompressedScoreThatUnpacksTooLarge` |
| Score files reach the browser only as real MusicXML, as an attachment with `nosniff` | CS `testScoreFilesAreNeverShownInline`; `ScoreServiceTest::testRefusesAFileThatOnlyPretendsToBeMusicXml`, `testRightsPolicyComesFirst` |
| Spreadsheets are read without document type declarations and with limits against ZIP bombs | `XlsxReaderTest::testRefusesDocumentTypeDeclarations`, `testRefusesAPartThatUnpacksTooLarge`, `testAFarCellCannotInflateTheWorkbook` |
| A rights notice in a MusicXML file never sets a rights status | `RightsNoticeServiceTest::testANoticeBecomesAtMostAProposal`, `testTheDoctypeOfMusicXmlIsAcceptedButNoInternalSubset`; `archive_extras_test` |
| The print station never writes into the archive folder and replaces only files it wrote itself | `print_mirror_test`; CS `testThePrintStationIsSyncedByTheCatalogLibrarianAndAdministrators` |
| The hand-over to a choir system never writes into the archive folder or the folder of the print station and hands over only what choir members may have | `handover_test`; CS `testTheHandOverIsSyncedByTheCatalogLibrarianAndAdministrators` |
| Backups and annual snapshots lie outside the archive folder | `workflow_test` ("the backup lies in the backup folder"), `archive_extras_test` ("the snapshot lies outside the archive folder"), `notifications_test` |
| The database holds link tokens only as hash and encrypted; tokens of links for choir members and public links only for their creator and the catalog librarian, no account names in link lists | `LinkTokensTest::testTheTableHoldsTheHashAndTheEncryptedTokenOnly`, `testTheTokenIsReadBackOnlyWithTheSecretOfTheInstance`, `testOnlyTheCreatorAndTheLibrarianSeeTokensThatOpenALinkToOthers` |
| Excerpts and whole pieces for choir members and the public carry no encoder, miscellaneous fields, comments or processing instructions | `MusicXmlExcerptTest::testNamesAndNotesOutsideTheMusicDoNotLeaveTheServer` |
| The rights notice is not read from documents with an internal DTD subset, however long the prolog | `RightsNoticeServiceTest::testTheDoctypeOfMusicXmlIsAcceptedButNoInternalSubset` |
| Backups hold no link tokens; a restore brings links back revoked | `ExportServiceTest::testABackupHoldsNoLinkTokensAndASnapshotNoAccounts`; `backup_test` |
| A deleted account leaves no account in the folder book and the audit log; access and erasure for one person record no name | `PrivacyTest::testADeletedAccountLeavesNoAccountBehind`, `testErasureAnonymisesTheLoansAndTheAuditEntriesAndRecordsNoName`, `testTheReportNamesLoansEntriesAndPersonsOfAnAccountOrAName` |
| Folders that never come back lose their holder after 36 months; persons without a known death keep only the year of birth | `FolderBookServiceTest::testFoldersThatNeverComeBackLoseTheHolderAfterThreeYears`; `PrivacyTest::testPersonsWithoutAKnownDeathKeepOnlyTheYearOfBirth` |
| Of the weekly backups the newest eight are kept | `ExportServiceTest::testOnlyTheNewestWeeklyBackupsAreKept` |
| Annual snapshots name nobody: no accounts, folder book, audit log or links | `ExportServiceTest::testABackupHoldsNoLinkTokensAndASnapshotNoAccounts`; `backup_test`, `archive_extras_test`; CS `testAnnualSnapshotsCheckTheRolesOnTheServer` |
| A restore writes only into an empty catalog, checks every value against its column and writes nothing when it fails | `backup_test`; `FullBackupServiceTest::testColumnValue`, `testRefusesDeeplyNestedJson`, `testRefusesWhatIsNoBackup` |
| The folder book is read and written only by its roles; its entries of the audit log are hidden from others | CS `testTheFolderBookIsReadByTheArchiveRolesAndMaintainedByTheLibrarians`, `testFolderBookEntriesOfTheAuditLogAreHiddenFromOtherRoles`; `folder_book_test` |
| After the retention period names and notes of a loan are removed, also from the audit log | `FolderBookServiceTest::testExpiredLoansLoseTheirPersonalDataAlsoInTheAuditLog`; `folder_book_test` |
| The search of Nextcloud answers only catalog roles, in pages, without preservation copies for others | `CatalogSearchProviderTest::testAccountsWithoutACatalogRoleFindNothing`, `testPreservationCopiesOnlyForTheRolesThatMayViewThem`, `testResultsComeInPages` |
| CSV files for people neutralise formulas; the catalog CSV keeps its cells for the round trip | `CatalogCsvTest::testWriteForPeopleNeutralizesHeaderAndCells`, `testCatalogCsvKeepsFormulaLikeCellsForTheRoundTrip`; `StatisticsCsvTest::testCellsThatLookLikeFormulasAreNeutralized` |
| Clients and notifications get no internal error texts | `NotifierTest::testExportFailureShowsNoInternalDetails`, `testLinksStayInsideTheApp`; `ApiV1ControllerTest::testErrorsBecomeOcsExceptions` |
| The app page allows WebAssembly and nothing else beyond Nextcloud's policy | CS `testTheAppPageAllowsWebAssemblyButNoScriptEval` |
| Pieces are kept on a device only where choir members may save them, a public link only for confirmed public domain, the concert folder only with the module `offline`, a license or confirmed public domain, and never an internal preservation copy; the service worker holds no data, removes itself when its modules are off and never answers an expired or unchecked piece from the device | `SharingTest::testChoirMembersSeeTheirLinksAndOnlyTheMeasures`, `testAPublicLinkShowsOnlyTheExcerptAndNothingInternal`; `ConcertFolderTest`; CS `testTheOfflineWorkerIsAScriptOfTheAppOrRemovesItself`, `testOnlyTheSharingPagesArePublic`, `testTheConcertFolderIsForTheConductorAndTheLibrarianOnly`; `offline.test.ts` |
| The audit log has no route to change or delete entries | review: the route list of CS `testEveryRouteIsCoreOrBelongsToAModule` names every route |

## Design notes

### Access

- Every API request checks the caller's role on the server; the user
  interface only hides what the server would refuse anyway. Every route
  needs a logged-in Nextcloud account, except the two public pages of the
  sharing modules (a public link and the public repertoire page, see below),
  which are off unless switched on.
- Only instance settings (role groups, archive folder, modules, repertoire
  thresholds, the access check of the archive folder, the target folder of
  the print station and the retention period of the folder book) are restricted by
  Nextcloud itself: to admins and to the groups the administration has given
  the page "Administration → Cantoria" under Administration privileges
  (`#[AuthorizedAdminSetting]`, checked again by the app). That gives no
  role, and the settings give nothing to Nextcloud's generic app config API.
  Those groups cannot give a role to a group they belong to, cannot set or
  change the archive folder and cannot switch on the modules that open the
  archive to the outside (agents, choir members, the public, the print
  station); these stay with administrators. Changes of roles and groups and
  of the archive folder are in the audit log with the old and the new
  value, as are changes of modules.
  Admins write catalog data only if they also hold a role; restoring a
  backup into an empty catalog is the one exception (after a reinstall). It
  stays with admins and the catalog role and cannot be delegated.
- Each field has its write roles in one registry (`lib/Service/Fields.php`);
  unknown fields are refused, read-only fields (decisions such as a rights
  confirmation, the valid version or the portal release) can only be set
  through their own workflow, never through a generic update. Requests that
  create tasks, links or accessions also refuse unknown fields.
- Write requests require Nextcloud's CSRF token. Only plain GET downloads
  skip it (app page, CSV export, full backup, song order, the CSV files of the
  statistics and the annual report, the calendar files of concerts); they do
  not change state. Adding concerts to a calendar keeps the check.
- A unit test (`tests/Unit/Controller/ControllerSecurityTest`) checks these
  route attributes, so that a new route cannot silently become public, skip
  the CSRF check or lose its restriction to the settings; for the API for agents also
  its modules and rate limit; for the sharing modules the list of public
  routes and the list of routes a choir member reaches.

### Sharing beyond the archive roles (milestone 0.7)

Four modules open parts of the archive to people without an archive role:
links for choir members (`member_links`), their practice audio
(`member_practice_audio`), public links (`public_links`) and the public
repertoire page (`public_repertoire`). They are off on new and updated
installations and part of no preset, not even "complete"; only their own
switch turns them on. Switched off, their routes answer 404 and the
navigation leaves them out.

**The role "Choir members" (`member`).**

- It is no archive role: `Roles::rolesOf()` and `Roles::hasAccess()` leave
  it out, so every route that checks access (`requireAccess`,
  `requireRole`, the rights policy) refuses a choir member as it refuses any
  account without a role. The navigation entry and the app page appear for
  choir members only while `member_links` is on, and show only "Shared with
  me".
- A choir member reaches exactly the app page and the three routes of
  `MemberController` (the score of a link, its scan, permission for a
  practice file); the unit test keeps that list and checks that no other
  controller serves choir members. An account with an archive role always
  gets the archive.
- They see title and composer of the work, the measures (or "whole
  piece"), voice, verse, tempo, note and expiry of each link and whether it
  includes the scan; no shelf marks, no account names, no
  catalog. Every link for choir members is visible to every choir member,
  and so is its note.

**Only the content for the view leaves the server.**

- Choir members and the public never get the score file. The server reads
  it through the account that configured the archive folder, only inside
  that folder (as the background jobs do), checks that it is real MusicXML
  as for the score viewer, unpacks `.mxl` and sends only the content for the
  view: the measures of the link (`MusicXmlExcerpt::cut`) or, for a link
  without measures, the whole piece with all repeats and jumps
  (`MusicXmlExcerpt::whole`). Both are parsed without entity expansion or
  network access, and the document type declaration is dropped.
- For choir members the score is an attachment with `nosniff` and the
  strict policy of data responses; on a public page it is part of the page
  itself.
- **The scan (PDF)** leaves the server only where the link includes it
  (`scan_file_id`, chosen when the link is made from the PDF files of the
  copy that the creator can open beside the score). It is read the same way
  through the account of the archive folder, must still be a PDF of the
  copy (role `score_pdf`, header checked) and is answered with
  `Content-Type: application/pdf`, `Content-Disposition: inline`, a fixed
  file name, `nosniff`, no caching and the policy of the scan beside the
  score (`default-src 'none'`, `object-src 'self'`,
  `frame-ancestors 'self'`), so that only the browser's own PDF viewer shows
  it. The page opens it in a tab of its own; that GET route skips the CSRF
  check like the scan beside the score, and only reads. Internal links never
  carry a scan.

**Rights on every call.** A link carries no rights of its own. When it is
made and on every call the server checks:

- for choir members the release for the digital provision to members (the
  singers' portal: confirmed public domain or a valid license for the
  portal);
- for the public the release for publication (publication status "free",
  set by the catalog librarian) and confirmed public domain or a valid
  license with the use "online publication";
- never an internal preservation copy;
- the same for the whole piece, an excerpt and the scan: legally an excerpt
  counts as much as the whole piece and a scan as much as the notes
  (guideline 2), so none of them has a release of its own; the scan in a
  link for choir members, which they can save and print, additionally needs
  confirmed public domain or a license for download and printing by
  members (or self-printing);
- on public links, playback of a protected version needs a license for
  online playback (audio); a license for practice audio covers the choir;
- playback and practice audio as in the archive (public domain or a license
  for practice audio; practice audio files additionally a confirmed status
  or that license). Every practice file of a choir member is recorded in the
  audit log with the role `member` only (no account, no name; since 0.11,
  older entries were anonymised on the update), measures, voice, mode and
  format; for an excerpt the measures of the link, whatever the request
  says.

Choir members and the public get MusicXML cut on the server, not the file:
without the encoder and the miscellaneous fields of `<identification>`,
without XML comments and processing instructions, which may name people or
hold internal notes. Title, credits, creators and the rights notice stay.

A version that loses its release disappears from "Shared with me" and its
public links stop working at once.

**The public pages.**

- Exactly three routes are public (`#[PublicPage]`), the only routes of
  their controllers: `GET /apps/cantoria/p/{token}`, the scan of a public
  link `GET /apps/cantoria/p/{token}/scan` and
  `GET /apps/cantoria/repertoire`. All are plain GET requests, limited
  per address (60 in 10 minutes, the scan 30) and per account, protected by
  Nextcloud's brute-force protection and need their module; the unit test
  checks this.
- Tokens are 32 alphanumeric characters from Nextcloud's secure random
  generator. The database holds only their SHA-256 hash, by which a link is
  found, and the token encrypted with the secret of the instance, so that
  the creator and the catalog librarian can copy the link again; a copy of
  the table alone opens no link. Other archive roles see links for choir
  members and public links without their token, and lists name creators by
  display name only. An unknown, malformed, expired or revoked token, a version no
  longer released and a switched-off module all get the same 404 page
  without a reason, and each counts as a failed attempt; the scan route
  answers the same way, also for a link without a scan.
- A public link shows no account names, no note, no download button for the
  score file and no practice audio (the MusicXML the page shows is in the
  page and can still be saved, see below); the page is marked `noindex` for search engines and has
  the policy of the app page (WebAssembly for the bundled notation engine,
  no JavaScript `eval`, no external sources, no frames).
- The repertoire page has no script of its own and Nextcloud's default
  policy. It shows title, composer and lyricist with their life dates,
  scoring and links to CPDL and IMSLP: only https links to `cpdl.org` and
  `imslp.org`, checked when they are saved and again when the page is
  rendered; nothing is loaded from there. Works are listed only while a
  version is confirmed public domain, checked on every call. No shelf marks,
  no copies, no accounts.

**What remains.**

- Whatever reaches a browser can be saved: the whole piece or the measures
  of an excerpt are in the page or the response, and a scan shown inline by
  the browser's PDF viewer can be downloaded or printed from there; no
  header can prevent that. The dialog says so before a scan is included
  ("Whoever sees the scan can also save it."). Legally an excerpt counts as
  much as the whole sheet, which is why only released versions are shared.
- Practice audio files are made in the browser: the server only grants the
  permission and records it. Once made, a file stays with whoever made it.
  That is an organisational protection, not a technical one; when a license
  ends, pass on its duties to delete to the choir members.
- Links can be passed on. They expire after at most 183 days and can be
  revoked at any time by their creator or the catalog librarian; calls are
  counted without recording who made them.
- The token is part of the address (`/p/{token}`). The access log of the
  web server records addresses, and so does Nextcloud's log when a request
  fails with an internal error. Whoever reads these logs can open the
  links found there until they expire or are revoked; the app's own log
  and audit log hold no tokens.
- The time a 404 takes may tell whether the module is on; which works are
  public is no secret.
- A note on a link for choir members is read by all choir members: it is
  no place for internal remarks.
- Practising without a network (module `offline`, milestone 0.15) keeps a
  copy on the device: the notes of a link, on request its scan, the page
  "Shared with me" with its initial state and request token, and its
  scripts, in the browser's Cache Storage of that device. Only where choir
  members may save and print the notes (`RightsPolicy::memberScanDenial`),
  only after a click, and checked with the server at every start with a
  network: expired, revoked and no longer allowed pieces are removed, and
  the service worker does not answer from the device for an expired link or
  30 days after the last check. Kept like a saved scan, a copy can still be
  taken out of the browser's storage by whoever controls the device; the
  rules above make it end, not impossible. Signing out of Nextcloud does
  not delete it (the logout is outside the scope of the worker); the page
  offers "Delete everything on this device", and another account opening
  the page removes the pieces of the previous one. The worker
  (`GET /offline-worker`) is a script of the app without data, served from
  the app's path with `connect-src 'self'` to everybody (a public page
  registers it, too, so it is a public route of the core); the pages allow
  `worker-src 'self'` only on "Shared with me", the concert folder and a
  public link, and frames only from `blob:`, for a kept scan shown from the
  browser's memory. It caches nothing for the pages of the archive, only
  scripts fetched by one of these pages, and never writing requests or the
  APIs of Nextcloud. Each page has a cache of its own; a page that answers
  without its header (another account, no role) removes only its pieces.
- A public link (module `offline`) is kept on a device only for a version
  whose public domain is confirmed: visitors have no license, and a license
  for online publication covers the showing, not a copy. Visitors have no
  account, so these pieces are kept per browser; each visit with a network
  is the check (the page carries the time the server made it), and a
  revoked or expired link is removed from the device at the next one.
- The concert folder (module `concert_folder`) does not open an internal
  preservation copy. With the module `offline` it keeps on the device of
  the conductor or the catalog librarian only what the rights allow as a
  copy (`RightsPolicy::deviceCopyDenial`: confirmed public domain or a
  license for download or self-printing); the same check at every start
  with a network and the same lock after 30 days apply. Without the module
  it keeps nothing. It only reads.

### Interface for agents

The API for agents (OCS, `/ocs/v2.php/apps/cantoria/api/v1/…`,
[docs/api-v1.md](docs/api-v1.md)) and the MCP server in `tools/mcp` follow
these rules:

- **Off unless switched on.** The module "Interface for agents"
  (`agent_api`) is off on new and updated installations and part of no
  preset; only an administrator switches it on. Switched off, every route
  answers 404 before the controller runs.
- **No right beyond the roles.** The routes call the same services as the
  user interface, with the same role checks; each route also needs the
  module of its function. An agent signs in with an app password of an
  account of its own; the documentation asks for the roles "contributor" or
  "reader" only.
- **Nothing decides.** There are no routes to confirm rights, change
  licenses, set the valid version, accept or reject proposals, close tasks,
  delete, restore, back up or change settings. Writing creates proposals
  (taken as a whole or not at all) and tasks, or answers a task addressed
  to an own role. A unit test lists every route of the API and checks its
  modules, role requirement, rate limit and CSRF handling.
- **CSRF.** The routes keep Nextcloud's CSRF check; an OCS request passes it
  only with the header `OCS-APIRequest: true` (or a Bearer token), which a
  foreign web page cannot send with the cookies of a logged-in browser. No
  route allows CORS.
- **Data minimisation.** Answers contain no account names (who confirmed or
  chose something) and no file contents. Internal preservation copies are
  left out of the search for roles that may not view them and refused
  (403) as a copy, text reference or target of a task.
- **Limits.** Every route is rate limited per account (30 to 300 requests
  in 10 minutes); internal errors are logged and answered with a generic
  message.
- **MCP server.** It runs locally over stdio (no listening port), needs
  https (plain http only for localhost), does not follow redirects, never
  logs the app password and replaces it in every output; a read-only mode
  offers no writing tools and refuses writing requests before they are
  sent. Catalog texts may contain instructions written to mislead an agent
  (prompt injection); what such an agent can do is limited by its role, and
  people decide on every proposal.

Note that Nextcloud lets any client with an app password and the header
`OCS-APIRequest: true` call the internal API of the user interface as well
(with the same role checks). The module therefore does not widen what an
account can do; it offers a stable, documented and limited contract.

### Files

- Files are read through the requesting user's own view of Nextcloud; the app
  never grants access to files a user could not open otherwise. Background
  jobs read through the account that configured the archive folder and only
  write into `_cantoria-app/` inside it (exports of the catalog) and into
  the backup folder of that account.
- The backup folder holds what names people or accounts or opens links: the
  weekly and manual backups (ODS with the audit log, complete JSON backup)
  and the annual snapshots. It lies outside the archive folder, in the files
  of the account that set the archive folder, so that sharing the archive
  folder with the role groups shares none of it. The app reads and writes it
  only as that account and never uses a folder inside the archive folder for
  it. Only an administrator sets the archive folder, and with it that
  account.
- Besides the backup folder, the mirror folder for the print station
  (module `print_mirror`) and the hand-over folder for a choir system
  (module `choir_handover`), both part of no preset, are the only places
  outside the archive folder where the app writes. An administrator chooses
  each target folder; the server refuses the archive folder and every
  folder inside it (also reached through shares) and the target folder of
  the other module, checks this again before every sync and writes as the
  account that chose the folder. The print station gets only the PDF scans
  of the versions on its list; the hand-over only the files of the versions
  on the list of the singers' portal that choir members may save and print,
  and recordings only where practice audio is allowed (the same rules as
  the lists, never internal preservation copies). Each replaces or removes
  only files listed in its own manifest whose SHA-256 is still the one it
  wrote; every other file in the folder stays. Every write and removal is
  in the audit log. Whoever can open a target folder can read the files in
  it: it must be shared only with the print station or the account of the
  choir system.
- Score files reach the browser only if they are linked to a copy, pass the
  rights policy (internal preservation copies: never played, viewed only by
  catalog librarian and conductor) and are real MusicXML: plain XML with the
  root `score-partwise` or `score-timewise`, or an `.mxl` archive in which
  every file the viewer could pick is such a document. Document type
  declarations with an internal subset are refused, because they let parsers
  disagree about a document. The response is an attachment with
  `X-Content-Type-Options: nosniff` and Nextcloud's strict policy for data
  responses (`default-src 'none'`), so it is never rendered on the Nextcloud
  origin.
- The rights notice of a MusicXML file (`<identification><rights>`) is read
  by the file sync with the same size limits, without loading anything and
  without expanding entities; documents with an internal DTD subset
  anywhere in the text are not read. The notice is shown as unchecked text (Vue escapes it) and never sets
  a rights status; at most it becomes a proposal.
- Revisions of a version (module `version_states`) are records with a
  checksum; the app copies no file for them. The file sync hashes score
  files through the syncing account, as the checksum job does. Comparing two
  revisions reads both files through the existing score route with the
  requesting user's own access and the rights policy, and parses them in
  the browser with the score view's own XML parser; nothing new reaches the
  browser. Only the catalog librarian sets the current revision (rate
  limited, audited); the API for agents only reads revisions, without
  account names.
- Uploaded files for imports are limited to 20 MB, backups for a restore to
  64 MB, score files to 32 MB; the content is read with that limit, not only
  checked against the size in the file cache.
- Spreadsheets (`.xlsx`) are parsed without document type declarations
  (no entities, no external loading, no network) and with limits against ZIP
  bombs: at most 10,000 entries, 16 MB unpacked per part, 48 MB in total,
  16,384 columns and one million cells.

### Backups

- A restore only writes the app's own tables, only columns that exist in the
  installed schema (column names from the file are never used as SQL
  identifiers unchecked), and checks every value against the type, length and
  nullability of its column. It runs in one transaction; the check that the
  catalog is empty is part of that transaction. JSON nesting is limited.
- The complete backup (download and the weekly file) contains the whole
  catalog including the audit log, tasks, the register of persons and the
  folder book. The roles that read all of it in the app (catalog,
  operations, conductor, read only) and admins may download it; the role
  "Proposals (tools)" may not, as it does not read the folder book. Backups
  hold no link tokens: after a restore every link is revoked. Of the weekly
  backups the newest eight are kept.
- Annual snapshots (module `annual_snapshot`) are kept for years and name
  nobody: they hold the catalog tables without accounts, without the folder
  book, audit log, tasks, proposals, links and reviews, plus a list of
  checksums and paths of the archive files. Only the catalog librarian makes
  one (rate limited, the job as the account that set the archive folder);
  the app never overwrites or deletes them.

### Folder book and personal data

- The folder book (module `folder_book`) is the only part of the app that
  stores personal data of choir members: the name or the Nextcloud account
  of the person who has a choir folder, and notes about the loan. Only the
  catalog and operations roles write it, conductor and read only read it;
  every route checks the role on the server. Contributors, admins without a
  role and any other role (for example choir members) get 403 and do not
  see its entries in the audit log.
- After a retention period set by the administration (default 12 months
  after the return) the daily background job removes name, account and
  notes of the loan, in the loan and in its own entries of the audit log,
  also while the module is switched off. This is the only change the app
  ever makes to the audit log; it notes the removal without the name.
- Backups made before still contain the names until the weekly backups are
  rotated out after eight weeks; backups stored by hand or downloaded, the
  trash bin and file versions of Nextcloud and server backups keep them
  longer. The administration guide asks to keep them no longer than the
  retention period and names the settings of Nextcloud for trash bin and
  versions.

### Search of Nextcloud

- The search provider (module `unified_search`) answers only accounts with a
  role of the catalog, named one by one, and admins; a role added later
  finds nothing until it is added on purpose. Internal preservation copies
  are left out for roles that may not view them, as in the API for agents.
- Results come in pages of at most 50; each account may search 300 times in
  10 minutes. Results contain shelf mark, title, composer, arranger text and
  status, no account names and no file contents.

### Exports and formula injection

- CSV files meant only for people (song order, snapshot of the register of
  persons) prefix cells starting with `=`, `+`, `-`, `@`, tab or carriage
  return with an apostrophe, so that spreadsheet programs do not evaluate
  them.
- The catalog CSV (legacy and extended format) keeps every cell unchanged on
  purpose: it must survive an import followed by an export byte for byte, and
  the extended format is a backup that is imported again. Its cells come from
  the catalog, which only librarians write. Open it in a spreadsheet program
  with the import dialog (columns as text, formulas not evaluated) or use the
  ODS file instead: the ODS export writes every cell as a text cell and is
  safe by construction.

### Links, limits and errors

- Excerpt links (of every circle) carry no rights of their own: rights,
  expiry and revocation are checked on every call; calls are counted without recording who made
  them. Tokens are 32 alphanumeric characters from Nextcloud's secure random
  generator. Unknown tokens count as failed attempts for Nextcloud's
  brute-force protection.
- Expensive endpoints (imports, file sync, exports, backups, restore, practice
  audio, statistics, calendar files and entries, the preview and sync of the
  print station) and the writing routes of the folder book are rate limited
  per user;
  Nextcloud answers with HTTP 429 when a limit is reached.
- Clients only see translated messages meant for users; internal errors are
  answered with a generic message and logged by Nextcloud. Notifications
  contain no personal data and no internal error texts; their links only lead
  into the app.

### Browser

- The app page, the page "Shared with me" and the page of a public link
  allow WebAssembly compilation (`'wasm-unsafe-eval'`) for the bundled
  notation engine and nothing else beyond Nextcloud's default policy: no
  JavaScript `eval`, no external sources. For recording oneself while
  practising (milestone 0.13) they allow the microphone for the page itself
  (`microphone 'self'`) and media from the browser's memory (`media-src
  blob:`). The microphone is asked for only after a click on "Record
  myself"; the recording never leaves the browser unless the person saves it
  as a file. "Show my pitch" (0.14) uses the same permission while its
  switch is on; the pitch is found in the browser and nothing is kept.
  Camera, location and payment stay off. Tested in
  `ControllerSecurityTest`.
- No external requests: notation, fonts and sounds are bundled.
- The audit log is append-only; there is no API to change or delete entries.
  The only change is the removal of personal data of the folder book after
  its retention period, done by the background job, never through a route.
