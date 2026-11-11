#!/usr/bin/env bash
# Enables the repository's git hooks (privacy checks before each commit).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
chmod +x .githooks/* scripts/*.sh
git config core.hooksPath .githooks

terms="$HOME/.config/livescoretracker/forbidden-terms.txt"
echo 'Git hooks enabled.'
if [[ ! -f "$terms" ]]; then
    echo "Create $terms with one forbidden term per line (real names, organisation, domain)."
fi
