#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Decides for the CI (job "changes" in ci.yml) whether code changed or
# documentation alone:
#   sh .github/scripts/code-changed.sh EVENT [BEFORE]
# EVENT is github.event_name, BEFORE github.event.before of a push. Prints
# "code=true" or "code=false" (for $GITHUB_OUTPUT) and the changed files on
# standard error.
#
# Documentation alone means only files below docs/, Markdown files,
# tools/screenshots/ and the GitHub templates for issues and pull requests.
# Everything else, also a workflow, counts as code. When the changes cannot
# be determined (a manual run, a tag, the first push of a branch, a base
# that is not in the history), everything counts as code: the CI then runs
# in full rather than too little.
set -eu

EVENT="${1:-}"
BEFORE="${2:-}"

code() {
	echo "$1" >&2
	echo "code=true"
	exit 0
}

case "$EVENT" in
	pull_request)
		# The checkout of a pull request is its merge commit with the
		# current base as first parent
		git rev-parse --verify --quiet 'HEAD^2' >/dev/null || code "HEAD is not a merge commit"
		BASE='HEAD^1'
		;;
	push)
		case "$BEFORE" in
			'' | *[!0]*) ;;
			*) code "push without a base (new branch or tag)" ;;
		esac
		[ -n "$BEFORE" ] || code "push without a base"
		git cat-file -e "$BEFORE^{commit}" 2>/dev/null || code "the base $BEFORE of the push is not in the history"
		BASE="$BEFORE"
		;;
	*)
		code "event $EVENT: everything runs"
		;;
esac

FILES=$(git diff --name-only --no-renames "$BASE" HEAD --)
echo "changed files:" >&2
echo "$FILES" >&2
OTHER=$(echo "$FILES" | grep -v -E '^(docs/|tools/screenshots/|\.github/ISSUE_TEMPLATE/)|\.md$' | grep -v '^$' || true)
if [ -n "$OTHER" ]; then
	echo "code changed:" >&2
	echo "$OTHER" >&2
	echo "code=true"
else
	echo "documentation alone: build and tests are skipped" >&2
	echo "code=false"
fi
