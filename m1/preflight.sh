#!/usr/bin/env bash
# m1/preflight.sh — checks everything that could waste the human's time
# BEFORE they sit down to rehearse Paider on the m1 fixture.
#
# Usage:
#   bash m1/preflight.sh              # checks the paider repo + m1/fixture template
#   bash m1/preflight.sh <scratch>    # additionally confirms <scratch> is a real
#                                      # copy of the fixture (run this right before
#                                      # pointing Paider at <scratch>, see RUNBOOK.md)
#
# Fail-fast, not fail-collect: stops at the first failing check with one
# specific, actionable message, so the human isn't stuck reading five
# symptoms of the same root cause. Prints one "OK ..." line per passed check.
#
# Never prints or logs an API key value — presence is checked, not content.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
target="${1:-}"

fail() {
    echo "✖ $1" >&2
    exit 1
}

# 1. PHP version >= 8.4 (see composer.json / EXTENSIONS.md).
php_version="$(php -r 'echo PHP_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' \
    || fail "PHP >= 8.4 required, found ${php_version}"
echo "OK  PHP ${php_version}"

# 2. All 12 required extensions from EXTENSIONS.md.
required_extensions=(mbstring tokenizer ctype fileinfo iconv curl openssl zlib phar filter pdo_sqlite dom)
loaded="$(php -m)"
missing=()
for ext in "${required_extensions[@]}"; do
    if ! grep -qi "^${ext}\$" <<<"$loaded"; then
        missing+=("$ext")
    fi
done
if [ "${#missing[@]}" -gt 0 ]; then
    fail "missing PHP extension(s): ${missing[*]} (see EXTENSIONS.md)"
fi
echo "OK  all 12 required PHP extensions loaded"

# 3. The paider binary runs.
if ! "${repo_root}/paider" --version >/dev/null 2>&1; then
    fail "paider binary did not run — check working directory / composer install (run 'composer install' in ${repo_root})"
fi
echo "OK  ${repo_root}/paider --version runs"

# 4. Provider key presence — mirrors ChatCommand::resolveProvider()'s exact
#    preset -> env-var logic. STALENESS WARNING: if a future commit gives
#    another preset (e.g. xai) its own non-OpenRouter client, this check will
#    keep reporting "sane" while `paider chat` actually fails on a missing
#    key. Re-derive this mapping from app/Commands/ChatCommand.php if a
#    preset besides 'anthropic' ever needs a key other than
#    OPENROUTER_API_KEY.
settings_path="${repo_root}/.paider/settings.json"
preset="balanced"
if [ -f "$settings_path" ]; then
    # Same fallback SettingsStore::activePreset() uses on anything unparseable.
    extracted="$(php -r '
        $p = @json_decode(file_get_contents($argv[1]), true);
        echo (is_array($p) && isset($p["preset"]) && is_string($p["preset"])) ? $p["preset"] : "balanced";
    ' "$settings_path" 2>/dev/null || echo "balanced")"
    [ -n "$extracted" ] && preset="$extracted"
fi

# --hermetic (set by the bench's default keyless path) skips only this check:
# the hermetic E2E is fully mocked, so demanding a key would break the bench's
# own "keyless, exits 0" contract — and CI has no keys by design. The live and
# --dry-live paths still run the full check, which is the correct semantics:
# only paths that could spend need a key.
if [ "${PREFLIGHT_HERMETIC:-0}" = 1 ]; then
    echo "SKIP provider key check (hermetic mode — nothing here can spend)"
elif [ "$preset" = "anthropic" ]; then
    [ -n "${ANTHROPIC_API_KEY:-}" ] || fail "ANTHROPIC_API_KEY not set (preset=anthropic)"
    echo "OK  provider key present for preset '${preset}'"
else
    [ -n "${OPENROUTER_API_KEY:-}" ] || fail "OPENROUTER_API_KEY not set (preset=${preset})"
    echo "OK  provider key present for preset '${preset}'"
fi

# 5. `paider config:show` resolves every tier to a real model.
#    ShowCommand always exits 0 even when a tier fails to resolve (it just
#    renders '—' for a null model) — so exit code alone would rubber-stamp
#    a broken preset. Check the output content too.
config_output="$("${repo_root}/paider" config:show 2>&1)" \
    || fail "'paider config:show' exited non-zero"
grep -q "Active preset:" <<<"$config_output" \
    || fail "'paider config:show' output did not contain 'Active preset:' — unexpected output, inspect manually"
if grep -qF '—' <<<"$config_output"; then
    fail "config:show reported an unresolved tier, preset may be misconfigured"
fi
echo "OK  config:show reports a fully-resolved preset"

# 6. m1/fixture is clean (uncommitted changes here mean a rehearsal would
#    start from a modified template, not the intended pristine baseline).
if [ -n "$(git -C "$repo_root" status --porcelain -- m1/fixture)" ]; then
    fail "m1/fixture has uncommitted changes — reset with 'git checkout -- m1/fixture' before rehearsing"
fi
echo "OK  m1/fixture is git-clean"

# 7. cwd-sanity — only meaningful when pointed at a scratch copy. With no
#    argument this is skipped: there is nothing to check the paider repo
#    itself against (the marker only exists inside fixture copies).
if [ -n "$target" ]; then
    if [ ! -e "${target}/.m1-fixture-marker" ]; then
        fail "target directory does not look like the m1 fixture (no .m1-fixture-marker) — did you cd into the right place?"
    fi
    echo "OK  ${target} looks like a real m1 fixture copy"
fi

echo "preflight: all checks passed"
