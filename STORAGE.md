# Storage

**One SQLite file. No services.** Paider persists through a single database reached via
`pdo_sqlite`, one of the twelve compiled extensions.

```
.paider/paider.db        # project-scoped: ✅ events (append-only), cost ledger (projection),
                         #                 ✅ sessions (session_* events)
                         # gitignored: the event log accumulates all calls and is local-only
                         # planned v0.2+: memory, response cache
                         # planned v0.3+: task board
.paider/.env             # ✅ project-scoped settings, read by ProjectEnv (gitignored with .paider/)
<project>/.env           # ✅ also read, lower precedence than .paider/.env
~/.paider/paider.db      # planned v0.2: credentials (AES-256-GCM via openssl), global preferences
```

## Configuration

**Nothing user-specific is hardcoded.** Settings are read by `App\Storage\ProjectEnv`, highest
precedence first:

1. a real environment variable — `PAIDER_YOLO=1 paider chat` always wins
2. `<project>/.paider/.env`
3. `<project>/.env`

This exists because Laravel Zero boots with `basePath` set to Paider's **install** directory, so
its built-in `env()` reads a `.env` sitting next to the installation and never looks at the project
you are working in. Anyone following "put it in your .env" was configuring nothing.

Values are parsed array-backed and are **never** exported via `putenv()`/`$_ENV` — otherwise every
project setting would leak into every subprocess `ShellTool` spawns, undoing the environment
scrubbing in [`DECISIONS.md` §17](DECISIONS.md).

| variable | default | effect |
|---|---|---|
| `PAIDER_YOLO` | `0` | Approve every action without asking. Cannot bypass `PathGuard` or `UrlGuard`. |
| `PAIDER_RESUME_MESSAGES` | `50` | How many stored messages a resume replays. `0` disables resume. |

Truthy values are `1`, `true`, `on`, `yes` in any case; anything unrecognised **fails closed**.

## Sessions survive process exit

Every turn is appended as a `session_message` event and replayed on the next run in the same
project. Two deliberate asymmetries:

- **The system message is never replayed.** `Session`'s constructor re-derives it from
  `PAIDER.md`/`CLAUDE.md`/`AGENTS.md` on every run, so replaying a stored copy would post it twice
  and pin a stale version of a file that may have been edited since.
- **Context files store the path, not the content.** They are re-read from disk on resume, so an
  edit between runs is picked up and `PatchFileTool`'s sha256 stamp describes what is actually
  there. A stored copy would hand it a stamp that can never match, and no patch would apply.

**Privacy consequence, stated plainly:** anything that reached a prompt now outlives the process.
`.paider/` is gitignored, but the conversation is on disk until the file is deleted. Set
`PAIDER_RESUME_MESSAGES=0` to keep the old behaviour where it died at exit.

**Confirmed under the FrankenPHP embed binary** (measured 2026-08-02 — see
[`DECISIONS.md` §8](DECISIONS.md)): an in-memory `pdo_sqlite` create/insert/select round-trip
works correctly under the static binary, not just under system PHP. **Re-confirmed under the
trimmed 12-extension static build** (round 2, same date — see [`DECISIONS.md` §9](DECISIONS.md)):
same round-trip, same result, so trimming 77 extensions down to 12 does not disturb `pdo_sqlite`.
The binary is otherwise unrelated to this design — see [`README.md`](README.md#distribution) for
the size numbers.

**v0.1 scope: event log and cost ledger.** The two tables above marked ✅ are implemented.
The rest (marked ⬜) are planned for v0.2+. This design document describes the full
chosen architecture, not just the v0.1 slice.

## What lives there

| concern | shape | state | notes |
|---|---|---|---|
| **Events** | append-only log, JSON payload per row | ✅ **v0.1** | all tier_calls, tool results, approvals; the source of truth for the cost ledger |
| **Cost ledger** | per-tier token and spend accounting | ✅ **v0.1** | pure projection over events, never mutable; see below — this is a feature, not plumbing |
| **Sessions** | conversation history, files in context | ✅ **v0.2** | `session_*` events, projected by `SessionStore`; resumable across runs, survives a reboot |
| **Memory** | durable project facts worth carrying between sessions | ⬜ **v0.2** | the thing that makes the second run smarter than the first |
| **Response cache** | Laravel's `database` cache driver, same file | ⬜ **v0.2** | prompt-cache-aware; identical requests should not be paid for twice |
| **Credentials** | AES-256-GCM blobs via `openssl` | ⬜ **v0.2** | no Node service, no keyring dependency; user-scoped in `~/.paider/paider.db` |
| **Task board** | plan items and their state | ⬜ **v0.3** | what the v0.3 terminal kanban renders from |

## Why SQLite and not Redis

Redis was pencilled in at v0.3 for cache, rate-limit parking and kanban state. On reflection it
earns none of those at this scale.

- It is a **daemon the user has to install and keep running**, against a tool whose entire
  distribution argument is "one binary, nothing to set up."
- Its **durability story is worse** for exactly the data we care about. Losing a conversation to
  an unflushed AOF is a bad day; SQLite is an fsync'd file.
- The workload is **single-user and single-process**. Redis's advantages are concurrency and
  cross-process sharing, neither of which exists here.

**Redis is now unplanned.** It gets reconsidered only with a written concurrency justification —
several agents sharing rate-limit state, or a team deployment. Both are speculative, and
speculative features are what the Non-goals section exists to refuse.

The same reasoning covers **libSQL / Turso**. SQLite itself has no network layer at all — it is a
library linked into the process, so "SQLite with websockets" is really libSQL's Hrana protocol,
a separate product with a server, auth and a network dependency. If multiple agents ever need to
share live state, that is the moment to look at it. Not before.

## The cost ledger deserves its own note

Nobody in this space tracks spend per *tier*. Paider names four — orchestrator, coder, research,
fast — so it can answer questions no other agent CLI can:

> Research burned 1.8M tokens for $0.23 while the orchestrator spent $4.10 on 61k.
> That ratio is the product working.

It also makes the routing claims falsifiable. The README asserts the default stack is 95.3%
cheaper than all-Opus; the ledger is what proves or disproves that on real work rather than a
modelled session.

**Watch out when implementing:** `total_cost_usd` semantics differ by source. In Claude Code's
`--output-format json` it is per-turn; under `stream-json` it is **cumulative for the session**,
so summing across events over-reports by roughly the turn count. That is filed upstream as
[`anthropics/claude-code#83239`](https://github.com/anthropics/claude-code/issues/83239). Detect
the semantics from monotonicity rather than assuming either:

```php
$cumulative = $seq === array_values(array_unique($seq))   // strictly increasing
    && end($seq) > reset($seq);
$total = $cumulative ? end($seq) : array_sum($seq);
```

## Vector search, if it ever comes up

[`sqlite-vec`](https://github.com/asg017/sqlite-vec) is an extension to **SQLite**, not to PHP —
so embeddings could live in the same file with no additional PHP extension and no vector
database. **Unverified** whether it loads through PDO in a statically linked build; check before
promising it anywhere. Worth knowing it exists before anyone reaches for Pinecone.
