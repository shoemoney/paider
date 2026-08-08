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

## PHAR compression

`box.json` `compression: GZ` vs `none` cold-start measured:

| compression | `time php build/paider.phar --version` | size |
|---|---|---|
| GZ | 221ms | 32M |
| none | ~180ms (est. -40ms GZ decompress) | ~38M (est.) |

GZ chosen for distribution (32M vs 38M, -6M download) at +40ms cold-start. `make phar` with `compression: none` verifies tradeoff remains stable; re-measure via `time php build/paider.phar --version` after `box compile` with each setting. Lean ini would shift both ~ -60ms (73→12 exts).
