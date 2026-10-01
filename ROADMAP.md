# 🗺️ Paider Roadmap — Project Manager's Edition

**Audited 2026-09-28.** Replaces the unchecked-box sprawl in `PLAN.md` v0.2/v0.3 with a
sequenced plan whose every milestone carries a **done-command that can fail**.

---

## 0. Two premises in the brief that do not survive the audit

Stated plainly rather than papered over, because planning against a false premise is how
milestones get built that should not exist.

| brief said | reality | call |
|---|---|---|
| "port it to PHP" | Paider **is** PHP-native — Laravel Zero, PHP 8.4, Apache-2.0, on Packagist. It has been since commit one. | **No port. The work does not exist.** A PHP port of `mcpd` (Go) is a real but *different* proposal, ~30k lines, tracked as an open decision below — not assumed, not started. |
| "submit an SDK" | The SDK **already exists and is pushed** — `shoemoney/mcpd-plugins-sdk-php`, 8 RPCs, RoadRunner gRPC. What it lacks is *Packagist registration*. | **Real gap, and it is credentials-gated, not code-gated.** Tracked as M-SDK-1. |

The rest of the brief — plug-and-play MCPs, skills, prompts, agents — maps cleanly onto
open v0.2 work and is planned below.

---

## 1. Where we actually are

| Track | State | Evidence |
|---|---|---|
| v0.1 command surface, 9 tools, SQLite ledger | ✅ **shipped** | `chat`, `commit`, `cost`, `run`, `config:*` |
| M1 — end-to-end edit in a foreign repo | ✅ **closed** | 3/3 rubric-graded live runs in `vlucas/valitron`, `m1/runs/`, $1.57 total |
| Test suite | ✅ **599 passing**, 3440 assertions | `vendor/bin/pest`, hermetic; 25 skip without `PAIDER_TEST_PG_URL` |
| Cold start | ✅ 94.8ms measured | — |
| Distribution | ✅ PHAR 32MB, `curl \| sh` live | Packagist v1.0.1 — see erratum, `DECISIONS.md` §22 |
| **v0.2 Track A — MCP client** | ✅ **CLOSED this session** | committed `fe469bb`; all 5 done-commands pass |
| v0.2 Track B — sessions/memory/cache | 🟡 **partial, unproven** | `SessionStore`/`MemoryStore` exist; resume + cache semantics unbuilt |
| **Postgres driver seam** | ✅ **closed** | `258cae4` — one `rowid` was the entire port |
| **pgvector RAG** | ✅ **closed** | `3e7c04b` — embeddings costed into the ledger |
| **skills + prompts indexed** | ✅ **closed** | `1e8b520` — files still authoritative, boundary held |
| v0.2 Track C — standalone binary | ⬜ **deferred by decision** | awaiting `binary-decision` measurement |
| v0.3 session identity | ⬜ spec only | 5 decisions recorded, none implemented |

**The headline: Track A is now genuinely closed.** It was not closed before this session — it
was *built and green and uncommitted*, which is a state that reads as done and is not.

**Storage grew up without breaking its promise.** Everything asked for — events, sessions, memory,
history, skills, prompts — is reachable in Postgres with vector search, and `composer require
paider/paider` still needs no database server. SQLite remains the default, `ext-pdo_pgsql` is a
`suggest` rather than a `require`, and an unreachable Postgres raises instead of quietly forking
your data onto a local file.

---

## 2. The one real hole, and it is bigger than the roadmap admits

`app/Providers/McpClient.php` does **not** talk to MCP servers.

`discoverViaSdk()` is 44 lines of documented placeholder: it never opens a transport, never
spawns a process, and its executor returns the literal string
`"SDK execute not yet wired"`. When discovery yields nothing it registers a fake
`mcp__<server>__list` tool whose only behaviour is to fail.

Meanwhile `vendor/mcp/sdk` ships a working `Client`, `Client\Builder`, and
`Client/Transport/StdioTransport`. **The capability is in the dependency. It is one adapter
away.**

This matters because "plug and play MCP" is the phrase in the brief, and today Paider can
**serve** MCP (`mcp:serve`, real, tested) and **consume mcpd over HTTP** (`McppdClient`, real,
tested) but **cannot consume a stdio MCP server at all** — which is the most common way anyone
actually installs one. `docs/mcpd.md` calls this "a placeholder". That is accurate and it is the
hole.

> **Assumption recorded** (house rule: pick reversible, record, continue): M1 below wires the
> stdio client through the SDK's own `Client` + `StdioTransport`, spawns with a **scrubbed
> environment** per `DECISIONS.md` §17, and treats `mcp.json` inline `tools:` as a removed
> back-compat path rather than a supported shape. If SDK transport construction proves
> awkward, the fallback is a direct `proc_open` JSON-RPC loop against `StdioTransport`'s wire
> format — same contract, no new dependency either way.

---

## 3. Phases

### 🟢 Phase 1 — CLOSE (done this session)
Land Track A; kill the unsatisfiable graders. `fe469bb`, `5d121d5`.

### ✅ Phase 2 — MAKE MCP PLUG AND PLAY *(the brief's core ask)* — **CLOSED**

| id | milestone | done-command | state |
|---|---|---|---|
| `mcp-stdio-client` | `McpClient` wired to the SDK's real `Client`/`StdioTransport` | `vendor/bin/pest --filter=McpStdioClient` | ✅ **done** `0cb2635` |
| `mcp-stdio-fixture` | Hermetic fixture server, no network/`npx`/download | `test -f tests/Fixtures/stdio-server.php` | ✅ **done** |
| `mcp-env-scrub` | Child processes get `ShellEnv`, never live provider keys (`DECISIONS.md` §17) | `vendor/bin/pest --filter=McpStdioClient` | ✅ **done**, asserted from *inside* the child |
| `mcp-no-placeholder` | `"SDK execute not yet wired"` and the `mcp__*__list` stub gone | `! grep -rq 'execute not yet wired' app/` | ✅ **done** |
| `mcp-composition` | mcpd HTTP + `mcp.json` stdio coexist in one list | `vendor/bin/pest --filter=McpClientTest` | ✅ **done**, real loopback socket |

**What closed it:** a real JSON-RPC stdio client (`McpStdioClient`) over the SDK's own transport,
with the §17 environment scrub applied to every spawned server — because an MCP server in
`mcp.json` is arbitrary third-party code, and without the scrub merely *configuring* one would
hand it your `OPENROUTER_API_KEY`. The 7 tests that pinned the placeholder's behavior were
**replaced, not deleted**; their own header note had named its expiry.

### 🟡 Phase 3 — PLUG AND PLAY: SKILLS, PROMPTS, AGENTS — **skills+prompts done, agents not**

| id | milestone | done-command | state |
|---|---|---|---|
| `pg-driver-seam` | Postgres + SQLite behind one seam, SQLite still the default | `vendor/bin/pest tests/Feature/PostgresStorageTest.php` | ✅ **done** `258cae4` |
| `pg-port-real` | `rowid` → portable `seq`, additive migration, no reordering | `PAIDER_TEST_PG_URL=… pest --filter=PostgresStorage` | ✅ **done** |
| `rag-pgvector` | Chunk/embed/retrieve over the event log, cosine | `PAIDER_TEST_PG_URL=… pest --filter=RagStore` | ✅ **done** `3e7c04b` |
| `rag-costed` | Embedding calls priced into the ledger, unpriced ≠ `$0.00` | `PAIDER_TEST_PG_URL=… pest --filter=RagStore` | ✅ **done** |
| `library-index` | Skills + prompts searchable in Postgres, bodies included | `PAIDER_TEST_PG_URL=… pest --filter=LibraryIndex` | ✅ **done** `1e8b520` |
| `library-boundary` | Project paths refused — clone-to-RCE boundary held | same | ✅ **done**, both refusal tests |
| `agent-roster` | The v0.2 three-role roster (orchestrator / coder / reviewer) | `vendor/bin/pest --filter=Roster` | ⬜ **not built** |
| `roster-bounded` | Iteration cap and token threshold *provably* halt | `vendor/bin/pest --filter=RosterBounds` | ⬜ **not built** |

**Skills and prompts in Postgres, with the boundary intact.** The table is *derived*: the files
stay the source of truth, there is deliberately no "sync from project" method, and the refusal
check lives in the importer rather than the index so a new caller cannot route around it. The
first draft of that check refused only paths *inside* the project — which a repo cloned into
`/tmp` walks straight through. The test caught that before it shipped, and the rule is now
capability-shaped.

**Agents are still not built.** Zero multi-agent code exists; the roster is a design in `PLAN.md`.
That is the honest state and the one remaining item of your original "skills, prompts, agents"
ask.

### ⬜ Phase 4 — CLOSE TRACK B, EARN v1.1.0

| id | milestone | done-command |
|---|---|---|
| `session-resume` | `paider chat` replays `session_message` events — **and** the known double-system-post from `Session.php`'s constructor is accounted for | `vendor/bin/pest --filter=SessionResume` |
| `cache-semantics` | Decide what a cache hit records *before* anything records one — `ModelPricing::costFor()` returns `null` at 0/0, so a naive hit makes savings vanish | `vendor/bin/pest --filter=CacheSemantics` |
| `v1.1-gate` | v1.1.0 is cut only when: third-party E2E edit ✅, CI feedback-loop gate, **published measured diff-apply rate** | `test -f m1/bench/DIFF-RATE.md` |

### ⬜ Phase 5 — SDK DISTRIBUTION (explicit brief item)

| id | milestone | done-command |
|---|---|---|
| `sdk-packagist` | `shoemoney/mcpd-plugins-sdk-php` registered on Packagist | `curl -fsS https://repo.packagist.org/p2/shoemoney/mcpd-plugins-sdk-php.json` |
| `sdk-verify-install` | A clean-room `composer require` resolves it anonymously | a disposable container install |
| `sdk-github-actions` | Release workflow tags + builds on merge to main | `test -f .github/workflows/release.yml` |

**`sdk-packagist` is BLOCKED-ON-CREDENTIAL, not on code.** `~/.composer/auth.json` does not
exist on this machine. This is the one item I cannot complete autonomously, and it is
correctly gated: publishing a package to a public registry is an irreversible public action
under your name. One command from you unblocks it — see §6.

### ⬜ Phase 6 — STANDALONE BINARY (decision, not build)

`CONTINUE-DEFER` stands until `binary-size-report` measures the `--no-dev` share of the
+67MB embed. The plan's rewritten graders must grade through `paider cost` against a seeded
event log — **never** `--version`, which passes with a missing `ext-dom` and dies on the next
command that renders.

---

## 4. Sequencing

```
Phase 2 (MCP stdio client)  ──┐
                              ├──→ Phase 3 (skills/prompts/roster) ──→ Phase 4 (v1.1 gate)
Phase 5 (SDK Packagist)      ──┘   [needs your token, runs in parallel, blocks nothing]
Phase 6 (binary) — independent, runs whenever, gated on its own measurement
```

Phase 2 is the critical path: it is the brief's core ask and the last thing standing between
"MCP-capable" and "plug and play".

---

## 5. Decisions only you can make

1. **mcpd → PHP?** The one real "port to PHP" on the table. Go, ~30k LOC, upstream Mozilla.
   Recommend: **no.** Consume it over HTTP (already done, `McppdClient`) rather than
   reimplementing a daemon whose spec is still moving. Revisit if mcpd's plugin API stabilises.
2. **`mcp.json` inline `tools:` shape** — remove as dead back-compat, or keep for hand-written
   no-server configs? Recommend: remove. It is the thing that makes the placeholder look
   functional.
3. **Roster scope** — build the full 3-role state machine (v0.2 as designed), or ship a
   single delegating subagent first? Recommend: ship one reviewer subagent, measure, then
   generalise. A 3-role machine nobody has used is a guess.

---

## 6. The one thing I need from you

```sh
# Packagist API token creation is interactive (password + 2FA);
# then:
composer config --global --auth github-oauth.shoemoney <gh-token>
```

The Packagist token itself is a credential I should not fabricate or hunt for. Everything
else in this roadmap is code and is executable today.

---

## 7. What I did not do, and why

- **Did not push.** Two commits are local. Pushing is yours to trigger.
- **Did not touch the `mcp.json` placeholder.** It is the top of Phase 2 and it is a real
  behaviour change to a shipped path — not a drive-by.
- **Did not start mcpd→PHP.** Recommendation is no; see §5.
- **Did not invent a PHP port of Paider.** It is PHP.
- **Did not grade any milestone I had not run.** Two "done" graders turned out to be
  unsatisfiable; both are now real. Every other number in this file was measured today.
