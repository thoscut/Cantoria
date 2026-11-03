# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Shared by package.sh and sign.sh (sourced, not run).

# pack_archive DIR APP_ID EPOCH ARCHIVE: packs DIR/APP_ID reproducibly
# (files sorted, all times EPOCH, no owner names, gzip without a time).
pack_archive() {
	tar -C "$1" --sort=name --mtime="@$3" --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
		--pax-option=exthdr.name=%d/PaxHeaders/%f,delete=atime,delete=ctime -cf - "$2" | gzip -n -9 > "$4"
}

# unpack_archive ARCHIVE DIR APP_ID: unpacks a release archive into DIR and
# checks that it holds the one directory APP_ID and nothing outside it.
unpack_archive() {
	local entry
	while IFS= read -r entry; do
		case "$entry" in
			*/../* | */..) ;;
			"$3" | "$3"/*) continue ;;
		esac
		echo "$1: unexpected entry '$entry' (only $3/ belongs in the archive)" >&2
		return 1
	done < <(tar -tzf "$1")
	tar -xzf "$1" -C "$2" --no-same-owner
}
