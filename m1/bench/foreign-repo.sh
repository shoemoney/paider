#!/usr/bin/env bash
# m1/bench/foreign-repo.sh — live E2E in foreign repo copy via real meta provider
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cp -a "$repo_root/m1/fixture" "$tmp/repo"
echo "==> foreign-repo E2E in $tmp/repo"
bash "$repo_root/m1/preflight.sh" "$tmp/repo" 2>&1 | tail -n 5
# Live Loop edit via meta provider (requires AIGATE_URL/TOKEN)
AIGATE_URL=${AIGATE_URL:-https://aigate.shoemoney.ai} AIGATE_TOKEN=${AIGATE_TOKEN:-5fbf4da98ae1b8ee017d4da51e253a7cb75241cd1b8e3de0} \
php "$repo_root/artisan" 2>&1 | head -n 5 || true
echo "foreign-repo bench scaffold — run: cd $tmp/repo && AIGATE_URL=... paider chat"
echo "prompt: add discount 10% to Receipt::build before tax"
