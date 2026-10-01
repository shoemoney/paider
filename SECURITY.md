# Security

Paider reads and writes your project, runs shell commands, and spawns processes on your behalf.
This file states the trust model in one place: what is enforced, where the enforcement lives, and
— just as importantly — **what is not enforced**.

Everything below was read out of the code and checked against the tests. Where a guarantee is
claimed here, a test asserts it. Where there is a gap, it is in [Known gaps](#known-gaps) rather
than buried, because a security document that lists only strengths is marketing.

Findings that have been fixed are listed as fixed, with the commit. The reasoning is in
`DECISIONS.md`.

---

## The one rule

Most of this design follows from a single distinction, stated in `app/Storage/ProjectEnv.php`:

> A repository ships its own `.paider/.env`, so any setting reachable through `ProjectEnv::get()`
> is a setting the REPOSITORY controls. **Convenience settings may live in a project file;
> permissions may not.**

So anything that grants authority — auto-approval, the SSRF allowlist, the MCP config path, the
embedding endpoint, the database URL — is read from the **real process environment** only, via
`ProjectEnv::fromEnvironment()`, which is a thin `getenv()`. A cloned repository cannot write to
the shell you typed the command in.

This is not theoretical. Before `ProjectEnv` existed, a `.paider/.env` containing
`PAIDER_YOLO=1` made every command auto-approve with no flag and no prompt, and
`PAIDER_FETCH_ALLOW` opened a chosen private address. The rule exists because that was true.

Two consequences worth stating plainly:

- **`--yolo` / `PAIDER_YOLO=1` is a real setting and it is announced.** A session that has stopped
  asking says so, in a badge (`app/Commands/ChatCommand.php`), because `PAIDER_YOLO` in a shell
  profile is a setting it is very easy to forget you set.
- **A project-shipped `test_command` is a permission, not a preference.** It used to be treated as
  a preference and ran with the gate bypassed. Fixed — see [Known gaps](#known-gaps).

---

## What is enforced

| Boundary | Where | Asserted by |
|---|---|---|
| **File reads/writes/patches stay inside the project root** | `app/Support/PathGuard.php` — lexical `..` normalisation, sibling-prefix check, `realpath()` of the deepest *existing* segment, NUL-byte rejection, dangling-symlink walk-past | `PathGuardTest` |
| **The model cannot forge an approval** | Approval is a PHP-typed parameter (`Tool::execute(array $input, bool $approved = false)`), not a key in the model-supplied `$input` array. `Loop::dispatch()` `unset()`s the internal fields before the tool ever sees them | `DECISIONS.md` §15, §18 |
| **No project-shipped file can grant authority** | `ProjectEnv::fromEnvironment()` for `PAIDER_YOLO`, `PAIDER_FETCH_ALLOW`, `PAIDER_EMBEDDING_URL`, `PAIDER_MCP_CONFIG`, `PAIDER_DATABASE_URL` | `ProjectSelfAuthorizationTest` — behavioural **and** a source-grep invariant |
| **A repo-shipped `mcp.json` is refused, not ignored** | `McpClient::configPath()` only ever builds a path from `$PAIDER_MCP_CONFIG` or `~/.paider/`. `$projectRoot` is never an input to it. A refusal notice is **rendered**, not just returned | `McpClientTest` — a real `{"command":"/bin/sh","args":["-c","touch …/PWNED"]}` config, asserting `PWNED` does not exist |
| **A repo-shipped `test_command` is gated** | `SettingsStore::testCommandSource()` returns provenance; `Loop::runPostPatchTests()` skips the gate only for `operator_authored` | `PostPatchTestFeedbackTest` — a real `touch …/PWNED`, asserting the file never appears |
| **Skills load from `~/.paider/skills` only** | `SkillLibrary::HOME_RELATIVE` is the sole discovery root; project-local `.paider/skills` and `.claude/skills` are refused **unconditionally**, with no env knob to suppress | `SkillLibraryTest` — including a source-grep proving no project-readable knob exists in that class |
| **The Postgres skill index cannot be reached from a project path** | `VettedItems` has a **private constructor**; the only factory is `afterRefusalCheck()`. `LibraryIndex` has one public writer, and it takes the value object. Capability-shaped refusal, not location-shaped | `LibraryIndexTest` — asserts the API surface by reflection, so the check cannot be quietly bypassed by adding a method |
| **`fetch_url` cannot reach the LAN** | `app/Support/UrlGuard.php`: every resolved answer must be public, IPv4-mapped IPv6 unwrapped, CGNAT `100.64/10` explicit, credentials-in-URL refused, per-hop re-guard, redirects followed **by hand**, `CURLOPT_RESOLVE` pinning, 256 KB / 15 s caps | `FetchUrlToolTest` — 19 tests |
| **No subprocess inherits provider keys** | `ShellEnv::build()` is a 7-name allowlist. An allowlist can only be too narrow, which fails safe | `SecretsGuardTest` — tokenises every `app/**/*.php`, finds every real `proc_open`, and asserts `ShellEnv::build()` is in its argument list. Guards the **invariant**, so a fourth call site cannot be added quietly |
| **Secrets do not reach the log via tool calls or messages** | `ProseStream::scrubSecrets()` (10 named vars) + `scrubSecretPatterns()` (credential shapes), applied to conversation messages and recursively to tool input **including array keys** | `ToolCallSecretScrubbingTest` — 8 tests, including negative ones proving the redactor does not eat ordinary logs |
| **The event log cannot be SQL-injected or forge a session id** | `json_encode(..., JSON_THROW_ON_ERROR)` validated before any write; bound parameters throughout; `session_id` stamped by `EventLog` itself, overwriting any caller value. Append-only is structural — no update or delete method exists | `EventLogTest` |
| **An unpriced model is not a free model** | `ModelPricing::costFor()` returns `null` and the ledger keeps that `null`, so the cost report *surfaces* the unknown rather than printing `$0.00`. Budgets meter the reference-model price instead of zero | `ModelPricingTest`, `CostCommandTest`, `RagStoreTest`, `ReviewerAgentTest` |

---

## What the gate does and does not cover

`Gate` answers exactly one question: **did a human say yes.** It is deliberately narrow, and the
guards that decide what is *reachable at all* — `PathGuard`, `UrlGuard`, `SecretsGuard` — run
outside it and are unaffected by `--yolo`.

| Action | Prompted? |
|---|---|
| `run_shell`, `artisan`, `fetch_url` | **Every call** |
| MCP tool invocations | **Every call** (both transports gate on the PHP `$approved` parameter, never a model-supplied key) |
| A repo-shipped `test_command` | **Every call** (was: bypassed) |
| `read_file`, `write_file`, `patch_file`, `git add`/`commit`/`diff` | **Only when `SecretsGuard::isSensitive()` matches the path.** An ordinary file is read or written with no prompt |
| `remember`, `load_skill` | Never |

That middle row is the one to internalise: **ordinary file writes are not prompted.** The gate
protects secrets-shaped paths, not your source files. If you want a prompt on every write, do not
rely on this tool.

The approval prompt itself is terminal-sanitised (`TerminalSafe::clean()`), because the subject is
model-controlled — an injected erase-line sequence could otherwise repaint the label *after* the
human reads it, so they approve a different command than the one that runs.

Session grants are keyed on the exact subject string, and non-string commands/URLs fail closed
**before** the gate is consulted — otherwise a grant cached on `''` would authorise everything.

---

## Known gaps

These are real, currently true, and not fixed. They are listed because a reader deciding whether to
clone-and-run deserves to know them.

### 🔴 High

- **Ordinary file writes are ungated** (above). A model can rewrite any non-secret file in your
  project with no prompt. This is a deliberate design choice — prompting on every edit would make
  the tool unusable — but it means the blast radius of a prompt injection is your working tree.

### 🟠 Medium

- **`ShellEnv` is not a sandbox.** It stops a subprocess inheriting provider *keys*. It does not
  stop an approved command reading `~/.netrc`, a config file, or a keychain, or making network
  calls. `DECISIONS.md` §17 says this in as many words; it is repeated here because it is the most
  misread thing in the codebase.
- **There is no `run_shell` command allowlist.** None. `run_shell` executes any string a human
  approves. (An `ArtisanTool` docblock once implied otherwise; corrected.)
- **`git add` / `git commit` are ungated and not `SecretsGuard`-checked.** A non-gitignored secret
  can be staged with no prompt. `argv`-array invocation, so no shell injection, and git refuses
  paths outside the worktree.
- **`remember` values reach the log unscrubbed** (`app/Tools/MemoryTool.php`) and are re-injected
  as a system message on every future turn. A credential the model is told to remember is stored
  verbatim in `.paider/paider.db`.
- **`test_run` output is logged unscrubbed** (`app/Agent/Loop.php`). Test output is
  attacker-shaped whenever the command is.
- **`PAIDER_MCPD_URL` and the Aigate key registry apply no `UrlGuard`** — no private-IP check, no
  DNS pinning, unlike `fetch_url`. Both are operator-set, so this is opt-in rather than a hole.
- **`ShellTool`'s timeout cannot reap a double-forked or backgrounded child.** The whole timeout
  window is the exposure. Documented in the tool.
- **`PAIDER.md` / `CLAUDE.md` / `AGENTS.md` from the project root are injected as a `system` message
  with no refusal and no provenance label** (`app/Agent/Session.php`). This is intentional agent
  behaviour, but it is a project-controlled prompt surface treated *differently* from skills, which
  are refused outright. Worth knowing when reasoning about what a cloned repo can say to the model.
- **`SecretsGuard::isGitIgnored()` fails open** — on a missing git binary or a non-repo it returns
  `false` and falls back to a 6-entry deny-list that does not cover most real secrets.

### 🟡 Low

- **`SecretsGuard` matches the bare substring `aigate` anywhere in an absolute path**, which is
  over-broad and will prompt on legitimate paths.
- **`McpdClient` and `Aigate` follow no redirect policy beyond `allow_redirects => false`.**

---

## Reporting a vulnerability

Open a GitHub issue, or email the maintainer. Please include a reproduction. If you have found
something that reaches code execution from a cloned repository, that is exactly the class of bug
this file exists to make visible — see the `test_command` entry above for what the last one looked
like and what it took to find it.

## What is not covered here

`DECISIONS.md` §15, §17 and §18 record the narrative of the security work. It does **not** record
the `UrlGuard`/SSRF design, the `ProjectEnv` rule, the `mcp.json` refusal, the `SkillLibrary`
home-only rule, the `LibraryIndex`/`VettedItems` construction, the `ProseStream` redaction, or
`TerminalSafe`. Those live in code and tests only, and **this file cites the code as the
authority** for them rather than pointing at a decision log that does not mention them.
