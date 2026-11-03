# Contributing to Cantoria

Thank you for helping with Cantoria. Bug reports, ideas, translations,
documentation and code are all welcome.

This project follows the
[Nextcloud Code of Conduct](https://nextcloud.com/contribute/code-of-conduct/).
Please report security issues privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## Before you start

- **Issues first for larger changes.** Open an issue (bug report or feature
  request) before you put much work into a pull request, so that we can agree
  on the approach.
- **Principles.** The app follows the principles in [ROADMAP.md](ROADMAP.md)
  (German): the app suggests and people decide, rights belong to the version,
  nothing is deleted, files are only read, roles are bound to groups, there is
  always a way back, paper stays the fallback, no external servers, no legal
  advice, accessible and inclusive, only what is needed, simple and
  error-free to use, simply built (KISS), secure from the start. The lasting
  goals of every change are simplicity, accessibility, security and
  usability. The roadmap also lists what the app deliberately does not do,
  such as the functions of a choir's practice system.

## Development setup

You need a Nextcloud 33, 34 or 35 test instance with MySQL, MariaDB or PostgreSQL, PHP 8.2
or newer, Node.js 24 with npm 11, and Composer for the PHP tools.

```sh
cd /path/to/nextcloud/apps
git clone https://github.com/thoscut/choircoresmanager.git cantoria
cd cantoria
npm ci
npm run dev          # development build; `npm run watch` rebuilds on changes
composer install     # PHP tools: PHPUnit, Psalm, coding standard
php ../../occ app:enable cantoria
```

The [demo data](tools/demo/README.md) gives you a small invented archive to
work with. Never use the data of a real choir in issues, tests, screenshots or
examples.

## Checks

The CI workflow (`.github/workflows/ci.yml`) runs these checks on every push
and pull request; please run them before you open a pull request:

```sh
npm run lint          # ESLint, including accessibility rules for templates
npm run stylelint     # styles of the Vue components
npm run typecheck     # TypeScript and the templates
npm test              # unit tests of the frontend logic (Vitest)
npm run build
npm run l10n          # translations; fails when a text has no translation
composer lint         # PHP syntax
composer cs:check     # Nextcloud coding standard (composer cs:fix fixes it)
composer psalm        # static analysis
composer test:unit    # PHP unit tests, no Nextcloud server needed
composer run openapi  # openapi.json of the API for agents; commit it when it changes
(cd tools/mcp && npm ci && npm test)   # MCP server for agents
```

The integration tests run against a real Nextcloud with the app installed;
[tests/README.md](tests/README.md) explains them. `tools/ci/integration-demo.sh`
sets up the demo data and runs them, but only on a fresh test instance: it
creates an account and refuses to run when the catalog is not empty.

## Translations

- English is the source language. Write user-facing texts as English source
  strings: `t('cantoria', '…')` and `n('cantoria', '…', '…', count)` in
  `src/`, `$l->t('…')` in `lib/`.
- The German translations live in `tools/l10n/translations.mjs`. A plain
  string is used for both variants; an array holds `[informal, formal]`
  ("du" and "Sie"). Run `npm run l10n` and commit the regenerated files in
  `l10n/`.
- The German interface uses neutral, simple and inclusive language, e.g.
  *Archivverwaltung*, *Chorleitung*, *Chormitglieder*, *Chorportal*.
- French, Spanish, Italian, Dutch and Polish live in
  `tools/l10n/translations.<lang>.mjs` (key: the English source; plurals: one
  text per plural form, see `tools/l10n/languages.mjs`). A missing
  translation falls back to English; `npm run l10n` lists it. Only German is
  required. The terms of each language are in
  [tools/l10n/glossary.md](tools/l10n/glossary.md); `npm test` checks
  placeholders and plural forms.
- Translations: fr, es, it, nl, pl were made with the help of AI and need a
  review by native speakers; please open an issue or pull request.
- To add a language, add it to `tools/l10n/languages.mjs` with the plural
  rule of `@nextcloud/l10n`, create `tools/l10n/translations.<lang>.mjs` and
  extend the glossary. Please open an issue first.

## Style

- Code, identifiers and comments are in English; the user interface is
  translated through `l10n/`.
- PHP follows the Nextcloud coding standard (`composer cs:check`), the
  frontend the Nextcloud ESLint and Stylelint configurations.
- Accessibility is part of every change (target WCAG 2.2 AA): everything works
  with the keyboard, inputs have labels, information is never shown by colour
  alone, contrast fits in the light and the dark theme.
- Security rules of the app:
  - Every route checks the caller's role on the server;
    `tests/Unit/Controller/ControllerSecurityTest` checks the route attributes.
  - Each field has its write roles in `lib/Service/Fields.php`.
  - The app never loads anything from other servers.
  - [SECURITY.md](SECURITY.md) describes the design.
- A schema change or a new route needs a new version in `appinfo/info.xml`,
  `package.json` and `package-lock.json`; see [docs/release.md](docs/release.md).
- Add user-visible changes to `CHANGELOG.md` under `[Unreleased]`.
- Update the documentation in `docs/` when you change what users see, always
  in both languages: the user guide and the administration guide exist in
  English (`docs/user-guide.md`, `docs/admin-guide.md`) and German
  (`docs/de/benutzerhandbuch.md`, `docs/de/administrationshandbuch.md`). Both
  versions have the same headings in the same order; `npm test` compares them
  and checks every link and anchor. The app links the sections listed in
  `src/docs.ts`, with the English and the German anchor. For new screenshots
  use [tools/screenshots](tools/screenshots/README.md) with the demo data.

## Commit messages

- In English, one topic per commit.
- The first line says what the change does, as a short sentence without a
  final period, e.g. "Practice audio files per voice from the score view".
  Keep it under about 72 characters.
- After a blank line, the body explains why and what changed, wrapped at about
  72 characters. Mention the tests you added or ran.
- Refer to issues with `#123` where it helps.

## Pull requests

Fill in the pull request template, keep pull requests focused, and make sure
the CI run is green. For changes to the user interface, add a screenshot made
with the demo data and describe how you checked keyboard and screen reader
use.

## License

By contributing you agree that your contribution is licensed under the
[GNU Affero General Public License v3.0 or later](LICENSE), the license of
this project.

### Licensing information in every file (REUSE)

The repository follows the [REUSE specification](https://reuse.software) 3.3,
like Nextcloud's own apps. Every new file needs an SPDX header in the form of
its neighbours, for example in PHP after `declare(strict_types=1);`:

    /**
     * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
     * SPDX-License-Identifier: AGPL-3.0-or-later
     */

Vue and Markdown files use `<!--` with `  - SPDX-…` lines, TypeScript and
JavaScript a `/** … */` block, shell scripts `#` lines after the shebang.
Code, templates, tests, icons, translations, documentation and screenshots are
`AGPL-3.0-or-later`; the demo data and trivial configuration are `CC0-1.0`.
Files without room for a comment (JSON, images) are listed in `REUSE.toml`.
Check with `pipx run reuse lint`; CI runs the same check.
