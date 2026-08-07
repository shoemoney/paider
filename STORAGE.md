# Storage

**One SQLite file. No services.** Paider persists through a single database reached via
`pdo_sqlite`, one of the twelve compiled extensions.

```
.paider/paider.db        # project-scoped: ✅ events (append-only), cost ledger (projection),
                         #                 ✅ sessions (session_* events)
                         # gitignored: the event log accumulates all calls and is local-only
                         # planned v0.2+: memory, response cache
                         # planned v0.3+: task board
.paider/.env             # ✅ project-scoped settings, read by ProjectEnv (gitignored with .paider/)
<project>/.env           # ✅ also read, lower precedence than .paider/.env
~/.paider/paider.db      # planned v0.2: credentials (AES-256-GCM via openssl), global preferences
```

## Configuration

**Nothing user-specific is hardcoded.** Settings are read by `App\Storage\ProjectEnv`, highest
precedence first:

1. a real environment variable — `PAIDER_RESUME_MESSAGES=10 paider chat` always wins
2. `<project>/.paider/.env`
3. `<project>/.env`

This exists because Laravel Zero boots with `basePath` set to Paider's **install** directory, so
its built-in `env()` reads a `.env` sitting next to the installation and never looks at the project
you are working in. Anyone following "put it in your .env" was configuring nothing.

Values are parsed array-backed and are **never** exported via `putenv()`/`$_ENV` — otherwise every
project setting would leak into every subprocess `ShellTool` spawns, undoing the environment
scrubbing in [`DECISIONS.md` §17](DECISIONS.md).

| variable | default | effect |
|---|---|---|

| `PAIDER_RESUME_MESSAGES` | `50` | How many stored messages a resume replays. `0` disables resume. |
| `PAIDER_MEMORY_LIMIT` | `100` | How many durable facts ride in the system prompt. Oldest are trimmed first. |

| `NO_COLOR` | unset | Any non-empty value disables all colour output, per [no-color.org](https://no-color.org). Read via `ProjectEnv::get()` so a real environment variable wins over a project `.env`, matching the standard. |
| `PAIDER_COLOR` | unset (auto-detect) | Explicit override, wins over everything including `NO_COLOR` — but only as a **real** environment variable; a `.paider/.env`-sourced `PAIDER_COLOR=1` cannot override the operator's own real `NO_COLOR`, or a cloned repo could silently turn colour back on for someone who disabled it system-wide. Falsy forces plain output, truthy forces colour even when piped — our `FORCE_COLOR` equivalent. Unset runs the normal detection ladder (`Palette::enabled()`). |
| `PAIDER_THEME` | unset (respect the terminal) | Bundled theme name (`dracula`, `gruvbox-dark`, `solarized-light`, `high-contrast`) resolving every `ColorRole` to that theme's absolute colours instead of the default symbolic ANSI-16 slots. Unknown name falls back silently to the default. |

Truthy values are `1`, `true`, `on`, `yes` in any case; anything unrecognised **fails closed**.

### Authority settings — real environment ONLY, never a project file

These two are **not** read from `.paider/.env` or `.env`. They come from the process environment
you exported in your own shell, and nowhere else:

| variable | default | effect |
|---|---|---|
| `PAIDER_YOLO` | `0` | Approve every action without asking. Cannot bypass `PathGuard` or `UrlGuard`. |
| `PAIDER_FETCH_ALLOW` | *(empty)* | Comma-separated hostnames `fetch_url` may reach even though they resolve to a private address — your own Forgejo, a local SearXNG. |

```bash
PAIDER_YOLO=1 paider chat          # works
echo 'PAIDER_YOLO=1' > .paider/.env && paider chat   # deliberately does NOT
```

**Why, and it is not a style preference.** A repository ships its own `.paider/.env`. Anything
reachable through the project-scoped path is therefore a setting *the repository controls*. When
`PAIDER_YOLO` was readable that way — as it was, briefly — a hostile repo could carry two lines and
grant itself unprompted `run_shell` against anyone who cloned it and ran Paider inside, plus a
named private address punched through `UrlGuard`. That is `git clone` to remote code execution,
authorised by the code under review to itself. Reproduced, then fixed.

A project may state **preferences**. It may not grant itself **permissions**. `ProjectEnv::
fromEnvironment()` is the seam that enforces the split, and `ProjectSelfAuthorizationTest` pins it —
including a source-grep invariant so a later tidy-up that routes these back through
`ProjectEnv::get()` fails loudly.

### Provider configuration — API keys and endpoints

Every command (`chat`, `commit`) routes to the same provider endpoint for a given preset, resolved by
`App\Providers\ProviderResolver`. A preset like `kimi` will use its direct endpoint **only** when
its own API key is set; otherwise both commands fall back to OpenRouter. This prevents the same
preset from silently switching endpoints and billing accounts between sessions.

| variable | effect |
|---|---|
| `OPENROUTER_API_KEY` | Fallback for any preset without a dedicated key; also enables the `openai`, `google`, `open`, `open-frugal`, and `balanced` presets. |
| `ANTHROPIC_API_KEY` | Enables the `anthropic` preset. Read by `AnthropicClient` itself, not the resolver, and routed to `api.anthropic.com` regardless of `OPENROUTER_API_KEY`. |
| `MOONSHOT_API_KEY` | Enables the `kimi` preset; routes directly to `https://api.moonshot.ai/v1` when set, otherwise falls back to OpenRouter. |
| `DEEPSEEK_API_KEY` | Enables the `deepseek` preset; routes directly to `https://api.deepseek.com` when set, otherwise falls back to OpenRouter. |
| `XAI_API_KEY` | Enables the `xai` preset; routes directly to `https://api.x.ai/v1` when set, otherwise falls back to OpenRouter. |
| `GLM_API_KEY` | Enables the `glm` preset; routes directly to `https://open.bigmodel.cn/api/paas/v4` when set, otherwise falls back to OpenRouter. |
| `DASHSCOPE_API_KEY` | Enables the `qwen` preset. Routes to pay-as-you-go PAYG endpoint by default. |
| `DASHSCOPE_PLAN_BASE_URL` | **Required** if using a Coding Plan key (`sk-sp-*`) with qwen. Must point to your region's plan-exclusive base URL; e.g., `https://token-plan.<region>.maas.aliyuncs.com/compatible-mode/v1`. Without this, a plan key falls back to OpenRouter (with a warning) or throws if OpenRouter key is also unset. Copy this from your Dashscope plan console's "Plan Exclusive Base URL" panel. |

**Routing logic:** Both `chat` and `commit` commands consult the same resolver. For a given preset:
1. If it's `anthropic`, use `AnthropicClient` with `ANTHROPIC_API_KEY`. There is no fallback — an unset key raises rather than quietly billing a different vendor.
2. If it's `qwen`, check `DASHSCOPE_API_KEY` and its plan URL if the key looks like a plan key (`sk-sp-*`)
3. If the preset has a direct endpoint and its own key is set, use that endpoint
4. Otherwise, fall back to OpenRouter with `OPENROUTER_API_KEY`

### Skills — fixed to `~/.paider/skills`, no override at all

Not in the table above because there is nothing to configure: `App\Skills\SkillLibrary` discovers
skills from exactly one path and there is no env var, `.paider/.env` key, or flag that changes it.

A skill is instructions loaded from disk and injected into the prompt — the same class of problem
as `PAIDER_YOLO` one step further along. `<project>/.paider/skills` and `<project>/.claude/skills`
are therefore **refused unconditionally**, with one printed line so the user knows skills were
present and skipped rather than silently believing their project skills loaded. There is
deliberately no knob that re-enables them, project-scoped or otherwise: a project-readable one
would just be the `.paider/.env` hole one layer down — a repository able to say "load my skills
anyway" grants itself standing instructions the moment someone clones it and runs Paider inside.

## Colour

Every colour Paider emits is named by meaning, never by hex or SGR code, through `App\Support\Palette`
and its `ColorRole` enum (`Accent`, `Muted`, `Success`, `Error`, `Alert`, `Brand`). This exists because
`Banner.php` once hardcoded a gradient that ran through bright-black (`SGR 1;30`) — charcoal on a dark
terminal's charcoal background. The charcoal rule: a role may colour primary content only with a
symbolic ANSI-16 slot (the user's own theme chose it to be readable) or an absolute colour checked
against *that theme's own* background polarity — relative luminance ≤0.30 clears ≥3:1 against a
light background, ≥0.10 clears ≥3:1 against a dark one. `Muted` is the sole exemption, because its
contract is "safe to lose entirely" — meaningful content in that role is a bug by definition. See
`Palette`'s class docblock and the luminance test in `PaletteTest` for the check.

`Palette::enabled()` is the single capability gate (real `PAIDER_COLOR` env > real `NO_COLOR` env >
file-sourced `PAIDER_COLOR` > file-sourced `NO_COLOR` > `TERM=dumb` > non-TTY stdout > on). Truecolor
is never emitted by the default palette; the only absolute defaults are 256-colour (`Brand`, theme
overrides), each degrading to no colour at all unless `TERM` contains
`256color` or `COLORTERM` is non-empty.

## Sessions survive process exit

Every turn is appended as a `session_message` event and replayed on the next run in the same
project. Two deliberate asymmetries:

- **The system message is never replayed.** `Session`'s constructor re-derives it from
  `PAIDER.md`/`CLAUDE.md`/`AGENTS.md` on every run, so replaying a stored copy would post it twice
  and pin a stale version of a file that may have been edited since.
- **Context files store the path, not the content.** They are re-read from disk on resume, so an
  edit between runs is picked up and `PatchFileTool`'s sha256 stamp describes what is actually
  there. A stored copy would hand it a stamp that can never match, and no patch would apply.

## Memory

The `remember` tool records durable project facts — conventions, decisions, where things live —
which are replayed into the system prompt of every later session as their own message, separate
from the tool protocol so a wrong fact can never corrupt the tool-call contract. They are labelled
to the model as context, **not** as instructions.

`remember` is the one tool with no approval prompt. Every gated tool acts *outside* the project;
this one appends a row to the project's own gitignored database and nothing else. Prompting for it
would train the user to approve reflexively, which is how a real approval prompt stops being read.

Values are capped at 2 KB because every stored fact is re-sent on every turn of every future
session — an unbounded value is a permanent recurring cost, not a one-off.

## Skills

`load_skill` fetches the full body of one skill from `~/.paider/skills/<name>/SKILL.md`, by name,
chosen by the model from an index (`name` + truncated `description`) `Loop` injects as its own
system message — separate from the memory block and the tool protocol, same reasoning as Memory
above: author-written prose that turns out wrong must never be able to corrupt the tool-call
contract. Only sent at all when at least one skill was found, so a user with none pays zero prompt
tokens for an empty catalogue or a tool doc for a tool that would do nothing.

Every loaded body is stamped with a provenance header before the model sees it: a skill is a
*procedure the model asked for*, not an instruction from the operator, and the header says so in
those words — it cannot grant approval (`run_shell`/`fetch_url` still hit `Gate::decide()` no
matter what a skill's own text claims) and it cannot change the tool-call format. It also names
which of the four capability categories a corpus census measured (subagents, MCP, browser,
artifacts) the body references, since Paider has none of them and a step that assumes one will
just fail rather than silently no-op.

`load_skill` is ungated, same reasoning as `remember`: it reads one file under the user's own home
directory and nothing else — no shell, no network, no write.

## Reaching your own infrastructure

`fetch_url` refuses anything resolving off the public internet, because a fetch tool inside an
agent loop is a request generator a prompt-injected model gets to aim, and this process runs on a
network with a git host, a vault and a NAS answering to anyone who can reach them.

Self-hosted infrastructure legitimately lives on those addresses, so `PAIDER_FETCH_ALLOW` names
hosts you vouch for:

```
PAIDER_FETCH_ALLOW=git.example.com,searx.example.com
```

Scoped deliberately:

- **Per host, never a blanket "allow private".** Allowlisting your git server does not open the NAS.
- **Re-checked on every redirect hop.** An allowlisted host answering `302 -> http://192.168.1.99/`
  is still refused; otherwise one entry could bounce the agent anywhere on the LAN.
- **Exact, case-insensitive match. No wildcards, no suffix matching** — `str_ends_with($host,
  'example.com')` would also match `notexample.com`. List each subdomain you need.
- Everything else still applies: http/https only, no credentials in the URL, connections pinned to
  the validated IPs.

## Response-cache accounting, decided before anything caches

The wiring is deliberately split from the semantics, because the obvious implementation is wrong
in a way that looks right. `ModelPricing::costFor()` returns `null` for a 0-in/0-out/0-cache call —
all four at zero means the usage block was never parsed, which is *unknown*, not a real `$0.00`
(LOCKED decision #3). A cache hit makes no provider call, so the naive event carries four zeros and
the saving it was meant to prove **evaporates as `null` on every hit**. The ledger's flagship claim
is that it reconciles against provider-reported usage; getting this wrong makes it lie in the
direction of its own marketing.

`CacheLedger::recordHit()` is therefore the only supported way to record one, and it prices the
hit from the **original response's** token counts — what the call *would* have cost.

A hit contributes to savings and nothing else. It never touches `calls`, `spend_usd`, or any token
column, and savings are **never** applied as a discount to spend. An unpriced model's saving is
recorded as unknown (`cache_unpriced_hits`), never as a confident zero.

**Privacy consequence, stated plainly:** anything that reached a prompt now outlives the process.
`.paider/` is gitignored, but the conversation is on disk until the file is deleted. Set
`PAIDER_RESUME_MESSAGES=0` to keep the old behaviour where it died at exit.

**Confirmed under the FrankenPHP embed binary** (measured 2026-08-02 — see
[`DECISIONS.md` §8](DECISIONS.md)): an in-memory `pdo_sqlite` create/insert/select round-trip
works correctly under the static binary, not just under system PHP. **Re-confirmed under the
trimmed 12-extension static build** (round 2, same date — see [`DECISIONS.md` §9](DECISIONS.md)):
same round-trip, same result, so trimming 77 extensions down to 12 does not disturb `pdo_sqlite`.
The binary is otherwise unrelated to this design — see [`README.md`](README.md#distribution) for
the size numbers.

**v0.1 scope: event log and cost ledger.** The two tables above marked ✅ are implemented.
The rest (marked ⬜) are planned for v0.2+. This design document describes the full
chosen architecture, not just the v0.1 slice.

## What lives there

| concern | shape | state | notes |
|---|---|---|---|
| **Events** | append-only log, JSON payload per row | ✅ **v0.1** | all tier_calls, tool results, approvals; the source of truth for the cost ledger |
| **Cost ledger** | per-tier token and spend accounting | ✅ **v0.1** | pure projection over events, never mutable; see below — this is a feature, not plumbing |
| **Sessions** | conversation history, files in context | ✅ **v0.2** | `session_*` events, projected by `SessionStore`; resumable across runs, survives a reboot |
| **Memory** | durable project facts worth carrying between sessions | ✅ **v0.2** | `memory_set`/`memory_retract` events, projected by `MemoryStore`; the thing that makes the second run smarter than the first |
| **Response cache** | Laravel's `database` cache driver, same file | 🟡 **v0.2** | *semantics done, wiring deferred* — `CacheLedger` defines what a hit records; nothing records one yet |
| **Credentials** | AES-256-GCM blobs via `openssl` | ⬜ **v0.2** | no Node service, no keyring dependency; user-scoped in `~/.paider/paider.db` |
| **Task board** | plan items and their state | ⬜ **v0.3** | what the v0.3 terminal kanban renders from |

## Why SQLite and not Redis

Redis was pencilled in at v0.3 for cache, rate-limit parking and kanban state. On reflection it
earns none of those at this scale.

- It is a **daemon the user has to install and keep running**, against a tool whose entire
  distribution argument is "one binary, nothing to set up."
- Its **durability story is worse** for exactly the data we care about. Losing a conversation to
  an unflushed AOF is a bad day; SQLite is an fsync'd file.
- The workload is **single-user and single-process**. Redis's advantages are concurrency and
  cross-process sharing, neither of which exists here.

**Redis is now unplanned.** It gets reconsidered only with a written concurrency justification —
several agents sharing rate-limit state, or a team deployment. Both are speculative, and
speculative features are what the Non-goals section exists to refuse.

The same reasoning covers **libSQL / Turso**. SQLite itself has no network layer at all — it is a
library linked into the process, so "SQLite with websockets" is really libSQL's Hrana protocol,
a separate product with a server, auth and a network dependency. If multiple agents ever need to
share live state, that is the moment to look at it. Not before.

## The cost ledger deserves its own note

Nobody in this space tracks spend per *tier*. Paider names four — orchestrator, coder, research,
fast — so it can answer questions no other agent CLI can:

> Research burned 1.8M tokens for $0.23 while the orchestrator spent $4.10 on 61k.
> That ratio is the product working.

It also makes the routing claims falsifiable. The README asserts the default stack is 95.3%
cheaper than all-Opus; the ledger is what proves or disproves that on real work rather than a
modelled session.

**Watch out when implementing:** `total_cost_usd` semantics differ by source. In Claude Code's
`--output-format json` it is per-turn; under `stream-json` it is **cumulative for the session**,
so summing across events over-reports by roughly the turn count. That is filed upstream as
[`anthropics/claude-code#83239`](https://github.com/anthropics/claude-code/issues/83239). Detect
the semantics from monotonicity rather than assuming either:

```php
$cumulative = $seq === array_values(array_unique($seq))   // strictly increasing
    && end($seq) > reset($seq);
$total = $cumulative ? end($seq) : array_sum($seq);
```

## Vector search, if it ever comes up

[`sqlite-vec`](https://github.com/asg017/sqlite-vec) is an extension to **SQLite**, not to PHP —
so embeddings could live in the same file with no additional PHP extension and no vector
database. **Unverified** whether it loads through PDO in a statically linked build; check before
promising it anywhere. Worth knowing it exists before anyone reaches for Pinecone.
