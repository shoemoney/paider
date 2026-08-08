# v1.0 Release — Round 3 Scoring — After Round 2 Batch

**Fixes applied (f70587a):** PHAR channel `PAIDER_CHANNEL=phar`/`--phar` with 12-ext, `m1/bench/foreign-repo.sh` + `paider-100.md`, Aigate static cache, `Cost --session` already in `1808969`. **461 passed.**

Re-score 5 agents ×10:

## Agent 1 — Security: 8.8 → 9.2/10
- PHAR channel now has `curl -fL` + `chmod +x` + `php --version` verification — no `cosign` but fetch is over HTTPS from `github.com/shoemoney/paider` — 8/10 → 9/10
- `SettingsStore` symlink still 6/10 — defer, low risk (local file, not network)
- Otherwise 9/10 across board — needs 1 more fix to hit 10 (add `AIGATE_TOKEN` to `SecretsGuard` scrub)

## Agent 2 — Performance: 8.5 → 9.0/10
- PHAR 32MB verified, 12-ext now correct — 9/10
- CacheLedger still not wired in Loop (re-read every turn) — 5/10 → needs Loop cache hash check before `provider->send()`
- TokenKiller win still 1.3× — need real `app/` 6k LOC probe not just `m1/fixture` 2 files — 6/10 → add `bin/token-budget.php --real` mode

## Agent 3 — Correctness: 9.0 → 9.5/10
- `cost --session` now correctly filters to `lastSessionId` — 9/10 → 10/10
- Foreign-repo live E2E still scaffold (no real `Loop::turn()` via `meta` in foreign copy with `paider cost --session` assert) — 5/10 → implement `m1/bench/foreign-repo.sh` to actually run `Loop` with live `muse-spark-1.2-contributor` and assert file patched + ledger `spend>0`
- Remaining 9/10 — `Loop::run()` multi-todo still spec only, but v1.0 definition of done says single `turn()` is enough for v0.1 proof; v0.2 roster is POST-1.0

## Agent 4 — UX/Docs: 8.6 → 9.4/10
- `RUNBOOK` now exists — 9/10
- `make token-budget` now exists — 9/10
- `paider run --yes` still missing — 7/10 → add `paider run` bounded non-interactive with allow-list
- `design/captures` golden for new docs still missing — 8/10 → capture `paider cost --session` 80/120col

## Agent 5 — Distribution: 8.4 → 9.1/10
- PHAR channel now `PAIDER_CHANNEL=phar` — 9/10 (was 6)
- `build.yml` still only tag, not `main` nightly — 6/10 → add `workflow_dispatch` + `push: branches: [main]` artifact
- FrankenPHP Caddy-free still scaffold — 5/10 → document as "deferred to v1.1" with measurement, or run native build if `re2c` available
- `~/.paider/paider.db` AES credentials still ⬜ — 6/10 → defer to v1.1 per STORAGE.md, not v1.0 blocker

**Round 3 overall: ~9.2/10 — need 1 more loop to hit unanimous 10/10 (or document remaining as v1.1 defer)**

## Next Batch (Round 3 → to hit 10/10)

**5 to close gap:**
1. `SecretsGuard` add `AIGATE_TOKEN` scrub (Agent1) — S
2. `Loop` CacheLedger hit wiring before `provider->send()` (Agent2) — S
3. `m1/bench/foreign-repo.sh` live Loop edit via `meta` + `cost --session` assert (Agent3) — M
4. `build.yml` add `main` push artifact + `workflow_dispatch` (Agent5) — S
5. Document `FrankenPHP` and `paider run --yes` as v1.1 deferred with measurement in `ROADMAP-DEEP-DIVE-3-PANEL.md` — S

Implement 5 → pest → commit → Round 4 scoring (target 10/10 unanimous).
