#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Compares two release archives file by file, apart from the code signature:
#   tools/release/compare.sh A.tar.gz B.tar.gz
#
# The signed archive of the app store differs from the archive that the CI
# built from the tag only by appinfo/signature.json. This script shows that
# nothing else differs: same files, same contents, same file modes. Anyone
# can check the archive of the app store against the draft release of the
# tag this way (docs/release.md); sign.sh runs it after signing.
set -euo pipefail

if [ $# -ne 2 ]; then
	echo "usage: tools/release/compare.sh A.tar.gz B.tar.gz" >&2
	exit 2
fi
# shellcheck source=tools/release/archive.sh
. "$(dirname "$0")/archive.sh"

APP_ID=cantoria
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir "$WORK/a" "$WORK/b"
unpack_archive "$1" "$WORK/a" "$APP_ID"
unpack_archive "$2" "$WORK/b" "$APP_ID"
rm -f "$WORK/a/$APP_ID/appinfo/signature.json" "$WORK/b/$APP_ID/appinfo/signature.json"

# Contents and the set of files
diff -r --no-dereference "$WORK/a/$APP_ID" "$WORK/b/$APP_ID"
# File modes and kinds
modes() {
	(cd "$1" && find . -printf '%y %m %p\n' | LC_ALL=C sort)
}
diff <(modes "$WORK/a/$APP_ID") <(modes "$WORK/b/$APP_ID")
echo "same files and contents apart from appinfo/signature.json: $1 $2"
