#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Builds the release archive for the Nextcloud app store:
#   tools/release/package.sh [--unreleased]
#
# The archive contains only what the app needs at runtime (appinfo, lib,
# templates, img, js, css, l10n, LICENSE, README, CHANGELOG) and, as in
# Nextcloud's own app archives, the license texts (LICENSES/), REUSE.toml,
# which names the license of the files without a header (icons,
# translations, info.xml), and openapi.json, the description of the API for
# agents. Tests, sources, tooling (including tools/screenshots and the MCP
# server in tools/mcp), the documentation with its screenshots (docs/), the
# GitHub templates (.github/), internal documents and dependencies for
# development stay out.
#
# Only files that git tracks go into the archive: they are taken from the
# commit (git archive HEAD), never from the working tree, and only js/ and
# css/ come from the build. A file that is not committed is therefore never
# packaged, and a build with uncommitted changes to tracked files stops.
#
# The archive is reproducible: the same commit gives the same bytes (files
# sorted, times of the last commit, no owner names, gzip without a time).
# npm installs the dependencies without their install scripts
# (--ignore-scripts): no code of a dependency runs before the build.
#
# Checks before the build: info.xml and package.json name the same
# version; info.xml has a <bugs> URL and matches the app store schema
# (check-appinfo.sh); every migration in lib/Migration is listed with its
# checksum in ReleasedMigrationsTest::RELEASED, so that it is frozen once an
# instance has run it (a listed migration that changed always stops the
# build). A version that is handed out, i.e. every version but the internal
# test builds "-dev.N" (also a release candidate such as 1.0.0-rc.1), also
# needs a clean working tree (untracked files included), the tag v<version>
# on HEAD, its entry in CHANGELOG.md, Node.js of .nvmrc, xmllint and reuse,
# and every migration listed in RELEASED.
#
# --unreleased (CI of every push and pull request): builds the archive of
# the current commit for the integration tests without the checks that only
# make sense for a version that is handed out (tag, CHANGELOG, clean tree);
# a migration missing in RELEASED is only reported (the unit test
# ReleasedMigrationsTest fails for it in the pull request).
#
# After packaging, `reuse lint` checks an unpacked copy of the finished
# archive (every file with its copyright and license), when reuse is
# installed; the archive itself is checked to be unchanged afterwards.
#
# The archive is not signed here: the maintainer signs the archive that the
# CI built and published as a draft release, with tools/release/sign.sh
# (docs/release.md).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/release/archive.sh
. tools/release/archive.sh

APP_ID=cantoria
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
UNRELEASED=0
case "${1:-}" in
	'') ;;
	--unreleased) UNRELEASED=1 ;;
	*)
		echo "usage: tools/release/package.sh [--unreleased]" >&2
		echo "(signing moved to tools/release/sign.sh, see docs/release.md)" >&2
		exit 2
		;;
esac
# Only the internal test builds (-dev.N) are not handed out; a release
# candidate (-rc.N) or a beta (-beta.N) goes to the app store and gets all
# checks of a final version.
FINAL=0
if [[ "$VERSION" != *-dev.* ]] && [ "$UNRELEASED" = 0 ]; then
	FINAL=1
fi

# A check that a final version must pass and other builds only report
require() {
	if [ "$FINAL" = 1 ]; then
		echo "$1" >&2
		exit 1
	fi
	echo "warning: $1" >&2
}

# The archive is the build of one commit: the files come from HEAD and the
# build runs in the working tree, so both must be the same.
if ! git diff --quiet HEAD --; then
	git status --porcelain --untracked-files=no >&2
	echo "tracked files have uncommitted changes; commit them first (the archive is built from HEAD)" >&2
	exit 1
fi

# appinfo/info.xml, package.json and package-lock.json name the same version.
PACKAGE_VERSION=$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json)
LOCK_VERSION=$(sed -n '3s/^  "version": "\(.*\)",$/\1/p' package-lock.json)
if [ -z "$VERSION" ] || [ "$VERSION" != "$PACKAGE_VERSION" ] || [ "$VERSION" != "$LOCK_VERSION" ]; then
	echo "version mismatch: appinfo/info.xml has '$VERSION', package.json '$PACKAGE_VERSION', package-lock.json '$LOCK_VERSION'" >&2
	exit 1
fi

# The app store refuses releases whose info.xml has no issue tracker.
if ! grep -q '<bugs>' appinfo/info.xml; then
	echo "appinfo/info.xml has no <bugs> URL; the app store refuses such releases" >&2
	exit 1
fi

# It also refuses an info.xml that does not match its schema.
if command -v xmllint >/dev/null; then
	tools/release/check-appinfo.sh
else
	require "xmllint not found, appinfo/info.xml was not checked against the app store schema"
fi

# The node version of .nvmrc (and of the CI image): another version may
# build other bytes.
NODE_WANTED=$(tr -d ' \n' < .nvmrc)
NODE_MAJOR=$(node -p 'process.versions.node.split(".")[0]')
if [ "$NODE_MAJOR" != "$NODE_WANTED" ]; then
	require "Node.js $NODE_MAJOR is running, .nvmrc names $NODE_WANTED; the archive may differ from the CI build"
fi

# Every migration is frozen in ReleasedMigrationsTest::RELEASED with its
# checksum before a build of it reaches an instance: a migration that an
# instance ran is never run there again, so a later change would reach new
# installations only. A listed migration that changed stops every build; a
# migration that is not listed yet stops a release (and the unit test of
# every pull request), other builds report it.
RELEASED_TEST=tests/Unit/Migration/ReleasedMigrationsTest.php
for migration in lib/Migration/Version*.php; do
	[ -e "$migration" ] || continue
	file=$(basename "$migration")
	sum=$(sha256sum "$migration" | cut -d' ' -f1)
	listed=$(sed -n "s/^[[:space:]]*'$file' => '\([0-9a-f]\{64\}\)',.*/\1/p" "$RELEASED_TEST")
	if [ -z "$listed" ]; then
		if [ "$UNRELEASED" = 0 ]; then
			echo "$file is not listed in RELEASED of $RELEASED_TEST; add it with its checksum ($sum) before this version reaches an instance" >&2
			exit 1
		fi
		echo "warning: $file is not listed in RELEASED of $RELEASED_TEST; add it with its checksum ($sum) in the pull request that adds it" >&2
	elif [ "$listed" != "$sum" ]; then
		echo "$file differs from its checksum in $RELEASED_TEST: a released migration must not change; undo the change and add a new migration" >&2
		exit 1
	fi
done

# A final version is built from its tag only, with its CHANGELOG entry.
if [ "$FINAL" = 1 ]; then
	if [ -n "$(git status --porcelain)" ]; then
		git status --porcelain >&2
		echo "the working tree has changes or untracked files; a release is built from a clean checkout" >&2
		exit 1
	fi
	if [ "$(git tag --points-at HEAD | grep -Fx "v$VERSION" || true)" = "" ]; then
		echo "HEAD carries no tag v$VERSION (git tag -a v$VERSION)" >&2
		exit 1
	fi
	if ! grep -q "^## \[$VERSION\] - " CHANGELOG.md; then
		echo "CHANGELOG.md has no entry '## [$VERSION] - <date>'" >&2
		exit 1
	fi
fi

# The archive holds the committed state of these paths (git archive HEAD).
PACKAGED=(appinfo lib templates img l10n LICENSE LICENSES REUSE.toml README.md CHANGELOG.md openapi.json)

# Times in the archive: those of the last commit (reproducible builds)
SOURCE_DATE_EPOCH=$(git log -1 --format=%ct)
export SOURCE_DATE_EPOCH

OUT="$ROOT/build/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

npm ci --ignore-scripts --no-audit --no-fund >/dev/null
rm -rf js css
npm run build >/dev/null
# The translations of the archive are the committed ones; they must be
# what the sources generate (as checked in the CI job "node").
npm run l10n >/dev/null
if [ -n "$(git status --porcelain -- l10n)" ]; then
	git status --porcelain -- l10n >&2
	echo "l10n/ is not up to date: run 'npm run l10n' and commit the result" >&2
	exit 1
fi

mkdir -p "$STAGE/$APP_ID" "$OUT"
git archive --format=tar HEAD -- "${PACKAGED[@]}" | tar -x -C "$STAGE/$APP_ID"
cp -r js css "$STAGE/$APP_ID/"
# Source maps are not needed at runtime; the license files of bundled
# third-party code (*.license) stay.
find "$STAGE/$APP_ID/js" -name '*.map' -delete

# License texts of bundled third-party code (js/third-party-licenses.txt
# and the js/*.license files of the build) that no file of the repository
# uses: SPDX license list 3.27.0
SPDX=https://raw.githubusercontent.com/spdx/license-list-data/v3.27.0/text
while read -r sum license; do
	# A short network failure is retried; the checksum decides
	curl -fsSL --retry 3 --retry-all-errors -o "$STAGE/$APP_ID/LICENSES/$license.txt" "$SPDX/$license.txt"
	echo "$sum  $STAGE/$APP_ID/LICENSES/$license.txt" | sha256sum -c --quiet -
done <<'LICENSES'
074e6e32c86a4c0ef8b3ed25b721ca23aca83df277cd88106ef7177c354615ff Apache-2.0
f32fb3b417a194167cfad068223fc975ba96c5960513a10f66a3c28720aec1df BSD-2-Clause
000c7fc2d306b8f74db81d1b44ce9a96da4c1674185714cbe4e9d6b82db45aa7 Font-exception-2.0
aaf135472f81c5b4a0dca9367e5bb5e9750032b5bebe5442b36e4c0a47430df3 GPL-2.0-only
fb981668c18a279e285fc4d83fba1e836cc84dd4daa73c9697d3cfd2d8aca6e0 GPL-3.0-or-later
f2ec607f67bb0dd3053b49835b02110d5cd0f8eb6da3aac4dc0b142a6b299be9 ISC
996af0513df21f7496288951c41428a03c174e9e4a9d63665c57d670f845ccb1 LGPL-3.0-or-later
b05785f9f18e6716bab63424b11454513b9943a222595b70411009202fc592b5 MIT
66a3107d5ad6a058aab753eaac2047ccb2ed0e39465dd0fe5844da3e300d5172 MPL-2.0
8eea8287e5876b539670cadb82e99f9a7afddec6f6730811be1daf25d2e9bcfd OFL-1.1
0bdebfeda07d45dada625ae1317c6f833186e798b171d0db640bcf32e92a8240 Unlicense
LICENSES
# Texts that only files outside the archive use (AGPL-3.0-only: a test
# stub; CC0-1.0: configuration and manifests, and REUSE.toml itself, which
# reuse does not count). A file of the archive that needs one of them
# again makes `reuse lint` below fail.
rm "$STAGE/$APP_ID/LICENSES/AGPL-3.0-only.txt" "$STAGE/$APP_ID/LICENSES/CC0-1.0.txt"
if [ ! -s "$STAGE/$APP_ID/js/third-party-licenses.txt" ]; then
	echo "js/third-party-licenses.txt is missing; the build writes it (vite.config.ts)" >&2
	exit 1
fi
# The fonts and C++ libraries inside the Verovio module, which the build
# sees as one npm package: their notices (everything below the header of
# tools/release/verovio-notices.txt) go into js/third-party-licenses.txt,
# their licenses and holders into the .license file of the chunk.
{
	printf '\n%s\n\n' "$(printf -- '-%.0s' $(seq 72))"
	sed '1,/^$/d' tools/release/verovio-notices.txt
} >> "$STAGE/$APP_ID/js/third-party-licenses.txt"
VEROVIO_LICENSE=("$STAGE/$APP_ID"/js/verovio-module-*.chunk.mjs.license)
if [ "${#VEROVIO_LICENSE[@]}" != 1 ] || [ ! -f "${VEROVIO_LICENSE[0]}" ]; then
	echo "expected one js/verovio-module-*.chunk.mjs.license in the build" >&2
	exit 1
fi
# (the tags below are for the archive, not for this script)
# REUSE-IgnoreStart
cat >> "${VEROVIO_LICENSE[0]}" <<'VEROVIO'

Compiled into the Verovio module (js/third-party-licenses.txt):
SPDX-License-Identifier: OFL-1.1
SPDX-License-Identifier: BSD-2-Clause
SPDX-License-Identifier: Unlicense
SPDX-License-Identifier: GPL-2.0-only WITH Font-exception-2.0
SPDX-FileCopyrightText: 2021 Steinberg Media Technologies GmbH (Bravura)
SPDX-FileCopyrightText: 2018 Steinberg Media Technologies GmbH (Petaluma)
SPDX-FileCopyrightText: 2014 Etienne Darbellay, Jean-Francois Marti and Laurent Pugin (Leipzig)
SPDX-FileCopyrightText: 2025 MuseScore BVBA (Leland)
SPDX-FileCopyrightText: Grzegorz Pruchniakowski (Gootville)
SPDX-FileCopyrightText: 2007 Ascender Corporation (Liberation Serif 1.04, Red Hat, Inc.)
SPDX-FileCopyrightText: 2006-2026 Arseny Kapoulkine (pugixml)
SPDX-FileCopyrightText: 2010 Hong Jiang (jsonxx)
SPDX-FileCopyrightText: 2019-2020 Paul Walker (tuning-library)
SPDX-FileCopyrightText: 2014-2017 Thomas Fussell (miniz-cpp)
SPDX-FileCopyrightText: 1999-2018 Craig Stuart Sapp (midifile)
SPDX-FileCopyrightText: 2000 Michael Barr (CRC, public domain)
VEROVIO
# REUSE-IgnoreEnd

# Refuse to package anything internal or development-only.
for forbidden in _intern node_modules tests src tools vendor-bin docs .github; do
	if [ -e "$STAGE/$APP_ID/$forbidden" ]; then
		echo "refusing to package $forbidden" >&2
		exit 1
	fi
done

ARCHIVE="$OUT/$APP_ID-$VERSION.tar.gz"
pack_archive "$STAGE" "$APP_ID" "$SOURCE_DATE_EPOCH" "$ARCHIVE"
SUM=$(sha256sum "$ARCHIVE" | cut -d' ' -f1)

# Every file of the archive with its copyright and license (REUSE). reuse
# reads an unpacked copy of the finished archive, never the files that were
# packed, and the archive must be the same bytes afterwards.
if command -v reuse >/dev/null; then
	CHECK="$STAGE/reuse"
	mkdir "$CHECK"
	unpack_archive "$ARCHIVE" "$CHECK" "$APP_ID"
	reuse --root "$CHECK/$APP_ID" lint --quiet || {
		reuse --root "$CHECK/$APP_ID" lint >&2 || true
		rm -f "$ARCHIVE"
		echo "reuse lint fails for the content of the archive" >&2
		exit 1
	}
	if [ "$(sha256sum "$ARCHIVE" | cut -d' ' -f1)" != "$SUM" ]; then
		rm -f "$ARCHIVE"
		echo "the archive changed during reuse lint" >&2
		exit 1
	fi
else
	require "reuse not found, the content of the archive was not checked with reuse lint"
fi

echo "archive: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
sha256sum "$ARCHIVE"
