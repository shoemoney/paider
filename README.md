# Paider

A PHP-native AI coding agent. Built on [Laravel Zero](https://laravel-zero.com),
[Laravel Prompts](https://laravel.com/docs/prompts), [Termwind](https://github.com/nunomaduro/termwind)
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

The full version of this — any MCP client driving Paider's tools — is v1.0, gated on the MCP PHP
SDK maturing past pre-1.0. But the shape ships in v0.1 already: pointed at a Laravel repo, Paider
gets one extra tool, `ArtisanTool`, that reads `route:list` as structured data instead of shell
text. Small on purpose — see [`PLAN.md`](PLAN.md) — but real, not just promised.

**2. You can see exactly where the money went.**

Four tiers, named for what they are *for*:

| tier | job | why it matters |
|---|---|---|
| `orchestrator` | plans, decomposes, reviews | low volume, high value |
| `coder` | writes the diff | runs in a loop, latency compounds |
| `research` | reads docs, greps, summarises | **high volume, low difficulty** — where the money quietly goes |
| `fast` | commit messages, retries | trivial work at trivial cost |

Nobody else names a research tier. It is the one that ingests 50k tokens to extract 500, and
paying orchestrator rates for it is how agent bills get absurd.

Because the tiers are named, Paider can account for them separately — and answer a question no
other agent CLI can:

```
$ paider cost
                                                    ← design, not shipped yet

  tier            calls      in        out       spend    share
  ───────────────────────────────────────────────────────────────
  orchestrator       14    61.2k      19.8k      $4.10    82.7%
  coder             203     1.4M     287.1k      $0.42     8.5%
  research          118     1.8M      34.6k      $0.23     4.6%
  fast               77    98.4k      12.2k      $0.21     4.2%
  ───────────────────────────────────────────────────────────────
  session                  3.36M     353.7k      $4.96

  92% of your tokens went through tiers costing 17% of your spend.
  Same work on all-Opus 5: $47.30  ·  you saved $42.34
```

That last line is the product in one sentence. Most agent tools show you a total, if anything.
Paider shows you the **ratio** — and the ratio is the whole argument for routing.

It also keeps us honest. The 95.3% figure below is a modelled session; the ledger is what
confirms or refutes it on real work. A cost claim you cannot check is marketing, and this one is
checkable by the person paying.

### The presets

Eleven ship in [`config/presets.php`](config/presets.php), every model ID and price verified
against the live OpenRouter catalogue. Modelled on a session planning 50k/20k and working 2M/300k:

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
program. Symfony Console is twenty years mature; `laravel/prompts` does streaming output
(`stream()`, `task()->partial()`) *and* every interactive input, with non-TTY fallback built in;
Termwind does Tailwind-style layout for static output; and Collision renders errors better than
most things in any language.

The Windows caveat you will read about — "`laravel/prompts` is WSL-only" — **does not apply here**,
and that was measured, not assumed. `Illuminate\Console\Command` already calls
`Prompt::fallbackWhen(windows_os())` and registers Symfony Question Helper fallbacks, so Laravel
Zero inherits a working Windows path for free. The streaming output side is **byte-identical**
under the fallback (all 47 lines of a captured run); only the interactive input prompts change,
from arrow-key selection to typed numbers. Windows Terminal is the documented baseline — legacy
`cmd.exe` mangles the box-drawing glyphs. Full measurement in [`DECISIONS.md` §10](DECISIONS.md).

Where Ink genuinely wins is a full-screen alternate-buffer app with many live reactive panes.
A coding agent is mostly streaming text, a spinner, a diff and a confirm — and PHP is fine at
those. If Paider ever needs a real reactive TUI, that is the moment to reconsider, not before.

## Read the thinking

- **[STORAGE.md](STORAGE.md)** — one SQLite file, no services. Why not Redis.
- **[EXTENSIONS.md](EXTENSIONS.md)** — the eleven extensions that ship, and what was cut.
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

## Distribution

Two channels, because there are exactly two users:

```bash
composer require paider/paider          # inside your Laravel app — this is the thesis
curl -fsSL paider.dev/install | sh      # planned: standalone binary, not built yet
```

The package is non-negotiable: an agent that turns *your* models and jobs into tools has to be a
dependency of your app, and a compiled binary cannot be one.

The binary is planned to be a [FrankenPHP](https://github.com/php/frankenphp) embed (11,263★, Go,
built on Caddy), which produces a self-executable with PHP inside and
[supports CLI](https://frankenphp.dev/docs/embed/) — `./my-app php-cli bin/console` — not just
HTTP. It selects extensions from `composer.json`, so the shipped tool never inherits a user's dev
ini. That matters: 76 extensions on the author's machine cost 94ms of a 143ms startup.

### ✅ Measured 2026-08-02, round 2 — trimmed and built, both risks resolved

Round 1 measured the stock off-the-shelf binary and could only clear the cold-start risk,
leaving size conditional. Round 2 actually built the trimmed binary natively — and it **revises**
round 1's cold-start conclusion, not just its size one. Full numbers:
[`DECISIONS.md` §9](DECISIONS.md).

| | round 1 (stock, 77 ext) | round 2 (trimmed, 11 ext) |
|---|---|---|
| Size | 178MB | **111.3MB (106.2 MiB)** — −37.5% |
| Compressed (`zstd -19`) | 60.4MB | **40.6MB** |
| Cold start vs. lean-ini PHP | ~23% slower | **parity** (1.01x, i.e. no measurable penalty) |
| Cold start vs. stock binary | — | **1.20x faster** |

**Cold start: the penalty is gone, not just smaller.** Round 1's "~23% slower than lean-ini PHP"
is **superseded** — that number came from the stock binary dynamically initialising 66 unwanted
extensions on every invocation. Trim to the eleven Paider actually needs and the penalty
disappears: 94.8ms ±1.3ms vs. 95.9ms ±1.3ms for lean-ini system PHP, a statistical tie. Say it as
"the cold-start penalty is eliminated," not "FrankenPHP is faster than PHP" — it isn't, it's even.

**Size: 111MB on disk, honestly stated — but 40.6MB is what an installer downloads.** That lands
right alongside the ~40MB Go binaries competing agents ship, which makes the `curl | sh` story
viable. Getting there required fixing a real bug first: the "documented nine" extensions produced
a binary that **could not boot** — `laravel-zero/framework` calls `Phar::running()`
unconditionally and needs `ext-phar` present, undeclared in its own `composer.json`. Paider's
required set is **eleven**, not nine (adding `phar` and `filter` cost +283KB). See
[`EXTENSIONS.md`](EXTENSIONS.md) for the trap and the full extension table.

<details>
<summary>Full round 2 hyperfine table (3 warmup + 30 timed runs, <code>-N</code>)</summary>

| command | mean | std dev |
|---|---|---|
| `php -n application --version` (lean-ini system PHP 8.5.8) | 95.9ms | ±1.3ms |
| `php application --version` (real Homebrew ini, 73 ext) | 192.9ms | ±1.4ms |
| stock frankenphp, 77 ext, 178MB | 113.5ms | ±1.4ms |
| **trimmed frankenphp, 11 ext, 111MB** | **94.8ms** | **±1.3ms** |

</details>

**The maintainer's 20–30MB estimate was not reached, and now we know why:** `build-static.sh`
always links the full Caddy server and Go HTTP stack, even for a binary only ever invoked as
`php-cli` — there's no CLI-only mode in the script. Not claimed impossible in general, just not
what the supported build path produces. Whether a Caddy-free CLI-only build could get closer is
open — see [`PLAN.md`](PLAN.md) — but it's a nice-to-have now, not a blocker.

**Build cost is cheap.** Built natively (not Docker — the Docker static-builder emits Linux
binaries only) in **~7 minutes**, PHP 8.5.9 / Caddy v2.11.4 / Go 1.26.5. `build-static.sh` cannot
cross-compile, so shipping is still a CI matrix (`ubuntu-latest`, `ubuntu-24.04-arm`,
`macos-latest`, `macos-13`), but 7 minutes/platform is cheap enough for that matrix to be routine.

**A Windows binary does exist** (`frankenphp-windows-x86_64`), and the `laravel/prompts` half of
the Windows question is **resolved** — Laravel's own `ConfiguresPrompts` already handles it, and
the streaming path is unchanged. See [`DECISIONS.md` §10](DECISIONS.md). Windows is shippable.

Functional check under the trimmed binary, for the record: `application list` renders correctly,
`pdo_sqlite` round-trips an in-memory DB (see [`STORAGE.md`](STORAGE.md)), `stream_isatty()`
behaves correctly under a non-TTY pipe, and `PHP_VERSION` reports 8.5.9 — comfortably above the
`^8.4` floor. Full writeup: [`DECISIONS.md` §9](DECISIONS.md).

**The distribution decision is now confirmed on both axes** — cold start and size — where round 1
could only confirm one. `curl -fsSL paider.dev/install | sh` above is still **planned**, not
built; the binary math behind it now checks out.

**No PHAR.** It needs PHP installed but is not a composer dependency, so it serves neither user
better than the two above. A third channel is maintenance forever for an audience of nobody.

**No Docker.** Container start would eat the entire startup budget.

## License

MIT.
