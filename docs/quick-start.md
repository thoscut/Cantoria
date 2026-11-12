# Quick start

**English** | [Deutsch](de/schnellstart.md)

This short guide shows music librarians and conductors their first steps in
Cantoria. The [user guide](user-guide.md) describes everything in detail, the
[administration guide](admin-guide.md) the setup.

> [!NOTE]
> You see Cantoria only if your account is in a group with a role. The
> administration assigns the roles to groups. The German interface comes in
> the informal and the formal form of address; you choose it with the
> language in your personal Nextcloud settings.

> [!TIP]
> The app opens files only with the rights of your own account. If it says
> *"The file lies in a folder that is not shared with your account"*, ask
> the music librarians to share the archive folder with you or your group;
> read access is enough.

**Contents**

- [Terms](#terms)
- [Finding your way](#finding-your-way)
- [For music librarians](#for-music-librarians)
- [For conductors](#for-conductors)
- [Common problems](#common-problems)
- [More](#more)

## Terms

Next to these terms the app shows a small **?**. It opens a short
explanation with a link into the [user guide](user-guide.md#terms); Escape
closes it again.

### Work, version and copy

The **work** is the piece itself, with title and composer. A **version** is
an edition or arrangement of the work; the rights belong to the version, not
to the work. A **copy** is a printed or digital copy of a version with a
shelf mark of its own.

### Shelf mark

The shelf mark is the number of the archive binder and the number in the
binder, for example `5-12`. The file names in the archive folder start with
the shelf mark; that is how the file sync assigns each file to its copy.

### Rights status and confirmation

The rights status of a version is "public domain", "protected" or "open".
The app suggests a status from the data (for example from the life dates),
and the music librarian confirms it with a reason and evidence. Only a
confirmed status counts for releases; the app is no legal advice.

### Release

A release says whether a version may be passed on in a certain way: in the
singers' portal, at the print station or as a publication. It follows from
the confirmed rights or a valid license and never applies to internal
preservation copies.

### Valid version

The valid version is the version the choir sings. There is at most one per
work, and only the conductor chooses it. It says nothing about the rights.

### Internal preservation copy

An internal preservation copy is kept for the archive only, for example the
scan of a protected print. It is never passed on, played or exported; only
music librarians and conductors see it.

## Finding your way

### Start page "Due"

![The start page "Due" of the Demo Librarian: cards with numbers of things to do, for example open tasks and proposals in the inbox, and below them the tasks for the librarian's roles, sorted by archive binder.](screenshots/start-page-due.png)

Cantoria opens with **Due**: cards show what is due for your roles, for
example open tasks, proposals in the inbox or versions whose public domain
status needs confirming. A click on a card opens the matching list. Below
them are the concert deadlines and the tasks for your roles, sorted by
archive binder.

### Catalog

![The catalog of the demo archive with the status filter "Active" (20 of 23 copies): shelf mark, title, composer, rights status with a symbol, repertoire and number of files per row; on the left the search field and the filters for binder, rights status, repertoire and status.](screenshots/catalog.png)

1. Open **Catalog**.
2. Type a shelf mark such as `2-1` or a word from the title or the composer
   into **Shelf mark, title or composer**.
3. Narrow the list with **Rights status** or **Repertoire**. The other
   filters, for example **Archive binder**, **With MusicXML** or **No version
   valid for the choir**, open with **More filters**. The app remembers
   whether they are open; when filters are active there, the button shows
   how many.
4. Click a row. The sidebar shows the tabs **Details**, **Rights**,
   **Queries**, **Files** (each file with its folder and a symbol for its
   kind) and **History** (the log of all changes).

The rights badge always shows text and a symbol: "public domain", "public
domain (recommendation, unconfirmed)", "protected", "open" or "Internal
preservation copy – do not share". A **?** next to a term explains it
briefly (see [Terms](#terms)).

## For music librarians

### Confirming rights

![The rights tab of a version: the confirmation with status, date, name, reason and evidence, the releases for the singers' portal and the print station, the version valid for the choir and the licenses.](screenshots/rights-panel.png)

An imported rights status stays a recommendation until you confirm it.

1. Open the copy, tab **Rights**, and choose **Confirm**.
2. Choose the confirmed status: public domain or protected.
3. Enter the **Reason** and the **Evidence**, for example "Everyone involved
   died more than 70 years ago" and "Life dates from the person register".
   Both are required.
4. Before you save, the dialog says what becomes possible, depending on the
   modules switched on: singers' portal and print station, practice files,
   sharing with choir members and, once the version is released for
   publication, public links. For "protected" it says that releases then
   need a valid license.
5. Choose **Confirm**. Your name and the date are recorded.

If you are unsure: **When in doubt: set to open**.

> [!NOTE]
> The rights aids follow German copyright law. They support your decision
> but are no legal advice.

### Applying answers

1. The card **Answered tasks to apply** leads to the answers.
2. Correct the catalog or the file if needed.
3. At the task choose **Close**, then **Applied** or **Discarded**, and then
   **Close the task**.

When **Tasks** or the **Inbox** are empty, they show you the next step: you
add tasks in the sidebar of a copy (tab **Queries**); the inbox explains
where proposals come from and leads to the start page.

### Recording an accession

1. Open **New accession** and there **Record a new piece**.
2. Choose the **Archive binder**. The app suggests the next free number;
   numbers are never given again.
3. Enter title, composer, title source, date of accession and provenance.
   If the catalog already has a piece with the same or a similar title by
   the same composer, the form names it, so that you can check for a
   duplicate before you record.
4. Choose **Record accession**. The label goes onto the label sheet below.

### Backup

Every week the app stores a complete backup in the backup folder, outside
the archive folder. Before larger changes, choose **Archive management** at
the bottom of the navigation and there **Store a complete backup now**.

## For conductors

### Choosing the valid version

Only the conductor chooses which version the choir sings. At most one
version per work is valid. The rights do not change.

**Several works at once:** open **Choose valid versions** in the navigation
(for conductors only, and only while the module "Valid version" is switched
on). It lists the works with several versions of which none is valid for
the choir yet, each version with its copies (shelf marks), their kind and
the rights status. The switch for the current repertoire shows only the
pieces you sing at the moment. Enter a reason if you like and choose **Set
as valid** at the right version, directly in the list.

**A single work:**

1. Open a copy of that version, tab **Rights**, section **Valid for the
   choir**.
2. Choose **Set as valid for the choir**, optionally with a reason, and then
   **Set as valid**.

The card **Current repertoire: no valid version chosen** on the start page
opens the catalog with the matching filter.

### Viewing and hearing the score

![The score viewer with measures 1 to 8 of the TTBB setting of copy 2-1: the fields "From measure" and "To measure", the switch "Loop", the focus voice Tenor II, the opened section "This passage" and the notation, in which the notes of Tenor II are drawn in colour.](screenshots/score-excerpt.png)

1. Choose **Score** in the sidebar.
2. Enter **From measure** and **To measure** and choose **Show**. The
   printed measure numbers apply; the pickup is measure 0.
3. Choose the **Focus voice**. It appears in colour with bolder note heads,
   the other voices lighter.
4. Under **Voices during playback** choose **All equal**, **Focus voice
   louder** or **Focus voice only**, and then **Play**. The space bar plays
   and pauses. **Tempo, volume and practising** opens the sliders, the
   switches for repeats and scrolling along and the tools for practising
   (starting notes, metronome, pitch, mixer).
5. Under **This passage** you copy a text reference, create an internal
   link or add a query about the measures shown.

Playback is offered for public domain versions and for versions with a
license for practice audio, never for internal preservation copies.

#### Practising a passage in a loop

1. Enter the passage in **From measure** and **To measure**.
2. Switch on **Loop** next to them. The passage is shown and played again
   and again until you choose **Pause** or **Stop**.
3. Under **Tempo, volume and practising** choose a **Count-in** of 1 or 2
   bars. Your browser remembers the choice.

A click on a note starts the playback there. With the keyboard, the left
and right arrow keys move to the previous or next measure in the score, and
the space bar plays.

### Making practice files

![The dialog "Practice files": measures, tempo, repeats and length taken from the score view, the choice between one file and a ZIP archive with every voice and a tutti, the voice, the format WAV or MIDI, the file name and the estimated size.](screenshots/practice-audio-dialog.png)

1. Set measures, tempo and repeats in the score view (tempo and repeats
   under **Tempo, volume and practising**).
2. Choose **Practice files …**.
3. Choose **One file** or **Every voice and a tutti (ZIP)** and the format
   **WAV (audio)** or **MIDI (one track per voice)**.
4. Choose **Make file** or **Make ZIP archive**. The browser puts the result
   into your downloads.

> [!IMPORTANT]
> Practice files are offered only for confirmed public domain versions and
> for versions with a valid license for practice audio, never for internal
> preservation copies. Each output is in the log of the version (tab
> **History**). You upload the files to the choir system yourself; the app
> passes nothing on to choir members.

### Answering a query

![The tab "Queries" of copy 2-1 with an open question to the conductor about measures 5 to 8 of Tenor II, and below it an internal link to the same place.](screenshots/task-question.png)

1. Open the query from the start page, from **Tasks** or from the
   notification.
2. **Show in the score** shows you the passage.
3. Choose **Answer**, write your answer and choose **Save answer**.

The music librarian then applies the answer to the catalog.

### Planning a concert

![The programme of the concert "Adventskonzert 2026": the pieces in order with their durations and warnings, for example "Internal preservation copy: not a singing copy" and "No valid version chosen for this work", with buttons to move, edit and remove each piece.](screenshots/concert-warnings.png)

1. **Concerts** → **New concert**: title, date, optionally start and end,
   location. **Save**.
2. **Add a piece (search by shelf mark or title)** → **Add**. Put the
   pieces in order and enter the durations.
3. Check the warnings: preservation copy, rights not clarified, no valid
   version or another one, protected without a license.
4. **Fix programme** when it is settled.
5. After the concert: **Confirm what was sung** and tick the pieces that
   were not sung. **Song order (CSV)** gives the list for the collecting
   society and the programme booklet.

The app reminds you of the deadlines 10, 8 and 6 weeks before the concert.
**New programme with these pieces …** starts the next concert from this
one, and **Songbook (PDF) …** puts the scans of the pieces whose rights allow
it into one PDF to print, with cover, contents and page numbers.

### Calendar, dashboard and files

- **Add to my calendar** puts a concert into one of your Nextcloud
  calendars, **Calendar file (.ics)** downloads it for any calendar program.
  With **With the preparation deadlines (10, 8 and 6 weeks before)** the
  deadlines come along as all-day entries.
- The widget **Cantoria – due** shows the same as the start page "Due" in
  the Nextcloud dashboard, with links into Cantoria: each entry with a short
  title, details in the second line and a symbol for concert, card or task.
- In the **Files** app, a click on a MusicXML file in the archive folder
  opens the score view of its copy. For scans and other files, **Open in
  Cantoria** is in the actions menu.

## Common problems

### No access to the music archive

*"You have no access to the music archive."* Your account has no role in
the music archive; the app or the page "No access yet" tells you so. Roles
belong to Nextcloud groups. Ask the administration to add your account to
the group of your role (see the
[administration guide](admin-guide.md#2-roles-and-groups)). Choir members
see only **Shared with me**, and only when it is switched on.

### A file does not open

The archive folder is not shared with your account; the file and your role
are fine. Ask the music librarians to share the archive folder with you or
your group; read access is enough. Then reload the page.

### A copy is missing from the catalog

Check the spelling; a shelf mark must be complete (`2-1`, not `2-`). Look
whether a filter is still active under **More filters**, for example the
status: withdrawn, missing and lent copies appear only with their status or
with **All statuses**. **Reset search and filters** removes all filters.

### Playback or practice files are not offered

The public domain status is not confirmed yet, a license for practice audio
is missing, or it is an internal preservation copy. The music librarian
confirms the rights in the tab **Rights** or records the license.

### *"No write permission for exports in the archive folder."*

The account that chose the archive folder needs write access to it. Ask the
administration for it.

## More

- **Statistics** shows holdings, rights, repertoire and upkeep in figures;
  the **Annual report** sums up a year for the board and the general
  meeting.
- [User guide](user-guide.md): all functions, ordered by roles and tasks,
  with the section [Common problems](user-guide.md#common-problems)
- [Administration guide](admin-guide.md): installation, roles, archive
  folder, import, backup
- [Demo data](../tools/demo/README.md): an invented music archive to try
  things out
