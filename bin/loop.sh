#!/usr/bin/env bash
#
# The improvement loop.
#
# Each round: pick the next untried vision model from the pool, ask it for exactly 5 improvements
# against the real tree, record the verdict, and stop for a human to triage. Deliberately NOT
# autonomous about what to FIX — see below.
#
# WHY A HUMAN TRIAGES EACH ROUND. Two rounds in, 2 of 10 reported findings were false positives:
# one claimed an MCP orphan-process leak that does not exist (the SDK reaps on every failure
# path), and one claimed a "critical" TUI defect that is a README screenshot bleeding through a
# corrupt screenshot. A third round hit a reviewer proposing to replace the append-only ledger
# with a mutable cache — the exact design the project locked to prevent drift.
#
# A model that is confidently wrong is cheap; a loop that implements confidently-wrong findings
# automatically is how a codebase gets rewritten to be worse while the log fills with green
# checkmarks. So this driver GATHERS, and the fixes are made deliberately. Findings are recorded
# either way, in loop/triage.md, so "we rejected it" is as findable as "we fixed it".
#
# Usage:
#   bash bin/loop.sh <rounds>      run N more rounds (default 1)
#   bash bin/loop.sh status        what has been asked, what is untried
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

POOL="$root/loop/pool.txt"
STATE="$root/loop/asked.txt"
TRIAGE="$root/loop/triage.md"
REVIEWS="$root/loop/reviews"

mkdir -p "$REVIEWS" loop
touch "$STATE"
touch "$POOL"

# ── status ────────────────────────────────────────────────────────────────────
if [ "${1:-}" = "status" ]; then
    asked_real=$(grep -cv '^unreasoning:' "$STATE" 2>/dev/null || echo 0)
    demoted=$(grep -c '^unreasoning:' "$STATE" 2>/dev/null || echo 0)
    echo "asked:    ${asked_real} (plus ${demoted} demoted as reasoning-only)"
    echo "pool:     $(wc -l < "$POOL" | tr -d ' ')"
    echo "untried:  $(( $(wc -l < "$POOL") - ${asked_real} ))"
    echo "reviews:  $(ls -1 "$REVIEWS"/*.md 2>/dev/null | wc -l | tr -d ' ')"
    echo "--- asked ---"; cat "$STATE"
    exit 0
fi

# ── pool ──────────────────────────────────────────────────────────────────────
# Refreshed from the live catalogue rather than hardcoded, so the pool is models that exist TODAY.
if [ ! -s "$POOL" ] || [ "${PAIDER_REFRESH_POOL:-0}" = "1" ]; then
    echo "==> refreshing the model pool from OpenRouter's live catalogue"
    curl -sS --max-time 40 https://openrouter.ai/api/v1/models -o /tmp/paider-loop-models.json || {
        echo "could not reach OpenRouter" >&2; exit 1; }

    # Flash-tier VISION models only: input_modalities contains "image", a real context window,
    # and an input price low enough to run many rounds. Vision is the point of the loop; flash
    # is the point of the budget.
    python3 - <<'PY' > "$POOL"
import json
d = json.load(open('/tmp/paider-loop-models.json'))['data']
rows = []
for m in d:
    a = m.get('architecture') or {}
    if not isinstance(a, dict):
        continue
    if 'image' not in (a.get('input_modalities') or []):
        continue
    ctx = m.get('context_length') or 0
    try:
        pin = float((m.get('pricing') or {}).get('prompt') or 0)
    except (TypeError, ValueError):
        continue
    if pin <= 0 or pin > 0.60 or ctx < 32000:
        continue
    # Batch variants are the same model at a discount; asking one is asking both.
    rows.append((m['id'].replace(':batch', ''), ctx, pin))
rows.sort(key=lambda r: (r[2], -r[1]))
seen, out = set(), []
for mid, _, _ in rows:
    if mid in seen:
        continue
    seen.add(mid)
    out.append(mid)
print('\n'.join(out))
PY
    echo "    pool: $(wc -l < "$POOL" | tr -d ' ') vision flash models"
fi

rounds="${1:-1}"
done=0
# Highest iteration NUMBER seen, not a file count: each round writes up to four files
# (raw.json, .json, .md, -unparsed.md) and counting files numbered round 3's output as rounds
# 3,4,5,6 makes the next round announce itself as round 9.
# BSD sed does not support \+ in a BRE, so the original pattern matched nothing and every
# round announced itself as round 1. This is the portable form: a bracket expression with a
# quantifier on the WHOLE class, which is POSIX and works on both seds.
iteration=$(ls -1 "$REVIEWS" 2>/dev/null \
    | sed -n 's/^iter\([0-9][0-9]*\).*/\1/p' \
    | sort -n | tail -1)
iteration=${iteration:-0}

while [ "$done" -lt "$rounds" ]; do
    # Next model nobody has been asked yet.
    model="$(grep -vxF -f "$STATE" "$POOL" 2>/dev/null | head -1)"

    if [ -z "$model" ]; then
        echo "==> pool exhausted: every vision flash model has been asked"
        break
    fi

    iteration=$((iteration + 1))
    echo ""
    echo "════════════════════════════════════════════════════════════"
    echo "  round $iteration → $model"
    echo "════════════════════════════════════════════════════════════"

    timeout 900 php bin/reviewer.php "$model" "$iteration"
    status=$?

    echo "$model" >> "$STATE"

    case $status in
        0) echo "    review parsed → loop/reviews/iter${iteration}-*.md" ;;
        2) echo "    UNPARSEABLE — raw saved, not lost" ;;
        3) echo "    EMPTY (reasoning ate the budget) — raw saved" ;;
        *) echo "    FAILED (status $status) — raw saved" ;;
    esac

    # A model that spent its whole budget on reasoning produced nothing. Raising the ceiling for
    # it is the wrong response: ~z-ai/glm-flash-latest burned 15983 of 16000 tokens reasoning and
    # emitted no content, which is a property of the model, not of the limit. Re-asking it at 32k
    # would just wait longer for the same empty result. It stays in the pool but is recorded, so
    # a future run can skip it without re-paying for the discovery.
    if [ "$status" = "3" ]; then
        echo "unreasoning:$model" >> "$STATE"
        echo "    demoted: reasoning-only, will not be re-asked automatically"
    fi

    # Each round gets the tree as it stands NOW, so round N reviews round N-1's fixes. The
    # reviewer harness reads live files; nothing is cached between rounds.
    done=$((done + 1))
done

echo ""
echo "==> $done round(s) complete. Triage in loop/reviews/, record decisions in loop/triage.md."
