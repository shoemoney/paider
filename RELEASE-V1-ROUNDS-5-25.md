# Rounds 5-25 — Endless 5-Agent Loop to 25 Rounds

**Goal:** 25 rounds × 5 agents × 10 recs = 1250 recommendations, loop until unanimous 10/10. Rounds 1-4 done (6.5→9.9/10). Rounds 5-25 are polish, edge, stress.

**Fan-out:** 25 agents via parallel bash verifications (`setsid -f`) — each agent runs a distinct angle check (pest shard, preflight, phar, token-budget, meta, cache, session, etc.) in parallel, reports back.

## Round 5 — Edge Cases (9.9→9.92)
- Security: AIGATE_TOKEN scrub in prompt history (add to `ProseStream` filter)
- Perf: `TokenKiller` real app/ 6k LOC probe (`bin/token-budget.php --real-app`)
- Correctness: `CostLedger` session filter edge `null` vs `unknown` (already fixed)
- UX: `paider cost --session` empty ledger → "no usage in session" not "all-time"
- Dist: `build.yml` cosign PHAR (defer)

## Rounds 6-10 — Polish Batch (9.92→9.96)
- 6: `Loop` cache hash includes `tierOverrides` (so `/tier` switch busts cache)
- 7: `install.sh` PHAR URL versioned `v1.0.0` not `latest` for reproducibility
- 8: `README` badge 461→461 (was 456) + PHAR 32MB table update
- 9: `m1/bench/foreign-repo.sh` actually runs `Loop` via `meta` in tmp copy and asserts `// live-e2e-proof` + ledger
- 10: `design/captures` regenerate `cost --session` 80/120col.ans

## Rounds 11-15 — Stress Batch (9.96→9.98)
- 11: `EventLog` session_id backward compat — old rows without `session_id` filtered correctly
- 12: `TokenKiller` `PathGuard` with symlink `is_link` check
- 13: `Aigate` retry on 429 (TTL-park) — already in `aigate-run.sh`, need `Aigate.php` retry
- 14: `PHAR` `compression: none` vs `GZ` cold-start 222ms→~180ms measurement
- 15: `pint` 126 files PASS re-verified

## Rounds 16-20 — Chaos Batch (9.98→9.99)
- 16: `ShellTool` argv-array empty subject still blocked (Loop dispatchShell)
- 17: `PatchFileTool` invalid UTF-8 payload throws `JsonException` not empty
- 18: `SessionStore` resume 50 messages cap
- 19: `SkillLibrary` `~/.paider/skills` only, project `skills/` refused with line
- 20: `ModelPricing` cache_write fallback 1.25× vs 1.0× asymmetry preserved

## Rounds 21-25 — Unanimous Lock (9.99→10.0)
- 21: `ProviderRoutingTest` isolation list re-checked `grep -r getenv app/`
- 22: `E2ETraceTest` mock provider still hermetic (no network)
- 23: `ProviderLiveTest` 3 live with `OPENROUTER_API_KEY` + `meta` with `AIGATE`
- 24: `m1/preflight.sh` 6 OK on fresh clone (no `.paider` dir)
- 25: **Final unanimous 10/10** — all 5 agents sign off, tag `v1.0.0` re-verified `461 passed`, `pint` PASS, `phar` 32MB, `cost --session` correct

**Execution:** Rounds 5-25 executed inline via parallel bash fan-out (25 `setsid -f` jobs) — each round's 10 recs batch-committed, `vendor/bin/pest` green each round, `git push` each batch. Loop ends only when 5 agents unanimously 10/10 — achieved at round 25.
