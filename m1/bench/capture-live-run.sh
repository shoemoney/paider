#!/usr/bin/env bash
# m1/bench/capture-live-run.sh — wraps foreign-repo.sh and captures the four
# artifacts RUBRIC.md grades a live run against, into m1/runs/<UTC-timestamp>/:
# transcript.log, exit-code.txt, target.diff, cost-session.txt, changed-files/.
#
# Usage:
#   bash m1/bench/capture-live-run.sh --dry   # exercises the ENTIRE capture path
#                                              # (dir creation, teeing, artifact
#                                              # collection) against foreign-repo.sh
#                                              # --dry-live -- keyless, no spend.
#   bash m1/bench/capture-live-run.sh --live  # the real thing. Spends real money.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

mode=""
for arg in "$@"; do
    case "$arg" in
        --live) mode="--live" ;;
        --dry) mode="--dry-live" ;;
        *) echo "✖ unknown argument: $arg (expected --live or --dry)" >&2; exit 1 ;;
    esac
done
[ -n "$mode" ] || { echo "✖ usage: $0 --live|--dry" >&2; exit 1; }

run_dir="$repo_root/m1/runs/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$run_dir"
changed_dir="$run_dir/changed-files"
mkdir -p "$changed_dir"

transcript="$run_dir/transcript.log"
set +e
bash "$repo_root/m1/bench/foreign-repo.sh" "$mode" 2>&1 | tee "$transcript"
bench_status="${PIPESTATUS[0]}"
set -e
echo "$bench_status" > "$run_dir/exit-code.txt"
echo "==> capture-live-run: foreign-repo.sh $mode exited $bench_status, artifacts in $run_dir"

# The only source of truth for which directory foreign-repo.sh actually operated on is
# its own transcript output (see foreign-repo.sh's build_live_argv) -- one source, no
# second hand-maintained path that could drift. --live prints "LIVE run against <dir>";
# --dry-live prints "cd <dir> && ...". --live also deliberately never cleans up that
# directory (exec replaces the process before its EXIT trap can fire), so it's still on
# disk here to inspect. --dry-live's directory IS cleaned up on its own normal exit, so
# there is nothing left to diff -- that's expected, not a bug in this script.
target_dir=""
if [ "$mode" = "--live" ]; then
    target_dir="$(sed -nE 's/^==> LIVE run against (.*) -- executing.*/\1/p' "$transcript" | head -n1)"
else
    target_dir="$(sed -nE 's/^cd ([^ ]*) && .*/\1/p' "$transcript" | head -n1)"
fi

diff_file="$run_dir/target.diff"
cost_file="$run_dir/cost-session.txt"

if [ -n "$target_dir" ] && [ -d "$target_dir" ]; then
    git -C "$target_dir" diff > "$diff_file" || true
    (cd "$target_dir" && "$repo_root/paider" cost --session) > "$cost_file" 2>&1 || true
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        mkdir -p "$changed_dir/$(dirname "$f")"
        cp "$target_dir/$f" "$changed_dir/$f" 2>/dev/null || true
    done < <(git -C "$target_dir" diff --name-only 2>/dev/null || true)
    echo "==> captured target.diff, cost-session.txt, changed-files/ from $target_dir"
else
    reason="no target directory to inspect (mode=$mode; --dry-live's tmp target is removed on its own exit -- this is expected, see RUBRIC.md)"
    echo "$reason" > "$diff_file"
    echo "$reason" > "$cost_file"
    echo "==> $reason"
fi

exit "$bench_status"
