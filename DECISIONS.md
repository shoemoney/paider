# Paider — how we got here

A decision log, written as the decisions were made on 2026-08-02. Every number below was
measured or verified against a live source on that date, not recalled. Where something was
wrong and got corrected, the correction is left in — the wrong turns are the useful part.

---

## 1. Why build anything at all: the upstream is stalled

The search started as "where can we make a legitimate open-source contribution", looking at
`cecli-dev/cecli`, described as the premier Aider fork. What the numbers actually showed:

| | |
|---|---|
| Upstream `Aider-AI/aider` | **47,883★**, 4,805 forks, **1,770 open issues** |
| Last commit to upstream | **2026-05-22 — ten weeks before this was written** |
| `cecli-dev/cecli`, the "premier fork" | **390★** |

A 48,000-star flagship with 1,770 open issues and no commits in two and a half months, and
4,805 forks in which **no successor has consolidated the userbase** — the leading fork holds
0.8% of upstream's stars.

**Correction made during the session:** the initial recommendation was "contribute to cecli,
it's the premier fork, that's high signal." That was wrong once the upstream numbers were
pulled. Contributing to one of 4,805 forks of a stalled project is low leverage. The actual
opportunity is the vacuum.

## 2. Why PHP

Not for speed — see §3, PHP loses that argument. For an underserved ecosystem with no
credible agent CLI, where the tooling to build one already exists and is well-liked:

| Package | Stars | State |
|---|---|---|
| `pestphp/pest` | 11,620★ | tagline is literally *"for PHP developers and AI agents"* |
| `nunomaduro/collision` | 4,657★ | active |
| `laravel-zero/laravel-zero` | 3,974★ | CLI scaffolding, ships PHAR build via Box |
| `laravel/boost` | 3,549★ | Laravel investing in MCP officially |
| `nunomaduro/termwind` | 2,492★ | Tailwind-for-terminal |
| `modelcontextprotocol/php-sdk` | 1,572★ | **official**, and has **no conformance harness** |

Meanwhile the plumbing is rotting faster than the ecosystem is growing: `prism-php/prism`
(2,406★) last pushed 2026-03-20, `php-mcp/server` (857★) last pushed **2025-08-09 — a full
year** — while Laravel ships official MCP tooling.

## 3. Speed: the honest numbers

Measured on one machine, median of 12+ runs, same conditions.

| | startup |
|---|---|
| ripgrep (Rust) | 3.7ms |
| git (C) | 5.5ms |
| gh (Go) | 20.0ms |
| Python, bare interpreter | 21.5ms |
| Node, bare | 33.6ms |
| **PHP, bare interpreter** | **48.6ms** |
| PHP on this dev box (76 extensions) | 143.9ms |
| **Laravel Zero scaffold, lean ini** | **95.9ms** |
| Laravel Zero on the 76-extension box | 189.7ms |
| **cecli today** | **~710ms** |

Three conclusions:

1. **PHP is not fast.** Its floor is 2.3x Python's. "Rewrite it in PHP for speed" is a losing
   argument and should never be the pitch.
2. **cecli's 710ms is not Python's fault.** Python starts in 21.5ms; the other ~689ms is an
   import tree of 1,548 modules. A fat composer autoloader would reproduce it exactly.
3. **A disciplined PHP CLI still lands ~7x faster than cecli** at ~96ms, and composer autoload
   is only 6.2ms of that. Good enough to feel instant. Not a headline.

If speed were the product, the answer would be Go or Rust — ripgrep is 190x faster than cecli.
It isn't the product. *Belonging to the stack* is.

**Distribution follows from this:** not Docker. `docker run` overhead lands in the hundreds of
milliseconds on a warm image and would eat the entire budget. Laravel Zero already ships
`box.json` for PHAR builds; `static-php-cli` or FrankenPHP is the upgrade when the extension
set needs pinning — a shipped tool must not inherit a user's 76-extension dev ini, which costs
94ms of the 143ms measured above.

## 4. Model tiers

Named for what they are *for*, not aider's `main`/`weak`/`editor`/`agent`:

- **orchestrator** — plans, decomposes, reviews. Low volume, high value.
- **coder** — writes the diff. Runs in a loop, so latency compounds.
- **research** — reads docs, greps, summarises. **High token volume, low difficulty**: ingest
  50k to extract 500. This is where agent bills quietly go, and nobody else names it as a tier.
- **fast** — commit messages, retries, one-liners.

### The default: Opus 5 to think, qwen3.7-flash to do

Jeremy's own working config. On a session that plans 50k/20k and does 2M/300k:

| stack | cost |
|---|---|
| all Opus 5 | $18.25 |
| all Sonnet 5 | $7.30 |
| **Opus 5 + qwen3.7-flash** | **$0.85** — 95.3% cheaper than all-Opus |

qwen3.7-flash is $0.03/$0.13 per Mtok with **1M context** and tool support.

**Practitioner corrections that overrode the spec sheet:**

- `qwen3-coder-plus` ($0.65/$3.25) was initially chosen as the qwen coder because it reports
  `structured_outputs=true`. Jeremy's call, from using it: *"not smart enough to be an
  orchestrator and not fast enough to be your coder"* — a dead zone buying neither. Replaced
  with qwen3.7-flash. Caveat retained in the config: flash reports
  `structured_outputs=false`, so malformed diffs are the first thing to suspect if they appear.
- Kimi's coder is `kimi-k2.7-code`, not k2.6 — newer and coding-specialised for 13c/Mtok more.

**cecli's aliases for comparison**, all verified stale against the live OpenRouter catalogue:
`sonnet` → claude-sonnet-**4**-20250514, `opus` → claude-opus-**4**, `haiku` →
claude-3-5-haiku-**20241022** (October 2024), while its `gemini` alias is current. Default
model is `gpt-4o`. Shipping current, opinionated tiers is itself a differentiator.

## 5. Multi-subscription rotation — quite possibly the actual product

**This is the differentiator, more than PHP and more than startup time.**

Jeremy runs **3x Claude Max, 1x OpenAI Max, 1x Kimi** — *"I'm not alone."* And he isn't:
developers stack multiple subscriptions specifically because a single seat's rate limit stops
them mid-session. It is a normal, widespread, expensive workaround.

**Every agent CLI assumes one API key.** aider, cecli, and the rest take `--model` and a key
and have no concept of "I hold five seats, use whichever isn't throttled right now." A
developer with three Max subs currently juggles them by hand — separate terminals, separate
configs, or just waiting out the limit.

On a subscription the scarce resource is **the rate limit, not the bill**, which inverts the
usual design. Cost optimisation is irrelevant; *availability* optimisation is everything. And
`aigate` already implements exactly that: AES-256-GCM key registry, TTL-parks a 429'd account
and retries the next healthy one, with a live usage dashboard.

So the pitch is not "an agent CLI in PHP." It is **"the agent CLI that knows you own five
subscriptions"** — and PHP is where it gets built because that ecosystem has no agent CLI at
all and the incumbents there are stale.

### The constraint that shapes it

**But a Max seat cannot be spent through the API.** Since 2026-06-15, headless `claude -p`
bills a separate metered credit pool, not the subscription — a fact established by Jeremy's own
benchmarking, published at
<https://gist.github.com/shoemoney/d8d707f7fa518a3a6a933e3cecf5f924>.

So rotation across *Claude* seats specifically has to be resolved before it is promised:

1. **API keys, per-token** — trivial to build, but the subscriptions sit unused, which defeats
   the point.
2. **Drive the `claude` binary as a subprocess** — the coprocess work above already proves a
   warm session is ~37% faster in wall clock than repeated `--resume`, so the mechanism is
   understood. It still draws the metered pool rather than the seat.
3. **Rotate the providers where seats *are* spendable** (OpenAI, Kimi, and any API-key
   provider) and be explicit in the docs about the Anthropic exception.

Option 3 is honest and shippable today; 1 and 2 are additive. What must not happen is
advertising "use all your subscriptions" and quietly billing people's metered credit.

**Verify the current terms before building on any of this** — that billing change is barely a
year old and the vendors are still moving.

## 6. What is deliberately NOT being done

- **Forking cecli.** It is already a fork of Aider; a fork-of-a-fork is a permanent merge tax
  and earns nothing reputationally.
- **A Go/Rust rewrite.** The speed pitch is real but the field is crowded with funded teams,
  and nobody remembers the fourth-fastest agent CLI.
- **phpredis.** 10,231★, healthy, welcoming maintainers — and entirely off-thesis.
- **Campaigning in anyone's Discord.** cecli's CONTRIBUTING routes feature requests there;
  small direct PRs are still explicitly welcome, and those are the only contributions worth
  making from outside.

## 7. Adjacent contributions that stand on their own

- **PHP into the MCP conformance matrix.** `KNOWN_SDKS` currently covers TypeScript, Python,
  Go, Rust and C#. PHP is absent, and the official php-sdk has no conformance harness at all
  — meaning MCP correctness in PHP is presently unverified by anyone. Same PR shape as
  `modelcontextprotocol/conformance` #432, which added the Swift SDK.
- **cecli model aliases.** Small, factual, verifiable, and permitted as a direct PR.

Both are independent of Paider, and hardening the SDK Paider stands on is the same work.
