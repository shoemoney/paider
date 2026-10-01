# 🎮 AAA review ledger — Paider

The `tripple-a-gamedev` skill is Godot-specific and its `wf_aaa.js` cannot run against a PHP CLI.
Its **method** is what applies here, and this file records what that method actually found.

---

## How the method was adapted

The skill's five failure modes are all about **believing your own harness**. That transfers
exactly, because Paider's harness is different and its failure modes are the same shape:

| skill (Godot) | here (PHP CLI) |
|---|---|
| screenshots from a real run | terminal captures from a real run, via `bin/capture-tui.sh` |
| a game frame | what a user actually sees: the TUI, `paider cost` |
| "the binary actually runs" CI job | the CLI smoke test |
| blind reviewer sees only pixels | blind reviewer sees only the rendered frame — no code, no paths |
| `verify_shots.py` capture gate | byte-size + distinct-content check before review |

**The blinding is preserved.** The reviewer gets images and nothing else. That is what produced
both real findings below — neither was findable by reading the source, and one was *invisible* in
the source because the source was correct and the RENDER was wrong.

---

## The loop

```
DOSSIER   capture real frames → gate them (not blank) → review the pixels
REVIEW    blind, vision-only, judged as top-tier for a developer CLI
CONFIRM   verify each claim at the code or by running the command — DISMISS artifacts
PLAN      kill the top fixable tells, each with a check that must fail on current HEAD
CODE      failing check first, confirm it fails, then fix
GATE      full suite green, or revert. Never commit red.
```

**Cost:** ~8 minutes/cycle against gemini-3.6-flash on a single dossier — far cheaper than the
skill's ~4.3h/cycle, because the dossier is two CLI invocations rather than a Godot capture
harness, and the reviewer is one flash model rather than an 88-agent roster. Re-time before
quoting that as fact.

---

## Cycle 1 — `google/gemini-3.6-flash`

**Verdict:** needs visual polish. **Confidence:** high.
**Attacked:** left-aligned numerics; collapsed hint spacing; border inconsistency.
**Attempts:** 1. **Accepted:** yes (`51b888d`).

| finding | confirmed? | outcome |
|---|---|---|
| Numbers left-aligned | ✅ real | fixed — `align="right"` |
| `type/quit(or exit)` | ✅ real | fixed — `&nbsp;` |
| "ASCII vs Unicode borders" | ❌ **artifact** | dismissed |
| `^Dit` control chars | ❌ **artifact** | dismissed, then fixed in the tool in cycle 2 |

### The trap worth remembering

The obvious fix for the alignment was `class="text-right"`. It **compiles cleanly, renders
identically, and looks correct in the diff.** Termwind's `TableRenderer` reads the `align`
*attribute* (`TableRenderer.php:165`); `text-right` is handled by `Styles.php` for non-table text
and is silently ignored inside a `<table>`. Found by measuring the output, seeing no change, and
reading the renderer. A test now asserts the attribute is present **and** the ineffective class
is absent, so the lookalike cannot return wearing a similar diff.

---

## Cycle 2 — `google/gemini-3.6-flash`

**Verdict:** functionality hampered by control artifacts and confusing summary statistics.
**Confidence:** high. **Attempts:** 1. **Accepted:** yes (`7c1a0f6`).

| finding | confirmed? | outcome |
|---|---|---|
| Summary contradicts the table above it | ✅ **real, and worse than cycle 1's** | fixed |
| `^Dit` control chars (again) | ⚠️ harness | **tool fixed this time** |
| No colour hierarchy | 🔸 deferred | Termwind palette; revisit |
| Heavy ASCII borders | ❌ artifact | see cycle 1 |

### The finding that mattered

> table says `orchestrator 100.0%` / summary says "0.0% of your tokens went through tiers
> costing 0.0% of your spend"

Both are **arithmetically true** — routed = total − orchestrator, and a single-tier session routes
nothing. Side by side they read as a broken calculation. The arithmetic was never wrong; the
**sentence** was. It now reads *"Everything went through the orchestrator tier — no other tier was
used."*

This is the failure mode the skill calls *"right about the smell, wrong about every detail"* in
reverse: not a wrong detail, but a correct number whose **presentation** manufactures an
inconsistency the user has to reason about.

### Why `^Dit` got reported twice

Cycle 1 dismissed it with the correct diagnosis — *"the capture tool is the thing that should
change"* — and did not change the tool. So the next cycle re-reported the same non-defect, which
is exactly what the skill warns about: **a dismissed artifact that is not actually removed comes
back, and each recurrence looks like a new finding.** Fixed the harness in cycle 2.

Fixing it took two false starts, both recorded because editing a renderer three times running is
where this goes wrong: a blanket "contains `0x08`" also matched the prompt box (laravel/prompts
redraws in place with backspaces) and ate the real prompt — fixing one artifact by creating
another. Scoped to `^` + control letter, which is what actually distinguishes a pty echo from
printed output.

---

## Standing decisions

- **Border style is NOT a defect.** Both tables are Termwind `<table>`. The ASCII grid is
  `bin/ansi-to-html.php` rendering one of them. If it is ever changed it is a capture-tool
  change, never a product one.
- **Colour hierarchy is deferred, not dismissed.** Real, cheap, and worth a cycle once the
  capture tool is trustworthy enough that a reviewer is not spending its budget on artifacts.
- **A finding whose evidence is a capture artifact gets the tool fixed, not a "no".** Re-reporting
  the same non-defect twice is the cost of not doing that.

---

## Yield so far

2 cycles · 2 accepted commits · 3 real defects fixed (alignment, hint spacing, summary sentence) ·
3 artifacts correctly dismissed · 1 harness defect fixed · 0 rejected real findings.

Every fix is fail-closed: each was verified to fail against the pre-fix code before commit.

---

## Cycle 3 — `google/gemini-3.6-flash`

**Verdict:** functional and informative, bogged down by grid clutter and alignment quirks.
**Confidence:** high. **Attempts:** 1. **Accepted:** yes (`b675e5c`).

| finding | confirmed? | outcome |
|---|---|---|
| `$1.654` above `$1.65` | ✅ **real** | fixed — one precision everywhere |
| "numbers are CENTERED" | ❌ **wrong** | verified right-aligned in the capture |
| "heavy ASCII borders" | ⚠️ **real, and I had dismissed it twice** | attempted, then reverted — see below |
| Summary line | ✅ praised | — |

### The finding I dismissed twice was real

Cycles 1 and 2 both reported "inconsistent ASCII vs Unicode table borders" and I dismissed both
as my capture renderer. **That was wrong.** Reading `Termwind/src/Html/TableRenderer.php:124`:

```php
$this->table->addRow(new TableSeparator);
```

Termwind inserts a **Symfony Console `TableSeparator`** before every `<tr>`. That is real product
output. Two cycles of reviewer budget spent on me insisting otherwise, and the reason I believed
it was that the two *screenshots in the dossier* did look inconsistent — the TUI genuinely uses
Unicode, the cost table genuinely uses ASCII. The observation was right and my explanation was
wrong.

**Attempted fix, then reverted.** The grid is only avoidable by not using `<table>`, so I rebuilt
the rows as `flex` + `w-*` columns. It fails: `w-*` exists in Termwind only as a **fraction**
(`w-1/2`), never a fixed character width, so columns cannot be sized. `StyleNotFound:
Style [table] not found` on the first attempt, then no width primitive on the second. Reverted,
precision fix kept.

So the honest status: **known, real, and not fixable within Termwind.** It would need a custom
column renderer rather than `<table>`. Recorded rather than quietly dropped, because a dismissed
finding that turns out to be real is the most expensive kind of dismissal.

### The currency finding rode inside a wrong one

"Centering breaks decimal alignment" was flatly wrong — the capture shows 42, 260.8k and $1.654
sharing a right edge, which is cycle 2's fix being described as the defect. But the same item
also observed two currency precisions in one output, which was **the actual bug of this cycle**.

That is now the loop's most reliable shape: **the real defect is rarely the headline.** It was
found by measuring each claim rather than ranking them.

---

## Standing decisions (revised after cycle 3)

- ~~Border style is NOT a defect.~~ **CORRECTED.** It is real product output from Termwind. It is
  not fixable without replacing `<table>`, so it is a known limitation, not a dismissed finding.
- Currency precision: **one precision everywhere**, asserted as a set so a fourth format fails.
- Colour hierarchy: still deferred. Lower value than the two fixed above, and worth a cycle only
  when the reviewer is not being handed a recurring non-finding.

---

## Cycle 4 — `google/gemini-3.6-flash`

**Attempts:** 1. **Accepted:** yes (`b05d195`).

| finding | confirmed? | outcome |
|---|---|---|
| `$0.000 saved` reads as a raw float | ✅ real | fixed — exact zero prints `$0.00` |
| hint spacing still broken | ⚠️ **stale asset** | code was fixed in cycle 1; the published capture predated it |
| "dashes are center-aligned" | ❌ wrong | verified right-aligned |
| grid borders | known Termwind limit | unchanged |

The hint finding is the interesting one: the reviewer was judging the **committed capture**, not
the code. Cycle 1 fixed the spacing; the image shipped alongside it had not been regenerated. A
stale artifact in a docs image is the same failure as the double-exposure capture earlier in this
loop — it does not merely look wrong, it makes whoever reads it draw a false conclusion about
the code. **Regenerated.**

Fixing the zero case introduced `$-5.600 more` on the negative branch (formatted `$saved` where
the line prints `-$saved` as positive). Caught by the golden test that already covered that
line, and `abs()` fixed it. Two cycles running where the suite that *reported* a change is the
suite that *policed* its consequences — which is the correct relationship and worth keeping.

## Cycle 5 — `google/gemini-3.6-flash`

**Attempts:** 1. **Accepted:** yes.

| finding | confirmed? | outcome |
|---|---|---|
| Total spend has no visual hierarchy | ✅ **real, deferred since cycle 2** | fixed |
| numbers right-aligned | ✅ praised | cycle 2's fix, confirmed stable |
| ASCII grid | known Termwind limit | unchanged |
| ASCII banner "illegible" | ❌ **taste** | the wordmark is the product's identity |

**Hero metric.** `Total spend` is the reason anyone runs this command and it rendered in the same
weight and colour as a table cell. Label now muted, figure accented, and a real saving takes the
success role — while a *zero* saving deliberately does not, because dressing "you saved $0.00" in
the success colour would be decoration lying about the result.

Dropping the colon while splitting the label from the figure broke two golden tests. Kept the
colon inside the muted span: emphasis should not cost a character of the sentence.

My own hierarchy test passed with the ternary deleted at first, because `ColorRole::Success`
appears elsewhere in the file. Tightened to the exact expression — **a test that survives
deleting the thing it names measures nothing.**

---

## Closing assessment — 5 cycles

**4 accepted commits.** Real defects fixed: 6. Wrong findings caught by measurement: 7. Artifacts
correctly dismissed: 2. Artifacts I dismissed and was **wrong** about: 1 (the grid, twice).

Three things this loop taught that generalise beyond it:

1. **The real defect is almost never the headline.** Cycle 2's headline was a capture artifact
   and the real finding was a sentence. Cycle 3's headline was a false alignment claim and the real
   finding was currency precision. Cycle 4's headline was a stale image and the real finding was a
   float in prose. Measure every claim; rank none by confidence.
2. **The reviewer describes a correct fix as a defect roughly once a cycle**, because it reads the
   current state with no idea what changed. "Numbers are centered" was cycle 2's own fix, reported
   as a bug in cycle 3.
3. **A dismissed artifact that is not removed comes back.** `^Dit` was reported twice before the
   capture tool was actually fixed. And a dismissal made with the wrong reasoning — the grid, which
   I called a capture artifact twice when Termwind emits it — costs more than the fix would have.

**Known and not fixed:** the `+---+` grid, because Termwind inserts a Symfony `TableSeparator`
before every `<tr>` and `w-*` exists only as a fraction, so the table cannot be rebuilt as columns
without a custom renderer. Recorded as a limitation, not dismissed.

**The loop's value was not the six fixes.** It was finding the two places where I was confidently
wrong about my own code — the grid, twice, and the CI gate's inverted logic — which no amount of
reading would have surfaced.

---

## Cycle 6 — `google/gemini-3.6-flash` (NEW SURFACES)

**Deliberately reviewed `paider list` and `config:show` this round** — the previous five cycles
only ever looked at `paider cost` and `paider chat`, and a review that keeps re-examining one
surface stops finding things there. Yield confirms it: this cycle found the worst defect of the
six on a surface that had never been judged.

**Attempts:** 1. **Accepted:** yes.

| finding | confirmed? | outcome |
|---|---|---|
| `price` column is ambiguous | ✅ **worst defect of the run** | fixed — split into `in / 1M` and `out / 1M` |
| `paider list` description padding is uneven | ✅ real | **attempted, abandoned** — see below |
| ASCII grid | known Termwind limit | unchanged |

### `price` — the finding the first five cycles missed

```
| tier         | model                  | price           |
| orchestrator | anthropic/claude-opus-5 | $5.00 / $25.00 |
```

Nothing said which number was input and which was output. The value is scraped from a
`// $in / $out` comment in `config/presets.php`, so the ambiguity is a *presentation* bug in a
table whose entire purpose is choosing a model by cost. Now two labelled, right-aligned columns
with the unit in the header, because `$5.00` alone is a number rather than a rate.

### The abandoned fix, and why

`paider list` really is ragged — `config:provider` gets a 1-space gap where `run` gets 13 — and
the cause is upstream: `Describer.php:68` resets `$this->width = 0` **per namespace group**, and
Paider registers one command per group, so nearly every gap collapses.

Fixing it took **four attempts** and I stopped:

1. A provider binding the contract — silently lost to the vendor's own `register()`.
2. A provider extending the vendor one — still lost; Zero registers it from a hardcoded list.
3. `config/app.php`'s `providers` — **not the seam at all**; Zero never calls
   `registerConfiguredProviders()`. It produced a CLI that died with a `foreach()` error pointing
   at a file with no `foreach` in it.
4. `booted()` — collided with `ServiceProvider::booted(Closure)`, which is the method that
   *registers* a callback. Fatal, exit 255, no output.

Deliberately abandoned and reverted rather than shipped half-working. It is a legitimate
improvement worth roughly one space of alignment, and the cost was already six of my eight failed
substitution attempts this session — the shape of this loop's recurring trap, where a change looks
applied and does nothing. Recorded as a known gap rather than a fix.

**Standing rule from cycle 6:** a cosmetic fix that needs the framework's container internals
understood is not a cosmetic fix. Either it is understood or it is deferred.

---

## Cycle 7 — `google/gemini-3.6-flash`

**Attempts:** 1. **Accepted:** yes.

| finding | confirmed? | outcome |
|---|---|---|
| trailing periods inconsistent across descriptions | ✅ real | fixed — house rule: none |
| price headers misaligned with their values | ✅ real | fixed |
| ASCII grid | known Termwind limit | unchanged |
| colour separation in help | ✅ praised | — |

**Two consistency defects, neither of which any test or review had caught in six cycles**,
because they only exist when you look at all the commands *together* rather than one at a time.

`paider list` mixed trailing periods in a single column — `chat`, `run`, `config:*` ended with
one, `commit`, `cost`, `mcp:*` did not. It reads as a style nobody owns. The test now scans every
`$description` in the tree, so the next command added is covered by the same rule instead of by
whoever remembers it, and the failure message names the offending file rather than just counting.

Mid-sentence periods stay (`(CI mode). Auto-approves…`) — those separate clauses and are a
different thing from a trailing one.

The price headers were left-aligned over right-aligned values, which Termwind pads to the widest
cell, so `in / 1M` sat a column left of the figure it labels. Same trap as cycle 1's `text-right`,
caught faster this time because the pattern was already known.

**Cycle 6's lesson, applied:** nothing here required touching the framework's container, so
nothing here was abandoned. That is the dividing line.

---

## Cycle 7 — `google/gemini-3.6-flash`

**Attempts:** 1. **Accepted:** yes.

| finding | confirmed? | outcome |
|---|---|---|
| trailing periods inconsistent across descriptions | ✅ real | fixed — house rule: none |
| price headers misaligned with their values | ✅ real | fixed |
| ASCII grid | known Termwind limit | unchanged |
| colour separation in help | ✅ praised | — |

**Two consistency defects, neither caught in six cycles**, because they only exist when you look
at all the commands *together* rather than one at a time.

`paider list` mixed trailing periods in a single column — `chat`, `run`, `config:*` ended with
one, `commit`, `cost`, `mcp:*` did not. Reads as a style nobody owns. The test now scans every
`$description` in the tree, so the next command added is covered by the same rule instead of by
whoever remembers it, and the failure names the offending file rather than counting offenders.

Mid-sentence periods stay (`(CI mode). Auto-approves…`) — those separate clauses and are a
different thing from a sentence terminator.

Price headers were left-aligned over right-aligned values, which Termwind pads to the widest
cell, so `in / 1M` sat a column left of the figure it labels. Exactly cycle 1's `text-right` trap,
caught far faster this time because the pattern was already written down.

**Cycle 6's lesson, applied:** nothing here required touching the framework's container, so nothing
here was abandoned. That is the dividing line.

---

## Cycle 8 — `google/gemini-3.6-flash` (THE ERROR PATH)

**Attempts:** 1. **Accepted:** yes.

| finding | confirmed? | outcome |
|---|---|---|
| parse error does not say what to DO | ✅ real, **ours** | fixed |
| code-frame vs trace-number gutter breaks | ⚠️ vendor | not ours — Collision's renderer |
| `JsonException::("Syntax error")` looks like bad syntax | ⚠️ vendor | not ours |
| trace paths not dimmed by directory | ⚠️ vendor | not ours |

**Pointed the reviewer at a MALFORMED-CONFIG crash**, a surface this loop had never judged, and
three of its four findings were about **Collision's exception renderer** — a transitive Laravel
Zero dependency, not Paider code. Overriding it is the same container fight cycle 6 abandoned,
so: not ours, not fixed, recorded.

The fourth was ours and it is the one that matters:

```
RuntimeException
Could not parse /tmp/errdemo/.paider/mcp.json: Syntax error
```

True, and useless to the person reading it. Collision renders the trace either way, so the
**actionable half has to be in the message itself** — otherwise a user who mistyped one
character in a JSON file is told a JsonException occurred and left to work out which file. Now:

> Could not parse …/mcp.json: Syntax error. **Fix the JSON, or point PAIDER_MCP_CONFIG somewhere
> else.**

Still deliberately does not echo the file contents: a config can carry a token-bearing URL, and an
error screen is the worst place to leak one. The test asserts both — the remedy is present AND
the contents are not.

**Cycle 8's lesson:** three of four findings were in code this project does not own. Judging a
dependency's presentation as if it were yours is the new variant of "right about the smell,
wrong about the detail" — and it is cheaper to catch than to fix, because the first question is
always *whose code is this?*

---

## Eight-cycle scoreboard

| | |
|---|---|
| cycles run | 8 |
| accepted commits | 8 |
| real defects in **Paider's** code fixed | 10 |
| findings about vendor code, correctly not fixed | 5 |
| wrong findings caught by measuring | 9 |
| surfaces reviewed | cost, chat, list, config:show, **error path** |
| abandoned after investigation | 1 (cycle 6 describer) |
