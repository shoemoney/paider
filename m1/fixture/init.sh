#!/usr/bin/env bash
# Stamps a disposable, git-initialized copy of this fixture into a scratch
# directory so a Paider rehearsal never runs against the paider repo's own
# working tree. This is the fixture's "own git repo" per m1/RUNBOOK.md.
#
# Usage:
#   m1/fixture/init.sh              # copies into a fresh mktemp -d
#   m1/fixture/init.sh /tmp/mytest  # copies into the given path (created if absent)
#
# Prints the resulting path on the last line of stdout on success.

set -euo pipefail

fixture_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

dest="${1:-}"
if [ -z "$dest" ]; then
    dest="$(mktemp -d -t m1-rehearsal)"
else
    mkdir -p "$dest"
fi

# Copy everything except init.sh itself and any prior .paider/ session state —
# a rehearsal run should start from the pristine fixture, not a previous run's
# leftovers.
for entry in "$fixture_dir"/*; do
    name="$(basename "$entry")"
    [ "$name" = "init.sh" ] && continue
    [ "$name" = ".paider" ] && continue
    cp -R "$entry" "$dest/"
done
cp "$fixture_dir/.m1-fixture-marker" "$dest/.m1-fixture-marker" 2>/dev/null || true

# Verify the copy actually landed before declaring success — a silently
# incomplete copy (e.g. a missed dotfile) would let a human rehearse against
# a fixture that's quietly missing a file.
expected=(
    "src/Cart.php"
    "src/Receipt.php"
    "tests/run.php"
    "README.md"
    ".m1-fixture-marker"
)
for f in "${expected[@]}"; do
    if [ ! -e "$dest/$f" ]; then
        echo "init.sh: copy is incomplete, missing $f in $dest" >&2
        exit 1
    fi
done

git -C "$dest" init -q
git -C "$dest" add -A
git -C "$dest" -c user.email="rehearsal@localhost" -c user.name="M1 Rehearsal" \
    commit -q -m "fixture: pristine baseline"

echo "$dest"
