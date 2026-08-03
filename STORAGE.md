# Storage

**One SQLite file. No services.** Everything Paider persists lives in a single database, reached
through `pdo_sqlite`, one of the twelve compiled extensions.

```
.paider/paider.db        # project-scoped: sessions, memory, plan, cost ledger
                         # gitignored: the event log accumulates prompts and is local-only
~/.paider/paider.db      # user-scoped: credentials, global preferences
```

**Confirmed under the FrankenPHP embed binary** (measured 2026-08-02 — see
[`DECISIONS.md` §8](DECISIONS.md)): an in-memory `pdo_sqlite` create/insert/select round-trip
works correctly under the static binary, not just under system PHP. **Re-confirmed under the
trimmed 12-extension static build** (round 2, same date — see [`DECISIONS.md` §9](DECISIONS.md)):
same round-trip, same result, so trimming 77 extensions down to 12 does not disturb `pdo_sqlite`.
The binary is otherwise unrelated to this design — see [`README.md`](README.md#distribution) for
the size numbers.

## What lives there

| concern | shape | notes |
|---|---|---|
| **Sessions** | conversation history, files in context, current plan | resumable across runs, survives a reboot |
| **Memory** | durable project facts worth carrying between sessions | the thing that makes the second run smarter than the first |
| **Credentials** | AES-256-GCM blobs via `openssl` | no Node service, no keyring dependency |
| **Response cache** | Laravel's `database` cache driver, same file | prompt-cache-aware; identical requests should not be paid for twice |
| **Cost ledger** | per-tier token and spend accounting | see below — this is a feature, not plumbing |
| **Task board** | plan items and their state | what the v0.3 terminal kanban renders from |

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
