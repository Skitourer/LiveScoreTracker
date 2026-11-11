#!/usr/bin/env bash
#
# Privacy guard (spec § 25.3): the public repository must never contain real data.
#
# Usage:
#   scripts/privacy-check.sh --staged          check files staged for commit (pre-commit hook)
#   scripts/privacy-check.sh --all             check every tracked file (CI)
#   scripts/privacy-check.sh --message FILE    check a commit message (commit-msg hook)
#   scripts/privacy-check.sh --commits RANGE   check commit messages in a git range (CI)
#
# Forbidden terms (real names, organisation, domain...) are NEVER stored in the repository.
# They are read, one per line (case-insensitive, '#' starts a comment), from:
#   - the FORBIDDEN_TERMS environment variable (CI: a GitHub secret), or
#   - the file named by PRIVACY_TERMS_FILE, default ~/.config/livescoretracker/forbidden-terms.txt
# Matches are reported by file and line only, so the terms never appear in logs.

set -euo pipefail

FIXTURES_DIR='tests/fixtures/'
SELF='scripts/privacy-check.sh'
FORBIDDEN_FILE_REGEX='(^|/)\.env($|\.)|\.(sqlite3?|db|xlsx|xlsm|xls|ods|csv|bak|dump|sql\.gz)$'
SECRET_REGEX='-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|sk-ant-[A-Za-z0-9_-]{20,}|xox[abprs]-[A-Za-z0-9-]{10,}'

errors=0
fail() {
    echo "privacy-check: $*" >&2
    errors=$((errors + 1))
}

load_terms() {
    local source=''
    if [[ -n "${FORBIDDEN_TERMS:-}" ]]; then
        source="$FORBIDDEN_TERMS"
    else
        local file="${PRIVACY_TERMS_FILE:-$HOME/.config/livescoretracker/forbidden-terms.txt}"
        if [[ -f "$file" ]]; then
            source="$(cat "$file")"
        fi
    fi

    TERMS_FILE="$(mktemp)"
    trap 'rm -f "$TERMS_FILE"' EXIT
    printf '%s\n' "$source" | sed -e 's/#.*//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | grep -v '^$' >"$TERMS_FILE" || true

    if [[ ! -s "$TERMS_FILE" ]]; then
        echo 'privacy-check: warning: no forbidden-terms list found, term check skipped.' >&2
        echo '               Set FORBIDDEN_TERMS or create ~/.config/livescoretracker/forbidden-terms.txt' >&2
    fi
}

# Reads a file's content: from the index (staged mode) or the working tree.
content_of() {
    if [[ "$MODE" == 'staged' ]]; then
        git show ":$1"
    else
        cat -- "$1"
    fi
}

check_text() {
    local label="$1" text_file="$2" line
    if [[ -s "$TERMS_FILE" ]]; then
        while IFS= read -r line; do
            fail "$label:$line contains a forbidden term"
        done < <(grep -n -i -F -f "$TERMS_FILE" -- "$text_file" | cut -d: -f1 | sort -un)
    fi
}

check_files() {
    local file tmp
    tmp="$(mktemp)"
    while IFS= read -r -d '' file; do
        if [[ "$file" =~ $FORBIDDEN_FILE_REGEX ]] && [[ "$file" != "$FIXTURES_DIR"* ]] && [[ "$file" != '.env.example' ]]; then
            fail "$file: this kind of file must not be committed (real data, database or secrets)"
            continue
        fi

        content_of "$file" >"$tmp" 2>/dev/null || continue
        grep -Iq . "$tmp" || continue # skip binary files

        check_text "$file" "$tmp"
        if [[ "$file" != "$SELF" ]] && grep -Eq -- "$SECRET_REGEX" "$tmp"; then
            fail "$file: looks like it contains a secret (key, token)"
        fi
    done < <(list_files)
    rm -f "$tmp"
}

list_files() {
    if [[ "$MODE" == 'staged' ]]; then
        git diff --cached --name-only --diff-filter=ACMR -z
    else
        git ls-files -z
    fi
}

MODE="${1:-}"
case "$MODE" in
    --staged | --all)
        MODE="${MODE#--}"
        load_terms
        check_files
        ;;
    --message)
        load_terms
        check_text 'commit message' "${2:?missing message file}"
        ;;
    --commits)
        load_terms
        msg="$(mktemp)"
        for sha in $(git rev-list "${2:?missing git range}"); do
            git log -1 --format='%B' "$sha" >"$msg"
            check_text "commit ${sha:0:8} message" "$msg"
        done
        rm -f "$msg"
        ;;
    *)
        sed -n '3,16p' "$0"
        exit 2
        ;;
esac

if ((errors > 0)); then
    echo "privacy-check: $errors problem(s) found. Remove the real data before committing." >&2
    exit 1
fi
echo 'privacy-check: OK'
