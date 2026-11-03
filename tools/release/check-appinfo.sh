#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Checks appinfo/info.xml against the schema of the Nextcloud app store
# (info.xsd), which refuses a release that does not match it, e.g. elements
# in the wrong order:
#   tools/release/check-appinfo.sh
#
# The schema is taken from the repository of the app store at a fixed commit
# and checked against its SHA-256, like the license texts in package.sh, so
# that the check does not change with what a server returns on that day.
# apps.nextcloud.com/schema/apps/info.xsd serves the current state of the
# same file; when the app store changes its schema, update the commit and
# the checksum below (sha256sum of the new file). Needs curl and xmllint.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# nextcloud/appstore, branch master on 3 November 2026
XSD_COMMIT=2e18d7a2ff90cc7e44b666cbe6a42a604ccd7f67
XSD_SHA256=a8ec208d4be9e507dc28b24e1bb7d8fafa45cbf00ed4b460f1545b131369d362
XSD_URL="https://raw.githubusercontent.com/nextcloud/appstore/$XSD_COMMIT/nextcloudappstore/api/v1/release/info.xsd"

XSD=$(mktemp)
trap 'rm -f "$XSD"' EXIT
# A short network failure is retried; the checksum decides
curl -fsSL --retry 3 --retry-all-errors -o "$XSD" "$XSD_URL"
echo "$XSD_SHA256  $XSD" | sha256sum -c --quiet -
xmllint --noout --schema "$XSD" "$ROOT/appinfo/info.xml"
