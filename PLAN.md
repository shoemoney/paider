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
  `config/presets.php` (anthropic, openai, google, kimi, deepseek, xai, qwen, glm, balanced) plus
  a generic OpenRouter/OpenAI-compatible escape hatch. Provider requests get a `--base-url`
  override, not a bespoke integration.
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

- Read file, write file (whole-file replace), patch file (unified diff apply)
- Run shell command — always behind an approval gate (allow-once / allow-session / deny), same
  three-state UX Maestro already validated
- `git diff`, `git add`, `git commit`

**Explicitly deferred past v0.1:** MCP client/server support, non-interactive `--yes` mode,
repo-map/search tooling, automatic test-runner feedback loop. All real, all v0.2.

**Definition of done for v0.1:** Jeremy runs `composer global require` from a fresh machine,
points `paider` at a real repo, has it make a multi-file edit via the default `balanced` preset
(Opus 5 orchestrator, `qwen3.7-flash` coder), approves the diff, and commits — end to end, no
manual model wiring, no crash on a malformed diff from the coder tier.

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
    ReadFileTool.php
    WriteFileTool.php
    PatchFileTool.php
    ShellTool.php             # wraps approval gate
    GitTool.php
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

**structured_outputs=false mitigation, concretely.** The default coder is `qwen/qwen3.7-flash`,
which reports `structured_outputs=false` (per `config/presets.php`'s own comment and
DECISIONS.md §4). `PatchFileTool` therefore never trusts a JSON tool-call payload for diff
content — it asks the coder tier for a fenced unified-diff block in prompt text, parses it with a
strict parser, and on parse failure retries once with an explicit "your last diff didn't parse,
here's why" message before escalating to the orchestrator tier to author the patch directly. This
needs a real test fixture set (malformed hunks, missing context lines, mixed line endings) before
v0.1 ships, not after a corrupted file is the bug report.

**Rendering/UX:** Termwind for diff coloring, tool-approval prompts, and the chat transcript —
already in the ecosystem's toolbox (2,492★, "Tailwind for terminal"), no reason to hand-roll
ANSI. **Testing:** Pest, already scaffolded, tagline literally targets this use case ("for PHP
developers and AI agents"). **Errors:** Collision, Laravel Zero default, keep it.

**Distribution.** `box.json` already exists — v0.1 ships as a PHAR via Box, installed through
`composer global require` (proven install path, matches Maestro exactly, zero reason to diverge
from what already works for this exact audience). Per DECISIONS.md §3, a shipped PHAR must not
inherit a dev machine's 76-extension ini (94ms of the 143ms measured overhead) — pin the
extension list in the Box build config. `static-php-cli`/FrankenPHP static-binary distribution is
a v0.2+ upgrade for users who don't want PHP installed at all, not a v0.1 requirement.

---

## Milestones

**v0.1 — "it works on my repo"**
Definition of done: see v0.1 scope above. Ships as a PHAR, installable via
`composer global require`, README includes an honest comparison table against Maestro (not a
"first ever" claim). Single-provider sessions only, interactive-only, five native tools.

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
- MCP **server** mode: Paider exposes its own read/write/patch/shell/git tools to external MCP
  clients (Claude Code, others) — dogfoods `php-sdk` in both directions
- Published semver policy and an explicit, versioned non-goals doc shipped alongside the release
  (the direct antidote to aider's death: state what will never be added, in writing, so scope
  requests have a citable "no")
- Measured, published diff-apply success rate on the default coder tier (qwen3.7-flash) across a
  fixture corpus — turns the structured_outputs risk from a comment in a config file into a
  tracked number with a regression gate in CI
- `static-php-cli`/FrankenPHP static binary as an alternate install path alongside the PHAR
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
because the entire thesis leans on it.

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
   *Mitigation:* strict diff parser + bounded retry + escalation path (see Architecture); a
   fixture-based test suite covering malformed hunks *before* v0.1 ships, not discovered from a
   bug report; published success-rate metric by v1.0 (see Milestones).

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
- **PHP 8.5 ships native Fibers**, and `curl_multi` is built in — measured 6 concurrent HTTP
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
