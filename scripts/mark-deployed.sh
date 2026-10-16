#!/usr/bin/env bash
# Run after a deploy has gone green in Elastic Beanstalk. Records the commit
# that deploy.zip was built from as the deployed one, so the next build can
# say whether it includes new database migrations.
# Usage: scripts/mark-deployed.sh [commit]   (defaults to the last build's commit)
set -euo pipefail
cd "$(dirname "$0")/.."

commit="${1:-$(cat .deploy-zip-commit 2>/dev/null || true)}"
commit="$(printf '%s' "$commit" | tr -d '[:space:]')"

if [ -z "$commit" ]; then
    echo "No build recorded yet. Run scripts/build-deploy-zip.sh first, or pass the deployed commit:" >&2
    echo "  scripts/mark-deployed.sh <commit>" >&2
    exit 1
fi

if ! git cat-file -e "${commit}^{commit}" 2>/dev/null; then
    echo "$commit is not a commit in this repository." >&2
    exit 1
fi

git rev-parse "$commit" > .last-deploy-commit
echo "Recorded $(git log -1 --format='%h %s' "$commit") as deployed."
