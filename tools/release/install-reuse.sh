#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs reuse (https://reuse.software) for the CI and the release build:
#   tools/release/install-reuse.sh [DIR]
#
# reuse and every dependency come in the versions of reuse-requirements.txt
# and are checked against the SHA-256 noted there (pip --require-hashes),
# wheels only, so no code of a package runs while it is installed. The same
# reuse checks the repository (job "reuse") and the content of the release
# archive (package.sh). Installs into a virtual environment in DIR
# (default /opt/reuse) and, without DIR, links /usr/local/bin/reuse to it.
# Needs python3 with venv (Debian: python3-venv).
set -eu

REQUIREMENTS="$(dirname "$0")/reuse-requirements.txt"
DIR="${1:-/opt/reuse}"

python3 -m venv "$DIR"
"$DIR/bin/pip" install --quiet --disable-pip-version-check --no-cache-dir \
	--require-hashes --only-binary=:all: -r "$REQUIREMENTS"
if [ $# -eq 0 ]; then
	ln -sf "$DIR/bin/reuse" /usr/local/bin/reuse
fi
"$DIR/bin/reuse" --version | head -n 1
