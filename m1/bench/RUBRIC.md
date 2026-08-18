# M1 live-run rubric

Pre-registered **before** any `foreign-repo.sh --live` run, per DECISIONS.md's rule that
running live without a committed rubric is unfalsifiable. This is the rubric the chair's
3 budget-capped live runs are graded against. Do not edit it to fit a result after the fact —
if a run fails, record the failure (see below), don't rewrite the bar.

## Pass/fail per run

A single live run **passes** iff all four hold:

1. **`paider` exits 0.** The exec'd `paider run ... --yes` inside `foreign-repo.sh --live`
   returns exit code 0 (captured in the run's `exit-code.txt`).
2. **The marker landed.** The target clone's `src/` contains the literal string
   `// live-e2e-proof` in at least one file (the edit was actually applied, not just
   attempted).
3. **A real, non-empty diff.** `git -C <target> diff` for the run is non-empty, is a
   syntactically valid unified patch, and touches the same file that contains the
   `// live-e2e-proof` marker from #2 (the expected file — whichever file the model
   actually edited to add the marker, not a fixed filename, since the foreign target
   varies).
4. **Spend was recorded.** `paider cost --session`, run from inside the target clone
   after the run, reports nonzero spend, and that spend traces back to a `tier_call`
   event in the target's own event log (`.paider/paider.db`) for that session — not a
   stale number left over from a previous session.

Any one criterion failing fails the run. There is no partial credit and no "close enough."

## Pass/fail across the 3 runs

- **3/3 consecutive passes → M1 is closed** on the "genuinely third-party repo" DoD
  (PLAN.md, v1.1.0 criteria).
- **Any single fail → stop immediately.** Do not retry into the same failure (see
  CLAUDE.md's error-prevention rules). Record the run's artifacts (see
  `capture-live-run.sh`) and the specific criterion that failed. Fix the root cause,
  re-register or amend this rubric if the fix changes what "pass" means, and only then
  restart the 3-run count from zero — a fixed run 2 does not inherit run 1's pass.

## How runs are captured

Every live (and dry-live rehearsal) invocation goes through `capture-live-run.sh`, never
`foreign-repo.sh` directly, so grading always has the same four artifacts to check against
the criteria above:

- `m1/runs/<UTC-timestamp>/transcript.log` — full stdout/stderr of the run
- `m1/runs/<UTC-timestamp>/exit-code.txt` — criterion 1
- `m1/runs/<UTC-timestamp>/target.diff` — criterion 3
- `m1/runs/<UTC-timestamp>/cost-session.txt` — criterion 4
- `m1/runs/<UTC-timestamp>/changed-files/` — copies of the changed file(s), for criterion 2

`m1/runs/` is gitignored (run artifacts are real API spend evidence, not template code) —
commit a run's directory deliberately when it's part of the record for a rubric decision.

## Amendment — 2026-08-18, after live run 1 (20260818T150450Z)

**Run 1 FAILED criteria 2 and 3** (no marker, zero-byte diff) while "passing" 1 and 4
(exit 0, $0.093 spend, 5 orchestrator calls). Artifacts committed at
`m1/runs/20260818T150450Z/`. Root cause was the **experiment, not the agent**: the
pre-registered prompt referenced pricing code that does not exist in the pinned foreign
target (valitron is a validation library); the model explored correctly and asked a
clarifying question into a non-interactive run. Two consequences:

1. **Prompt re-registered** (foreign-repo.sh PROMPT): two unambiguous edits that exist in
   the pinned target — `// live-e2e-proof` comment above `class Validator` in
   `src/Valitron/Validator.php`, plus a `<!-- live-e2e-proof -->` line appended to
   README.md. Pass criteria 1-4 are UNCHANGED. Criterion 3's "expected file" now reads on
   either of the two named files. **Run count restarted at zero** per this rubric's own rule.
2. **Product defect recorded, not fixed here:** `paider run` exits 0 when a run completes
   without a single applied edit — silent success in CI mode. Criterion 1 alone is
   therefore proven insufficient as a success signal; the rubric's criteria 2+3 are what
   caught this. Filed for the next survey cycle.
