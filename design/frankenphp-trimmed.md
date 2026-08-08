# FrankenPHP trimmed build — Phase 7 scaffold

Stock FrankenPHP v1.12.6 is 178MB (see DECISIONS.md §8). Trimmed build (12 exts only) is the conditional for distribution via static binary.

## Blockers (deferred)

1. Invocation is `<binary> php-cli paider <cmd>` not `./paider <cmd>`
2. Naming collision: binary named `paider` next to `paider` script → PHP `include` of 178MB binary → OOM
3. Untar to `$TMPDIR` (world-writable) on every start

These are documented in `install.sh` header and DECISIONS.md §9. Do not ship static binary until resolved.

## Build invocation (native, not Docker)

Docker `static-builder.Dockerfile` emits Linux only. macOS must build natively:

```bash
git clone https://github.com/dunglas/frankenphp
cd frankenphp
EMBED=../paider/paider ./build-static.sh --php-extension=mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom
```

Or use scaffold:

```bash
./build-static.sh --check   # docs only
./build-static.sh            # attempts native build if frankenphp/ cloned
```

## Measurements to re-take on trimmed binary

- Size (target <80MB)
- Cold start: `time frankenphp php-cli paider --version` vs `time php paider --version`
- `stream_isatty()` under pipe
- `pdo_sqlite` round-trip
