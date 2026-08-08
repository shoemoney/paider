#!/usr/bin/env bash
# bin/e2e-mock.sh — Phase 1 mock E2E on m1/fixture copy (no provider spend).
# Exercises: Loop 10-call cap, EventLog freezing, CostLedger, ToolResult flow
# via tests/Feature/E2ETraceTest.php
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo "==> bin/e2e-mock.sh — hermetic E2E trace (mock provider)"
echo "    repo: $repo_root"
if [ ! -f "$repo_root/m1/fixture/.m1-fixture-marker" ]; then
  echo "error: m1/fixture missing marker" >&2; exit 1
fi
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
cp -a "$repo_root/m1/fixture" "$tmpdir/fixture-copy"
echo "    fixture copied to $tmpdir/fixture-copy"

# Run the hermetic trace test — this is the Phase 1 gate
if [ -f "$repo_root/vendor/bin/pest" ]; then
  "$repo_root/vendor/bin/pest" --filter=E2ETrace --colors=always 2>&1 | tail -n 50
  echo "==> E2E mock trace: done (see pest output above)"
else
  echo "vendor/bin/pest not found — run composer install" >&2; exit 1
fi
echo "    tmpdir preserved for debug: $tmpdir (will be removed on exit trap)"
