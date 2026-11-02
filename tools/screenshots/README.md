# Screenshots

`shoot.mjs` recreates every picture in [`docs/screenshots`](../../docs/screenshots/README.md)
from a Nextcloud that holds the public demo data of [`tools/demo`](../demo/README.md).
It logs in as the demo accounts in a headless Chromium-based browser, opens
each view and saves the window or a cropped part of it. The public link and
the public repertoire page are taken without signing in.

- **Window:** fixed at 1600 × 1000 pixels, device scale 1.
- **Themes:** light, and dark where the file name says so (`*-dark.png`).
- **Languages:** English; the pictures in `de/` use the German interface in
  informal address. Every picture of the English guides has a German twin
  `de/<file>` for the German guides (`GERMAN_TWINS` in `shots.mjs`). Each
  language has its own browser, so that date fields follow it too (on Linux
  through the environment variable `LANG`); pages without signing in get
  the language as `Accept-Language`.
- **Stable pictures:** animations and transitions are off, reduced motion
  is requested, the text cursor and toasts are hidden, and the focus ring
  and the mouse pointer are moved out of the way.
- **No side effects from background jobs:** the browser's background job
  calls (`cron.php`, Nextcloud's "AJAX" mode) are blocked while capturing.
- **Small files:** the PNG files are reduced to a palette of 256 colours with
  [sharp](https://sharp.pixelplumbing.com/); `--no-optimize` keeps the raw
  screenshots.

The tool has its own `package.json`. Its dependencies (puppeteer-core and
sharp) are not part of the app and are never installed by the app's
`npm ci`.

## Requirements

- Node.js 22 or newer
- Chrome, Chromium or Edge; the script looks in the usual places, or give the
  path with `--browser`
- A **test instance** of Nextcloud 33–35 with the Cantoria app and the
  apps notifications, dav and dashboard enabled. The catalog of the app is
  global: use an instance whose catalog may be replaced by the demo data.

> [!WARNING]
> `--prepare` writes into the catalog (imports, confirmations, tasks, a
> concert). Never run it against an instance with real data.

## Setting up the instance

Run these steps as the user that owns the Nextcloud files, in the Nextcloud
folder. `tools/ci/integration-demo.sh` does the same for the integration
tests.

1. Build the demo data in the repository: `node tools/demo/build.mjs`.
2. Create the groups and accounts with neutral names. One password for all
   of them is enough:

   ```sh
   php occ group:add --display-name="Music librarians" demo-librarians
   php occ group:add --display-name="Conductors" demo-conductors
   php occ group:add --display-name="Board" demo-board
   php occ group:add --display-name="Transcription tools" demo-tools
   php occ group:add --display-name="Choir members" demo-members
   export OC_PASS='…'
   php occ user:add --password-from-env --display-name="Demo Librarian" --group=demo-librarians demo-librarian
   php occ user:add --password-from-env --display-name="Demo Conductor" --group=demo-conductors demo-conductor
   php occ user:add --password-from-env --display-name="Demo Contributor" --group=demo-tools demo-contributor
   php occ user:add --password-from-env --display-name="Demo Administrator" --group=admin demo-admin
   php occ user:add --password-from-env --display-name="Demo Choir Member" --group=demo-members demo-member
   ```

3. For each account: English as language, no first-run wizard; the dashboard
   of the librarian shows only the widget of the app:

   ```sh
   for uid in demo-librarian demo-conductor demo-contributor demo-admin demo-member; do
     php occ user:setting "$uid" core lang en
     php occ user:setting "$uid" core locale en_GB
     php occ user:setting "$uid" core timezone Europe/Berlin
     php occ user:setting "$uid" firstrunwizard show 99.0.0
   done
   php occ user:setting demo-librarian dashboard layout cantoria-due
   ```

4. Copy `build/demo/Cantoria-Demo` into the files of `demo-librarian`
   (for example `data/demo-librarian/files/Cantoria-Demo`) and run
   `php occ files:scan --path=/demo-librarian/files/Cantoria-Demo`, or
   upload the folder in the Files app.
5. Roles, archive folder and the modules of the newer pictures (sharing,
   folder book, mirror folder of the print station, annual snapshots,
   proposals, statistics; they are part of no preset or not of Standard):

   ```sh
   php occ cantoria:setup demo-librarian Cantoria-Demo \
     -r librarian=demo-librarians -r operations=demo-librarians \
     -r conductor=demo-conductors -r contributor=demo-tools -r reader=demo-board \
     -r member=demo-members
   php occ cantoria:modules enable member_links member_practice_audio public_links \
     public_repertoire folder_book print_mirror annual_snapshot proposals statistics
   ```

6. Import the catalog and link the files:

   ```sh
   php occ cantoria:import --execute demo-librarian Cantoria-Demo/_Import/catalog.csv
   php occ cantoria:import --execute demo-librarian Cantoria-Demo/_Import/catalog-supplement.csv
   php occ cantoria:file-sync demo-librarian
   ```

7. A calendar for the calendar dialog: `php occ dav:create-calendar demo-conductor Choir`.

Everything else is done by `--prepare` through the app's own API, as the
account that may do each step: it shares the demo folder read-only with the
conductors and the administrators, imports persons, performances and song
lists, confirms rights, adds licenses, chooses valid versions, records the
roles of the persons involved (composer, lyricist) and traditional melodies
and texts, asks questions, makes a proposal and an internal link, and plans
the concert "Adventskonzert 2026". For the newer pictures it releases 1-1 for the
singers' portal and 1-2 for publication, shares both as a whole piece with
the scan (PDF), with a link for choir members and a public link, puts three
works on the public repertoire page, creates three folders of the folder
book (one out with an invented name), chooses the folder "Print station" of
the administrator as mirror folder (with a file of the same name as a scan,
which the sync skips), corrects one note of 2-2 so that it has a second
revision, marks 1-6 as a scientific edition and writes two annual snapshots
with a confirmation and a license between them. It skips what is done
already, so it can run again.

## Running

```sh
cd tools/screenshots
npm ci
NC_PASSWORD='…' node shoot.mjs --url http://localhost:8080 --prepare
```

| Option | Meaning |
|---|---|
| `--url` | address of the Nextcloud (or `NC_URL`) |
| `--prepare` | create the demo state first (see above) |
| `--only a,b` | only these pictures, by name or file, e.g. `--only catalog,de/catalog.png` |
| `--out dir` | target folder (default: `docs/screenshots`) |
| `--browser path` | browser executable (or `BROWSER_PATH`) |
| `--demo-folder name` | folder of the demo data in the librarian's files (default: `Cantoria-Demo`) |
| `--no-optimize` | keep the screenshots as the browser made them |
| `--list` | list the pictures with account, language and theme |

Passwords come from the environment only and are never printed:
`NC_PASSWORD` for all accounts, or `NC_PASSWORD_<UID>` per account
(upper case, other characters as `_`, e.g. `NC_PASSWORD_DEMO_ADMIN`). Other
account names can be set with `NC_USER_LIBRARIAN`, `NC_USER_CONDUCTOR`,
`NC_USER_CONTRIBUTOR`, `NC_USER_ADMIN` and `NC_USER_MEMBER`, and the groups
for the shares of the demo folder with `NC_GROUP_CONDUCTORS` and
`NC_GROUP_ADMINS` (default `admin`).

The script changes the language of the accounts while it runs and sets them
back to English at the end. Dates, "last performed" and the repertoire
classes depend on the day the pictures are taken.

## Changing the pictures

The pictures are defined in `shots.mjs`: file, account, language, theme and
the steps to the view. A step function returns a clip rectangle for a
detail picture, or nothing for the whole window. Texts of the app are given
as English source strings; `h.t()` translates them for the German pictures
from `l10n/de.json`.

After a change, check every picture you replaced, update its alternative
text in the documentation and the list in
[`docs/screenshots/README.md`](../../docs/screenshots/README.md). The App
Store reads the pictures listed in `appinfo/info.xml` (at most ten, the
first is the title picture).

## Removing the demo again

Delete the demo accounts and groups (`php occ user:delete …`,
`php occ group:delete …`); their files, shares and calendars go with them.
The catalog keeps the demo data until it is emptied or the instance is
replaced.
