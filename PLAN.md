# Paider — Build Plan

Written 2026-08-02, immediately after `DECISIONS.md`. That log is the constraint set for
everything below — numbers are not re-derived here, only applied. Where this plan's own
research changed the picture `DECISIONS.md` assumed, that's called out explicitly rather than
smoothed over.

---

## Thesis

**Paider is a PHP-native coding-agent CLI built on Laravel Zero, differentiated by disciplined
scope and cost-aware model routing, not by being first.** Say the honest part up front: a direct
competitor already exists — [`neuron-core/maestro`](https://github.com/neuron-core/maestro)
(38★, PHP 8.4+, `composer global require neuron-core/maestro`, built on
[`neuron-core/neuron-ai`](https://github.com/neuron-core/neuron-ai), 2,038★, active) ships
today as an interactive, repo-aware, tool-approving, MCP-capable coding agent. Its own README
calls it "the first CLI agent built in PHP." That claim predates Paider by weeks, and it's a
real, working product, not vaporware — verified via its README, releases (tagged `2.0.0`,
2026-06-19), and composer.json. **The "no PHP coding-agent CLI exists" framing from the original
research prompt is false. Do not ship a README that says it.**

What's still true, and still worth building on:

- Maestro is 38 stars after months next to its own 2,038-star underlying SDK — the "PHP
  developers want an agent CLI in PHP" thesis is *unproven*, not confirmed, even by the one
  entrant that exists.
- Maestro is a single-maintainer project structurally coupled to a commercial upsell
  ([Inspector.dev](https://inspector.dev) observability SaaS is wired into its monitoring story)
  and sits on top of a pre-1.0 stack at every layer: `neuron-ai` itself, and transitively
  `modelcontextprotocol/php-sdk` (v0.7.0, explicitly "considered experimental" per its own
  README) if/when it uses MCP client features.
- Nobody in the PHP space — Maestro included, per its public docs — treats **model economics as
  a first-class, named feature**. Aider's own `main`/`weak`/`editor` split is unlabeled and its
  fork's aliases are 1–4 generations stale (`cecli`'s `haiku` still points at an October 2024
  model). Paider already ships `config/presets.php` with four *named* tiers
  (orchestrator/coder/research/fast), verified-live model IDs and prices, and a documented
  95.3%-cheaper default (Opus 5 + `qwen3.7-flash` vs. all-Opus) — that's built, not aspirational.
- Aider died from **scope creep and abandoned stewardship**, not from a technical flaw (see
  Risks). The single most-repeated complaint mined from its own issue tracker isn't a missing
  feature — it's "where is Paul?" (#4613) and "what is the intended future of Aider?" (#4648,
  closed not-planned by the maintainer). A PHP agent that is still being maintained a year from now
  beats a 48k-star original that is ten weeks dark. Note this is about
  *stewardship*, not velocity — Jeremy's objection to an earlier draft of this
  line was that "ships slower" is not the trade being made. He is not capacity
  constrained; the discipline is in scope, not in pace.

**The honest thesis, one sentence:** Paider bets that a narrowly-scoped, cost-routed,
Laravel-native PHP coding agent beats both a stalled 48k-star original and a 38-star PHP entrant
coupled to a SaaS pitch — not because PHP needed an agent, but because an agent that lives
*inside* a Laravel app knows things an external one has to be told, and because nobody has yet
shipped one that won't rot.

**Model routing is a named feature, not a config detail.** Eleven presets ship in
`config/presets.php`, every model ID and price verified live: eight single-provider stacks, a
`balanced` default (Opus 5 to think, `qwen3.7-flash` to do — 95.3% cheaper than all-Opus), and
two open-weight stacks (`open` on kimi-k3, `open-frugal` on minimax-m3 at $0.30/$1.20 for a 1M
context orchestrator) for developers who will not send their code to a US frontier lab. That
last constituency is real and entirely unserved in PHP.

---

## Non-goals

Written to prevent the exact death aider had: 1,770 open issues and no commits in ten weeks.
Every one of these is a plausible, tempting feature. None of them ship in v1.0.

- **No web UI, no dashboard, no hosted service.** Terminal only. Maestro already pointed this at
  a commercial SaaS (Inspector); Paider does not compete on observability tooling.
- **No plugin marketplace.** An `ExtensionInterface` may exist internally (Maestro's pattern is
  worth studying), but there is no discovery/registry/store for third-party extensions in scope.
- **No multi-repo / multi-workspace orchestration.** One repo, one working directory, one
  session. If you need a monorepo tool, that's a v2+ conversation, not v1.
- **No auto-PR / GitHub-API integration.** Paider edits your working tree and commits locally.
  Opening PRs, managing CI, or talking to GitHub's API is out of scope indefinitely.
- **No support for every LLM provider on day one.** Only the presets already defined in
  `config/presets.php` — eleven of them: anthropic, openai, google, kimi, deepseek, xai, qwen,
  glm, `open`, `open-frugal`, balanced (the last two, both open-weight stacks, were added after
  this list was first written and are synced back here now) — plus a generic
  OpenRouter/OpenAI-compatible escape hatch. Provider requests get a `--base-url` override, not a
  bespoke integration.
- **No native Windows support.** WSL only — Maestro made the same call for the same reason
  (POSIX shell tool execution). State it, don't apologize for it.
- **No multi-subscription rotation.** Explicitly parked in `DECISIONS.md` §5: a Claude Max seat
  cannot be spent through the API since 2026-06-15, which breaks the one case (Anthropic) where
  Jeremy personally holds multiple seats. `config/presets.php`'s `accounts` block stays inert
  until an actual user asks for it with real API-key rotation in mind, not subscription seats.
- **No autonomous/unattended multi-day agent loops.** Every tool call that touches the
  filesystem or shell is either interactive-approved or run inside an explicit, bounded
  `--yes`-gated non-interactive session (v0.2). No background daemons, no cron-triggered agents.
- **No custom editor/IDE integration.** aider's highest-reaction unshipped requests are VSCode
  (#68, 56 reactions), Emacs (#1913, 62), PyCharm (#483, 40) — all still open after years. Don't
  start a fourth front. If MCP-server mode (v1.0) lets Claude Code or another client drive
  Paider's tools, that's the integration story; a native plugin is not.

---

## v0.1 scope

The smallest thing that is genuinely useful, shippable solo, installed the way Maestro proved
PHP devs will actually install a CLI agent: `composer global require`.

**Binary:** `paider` (rename from the Laravel Zero default `application` entry point).

Commands:

- **`paider`** (bare, default) — starts an interactive chat session rooted at the current
  working directory. Loads a context file if present, checking in order: `PAIDER.md`,
  `CLAUDE.md`, `AGENTS.md` (matches the ecosystem convention Maestro and Claude Code both use —
  don't invent a fourth filename).
- **`paider chat`** — explicit alias of the bare command, for scripts/muscle memory.
- In-session slash commands (aider's proven UX, not a Paider invention):
  `/add <file>`, `/drop <file>`, `/diff`, `/undo`, `/tier <name> <model>` (session override of a
  tier), `/quit`.
- **`paider commit`** — stages the working tree, generates a commit message on the **fast**
  tier, runs `git commit`. This is the smallest standalone feature that's useful even outside a
  full agent session and a good "does the tier router actually work" smoke test.
- **`paider config provider <preset>`** — writes the selected `config/presets.php` preset as the
  active provider stack into `.paider/settings.json` (mirrors Maestro's
  `.maestro/settings.json` pattern, same directory-scoping decision, no reason to diverge).
- **`paider config show`** — prints the resolved tier → model → price table for the active
  preset. This is a real feature, not a debug command: nobody else in this space surfaces "here
  is exactly what you're about to pay for each tier" before you start burning tokens.

Tools available to the agent loop in v0.1 (all native PHP, no MCP dependency yet — see
Architecture):

- Read file (`.gitignore`-aware, deny-list-guarded — see Architecture), write file (whole-file
  replace), patch file (unified diff apply, stamp-checked against the file at `/add` time, `php
  -l` checked after write — see Architecture)
- Run shell command — always behind an approval gate (allow-once / allow-session / deny), same
  three-state UX Maestro already validated
- `git diff`, `git add`, `git commit`
- **`ArtisanTool`** — present only when `artisan` exists at the repo root. One hardcoded call,
  `php artisan route:list --json`, exposed to the agent as a typed tool result (route, method,
  action) rather than raw shell text. This is the v0.1 proof of the Laravel-host thesis — see
  "Sequencing: the Laravel-host proof can't wait for v1.0" below.

**Explicitly deferred past v0.1:** MCP client/server support (Paider consuming or exposing tools
over the actual protocol), non-interactive `--yes` mode, repo-map/search tooling, automatic
test-runner feedback loop, and any *general* Artisan/job/model passthrough beyond the one
hardcoded `ArtisanTool` call above. All real, all v0.2+.

**Definition of done for v0.1:** Jeremy runs `composer global require` from a fresh machine,
points `paider` at a real repo, has it make a multi-file edit via the default `balanced` preset
(Opus 5 orchestrator, `qwen3.7-flash` coder), approves the diff, and commits — end to end, no
manual model wiring, no crash on a malformed diff from the coder tier, no silent apply against a
file that moved since `/add`, and no `.env` sent to a provider by accident. Pointed at a Laravel
repo, `ArtisanTool` is available and the agent can use it unprompted.

---

## Architecture

Laravel Zero is already scaffolded (`composer.json` pins `php: ^8.2`, `laravel-zero/framework:
^12.0.2`, Pest 3/4, Pint, Mockery; `box.json` exists for PHAR builds). Build inside that, don't
restructure it.

```
app/
  Commands/
    ChatCommand.php          # bare + `chat` — the REPL loop
    CommitCommand.php
    Config/
      ProviderCommand.php    # `config provider <preset>`
      ShowCommand.php        # `config show`
  Agent/
    Session.php              # holds context files, chat history, active tier map
    TierRouter.php           # resolves (operation) -> (provider, model, tier) from config/presets.php + .paider/settings.json
    Loop.php                 # the actual think -> tool-call -> apply -> observe cycle
  Providers/
    Contracts/ProviderClient.php   # send(messages, tools, model) -> response
    AnthropicClient.php
    OpenAiCompatibleClient.php     # covers openai, kimi, deepseek, xai, qwen, glm, google via OpenRouter/base-url override
  Tools/
    Contracts/Tool.php
    ReadFileTool.php          # .gitignore + deny-list guard before any content leaves the process
    WriteFileTool.php
    PatchFileTool.php         # stamp check + php -l gate before the diff is shown for approval
    ShellTool.php             # wraps approval gate
    GitTool.php
    ArtisanTool.php           # v0.1 Laravel-host proof: one hardcoded call, see below
  Approval/
    Gate.php                  # allow-once / allow-session / deny, Termwind-rendered prompt
  Rendering/
    DiffRenderer.php           # Termwind
    ChatRenderer.php            # Termwind
config/
  presets.php   # already exists — do not rewrite, extend
  app.php, commands.php  # scaffolded defaults
```

**Provider layer — deliberately NOT `prism-php/prism`.** `prism-php/prism` (2,406★) last pushed
2026-03-20 — over four months stale with 114 open issues, per DECISIONS.md and this plan's own
verification. Taking a hard dependency on a stalling package to build a project whose entire
premise is *not dying of neglect* is a contradiction. `ProviderClient` is a ~150-line interface;
Anthropic and "OpenAI-compatible" (covers OpenRouter and every non-Anthropic preset in
`presets.php`, since they're all reachable through OpenRouter-shaped endpoints) cover every
preset with two implementations. If `prism-php` gets healthy again later, swap the
implementation behind the same interface — that's what the interface is for. Don't block v0.1 on
someone else's maintenance cadence.

**MCP layer — `modelcontextprotocol/php-sdk`, scoped narrowly.** Verified state as of 2026-08-02:
v0.7.0, last commit 2026-07-27, backed jointly by the PHP Foundation and Symfony (institutional,
not solo — lower abandonment risk than it looks from stars alone), both client and server
support implemented, stdio + HTTP/streamable-HTTP transports, protocol version 2025-11-25 (
current). It is genuinely absent from `modelcontextprotocol/conformance`'s `KNOWN_SDKS` — but so
is the official Swift SDK, so this tracks with "everything pre-1.0" rather than a PHP-specific
red flag. Known open bugs worth watching: #405 (tool-schema serialization), #398 (silent
discovery failure if `symfony/finder` missing), #399 (missing resource-link blocks), #381
(request-id correlation loss). None architecture-breaking.

Decision: **v0.1 does not depend on it.** Paider's own five tools (read/write/patch/shell/git)
are native PHP, not MCP tool handlers — MCP is an interop protocol for *external* tool servers,
and v0.1 has no external servers to interop with. v0.2 adds `php-sdk` as an **MCP client only**
(consume third-party MCP servers the way Maestro's `mcp_servers` config does). v1.0 adds **MCP
server mode** (Paider exposes its own tools to Claude Code or another client) — that's the point
where being on a pre-1.0 SDK matters most, so it's deliberately the last thing added, after the
SDK has had two more quarters to mature past 0.7.

**Tier router.** `TierRouter::resolve(string $operation): array{provider, model, tier}` reads
the active preset (default `balanced`) merged with any `.paider/settings.json` session
overrides from `/tier`. Four fixed operations map to the four fixed tiers — this mapping is
intentionally not configurable per-operation in v0.1 (that's a config-surface trap, not a
feature): `plan` → orchestrator, `edit` → coder, `search`/`summarize` → research, `commit-msg`
→ fast.

**`sk-sp-` guard, in v0.1.** Before any provider call goes out, the client construction path
checks the resolved key against the resolved base URL: if the key matches `sk-sp-*` (a Qwen
Coding Plan key, see "Qwen Coding Plan" below) and the base URL does not contain
`coding.dashscope`, refuse loudly instead of sending the request. A wrong-but-valid Model Studio
base URL silently bills pay-as-you-go against a plan key — a real surprise bill, cheap to catch
with one string check, no reason to wait for v0.2.

**structured_outputs=false mitigation, concretely.** The default coder is `qwen/qwen3.7-flash`,
which reports `structured_outputs=false` (per `config/presets.php`'s own comment and
DECISIONS.md §4). `PatchFileTool` therefore never trusts a JSON tool-call payload for diff
content — it asks the coder tier for a fenced unified-diff block in prompt text, parses it with a
strict parser, and on parse failure retries once with an explicit "your last diff didn't parse,
here's why" message before escalating to the orchestrator tier to author the patch directly. This
needs a real test fixture set (malformed hunks, missing context lines, mixed line endings) before
v0.1 ships, not after a corrupted file is the bug report.

**Diff-apply staleness — the failure the parse-retry above doesn't cover.** Aider's own
post-mortem ([#3895](https://github.com/Aider-AI/aider/issues/3895)) names *context/state
mismatch* — a syntactically valid diff whose context lines no longer match because the file
changed after the model read it — as the largest failure bucket, bigger than parse failure.
Concretely: `/add <file>` stamps the file with a content hash at the moment it enters context,
held in `Session.php` alongside the chat history. Before `PatchFileTool` (or `WriteFileTool`)
writes, it re-hashes the file on disk; if the hash has moved since the stamp, it refuses to apply
and surfaces a conflict to the user (file changed since `/add` — re-add it or discard the pending
edit) instead of writing over an edit the model never saw. **Multi-file apply is all-or-nothing**,
for free: every file in one apply pass writes to its temp path first (the same atomic
write-then-rename primitive already chosen for interrupt safety, see EXTENSIONS.md), and the
renames only happen once every temp write and every stamp check in the batch has succeeded — one
bad file fails the whole apply, nothing partially lands.

**`php -l` after apply, before approval.** One more layer on the same risk: once a patch or
whole-file write lands on disk (post-stamp-check, pre-approval), Paider shells `php -l` against
every touched `.php` file. A syntax error is treated exactly like a parse failure upstream — the
write is reverted (via the undo stack below) and the coder tier gets one retry with the lint error
before escalating to the orchestrator. This is a one-liner that directly targets the
`structured_outputs=false` risk on the default coder (`qwen3.7-flash`): it catches the case where
the diff parsed cleanly but produced invalid PHP, which the parser alone can't see.

**`/undo`, designed.** It undoes the single most recently *applied* file write (whole-file or
patch) — not a turn, not a commit. `Session.php` keeps an in-memory stack of `{path, previous
bytes | null}` entries, pushed immediately before each atomic write (`null` previous means the
file didn't exist and `/undo` deletes it). `/undo` pops one entry and atomically restores it;
repeated `/undo` walks back further, one applied write at a time. It is **not git-backed** — v0.1
has no persistence layer yet (`pdo_sqlite` is v0.2, see EXTENSIONS.md), and inventing one just for
undo would be a config-surface trap for a command whose job is "get me back to before that last
edit." It is also **deliberately disconnected from `paider commit`**: undo only ever touches the
uncommitted working tree; once `commit` runs, those changes are git history and get rolled back
with ordinary git tooling (`git revert` / `git reset`), not `/undo`. If the working tree has moved
out from under the undo stack (the user hand-edited a file between an apply and an `/undo`), it
refuses and surfaces a conflict — the same stamp-mismatch path as diff-apply staleness above,
since both are "the file changed under us."

**Secrets read-guard.** Nothing currently stops `ReadFileTool` from handing `.env` or a private
key to a provider as context. Before returning file content, `ReadFileTool` checks the path
against two things: `git check-ignore <path>` (git is already a hard dependency via `GitTool`, so
this is zero new code and zero new extensions — no reason to hand-roll a `.gitignore` parser) and
a small hardcoded deny-list for the things people forget to `.gitignore` in the first place
(`.env`, `.env.*`, `*.pem`, `*.key`, `id_rsa*`, `*.p12`, `.aws/credentials`). A match refuses by
default and routes through the same Approval Gate as `ShellTool` — allow-once if the user really
means to send it, not a silent send.

**`ArtisanTool` — the v0.1 Laravel-host proof.** See "Sequencing" below for why this exists in
v0.1 at all rather than waiting for MCP server mode. It is intentionally the smallest possible
version of the claim: registered only when `artisan` exists at the repo root, it exposes exactly
one call — `php artisan route:list --json`, parsed into a typed tool result — not a general
Artisan passthrough (`ShellTool` already covers "run anything," approval-gated) and not job
dispatch or model introspection (both mutate state or need schema access this proof doesn't). The
point is narrow on purpose: prove the agent's tool surface can speak a Laravel-native concept
instead of only generic files and shell text, in v0.1, not in a v1.0 that hasn't shipped yet.

**Rendering/UX:** Termwind for diff coloring, tool-approval prompts, and the chat transcript —
already in the ecosystem's toolbox (2,492★, "Tailwind for terminal"), no reason to hand-roll
ANSI. **Testing:** Pest, already scaffolded, tagline literally targets this use case ("for PHP
developers and AI agents"). **Errors:** Collision, Laravel Zero default, keep it.

**Distribution.** ⚠️ **Superseded — see "Distribution and concurrency" below: PHAR is cut.** Left
here, unmarked-until-now, exactly per this project's convention of keeping wrong turns visible
rather than silently deleting them. Original text, for the record: "`box.json` already exists —
v0.1 ships as a PHAR via Box, installed through `composer global require` (proven install path,
matches Maestro exactly, zero reason to diverge from what already works for this exact audience).
Per DECISIONS.md §3, a shipped PHAR must not inherit a dev machine's 76-extension ini (94ms of the
143ms measured overhead) — pin the extension list in the Box build config. `static-php-cli`
/FrankenPHP static-binary distribution is a v0.2+ upgrade for users who don't want PHP installed
at all, not a v0.1 requirement." That is no longer the plan — PHAR needs PHP installed but isn't a
composer dependency, so it loses to both channels decided later in this document.

---

## Sequencing: the Laravel-host proof can't wait for v1.0

As originally milestoned, v0.1 was a standalone CLI — "Maestro, done more carefully" — while the
README called the Laravel-host angle "the thesis" and MCP *server* mode (the mechanism that would
actually make it true) sat in v1.0. That means through the entire v0.1–v0.2 window, exactly when
early adopters form an opinion of what Paider is, the stated differentiator would not exist yet.
That's backwards: a reader who tries v0.1 and finds a competent-but-generic CLI, then reads a
README calling it "the thesis," reasonably concludes the README is marketing ahead of the product
— which is the same credibility problem this project is explicitly trying not to have with the
"first PHP coding agent" claim (see Thesis).

The fix is not to build MCP server mode early — the SDK-maturity reasoning for parking that at
v1.0 (see Architecture) still holds, and pulling it forward would reintroduce the exact
pre-1.0-dependency risk it was sequenced to avoid. The fix is to ship a **token-sized proof of the
shape**, not the mechanism: `ArtisanTool` (see Architecture), one hardcoded call exposing a real
Laravel-host artifact (`route:list`) as a typed tool rather than generic shell text. It costs one
small file and doesn't touch MCP, the SDK, or the package-vs-binary distribution question at all
— Paider is still installed and run exactly as v0.1 already specifies (`composer global require`,
pointed at a repo). It proves the narrower, load-bearing half of the claim — *the tool surface can
speak Laravel's own idiom* — while the fuller claim (*any client can drive those tools over MCP*)
stays exactly where it was, in v1.0, now visibly building on a working v0.1 example instead of
starting from zero.

This displaces nothing from Non-goals: it is not a plugin marketplace, not multi-repo, not a new
provider, and it is explicitly *not* general Artisan/job/model access (that stays v0.2+, gated the
same way the rest of the tool surface is). One file, one hardcoded call, shipped now instead of
promised later.

---

## Milestones

**v0.1 — "it works on my repo"**
Definition of done: see v0.1 scope above. Installable via `composer global require` (see
"Distribution and concurrency" — PHAR is cut, this line was left stale above on purpose, don't
copy it), README includes an honest comparison table against Maestro (not a "first ever" claim).
Single-provider sessions only, interactive-only, six native tools (the usual five plus
`ArtisanTool` when run against a Laravel repo — see "Sequencing" above) — this is also where the
`sk-sp-` key/base-URL guard and the diff-apply staleness, `php -l`, `/undo`, and secrets-guard
designs above ship, not v0.2.

**v0.2 — "it doesn't need me watching it"**
- MCP client support via `modelcontextprotocol/php-sdk` (consume external tool servers)
- `paider run "<prompt>" --yes` — bounded non-interactive mode for scripting/CI, with an
  explicit allow-list of tools it may auto-approve (never unrestricted)
- Repo-map/search tool on the research tier (cheap, high-volume, exactly the tier DECISIONS.md
  named for this)
- Automatic test-runner feedback loop: after an applied edit, run a configured test command,
  feed failures back to the coder tier for N bounded retries
- `.paider/` directory consolidation, XDG-respecting config location — directly answers aider's
  own oldest unresolved high-reaction issue (#216, 79 reactions: "config file location should
  follow modern specifications") and #2860 (26 reactions, scattered dotfiles)
- Definition of done: a CI job can run `paider run` against a failing test and get a passing
  commit without a human in the loop, bounded by a retry cap and a tool allow-list.

**v1.0 — "safe to depend on"**
- MCP **server** mode: generalizes v0.1's single hardcoded `ArtisanTool` into the real thing —
  Paider exposes its own read/write/patch/shell/git/Artisan tools, and arbitrary host-app jobs and
  models, to external MCP clients (Claude Code, others) over the actual protocol — dogfoods
  `php-sdk` in both directions
- Published semver policy and an explicit, versioned non-goals doc shipped alongside the release
  (the direct antidote to aider's death: state what will never be added, in writing, so scope
  requests have a citable "no")
- Measured, published diff-apply success rate on the default coder tier (qwen3.7-flash) across a
  fixture corpus — turns the structured_outputs risk from a comment in a config file into a
  tracked number with a regression gate in CI
- `static-php-cli`/FrankenPHP static binary hardened into a real release artifact (it's already
  one of the two decided channels — see "Distribution and concurrency" — this bullet originally
  said "alongside the PHAR," which no longer exists; left corrected rather than silently rewritten
  elsewhere)
- Definition of done: Paider has shipped at least one release per quarter for a year with no
  unaddressed critical (data-loss-class) bug open longer than two weeks — the "didn't die"
  criterion, since that's the actual differentiator being bet on.

---


## The Laravel-native angle (added 2026-08-02, Jeremy's input)

Three things that arrived after the plan was drafted and change the shape of the differentiator.
Maestro has none of them.

### 1. Laravel can HOST MCP servers, not just consume them

`laravel/mcp` (788★, official, pushed 2026-07-23) exists to *"rapidly build MCP servers for your
Laravel applications."* Combined with `modelcontextprotocol/php-sdk` for the client side, a
Laravel app can be **both ends of the protocol at once**.

That is the concrete form of "belongs in the stack", and it is a thing no Python or Go agent CLI
can do for a Laravel developer:

- Paider as a **client** consumes MCP tools like any other agent.
- Paider shipped as a **Laravel package** turns the host application into an MCP server — your
  own models, jobs, queues, migrations and domain logic become tools the agent can call, defined
  in the framework's own idiom rather than hand-rolled JSON schemas.

An external agent has to be *told* about your app. An agent living inside it already knows. That
is the argument for building here rather than reaching for Go, and it is worth validating early
because the entire thesis leans on it — which is exactly why it doesn't wait for the full MCP
server in v1.0. `ArtisanTool` ships in v0.1 as the token-sized proof of this exact claim; see
"Sequencing" above.

### 2. aigate as the provider/credential layer

`aigate` already exists and already solves the boring part: AES-256-GCM key registry, machines
fetch provider credentials at runtime instead of hardcoding them, rate-limit-aware routing that
parks a throttled account and retries the next healthy one, live usage dashboard.

It is a Node service with an HTTP API and bearer auth, so the PHP side is a thin client — a
`Paider\Providers\Aigate` driver doing authenticated GETs against `$AIGATE_URL/api/keys/<provider>`.
No port, no rewrite.

**Design it as optional from day one.** Paider must work with a plain `ANTHROPIC_API_KEY` in the
environment; aigate is the upgrade for people who have many keys. If it is required, every
contributor has to stand up a Node service before they can run the thing, and the project dies
of setup friction.

### 3. Kanban board — deliberately deferred

Jeremy raised a kanban board for agent work. It is a good instinct: multi-step agent runs are
genuinely hard to follow in a scrollback, and a board is the honest UI for "what is planned, what
is running, what is blocked, what landed."

**But it is v0.3 at the earliest, and it is the single most likely thing to eat this project.**
It implies persistent state, a UI surface, and probably a web view — three new maintenance
burdens for a solo maintainer, attached to a project whose central risk is exactly that. Aider
did not die of missing features.

If it happens, the cheap version first: Termwind renders the current plan as a static board in
the terminal, backed by the same task list the orchestrator already keeps. No storage layer, no
web UI, no new dependency. Earn the full version with users asking for it.

## Risks

Ranked by (likelihood × how bad it is if it happens), not by how interesting it is to talk about.

1. **Solo maintainer, unbounded scope → dies exactly like aider.** This is the single largest
   risk and the one the whole plan is structured against. aider had 47,883 stars and 1,770 open
   issues and still went dark — stars and demand don't prevent this. *Mitigation:* the Non-goals
   section is not aspirational, it's the actual spec. Every milestone review asks "what did we
   say no to this cycle," not just "what did we ship." No feature ships without an explicit
   answer to "what does this cost to maintain forever," because forever is the actual bar aider
   failed.

2. **Competitive/positioning risk: Maestro already exists and got there first.** Confirmed via
   its own README, releases, and composer.json — not a hypothetical. It's small (38★) but real,
   and it's backed by a company with commercial incentive (Inspector.dev) to keep building it out
   as a lead-gen surface for observability. *Mitigation:* never claim "first PHP coding agent" —
   that claim is false and checkable. Differentiate on named-tier cost routing (built, not
   promised) and on maintenance discipline (a bet that pays off over quarters, not at launch).
   Consider citing Maestro directly in the README comparison table rather than pretending it
   doesn't exist — a reader who finds it independently and notices Paider hid it will trust
   Paider less, not more.

3. **`structured_outputs=false` on the default coder produces malformed diffs that corrupt
   files.** `qwen/qwen3.7-flash` reports this explicitly; it's the cheapest tier by ~77x on
   output tokens and the one running in the tightest loop, so it's also the one most exposed.
   *Mitigation:* strict diff parser + bounded retry + escalation path, a content-hash stamp check
   against the file the diff was actually written against, and a `php -l` gate after apply and
   before approval (see Architecture — all three ship in v0.1, not after); a fixture-based test
   suite covering malformed hunks *before* v0.1 ships, not discovered from a bug report; published
   success-rate metric by v1.0 (see Milestones).

4. **Dependency rot inherited from an ecosystem that's already showing cracks.**
   `prism-php/prism` stale 4.5+ months with 114 open issues, `php-mcp/server` stale a full year,
   `modelcontextprotocol/php-sdk` pre-1.0 with open serialization/silent-failure bugs (#405,
   #398, #399, #381). *Mitigation:* no hard dependency on `prism-php` (write the ~150-line
   provider interface directly, see Architecture); treat `php-sdk` as an optional interop layer
   added late (v0.2/v1.0) rather than load-bearing infrastructure from day one; pin dependency
   versions and review them every release rather than trusting `composer update` blindly.

5. **The underlying demand is unproven, not just underserved.** Maestro sitting at 38★ against
   its own framework's 2,038★ is evidence *against* "PHP developers want this," not just evidence
   that nobody's executed it well yet — the counterfactual (they'd rather run aider/Claude Code
   regardless of implementation language) hasn't been ruled out. *Mitigation:* don't overbuild
   for an audience that might not show up. v0.1's bar is "useful to one person" (Jeremy, on his
   own repos) — that's a real bar that doesn't require market validation, and it's the only bar
   v0.1 needs to clear. Revisit ambition after real usage data, not before.

6. **PHP's startup-cost ceiling gets reproduced by accident.** PHP bare is 2.3x Python's floor
   (48.6ms vs. 21.5ms) and cecli's 710ms disaster came from an import tree, not from Python
   itself — the same failure mode (a fat autoloader, an unpinned extension set) is fully
   reproducible in PHP if nobody's watching it. *Mitigation:* the 6.2ms composer-autoload budget
   and the 94ms cost of an unpinned dev-extension ini are both measured in DECISIONS.md — track
   both in CI on every release, not just at launch.

7. **Windows/WSL-only cuts off a real slice of PHP developers** (PHP has meaningfully more
   native-Windows usage than the Python/Node agent-CLI audience does). *Mitigation:* it's a
   stated, deliberate non-goal shared with Maestro, not silence — revisit only if WSL friction
   shows up as a repeated real complaint, not preemptively.

---

## Open questions

Things that need Jeremy's call, not a default guess:

1. **README positioning against Maestro.** Cite it directly in a comparison table, or avoid
   naming a 38-star project at all? Both are defensible; silence risks looking evasive once
   someone finds Maestro independently, naming it risks "punching down" optics. Your call.

2. **Provider abstraction long-term.** Hand-rolled `ProviderClient` per Architecture, or revisit
   `prism-php` if it un-stalls? Set a review trigger (e.g., "reconsider if prism-php ships a
   release in the next two quarters") rather than leaving it open-ended.

3. **License.** aider is Apache-2.0; Laravel Zero itself ships MIT (see its own `composer.json`,
   already in this repo). No strong technical reason to diverge from MIT, but it's your call for
   an OSS project you want contributions on.

4. **Repo hosting: GitHub vs. Forgejo.** Your global default is Forgejo (`git.shoemoney.ai`) for
   new repos. But every comparison metric in this plan and in `DECISIONS.md` (stars, forks, issue
   activity, `KNOWN_SDKS` visibility) is a GitHub-native signal, and Packagist auto-discovery
   assumes a GitHub (or GitLab) origin for update webhooks. Mirroring Forgejo → GitHub is
   possible but is itself an ongoing maintenance surface — worth deciding deliberately rather
   than defaulting silently either way, given this project explicitly can't afford surprise
   maintenance load.

5. **`accounts` preset in `config/presets.php`: keep as inert config, or delete until the
   underlying hypothesis is validated?** It's already correctly marked as a parked hypothesis
   with the blocking fact documented inline (Claude Max seats can't be spent through the API).
   Leaving it in the shipped config surface risks a user trying it and hitting the wall
   DECISIONS.md already predicted. Either delete it from the file until there's a real
   API-key-rotation use case, or gate it behind an explicit `--experimental` flag that prints the
   caveat before it can be selected.

6. **paider.dev scope.** Docs-only, or also a hosted cost calculator built off the numbers
   already in `config/presets.php`? Cheap to build, genuinely useful, but it's a second surface
   to maintain forever — same "does this need to exist" filter as everything else in this plan.


---

## Distribution and concurrency — decided 2026-08-02

### Two channels, not three

| channel | user | why |
|---|---|---|
| `composer require paider/paider` | Laravel dev, agent inside the app | **the thesis requires it** — a compiled binary cannot be a package dependency, and turning your models into tools means being one |
| FrankenPHP embed binary | anyone wanting the CLI standalone | no PHP install, extensions pinned at build |

**PHAR is cut.** It requires PHP installed yet is not a composer dependency, so it is strictly
worse than `composer global require` for the only audience it could have had. Every extra
distribution channel is permanent maintenance for a solo maintainer — see Risks.

[FrankenPHP](https://github.com/php/frankenphp) (11,263★, Go, on Caddy, pushed 2026-07-29)
embeds PHP in a self-executable and
[explicitly supports CLI](https://frankenphp.dev/docs/embed/): `./my-app php-cli bin/console`.
It builds the extension set from `composer.json`, which is the fix for the measured problem —
76 extensions on a dev box cost 94ms of a 143ms startup, and a shipped tool must never inherit
that. `static-php-cli` (1,917★) is the fallback if FrankenPHP proves awkward.

**Unverified:** binary size and cold-start of a FrankenPHP-embedded CLI. Embedding a PHP runtime
is tens of megabytes, and startup must be measured against the 95.9ms Laravel Zero baseline
before this is promised anywhere public. If the binary starts slower than the scaffold, the
whole rationale weakens.

### Concurrency: Fibers, not Swoole

Where an agent actually needs concurrency is all I/O — parallel tool calls, fanning out to
sub-agents, racing providers on failover, several MCP servers at once.

- **Octane is the wrong pattern.** It is a long-running HTTP server; a CLI is one process, one task.
- **Swoole is not needed and costs the distribution story.** It is an extension, so every user
  installs it, which contradicts the single-binary goal.
- **PHP has shipped native Fibers since 8.1**, and `curl_multi` is built in — measured 6 concurrent HTTP
  requests in 71ms with zero extensions. Amp v3 and ReactPHP sit on Fibers if a real scheduler
  is wanted.

Revisit Swoole only if profiling demands it, which for an I/O-bound agent is unlikely.


---

## Provider layer — simpler than expected (verified 2026-08-02)

Every provider that matters speaks **OpenAI-compatible HTTP**, so v0.1 needs one client and a
base URL, not a driver per vendor:

| provider | endpoint | notes |
|---|---|---|
| Moonshot / Kimi | `https://api.moonshot.ai/v1` | OpenAI-compatible |
| Alibaba / Qwen | `.../compatible-mode/v1` | **and** an Anthropic-compatible endpoint at `.../apps/anthropic`; regions incl. US-Virginia |
| Anthropic | `https://api.anthropic.com` | its own shape |
| OpenRouter | `https://openrouter.ai/api/v1` | aggregator over all of the above |

That collapses most of the provider abstraction into configuration. Good news for a solo
maintainer, and it means adding a vendor is usually a preset entry rather than code.

**But do not build OpenRouter-only.** `kimi-k2.7-code-highspeed` — the variant Moonshot
recommends *"when you need higher output speed"*, which is precisely what the coder tier wants —
is **not listed on OpenRouter** and is reachable only through the direct endpoint. Aggregators
lag, omit variants, and add a hop. Direct provider endpoints have to be a first-class path, with
the aggregator as one provider among several rather than the substrate.

**Subscriptions, corrected:** unlike Claude Max seats (which cannot be spent through the API —
see §5), Qwen and Kimi plans issue real API keys against these endpoints. So a user holding
those plans can actually spend them from Paider. Worth confirming per-plan whether it is
flat-rate or prepaid credit before documenting anything about it.

---

## Qwen Coding Plan — verified 2026-08-02, and it changes the default

Reported to be "virtually unlimited". It is not.

| | |
|---|---|
| Price | **$50/month** |
| Quota | 6,000 req / 5h · 45,000 / week · **90,000 / month** |
| Exhaustion | calls **fail** — no fallback to pay-as-you-go |
| Cost of a task | their docs: simple ~5–10 requests, complex **30+** |

Three findings that affect Paider directly:

**1. `qwen3.7-flash` is not on the plan.** The allowlist is exact-string and the docs warn
"do not infer version compatibility." Included: `qwen3-coder-next`, `qwen3-coder-plus`,
`qwen3.7-plus`, `qwen3.6-plus`, `qwen3.5-plus`, `qwen3-max-2026-01-23`, plus third-party
`kimi-k2.5`, `glm-5`, `MiniMax-M2.5`, `glm-4.7`.

So the `balanced` default — Opus 5 to think, `qwen3.7-flash` to do — **cannot run on a Coding
Plan**. A plan holder needs a different preset. That is a real gap: add a `qwen-plan` preset
built only from allowlisted models, and have `paider config provider` warn when a selected model
is not on the user's plan.

**2. Wrong key silently bills pay-as-you-go.** Plan keys are prefixed `sk-sp-` and require a
base URL containing `coding.dashscope`. Using the general Model Studio key and URL works fine
and charges separately. **Paider should detect an `sk-sp-` key against a non-coding base URL and
refuse, loudly.** Cheap to implement, saves a real bill, and no other tool does it.

**3. ToS constraint.** "API keys are for interactive coding tools (not scripts or batch calls)
and for personal use only. Account sharing may result in suspension." Paider is an interactive
coding tool, so it qualifies — but nothing in the docs may encourage batch or unattended use of
a Coding Plan key, and the account-rotation hypothesis in §5 is explicitly off the table for
this provider.

**Correction to the earlier subscription picture:** Qwen plan keys are real API keys, so they
are spendable from Paider — but only within a request quota, only for allowlisted models, and
only through the plan-specific endpoint. "Subscriptions just work" was too simple.

---

## Fable's review — accepted findings, 2026-08-02

An adversarial read of these docs. The load-bearing criticisms, accepted:

**1. Sequencing risk, ranked above generic scope creep.** PLAN.md's v0.1 builds a standalone CLI —
which is "Maestro, done more carefully". The README calls the Laravel-host package "the thesis",
but MCP *server* mode is scheduled for **v1.0**. So through the entire v0.1–v0.2 window, when
early adopters form an opinion, the actual differentiator does not exist while the README claims
it already does. **Fix: ship a token-sized proof of the Laravel-host angle in v0.1** — one
hardcoded tool exposing a host-app job is enough — or stop calling the package "the thesis" until
it is true.

**Resolved 2026-08-02:** shipped the first option. `ArtisanTool` (one hardcoded `route:list` call)
added to v0.1 scope and Architecture; see the new "Sequencing" section above and the updated v0.1
Milestone entry.

**2. PHAR contradiction is live in this file.** The Architecture and Milestones sections still say
"v0.1 ships as a PHAR"; the Distribution section says "PHAR is cut". Both are present, unmarked.
A reader top-to-bottom cannot tell which is current. **The earlier text is superseded** — see
"Distribution and concurrency". Left visible per this project's convention rather than deleted.

**Resolved 2026-08-02:** the Architecture and Milestones passages are now explicitly labelled
superseded, pointing at "Distribution and concurrency," with the original wording kept intact
underneath rather than deleted.

**3. Factual error, corrected.** "PHP 8.5 ships native Fibers" was wrong — Fibers landed in
**8.1**, and `composer.json` pins `^8.2`, so they are available at our floor already.

**Resolved** — already correct in the "Concurrency: Fibers, not Swoole" section above; this entry
is the record of the correction, not an open item.

**4. Non-goals lists 9 presets; `config/presets.php` has 11.** `open` and `open-frugal` were added
later and never synced back.

**Resolved 2026-08-02:** Non-goals now lists all eleven, with `open`/`open-frugal` called out as
the two added late.

**5. The cost ledger is not a moat.** It is arithmetic on data we already hold — a competitor
copies it with four config keys and a `GROUP BY tier`. It remains a genuinely good feature and a
falsifiability mechanism. **Stop calling it a moat.** The only real moats here are the
Laravel-host integration (structurally hard for a Python/Go tool to copy) and outlasting Maestro,
which is unverifiable until it is already true.

**6. Diff-apply: the dominant failure mode is undesigned.** From aider's own post-mortem
([#3895](https://github.com/Aider-AI/aider/issues/3895)), the largest bucket is *context/state
mismatch* — a syntactically valid diff whose context no longer matches because the file changed
after the model read it. Our `PatchFileTool` mitigation only covers **parse** failure.
**Fix: stamp files with a hash or mtime at `/add` time and refuse to apply against a moved stamp**,
surfacing a conflict instead of a silent corrupt write.

**Resolved 2026-08-02:** designed in Architecture ("Diff-apply staleness") — hash stamp at `/add`,
re-check before write, conflict surfaced, and all-or-nothing multi-file apply spelled out using
the existing atomic-write primitive.

**7. Other v0.1 gaps.** Partial multi-file apply is undefined (atomic writes already give
all-or-nothing nearly free — state it). `/undo` is in the command list with zero design. No
syntax check: `php -l` after apply, before showing the diff, is a one-liner and directly targets
the `structured_outputs=false` risk. **Secrets:** nothing stops `ReadFileTool` sending `.env` or
private keys to a provider — a `.gitignore`-aware read guard is needed before v0.1, not after.

**Resolved 2026-08-02:** all four designed in Architecture — multi-file all-or-nothing stated
alongside the staleness fix, `/undo` given a real spec (in-memory session stack, not git-backed,
explicitly decoupled from `paider commit`), `php -l` gate added to the apply pipeline, and a
`git check-ignore` + deny-list read guard added to `ReadFileTool`.

**8. FrankenPHP CLI embed is younger than it looks.** CLI embedding landed via PR #1561/#1632 with
the clean fix punted to a future PHP version; a maintainer estimated 20–30MB for the CLI binary
*before* the app and Caddy. PLAN.md hedges this correctly as unverified — **README does not**, and
already advertises `curl -fsSL paider.dev/install | sh` as settled. That is the live over-claim.

**Resolved 2026-08-02:** README's Distribution section now carries the same unverified-size/
cold-start caveat PLAN.md already had, plus the PR #1561/#1632 and 20–30MB detail, instead of
presenting the curl install as settled.

**On the Qwen Coding Plan:** breakeven is ~59 sessions/month against the $0.85 default, so the
plan suits an audience that already pays for it rather than the one the cheap default attracts.
Worse, **every allowlisted model is in the family Jeremy already rejected by hand** — "not smart
enough to orchestrate, not fast enough to code." A `qwen-plan` preset is v0.2 and must say so
honestly. The `sk-sp-` wrong-base-URL guard, however, is cheap and should ship now.

**Resolved 2026-08-02:** the `sk-sp-` guard moved into v0.1 scope and Architecture (Tier
router/provider construction). The `qwen-plan` preset itself stays v0.2, unchanged, and is not
what shipped here.

---

## Console/TUI library research — 2026-08-02, and it corrects three decisions

Researched on the `research` tier (haiku), which is the routing thesis doing its own job.

### Correction 1: Termwind cannot stream. laravel/prompts is the UI layer.

`nunomaduro/termwind` (2,492★, pushed 2026-07-22) has **no streaming, partial-redraw or
live-update mechanism** — it renders once. The README and PLAN have been naming it as the TUI
layer, which is wrong for a tool whose main output is a streaming LLM response.

**`laravel/prompts` (721★, pushed 2026-08-02 — today) is the actual answer**, and it is better
than expected:

- `stream()` — append chunk-by-chunk, then `close()`
- `task()` with `$logger->partial($chunk)` / `commitPartial()` — spinner plus a scrolling log
- every input type needed: `confirm`, `select`, `multiselect`, `text`, `search`, `suggest`
- **non-TTY detection built in** via `stream_isatty(STDIN)`, falling back to Symfony's Question
  Helper — which matters for CI and piped use
- standalone: no Laravel proper required
- needs only `mbstring`

Termwind stays, demoted: static styled output and tables, not the streaming path.

### Correction 2: cutting pcntl costs an animated spinner

`laravel/prompts` uses **PCNTL for spinner animation** and "degrades gracefully to a static
version without it". We cut `pcntl` (see EXTENSIONS.md) on the grounds that `proc_open` and
`stream_isatty` are ext-standard and atomic writes beat SIGINT trapping. That reasoning holds —
but the cut has a **cosmetic cost that was not known when it was made**: a static spinner instead
of an animated one.

**Decision: the cut stands.** A non-animated spinner is not worth a Unix-only extension and a
split Windows build. Recorded so nobody rediscovers it as a bug.

### Correction 3: Windows is a real problem, and distribution assumed otherwise

`laravel/prompts` **explicitly does not support native Windows PHP — WSL only.** The distribution
plan promises a FrankenPHP binary as though it were cross-platform. It cannot be, for the
interactive path, unless the UI layer changes.

**Unresolved.** Options: ship Windows as WSL-only and say so plainly; fall back to Symfony
Question Helper on Windows and accept a worse experience; or defer Windows entirely. This needs
deciding before anything is promised, and it is not currently in the Risks section.

### Full-screen TUI: blocked, and that is fine

`php-tui` (605★, a Ratatui port, pushed 2026-05-04) is the only credible full-screen
alternate-buffer option and it **requires `ext-intl`**, which is not on the approved list. Adding
intl for a UI we already argued we do not need would be exactly backwards. Confirms the earlier
call: streaming text, a spinner, a diff and a confirm — not a reactive pane layout.

### Diffs

| library | ★ | pushed | role |
|---|---|---|---|
| `sebastianbergmann/diff` | 7,656 | 2026-07-30 | diff engine, no highlighting — what PHPUnit uses |
| `jfcherng/php-diff` | 483 | 2026-07-20 | full diffs incl. side-by-side/unified/inline, with colour |
| `tempest/highlight` | 697 | 2026-06-29 | syntax highlighting, terminal and web |

Recommendation: `jfcherng/php-diff` for structure plus `tempest/highlight` for colour. No
extensions beyond standard. All three actively maintained.

### Resulting UI stack

1. **`laravel/prompts`** — streaming output and all interactive input
2. **`jfcherng/php-diff` + `tempest/highlight`** — the approval diff
3. **`termwind`** — static styled output, tables
4. **`symfony/console`** — `ConsoleSectionOutput` as the low-level redraw escape hatch

Zero unapproved extensions.
