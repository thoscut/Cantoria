#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Upgrade test (job "upgrade" in .github/workflows/ci.yml): an instance that
# ran the version of the base branch, with the demo data and everything the
# integration tests of that version left behind, gets the new release
# archive, the way an administrator updates the app:
#   bash tools/ci/upgrade-check.sh <nextcloud-root> <new-archive.tar.gz> <user>
#
# The script
#   1. saves a complete backup (cantoria:export --full) of the old version,
#   2. replaces apps/cantoria with the content of the new archive,
#   3. runs `occ upgrade` and the pending migrations of the app
#      (`occ migrations:migrate cantoria`; while a pull request keeps the
#      version number, Nextcloud would not run them by itself),
#   4. checks that the app is enabled with the version of the archive,
#   5. saves a backup of the new version and compares: every table of the
#      old backup exists and holds at least as many rows (no migration
#      dropped data),
#   6. runs the file sync and the module list of the new version on the
#      migrated data.
#
# Ends with exit code 0 only if every step passed.
set -euo pipefail

if [ $# -ne 3 ]; then
	echo "usage: $0 <nextcloud-root> <new-archive.tar.gz> <user>" >&2
	exit 2
fi
NC_ROOT="$(cd "$1" && pwd)"
ARCHIVE="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
CI_USER="$3"
PHP="${PHP:-php}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

occ() {
	"$PHP" -d memory_limit=1G "$NC_ROOT/occ" --no-warnings "$@"
}

step() {
	printf '\n== %s\n' "$*"
}

step "1. Backup of the old version"
old_version="$(occ app:list --enabled --output=json | "$PHP" -r '$a = json_decode(stream_get_contents(STDIN), true); echo $a["enabled"]["cantoria"] ?? "";')"
[ -n "$old_version" ] || { echo "cantoria is not enabled" >&2; exit 1; }
echo "old version: $old_version"
occ cantoria:export --full > "$WORK/before.json"

step "2. New release archive"
rm -rf "$NC_ROOT/apps/cantoria"
tar -xzf "$ARCHIVE" -C "$NC_ROOT/apps" --no-same-owner
new_version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$NC_ROOT/apps/cantoria/appinfo/info.xml")"
echo "new version: $new_version"

step "3. Upgrade and migrations"
occ upgrade
occ migrations:status cantoria
occ migrations:migrate cantoria

step "4. App enabled with the new version"
enabled="$(occ app:list --enabled --output=json | "$PHP" -r '$a = json_decode(stream_get_contents(STDIN), true); echo $a["enabled"]["cantoria"] ?? "";')"
if [ "$enabled" != "$new_version" ]; then
	echo "expected cantoria $new_version enabled, found '$enabled'" >&2
	exit 1
fi
echo "ok: cantoria $enabled"

step "5. No data lost"
occ cantoria:export --full > "$WORK/after.json"
# shellcheck disable=SC2016 # PHP code, not shell
"$PHP" -r '
	$read = function (string $file): array {
		$text = (string)file_get_contents($file);
		$start = strpos($text, "{");
		return json_decode(substr($text, $start === false ? 0 : $start), true, 512, JSON_THROW_ON_ERROR)["tables"];
	};
	$before = $read($argv[1]);
	$after = $read($argv[2]);
	$failed = false;
	$rows = 0;
	foreach ($before as $table => $list) {
		$now = $after[$table] ?? null;
		if ($now === null) {
			echo "FAIL table $table is missing after the upgrade\n";
			$failed = true;
		} elseif (count($now) < count($list)) {
			printf("FAIL table %s: %d rows before, %d after\n", $table, count($list), count($now));
			$failed = true;
		} else {
			$rows += count($list);
		}
	}
	printf("%d tables, %d rows before the upgrade, all kept\n", count($before), $rows);
	if ($rows === 0) {
		echo "FAIL the old version had no data: the upgrade test checked nothing\n";
		$failed = true;
	}
	exit($failed ? 1 : 0);
' -- "$WORK/before.json" "$WORK/after.json"

step "6. The new version works on the migrated data"
occ cantoria:file-sync "$CI_USER"
occ cantoria:modules list

echo
echo "upgrade from $old_version to $new_version passed"
