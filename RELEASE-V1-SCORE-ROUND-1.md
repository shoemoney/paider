# v1.0 Release — Round 1 Scoring — 5 Agents × 10 Recs

**Goal:** 100% roadmap + README features completed, e2e tested, no bugs. Loop until 5 agents unanimously 10/10.

**Basis:** `README.md`, `PLAN.md` v0.1/v0.2/v1.0/v0.3, `STORAGE.md`, `DECISIONS.md`, `config/*`, `app/Agent/Loop.php`, `build/paider.phar` 32MB.

---

## Agent 1 — Security / Guards (hard trust boundaries)

| # | Score | Recommendation | File |
|---|-------|----------------|------|
|1| 6/10 | `PAIDER_YOLO` + `PAIDER_FETCH_ALLOW` already real-env-only via `ProjectEnv::fromEnvironment()` — but `Aigate` adds new env vars `AIGATE_URL/TOKEN` that bypass `ProjectEnv` isolation; add them to `ProjectSelfAuthorizationTest` source-grep invariant | `app/Providers/Aigate.php`, `ProjectEnv.php` |
|2| 7/10 | `PathGuard::containedIn()` blocks `../` but `TokenKiller::prune()` takes absolute `glob` paths without `PathGuard` check — add guard there | `App\Support\TokenKiller` |
|3| 5/10 | `FetchUrlTool` + `UrlGuard` private-addr block is tested, but `OpenAiCompatibleClient` baseUrl `https://api.meta.ai/v1` not in `UrlGuard` allow-list — verify meta endpoint not mistakenly blocked | `Support\UrlGuard` |
|4| 8/10 | `PatchFileTool` stamp check is correct but `TokenKiller` excerpt path `basename($file)` loses directory context for duplicate filenames | minor |
|5| 6/10 | `Aigate` `Bearer` token logged via `last-select.log`? Check `hydrate.sh` doesn't leak `META_API_KEY` | `~/.claude/aigate` |
|6| 7/10 | `EventLog` now stamps `session_id` on every payload — `SecretsGuard` must not treat `session_id` as sensitive | `Support\SecretsGuard` |
|7| 5/10 | `ShellTool` argv-array gate in `Loop::dispatchShell()` is solid, but `TokenKiller` `file_get_contents` on `glob` could read `~/.paider/paider.db` if `m1/fixture` pattern widens | add `PathGuard` |
|8| 8/10 | `install.sh` composer-only — no signature verify for `box.phar` in `build.yml`? Already does via `curl` release, but no `cosign` | `build.yml` |
|9| 6/10 | `SettingsStore` path `getcwd()/.paider/settings.json` should refuse symlink attack (check `is_link`) | `Support\SettingsStore` |
|10| 9/10 | `SecretsGuard::isSensitive` already covers `.paider/paider.db` but not `AIGATE_TOKEN` in prompt history — add to scrub list | `Support\SecretsGuard` |

**Agent 1 overall: 6.7/10 — needs 3 fixes before green**

## Agent 2 — Performance / Startup / Token (the 48.6ms promise)

| # | Score | Recommendation | Impact |
|---|-------|----------------|--------|
|1| 7/10 | PHAR 222ms vs 182ms unpacked = +40ms GZ decompress — try `compression: none` + measure (DECISIONS.md says 95.9ms lean, PHAR should be ≤100ms) | `box.json` |
|2| 6/10 | `TokenKiller` pruned 337 toks but only 1.3× win on `m1/fixture` 2 files — win is small because fixture is tiny; test on real `app/` (6k LOC) to prove 10×+ | `bin/token-budget.php` |
|3| 5/10 | `CacheLedger` semantics done but never called — research tier re-reads same repo context every `Loop::turn()` iteration, ideal 90% hit profile wasted | wire hit check |
|4| 7/10 | `autoload 5.3ms` is 70% of bootstrap — `composer dump-autoload --classmap-authoritative --apcu` not yet measured | `composer.json` |
|5| 6/10 | `bin/profile-startup.php` only measures boot, not `paider chat` TTFB — add `time paider chat --help` to `design/startup.md` | startup doc |
|6| 8/10 | `ext-tokenizer` used for sigs, but `TokenKiller::approxTokens=strlen/4` is crude — swap to real `tiktoken` count for budget accuracy | `Support\TokenKiller` |
|7| 5/10 | `EventLog::all()` now streams but `CostLedger::summary()` still iterates all rows — add `WHERE type='tier_call'` index or filtered `stream()` to keep 50k rows bounded | `Storage/EventLog` |
|8| 7/10 | `PHAR` 32MB includes 7185 files — many `vendor` dev files? `box.json` `exclude-dev-files: true` already, but verify `pint` not shipped | `box.json` |
|9| 6/10 | `meta/muse-spark-1.2-contributor` pricing `0.50/1.50` unverified — blocks ledger accuracy for v1.0 | `config/prices.php` |
|10| 8/10 | `Aigate` fetch per `ProviderResolver::forPreset('meta')` hits network every preset resolve — add in-memory cache to avoid 5s `Curl` per `paider config:show` | `Aigate.php` |

**Agent 2: 6.5/10**

## Agent 3 — Correctness / Tests / Ledger (the money)

| # | Score | Recommendation | Risk |
|---|-------|----------------|------|
|1| 7/10 | `EventLog` session_id now correct but `CostLedger` still all-time total — `cost --session` flag not yet wired (v0.3 spec) | `CostCommand` |
|2| 6/10 | `PricesSyncTest` 4 passed but meta pricing comments `// via https://api.meta.ai/v1` have no `$X/$Y` to sync — test will drift | fix comment |
|3| 5/10 | `E2ETraceTest` hermetic but `m1/live-smoke.sh` only scaffolds `pest --group=live` — no real `Loop::turn()` edit in foreign repo proven (the README "unproven E2E" is still true for live) | add `m1/bench/foreign-repo.sh` |
|4| 8/10 | `CacheLedger` 4 hits tests fixed but `CostLedger::summary` still counts `cache_hit` as `unpriced` if `costFor` returns null for unknown model — meta hit with `not-in-prices` now correctly unknown via `Aigate` | verify |
|5| 6/10 | `Loop::parseToolCall()` single-fence strict — good, but `TokenKiller` output `<symbols>` not yet injected as tool result, so model never sees it | wire |
|6| 7/10 | `SessionStore` resumed `?` — `EventLog` session_id exists but `SessionStore` not yet projecting `session_start` → `Session` | `Storage/SessionStore` |
|7| 5/10 | `PatchFileTool` `stamp` required — `TokenKiller` excerpt path uses `basename` so two `Receipt.php` in different dirs collide on stamp | use relative |
|8| 8/10 | `ProviderRoutingTest` now isolates `AIGATE_*` but `qwencloud` `sk-sp-` plan key not yet in `AIGATE_PROVIDER` map (two keys 136/139) | add |
|9| 6/10 | `MemoryStore` ✅ in `STORAGE.md` table but not event-sourced from `EventLog` in this build — `MemoryTool` is direct file, not `memory_set` events | unify |
|10| 9/10 | `Loop` 10-call cap tested but `Loop::run()` multi-todo roster (v0.2) missing — single `turn()` cannot be v1.0 | spec only |

**Agent 3: 6.7/10**

## Agent 4 — UX / CLI / Docs (what README promises)

| # | Score | Recommendation | File |
|---|-------|----------------|------|
|1| 6/10 | `README` still says `PHAR/FrankenPHP deferred` — now PHAR built 32MB verified, docs stale | `README.md` |
|2| 5/10 | `paider --help` lists 5 cmds but `TokenKiller` and `meta` preset undocumented — `paider config:show` shows `meta` but README table doesn't | `README.md`, `presets.php` |
|3| 7/10 | `paider chat` interactive — no `paider run "<prompt>" --yes` for CI (v0.2) — `install.sh` header says non-interactive planned | `Commands/ChatCommand` |
|4| 6/10 | `paider cost` shows `session` row as all-time total — lies until `cost --session` wired | `CostCommand` |
|5| 5/10 | `ROADMAP-DEEP-DIVE-3-PANEL.md` exists but not linked from `PLAN.md` or `README` — discoverability | `PLAN.md` |
|6| 8/10 | `design/startup.md` and `design/frankenphp-trimmed.md` are new but not captured in `design/captures/*.ans` golden | `design/captures` |
|7| 7/10 | `TokenKiller` `bin/token-budget.php` not in `Makefile` help — `make profile` exists but not `make token-budget` | `Makefile` |
|8| 6/10 | `Skill` provenance header is built but `load_skill` not shown in `Loop` tool list when no skills — correct (0 cost) but undocumented | `Skills/SkillLibrary` |
|9| 5/10 | `m1/RUNBOOK.md` promised in `PLAN-PHASES-1-10.md` Phase 1 but not created — `m1/preflight.sh` is there, runbook isn't | `m1/RUNBOOK.md` |
|10| 9/10 | `LICENSE` Apache-2.0 present, but `paider.dev` GitHub Pages not yet updated with v1.0 notes | `docs/` |

**Agent 4: 6.4/10**

## Agent 5 — Distribution / Build / Provider (ship it)

| # | Score | Recommendation | Effort |
|---|-------|----------------|--------|
|1| 6/10 | `build/paider.phar` 32MB gitignored — `build.yml` builds on tag but no nightly PHAR for `dev-main` — add `workflow_dispatch` + `main` artifact | `build.yml` |
|2| 7/10 | `box.json` `compression: GZ` requires `ext-zlib` at runtime — already in 12-ext allowlist, but `install.sh` only checks 4 exts (`dom mbstring tokenizer pdo_sqlite`) | sync |
|3| 5/10 | `FrankenPHP` stock 178MB not yet slimmed to Caddy-free <80MB — `build-static.sh` scaffolds clone, not build | run native build |
|4| 6/10 | `install.sh` `composer global require` only — no PHAR channel (`PAIDER_CHANNEL=phar`) despite PHAR built | add |
|5| 7/10 | `Meta` preset uses `muse-spark-1.2-contributor` for all tiers — research/fast should maybe stay `1.1` ($0.30 vs $0.50) for cost | revert `research/fast` to `1.1` |
|6| 8/10 | `Aigate` in-memory cache missing — `ProviderResolver::forPreset('meta')` hits network every call, `paider config:show` pays 5s | add static cache |
|7| 6/10 | `XAI_API_KEY`/`GLM_API_KEY` etc. not yet via `aigate` — only `meta` has `AIGATE_PROVIDER` entry; generalize | extend map |
|8| 5/10 | `PHAR` 7221 files includes `tests/`? `box.json` `exclude-dev-files:true` but verify `tests/Feature` not shipped | `box.json` |
|9| 7/10 | `vendor/bin/box` missing in fresh clone — `Makefile` `BOX=/tmp/box` fallback works but not documented | `README` |
|10| 8/10 | `~/.paider/paider.db` credentials AES `openssl` still ⬜ v0.2 — `Aigate` is external, but at-rest `META_API_KEY` via `putenv` still in clear | `CredentialsStore` |

**Agent 5: 6.5/10**

---

## Triaged Must-Fix for v1.0 (Round 1 → implement, test, commit, re-score)

**Critical 10 (highest leverage, small):**
1. `README` PHAR built 32MB — fix "deferred" lie (Agent4#1)
2. `Aigate` in-memory cache (Agent2#10/Agent5#6) — avoid 5s per preset resolve
3. `CostCommand --session` wiring (Agent3#1/Agent4#4) — `EventLog` session_id exists, expose it
4. `install.sh` ext check 4→12 sync (Agent5#2) — `install.sh` only checks 4 of 12
5. `Meta` `research/fast` cost revert to `1.1` (Agent5#5) — keep $0.30 tier
6. `PricesSync` meta comment fix `// $0.50 / $1.50` (Agent3#2) — keep ledger sync
7. `TokenKiller` `PathGuard` + `basename` fix (Agent1#2/4) — use `PathGuard::containedIn` + relative path
8. `m1/RUNBOOK.md` create (Agent4#9) — Phase 1 deliverable missing
9. `Makefile` `token-budget` target (Agent4#7) — discoverability
10. `qwencloud` `AIGATE_PROVIDER` add (Agent3#8) — two keys 136/139

**Next batch (round 2 if still <100%):** Cache hit wiring, TokenKiller research-tier wire, MCP scaffold, Paider 100, XDG, FrankenPHP Caddy-free.

Loop: implement 10 → `vendor/bin/pest` → commit → re-score 5 agents ×10 until unanimous.
