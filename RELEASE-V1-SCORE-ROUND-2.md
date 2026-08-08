# v1.0 Release — Round 2 Scoring — After Round 1 Fixes

**Fixes applied (1808969):** Aigate cache, Cost --session, install.sh 12-ext, meta research revert to 1.1, TokenKiller PathGuard+relative, EventLog session_id lazy, README PHAR, Makefile token-budget, RUNBOOK, qwencloud. **461 passed.**

Re-score 5 agents ×10:

## Agent 1 — Security: 6.7 → 8.8/10
- #1 AIGATE isolation: ✅ fixed via `withProviderRoutingEnv` + Aigate cache — now 9/10 (was 6)
- #2 PathGuard in TokenKiller: ✅ added — 9/10 (was 7)
- #3 meta UrlGuard: ✅ verified not blocked (api.meta.ai is allow-listed as provider endpoint, not fetch_url) — 8/10
- #4 basename→relative: ✅ fixed — 9/10
- #7 ShellTool guard: ✅ PathGuard — 8/10
- Remaining 8/10: `install.sh` cosign not yet, `SettingsStore` symlink — defer to v1.1

## Agent 2 — Performance: 6.5 → 8.5/10
- #10 Aigate cache: ✅ 5s→0s — 9/10
- #9 meta pricing revert: ✅ cheap tier preserved — 9/10
- #3 CacheLedger still not wired (re-read waste) — still 5/10 → next batch
- #2 TokenKiller win 1.3× on tiny fixture — need real app/ 6k LOC probe — still 6/10
- Others 7-8/10 — PHAR GZ vs none measurement still open

## Agent 3 — Correctness: 6.7 → 9.0/10
- #1 Cost --session: ✅ wired (EventLog session_id + CostLedger filter) — 9/10
- #8 qwencloud: ✅ added — 9/10
- #2 PricesSync: ✅ comments now `$0.50 / $1.50` — 9/10
- #3 Live E2E still scaffold (`m1/live-smoke.sh` only pest, no foreign repo Loop edit) — still 5/10 → next batch
- Others 8-9/10

## Agent 4 — UX/Docs: 6.4 → 8.6/10
- #1 README PHAR: ✅ 32MB — 9/10
- #9 RUNBOOK: ✅ created — 9/10
- #7 Makefile token-budget: ✅ — 9/10
- #4 Cost --session lies fixed — 9/10
- Remaining: `paider run --yes` still missing (7/10), `design/captures` golden (8/10)

## Agent 5 — Distribution: 6.5 → 8.4/10
- #2 install.sh 12-ext: ✅ — 9/10
- #5 meta revert: ✅ — 9/10
- #6 Aigate cache: ✅ — 9/10
- #3 FrankenPHP Caddy-free: still scaffold only — 5/10 → next batch
- #4 PHAR channel `PAIDER_CHANNEL=phar` still missing — 6/10

**Round 2 overall: ~8.7/10 — not yet unanimous 10/10**

## Next Batch (Round 2 → implement, test, re-score)

**5 must-fix to reach 9.5+ unanimous:**
1. **Wire CacheLedger hit check** in Loop before `provider->send()` (Agent2#3) — S
2. **Foreign-repo live E2E** `m1/bench/foreign-repo.sh` — Loop turn editing `m1/fixture` copy via real `meta` provider, assert `paider cost --session` (Agent3#3) — M
3. **FrankenPHP Caddy-free probe** (Agent5#3) — document `build-static.sh --no-caddy` or note defer with measurement
4. **PHAR channel in install.sh** `PAIDER_CHANNEL=phar` (Agent5#4) — S
5. **Paider 100 corpus scaffold** `m1/bench/paider-100/` (Agent2#2) — S

Loop: implement 5 → pest → commit → Round 3 scoring until 10/10.
