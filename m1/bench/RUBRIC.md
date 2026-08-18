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
