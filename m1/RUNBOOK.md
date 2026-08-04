# M1 rehearsal runbook

Goal: watch Paider drive a real multi-file edit in a repo that is not its
own, with a human at the approval prompts. This closes the #1 open item
blocking M1. Budget: about 5 minutes of setup, then however long the model
takes.

## 0. Preflight

```
bash m1/preflight.sh
```

Fix anything it flags before continuing — it checks PHP version, required
extensions, that the `paider` binary runs, that a provider API key is
present (it never prints the value), that `paider config:show` resolves
every tier to a real model, and that `m1/fixture/` is clean.

## 1. Stamp a disposable scratch copy of the fixture

```
m1/fixture/init.sh /tmp/m1-rehearsal
```

(Omit the path to let it `mktemp -d` one instead.) This prints the resulting
path — note it, you'll need it below. It is its own fresh git repo with one
commit, `fixture: pristine baseline`.

## 2. Confirm you're pointed at the fixture, not the paider repo

```
bash m1/preflight.sh /tmp/m1-rehearsal
```

This re-runs every check above **plus** verifies `/tmp/m1-rehearsal` actually
looks like the fixture (it checks for a marker file). Do not skip this — see
"wrong-cwd scare" below for why.

## 3. Start Paider inside the scratch copy — read the cwd banner before typing anything

**This is the step most likely to go wrong. Do not skip verifying it.**

```
cd /tmp/m1-rehearsal
/Users/shoemoney/Projects/paider/paider chat
```

(Use the absolute path to the `paider` binary in the paider repo — you are
running it *from inside* the scratch directory, which is what makes the
scratch directory Paider's project root.) When Paider starts, it prints the
working directory it thinks it's operating in. **Confirm that line says
`/tmp/m1-rehearsal` (or whatever `init.sh` printed) before typing anything
else.** If it says anything under `.../Projects/paider`, stop — see "wrong-cwd
scare" below.

## 4. Paste the task prompt

Paste the prompt from `m1/TASK.md` verbatim into the chat.

## 5. What the approval prompts will look like

| tool call | subject | what to do |
|---|---|---|
| `read_file` | the file path (e.g. `src/Cart.php`, `src/Receipt.php`, maybe `README.md`) | Normal — Paider reads before it writes. Fires once **per file it inspects**, which can be several in a row. This is the #1 "looks stuck, isn't" trap: it's not hung, it's reading. Pick **"Allow for this session"** the first time to stop re-approving every read. |
| `write_file` / `patch_file` | the file path being changed or created | Fires once per file touched. Expect `src/DiscountCode.php` (new), `src/Cart.php`, `src/Receipt.php`. If it proposes writing anything outside `src/` or `tests/`, see below. |
| `git` | no path, subject is just `git` | Only if Paider stages/commits on its own. Fine to allow. |
| `artisan` | — | **Should not appear at all.** The fixture has no `artisan` file, so if Paider tries to call the `artisan` tool it has misdetected the fixture as a Laravel app. Deny it. |

## 6. After Paider stops

From inside the scratch copy:

```
php tests/run.php
```

Exit code should be `0`. Then:

```
git diff --stat HEAD
```

(`HEAD` is the `fixture: pristine baseline` commit `init.sh` made.) Check
against `m1/TASK.md`'s pass/fail criteria: at least 3 files touched
including a new `src/DiscountCode.php`, and nothing unrelated.

## 7. If it goes wrong

- **Model asks to edit something outside `src/` or `tests/`** (e.g.
  `README.md`, `.git/`, anything above the fixture root) — deny it and note
  what it tried. That's a scope miss, not something to rehearse further.
- **Approval prompts feel like they're stuck in a loop** — it's almost
  always `read_file` firing once per file, not a hang. Use "Allow for this
  session" on the first prompt to stop the repetition.
- **Wrong-cwd scare**: if Paider proposes edits to files that look like
  `app/`, `composer.json`, or anything else that belongs to the *paider
  repo itself* rather than the fixture — **abort immediately** (deny
  everything, then Ctrl-C). You are pointed at the wrong directory. Go back
  to step 2 and re-check the marker file, and re-verify step 3's cwd banner
  before restarting.
- **`config:show` or the key check failed in preflight** — fix that first,
  don't try to route around it by editing `.paider/settings.json` by hand
  mid-rehearsal.
- **preflight said everything's fine but `paider chat` still can't reach the
  provider** — preflight's key check hardcodes today's
  preset→env-var mapping (`anthropic` preset → `ANTHROPIC_API_KEY`,
  everything else → `OPENROUTER_API_KEY`, matching
  `ChatCommand::resolveProvider()`). If a preset has since gained its own
  dedicated client (check `app/Commands/ChatCommand.php`), preflight is
  stale — fix the mapping in `m1/preflight.sh`, don't just work around it by
  hand for one run.
- **Suspicious fixture, but `git status --porcelain -- m1/fixture` is
  clean** — a clean status only proves nothing is *currently edited*, not
  that the committed template itself is the right one (e.g. it could have
  been reset to an older commit, or an earlier bad commit could have
  replaced a file with something subtly wrong that still looks clean). If
  something about the fixture looks off, check `git log -- m1/fixture` in
  the paider repo rather than trusting a clean `git status` alone.

## 8. Reset for another run

The scratch copy is disposable — the committed `m1/fixture/` template is
never touched by a rehearsal run:

```
rm -rf /tmp/m1-rehearsal
```

Confirm the template itself is untouched (run from the paider repo):

```
git status --porcelain -- m1/fixture
```

Empty output means the template is still pristine. Re-run `m1/fixture/init.sh`
for the next attempt.
