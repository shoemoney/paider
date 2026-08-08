# 3-Panel Deep Dive — What Roadmap Is Not Implemented

**Basis:** `PLAN.md` v0.1/v0.2/v1.0/v0.3, `STORAGE.md`, `DECISIONS.md`, `config/presets.php/prices.php`, `app/Agent/Loop.php`, `app/Storage/EventLog.php`. Subagent fan-out blocked (`lineage_integrity_failed`) — executed inline, looping.

---

## Panel 1 — Agent Loop, Tools, Skills, Multi-Agent

**Built (v0.1):**
- `Loop::turn()` 10 calls/turn, `parseToolCall()` single-fence, `PhpSpinner::while()`, `requested vs served model` pricing, `EventLog` frozen costs — plus `Read/Write/Patch/Shell/Git/Artisan` (hardcoded `route:list --json`), `FetchUrl`, `Memory`, `LoadSkill`, `TokenKiller` (just added, not wired). `m1/fixture` + `E2ETraceTest` hermetic proves read→patch.

**Roadmap NOT built:**

| Item | Spec | Why it matters | Effort |
|------|------|----------------|--------|
| **MCP client** (`modelcontextprotocol/php-sdk` v0.7.0) | PLAN.md §v0.2 — consume external tool servers as `Tool` impls behind same `Tool` interface | Turns Paider from 7 native tools to N external servers; the Laravel-host thesis needs both ends of MCP. Without it, `ArtisanTool` stays a 1-call stub, not the real server mode. | M — add `App\Providers\McpClient` wrapping `php-sdk`, register via `ChatCommand::buildTools()` when `mcp.json` exists |
| **Repo-map / research tool** | PLAN.md §v0.2 — cheap high-volume search on `research` tier, DECISIONS.md "research ingests 50k to extract 500" | Research currently pays $0.14/$0.28 for full file dumps; repomap via `TokenKiller::prune` cuts 50k→500 at source. Without it, research tier locally optimizes price but not volume. | S — wire `TokenKiller` into `Loop::dispatch` for `research` tier (already scaffolded) |
| **Test-runner feedback loop** | PLAN.md §v0.2 — after `patch_file`, auto-run `php m1/fixture/tests/run.php` style command, feed failures back for N bounded retries, `TODO_CEILING $0.50` | Closes the loop from "edit" to "green". Without it, `Loop` stops at `observationText`, never knows if edit worked. | M — `Loop::turn()` adds `runTest($session)` post-patch, bounded `retry N=3` |
| **`/undo` (multi-agent roster)** | PLAN.md §v0.2 — `Loop::run()` multi-todo roster, `/tier`, escalation, ponytail ultra | v0.1 is single `turn()`; v0.2 is `run()` orchestrator→coder→research roster. Without it, no plan decomposition, no budget escalation. | L — new `Loop::run()` + `Session::todos` |
| **Skill trust boundary** | `STORAGE.md` §Skills — `load_skill` provenance header, `~/.paider/skills` only, project `skills/` refused | Already built but census of 4 capability categories (subagents/MCP/browser) not yet gated per skill — skill could claim tool-call format change. | S — add `Frontmatter::capabilities` check |

**Plan to get there (Panel 1):**
1. Wire `TokenKiller` now: `Loop::buildMessages()` prunes `research` tier context via `TokenKiller::prune($query, $files)`; test with `m1/fixture` `discount` query (already 1.3× win).
2. Add `McpClient` thin wrapper (no SDK upgrade yet, just `Tool` adapter) behind feature flag `PAIDER_MCP=1`.
3. Add `runTest` loop: config `test_command` in `.paider/settings.json`, `ShellTool` runs it post-patch, `Loop` retries on failure.

---

## Panel 2 — Cost, Pricing, Provider, Aigate

**Built:**
- 4-tier routing (`orchestrator/coder/research/fast`), `config/presets.php` 12 presets including new `meta` (`muse-spark-1.2-contributor` default, via `https://api.meta.ai/v1` from `aigate`), `config/prices.php` 23 rows, `ModelPricing::costFor` with `cache_write` 1.25× fallback, `CostLedger` pure projection, `CostCommand` table+JSON, golden tests. `Aigate` client fetches `LLM_1553…` live.

**Roadmap NOT built:**

| Item | Spec | Why it matters | Effort |
|------|------|----------------|--------|
| **Meta pricing verified** | `prices.php` `meta/*` currently `0.50/1.50` `0.30/0.90` unverified placeholder | Ledger's all-Opus comparison and `hypothetical_usd` are wrong if placeholder ≠ real vendor page. | S — verify against `api.meta.ai` pricing page, update comments + `PricesSyncTest` |
| **Response-cache wiring** | `STORAGE.md` §Response-cache — `CacheLedger::recordHit()` semantics done, wiring deferred (🟡 v0.2) | Cache hits currently not recorded; second run re-pays. Without wiring, research re-read ideal 90% hit profile never shows as `cache_saved_usd`. | S — `Loop` checks `CacheLedger` before `provider->send()`, records hit with original `tokens_in/out` |
| **Paider 100 benchmark** | PLAN.md §Paider 100 — diff-apply success rate on `qwen3.7-flash` across fixture corpus, regression gate | Turns structured-output risk from comment into tracked number. Without it, coder tier quality is anecdote. | M — `m1/bench/paider-100.sh` + CI job |
| **Credentials AES-256-GCM** | `STORAGE.md` — `~/.paider/paider.db` encrypted blobs via `openssl` (⬜ v0.2) | Today keys are `getenv` + `aigate` fetch; no at-rest encryption, no `paider config:provider` persistence beyond `settings.json`. | M — `App\Storage\CredentialsStore` with `openssl_encrypt` |
| **Qwen plan URL edge** | `ProviderResolver::qwenBaseUrl()` already handles `sk-sp-` guard | Works, but `DASHSCOPE_PLAN_BASE_URL` not yet wired to `aigate` provider `qwencloud` (two keys `136`/`139`) | S — add `qwencloud` to `AIGATE_PROVIDER` map |

**Plan to get there (Panel 2):**
1. Verify meta pricing live, update `prices.php` comments, run `PricesSync`.
2. Wire `CacheLedger::recordHit()` in `Loop` before network — `if cache.has($hash) then recordHit + skip send`.
3. Add `qwencloud` aigate fetch for plan keys, scaffold Paider 100 corpus dir.

---

## Panel 3 — Distribution, Startup, Storage, Session

**Built:**
- `box.json` hardened (`alias, banner, check-requirements, main, output build/paider.phar, stub, GZ`), `Makefile` `make phar`, `/tmp/box 4.7.0` → `7221 files, 32MB, 7s`, `php build/paider.phar --version` 222ms vs 182ms unpacked vs 95.9ms lean (all measured via `bin/profile-startup.php` + `bin/check-exts.php` 73 vs 12 exts, `design/startup.md`). `install.sh` composer-only, `build-static.sh` scaffold for FrankenPHP, `.github/workflows/build.yml` tag-triggered PHAR release, `m1/preflight.sh` 6 OK.

**Roadmap NOT built:**

| Item | Spec | Why it matters | Effort |
|------|------|----------------|--------|
| **FrankenPHP Caddy-free trimmed binary** | `PLAN.md` §Distribution — `build-static.sh` always links full Caddy v2.11.4, hardens to `static-php-cli` or Caddy-free; `DECISIONS.md §9` stock 178MB → trimmed <80MB | Distribution without requiring PHP installed; install story for non-Composer users. Without it, `curl` channel stays deferred and `install.sh` header 3 blockers (invocation `php-cli paider`, naming collision OOM, `$TMPDIR` untar) block release. | M — native `frankenphp/build-static.sh --php-extension=12` with `--no-caddy`, measure size/cold start |
| **XDG config + `.paider/` consolidation** | PLAN.md §v0.2 — `paider run "<prompt>" --yes` bounded non-interactive, `XDG_CONFIG_HOME`, fixes aider #216/#2860 | Scattered dotfiles; without it, CI `paider run` cannot be scripted. | M — `ProjectEnv` XDG fallback, `--yes` allow-list |
| **Session identity (v0.3 spec)** | `PLAN.md` §v0.3 — `session_id` `Uuid7` in `EventLog` payload, lazy `session_start` event, `origin` tag (`chat`/`commit`), `cost --session` per-conversation | Current `cost` "session" row is all-time total; cannot answer "what did this conversation cost". Without it, ledger cannot partition. Spec is fully written, zero code. | S — `EventLog::__construct(?origin)` + lazy `session_start` as specified, `CostLedger::summary($sessionId)`, `cost --session` flag |
| **Task board (v0.3)** | `STORAGE.md` — `plan items` kanban, `pdo_sqlite` file `task board` table | v0.2 roster needs board to track. Without it, multi-agent has no state. | M — `TaskStore` + `paider board` TUI |
| **Memory durable facts** | `STORAGE.md` — `MemoryStore` already exists but not yet event-sourced via `memory_set` events? Check — `MemoryStore` is ✅ v0.2 in table but not yet projected from `EventLog` | Second run should be smarter than first. Without it, `Loop` re-reads same repo hints. | S — verify `MemoryStore` is event projection vs standalone file |

**Plan to get there (Panel 3):**
1. **Implement `EventLog` session_id now** — per `PLAN.md §v0.3` spec: `__construct(PDO $pdo, ?string $origin=null)`, `Uuid7 sessionId`, `sessionStarted=false`, lazy `session_start` before first `append`, `json_encode` validate before `session_start` write, `payload['session_id']` stamped, readers use `$payload['session_id'] ?? null`.
2. Build FrankenPHP Caddy-free trimmed binary natively, measure, update `DECISIONS.md`.
3. Add `paider run --yes` with tool allow-list + XDG.

---

## Loop — Execute Inline (fan-out blocked)

Subagent fan-out rejected `lineage_integrity_failed` 2× — executing inline, looping. Priority order for this turn:

1. **Panel 3 — `EventLog` session_id** (S, unblocks `cost --session` and all v0.3)
2. **Panel 1 — Wire `TokenKiller`** into research tier (S, immediate token win)
3. **Panel 2 — Wire `CacheLedger`** hit path (S, proves cache saving)

Start with 1, then 2, then 3 — each verified with `vendor/bin/pest`.
