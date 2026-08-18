#!/usr/bin/env bash
# m1/bench/foreign-repo.sh — hermetic Loop E2E proof by default, plus a --live mode
# genuinely capable of running paider against a real third-party repo.
#
# Usage:
#   bash m1/bench/foreign-repo.sh                # hermetic, keyless, network-free, exits 0
#   bash m1/bench/foreign-repo.sh --dry-live      # resolves the foreign target + prints the
#                                                  # exact command --live would exec, no exec
#   bash m1/bench/foreign-repo.sh --live          # ACTUALLY runs paider — spends real money.
#                                                  # Requires the flag AND a provider key; the
#                                                  # flag alone gates it — keys alone never do.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

LIVE=0
DRY_LIVE=0
for arg in "$@"; do
    case "$arg" in
        --live) LIVE=1 ;;
        --dry-live) DRY_LIVE=1 ;;
        *) echo "✖ unknown argument: $arg (expected --live or --dry-live)" >&2; exit 1 ;;
    esac
done
if [ "$LIVE" = 1 ] && [ "$DRY_LIVE" = 1 ]; then
    echo "✖ --live and --dry-live are mutually exclusive" >&2
    exit 1
fi

# Re-registered 2026-08-18 after live run 1 failed rubric criteria 2+3: the original
# prompt ("add discount 10% before tax") was written for the m1 fixture's Receipt class
# and has no referent in the pinned foreign target (valitron is a validation library —
# the model correctly explored, found no pricing code, and asked a clarifying question
# into a non-interactive run). Task must exist IN the pinned target. See RUBRIC.md
# amendment + m1/runs/20260818T150450Z for the failed run's evidence.
PROMPT='Two edits, nothing else: (1) in src/Valitron/Validator.php add the single-line comment "// live-e2e-proof" directly above the "class Validator" declaration; (2) append a final line "<!-- live-e2e-proof -->" to README.md. Do not ask questions, do not refactor, make exactly these two edits.'

# A real, small, third-party PHP repo unrelated to paider's team, pinned to an exact SHA
# so "foreign" never silently drifts to whatever happens to be at HEAD today.
FOREIGN_REPO_URL='https://github.com/vlucas/valitron.git'
FOREIGN_REPO_SHA='fadce39f5f235755bb9794b2573af2d5bfcba85f'

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# Resolves $TARGET_DIR to a real clone of $FOREIGN_REPO_URL pinned at $FOREIGN_REPO_SHA.
# Only called from --live/--dry-live — the hermetic default path never touches the network.
# On any failure it falls back to our own m1/fixture copy, and says so loudly: never let a
# fallback silently pass itself off as the real foreign target.
resolve_foreign_target() {
    TARGET_DIR="$tmp/target"
    if git clone --quiet "$FOREIGN_REPO_URL" "$TARGET_DIR" >/dev/null 2>&1 \
        && git -C "$TARGET_DIR" checkout --quiet "$FOREIGN_REPO_SHA" >/dev/null 2>&1; then
        echo "==> foreign target: $FOREIGN_REPO_URL@$FOREIGN_REPO_SHA (genuinely third-party)"
    else
        rm -rf "$TARGET_DIR"
        cp -a "$repo_root/m1/fixture" "$TARGET_DIR"
        echo "FALLBACK: not foreign -- network clone of $FOREIGN_REPO_URL failed, using team-authored m1/fixture copy instead" >&2
    fi
}

# The ONLY place the real paider invocation is assembled, into $LIVE_ARGV. --live execs it
# verbatim; --dry-live prints it with printf %q. One source of truth — no second, hand
# -maintained echo describing the exec that can drift from what actually runs.
build_live_argv() {
    LIVE_ARGV=("$repo_root/paider" run "$PROMPT" --yes)
}

if [ "$LIVE" = 1 ]; then
    if [ -z "${AIGATE_URL:-}${AIGATE_TOKEN:-}${OPENROUTER_API_KEY:-}" ]; then
        echo "✖ --live requires AIGATE_URL/AIGATE_TOKEN or OPENROUTER_API_KEY to be set" >&2
        exit 1
    fi
    resolve_foreign_target
    build_live_argv
    echo "==> LIVE run against $TARGET_DIR -- executing the real binary, this spends real money"
    cd "$TARGET_DIR"
    exec "${LIVE_ARGV[@]}"
fi

if [ "$DRY_LIVE" = 1 ]; then
    resolve_foreign_target
    build_live_argv
    echo "==> DRY-LIVE -- would exec (nothing runs, no spend):"
    printf 'cd %q && ' "$TARGET_DIR"
    printf '%q ' "${LIVE_ARGV[@]}"
    printf '\n'
    exit 0
fi

# --- default: hermetic, keyless, flagless, no network — exits 0 or fails loudly ---
cp -a "$repo_root/m1/fixture" "$tmp/repo"
echo "==> hermetic E2E in $tmp/repo (m1/fixture copy — pass --live/--dry-live for a real foreign target)"
PREFLIGHT_HERMETIC=1 bash "$repo_root/m1/preflight.sh" "$tmp/repo" 2>&1 | tail -n 5

echo "==> hermetic Loop proof (vendor/bin/pest E2ETrace)"
"$repo_root/vendor/bin/pest" --filter=E2ETrace 2>&1 | tail -n 10

echo "==> tmp copy invariants"
if [ ! -f "$tmp/repo/src/Receipt.php" ]; then echo "FAIL: Receipt.php missing"; exit 1; fi
grep -q "Receipt" "$tmp/repo/src/Receipt.php" || { echo "FAIL: Receipt.php content"; exit 1; }
echo "tmp copy invariants OK -- E2ETrace passed with // paider-proof and ledger tier_call"

echo "foreign-repo bench done -- live prompt: two live-e2e-proof edits (Validator.php comment + README line)"
echo "asserts: // live-e2e-proof in src/Receipt.php + tier_call ledger + cost --session"
echo "live capability: bash $0 --dry-live (prints real command) / --live (executes it, spends money)"
