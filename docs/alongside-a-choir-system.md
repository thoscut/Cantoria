<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Cantoria alongside a choir system

Many choirs already use a choir system: a member app or a website where the
singers find their scores and practice recordings, the dates and the news.
Cantoria does not replace it. It is the archive behind it: what the choir
owns, which version it sings, what the rights allow, and what may go out to
the singers. This page is for administrators and music librarians who run
both. [Deutsch](de/neben-einem-chorsystem.md)

## Who keeps what

| Data | Kept in | Reaches the choir system |
|---|---|---|
| Catalog: works, versions, copies, shelf marks | Cantoria | Shelf mark, title, composer and version line in `Liste.csv` of the hand-over folder |
| Scans and MusicXML files | the archive folder in Nextcloud | Only those choir members may save and print, through the hand-over folder |
| Practice recordings | the archive folder | Only where practice audio is allowed, through the hand-over folder |
| Rights, licenses, evidence, preservation copies | Cantoria | The license notice per file in `Liste.csv`; never the evidence |
| Notes of the conductor per passage | the notes of the links for choir members | `… Hinweise.txt` per piece in the hand-over folder |
| Concerts and their programme | Cantoria | As calendar files (`.ics`) if you choose so |
| Members, voices, attendance, news | the choir system | Never in Cantoria |
| Folder book (who has which folder) | Cantoria | Never |
| Audit log, tasks, proposals, transcription reports | Cantoria | Never |

## What never goes into the choir system

- Internal preservation copies and scans whose rights do not allow choir
  members to save and print them.
- Recordings of works whose rights do not allow practice audio.
- Evidence of rights, purchase receipts, license contracts and their
  holders' contact data.
- Names from the folder book, the audit log or the inbox.

The rules are the same as for the links for choir members, so a piece is
never available in the choir system that a link would refuse.

## The way in: hand-over folder

Switch on the module **Hand-over to the choir system** (administration
guide, [section 17](admin-guide.md#17-hand-over-to-a-choir-system)). The
app keeps a folder of its own up to date with what the singers' portal
releases. Someone of the choir uploads its content to the choir system, or
the choir system reads the folder through a Nextcloud share if it can.
The app itself uploads nothing to the provider of the choir system.

Whether a choir system can take a Nextcloud share as its storage depends
on its provider; ask them before you rely on it.

## The way back: comparing an export

What is really in the choir system is recorded in Cantoria in the field "In
singers' portal since": in Cantoria the singers' portal is the list of what
the choir may get, the choir system the place where it really gets it.
Instead of keeping it by hand, export the list of
pieces from the choir system and compare it with the list **Singers'
portal** ([user guide](user-guide.md#comparing-with-the-choir-system)). The
differences become proposals in the inbox; nothing changes until the
catalog librarian accepts them. Pieces that are in the choir system but not
released for it are listed so that they can be removed there.

### Preparing the export

The app finds a piece by its shelf mark at the start of a cell, so the
choir system has to carry the shelf mark somewhere in its list of pieces.
Agree on one field for it, one that the export contains, and fill it once
for every piece, e.g. `2-3` or `2-3 #advent`. The title works as well
("2-3 Am Brunnen vor dem Tore"), but the singers then see the shelf mark
in the title.

- **CSV or XLSX.** The "Excel" button of many web tables gives an old
  Excel file (`.xls`) or a web page. The app does not read such a file and
  does not offer it for the comparison; choose CSV instead.
- **All pieces, not only the page on screen.** Many tables export only the
  rows shown. Check that the export has as many rows as the choir system
  has pieces: a piece missing from the export becomes a proposal "removed
  from the singers' portal". The dry run shows it before anything happens.
- **The whole list, not a plan.** The list of one rehearsal or concert is
  no export of the repertoire: every piece not in it would count as
  removed.

**Example: Singste** (as of November 2026). Write the shelf mark as the
first word in the field **Hashtags** of each piece, and check with one
piece that the export shows it at the start of the cell. Export the list
under **Repertoire → Gesamtliste nach Titeln** as CSV. The field
**Buchnummer** would fit better, but that table export does not contain it.
The CSV of the song plan of a date (**Liedplanung**) holds only the pieces
of that date; do not use it for the comparison.

## Concerts

Concerts stay in Cantoria, because the programme decides which versions
and rights are needed. For the choir system or the singers' calendars,
download a concert or all upcoming concerts as a calendar file (`.ics`)
([user guide](user-guide.md#concerts-in-your-calendar)). Every concert keeps
its identifier, so importing the file again updates the entries.

## A routine that works

1. After a release decision (rights confirmed, license recorded, valid
   version chosen), the hand-over folder follows the next day, or at once
   with **Sync now**.
2. Upload what is new in the hand-over folder to the choir system and remove
   there what has left it. `Liste.csv` gives the license notice to show with
   each file.
3. Once a month or before a concert, export the list of the choir system and
   compare it; decide the proposals in the inbox.
