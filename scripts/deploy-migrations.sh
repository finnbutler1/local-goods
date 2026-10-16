#!/usr/bin/env bash
# Lists migration files added, changed or removed since the last deployed
# commit (recorded in .last-deploy-commit by scripts/mark-deployed.sh), one
# per line, including uncommitted ones, since deploy.zip is built from the
# working folder. Prints nothing when there are none.
# Usage: scripts/deploy-migrations.sh [commit]   (defaults to .last-deploy-commit)
# Exit codes: 0 = checked (list may be empty), 3 = no deployed commit known.
set -uo pipefail
cd "$(dirname "$0")/.."

base="${1:-$(cat .last-deploy-commit 2>/dev/null || true)}"
base="$(printf '%s' "$base" | tr -d '[:space:]')"

if [ -z "$base" ] || ! git cat-file -e "${base}^{commit}" 2>/dev/null; then
    exit 3
fi

{
    git diff --name-only "$base" -- database/migrations
    git ls-files --others --exclude-standard -- database/migrations
} | sort -u
