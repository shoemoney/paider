# Paider Terminal UI Design Review

## Overview

This review examines Paider's terminal output against core UX criteria: information
hierarchy, first-time user experience, error messages, color semantics, accessibility, and
responsive layout. The **render-once limitation** noted in PLAN.md:1831 is intentionally
excluded as already-documented; this review addresses only new findings.

**Captures** are produced by `design/capture.sh`, committed alongside this document — re-run
it any time `app/` output changes and re-review against fresh files. Do not hand-edit a
`.ans` file. The script:

- allocates a **real pty** via `script` and resizes it with `stty cols 80` / `stty cols 120`
  before running the command — not just `COLUMNS=`, which an app never queries via ioctl
  doesn't see. `app/` and `config/` contain no reference to `COLUMNS`, `tput`, or
  `TIOCGWINSZ` (checked by grep), so **the app is architecturally width-invariant**: it
  never reads terminal width at all. `cmp` on every 80-vs-120 pair in `design/captures/`
  confirms this empirically too — every pair is byte-identical.
- produces the `-nocolor` captures with `NO_COLOR=1` actually exported and **no** `--ansi`
  flag.
- produces the `-piped` captures with a plain `./paider X > file` redirect — no pty, no
  `--ansi`.
- strips the `^D^H^H` (+ optional CR LF) artifact that macOS `script` echoes before the
  child's real first byte, so every line number cited below matches the file on disk.

---

## Findings, Ranked by Impact

### 1. `NO_COLOR` is respected for color, but Termwind's bold table headers leak through it and through piping regardless

**File:** `design/captures/cost-80col-nocolor.ans`, `design/captures/cost-80col-piped.ans`,
`design/captures/list-80col-nocolor.ans`
**Severity:** Medium (accessibility) — narrower than it first looks; corrected from an
earlier draft of this review that claimed `NO_COLOR` was ignored outright. That claim was
wrong and is retracted here; the paragraph below is the checked replacement.

**Verified, corrected claim:** `NO_COLOR` genuinely works for `list`, `config:provider`, and
the error box — controlled A/B, no `--ansi`:

```
$ script -q /dev/null ./paider list          | grep -c '<ESC>' lines → 7
$ NO_COLOR=1 script -q /dev/null ./paider list | grep -c '<ESC>' lines → 0
```
(`design/captures/list-80col.ans` vs. `design/captures/list-80col-nocolor.ans` — 0 escape
bytes anywhere in the nocolor file, confirmed both by line-count and by raw escape-byte
count.)

**What actually leaks:** `cost` and `config:show` render their tables through Termwind's
`<table><th>` bold styling, and that bold styling is **not** gated by the same
decorated/NO_COLOR check the rest of the app uses. Same escape-byte count (12 occurrences,
6 `\e[1m`/6 `\e[0m` pairs) whether colored, `NO_COLOR=1`, or plainly piped with no pty and no
`--ansi` at all:

| capture | ESC occurrences |
|---|---|
| `cost-80col.ans` (color) | 12 |
| `cost-80col-nocolor.ans` (`NO_COLOR=1`) | 12 |
| `cost-80col-piped.ans` (piped, no tty, no `--ansi`) | 12 |
| `list-80col-nocolor.ans` (`NO_COLOR=1`, no table) | 0 |

Same pattern reproduces on `config:show` under `NO_COLOR=1` (checked but not committed as a
capture — 6 escape bytes, 3 header cells × bold-on/bold-off).

This also resolves what was a separate, self-contradicting "piped output" finding in an
earlier draft: piping degrades correctly for `list` (0 escapes) but not for the two
table-rendering commands, where 12 escapes survive a plain redirect with no pty at all.

**Proposed Fix:** Find wherever `cost`/`config:show` build the `<table>` HTML for
`Termwind\render()` (`app/Commands/CostCommand.php`, `app/Commands/Config/ShowCommand.php`)
and make the bold `<th>` styling go through the same decorated-output check the rest of the
app already uses correctly (likely `Termwind::renderUsing()`'s output resolution, or a
manual `NO_COLOR`/`isatty` guard before emitting the `<table>` block).

**Scope:** Cheap and obviously right — the surrounding code proves the gate exists and
works elsewhere; this is applying it consistently, not building it from scratch.

---

### 2. Error message does not say what to do next

**File:** `design/captures/bad-cmd-80col.ans`, lines 1-3
**Severity:** High (first-time UX)

Actual output:
```
  No arguments expected, got "notarealcommand".
```
(red box, `design/captures/bad-cmd-80col.ans:2`)

A user who types `./paider notarealcommand` (a common mistake) gets a red error box stating
the problem but offering zero guidance. No mention of `./paider list`, no suggestion to run
`--help`, no pointer to documentation.

**Comparison:** `./paider --help` shows chat-command help (not app-level commands) — see
Finding 3. This is a compound problem: `--help` doesn't surface the command list, and errors
don't redirect the user.

**Proposed Fix:**
```
No arguments expected, got "notarealcommand".

Run `paider list` to see all commands, or `paider --help` for options.
```

**Scope:** Needs a product decision — does the app want to be chatbot-first (`--help` shows
chat) or command-list-first? If chatbot-first, errors must say "run `paider list` if you
meant a different command." If command-list-first, `--help` should show the app-level
command table (not chat-specific help).

---

### 3. `./paider --help` is scoped to the chat command; app-level commands are not discoverable

**File:** `design/captures/help-80col.ans`, lines 1-5
**Severity:** Medium (first-time UX)

Actual output:
```
Description:
  Start an interactive Paider chat session in the current project.

Usage:
  chat
```

A new user expecting `--help` to list all commands (the universal pattern) instead sees
chat-specific help. The commands (`list`, `cost`, `commit`, `config:*`) are hidden and only
visible via `./paider list`.

**Contrast with `./paider list`** (`design/captures/list-80col.ans`, lines 3-10), which
clearly shows:
```
USAGE:  <command> [options] [arguments]

chat            Start an interactive Paider chat session...
commit          Stage all changes and commit...
cost            Show token/spend usage per tier...
config:provider Set the active provider preset...
config:show     Show the active preset...
```

This is the **first thing a new user should see** — not help for one subcommand.

**Proposed Fix:**
Option A (Recommended): Detect when `--help` is run with no subcommand and show the
app-level command list (same as `list`), not the chat help.
Option B: Mention in the chat help that `paider list` shows all commands.

**Scope:** Needs a product decision — chatbot-first (chat is default) or command-list-first
(app overview first)?

---

### 4. Cost table does not reflow at different column widths — confirmed width-invariant, not assumed

**File:** `design/captures/cost-80col.ans` vs. `design/captures/cost-120col.ans`
**Severity:** Medium (responsive design)

Both files are byte-identical (`cmp` exit 0, run by `design/capture.sh`'s own
width-invariance check) — and this time the two captures were genuinely taken at different
pty widths (`stty cols 80` / `stty cols 120` inside a real `script` pty, not just a
`COLUMNS=` env var that an app reading raw terminal ioctl would never see). Grepping `app/`
and `config/` for `COLUMNS`, `tput`, and `TIOCGWINSZ` returns nothing — the renderer never
asks the terminal how wide it is, at any width, for any command. Every 80-vs-120 pair in
`design/captures/` (`help`, `list`, `config-show`, `cost`, `bad-cmd`, `cost-nodb`) is
byte-identical for the same reason.

```
|  tier          |  calls  |  tokens in  |  tokens out  |  spend   |  share   |
|  orchestrator  |  1      |  61.2k      |  19.8k       |  $0.801  |  100.0%  |
```

At 80 columns, this table (79 chars wide) fits snugly. At 120 columns, it uses the exact
same layout with 41 chars of unused whitespace on the right.

**Proposed Fix:** Measure terminal width (`Symfony\Component\Console\Terminal::getWidth()`
is already a transitive dependency via Laravel Zero) and dynamically adjust column widths,
or accept fixed-width tables as a deliberate simplicity trade-off and say so in the README.

**Scope:** Needs a product decision — is responsive layout a goal? If not, this is a
non-finding and can be closed as intentional.

---

### 5. Cost table summary line is buried at the bottom; total spend is not the most visually prominent element

**File:** `design/captures/cost-80col.ans`, line 8 (file is 8 lines total)
**Severity:** Medium (information hierarchy)

The most important number (total spend: $0.801) appears in the table's "spend" column,
third row (line 5). The summary line at the very bottom of the 8-line file (line 8) reads:
```
0.0% of your tokens went through tiers costing 0.0% of your spend.
```

This is confusing and not the headline. The user's eye should land on **total spend** first,
followed by a breakdown by tier. Instead, the layout is:
1. Table with spend buried in a column (line 5)
2. Cryptic summary line at the very bottom (line 8)

**Expected mental model:** "How much have I spent?" → $0.801 (immediate).
**Actual:** Scan the table, parse the row structure, then read a summary that talks about
percentages and tier distribution.

**Proposed Fix:**
```
Total spend: $0.801 | Tokens: 81.0k in → 19.8k out | Saving vs. open models: ~67%

+---+ tier table +---+
```

**Scope:** Cheap and obviously right — one summary line with the headline number before the
table.

---

### 6. Share column is empty for the session row; confusing UX

**File:** `design/captures/cost-80col.ans`, line 5
**Severity:** Low (clarity)

```
|  orchestrator  |  1      |  61.2k      |  19.8k       |  $0.801  |  100.0%  |
|  session       |         |  61.2k      |  19.8k       |  $0.801  |          |
```

The "share" column shows `100.0%` for orchestrator but blank for the `session` row (line 5).
Visually, it reads as "incomplete data" or "missing field." In fact, `session` is the
parent-tier aggregate, not a tier that incurred spend on its own, so it has no share — the
code (`app/Commands/CostCommand.php`) already renders `—` for a per-tier row whose share is
genuinely unknown, but the aggregate row's blank calls/share cells read the same as that
"unknown" state to someone who hasn't read the source.

**Proposed Fix:** Either label the aggregate row distinctly (e.g. a horizontal rule or
"total" label instead of the bare word "session"), or use "–" consistently instead of blank
for any non-spend cell so blank never has to be interpreted twice.

**Scope:** Cheap and obviously right — use "–" instead of blank for the aggregate row's
calls/share cells.

---

### 7. `config:show` table alignment allows model names to overflow column width

**File:** `design/captures/config-show-80col.ans`, lines 3-10
**Severity:** Low (polish)

```
|  tier          |  model                       |  price            |
|  orchestrator  |  anthropic/claude-opus-5     |  $5.00 /  $25.00  |
|  coder         |  qwen/qwen3.7-flash          |  $0.03 /   $0.13  |
|  research      |  deepseek/deepseek-v4-flash  |  $0.14 /   $0.28  |
```

The model names fit here, but in a narrower terminal or with longer model IDs, they could
overflow — the table has no max-width or truncation logic (consistent with Finding 4: the
renderer sizes to content, never to terminal width).

**Proposed Fix:** Truncate model names with an ellipsis if they exceed a fixed column
budget, e.g. `qwen/qwen3.7-f...`.

**Scope:** Cheap and obviously right — add a max-width guard to the model column.

---

## Summary by Scope

### Cheap and Obviously Right
1. **Table bold-header escape leak (Finding 1)** — gate `<th>` bold styling through the same
   decorated/`NO_COLOR` check `list` and the error box already use correctly.
2. **Cost summary header (Finding 5)** — add total spend above the table.
3. **Aggregate row clarity (Finding 6)** — use "–" instead of blank, or label the row.
4. **Model name truncation (Finding 7)** — add max-width to `config:show`'s model column.
5. **Error message next steps (Finding 2)** — append a suggestion to run `paider list`.

### Needs a Product Decision
1. **Help scoping (Finding 3)** — should `--help` show app-level commands or chat-specific
   help?
2. **Responsive layout (Finding 4)** — should tables adapt to terminal width, or is a fixed
   layout an accepted simplicity trade-off?

---

## Accessibility Notes

- **`NO_COLOR`:** Works correctly for `list`, `config:provider`, and the error box — verified
  by a controlled pty A/B (7 escape-bearing lines without, 0 with). Does **not** work for the
  bold table headers in `cost` and `config:show` (Finding 1) — same escape-byte count
  regardless of `NO_COLOR` or TTY status.
- **Piping (non-TTY, no `--ansi`):** `list` degrades correctly to zero escape bytes. `cost`
  retains 12 escape bytes for its table headers even with no pty and no `--ansi` flag at all
  — the same defect as the `NO_COLOR` gap above, not a separate issue (an earlier draft of
  this review reported these as two findings that contradicted each other; they are one
  finding, fixed at the same source).
- **High contrast:** Color choices (green for commands, yellow for headers, red for errors)
  are readable at default terminal contrast.
- **Missing data indication:** the `session` aggregate row's blank cells need a "–" or a
  distinct label for clarity (Finding 6, minor).

---

## Conclusion

The TUI is clean and readable at 80–120 columns, and colored output degrades correctly for
piping and `NO_COLOR` in most commands. The real issues are:
1. **Accessibility gap, narrower than first assumed:** table bold-header codes bypass both
   `NO_COLOR` and TTY detection in `cost` and `config:show` — everything else in the app gets
   this right.
2. **First-time UX:** error messages don't guide users, and `--help` doesn't show the command
   list.
3. **Polish:** summary line buried, table layout doesn't use available width (may be
   intentional), aggregate row's blank cells unclear.

Fixing the five items under "Cheap and Obviously Right" addresses most of the concrete
problems here without requiring a product conversation. The two product-decision items
(help scoping, responsive layout) can follow if desired.
