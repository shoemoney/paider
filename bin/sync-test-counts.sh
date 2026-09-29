#!/usr/bin/env bash
#
# Rewrite the test counts in README.md and ROADMAP.md from a real run.
#
# WHY THIS FILE EXISTS. The README carried "518 passing, 2975 assertions" while the measured
# suite was 562, and ROADMAP.md said 527. An adversarial review flagged it, it was corrected by
# hand — and it was stale again within one commit, because the fix itself added a test. Three
# documents quoting a number that changes every time anyone writes a test is a number nobody
# should have to maintain.
#
# So the number is generated, from a run, at the moment it is written. The cost table already
# works this way (CostReadmeGoldenTest seeds a ledger, runs the real command, and asserts the
# output) — the counts get the same treatment.
#
# Usage: bash bin/sync-test-counts.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

echo "==> running the suite (this is the number we are about to publish)"
line="$(vendor/bin/pest 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -E '^ *Tests:' | tail -1)"

# e.g. "  Tests:    24 skipped, 570 passed (3370 assertions)"
passed="$(printf '%s' "$line"  | sed -n 's/.*[^0-9]\([0-9][0-9]*\) passed.*/\1/p')"
skipped="$(printf '%s' "$line" | sed -n 's/.*[^0-9]\([0-9][0-9]*\) skipped.*/\1/p')"
assertions="$(printf '%s' "$line" | sed -n 's/.*(\([0-9][0-9]*\) assertions).*/\1/p')"

if [ -z "$passed" ] || [ -z "$assertions" ]; then
    echo "could not parse: '$line'" >&2
    exit 1
fi

skipped="${skipped:-0}"
echo "    measured: ${passed} passing, ${assertions} assertions, ${skipped} skipped"

for file in README.md ROADMAP.md; do
    [ -f "$file" ] || continue

    # Any "NNN passing" / "NNN tests, NNN assertions" phrasing, wherever it appears.
    perl -0pi -e "
        s/\*\*\d+ passing\*\*, \d+ assertions/**${passed} passing**, ${assertions} assertions/g;
        s/tests-\d+%20passing/tests-${passed}%20passing/g;
        s/\\\`vendor\\/pest\\\`, \d+ tests, \d+ assertions/\\\`vendor\\/pest\\\`, ${passed} tests, ${assertions} assertions/g;
        s/\d+ tests, \d+ assertions/${passed} tests, ${assertions} assertions/g;
        s/\d+ passing\`, \d+ assertions/${passed} passing\`, ${assertions} assertions/g;
    " "$file"

    # The skip count only appears in the sentence that names the Postgres-gated suites.
    if grep -q "PAIDER_TEST_PG_URL" "$file"; then
        perl -0pi -e "s/\b\d+ skip without \\\`PAIDER_TEST_PG_URL\\\`/${skipped} skip without \\\`PAIDER_TEST_PG_URL\\\`/g" "$file"
        perl -0pi -e "s/\b\d+ skipped \(Postgres/${skipped} skipped (Postgres/g" "$file"
    fi
done

echo "==> updated README.md and ROADMAP.md"
grep -n "passing\*\*" README.md ROADMAP.md | head -4
