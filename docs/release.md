# Releasing

How a version of the app gets into the Nextcloud app store. The app store
only accepts signed releases from a public repository.

## Once

1. **Public repository** with the app's code (the app store certificate
   request links to it):
   [github.com/thoscut/choircoresmanager](https://github.com/thoscut/choircoresmanager).
   `appinfo/info.xml` names it in `<website>`, `<bugs>` (issue tracker,
   required; the packaging script refuses to build without it),
   `<repository>` and `<documentation>`. The `<screenshot>` entries point to
   `docs/screenshots/` on the `main` branch, so they only show once that
   branch holds this app.

   The repository started as a fork of the Orchestra Scores Manager (another
   app) with its own open pull requests. The first push of this app replaces
   `main`; before that, keep the old line intact:

   1. Create the branch `orchestrascoresmanager` from the current `main`
      (GitHub: branch selector → "Create branch from main", or
      `git push origin origin/main:refs/heads/orchestrascoresmanager`).
   2. Change the base of every open pull request that targets `main` to
      `orchestrascoresmanager` (pull request → Edit → base). Pull requests
      based on another feature branch stay as they are.
   3. Only then push this app: `git push --force-with-lease=main:<old main
      sha> origin main`, so the push fails if `main` moved in the meantime.

   All other branches keep their history. The fork relation to the
   Orchestra Scores Manager stays visible on GitHub until GitHub support
   detaches the fork.
2. **Key and certificate request.** Create a private key and a certificate
   signing request with the app id as common name, and request the certificate
   with a pull request to
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests):

   ```sh
   openssl req -nodes -newkey rsa:4096 -keyout cantoria.key -out cantoria.csr -subj "/CN=cantoria"
   ```

   Keep `cantoria.key` secret and outside the repository. Nextcloud
   returns the signed certificate (`cantoria.crt`).
3. **Register the app** on [apps.nextcloud.com](https://apps.nextcloud.com)
   (account → register app) with the certificate and the signature of the app
   id:

   ```sh
   echo -n "cantoria" | openssl dgst -sha512 -sign cantoria.key | openssl base64
   ```

## Version numbers

The app uses [semantic versioning](https://semver.org/), aligned with the
milestones of the [roadmap](../ROADMAP.md):

- **Minor version** (0.6.0, 0.7.0, …): a round with new functions,
  normally one milestone. Until 1.0.0, a minor version may also change
  behaviour.
- **Pre-release** (`0.7.0-dev.1`, `0.7.0-dev.2`, …): internal test builds
  within a round. They are deployed to test instances only and never
  uploaded to the app store. Nextcloud orders them before the final version
  (`0.6.0` < `0.7.0-dev.1` < `0.7.0-dev.2` < `0.7.0`), so every build still
  runs its migrations and reloads its routes.
- **Patch version** (0.6.1): fixes without new functions.
- **1.0.0**: the stable version (milestone 1.0).

The internal builds before 0.6.0 were numbered 0.1.x.

## Every release

1. Raise `<version>` in `appinfo/info.xml` (semantic versioning; a schema
   change or a new route always needs a new version, otherwise Nextcloud does
   not run the migration or reload the routes), and the same version in
   `package.json` and `package-lock.json`; `tools/release/package.sh` stops
   when `info.xml` and `package.json` differ. Check the supported Nextcloud
   range in `<dependencies>`.
2. Move the entries under `[Unreleased]` in `CHANGELOG.md` to the new version
   with its date.
3. Run the checks: `composer test:unit`, `composer psalm`,
   `composer cs:check`, `npm run lint`, `npm run stylelint`, `npm test`,
   `npm run typecheck`, `npm run l10n` (fails on
   missing translations), and the integration tests in `tests/Integration`
   against the oldest and the newest supported Nextcloud. The CI workflow
   (`.github/workflows/ci.yml`) runs all of them on every push and pull
   request, the integration tests with the demo data on fresh Nextcloud 33
   and 35 instances (`tools/ci/integration-demo.sh`, see
   [tests/README.md](../tests/README.md)); release only from a commit whose
   CI run is green. When the supported range in `<dependencies>` changes,
   change the Nextcloud versions of the `integration` job as well.
4. Tag the version (`git tag -a v<version>`) and push the tag. The
   workflow **Release** (`.github/workflows/release.yml`) builds the archive
   twice from the tag, checks that both builds are the same bytes and keeps
   the archive with its checksum as an artifact of the run. It does not sign
   and does not upload.
5. Build and sign on a machine with Node.js, PHP and a Nextcloud instance
   (for `occ integrity:sign-app`):

   ```sh
   tools/release/package.sh --sign /path/cantoria.key /path/cantoria.crt /path/to/nextcloud/occ
   ```

   The script checks `appinfo/info.xml` against the app store schema (with
   `xmllint`); for a final version (no `-dev.N`) it also needs a clean
   working tree, the tag `v<version>` on `HEAD` and the entry in
   `CHANGELOG.md`. It builds the frontend and the translations, copies only
   the runtime files together with the license texts (`LICENSES/`,
   `REUSE.toml`), adds the texts of the GPL, LGPL and SIL Open Font License
   for the bundled Verovio and its music fonts (downloaded with a fixed
   checksum), checks `js/third-party-licenses.txt` (every bundled package
   with its license, written by the build), refuses to package internal or
   development files, writes the code signature (`appinfo/signature.json`)
   into the archive, checks the archive signature against the certificate
   and prints it. The archive is reproducible: files sorted, times of the
   last commit, no owner names.
6. Attach `build/release/cantoria-<version>.tar.gz` to the release in the
   repository (the app store downloads it from a public HTTPS URL) and upload
   that URL with the printed signature on apps.nextcloud.com (account → upload
   app release). Mark pre-releases as nightly or with a pre-release version
   such as `1.0.0-rc.1`.
7. After the app store has published the release, update a test instance
   from the app store and check the update path (migrations, background
   jobs, settings kept).

Any change to a file after signing invalidates the code signature; build and
sign again instead of editing the archive.
