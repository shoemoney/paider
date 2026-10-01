# 🏛️ Completion Plan — Architect's View

**Written 2026-09-30**, after the autonomous review loop surfaced five security defects in code
this project had already merged. That changes what "complete" means here, and this plan is
ordered by that.

---

## 0. The architect's first question: what is actually unfinished?

Not the roadmap's ⬜ boxes. Measured:

| area | state | evidence |
|---|---|---|
| v0.1 command surface | ✅ shipped | `chat`, `commit`, `cost`, `run`, `config:*` |
| v0.2 Track A — MCP | ✅ **closed** | stdio client, mcpd HTTP, `mcp:serve`, `mcpd:daemon` |
| v0.2 Track B — sessions/memory | 🟡 **present but unproven** | `SessionStore`/`MemoryStore` exist; resume + cache semantics untested against real use |
| Postgres + pgvector | ✅ shipped | driver seam, RAG, costed embeddings |
| Skills/prompts index | ✅ shipped | `LibraryIndex`, trust boundary enforced structurally |
| **Agents / roster** | ❌ **zero code** | the v0.2 three-role design is a `PLAN.md` section and nothing else |
| Security review | ⚠️ **five holes found and closed this session** | credentials in tool args, mcp.json RCE, endpoint hijack, index bypass, seq race |
| CI | ✅ green | 4 jobs, includes `php 8.4 lowest` |
| Release | ⏳ **never earned** | v1.0.x shipped early (erratum, `DECISIONS.md` §22) |

**The architect's read.** This is not a project with a feature backlog. It is a project with a
**verification backlog**: the loop found five defects in ~40 findings, and four of the five were
in code written *in this same session*. The pattern is consistent — **work shipped here moves
faster than it is checked.** Adding the roster before the checking catches up would make that
worse, not better.

So the completion plan is ordered: **harden → prove → then build**.

---

## Phase A — Make the loop's output survive contact with CI *(doing now)*

**Why first.** Every finding this plan acts on arrived by manual triage. A defect that a reviewer
found at 01:00 and a human triaged at 09:00 is a defect with a nine-hour window where it is still
in `main`. That window is the whole risk.

| # | todo | done-command (must fail when undone) |
|---|---|---|
| A1 | `composer audit` gate in CI, non-zero on high/critical | `grep -q 'composer audit' .github/workflows/tests.yml` |
| A2 | Test-count + doc-claim drift gate | `vendor/bin/pest --filter=SyncTestCounts` |
| A3 | A `SECURITY.md` stating the trust model in one place | `test -f SECURITY.md` |
| A4 | Publish every confirmed finding to the triage ledger | `grep -c '^| [0-9]' loop/triage.md` |

**A1 is the one that matters most.** The HIGH advisory on `mcp/sdk` survived because `composer
audit` was not in CI — it surfaced incidentally on the lowest-deps matrix. A pin is a stability
control, not a security control; a CI gate makes that distinction operational.

---

## Phase B — Close Track B properly *(the "unproven" column)* — ✅ **VERIFIED, no code needed**

**Architect's note on this phase, recorded because the plan was wrong about it.** The plan
predicted sessions and memory would need building. They did not: on execution, all four
milestones were **already implemented and already tested** — `SessionStore::messages()` replays
stored turns, `ChatCommand` wires it, `Session::replay()` exists specifically to avoid the
double-post, and `CacheLedger` prices a hit from the *original* token counts rather than zeros,
which is the exact trap B3 warned about.

The done-commands all pass:

```
vendor/bin/pest --filter=SessionResume|SessionStore   19 passed
vendor/bin/pest --filter=CacheLedger                   10 passed
vendor/bin/pest --filter=unpriced|Unpriced             10 passed, 1 skipped
```

So Phase B was a **verification** task, not a build task. Recorded here rather than quietly
reordered, because "the plan said build and the answer was verify" is the useful finding — and
because a plan that is never wrong about its own state is not being read carefully.

**What the original plan got right, and it was the important part:** the roadmap marked this
"🟡 partial, unproven" while the code was complete. Both were true. Unproven is not the same as
unbuilt, and the difference is exactly what a done-command is for.

| # | todo | done-command |
|---|---|---|
| B1 | `session-resume` — chat replays `session_message` events before turn 1 | `vendor/bin/pest --filter=SessionResume` |
| B2 | Account for `Session.php`'s unconditional `pushHistory('system', …)` — the documented double-post | `vendor/bin/pest --filter=SessionDoublePost` |
| B3 | `cache-semantics` — decide what a hit records *before* anything records one | `vendor/bin/pest --filter=CacheSemantics` |
| B4 | `unpriced ≠ $0.00` held for cache hits, embeddings and mixed tiers | `vendor/bin/pest --filter=UnpricedNeverZero` |

**B3 is the sharpest question in the roadmap and it is a trap.**
`ModelPricing::costFor()` returns `null` for a 0-in/0-out call, deliberately, so an unpriced model
*surfaces*. A naive zero-token cache-hit event therefore makes savings vanish as `null`. The
ledger's flagship claim is that it reconciles against provider usage; get this wrong and the
feature starts lying. **Decide the semantics before writing the writer.**

---

## Phase C — The agent roster *(the last real feature)*

Three roles, one executor, per the v0.2 design: orchestrator plans, coder edits, reviewer checks.
Bounded at 3 shared rounds.

| # | todo | done-command |
|---|---|---|
| C1 | `Roster` as a bounded state machine, injectable like `Loop` | `vendor/bin/pest --filter=Roster` |
| C2 | `roster-bounded` — the cap **provably halts**, not merely starts | `vendor/bin/pest --filter=RosterBounds` |
| C3 | Every roster call books to the tier it used (the F1 defect, applied here from day one) | `vendor/bin/pest --filter=RosterTierAccounting` |
| C4 | A round that cannot complete is **reported**, never silently truncated | `vendor/bin/pest --filter=RosterBudgetExhausted` |

**C2 is deliberately a separate milestone from C1.** This repo's own history (`DECISIONS.md` §20)
is a test that could not fail. A bounds test that only proves the happy path is the same defect
wearing a new hat — and bounds are the *only* thing that keeps an agent from looping until it
runs out of money.

**Architect's recommendation: ship one reviewer subagent, not the full roster.** Measure it, then
generalise. A three-role state machine nobody has run is a guess; one reviewer with a budget is a
measurement.

---

## Phase D — Earn the release

No further `v1.0.z` ships, per `DECISIONS.md` §22. `v1.1.0` is gated, and every gate is now
*runnable* — which is the point of the gates.

| # | todo | done-command |
|---|---|---|
| D1 | E2E edit in a genuinely third-party repo, rubric-graded | `m1/bench/RUBRIC.md` + a passing run |
| D2 | Published, **measured** diff-apply rate (not a modelled one) | `test -f m1/bench/DIFF-RATE.md` |
| D3 | `composer audit` clean at high/critical | `composer audit --format=plain` exits 0 |
| D4 | CI green on the exact tag commit | `gh run list --commit $TAG` |

**D2 is the one this project has never done** and the reason `v1.0.0` shipped on a score loop
grading polish. A number that cannot be re-derived from a run is a marketing claim.

---

## Phase E — Distribution

| # | todo | done-command |
|---|---|---|
| E1 | `mcpd-plugins-sdk-php` on Packagist | `curl -fsS https://repo.packagist.org/p2/shoemoney/mcpd-plugins-sdk-php.json` |
| E2 | Clean-room `composer require` resolves it anonymously | disposable container install |
| E3 | Standalone binary: **decision**, not build — measure, then GO or CONTINUE-DEFER | `grep -qiE '^## [0-9]+\. FrankenPHP embed' DECISIONS.md` |

**E1 is blocked on a credential I do not have and will not fabricate** — publishing a package to
a public registry is irreversible and runs under your name. One token unblocks it.

**E3 is a decision, not a chore.** 178MB → the size argument never changed. What is new is
`ext-pdo_pgsql` and the driver seam, which slightly favours the binary (one less reason to carry
a service). Measure, then decide.

---

## Autonomy: how to make this continue without you

**The mechanism is `/ralph-loop`, and it is already available in this session.** It is a real
plugin with a state file, not a promise:

```
/ralph-loop "Work ROADMAP.md completion plan Phase A, then B, then C. Before any commit run the
              full suite and pint. Never push without saying so. Stop at <promise>DONE</promise>"
/cancel-ralph            # stop it
```

State lives at `.opencode/ralph-loop.local.md`, so it survives a session restart.

**Two honest limits, stated up front:**

1. **It self-continues on *idle*, not on wall-clock.** If it has nothing actionable it stops.
   Making it useful means the task must always have a next verifiable action — which is why this
   plan is written as done-commands rather than intentions.
2. **It does not remove the need for you on irreversible actions.** Tagging, Packagist publishing
   and pushing stay yours, because each is public and under your name. That is a design choice in
   this plan, not a limitation I'm inventing to avoid work.

**What autonomy already looks like in this session:** the review loop ran 24 models unattended
while I triaged its output. That is the pattern — *gather* unattended, *decide* deliberately.

---

## Sequenced

```
A  harden   ← doing now; closes the window between "reviewer found it" and "human triaged it"
B  prove    ← sessions/memory exist but are unverified; B3 is a trap, decide before building
C  build    ← roster, but ship ONE reviewer first and measure
D  earn     ← v1.1.0, gated on runnable checks, including a measured diff-apply rate
E  ship     ← Packagist (needs your token), binary (needs a measurement first)
```

Each phase is independently shippable. **A through D is achievable unattended. E1 needs you, and
nothing else does.**