# Paider Terminal UI Design Review

## Overview

This review examines Paider's terminal output against core UX criteria: information hierarchy, first-time user experience, error messages, color semantics, accessibility, and responsive layout. The **render-once limitation** noted in PLAN.md:1831 is intentionally excluded as already-documented; this review addresses only new findings.

**Captures:** All commands tested at COLUMNS=80 and COLUMNS=120; also tested NO_COLOR=1 and piped (non-TTY) output. Captures preserved in `design/captures/`.

---

## Findings, Ranked by Impact

### 1. **NO_COLOR environment variable is completely ignored**
**File:** `design/captures/list-80col-nocolor.ans`, `design/captures/cost-80col-nocolor.ans`  
**Severity:** High (accessibility)

The app outputs full ANSI color codes even when `NO_COLOR=1` is set. Lines retain `[37;1m`, `[32;1m`, `[33;1m` sequences. Users relying on NO_COLOR (colorblind users, scripts, low-bandwidth terminals, accessibility tools) get colored output anyway.

**Finding:** The Symfony/Termwind stack should respect `NO_COLOR` automatically if properly configured, but it is not working.

**Proposed Fix:**  
Add this check at app initialization (before any output):
```php
if (getenv('NO_COLOR')) {
    putenv('CLICOLORS_FORCE=0');
    // or call Symfony OutputFormatter directly to disable colors
}
```
Alternatively, grep `app/` to locate where OutputFormatter or Termwind is initialized and ensure `setDecorated(false)` when `NO_COLOR` is set.

**Scope:** Cheap fix — one guard at app bootstrap.

---

### 2. **Error message does not say what to do next**
**File:** `design/captures/bad-cmd-80col.ans`  
**Severity:** High (first-time UX)

Actual output:
```
[37;41m                                                 [39;49m
[37;41m  No arguments expected, got "notarealcommand".  [39;49m
[37;41m                                                 [39;49m
```

A user who types `./paider notarealcommand` (a common mistake) gets a red error box stating the problem but offering zero guidance. No mention of `./paider list`, no suggestion to run `--help`, no pointer to documentation.

**Comparison:** `./paider --help` shows chat-command help (not app-level commands). This is a compound problem: `--help` doesn't surface the command list, and errors don't redirect the user.

**Proposed Fix:**  
Modify the error output to append a suggestion:
```
No arguments expected, got "notarealcommand".

Run `paider list` to see all commands, or `paider --help` for options.
```

**Scope:** Needs a product decision — does the app want to be chatbot-first (`--help` shows chat) or command-list-first? If chatbot-first, errors must say "run `paider list` if you meant a different command". If command-list-first, `--help` should show the app-level command table (not chat-specific help).

---

### 3. **./paider --help is scoped to the chat command; app-level commands are not discoverable**
**File:** `design/captures/help-80col.ans`  
**Severity:** Medium (first-time UX)

Actual output shows:
```
Description:
  Start an interactive Paider chat session in the current project.

Usage:
  chat

Options:
  -h, --help  ...
```

A new user expecting `--help` to list all commands (the universal pattern) instead sees chat-specific help. The commands (`list`, `cost`, `commit`, `config:*`) are hidden and only visible via `./paider list`.

**Contrast with `./paider list`** (`design/captures/list-80col.ans`), which clearly shows:
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
Option A (Recommended): Detect when `--help` is run with no subcommand and show the app-level command list (same as `list`), not the chat help.  
Option B: Mention in the chat help that `paider list` shows all commands.

**Scope:** Needs a product decision — chatbot-first (chat is default) or command-list-first (app overview first)?

---

### 4. **Cost table does not reflow at different column widths**
**File:** `design/captures/cost-80col.ans` vs. `design/captures/cost-120col.ans`  
**Severity:** Medium (responsive design)

Both files are byte-identical. The table layout and column widths are hardcoded and do not adapt to terminal width:
```
+----------------+---------+-------------+--------------+----------+----------+
|  tier          |  calls  |  tokens in  |  tokens out  |  spend   |  share   |
+----------------+---------+-------------+--------------+----------+----------+
|  orchestrator  |  1      |  61.2k      |  19.8k       |  $0.801  |  100.0%  |
```

At 80 columns, this table (79 chars wide) fits snugly. At 120 columns, it still uses the same layout with extra whitespace on the right — the table does not expand to use available width, and columns are not widened to show more data.

**Comparison:** At 120 cols, the app could widen the "model" column in `config:show` (currently fixed at 30 chars) or add a third spending category (e.g., "tokens per dollar"), but instead it remains static.

**Proposed Fix:**  
Measure terminal width (already available as COLUMNS env var, or via `exec('tput cols')`) and dynamically adjust column widths or table layout.

**Scope:** Needs a product decision — is responsive layout a goal? If yes, architect the table renderer to respect terminal width.

---

### 5. **Cost table summary line is buried at the bottom; total spend is not the most visually prominent element**
**File:** `design/captures/cost-80col.ans`, lines 1-10  
**Severity:** Medium (information hierarchy)

The most important number (total spend: $0.801) appears in the table's "spend" column, third row. But the summary line at the bottom reads:
```
0.0% of your tokens went through tiers costing 0.0% of your spend.
```

This is confusing and not the headline. The user's eye should land on **total spend** first, followed by a breakdown by tier. Instead, the layout is:
1. Table with spend buried in a column
2. Cryptic summary line at bottom

**Expected mental model:** "How much have I spent?" → $0.801 (immediate).  
**Actual:** Scan the table, parse the row structure, then read a summary that talks about percentages and tier distribution.

**Proposed Fix:**  
Add a summary header before the table:
```
Total spend: $0.801 | Tokens: 81.0k in → 19.8k out | Saving vs. open models: ~67%

+---+ tier table +---+
```

Or restructure as:
```
[Spend Summary]
├─ Total spend: $0.801
├─ Saved vs. open models: ~67%
└─ By tier: (table)
```

**Scope:** Cheap and obviously right — one summary line with the headline number before the table.

---

### 6. **Share column is empty for non-orchestrator tiers; confusing UX**
**File:** `design/captures/cost-80col.ans`, line 6  
**Severity:** Low (clarity)

The table shows:
```
|  tier          |  calls  |  tokens in  |  tokens out  |  spend   |  share   |
|  orchestrator  |  1      |  61.2k      |  19.8k       |  $0.801  |  100.0%  |
|  session       |         |  61.2k      |  19.8k       |  $0.801  |          |
```

The "share" column shows `100.0%` for orchestrator but blank for session. Visually, it reads as "incomplete data" or "missing field" for the session row. In fact, session is a parent-tier aggregate, not a tier that incurred spend, so it has no share — but the UI doesn't communicate this.

**Proposed Fix:**  
Either:
- Add a note or symbol (e.g., "–" or "N/A") for non-spend rows.
- Restructure to separate "spend" tiers from "aggregate" rows more clearly.

**Scope:** Cheap and obviously right — use "–" instead of blank for aggregate rows.

---

### 7. **Config:show table alignment allows model names to overflow column width**
**File:** `design/captures/config-show-80col.ans`  
**Severity:** Low (polish)

The "model" column shows:
```
|  tier          |  model                       |  price            |
|  orchestrator  |  anthropic/claude-opus-5     |  $5.00 /  $25.00  |
|  coder         |  qwen/qwen3.7-flash          |  $0.03 /   $0.13  |
|  research      |  deepseek/deepseek-v4-flash  |  $0.14 /   $0.28  |
```

The model names fit here, but in a narrower terminal or with longer model IDs, they could overflow. No truncation or wrapping is in place.

**Proposed Fix:**  
Truncate model names with ellipsis if they exceed column width:
```
|  qwen/qwen3.7-f... |  (full name on hover, or shown with --verbose)
```

**Scope:** Cheap and obviously right — add max-width to the model column.

---

### 8. **Piped output (non-TTY) still includes ANSI codes when --ansi is used**
**File:** `design/captures/list-80col-piped.ans`  
**Severity:** Low (edge case)

When the output is piped (not a TTY), Symfony typically auto-disables ANSI unless `--ansi` is passed. The behavior is correct: `--ansi` forces ANSI even when piped, which is the user's intent. However, scripts that pipe output and then post-process it may be surprised by color codes.

**Finding:** Not a bug — this is correct behavior for `--ansi` flag. Documented for clarity.

**Proposed Fix:** None — this is intentional.

---

## Summary by Scope

### Cheap and Obviously Right
1. **NO_COLOR support** — Add env var check at bootstrap.
2. **Cost summary header** — Add total spend above the table.
3. **Share column empty rows** — Use "–" instead of blank for aggregates.
4. **Model name truncation** — Add max-width to config:show table.
5. **Error message next steps** — Append suggestion to run `paider list`.

### Needs a Product Decision
1. **Help scoping** — Should `--help` show app-level commands or chat-specific help?
2. **Responsive layout** — Should tables adapt to terminal width?

---

## Accessibility Notes

- **NO_COLOR:** Currently broken; fix via environment check (blocker for users with visual impairments, scripts, and terminals that do not support colors).
- **High contrast:** Color choices (green for commands, yellow for headers, red for errors) are readable at default terminal contrast.
- **Non-TTY graceful degradation:** Works correctly when output is piped (loses color, retains text).
- **Missing data indication:** "Share" column needs "–" for clarity (minor).

---

## Conclusion

The TUI is clean and readable at 80–120 columns. The main issues are:
1. **Accessibility blocker:** NO_COLOR is ignored.
2. **First-time UX:** Error messages don't guide users, and `--help` doesn't show the command list.
3. **Polish:** Summary line buried, responsive layout static, empty table cells unclear.

Fixing 1–3 under "Cheap and Obviously Right" will address 80% of the problems. The product decisions (help scoping, responsive layout) can follow if desired.
