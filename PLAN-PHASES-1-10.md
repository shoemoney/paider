# Phases 1-10 — Fix E2E, Startup Tax, Distribution

**Goal:** close `README.md` alpha gaps:
- Unproven E2E: `app/Agent/Loop.php` 10 calls/turn never drove real edit in foreign repo
- Startup tax: 48.6ms floor (DECISIONS.md §3), lean binary must stay ≤96ms, 12-extension allowlist load-bearing
- Distribution: `box.json` PHAR / FrankenPHP static binary deferred, `install.sh` is composer-only

Measurement basis: DECISIONS.md §3/§8/§9, PLAN.md, m1/preflight.sh, m1/fixture, .github/workflows/tests.yml

---

## Phase 1 — E2E Harness (mock, no spend)
**Deliver:** `m1/RUNBOOK.md` trace template, `tests/Feature/E2ETraceTest.php` drives `Loop::turn()` with mock ProviderClient through `read_file→patch_file→git diff` on tmp copy of `m1/fixture`, asserts `tool_calls≤10`, `EventLog tier_call` frozen `cost_usd`/`hypothetical_usd`, `CostLedger` projection matches, `ToolResult` ok. `bin/e2e-mock.sh` helper.
**Files:** `m1/RUNBOOK.md` (new), `tests/Feature/E2ETraceTest.php` (new), `bin/e2e-mock.sh` (new)
**Verify:** `vendor/bin/pest --filter=E2ETrace` green (hermetic).

## Phase 2 — Loop hardening + guards
**Deliver:** `Loop::parseToolCall()` strict single-fence, retry on `RETRY_ON_APPROVAL_TOOLS` (= read_file/write_file/patch_file/git) via `Gate::decide()`, timeout on `provider->send()` inside `PhpSpinner::while()`, `requested_model` vs `servedModel` pricing preserved, `Session` resume cap `PAIDER_RESUME_MESSAGES`.
**Files:** `app/Agent/Loop.php`, `app/Agent/Session.php`, `app/Approval/Gate.php`, `tests/Feature/LoopToolCallProtocolTest.php` + new `LoopMalformedToolCallTest.php`
**Verify:** New tests green, `m1/preflight.sh` still passes.

## Phase 3 — Cost ledger E2E verification (live, single tier)
**Deliver:** `m1/live-smoke.sh` runs `paider chat` (“add // paider-proof to README.md”) against real `OPENROUTER_API_KEY` on tmp fixture copy, captures `.paider/paider.db` + `paider cost --json`, asserts `spend_usd>0`, `unpriced_calls==[]`, `tokens_*` × `config/prices.php` matches `ModelPricing::costFor()`.
**Files:** `m1/live-smoke.sh` (new), `app/Support/ModelPricing.php` (audit only)
**Verify:** `vendor/bin/pest --group=live` (1 passed when key present, 3 skipped otherwise), `PricesSyncTest` green.

## Phase 4 — Startup profiling & autoload diet
**Deliver:** `bin/profile-startup.php` (hrtime around autoload + Laravel Zero boot), `design/startup.md` with cold-start breakdown, `composer.json` autoload `optimize`/`classmap-authoritative` audit, lean-ini gate.
**Files:** `bin/profile-startup.php` (new), `design/startup.md` (new), `composer.json` (audit), `config/app.php` (provider trim if needed)
**Verify:** `php -n -c lean.ini bin/profile-startup.php` ≤120ms on runner; `vendor/bin/pest` still green.

## Phase 5 — Extension allowlist + preflight hardening
**Deliver:** Enforce 12-ext allowlist (`mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom`) everywhere: `m1/preflight.sh` (already), CI `setup-php` extensions (already), new `bin/check-exts.php` (fail if loaded ext ∉ allowlist+PHP core), `EXTENSIONS.md` sync.
**Files:** `bin/check-exts.php` (new), `EXTENSIONS.md` (sync), `m1/preflight.sh` (no change unless drift)
**Verify:** `php bin/check-exts.php` on clean runner, `m1/preflight.sh` passes.

## Phase 6 — box.json PHAR hardening
**Deliver:** Harden `box.json` (compactors `Php,Json`, `compression:GZ`, `check-requirements:true`, `directories:[app,bootstrap,config,vendor]`), add `Makefile` target `phar: vendor/bin/box compile`, smoke `build/paider.phar --version` + `build/paider.phar list` under lean ini.
**Files:** `box.json` (updated), `Makefile` (new or extend), `.gitignore` (ensure `build/` ignored if not)
**Verify:** `make phar && php build/paider.phar --version` equals `paider --version`; inner `vendor/bin/pest` not shipped.

## Phase 7 — FrankenPHP static binary (deferred issues)
**Deliver:** Document + scaffold the three deferred blockers from `install.sh` header / DECISIONS.md §9: (1) invocation is `<binary> php-cli paider <cmd>` not `./paider <cmd>`, (2) naming collision `paider` binary vs `paider` script → OOM include, (3) `$TMPDIR` world-writable untar on every start. Add `build-static.sh` wrapper calling FrankenPHP `build-static.sh` natively (not Docker — Docker emits Linux-only), trimmed ext set only.
**Files:** `build-static.sh` (new), `design/frankenphp-trimmed.md` (new), `DECISIONS.md` §9 follow-up note
**Verify:** Native macOS trimmed build ~7min, `frankenphp-mac-arm64` smoke: `pmm... php-cli paider list` works, size noted (stock 178MB → trimmed target <80MB), `stream_isatty()` false under pipe.

## Phase 8 — Distribution installer
**Deliver:** Extend `install.sh` to support `PAIDER_CHANNEL=phar|composer` (phar channel only when FrankenPHP blockers resolved; until then document `composer` as default), add `PREFIX` handling for phar symlink, signature verify if fetching phar.
**Files:** `install.sh` (updated), `README.md` install section (updated when channel live)
**Verify:** `install.sh --dry-run` for both channels, `curl -fsSL paider.dev/install | sh -- --dry-run` on fresh VM.

## Phase 9 — CI/CD release matrix
**Deliver:** `.github/workflows/build.yml` — builds PHAR on tag (`vendor/bin/box compile`), attaches `paider.phar` to GitHub Release, optional matrix `ubuntu-latest, ubuntu-24.04-arm, macos-latest, macos-13` for FrankenPHP when Phase 7 lands. Keep `tests.yml` as gate.
**Files:** `.github/workflows/build.yml` (new), `.github/workflows/tests.yml` (unchanged except maybe `needs:` )
**Verify:** Tag push builds artifact, `gh release view` shows asset.

## Phase 10 — Public proof artifact
**Deliver:** Replace modelled `paider cost` table in `README.md` “Modelled session, real command” note with live trace excerpt from Phase 3 (or keep modelled + add live annex), add `design/captures/e2e-80col.ans` + `blog/2026-08-08-e2e-proof.md` with ledger reconciliation, mark alpha status to “E2E proven on m1/fixture”.
**Files:** `README.md`, `design/captures/e2e-*.ans`, `blog/2026-08-08-e2e-proof.md`
**Verify:** `CostReadmeGoldenTest` + `CostJsonGoldenTest` still green after README change; `m1/live-smoke.sh` log committed as proof.

---
**Sequencing:** 1→2→3 (E2E chain), 4→5→6→7→8→9 (startup/distro chain can parallelize after 1), 10 last.
**Current execution:** Phases 1,4,5,6 started inline; 7-9 scaffolded with docs, full FrankenPHP build deferred per DECISIONS.md conditional.
