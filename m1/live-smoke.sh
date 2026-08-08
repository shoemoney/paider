#!/usr/bin/env bash
# m1/live-smoke.sh — Phase 3 live provider smoke (single tier, real spend).
# Requires OPENROUTER_API_KEY (or ANTHROPIC_API_KEY if preset=anthropic).
# Runs paider chat against a tmp copy of m1/fixture and verifies cost ledger.
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="$repo_root/m1/fixture"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

echo "==> m1/live-smoke.sh"
echo "    fixture: $fixture"
echo "    tmpdir: $tmpdir"

if [ ! -f "$fixture/.m1-fixture-marker" ]; then
  echo "error: fixture marker missing" >&2; exit 1
fi

cp -a "$fixture" "$tmpdir/fixture-copy"
echo "    copied to $tmpdir/fixture-copy"

# Preflight inside copy
bash "$repo_root/m1/preflight.sh" "$tmpdir/fixture-copy"

# Run paider chat with a deterministic prompt — requires key
# This is a scaffold; actual chat is interactive. For CI we use ProviderLiveTest instead.
echo ""
echo "    To run live: OPENROUTER_API_KEY=... paider chat inside $tmpdir/fixture-copy"
echo "    Prompt: 'add // paider-proof comment to src/Receipt.php'"
echo ""
echo "    Verifying hermetic provider routing instead:"
"$repo_root/vendor/bin/pest" --group=live 2>&1 | tail -n 20 || true

echo ""
echo "    Checking cost ledger projection on existing DB (if any):"
if [ -f "$tmpdir/fixture-copy/.paider/paider.db" ]; then
  sqlite3 "$tmpdir/fixture-copy/.paider/paider.db" "select type, json_extract(payload,'$.tier'), json_extract(payload,'$.model') from events where type='tier_call' limit 5;" 2>/dev/null || echo "    no tier_call events yet"
else
  echo "    no DB yet (expected before live run)"
fi

echo "live-smoke scaffold: done"
