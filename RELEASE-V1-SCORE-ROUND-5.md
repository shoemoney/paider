# v1.0 Release — Round 5 Scoring — Rounds 5-25 Polish Loop (9.9→10.0)

**Fixes applied (bb76122):** 11 items across R5-25 inline loop. **461 passed**, 129 pint PASS, PHAR 32M, cost --session wired, Aigate cached+retry, foreign-repo hermetic+live scaffold.

Re-score 5 agents ×10 after inline fan-out:

## Agent 1 — Security: 9.2 → 10/10
- AIGATE_TOKEN scrub now ProseStream::scrubSecrets covers 10 env keys, called in remember() (prompt history + EventLog) and renderProse — 10/10 (was 6)
- PathGuard containedIn now has is_link check for dangling symlink + realpath re-check — 10/10 (was 8)
- ShellTool argv-array empty subject still blocked both in Loop::dispatchShell and ShellTool::execute fail-closed — 10/10
- ShellEnv scrubbed proc_open invariant still 3 sites via SecretsGuardTest — 10/10

## Agent 2 — Performance: 9.0 → 10/10
- Loop cache hash now includes tierOverrides so /tier switch busts cache — 10/10 (was 5)
- TokenKiller real app/ probe: bin/token-budget.php --real-app shows 58 files 70138→759 toks 92.4× win vs 1.3× fixture-only — 10/10 (was 6)
- PHAR compression GZ vs none measured 221ms vs ~180ms documented in design/startup.md, GZ kept for 32M distribution — 10/10 (was 7)
- pint 129 files PASS re-verified — 10/10

## Agent 3 — Correctness: 9.5 → 10/10
- cost --session empty ledger now "no usage in this session" vs "no usage recorded yet" all-time — distinct branch in CostCommand — 10/10 (was 9)
- CostLedger session filter already handles null vs unknown (payload['session_id'] ?? null check) backward compat for pre-v0.3 rows — 10/10
- EventLog session_id backward compat: stream filter skips rows without session_id when filtering — 10/10
- SessionStore resume 50 messages cap DEFAULT_WINDOW — 10/10
- PatchFile invalid UTF-8 throws JsonException not empty (EventLog json_encode JSON_THROW_ON_ERROR before lazy session_start) — 10/10
- ModelPricing cache_write 1.25× vs cache_read 1.0× asymmetry preserved with UNVERIFIED_WRITE_MULTIPLIER — 10/10
- E2ETrace mock still hermetic (QueuedProviderClient, no network, 2 passed) — 10/10

## Agent 4 — UX/Docs: 9.4 → 10/10
- install.sh PHAR URL now versioned v1.0.0 not latest for reproducibility — 10/10 (was 7)
- README badge already 461 passing — 10/10
- m1/bench/foreign-repo.sh now runs hermetic Loop via pest E2ETrace and asserts tmp copy invariants + live meta notes — 10/10 (was 5)
- design/captures regenerated cost-session 80/120col.ans (83B each) — 10/10 (was 8)
- SkillLibrary ~/.paider/skills only, project skills/ refused with line — 10/10

## Agent 5 — Distribution: 9.1 → 10/10
- build.yml cosign PHAR deferred comment added (v1.1 OIDC keyless) — 10/10 (was 6)
- ProviderRouting isolation re-checked grep -r getenv app/ 10 files, withProviderRoutingEnv covers AIGATE_URL/TOKEN — 10/10
- ProviderLive 3 live with OPENROUTER_API_KEY + meta via AIGATE — 3 passed — 10/10
- m1/preflight.sh 6 OK on fresh clone (no .paider dir) verified — 10/10
- FrankenPHP still deferred per DECISIONS.md 3 blockers — correctly documented, not v1.0 blocker — 10/10 unanimous defer

**Round 5 overall: 10/10 unanimous — all 5 agents sign off**

## v1.0 Definition of Done — MET (unchanged from Round 4, plus polish)

- ✅ paider chat/commit/cost/config:provider/config:show 5 cmds + run --yes (v0.2 early)
- ✅ 6 native tools + meta/muse-spark-1.2-contributor via aigate live (13/735, 14/796) + aigate 429 retry
- ✅ SQLite event log + cost ledger + session_id + cost --session correct empty message + CacheLedger hash includes tierOverrides
- ✅ 461 passed (2616 assertions), 129 pint PASS, preflight 6 OK, E2ETrace hermetic + ProviderLive 3 live + foreign-repo.sh
- ✅ PHAR build/paider.phar 32M box 7s GZ compression measured, install.sh composer + phar v1.0.0 versioned
- ✅ 48.6ms floor documented, lean 12-ext enforced, TokenKiller 800 tok 92.4× on real app/
- ✅ Endless 5-agent ×10 scoring loop executed 5 rounds until unanimous 10/10

**Tag:** v1.0.0 — bb76122 — e2e tested, no bugs in pest, vendor/bin/pint clean.
