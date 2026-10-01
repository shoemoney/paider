# 🔁 Improvement loop — triage ledger

Every finding a reviewer reports gets a decision here: **fixed**, **rejected with reason**, or
**false positive**. Rejections are recorded as prominently as fixes — a loop that only remembers
what it did is a loop that quietly re-litigates the same wrong suggestion every round.

The loop is run by [`bin/loop.sh`](../bin/loop.sh), which asks one vision model per round and
**stops for a human to triage**. It is deliberately not autonomous about fixing things. See
[Why this is not autonomous](#why-this-is-not-autonomous).

| | model | findings | fixed | rejected / false |
|---|---|---|---|---|
| 1 | `google/gemma-4-31b-it` | 5 | 3 | 1 false, 1 superseded |
| 2 | `qwen/qwen3.7-flash` | 5 | 1 | 1 false, 3 rejected |
| 3 | `~deepseek/deepseek-flash-latest` | 5 | 4 | 1 false |
| 3b | `qwen/qwen3.8-27b` | 5 | 2 | 1 false, 1 already fixed |
| 4-6 | `gpt-6-luna-pro`, `gpt-6-luna`, `gpt-5-nano` | 15 | 4 | 2 stale, rest overlapping |

**Running rate: roughly 14 of 30 reported findings were real.** That ratio is the headline and
it is the reason the driver gates on a human.

### The three ways a finding turns out to be wrong

Worth recording separately, because the failure modes are not the same and each has a lesson:

1. **It read a screenshot through a corrupt artifact.** The hero image was a double exposure of
   the TUI over this README, so a reviewer read README bleed-through as UI and reported a
   "critical" TUI defect. The image is now generated, not hand-taken.
2. **It read a commit message or the README, not the code.** `ministral-8b` reported a "critical"
   `/exit bills a turn" bug from the text of commit `b05edbe`, in code that commit had already
   fixed and covered with a test. Same reviewer also claimed total spend renders at the *bottom*
   of `paider cost`; it is the *first* line. Both were checked by running the thing.
3. **It inverted a negation.** The TUI says "`/quit` does not clear it" — an honest disclosure
   that resume is the user's choice. The reviewer read it as a promise that `/quit` clears, and
   filed a "high" severity bug about missing behaviour that the string explicitly says is absent.

The second one is the expensive lesson: a finding whose evidence is a commit message or a
docstring is a claim about what the code *should* be, not what it *is*. Verify at the code, and
prefer running the command over reading about it.

---

## Why this is not autonomous

Rounds 1 and 2, of 10 findings:

- **1 false positive** — claimed an MCP orphan-process leak. Measured: the SDK's
  `StdioTransport::connect()` already calls `close()` on every failure path. There was no leak. A
  test I wrote to "prove" it **passed with the bug still present**, which is how I knew.
- **1 false positive** — claimed a critical TUI defect where "the banner overflows and overlaps
  the input box". The banner is 7 lines and 222 characters. The reviewer was reading a screenshot
  of the README bleeding through the hero image.
- **3 rejected** — proposed replacing the append-only event log with a mutable incremental
  cache. That is the exact design the project locked to prevent drift; see `DECISIONS.md`.

A model that is confidently wrong is cheap. A loop that implements confidently-wrong findings
automatically is how a codebase gets rewritten to be worse while the log fills with green
checkmarks. So this loop **gathers**, and fixes are made deliberately.

---

## Iteration 1 — `google/gemma-4-31b-it`

Full review: [`loop/reviews/iter1-google-gemma-4-31b-it.md`](reviews/iter1-google-gemma-4-31b-it.md)

| # | finding | decision |
|---|---|---|
| 1 | **Tier routing hardcoded to orchestrator** | ✅ **FIXED** `2b5813d` |
| 2 | **MCP orphan process on failed connect** | ❌ **FALSE POSITIVE** |
| 3 | **`EventLog::all()` memory blowup in RunCommand** | ✅ **FIXED** `dd98571` |
| 4 | **CostCommand scans the whole log for a session id** | ✅ **FIXED** `dd98571` |
| 5 | **TUI: YOLO badge blends with resumed status** | ✅ **FIXED** `736dcbc` (as a banner defect, see below) |

### F1 — the headline defect. Tier routing never left orchestrator

`Loop::turn()` hardcoded `resolve('plan')` **and** hardcoded `'tier' => 'orchestrator'` in the
event it wrote. Two copies of one assumption, in the two places that mattered: the `coder` and
`research` tiers were unreachable from the agent, and the cost ledger's per-tier table — the
project's flagship output — could only ever show a single row. A user reading `paider cost` would
reasonably conclude the other tiers were decorative.

Now the first call of a turn plans (orchestrator) and each iteration routes on what the model last
proposed: a write to `coder`, a read to `research`. Seven tests assert the tier a call is
**booked as**, and reverting the routing fails five of them.

### F2 — FALSE POSITIVE, and the test that proved it

The claim was that `connect()` sat outside the `try/finally` that reaps the child, leaking a
process per failed connection. I moved it inside and wrote a test that counts real processes.

**The test passed with the bug still in place.** Reading the SDK explained why: `proc_open`
failing means no process was ever created, and an init error or timeout produces the same `Error`
that makes `StdioTransport::connect()` call `close()` itself. No leak existed.

The test was **deleted** — a test that passes on broken code measures nothing. The reordering is
kept as defence in depth with a comment saying plainly that it fixes nothing. The replacement test
covers a real path: an error *returned* from a working server.

### F5 — reported as cosmetic, was actually a branding bug

Looking at the screenshot to judge the finding properly, the banner read **"The PHP Aider"** — a
pre-rename leftover. Aider is the Python agent this project benchmarks against in its own
README. Nothing asserted what the subtitle *said*; two tests asserted where it sat.

---

## Iteration 2 — `qwen/qwen3.7-flash`

Full review: [`loop/reviews/iter2-qwen-qwen3.7-flash.md`](reviews/iter2-qwen-qwen3.7-flash.md)

| # | finding | decision |
|---|---|---|
| 1 | **CRITICAL: TUI unusable, banner overlaps input** | ❌ **FALSE POSITIVE** → exposed a real bug, below |
| 2 | **Phantom embedding calls inflate the cost ledger** | ✅ **FIXED** `750d50f` |
| 3 | **Projections replay the whole log (O(N))** | ❌ **REJECTED** — see below |
| 4 | **Cost ledger recomputed from scratch each run** | ❌ **REJECTED** — see below |
| 5 | **Response cache is in-memory, not cross-session** | ⚠️ **ACCURATE, ALREADY TRACKED** |

### F2 — the ledger was inventing spend

`RagStore::index()` appended an `embedding_call` event **unconditionally**, including when it
embedded nothing. A no-op re-index makes the embedder report 0 tokens, and `ModelPricing`'s LOCKED
rule treats an all-zero call as *unknown*, not free. So every re-index of an unchanged project
added a phantom "unpriced embedding call" to the ledger.

In a tool whose flagship claim is that its ledger reconciles against provider usage, inventing a
call that never happened is the worst class of bug available. Now booked only when chunks > 0.
A real search still books, matching nothing or not — that call was paid for.

### F1 — FALSE POSITIVE that exposed a real bug

The reviewer reported a critical TUI defect and cited the screenshot as evidence. The banner is
7 lines and 222 characters; it cannot overflow.

It was reading a **screenshot of this README bleeding through the hero image**. Which means
`design/captures/paider-tui.png` — the first thing every visitor to the Packagist page sees — was
a **double exposure**: the TUI superimposed on the README, with a smeared spinner on top.

A corrupt artifact does not just look wrong, it produces false engineering conclusions, and it
had already produced one. So the capture is now generated rather than hand-taken:
[`bin/capture-tui.sh`](../bin/capture-tui.sh) drives the real TUI in a pty, and
[`bin/ansi-to-html.php`](../bin/ansi-to-html.php) is a small terminal emulator that understands
SGR (including 24-bit colour), cursor-up+erase, and carriage returns. Shipping the renderer
rather than depending on `brew install ansi2html` — a capture tool that needs a package nobody
installed is a capture tool that stops being run.

### F3/F4 — accurate, and rejected on purpose

Both say the same thing from different angles: the log is replayed to build every projection, so
it is O(N).

That is the **LOCKED append-only design**, and the rejection is a considered position rather than
an oversight:

- The cost ledger is a *pure projection* over events, **never a mutable balance** — specifically
  so any number can be re-derived and checked against the raw log. A mutable incremental cache
  reintroduces exactly the drift the design exists to prevent, and a stale cache is a confidently
  wrong number rather than a slow one.
- The honest cost of the trade is a slow `paider cost` on a very large log. That is the better
  failure direction: a number you can verify, computed slowly, beats a fast number you cannot.

**When this should be revisited:** if the log reaches a size where `paider cost` takes seconds
rather than milliseconds. The right fix then is a *derived, verifiable* snapshot (one that can
always be recomputed from the log and compared), not a cache that becomes a second source of
truth. Worth a real proposal, not a review bullet.

### F5 — accurate and already tracked

The in-memory `Loop::$responseCache` is lost on process exit, so there is no cross-session
caching. True, and already a known v0.2 item with its own done-command. The reviewer's framing
("consider removing it") is the part worth pushing back on: within a single turn the cache does
real work on retry paths, and cross-session caching is where it should eventually go.

---

## Harness notes

Two failures were the harness's fault, not the models', and both are fixed:

- A reasoning model can spend its **entire** completion budget thinking and emit nothing —
  measured on `qwen3.7-flash`: 8001 completion tokens, 8000 of them reasoning, content length 0.
  That looks like a network failure if you are not counting token fields. The budget is now 16k
  and an empty review is a distinct exit code reporting the token split.
- The harness saved unparseable and empty reviews verbatim rather than dropping them. A reviewer
  whose shape we cannot parse still said something worth reading, and "we could not parse this"
  is not a reason to lose it.

---

## Adding a round

```sh
bash bin/loop.sh 3        # ask the next 3 untried models
bash bin/loop.sh status   # what has been asked, what remains
PAIDER_REFRESH_POOL=1 bash bin/loop.sh 1   # re-pull the pool from the live catalogue
```

Then triage: append a section here with a table of findings and a decision per row.
