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
2. **Trademark check of the name, before the certificate request.** The
   certificate is issued for the app id `cantoria` and cannot be moved to
   another id; a name that must change after the request means a new
   certificate, a new app id and a new listing. Search the registers for
   "Cantoria" as a word mark and for similar spellings in the Nice classes
   9, 41 and 42, with the list of goods and services and the status of each
   hit: [DPMAregister](https://register.dpma.de),
   [TMview](https://www.tmdn.org/tmview),
   [WIPO Global Brand Database](https://branddb.wipo.int) and
   [USPTO](https://tmsearch.uspto.gov) (with TSDR for the details). Record
   the result with its date in the
   [name search](de/namensrecherche-cantoria.md) and, where a hit is
   close, have it assessed by a lawyer. Request the certificate only when
   the result is recorded and the name stays.
3. **Key and certificate request.** Create a private key and a certificate
   signing request with the app id as common name, and request the certificate
   with a pull request to
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests):

   ```sh
   openssl req -nodes -newkey rsa:4096 -keyout cantoria.key -out cantoria.csr -subj "/CN=cantoria"
   ```

   Keep `cantoria.key` secret and outside the repository. Nextcloud
   returns the signed certificate (`cantoria.crt`).
4. **Register the app** on [apps.nextcloud.com](https://apps.nextcloud.com)
   (account → register app) with the certificate and the signature of the app
   id:

   ```sh
   echo -n "cantoria" | openssl dgst -sha512 -sign cantoria.key | openssl base64
   ```
5. **Branch protection for `main`** (repository settings → Rules →
   Rulesets → new branch ruleset, target the default branch; or Branches →
   branch protection rule for `main`):
   - require a pull request before merging;
   - require status checks to pass, with the one check **CI result** (the
     job `ci-ok` of `.github/workflows/ci.yml`, source GitHub Actions);
   - block force pushes and deletion of `main`.

   Only **CI result** is required, not the single jobs: it waits for all
   jobs of the CI and is red when one failed, was cancelled or was skipped
   although code changed. A pull request with documentation alone runs the
   CI as well (the job `changes` skips build and tests, the checks of the
   documentation and REUSE run), so its required check reports and the
   pull request does not wait for a check that never comes. The names of
   the single jobs carry matrix values ("Unit tests 8.4", "Integration
   tests, Nextcloud 35"); they may change without touching the protection.
   When a job is added to `ci.yml`, add it to `needs` of `ci-ok` (a unit
   test, `.github/scripts/ci-scripts.test.mjs`, fails otherwise).

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
- **Release candidate** (`1.0.0-rc.1`, also `-beta.N`): a pre-release for
  the app store. Only `-dev.N` counts as internal: `tools/release/package.sh`
  builds a release candidate with all checks of a final version (tag
  `v1.0.0-rc.1` on `HEAD`, the entry `## [1.0.0-rc.1] - <date>` in
  `CHANGELOG.md`, clean checkout, every migration in `RELEASED`, `xmllint`,
  `reuse` and Node.js of `.nvmrc`), and it follows the same steps below.
- **Patch version** (0.6.1): fixes without new functions.
- **1.0.0**: the stable version (milestone 1.0).

The internal builds before 0.6.0 were numbered 0.1.x.

`main` always carries the next pre-release, never the version already
released: right after a release, raise `<version>` to the next
`-dev.1` (after 0.16.1: `0.17.0-dev.1`), and raise `-dev.N` with every
schema change or new route on `main`, so that a test instance that installs
`main` runs the new migration and reloads the routes. A migration counts as
released as soon as it is on `main`, since a test instance may have run it:
it goes into `ReleasedMigrationsTest::RELEASED` with its checksum in the
same pull request, and from then on it is never changed
(`tools/release/package.sh` refuses to build a version whose migrations are
not all listed with the right checksum; in every pull request the unit
test `ReleasedMigrationsTest::testEveryMigrationIsListed` fails for a
migration that is not listed, and every build stops for a listed migration
whose checksum no longer matches). When that test reports a changed
checksum, undo the change of the migration and add a new one; never update
the checksum of a listed file.

## Every release

1. Raise `<version>` in `appinfo/info.xml` from the pre-release of `main`
   to the final version (semantic versioning; a schema change or a new
   route always needs a new version, otherwise Nextcloud does not run the
   migration or reload the routes), and the same version in `package.json`
   and `package-lock.json`; `tools/release/package.sh` stops when the three
   differ. Check the supported Nextcloud range in `<dependencies>`.
2. Check that every migration of the version is listed with its checksum in
   `RELEASED` of `tests/Unit/Migration/ReleasedMigrationsTest.php`
   (`sha256sum lib/Migration/<file>`). This normally happened when the
   migration came to `main` (see above); `tools/release/package.sh` refuses
   to build otherwise.
3. Move the entries under `[Unreleased]` in `CHANGELOG.md` to the new version
   with its date.
4. Run the checks: `composer test:unit`, `composer psalm`,
   `composer cs:check`, `npm run lint`, `npm run stylelint`, `npm test`,
   `npm run typecheck`, `npm run l10n` (fails on
   missing translations), and the integration tests in `tests/Integration`
   against the oldest and the newest supported Nextcloud. The CI workflow
   (`.github/workflows/ci.yml`) runs all of them on every push and pull
   request that changes code (documentation alone runs only the checks of
   the documentation and REUSE), the integration tests with the demo data on fresh Nextcloud 33
   and 35 instances (`tools/ci/integration-demo.sh`, see
   [tests/README.md](../tests/README.md)), each on the release archive that
   the job `archive` built (`tools/release/package.sh --unreleased`). When
   the supported range in `<dependencies>` changes, change the Nextcloud
   versions of the `integration` job as well.
5. Tag the version (`git tag -a v<version>`) and push the tag. The
   workflow **Release** (`.github/workflows/release.yml`):
   - runs the whole CI for the tag; its job `archive` builds the archive
     with all checks of a final version (clean checkout, the tag on `HEAD`,
     the `CHANGELOG.md` entry, every migration in `RELEASED`, `info.xml`
     against the app store schema pinned with its checksum, Node.js of
     `.nvmrc`, `reuse lint` over the content of the archive), and the
     integration tests install exactly this archive;
   - only if all of that is green, builds the archive again in a separate
     job on a fresh runner and checks that both are the same bytes;
   - creates a **draft release** for the tag with
     `cantoria-<version>-unsigned.tar.gz` and `SHA256SUMS`. Note the
     checksum that the log of the job `archive` prints.

   The archive holds only committed files (`git archive` of the commit) and
   the build of `js/` and `css/`; npm installs the dependencies without
   their install scripts. It holds the license texts (`LICENSES/`,
   `REUSE.toml`), the texts of the licenses of the bundled third-party code
   (downloaded with a fixed checksum), `js/third-party-licenses.txt` (every
   bundled package with its license, and the fonts and C++ libraries
   compiled into Verovio with their copyright notices) and nothing internal
   or for development. It is reproducible: files sorted, times of the last
   commit, no owner names.
6. **Test the upgrade before the upload.** On a test instance that runs the
   previous release (with data, e.g. the demo data of `tools/demo`), replace
   `apps/cantoria` with the content of
   `cantoria-<version>-unsigned.tar.gz` of the draft release (remove the old
   directory first, as the app store update does), run `occ upgrade` and
   check the update path:
   migrations ran (`occ migrations:status cantoria`), background jobs run,
   settings, roles and links are kept, and the pages open. (An unsigned
   archive is fine for a test instance; Nextcloud only reports the missing
   signature in the integrity check.) A failure here costs a new tag, not a
   broken update for every choir.
7. **Sign the archive of the draft release** on a machine with PHP, OpenSSL
   and `occ` of a Nextcloud server archive that is unpacked but not
   installed (for `occ integrity:sign-app`); Node.js and npm are not needed,
   nothing is built there. A Nextcloud that is not installed registers its
   own commands, among them `integrity:sign-app`, but loads no apps, so no
   code of an app runs next to the key; `occ` of an installed instance
   would start all its apps first, and `sign.sh` warns about it. Download
   `nextcloud-<version>.tar.bz2` with its `.sha512` and `.asc` from
   [download.nextcloud.com](https://download.nextcloud.com/server/releases/),
   check both (`sha512sum -c`, `gpg --verify` with the
   [key of Nextcloud](https://nextcloud.com/nextcloud.asc)) and unpack it;
   do not run the installer:

   ```sh
   tools/release/sign.sh cantoria-<version>-unsigned.tar.gz SHA256SUMS \
     /path/cantoria.key /path/cantoria.crt /path/to/nextcloud/occ
   ```

   Compare the checksum in `SHA256SUMS` with the one in the log of the run
   first. The script checks the archive against `SHA256SUMS`, writes the
   code signature (`appinfo/signature.json`), packs the archive again in the
   same reproducible way as `build/release/signed/cantoria-<version>.tar.gz`,
   checks with `tools/release/compare.sh` that only
   `appinfo/signature.json` was added, checks the archive signature against
   the certificate and prints it.
8. Attach `build/release/signed/cantoria-<version>.tar.gz` to the draft
   release, publish the release (the app store downloads the archive from a
   public HTTPS URL), and upload that URL with the printed signature on
   apps.nextcloud.com (account → upload app release). Mark pre-releases as
   nightly or with a pre-release version such as `1.0.0-rc.1` (built with
   all checks of a final version, see above).
9. After the app store has published the release, update a test instance
   from the app store once more and check that the update arrives.
10. Raise `<version>` on `main` to the next pre-release (e.g. `0.18.0-dev.1`).

Any change to a file after signing invalidates the code signature; sign the
archive of the draft release again instead of editing the signed archive.

### Trial run of the release chain

The workflow Release has to work on the day of the first tag; a failure
then means moving the tag or a new version. Try it before:

1. **After the merge, on `main`** (the workflow can only be started by
   hand once `release.yml` is on the default branch): wait until the CI
   run of the push of that commit has finished, then start **Release** by
   hand (Actions → Release → Run workflow, branch `main`). This runs the
   CI as a called workflow (`workflow_call`), the job `rebuild` on a fresh
   runner and the comparison of the bytes, with `package.sh --unreleased`;
   the draft release is skipped (only for a tag). The CI of the trial has
   a concurrency group of its own (`Release-workflow_dispatch-<sha>`), so
   it does not cancel the push run of the same commit; the check "CI
   result" of the trial must be green as well.
2. **The final path in a fork**: on a branch of the fork, set the version
   to the coming one (e.g. 0.17.0) in `appinfo/info.xml`, `package.json`
   and `package-lock.json`, add its `CHANGELOG.md` entry, tag it
   (`git tag -a v0.17.0`) and push branch and tag to the fork. Check that
   Release runs through, the draft release holds
   `cantoria-0.17.0-unsigned.tar.gz` and `SHA256SUMS`, and that
   `tools/release/sign.sh` signs that archive with a test key and a
   self-signed test certificate (`openssl req -x509 -nodes -newkey
   rsa:4096 -keyout test.key -out test.crt -subj "/CN=cantoria"`). Delete
   the draft release and the tag in the fork afterwards.

### Checking the archive of the app store

Anyone can check that the archive in the app store is the one that the CI
built and tested from the tag: download the signed archive and
`cantoria-<version>-unsigned.tar.gz` from the release, check the latter
against `SHA256SUMS`, and run

```sh
tools/release/compare.sh cantoria-<version>-unsigned.tar.gz cantoria-<version>.tar.gz
```

It reports a difference in anything but `appinfo/signature.json`. To check
the unsigned archive against the tag itself, check out the tag and run
`tools/release/package.sh` with Node.js of `.nvmrc` (or in the container
image of the job `archive`): it builds the same bytes.
