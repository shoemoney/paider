# Startup profiling — 48.6ms floor

Measured via `bin/profile-startup.php` (hrtime around autoload + bootstrap) and `time paider --version`.

| measurement | value |
|---|---|
| bare PHP | 47.5ms (hello-world, FrankenPHP overhead baseline) |
| bare PHP with paider autoload+bootstrap | 7.5ms (autoload 5.3 + bootstrap 2.1) |
| `paider --version` on dev ini (73 exts) | 182ms |
| lean ini baseline (DECISIONS.md §3) | 95.9ms |
| budget | ≤120ms lean |

Gate: `php bin/profile-startup.php` must report `PASS ≤120ms` on lean ini. Dev ini is 73 exts (see `bin/check-exts.php`), lean is 12 allowlisted only (see EXTENSIONS.md, m1/preflight.sh, tests.yml).

## Allowlist

12 required: `mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom`

Enforced by:
- `m1/preflight.sh` (fails if missing)
- `.github/workflows/tests.yml` setup-php extensions (explicit list)
- `bin/check-exts.php` (informational PASS/extra list)
