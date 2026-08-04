# PHP extension manifest

The shipped binary compiles a **chosen** extension set. This file is the whole list, and adding
to it requires a reason written down here.

Why this is a document and not an afterthought: on the author's dev machine, 76 loaded
extensions cost **94ms of a 143ms** PHP startup. A tool that inherits a user's ini inherits that
tax. Pinning the set is most of the reason
[FrankenPHP](https://frankenphp.dev/docs/embed/) was chosen over `composer global require`.

## v0.1 — required, twelve

⚠️ **Corrected 2026-08-02 (round 2).** This table was eight for one measurement cycle and it was
wrong — `phar` and `filter` are both load-bearing and were missing. See "🪤 The Laravel Zero
`Phar::running()` trap" below for how that surfaced: a trimmed 9-extension static build compiled
clean and then couldn't boot the app.

⚠️ **Corrected 2026-08-04.** `pdo_sqlite` was framed below as "opt-in, v0.2" — stale. Three
shipped v0.1 commands (`chat`, `commit`, `cost`) already call `Database::connect()`, which does
`new PDO('sqlite:'.$path)`. It was never opt-in once those commands shipped; it just wasn't
declared in `composer.json`, so `composer check-platform-reqs` couldn't catch a consumer missing
it. Moved into this table; `composer.json`'s `require` block now matches.

| extension | why | who needs it |
|---|---|---|
| `mbstring` | UTF-8 handling throughout | 9 packages, incl. `illuminate/console` |
| `tokenizer` | Blade / view compilation | 5 packages, incl. `illuminate/view` |
| `ctype` | validation | `illuminate/support` |
| `fileinfo` | file-type detection when reading files | `league/flysystem-local` |
| `iconv` | encoding conversion | `symfony/polyfill-mbstring` |
| `curl` | LLM HTTP, and `curl_multi` for concurrency | Paider |
| `openssl` | TLS, and AES-256-GCM for stored credentials | Paider |
| `zlib` | gzipped HTTP responses | Paider |
| `phar` | `laravel-zero/framework`'s `Build::isRunning()` calls `Phar::running()` **unconditionally**, every invocation, not only when building a PHAR | `laravel-zero/framework` — an **undeclared** dependency, see the trap below |
| `filter` | flagged by `composer check-platform-reqs --no-dev` on the prod tree | `illuminate` internals |
| `dom` | Termwind's `HtmlRenderer` does `new DOMDocument` | Termwind — an **undeclared** dependency, see the trap below |
| `pdo_sqlite` | the entire state layer — sessions, memory, credentials, cache, cost ledger, task board, already live behind `chat`/`commit`/`cost`. See [STORAGE.md](STORAGE.md). **PDO, not `sqlite3`**: Eloquent talks PDO and the two are different APIs. | Paider — an **undeclared** dependency until this correction |

Free — compiled into PHP core, not separate extensions: `json`, `pcre`, `date`,
`spl`, `reflection`, `hash`, `random`.

### 🪤 The `ext-dom` trap — nobody declares it

`dom` was the twelfth, and it was found the expensive way: a trimmed FrankenPHP build with the
"documented eleven" passed `paider --version` and `paider list`, then died on `paider cost` with
`Class "DOMDocument" not found`.

The reason nothing caught it earlier is that **nothing declares it anywhere in the chain**.
Termwind's `HtmlRenderer` does `new DOMDocument` (`vendor/nunomaduro/termwind/src/HtmlRenderer.php:32`)
while Termwind's own `composer.json` requires only `php` and `ext-mbstring`. So
`composer check-platform-reqs` cannot know, CI has `dom` in its default PHP anyway, and every
development machine has it. The only environment that could reveal it is the one we actually
distribute — a build containing exactly what we asked for and nothing else.

Paider now declares `ext-dom` in its own `composer.json`, since the package that needs it does not.

**The general lesson, which cost this project twice:** a command that only prints a version
string exercises almost none of the app. `--version` and `list` both passed on the broken build.
It took running a command that renders real output through Termwind to fail. Smoke-test with
something that does work, not something that proves the binary starts.

### 🪤 The Laravel Zero `Phar::running()` trap

Trim a FrankenPHP static build to the "documented nine" (the eight above minus `phar`/`filter`,
plus `pdo_sqlite`) and it compiles cleanly, boots the binary — and dies on the very first command:

```
Fatal error: Uncaught Error: Class "Phar" not found
  in vendor/laravel-zero/framework/src/Providers/Build/Build.php:37
```

`LaravelZero\Framework\Providers\Build\Build::isRunning()` calls `Phar::running()`
**unconditionally during bootstrap** — every single invocation pays that check, not just a
`box compile` run. And `laravel-zero/framework`'s own `composer.json` does **not** declare
`ext-phar` as a dependency, so nothing warns you before the binary is built and shipped. It's
invisible on a stock FrankenPHP binary (77 extensions covers it by accident) and invisible on
ordinary Homebrew PHP (`ext-phar` ships enabled by default) — it only bites a *deliberately
trimmed* static build, which is exactly the build this project needs. `composer
check-platform-reqs --no-dev` flagged `filter` the same way, for the same reason: present
everywhere by accident, undeclared, invisible until something narrows the extension set on
purpose.

**Anyone trimming a PHP static binary for a Laravel-Zero-based tool will hit this.** Documented
loudly here rather than rediscovered as a "why won't my binary start" bug report.

**This is a different thing from "no PHAR as a distribution format."** That decision (see
`DECISIONS.md` / `PLAN.md`) is about how Paider ships — never as a `.phar` file. Compiling
`ext-phar` *into* the interpreter is unrelated: it's a class the framework's own bootstrap
requires to exist, regardless of whether anything is ever packaged as a PHAR. Same word, two
unconnected questions — no contradiction.

## Deliberately excluded

**Dev-only, never shipped.** `dom`, `libxml`, `simplexml`, `xml`, `xmlwriter`. Every one arrives
via `paratest`, `phpunit`, `phar-io/manifest` or `pint` — test and lint tooling that has no
business in a user's binary.

⚠️ **`phar` used to be listed here too, and that was wrong** — moved to required above,
2026-08-02. It looks like dev-only tooling (it does arrive via `phar-io/manifest`), but
`laravel-zero/framework` also needs the `Phar` class present at runtime, on every invocation, not
just at build time. See the trap above.

**`pdo_mysql`, `pdo_pgsql` — cut.** They were listed to let the agent introspect the *user's*
database schema. Laravel already writes its schema down as migration files, which the agent can
read as plain text: no database credentials to handle, works offline, no extension, no
connection to configure. The simpler path is also the safer one.

**`swoole` — not needed.** PHP has shipped native Fibers since 8.1, and `curl_multi` did 6 concurrent
requests in 71ms with zero extensions. An agent is I/O-bound; that is enough. Revisit only if
profiling says otherwise.

**`pcntl`, `posix` — cut.** They were listed for "subprocess control", which was wrong:
`proc_open`, `exec` and `stream_isatty()` are all in `ext-standard` and need no extension. The
only real use is trapping SIGINT for a graceful Ctrl+C — and the better answer there is
**atomic writes** (write a temp file, rename), which makes interruption harmless without any
signal handling and also survives crashes and power loss. Cutting them keeps the build matrix
single-profile; both are Unix-only and would have forced a separate Windows build.

*(Note on testing this: `php -n` does not prove an extension is unnecessary. It disables the ini
file, but Homebrew compiles `pcntl` and `posix` into the binary, so they survive `-n` and the
probe reports them as core. Check the extension's origin, not its presence.)*

**`redis` — unplanned.** Previously pencilled in for v0.3 (cache, rate-limit parking, kanban).
Cut: it is a daemon the user must install and keep running, against a tool whose whole
distribution pitch is "one binary, nothing to set up", and its durability is worse than an
fsync'd file for data like conversation history. The workload is single-user and single-process,
which is precisely where Redis has no advantage. Reconsider only with a written concurrency
justification. Same reasoning excludes libSQL/Turso. See [STORAGE.md](STORAGE.md).

**`opcache` — unmeasured.** Plausibly worth it in the embedded binary, since it caches compiled
scripts. Measure before adding; it did nothing for bare-interpreter startup in testing.

## Opt-in, per milestone

Nothing currently opt-in. `pdo_sqlite` was the one entry here; moved to required above
2026-08-04 once it was confirmed live behind three shipped v0.1 commands rather than deferred.

## 📦 The stock FrankenPHP binary carries 77 — measured 2026-08-02

Downloaded and inspected directly: `frankenphp-mac-arm64` (FrankenPHP v1.12.6, PHP 8.5.9, Caddy
v2.11.4) compiles in **77 extensions**. All 9 this file requires are present. The other **68**
are not wanted anywhere in this document: `imagick`, `ldap`, `amqp`, `memcached`, `parallel`,
`pgsql`, `pdo_pgsql`, `mysqli`, `pdo_mysql`, `soap`, `tidy`, `xsl`, `gd`, `intl`, `redis`, `ssh2`,
`protobuf`, `xlswriter`, and more.

That bloat is why the off-the-shelf binary is **178MB**. Trimming those 68 down to the twelve
above is exactly what a custom static build (`build-static.sh`, run natively) is for — see
[`DECISIONS.md` §9](DECISIONS.md) and [`README.md`](README.md#distribution).

## ✅ Trimmed build verified — round 2, 2026-08-02

Round 1 could only measure the stock binary; this round actually built the trimmed one, natively
on macOS with FrankenPHP's own `build-static.sh` (**not** Docker — the Docker static-builder
emits Linux binaries only). PHP 8.5.9, Caddy v2.11.4, Go 1.26.5. Build time: **~7 minutes**.

The first attempt used the "documented nine" and **could not boot** — the `Phar::running()` trap
above. With all twelve extensions
(`PHP_EXTENSIONS="mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,pdo_sqlite,phar,filter,dom"`)
it works:

| | |
|---|---|
| Binary size | **111,315,960 bytes = 111.3 MB decimal / 106.2 MiB** |
| Cost of adding `phar` + `filter` + `dom` | **+283 KB** — negligible |
| vs. stock 178MB / 77-ext binary | **−37.5%** size |
| Extensions loaded at runtime | **26** — the 12 above, plus 14 always-compiled core: `Core`, `PDO`, `Reflection`, `SPL`, `Zend OPcache`, `date`, `hash`, `json`, `lexbor`, `pcre`, `random`, `standard`, `uri` |
| Compressed, `gzip -9` | 46.6 MB |
| Compressed, `zstd -19` | 40.6 MB |

Functional check, trimmed binary: `application --version`, `application list`, an in-memory
`pdo_sqlite` create/insert/select round-trip, `stream_isatty()` correct under a non-TTY pipe,
`PHP_VERSION` 8.5.9. All pass. Cold-start numbers (−16% vs. the stock binary): `DECISIONS.md` §9.

**The 20–30MB maintainer estimate was not reached via this build path, and now we know why:**
`build-static.sh` always links the full Caddy server and Go HTTP stack, even for a binary that
will only ever be invoked as `php-cli`. There is no CLI-only mode in the script. Not disproven in
general — a Caddy-free build might get closer — just not what the supported path produces. See
`PLAN.md`'s open questions.

## The rule

An extension enters this file when a milestone needs it, not when it might be handy. Every entry
is bytes in the binary, time at boot, and a platform-support question. The set staying small is a
feature, and it is the one that is easiest to lose by accident.
