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

---

## 11. Command surface locked to five — 2026-08-02

v0.1.0 shipped with the command roster frozen: `paider chat`, `commit`, `cost`, `config:provider`,
`config:show`. That is exactly five public commands, all Paider's own. Removed: `app:build`,
`app:install`, `app:rename`, `make:command`, `make:test`, `test`, `inspire` — the Laravel Zero
defaults that shipped in the scaffold.

**Rationale:** a release tag is a promise. Anything on the public surface at the first tag is frozen
by the release policy. `app:build` is a distribution channel (builds a PHAR via Box) and leaving it
public advertised a channel that does not exist — the binary distribution is FrankenPHP, not PHAR.
Hidden four LaravelZero scaffold commands you never advertised; nobody's workflow broke.

## 12. `--version` now reads from Composer, not `git describe`

v0.1.0 `paider --version` reports `Paider v0.1.0` every time, regardless of what the consumer app's
version is. The bug: prior code read `config/app.php`'s `app('git.version')`, which calls `git describe
--tags` from `basePath()`. **On install via composer, there is no `.git` in `vendor/paider/paider`, so
`git` walks up the tree** — inside a host app tagged `v9.9.9`, Paider reported `v9.9.9`.

**Verified in a scratch repo**: fresh `composer require paider/paider`, host app was tagged
`application@v9.9.9`. Before fix: `paider --version` → "Application v9.9.9". After fix: `paider --version`
→ "Paider v0.1.0".

**Solution:** read `Composer\InstalledVersions` at runtime, which reports the installed package
version. Removes a `git` fork from every invocation, which also saves 3–5ms on startup.

## 13. Dist archive trimmed: 760 KB → 200 KB

The shipped tarball via Packagist (what `composer install` extracts) is controlled by `.gitattributes`
with `export-ignore` — unused files never leave the repo. v0.1.0 configured:

```
export-ignore
/tests/
phpunit.xml.dist
PLAN.md
DECISIONS.md
EXTENSIONS.md
STORAGE.md
box.json
composer.lock
```

A consumer gets exactly `app/ bootstrap/ config/ paider/ composer.json LICENSE README.md CHANGELOG.md`.

**Note:** `CHANGELOG.md` was **un**-ignored, correcting a mistake in the skeleton. The skeleton
shipped it on the repo but ignored it on export — backwards. Now the changelog is in the tarball.

Result: 200 KB of **intentional, shipped code** vs. the 760 KB of build tooling and unreleased docs
that no consumer needs.

## 14. CI pipeline shipped — 2026-08-02

`.github/workflows/tests.yml` runs on every `pull_request` (never `pull_request_target`, so fork PRs
see no secrets):

- **Hermetic suite on PHP 8.4 + 8.5**: `vendor/bin/pest` with `--prefer-lowest` (all dependencies at
  their minimum declared versions) to catch transitive version leaks.
- **PHP extensions**: exactly Paider's twelve (from `EXTENSIONS.md`), not a system default, so the
  binary's trimmed extension set is verified on every PR.
- **Live-suite safety**: the three live tests (real API calls, requires credentials) skip cleanly
  with the message "skipped" if credentials are absent, so CI stays green in sandboxes with no
  secrets configured.
- **Functional smoke test**: `paider cost` runs against a seeded event log and asserts the formatted
  output matches.

**Real finding from the first run**: the `--prefer-lowest` job caught a transitive version leak.
Paider depends on `laravel/prompts` *indirectly* via Laravel Zero, but had no explicit constraint.
The stock version resolved to `^0.3.0`, which includes classes (`Stream`, `Task`, `Callout`) added
in v0.3.19, but `--prefer-lowest` pulled 0.3.0 (the tag) which did not have them — test failure.
Now pinned to `^0.3.19` explicitly.

## 15. Security audit — six defects found and fixed, 2026-08-03

Adversarial review with 33 agents across 8 lenses (red team playing a hostile model). Every finding
independently reproduced by two skeptics instructed to refute it. All six committed to regression tests.

**The headline: a fix that had already been verified was incomplete.**

Earlier, an ACE hole was closed: `Loop::dispatchShell()` and `Loop::dispatchArtisan()` were scrubbed
of `$input['approval']` so the model could not self-approve. Tests passed. Then the security audit
read the code on disk and found that `read_file`, `write_file`, `patch_file`, and `git` trust a
*different* key — **`approved`** — and `Loop::dispatch()` passed the model's raw input unchanged to all
of them. The model is told both field names in the system prompt, so it knew exactly what to try.
A reply with `{"approved":"allow-once"}` would bypass the gate on file and git operations: no
approval callback, no human confirmation, raw credentials in the next provider call. 

**This is the useful lesson for codebases that gate tool calls:** centralize approval scrubbing at
the sole chokepoint where *all* tools route through. Fixedness at one dispatcher leaves siblings
broken. Fixed in `Loop::dispatch()` with regression tests asserting the gate runs and wins on both
field names.

**The other five defects found and fixed:**

1. **JSON-array command execution** — a `command` field with an array value was coerced to `''` for
   display in the approval prompt, but the original array was passed to `proc_open()` — which
   takes arrays as `argv`, needing no shell parsing. Grants cached by displayed text, so one
   `allow-session` grant silently authorized every later array-form command for the session.
   Displays plainly now, grants only cache by the displayed text, no auto-grant on arrays.

2. **`/undo` deleted files outside project root** with no prompt, reporting "reverted" while
   poisoning the undo stack. A write correctly rejected for being out-of-root still added an
   entry to the stack, so later `/undo` commands tried to reverse it. Now `/undo` respects
   boundaries.

3. **PathGuard walked past a dangling symlink** — the third independent escape found in that
   single function. `file_exists()` returns false for a dangling symlink, but nothing checked
   after that point. Now fails safe: if the path traversal crosses anything symlinked, reject it.

4. **A NUL byte in a path crashed the process** — `fnmatch()` throws `ValueError` when a NUL
   appears. Unhandled, this is a model-triggerable zero-approval DoS. Caught and fail-safe now.

**Two residual risks were recorded here honestly. Both have since been addressed — the original
wording is kept below because the wrong turns are the useful part of this log.**

1. ~~**Architectural caveat:** tools still trust the `approved` key if called directly, not
   through `dispatch()`. Defence is a single chokepoint, so any future code path reaching a tool
   without going through `dispatch()` reopens all four bypasses at once.~~
   **Closed by §18** for the four file/git tools, which now take approval as a PHP-typed
   parameter that model-supplied JSON cannot reach — two independent mechanisms instead of one.
   **Scope, stated precisely:** `run_shell` and `artisan` use a different tri-state `approval`
   key and were deliberately left out; for those two, `Loop::dispatch()`'s `unset()` genuinely
   does remain the sole defence. The caveat is narrower than it was, not gone.

2. ~~An approved shell command's child inherits the full parent environment, including live
   provider API keys.~~ **Closed by §17** — `ShellTool` and `GitTool` now pass an explicit
   allowlisted environment to `proc_open` via `App\Support\ShellEnv`.

Commit: `407a2fc`. Tests added: `ApprovalGateSelfApprovalTest`, `ApprovalGateApprovedFieldTest`,
`PathGuardSymlinkTest`, `ToolExitCodeTest`.

## 16. FrankenPHP embed: twelve extensions, not eleven — 2026-08-03

The trimmed FrankenPHP binary from §9 was built and measured for real. It works, and it corrected
the extension count.

| | |
|---|---|
| **Extensions compiled in** | **12**, not 11: `mbstring`, `tokenizer`, `ctype`, `fileinfo`, `iconv`, `curl`, `openssl`, `zlib`, `pdo_sqlite`, `phar`, `filter`, **`dom`** |
| cold start (first invocation) | 445 ms — untars 74 MB to `$TMPDIR` |
| cold start (warm, subsequent) | 110 ms — binary already extracted |
| **disk footprint, Paider embedded** | **178 MB** (177,955,720 bytes) |
| **compressed transfer, Paider embedded** | **~72 MB** with `zstd -19` |
| runtime only, no Paider inside (§9) | 111.3 MB disk / 40.6 MB zstd |
| cost of embedding Paider | **+67 MB** — mostly `vendor/`, plus libxml arriving with `dom` |

> ⚠️ **Do not quote §9's 111.3 MB / 40.6 MB for the shippable artifact.** Those are the RUNTIME
> ALONE, with no Paider in it. The binary a user would actually download is 178 MB on disk and
> ~72 MB compressed. This is easy to get wrong because of an unhelpful coincidence: the stock
> 77-extension release binary is *also* ~178 MB, so "178 MB" appears in this file meaning two
> different things. The trimmed-plus-embedded build is not a −37.5% cut from anything — it is
> the same size as the stock binary, having traded 65 unwanted extensions for Paider's `vendor/`.
>
> This matters to the decision, not just the record: 40.6 MB lands beside a Go binary and 72 MB
> does not, and the ship-or-defer call was made partly on that figure.

**The missing extension, found the expensive way:** the "documented eleven" passed `paider --version`
and `paider list` (commands that print a version string, exercising almost none of the app), then
died on `paider cost` with `Class "DOMDocument" not found`. Termwind's `HtmlRenderer` does
`new DOMDocument`, and Termwind's own `composer.json` requires only `php` and `ext-mbstring`. So
`composer check-platform-reqs` cannot see it, CI has `dom` in its default PHP anyway, and every
dev machine has it. The only environment that could reveal it is the one actually distributed —
a build containing exactly what was asked for and nothing else.

**The general lesson:** smoke-test with something that does *work*, not something that proves the
binary starts. `--version` and `list` are not smoke tests; they prove the binary can fork and print.
Run a command that does real work through the actual rendering pipeline. Paider declares `ext-dom`
in its own `composer.json` now, since Termwind does not.

**Three reasons the recommendation is still defer, not ship:**

1. **Invocation is `<binary> php-cli paider <command>`**, not `./paider <command>`. A bare name
   mismatch is a documentation problem; the real issue is the next one.

2. **Naming the binary `paider` and running it from a directory containing a `paider` script**
   makes PHP try to `include` the 178 MB binary as a script and OOM. The obvious layout is the one
   that breaks. Needs a rename, a wrapper, or a different distribution model.

3. **It untars to `TMPDIR` per start**, which defaults to `/var/tmp` on Linux — shared, world-writable,
   and deterministic in path. On CI/containers a co-resident user could pre-plant a poisoned `.php`
   file there. Needs `TMPDIR` explicitly set on execution, or untars to a `.paider/` cache directory.

**FrankenPHP has no Windows static build at all** — the release matrix is 3 platforms, not 4. That
decision (no Windows for a compiled binary) stands on its own, but it is worth noting explicitly.

Commit: `db8b8f1`. Test suite: 201 passing, 697 assertions (hermetic); 3 live tests (real API calls),
currently failing due to credential config but skip cleanly in sandboxes.

## 17. Shell environment scrub — 2026-08-03

Fixed the residual risk recorded in §15: `ShellTool::execute()` and `GitTool::run()` both called
`proc_open()` with no `$env` argument, so every approved shell command and every git subprocess
inherited the FULL parent environment — including live provider API keys
(`OPENROUTER_API_KEY`, `ANTHROPIC_API_KEY`, `XAI_API_KEY`). An approved diagnostic script, or an
ordinary `git commit`, could hand a live credential straight back into the model's own context.

**Fix:** `App\Support\ShellEnv::build()` — a single shared helper, used by both call sites —
constructs an explicit env array from an ALLOWLIST, not a denylist: `PATH`, `HOME`, `LANG`,
`TERM`, `TMPDIR`, `USER`, `SHELL`. A denylist of secret-shaped names will always miss one; an
allowlist can only ever be too narrow, which fails safe. A user who genuinely needs another
variable passed through opts it back in with `PAIDER_SHELL_ENV_ALLOW` (comma-separated names),
read fresh on every command — nothing is cached, so it can be changed mid-session.

**What this covers:** every `run_shell` tool call (and, since `ArtisanTool` routes all its work
through `ShellTool`, every `run_artisan` call too) and every `git` tool call. All three routes
now see only the seven allowlisted variables plus whatever the user explicitly opted back in.

**What this does NOT cover:** this scrubs the *environment*, nothing else. It is not a sandbox —
an approved command can still read the filesystem, make network calls, or read a credential from
some OTHER source (a config file, `~/.netrc`, a keychain) that isn't environment-variable-shaped.
The §15 architectural caveat (tools calling `proc_open` directly instead of through `ShellEnv`
reopen this) still applies to any future proc_open call site — lint or code-review for it.
Regression test: `ShellToolTest.php` — sets a sentinel via `putenv()`, echoes it through
`run_shell`, asserts it does not appear in output; confirmed to fail if the `$env` argument is
removed.
## 18. Approval defence in depth — §15's residual risk #2 closed, 2026-08-03

§15 flagged that `read_file`, `write_file`, `patch_file`, and `git` trusted an `approved` key
if called directly, not through `Loop::dispatch()` — the entire defence against self-approval
rested on a single `unset()` at that one chokepoint.

Closed for those four tools by moving the approval decision off `$input` entirely: `Tool::execute()`
now takes a second `bool $approved = false` parameter that only the caller's own PHP can set — the
model's JSON tool-call payload becomes `$input` and never reaches it. The four tools' `inputSchema()`
no longer advertises `approved`, matching the convention `ArtisanTool` already used for `approval`.
`Loop::retryWithApproval()` and the undo-bookkeeping read in `Loop::previousContentFor()` pass the
decision as `execute($input, true)` instead of splicing `'approved' => true` into `$input`.

A future code path that reaches one of these four tools directly with model-shaped JSON — bypassing
`Loop::dispatch()` entirely — can no longer self-approve: there is no `$input` key left to trust.
`Loop::dispatch()`'s existing `unset($input['approval'], $input['approved'])` stays in place
unchanged; for these four it is now genuinely redundant-but-harmless insurance (real defence in
depth — two independent mechanisms, not one) rather than the sole defence.

`run_shell` and `artisan` are explicitly out of scope: they use a different, tri-state `approval`
key (`allow-once` / `allow-session` / `deny`) with an existing test contract, and were not named
in §15's residual risk #2. `Loop::dispatch()`'s `unset()` remains their sole defence.

New regression tests construct each of the four tools directly (no `Loop`) and pass a model-shaped
`$input` asserting `'approved' => true`; each must refuse. Verified these fail red if the fix is
reverted (temporarily read `$input['approved']` again in `ReadFileTool`, confirmed the new test
failed, restored). Test suite: 201 passing (hermetic), up from 186 baseline.
## 19. Served vs requested model id — resolved, not disputed — 2026-08-03

The money-path audit left one finding DISPUTED: "the served model id is parsed, then discarded —
the ledger prices the id you asked for, not the one you were billed for, and the log cannot be
reconciled against an invoice." Investigated by reading `AnthropicClient::send()` and
`OpenAiCompatibleClient::send()` and checking both providers' documented response shapes (no live
call made — none is permitted for this job).

**The finding was real.** Both clients decode a `$raw` response body and use only
`content`/`choices` and `usage` from it; neither ever reads `raw['model']`. Both wire formats
document a top-level `model` field naming what actually served the request:

- **Anthropic's Messages API** echoes `model` in every response, and Anthropic documents that an
  undated alias in the request (e.g. a `-latest` style id) can resolve to a dated snapshot in the
  response — the two are not guaranteed to match character-for-character.
- **OpenAI-compatible/OpenRouter chat-completions responses** carry the same top-level `model`
  field, and OpenRouter documents that routing and fallback can serve a different underlying model
  than the one requested, with the served id reported back in this field.

So the fix path applies, not the "not applicable" path. `ProviderResponse` gained a fourth,
defaulted `?string $servedModel` field (default `null` so every existing positional
`new ProviderResponse($content, $tokensIn, $tokensOut, $raw)` call site across the test suite kept
compiling unchanged). Both clients now parse `is_string($raw['model'] ?? null) ? $raw['model'] :
null` into it. `Loop::turn()` and `CommitCommand::handle()` both compute
`$servedModel = $response->servedModel ?? $requestedModel` — falling back to the requested id when
the provider/fixture reports none, which is what keeps every `raw: []` test double and every
legacy event unaffected — and now write **both** ids onto the `tier_call` payload: `model` (still
the field `ModelPricing::costFor()` prices against and `CostLedger` reads) now names the served id,
and a new `requested_model` key holds what was actually asked for. Same write-time-freeze
discipline as `cost_usd` (LOCKED #2/#3): whichever id was served at call time is what gets priced
and stored, permanently.

`CostLedger::summary()` gained `mismatched_calls` / `mismatched_models` (`"requested -> served"`
strings), computed with the same "absence is unknown, not false" rule as `unpriced_models` and
`hypothetical_unknown`: a row only counts as a mismatch when `requested_model` is present in its
payload AND differs from `model`; a legacy row with no `requested_model` key at all contributes
nothing. `paider cost` renders one line per affected tier below the existing unpriced-calls lines,
and `--json` gets a `model_mismatches` key alongside `unpriced_calls`.

**A separate, pre-existing bug was found along the way and is explicitly NOT fixed here:**
`AnthropicClient` sends OpenRouter-style slug ids (e.g. `anthropic/claude-opus-5`, the id
`config/presets.php` uses for the direct-Anthropic preset) straight into the `model` field of a
request to `api.anthropic.com`. Real Anthropic will not echo that slug format back — it will
report its own native dated-snapshot id, with no `anthropic/` prefix. Once this feature ships,
real production traffic through the direct-Anthropic preset will very likely start showing those
orchestrator-tier calls as **UNPRICED** (`config/prices.php` has no entry under the real served
id), not silently mispriced under the wrong id — an honest fail-loud outcome per LOCKED decision
#3, but a follow-up worth its own job to fix the request-side id mapping.

Tests added: `AnthropicClientTest` and `OpenAiCompatibleClientTest` each gained a served-model-
parsed and a served-model-absent case; `LoopToolCallProtocolTest` gained a case proving a served id
that differs from the resolved/requested id is what gets priced and recorded, with the requested id
recorded alongside it; `CostLedgerTest` and `CostCommandTest` each gained a mismatch-counting case
(table line and `--json` shape). Verified red/green: temporarily priced by the requested id instead
of the served id in `Loop::turn()`, confirmed the new Loop test failed on the expected assertion,
reverted, confirmed green again. Suite: 194 passing (666 assertions), still fully hermetic.
