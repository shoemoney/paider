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
