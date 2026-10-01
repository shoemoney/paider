# Measured diff-apply rate

`COMPLETION-PLAN.md` D2 asks for a **published, measured** diff-apply rate, and says of it:
*"This is the one this project has never done… A number that cannot be re-derived from a run is a
marketing claim."*

So this file is derived from run artifacts under `m1/runs/`, and every number below can be
re-derived by re-running the grading. Where the evidence does not support a number, there is no
number — see [What this is not](#what-this-is-not), which is the most important section here.

**Graded:** 2026-10-01, against the pre-registered `RUBRIC.md` (registered before any run, per
`DECISIONS.md`'s rule that a live run without a committed rubric is unfalsifiable).

---

## The measurement

| | |
|---|---|
| Target | `github.com/vlucas/valitron` @ `fadce39f5f235755bb9794b2573af2d5bfcba85f` — genuinely third-party, pinned |
| Model | `anthropic/claude-opus-5` |
| Task | Two specified edits, nothing else: a `// live-e2e-proof` comment directly above `class Validator` in `src/Valitron/Validator.php`, and a `<!-- live-e2e-proof -->` line appended to `README.md` |
| Invocation | `paider run "<task>" --yes --require-edit` |
| **Live runs** | **4** (2026-08-18) |
| **Rehearsals (dry-live, not graded)** | 2 (2026-09-29, 2026-10-01) — nothing executed, no spend; excluded from every number below |

### Results, all four live runs, graded against `RUBRIC.md`

| run | exit 0 | marker landed | real diff | spend recorded | verdict |
|---|---|---|---|---|---|
| `20260818T150450Z` | ✅ | ❌ | ❌ | ✅ $0.093 | **FAIL** — different cause, see below |
| `20260818T150719Z` | ✅ | ✅ | ✅ 21 lines | ✅ $0.634 | **PASS** |
| `20260818T150837Z` | ✅ | ✅ | ✅ 21 lines | ✅ $0.471 | **PASS** |
| `20260818T150926Z` | ✅ | ✅ | ✅ 21 lines | ✅ $0.468 | **PASS** |

**Three consecutive passes → M1 is closed** on the "genuinely third-party repo" criterion
(`PLAN.md`, v1.1.0), per the rubric's own rule. The first run failed, so the 3-run count restarted
from zero, exactly as the rubric requires.

### What the three passes actually produced

All three diffs are **byte-identical** — `sha256:2e5226d54ab95dc2…` for every one:

```
 README.md                | 1 +  <!-- live-e2e-proof -->
 src/Valitron/Validator.php | 1 +  // live-e2e-proof
```

2 files, 2 added lines, 0 removed, 0 reflowed. The model made exactly the two edits asked for and
nothing else, three times out of three.

**Cost per passing run:** $0.634, $0.471, $0.468 — median **$0.471**, for a 2-file, 2-line edit
through a live third-party clone.

---

## The one failure, and why it is not a diff-apply failure

`20260818T150450Z` was given a *different* task: implement "discount 10% before tax". Against a
validation library, that requirement has no host. The model's response was to **decline to guess**:

> To implement it I'd have to invent a pricing module — a `Cart`/`Order` class, a tax rate source,
> a rounding policy — and drop it into a validation library where nothing would ever call it.
> […] Which of the three is it?

It made no edit, so criteria 2 and 3 failed. **This is not a patch that failed to apply** — it is
a model declining an ambiguous spec and asking. Grading it in a "diff-apply rate" denominator would
be measuring the wrong thing, and counting it as a success would be measuring nothing at all.

Recorded here because the honest total is **3 of 4 live runs produced the requested diff, and the
fourth was correctly refused rather than wrongly applied.**

---

## What this is not

Read this before quoting any number from this file.

- **This is not a diff-apply *rate* over tasks. It is one task, run three times.** A rate needs a
  denominator of distinct tasks; the denominator here is 1, repeated. A per-run figure of "75%"
  (3/4) is arithmetically true and **misleading**, because the three successes are not independent
  samples — they are the same input producing the same output, verified stable.
- **The three passes are byte-identical**, which is evidence of *determinism* — a real and useful
  property — not evidence of breadth. Nothing here shows the tool applies diffs correctly across
  varied repos, varied languages, or varied diff shapes.
- **n = 1 task, 1 repo, 1 pinned commit, 1 model.** The 95% confidence interval on "3/3" is
  unhelpfully wide, and quoting a percentage at all invites reading it as broader than it is.
- **No run since 2026-08-18.** The two later runs are dry-live rehearsals, which execute nothing
  and spend nothing. They verify the harness still works, not that the tool still works. There is
  **no live data on any commit after 2026-08-18** — including every security fix since, which are
  covered by the hermetic suite but not by this benchmark.
- **Scored by construction, not by a third party.** `RUBRIC.md` was written by this project, and
  the task is two comment insertions. It is an end-to-end plumbing proof — binary boots, model
  called, gate approved, diff written to a foreign clone, spend reconciled — and it should not be
  read as a measure of code-editing quality.

**The defensible claim, in full:** *on a pinned third-party PHP repository, a specified two-file
edit was applied correctly and byte-identically in 3 of 3 consecutive live runs at a median cost of
$0.47, and an ambiguous spec was refused rather than guessed.*

---

## Re-deriving this

```bash
# grade any run against the pre-registered rubric
d=m1/runs/20260818T150719Z
cat "$d/exit-code.txt"                                    # criterion 1
grep -rq 'live-e2e-proof' "$d/changed-files/"             # criterion 2
wc -l < "$d/target.diff"                                  # criterion 3
grep -oiE '\$[0-9.]+' "$d/cost-session.txt"               # criterion 4
shasum -a256 m1/runs/*/target.diff                        # the byte-identical claim

# reproduce a live run (spends real money)
bash m1/bench/capture-live-run.sh --live
```

`RUBRIC.md` is the authority on what "pass" means and was registered before these runs. If a
future run fails, the rubric says record the failure and fix the root cause — **do not re-register
the bar to fit the result.**
