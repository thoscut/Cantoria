#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Builds the release archive for the Nextcloud app store:
#   tools/release/package.sh [--sign KEY CERT OCC]
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
# The archive is reproducible: the same commit gives the same bytes (files
# sorted, times of the last commit, no owner names, gzip without a time).
# A final version (no "-dev.N") needs a clean working tree, the tag
# v<version> on HEAD and its entry in CHANGELOG.md. The license texts of
# bundled third-party code that the repository does not need itself (GPL,
# LGPL, OFL) are downloaded with a fixed checksum into LICENSES/ of the
# archive; js/third-party-licenses.txt lists every bundled package.
#
# With --sign (KEY = private key of the app store certificate request, CERT =
# the certificate issued by Nextcloud, OCC = path to `occ` of any Nextcloud
# instance), the staged app is code signed first (`occ integrity:sign-app`
# writes appinfo/signature.json into the archive), and the SHA-512 signature
# of the archive for the app store upload form is printed.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

APP_ID=cantoria
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
SIGN=0
if [ "${1:-}" = "--sign" ]; then
	SIGN=1
	KEY="${2:?path to the private key}"
	CERT="${3:?path to the certificate}"
	OCC="${4:?path to occ of a Nextcloud instance}"
fi

# appinfo/info.xml and package.json must name the same version.
PACKAGE_VERSION=$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json)
if [ -z "$VERSION" ] || [ "$VERSION" != "$PACKAGE_VERSION" ]; then
	echo "version mismatch: appinfo/info.xml has '$VERSION', package.json has '$PACKAGE_VERSION'" >&2
	exit 1
fi

# The app store refuses releases whose info.xml has no issue tracker.
if ! grep -q '<bugs>' appinfo/info.xml; then
	echo "appinfo/info.xml has no <bugs> URL; the app store refuses such releases" >&2
	exit 1
fi

# It also refuses an info.xml that does not match its schema (e.g. elements
# in the wrong order). Needs xmllint and network access.
if command -v xmllint >/dev/null; then
	XSD=$(mktemp)
	curl -fsSL -o "$XSD" https://apps.nextcloud.com/schema/apps/info.xsd
	xmllint --noout --schema "$XSD" appinfo/info.xml || { rm -f "$XSD"; exit 1; }
	rm -f "$XSD"
else
	echo "warning: xmllint not found, appinfo/info.xml was not checked against the app store schema" >&2
fi

# A final version is built from its tag only, with its CHANGELOG entry.
if [[ "$VERSION" != *-* ]]; then
	if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
		echo "the working tree has changes; a release is built from a clean checkout" >&2
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

# Times in the archive: those of the last commit (reproducible builds)
SOURCE_DATE_EPOCH=$(git log -1 --format=%ct)
export SOURCE_DATE_EPOCH

OUT="$ROOT/build/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

npm ci --no-audit --no-fund >/dev/null
rm -rf js css
npm run build >/dev/null
npm run l10n >/dev/null

mkdir -p "$STAGE/$APP_ID" "$OUT"
cp -r appinfo lib templates img js css l10n LICENSE LICENSES REUSE.toml README.md CHANGELOG.md openapi.json "$STAGE/$APP_ID/"
# Source maps are not needed at runtime; the license files of bundled
# third-party code (*.license) stay.
find "$STAGE/$APP_ID/js" -name '*.map' -delete

# License texts of bundled third-party code (js/third-party-licenses.txt)
# that no file of the repository uses: SPDX license list 3.27.0
SPDX=https://raw.githubusercontent.com/spdx/license-list-data/v3.27.0/text
while read -r sum license; do
	curl -fsSL -o "$STAGE/$APP_ID/LICENSES/$license.txt" "$SPDX/$license.txt"
	echo "$sum  $STAGE/$APP_ID/LICENSES/$license.txt" | sha256sum -c --quiet -
done <<'LICENSES'
fb981668c18a279e285fc4d83fba1e836cc84dd4daa73c9697d3cfd2d8aca6e0 GPL-3.0-or-later
996af0513df21f7496288951c41428a03c174e9e4a9d63665c57d670f845ccb1 LGPL-3.0-or-later
8eea8287e5876b539670cadb82e99f9a7afddec6f6730811be1daf25d2e9bcfd OFL-1.1
LICENSES
if [ ! -s "$STAGE/$APP_ID/js/third-party-licenses.txt" ]; then
	echo "js/third-party-licenses.txt is missing; the build writes it (vite.config.ts)" >&2
	exit 1
fi

# Refuse to package anything internal or development-only.
for forbidden in _intern node_modules tests src tools vendor-bin docs .github; do
	if [ -e "$STAGE/$APP_ID/$forbidden" ]; then
		echo "refusing to package $forbidden" >&2
		exit 1
	fi
done

if [ "$SIGN" = 1 ]; then
	php "$OCC" integrity:sign-app --privateKey="$KEY" --certificate="$CERT" --path="$STAGE/$APP_ID"
fi

ARCHIVE="$OUT/$APP_ID-$VERSION.tar.gz"
tar -C "$STAGE" --sort=name --mtime="@$SOURCE_DATE_EPOCH" --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
	--pax-option=exthdr.name=%d/PaxHeaders/%f,delete=atime,delete=ctime -cf - "$APP_ID" | gzip -n -9 > "$ARCHIVE"
echo "archive: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
sha256sum "$ARCHIVE"

if [ "$SIGN" = 1 ]; then
	SIGNATURE="$STAGE/archive.sig"
	openssl dgst -sha512 -sign "$KEY" -out "$SIGNATURE" "$ARCHIVE"
	# The app store checks the signature with the public key of the certificate
	openssl x509 -in "$CERT" -pubkey -noout > "$STAGE/cert.pub"
	openssl dgst -sha512 -verify "$STAGE/cert.pub" -signature "$SIGNATURE" "$ARCHIVE" >/dev/null || {
		echo "the signature does not match the certificate: wrong key or certificate?" >&2
		exit 1
	}
	echo "signature (paste into the app store upload form):"
	openssl base64 -in "$SIGNATURE"
fi
