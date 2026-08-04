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

## 3. Start Paider inside the scratch copy — check `pwd` before typing anything

**This is the step most likely to go wrong. Do not skip verifying it.**

```
cd /tmp/m1-rehearsal
pwd
/Users/shoemoney/Projects/paider/paider chat
```

(Use the absolute path to the `paider` binary in the paider repo — you are
running it *from inside* the scratch directory, which is what makes the
scratch directory Paider's project root.) Paider does NOT print its working
directory when it starts — the chat banner is just "Paider / chat session
started". There is nothing on screen to confirm the cwd, so confirm it
yourself: **the `pwd` you just ran must print `/tmp/m1-rehearsal` (or
whatever `init.sh` printed) before you launch `paider chat`.** This is also
exactly what step 2's `bash m1/preflight.sh /tmp/m1-rehearsal` marker check
already verified — if that passed, you're pointed at the fixture. If your
`pwd` shows anything under `.../Projects/paider`, stop and re-run step 2
before launching — see "wrong-cwd scare" below.

## 4. Paste the task prompt

Paste the prompt from `m1/TASK.md` verbatim into the chat.

## 5. What the approval prompts will look like

Read the code before trusting your intuition here: `read_file`, `write_file`,
`patch_file`, and `git` (`app/Tools/ReadFileTool.php`,
`WriteFileTool.php`, `PatchFileTool.php`, `GitTool.php`) only ever prompt
when `SecretsGuard::isSensitive()` flags the target path (things like
`.env`, credential files). Nothing in the fixture matches that, so in a
clean run you should see **none of those four fire an approval prompt at
all** — Paider reads and writes `src/Cart.php`, `src/Receipt.php`, and the
new `src/DiscountCode.php` silently. Don't wait for a read/write prompt that
correct behavior will never produce.

| tool call | subject | what to do |
|---|---|---|
| `run_shell` | the exact shell command (e.g. `php tests/run.php`) | **This is the prompt you will actually see, probably more than once.** `run_shell` is gated unconditionally (`Loop::dispatchShell` calls `Gate::decide` for every command, no sensitive-path exception) and a model asked to add a feature to a repo with `tests/run.php` will very likely try running it to check its own work. Approve `php tests/run.php` and any other read-only command confined to the scratch copy (e.g. `git diff`, `git status`). Note `Gate::decide` keys grants by the **exact command string** — "Allow for this session" only re-authorizes that identical command again, not shell calls in general, so a slightly different command (different flags, different script) will prompt again. Deny anything that reaches outside the scratch copy or looks destructive. |
| `git` | no path, subject is just `git` (only appears if a diff/add/commit touches a path `SecretsGuard` flags — won't happen in this fixture) | Fine to allow if it appears; expect it not to. |
| `artisan` | — | **Cannot appear.** `ChatCommand::handle()` only registers the `artisan` tool `if (file_exists($this->projectRoot.'/artisan'))`, and the fixture has no `artisan` file — the tool isn't offered to the model at all, so there's nothing to deny. If you somehow see an `artisan` prompt, you are not pointed at the fixture; go back to step 3's `pwd` check. |

## 6. After Paider stops

From inside the scratch copy:

```
php tests/run.php
```

Exit code should be `0`. Then:

```
git add -A && git diff --stat --cached
```

(Comparing against the `fixture: pristine baseline` commit `init.sh` made.
**Use `git add -A` first** — plain `git diff --stat HEAD` never lists
untracked files, and the new `src/DiscountCode.php` is untracked until
staged, so skipping this step makes a correct run look like a failure.)
Check against `m1/TASK.md`'s pass/fail criteria: at least 3 files touched
including a new `src/DiscountCode.php`, and nothing unrelated.

## 7. If it goes wrong

- **Model asks to edit something outside `src/` or `tests/`** (e.g.
  `README.md`, `.git/`, anything above the fixture root) — deny it and note
  what it tried. That's a scope miss, not something to rehearse further.
- **Approval prompts feel like they're stuck in a loop** — `read_file`,
  `write_file`, and `patch_file` don't prompt at all in this fixture (see
  step 5), so a real loop here is `run_shell` re-asking for what looks like
  the same command. Check the exact command string in each prompt — `Gate`
  grants by exact string, so slightly different commands (a different flag,
  a different script path) each need their own approval; that's normal, not
  a hang.
- **Wrong-cwd scare**: if Paider proposes edits to files that look like
  `app/`, `composer.json`, or anything else that belongs to the *paider
  repo itself* rather than the fixture — **abort immediately** (deny
  everything, then Ctrl-C). You are pointed at the wrong directory. Go back
  to step 2 and re-check the marker file, and re-verify step 3's `pwd`
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
