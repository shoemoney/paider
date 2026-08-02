# Paider

A PHP-native AI coding agent. Built on [Laravel Zero](https://laravel-zero.com),
[Termwind](https://github.com/nunomaduro/termwind), [Laravel Prompts](https://laravel.com/docs/prompts)
and the official [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk).

**Status: pre-alpha.** Nothing works yet. This repository currently contains a plan, a decision
log, and a model-routing config. It is being built in public from commit one, including the
parts that were wrong.

---

## What this is not

It is not the first PHP coding agent — [`neuron-core/maestro`](https://github.com/neuron-core/maestro)
got there first and its README says so correctly. It is not faster than a Go or Rust agent; PHP's
interpreter floor is 48.6ms against Python's 21.5ms and ripgrep's 3.7ms, and no amount of care
changes that. If raw startup is what you want, use something compiled.

## What it is

Two bets.

**1. An agent that lives inside your Laravel app knows things an external one has to be told.**

`laravel/mcp` builds MCP *servers*; the PHP SDK consumes them. A Laravel application can be both
ends of the protocol at once. Shipped as a package, Paider turns your own models, jobs, queues
and domain logic into tools the agent can call — defined in the framework's idiom, not
hand-rolled JSON schemas. No Python or Go CLI can do that for a Laravel developer.

**2. Model routing is a named feature, not a config detail.**

Four tiers, chosen for what they are *for*:

| tier | job | why it matters |
|---|---|---|
| `orchestrator` | plans, decomposes, reviews | low volume, high value |
| `coder` | writes the diff | runs in a loop, latency compounds |
| `research` | reads docs, greps, summarises | **high volume, low difficulty** — where the money quietly goes |
| `fast` | commit messages, retries | trivial work at trivial cost |

Nobody names a research tier. It is the one that ingests 50k tokens to extract 500, and paying
orchestrator rates for it is how agent bills get absurd.

Eleven presets ship in [`config/presets.php`](config/presets.php), every model ID and price
verified against the live OpenRouter catalogue. On a session that plans 50k/20k tokens and works
2M/300k:

| stack | cost |
|---|---|
| all Opus 5 | $18.25 |
| all Sonnet 5 | $7.30 |
| **default** — Opus 5 to think, `qwen3.7-flash` to do | **$0.85** |

There is also an **open-weight stack** — `kimi-k3` orchestrating with `qwen3.7-flash` on the
other three tiers, self-hostable end to end, for people who will not send their code to a US
frontier lab. As far as we can tell nobody in PHP ships a preset for them.

```bash
paider config provider open      # kimi-k3 + qwen3.7-flash, open weights
paider config provider balanced  # opus-5 to think, qwen3.7-flash to do
paider config provider kimi      # single-provider stacks for all the majors
```

## Why build it at all

[`Aider-AI/aider`](https://github.com/Aider-AI/aider) has 47,883 stars, 1,770 open issues, and no
commit since 2026-05-22. Its most-repeated issues are not feature requests — they are
[#4613 "where is Paul?"](https://github.com/Aider-AI/aider/issues/4613) and
[#4648 "what is the intended future of Aider?"](https://github.com/Aider-AI/aider/issues/4648),
closed not-planned. There are 4,805 forks and none has consolidated the userbase; the leading
one holds 0.8% of upstream's stars.

It died of abandoned stewardship, not a technical flaw. That is the thing worth designing
against, and it is why [`PLAN.md`](PLAN.md) has a Non-goals section longer than its feature list.

## On the terminal UI

The fashionable agent CLIs render with [Ink](https://github.com/vadimdemedes/ink), React for the
terminal. It is good, and it is not obviously better than what PHP already has for this shape of
program. Symfony Console is twenty years mature, Laravel Prompts covers interactive input
properly, Termwind does Tailwind-style layout, and Collision renders errors better than most
things in any language.

Where Ink genuinely wins is a full-screen alternate-buffer app with many live reactive panes.
A coding agent is mostly streaming text, a spinner, a diff and a confirm — and PHP is fine at
those. If Paider ever needs a real reactive TUI, that is the moment to reconsider, not before.

## Read the thinking

- **[PLAN.md](PLAN.md)** — thesis, non-goals, v0.1 scope, architecture, milestones, risks.
- **[DECISIONS.md](DECISIONS.md)** — how we got here, measured, with the wrong turns left in:
  recommending the wrong repo, picking a model off a spec sheet that a practitioner knew was a
  dead zone, and asserting a gap that turned out to be occupied.

## Measured, not estimated

Startup, one machine, medians:

| | |
|---|---|
| ripgrep (Rust) | 3.7ms |
| gh (Go) | 20.0ms |
| Python, bare | 21.5ms |
| PHP, bare | 48.6ms |
| **Laravel Zero, lean ini** | **95.9ms** |
| cecli (1,548 modules) | ~710ms |

Distribution is a PHAR via the bundled `box.json`, with
[`static-php-cli`](https://github.com/crazywhalecc/static-php-cli) as the upgrade for pinning the
extension set. Not Docker — container start would eat the entire budget.

## License

MIT.
