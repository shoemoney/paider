# PHP extension manifest

The shipped binary compiles a **chosen** extension set. This file is the whole list, and adding
to it requires a reason written down here.

Why this is a document and not an afterthought: on the author's dev machine, 76 loaded
extensions cost **94ms of a 143ms** PHP startup. A tool that inherits a user's ini inherits that
tax. Pinning the set is most of the reason
[FrankenPHP](https://frankenphp.dev/docs/embed/) was chosen over `composer global require`.

## v0.1 — required, eight

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

Free — compiled into PHP core, not separate extensions: `json`, `filter`, `pcre`, `date`,
`spl`, `reflection`, `hash`, `random`.

## Deliberately excluded

**Dev-only, never shipped.** `dom`, `libxml`, `simplexml`, `xml`, `xmlwriter`, `phar`. Every one
arrives via `paratest`, `phpunit`, `phar-io/manifest` or `pint` — test and lint tooling that has
no business in a user's binary.

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

| extension | milestone | why |
|---|---|---|
| `pdo_sqlite` | v0.2 | the entire state layer — sessions, memory, credentials, cache, cost ledger, task board. See [STORAGE.md](STORAGE.md). **PDO, not `sqlite3`**: Eloquent talks PDO and the two are different APIs. |

Nothing else is planned. That is the point.

## 📦 The stock FrankenPHP binary carries 77 — measured 2026-08-02

Downloaded and inspected directly: `frankenphp-mac-arm64` (FrankenPHP v1.12.6, PHP 8.5.9, Caddy
v2.11.4) compiles in **77 extensions**. All 9 this file requires are present. The other **68**
are not wanted anywhere in this document: `imagick`, `ldap`, `amqp`, `memcached`, `parallel`,
`pgsql`, `pdo_pgsql`, `mysqli`, `pdo_mysql`, `soap`, `tidy`, `xsl`, `gd`, `intl`, `redis`, `ssh2`,
`protobuf`, `xlswriter`, and more.

That bloat is why the off-the-shelf binary is **176MB**. Trimming those 68 down to the 9 above is
exactly what a custom static build (`static-builder.Dockerfile` or native `static-php-cli`) is
for — see [`DECISIONS.md` §8](DECISIONS.md) and [`README.md`](README.md#distribution). That
trimmed build is not yet built or verified; the "chosen set" in this file stays the target
regardless of which build tool gets there.

## The rule

An extension enters this file when a milestone needs it, not when it might be handy. Every entry
is bytes in the binary, time at boot, and a platform-support question. The set staying small is a
feature, and it is the one that is easiest to lose by accident.
