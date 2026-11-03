#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Signs the release archive that the CI built from the tag, for the app store:
#   tools/release/sign.sh ARCHIVE SHA256SUMS KEY CERT OCC
#
# ARCHIVE and SHA256SUMS are the files of the draft release that the
# workflow Release created for the tag (docs/release.md). KEY is the private
# key of the app store certificate, CERT the certificate issued by
# Nextcloud, OCC the path to `occ` of any Nextcloud instance.
#
# Nothing is built here and no npm package runs on the machine with the
# key: the script checks the archive against SHA256SUMS, unpacks it, writes
# the code signature (`occ integrity:sign-app`, appinfo/signature.json),
# packs it again in the same reproducible way, checks with compare.sh that
# only appinfo/signature.json was added, signs the archive (SHA-512) and
# prints the signature for the app store upload form. The signed archive is
# written to build/release/signed/.
set -euo pipefail

if [ $# -ne 5 ]; then
	echo "usage: tools/release/sign.sh ARCHIVE SHA256SUMS KEY CERT OCC" >&2
	exit 2
fi
ARCHIVE="$1"
SUMS="$2"
KEY="$3"
CERT="$4"
OCC="$5"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=tools/release/archive.sh
. "$ROOT/tools/release/archive.sh"
APP_ID=cantoria

# The archive is the one the CI built and published with its checksum
NAME=$(basename "$ARCHIVE")
EXPECTED=$(awk -v name="$NAME" '$2 == name || $2 == "./" name || $2 == "*" name { print $1 }' "$SUMS")
if [ -z "$EXPECTED" ]; then
	echo "$SUMS names no checksum for $NAME" >&2
	exit 1
fi
if [ "$EXPECTED" != "$(sha256sum "$ARCHIVE" | cut -d' ' -f1)" ]; then
	echo "$NAME does not match its checksum in $SUMS" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
unpack_archive "$ARCHIVE" "$STAGE" "$APP_ID"
if [ -e "$STAGE/$APP_ID/appinfo/signature.json" ]; then
	echo "$NAME is signed already" >&2
	exit 1
fi
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$STAGE/$APP_ID/appinfo/info.xml")
if [ "$NAME" != "$APP_ID-$VERSION.tar.gz" ]; then
	echo "$NAME holds version $VERSION" >&2
	exit 1
fi
# All files of the archive carry the time of the tagged commit
EPOCH=$(stat -c %Y "$STAGE/$APP_ID/appinfo/info.xml")

php "$OCC" integrity:sign-app --privateKey="$KEY" --certificate="$CERT" --path="$STAGE/$APP_ID"

OUT="$ROOT/build/release/signed"
mkdir -p "$OUT"
SIGNED="$OUT/$NAME"
pack_archive "$STAGE" "$APP_ID" "$EPOCH" "$SIGNED"
"$ROOT/tools/release/compare.sh" "$ARCHIVE" "$SIGNED"
if ! tar -tzf "$SIGNED" | grep -x "$APP_ID/appinfo/signature.json" >/dev/null; then
	echo "occ wrote no appinfo/signature.json" >&2
	exit 1
fi
echo "signed archive: $SIGNED"
sha256sum "$SIGNED"

SIGNATURE="$STAGE/archive.sig"
openssl dgst -sha512 -sign "$KEY" -out "$SIGNATURE" "$SIGNED"
# The app store checks the signature with the public key of the certificate
openssl x509 -in "$CERT" -pubkey -noout > "$STAGE/cert.pub"
openssl dgst -sha512 -verify "$STAGE/cert.pub" -signature "$SIGNATURE" "$SIGNED" >/dev/null || {
	echo "the signature does not match the certificate: wrong key or certificate?" >&2
	exit 1
}
echo "signature (paste into the app store upload form):"
openssl base64 -in "$SIGNATURE"
