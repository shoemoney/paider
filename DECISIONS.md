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

## 5. Build capacity, and a product hypothesis that came out of it

**What Jeremy actually meant:** he holds **3x Claude Max, 1x OpenAI Max, 1x Kimi** and is not
short of capacity to *build* this — *"I'm not alone"* on having a stack of seats to work with.
Development throughput is not the constraint here. Scope and maintenance are.

*(Logged because this was initially misread as a product requirement. It was not. The idea
below is a hypothesis that fell out of the misreading, kept because it may still be worth
something — but it is not a stated goal.)*

### Hypothesis, unvalidated: multi-subscription rotation

Every agent CLI — aider, cecli, the rest — takes one `--model` and one key, with no concept of
"I hold several seats, use whichever isn't throttled." Developers who stack subscriptions to
dodge rate limits currently juggle them by hand. On a subscription the scarce resource is the
rate limit rather than the bill, which inverts the usual design: availability optimisation, not
cost optimisation. `aigate` already implements precisely that — AES-256-GCM key registry,
TTL-parks a 429'd account, retries the next healthy one.

**Blocking fact if this is ever pursued:** a Claude Max seat cannot be spent through the API.
Since 2026-06-15 headless `claude -p` bills a separate metered credit pool, not the
subscription — established by Jeremy's own benchmarking at
<https://gist.github.com/shoemoney/d8d707f7fa518a3a6a933e3cecf5f924>. So rotation would work
for API-key providers and *not* for the Anthropic seats, which is the opposite of useful for
the person who has three of them. Do not advertise "use all your subscriptions" and quietly
drain metered credit.

Park it. Revisit only if a user asks for it.

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

## 8. FrankenPHP measured — 2026-08-02

§3 and §6 flagged FrankenPHP's binary size and cold start as **unverified** — a maintainer's
20–30MB estimate, never checked. That changed today: the off-the-shelf `frankenphp-mac-arm64`
binary was downloaded and measured directly, arm64/Apple Silicon, macOS Darwin 27.0.0.

**Binary tested:** FrankenPHP **v1.12.6**, embedding **PHP 8.5.9**, Caddy **v2.11.4**.

### 📦 Size: 178MB, not 20–30MB

| | |
|---|---|
| Static binary, off the shelf | **178MB** (177,902,104 bytes) |
| Extensions compiled in | **77** — all 9 Paider requires, plus 68 unwanted (`imagick`, `ldap`, `amqp`, `memcached`, `parallel`, `pgsql`, `pdo_pgsql`, `mysqli`, `pdo_mysql`, `soap`, `tidy`, `xsl`, `gd`, `intl`, `redis`, `ssh2`, `protobuf`, `xlswriter`, and more) |
| Paider's own payload, `--no-dev --classmap-authoritative` | 33MB on disk, 7.4MB gzipped |
| Realistic naive-embed total | **~184MB** |

The maintainer's 20–30MB figure describes a **trimmed custom static build**, not what `curl`ing the
release gives you. That trimmed build **remains unverified** — 178MB is what a naive embed
actually is today, and the docs must not blur the two numbers.

> ⚠️ **178MB is decimal (177,902,104 bytes).** GitHub's release page labels the same asset
> "169.7 MB" because it means MiB. Same file, two conventions — quote bytes when it matters.

Note also that `static-builder.Dockerfile` produces **Linux** binaries only. A macOS binary must
be built natively with `build-static.sh`; Docker is not a substitute and its absence blocks
nothing on macOS.

### 🌍 One binary per platform — there is no universal build

`build-static.sh` **cannot cross-compile** (CGO plus a native PHP toolchain), so every target
needs a runner of that platform. FrankenPHP's own v1.12.6 release is the template:

| asset | size |
|---|---|
| `frankenphp-linux-x86_64` | 165.1MB |
| `frankenphp-linux-aarch64` | 156.8MB |
| `frankenphp-mac-arm64` | 169.7MB |
| `frankenphp-mac-x86_64` | 179.2MB |
| `frankenphp-windows-x86_64.zip` | 56.7MB — **zipped**, so not comparable to the rest |

Two consequences:

1. **A Windows binary exists.** The open Windows question is therefore about `laravel/prompts`
   (WSL-only, no native Windows PHP support), **not** about FrankenPHP. Those are separate
   problems and should stop being discussed as one.
2. **Shipping means a CI matrix**, not a build step: `ubuntu-latest`, `ubuntu-24.04-arm`,
   `macos-latest`, `macos-13`, optionally `windows-latest`. Free for public repos, and the same
   shape Deno, Bun, and rtk already use.

**Compressed transfer size**, which is what an installer actually downloads — measured on
`frankenphp-mac-arm64`: `gzip -9` → **72.6MB**, `zstd -19` → **60.4MB**. Roughly a third of the
on-disk figure, and the reason the Windows asset merely *looks* smaller.

### ⏱️ Cold start: the decision is confirmed, not weakened

hyperfine, 3 warmup + 30 timed runs, no shell (`-N`):

| command | mean | std dev |
|---|---|---|
| `php -n application --version` (lean-ini system PHP 8.5.8) | 90.3ms | ±2.9ms |
| `frankenphp php-cli application --version` | 111.3ms | ±1.7ms |
| `php application --version` (real Homebrew ini, 73 extensions loaded) | 189.6ms | ±4.2ms |
| `php -n` hello-world | 47.5ms | ±0.5ms |
| `frankenphp php-cli` hello-world | 58.3ms | ±0.8ms |

- FrankenPHP is **~23% slower** than a hypothetical lean-ini system PHP (111.3 vs 90.3ms) — the
  honest downside. **⚠️ Superseded by §9 below** — this measured the stock, untrimmed binary; the
  penalty turned out to be the cost of the 66 unwanted extensions dynamically initialising, not
  anything inherent to FrankenPHP. It disappears entirely once trimmed.
- FrankenPHP is **~1.7x faster** than the PHP a real user actually has installed (111.3 vs
  189.6ms): Homebrew PHP dynamically loads 73 extensions from disk on every invocation; the
  static binary has them compiled in and pays nothing for it at runtime.
- Fixed FrankenPHP runtime overhead is a **constant ~11ms** (58.3 − 47.5ms on hello-world);
  everything past that is Paider's own bootstrap (~53ms), identical under both runtimes.
- The 95.9ms Laravel Zero lean-ini baseline from §3 **reproduces** (90.3ms measured today).

Functional verification under the static binary: `application list` runs correctly and renders
the full command list, `pdo_sqlite` round-trips an in-memory DB (create/insert/select), and
`stream_isatty()` returns correctly (`bool(false)`) under a non-TTY pipe. `PHP_VERSION` reports
8.5.9, comfortably above Paider's `^8.4` floor.

### The decision, restated

Composer package + FrankenPHP embed binary, no PHAR, no Docker-as-distribution ("Distribution and
concurrency" in PLAN.md) is **CONFIRMED on cold start** — it was the assumed risk and measurement
shows it isn't one — and **CONDITIONAL on the trimmed-build size**. If a trimmed static build
cannot land meaningfully under 178MB, the size argument against `curl | sh` gets harder, not
easier, to answer. That build is the next thing to verify — see PLAN.md's Open questions.

*(Housekeeping, unrelated to the measurement above: `composer.lock` is stale against
`composer.json` — composer warns on install. The `^8.4` floor bump was never re-locked.)*

⚠️ **Both open items above are resolved by §9 below** — the trimmed build was built and measured,
and it revises the cold-start conclusion, not just the size one.

## 9. FrankenPHP, round 2 — the trimmed build was built, and it changes two conclusions

§8 measured the **stock** off-the-shelf binary and could only confirm the decision on one axis
(cold start), leaving size conditional on an unverified trimmed build. That build now exists:
built natively on macOS with FrankenPHP's own `build-static.sh` (**not** Docker — the Docker
static-builder emits Linux binaries only), PHP **8.5.9**, Caddy **v2.11.4**, Go **1.26.5**. Build
time: **~7 minutes**. Only missing host dependency was `re2c`.

### 🪤 Attempt 1 failed — and found a real spec bug

The documented nine extensions (`mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,
pdo_sqlite`) produced a 111,032,744-byte binary that **could not boot the app**:

```
Fatal error: Uncaught Error: Class "Phar" not found
  in vendor/laravel-zero/framework/src/Providers/Build/Build.php:37
```

`LaravelZero\Framework\Providers\Build\Build::isRunning()` calls `Phar::running()`
**unconditionally** during bootstrap, on every invocation — and `laravel-zero/framework` does not
declare `ext-phar` in its `composer.json`, so nothing warns you. It's invisible on the stock
77-extension binary by accident. `composer check-platform-reqs --no-dev` on the prod tree also
flagged **`filter`**, likewise absent from the documented nine.

**Paider's required extension set is eleven, not nine** — the existing nine plus `phar` and
`filter`. This would have shipped as a broken binary. Full writeup and the trap explanation:
[`EXTENSIONS.md`](EXTENSIONS.md).

### ✅ Attempt 2 — eleven extensions, works

| | |
|---|---|
| Trimmed binary size | **111,315,960 bytes = 111.3 MB decimal / 106.2 MiB** |
| Cost of adding `phar` + `filter` | **+283 KB** — negligible |
| Extensions loaded at runtime | 25 (the 11 required + 14 always-compiled core: `Core`, `PDO`, `Reflection`, `SPL`, `Zend OPcache`, `date`, `hash`, `json`, `lexbor`, `pcre`, `random`, `standard`, `uri`) |
| vs. stock 178MB / 77-ext binary | **−37.5%** |
| Compressed, `gzip -9` | **46.6 MB** |
| Compressed, `zstd -19` | **40.6 MB** |

(Round 1 compressed the stock binary to gzip 72.6MB / zstd 60.4MB, for comparison.)

### ⏱️ Cold start, four-way, hyperfine (3 warmup + 30 timed runs, `-N`)

| command | mean | std dev |
|---|---|---|
| `php -n application --version` (lean-ini system PHP 8.5.8) | 95.9ms | ±1.3ms |
| `php application --version` (real Homebrew ini, 73 ext) | 192.9ms | ±1.4ms |
| stock frankenphp, 77 ext, 178MB | 113.5ms | ±1.4ms |
| **trimmed frankenphp, 11 ext, 111MB** | **94.8ms** | **±1.3ms** |

- The trimmed binary is **1.01x faster** than the lean-ini system-PHP baseline — a statistical tie
  / parity, not a meaningful win. Correct framing: the cold-start penalty is **eliminated**, zero
  measurable cost — not "FrankenPHP is faster than PHP."
- Trimmed is **1.20x faster** than the stock binary and **2.03x faster** than a real user's
  Homebrew PHP.
- **§8's "~23% slower than lean-ini PHP" conclusion is superseded.** That penalty was never
  inherent to FrankenPHP — it was the cost of dynamically initialising 66 unwanted extensions in
  the stock binary. Trimming removes it entirely.
- The 95.9ms lean-ini baseline **reproduced exactly** this round (95.9ms both times) — a good
  sanity check on the harness.

### 📏 The 20–30MB estimate: not achieved, and now we know why

111MB is 3.7–5.5x the maintainer's estimate, so it does not hold for this build path. Reason:
`build-static.sh` **always links the full Caddy server and Go HTTP stack**, even for a binary
only ever invoked as `php-cli` — there is no CLI-only mode in the script. Reported as **not
achieved via the supported build path**, Caddy named as the likely cause. Not claimed impossible
in general, and not claimed disproven — see PLAN.md's open questions for the remaining
CLI-only-build question.

### Functional verification, trimmed binary

`application --version` → "Application unreleased" ✅ · `application list` renders the full
command list ✅ · `pdo_sqlite` round-trips an in-memory DB (create/insert/select) ✅ ·
`stream_isatty()` correct under a non-TTY pipe (`bool(false)`) ✅ · `PHP_VERSION` 8.5.9, above the
`^8.4` floor ✅.

### The decision, restated again — now confirmed on both axes

Composer package + FrankenPHP embed, no PHAR, no Docker-as-distribution is now **CONFIRMED on
both axes**, where §8 could only confirm one:

- **Cold start:** no penalty at all once trimmed. Fully resolved.
- **Size:** 111MB on disk, but **40.6MB compressed** — landing right alongside the ~40MB Go
  binaries competing agents ship. The `curl | sh` story is viable. The on-disk figure is still
  large and worth stating honestly, but transfer size is what an installer experiences.
- **Build cost** is ~7 minutes per platform, built natively — cheap enough for a CI matrix, which
  removes the last practical objection.

**Remaining open:** whether a CLI-only build without Caddy could approach the 20–30MB estimate.
A nice-to-have now, not a blocker — see PLAN.md's Open questions.

---

## 10. Windows resolved — 2026-08-02

The blocker recorded in §8 ("the open Windows question is about `laravel/prompts`") **was already
solved by the framework Paider is built on.** Nothing needed to be written.

`Illuminate\Console\Command` uses the `ConfiguresPrompts` trait, which on every command run calls:

```php
Prompt::fallbackWhen(windows_os() || $this->laravel->runningUnitTests());
```

…and registers a Symfony Question Helper fallback for ten of the eleven input prompts. Laravel
Zero's `Command` extends `Illuminate\Console\Command`, so Paider inherits all of it for free. The
`RuntimeException('Prompts is not currently supported on Windows')` in `Prompt::checkEnvironment()`
is only reachable when `laravel/prompts` is used **standalone**, outside a Laravel console kernel.
That is not how Paider uses it.

### Measured, not assumed

`Prompt::fallbackWhen(true)` reproduces the exact branch native Windows takes, so the comparison
runs without a Windows box. Three PTY captures (expect-driven, `laravel/prompts` v0.3.21), with
`pcntl` disabled via `-d disable_functions=…` to match Paider's extension set:

| run | mode | pcntl | bytes |
|---|---|---|---|
| A | native | yes | 11,709 |
| B | native | **no** | 11,518 |
| C | **windows fallback** | **no** | 7,864 |

**The output section of B and C is byte-identical — all 47 lines.** `stream()`, `note()`,
`table()`, `spin()` and `progress()` render exactly the same under the Windows fallback, because
every one of those classes **overrides `prompt()`** and so never reaches `checkEnvironment()`.
`shouldFallback()` is `$shouldFallback && isset($fallbacks[static::class])`, so an output class
with no registered fallback renders normally rather than throwing.

Paider's primary UI — the streaming LLM response — is therefore **unaffected on Windows.** Only
the eleven interactive input prompts change, and they degrade to Laravel's styled
`$this->components` UI, not to raw Symfony:

```
  Route to: [sonnet]                     ┌ Route to ─────────────────┐
  fable ............................ 0   │ › ● fable                 │
  sonnet ........................... 1   │   ○ sonnet                │
  haiku ............................ 2   │   ○ haiku                 │
❯                                        └───────────────────────────┘
       windows fallback                            native
```

Typed number instead of arrow keys. Worse, not bad, and only on the input path.

### Two real findings

1. **`NumberPrompt` has no fallback registered and does reach the Windows guard.** Every other
   input prompt is covered; `number()` alone would throw `RuntimeException` on native Windows.
   **Do not call `number()`** — use `text()` with numeric validation, or register a fallback in
   Paider's own base command. Guarded by `tests/Feature/PromptsWindowsFallbackTest.php`.
2. **Spinner animation confirmed dead without pcntl** — 6 frames with `pcntl`, 1 static frame
   (`⠶`) without. Exactly the cosmetic cost PLAN.md Correction 2 predicted and accepted. The
   progress bar is unaffected: it renders and advances fully either way.

### The remaining Windows caveat is the terminal, not the library

`laravel/prompts` renders with box-drawing (`┌ │ └ ─`) and block glyphs (`█`). Native Windows PHP
8.4 handles the ANSI side fine — PHP enables `ENABLE_VIRTUAL_TERMINAL_PROCESSING` at startup via
`sapi_windows_vt100_support()`, and Symfony Console probes the same function in
`hasColorSupport()`. `stream_isatty()` works on Windows too. What is *not* reliable is UTF-8 in
legacy `conhost.exe`/`cmd.exe`, where code page 65001 is a known-partial hack and box-drawing
needs a TrueType console font.

**Decision: Windows Terminal is the documented baseline for Windows.** No WSL requirement, no
split UI layer, no Symfony fallback to write. Ship it and say so in the README.
