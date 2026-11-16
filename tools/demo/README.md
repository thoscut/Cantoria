<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Demo data

A small, invented music archive of a fictitious choir, the "Demochor", for
trying out the Cantoria app, for screenshots and for end-to-end tests.

**No real data.** The choir, its concerts, places, publishers, invoices and
the persons "A. Example" and "B. Beispiel" are invented. Nothing comes from
the catalog or the files of a real choir. The only real persons are the
composers, lyricists and arrangers of the works, all of whom died more than
70 years ago.

## License

- The demo data – `catalog.json`, the score specs in `scores/`, the
  transcription reports in `reports/`, the generated CSV, PDF, MusicXML and
  report files and the four-part settings ("Demo-Satz") – are
  dedicated to the public domain under
  [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/).
- The works themselves (melodies and texts) are in the public domain: every
  composer, lyricist and arranger involved died more than 70 years ago. The
  settings are new and simple four-part settings of these melodies, written
  for this demo; they are not copies of historical or published settings.
- Two versions are marked "geschützt" (protected) in the catalog. That is a
  label for the demo only: their "arranger" A. Example is a fictitious living
  person. The files themselves are CC0 like all other demo data.
- The build and check scripts (`build.mjs`, `lib/`, `check-csv.php`) and this
  README belong to the app's code and documentation and are licensed under
  AGPL-3.0-or-later. Each file names its license in an SPDX header or in
  `REUSE.toml` in the repository root.

## Contents

`node tools/demo/build.mjs` writes everything to `build/demo/Cantoria-Demo/`
(`build/` is not part of the repository):

```
Cantoria-Demo/
  Mappe_1/ … Mappe_4/      placeholder PDFs and MusicXML, file names start with the shelf mark;
                           two transcription reports "… - Bericht.json" (1-1, 2-1)
  LIESMICH.txt             short note in German; ignored by the file sync
  _Import/                 skipped by the file sync (folder name starts with "_")
    catalog.csv            catalog in the legacy format (36 German columns)
    catalog-supplement.csv copy status, kinds, scoring etc. (extended columns)
    persons.csv            research list of persons (person import)
    performances.csv       programme lines of concerts (performance import)
    song-lists.csv         song lists (performance import)
```

The catalog has 23 copies (shelf marks `1-1` to `4-3`) of 20 works:

| Shelf mark | Title | Version | Rights status | Files | Shows |
|---|---|---|---|---|---|
| 1-1 | Der Mond ist aufgegangen (Schulz/Claudius) | Demo-Satz SATB | gemeinfrei | PDF, MusicXML, report | pickup, 1st/2nd ending, two verses; second version 3-1; transcription report with review points |
| 1-2 | Freude, schöner Götterfunken (Beethoven/Schiller) | Demo-Satz SATB, four staves | gemeinfrei | PDF, MXL | endings, tie across the bar line; legacy "on the singers' portal" without confirmation |
| 1-3 | Großer Gott, wir loben dich (Wien 1776/Franz) | Demo-Satz SATB | gemeinfrei | PDF, MusicXML | repeat, two verses |
| 1-4 | Stille Nacht, heilige Nacht (Gruber/Mohr) | Demo-Satz SATB | gemeinfrei | PDF, MXL | 6/8 with dotted rhythms |
| 1-5 | Stille Nacht, heilige Nacht | same version as 1-4 | gemeinfrei | PDF | second copy, **retired** |
| 1-6 | Es ist ein Ros entsprungen (Praetorius) | Satz 1609 | gemeinfrei | PDF | |
| 1-7 | Ein feste Burg ist unser Gott (Luther) | Satz: B. Beispiel | gemeinfrei | PDF | **conflict**: marked public domain, arranger's dates unknown |
| 1-8 | Abschied vom Walde (Mendelssohn/Eichendorff) | original | gemeinfrei | PDF | "reserve" in a programme |
| 2-1 | Ännchen von Tharau (Silcher/Dach/Herder) | Demo-Satz TTBB | gemeinfrei | PDF, MXL, report | repeat, two verses, "Tenor I/II" on one staff; transcription report with review points |
| 2-2 | Kein schöner Land (Volksweise/Zuccalmaglio) | Demo-Satz TTBB | gemeinfrei | PDF, MusicXML | pickup of three eighths, short last bar |
| 2-3 | Am Brunnen vor dem Tore (Schubert/Silcher/Müller) | Satz Silcher | gemeinfrei | PDF | |
| 2-4 | Tischlied (Zelter/Goethe) | edition by B. Beispiel | offen | PDF | same print as 3-2 |
| 2-5 | Das Wandern ist des Müllers Lust (Zöllner/Müller) | original | gemeinfrei | PDF | |
| 2-6 | Die Gedanken sind frei (Volksweise) | Satz: A. Example | geschützt | PDF | licence and proof of purchase (fictitious) |
| 2-7 | Heidenröslein (Werner/Goethe) | original | gemeinfrei | PDF | copy **missing** |
| 3-1 | Der Mond ist aufgegangen | Bearbeitung TTBB: A. Example | geschützt | PDF, MXL | **internal preservation copy** (view only for librarian and conductor, no playback) |
| 3-2 | Tischlied | same print as 2-4 | offen | PDF | second copy of the same print |
| 3-3 | Nun danket alle Gott (Crüger/Rinckart) | | gemeinfrei | PDF | |
| 3-4 | Lobe den Herren (Stralsund 1665/Neander) | | gemeinfrei | PDF | copy **lent** |
| 3-5 | Die Nachtigall (Mendelssohn/Goethe) | | gemeinfrei | PDF | |
| 4-1 | Ave verum corpus (Mozart) | | gemeinfrei | PDF | Latin |
| 4-2 | Locus iste (Bruckner) | | gemeinfrei | PDF | |
| 4-3 | Abendlied (Rheinberger) | | gemeinfrei | – | copy without file |

`Mappe_4/4-04_Neuzugang-ohne-Eintrag.pdf` has no catalog entry: the file sync
lists it as not assignable, and the next free shelf mark in binder 4 is 4-5.

The legacy import creates one work, one version and one copy per row. The
merge proposals then offer the three pairs with the same title and composer
(1-1/3-1, 1-4/1-5, 2-4/3-2): merging the works gives a work with two
versions, moving copy 1-5 to the version of 1-4 (or 3-2 to 2-4) gives a
version with two copies.

## Building

Node 22 or newer, no further dependencies:

```sh
node tools/demo/build.mjs            # writes build/demo/Cantoria-Demo/
node tools/demo/build.mjs --check    # only checks scores, reports and catalog
```

The build reads the legacy columns and the allowed values from
`lib/Service/CatalogCsv.php` and `lib/Service/Fields.php` and stops when the
catalog no longer fits them. It also checks every setting: parallel fifths
and octaves, voice ranges and spacing, measure lengths and lyrics. The output
is reproducible: the same sources give byte-identical files.

Checks of the result:

```sh
# the app's score logic with Verovio: loading, measures, voices, playing order
node tools/score-check/check.mjs build/demo/Cantoria-Demo/Mappe_*/*.mxl build/demo/Cantoria-Demo/Mappe_*/*.musicxml

# the import files and the transcription reports with the app's own PHP classes (after `composer install`)
php tools/demo/check-csv.php . build/demo/Cantoria-Demo/_Import
```

`check-csv.php` parses every CSV with `CatalogCsv`, checks each catalog cell
against the field registry, confirms that parse → write gives the same bytes,
and reports what the person and performance imports will make of the lists.
It also reads the transcription reports of the archive folder (the folder
above the import folder) with `TranscriptionReportParser`, as the file sync
does, and checks that each names its copy and the score file next to it.

### Score specs

Each file in `scores/` describes one setting: staves (a part name such as
`Tenor 1/2` on a staff with two voices gives the voices "Tenor 1" and
"Tenor 2"), measure structure (numbers, pickup, repeats, endings) and one
string per voice with sounding pitches, e.g. `F4:q | G4:q (A4:e B4:e) ...`.
The notation is described at the top of `lib/notation.mjs`. To add a work,
add a spec, reference it with `"score"` from a copy in `catalog.json` and
build.

## Trying it out

You need a Nextcloud with the Cantoria app and an account in a group that
holds the role "Catalog (music librarian)".

1. **Upload** the folder `build/demo/Cantoria-Demo` to your Nextcloud files
   (e.g. by drag and drop in the Files app).
2. **Administration → Cantoria** (admin): assign groups to the roles
   and choose `Cantoria-Demo` as archive folder. If other accounts are to
   open the files, share the folder with the groups of the roles; **Access to
   the archive folder** on the same page shows who cannot open it.
3. **Import the catalog**: Archive management → Import → `_Import/catalog.csv`.
   The dry run reports 23 rows, 7 with MusicXML, 1 preservation copy and the
   rights status 19 × public domain, 2 × protected, 2 × open. Then import.
4. *Optional:* import `_Import/catalog-supplement.csv` the same way. This
   repeated import only adds copy status (retired 1-5, missing 2-7, lent 3-4),
   kinds, counts, scoring, key and language; it reports the other catalog
   columns as missing, which is expected, and stores a snapshot first.
5. **File sync**: 30 files named after a shelf mark (23 PDF, 4 MXL, 3
   MusicXML), 29 assigned, `Mappe_4/4-04_…pdf` not assignable, `LIESMICH.txt`
   ignored, `_Import` skipped, 4-3 without a file. The two transcription
   reports are counted apart (`reports`: 2 files, 2 assigned); with the module
   "Reviewing transcriptions" on (preset "standard") both are read (2
   imported). Together 32 new file rows.
6. **Persons**: import `_Import/persons.csv`: 30 rows, 29 persons (Friedrich
   Silcher appears twice), role words such as "Tonsatz von" removed, B.
   Beispiel uncertain. Afterwards the protection proposal shows "free" for
   e.g. 2-1, "unknown" for 2-4 and 3-2, and the resubmission list shows 1-7
   as a conflict.
7. **Performances**: import `_Import/performances.csv` (9 concerts 2014–2026
   of the Demochor, 45 lines: 40 assigned, 2 ambiguous, 2 not in the catalog,
   1 with the unknown shelf mark 2-17; 37 count as performance – a guest
   choir, an encore "if needed" and a reserve piece do not), then
   `_Import/song-lists.csv` (3 song lists). The repertoire columns now show
   current, dormant and earlier works; resolve the ambiguous lines on the
   start page.
8. **Look around**: open 1-2 or 2-1 and play an excerpt; open 3-1 as
   librarian (internal preservation copy, no playback); the release lists
   show 1-2 and 2-1 as "remove" until their public domain status is
   confirmed in the app; the merge proposals list the three pairs above.
9. **Review points**: open the score view of 1-1 or 2-1 and its review. Each
   report gives 7 review points: 4 uncertain readings with measures and voice
   (e.g. 2-1, measure 7, "T II"), 1 omitted mark, 1 material finding and 1
   question. 1-1 has 5 points for the conductor and 2 for the librarian (its
   question names no measure), 2-1 has 6 and 1. Choosing a point shows its
   measures with the voice highlighted. The reports also propose one catalog
   field each (1-1 "Veröffentlichung", 2-1 "Verlag / Bestellnummer"); the
   proposals reach the inbox only when the module "Proposals inbox" is on (it
   is off in the preset "standard"). Their rights assessment (public domain)
   equals the catalog, so no rights status is proposed.

The repertoire classes depend on the date: with the default thresholds,
works performed since 2023 count as current in 2026.

## Transcription reports

`reports/<shelf mark>.json` are invented reports of a transcription round in
the format `bericht/1` (see `docs/api.md`, "Reviewing transcriptions and
corrections"), with the optional fields `runde` and `datum` of the proposed
extension. `build.mjs` writes each as `<base name> - Bericht.json` next to
its score, where the file sync finds it, and checks it against the score
spec: required fields, shelf mark and base name of the copy, every measure
number and voice named, and the counted marks (`umfang`, dynamics, slurs,
ties, fermatas) as `abnahme.py` of the transcription skill would count them.
