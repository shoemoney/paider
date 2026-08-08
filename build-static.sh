#!/usr/bin/env bash
# build-static.sh — FrankenPHP static binary (trimmed, native build only)
# See DECISIONS.md §9 and PLAN-PHASES-1-10.md Phase 7.
# This is a scaffold: documents the 3 deferred blockers and the build invocation.
# Docker static-builder emits Linux binaries only; macOS must build natively via FrankenPHP's own build-static.sh.

set -euo pipefail

# Deferred blockers (from install.sh header / DECISIONS.md):
# 1. Invocation is "<binary> php-cli paider <cmd>" not "./paider <cmd>"
# 2. Naming collision: a binary named "paider" next to a "paider" script makes PHP include the 178MB binary and OOM
# 3. Untar to $TMPDIR (often world-writable) on every start

echo "==> build-static.sh — FrankenPHP trimmed static binary"
echo "    Blockers:"
echo "      1. invocation: <binary> php-cli paider <cmd>"
echo "      2. naming collision: paider binary vs paider script -> OOM include"
echo "      3. TMPDIR world-writable untar on every start"
echo ""

if [ "${1:-}" = "--check" ]; then
  echo "check: documenting blockers only"
  exit 0
fi

# Actual build — requires FrankenPHP repo and host deps (re2c, go, etc.)
# See https://github.com/dunglas/frankenphp/blob/main/docs/static.md
# Example (trimmed, 12 exts only):
#   git clone https://github.com/dunglas/frankenphp
#   cd frankenphp
#   EMBED=../paider/paider ./build-static.sh --php-extension=mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom

if [ ! -d "frankenphp" ]; then
  echo "frankenphp repo not cloned — run: git clone https://github.com/dunglas/frankenphp" >&2
  echo "Then: EMBED=\$(pwd)/paider ./frankenphp/build-static.sh --php-extension=mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom" >&2
  exit 1
fi

echo "Found frankenphp/ — invoking build-static.sh with trimmed extension set..."
EMBED="$(pwd)/paider" ./frankenphp/build-static.sh --php-extension=mbstring,tokenizer,ctype,fileinfo,iconv,curl,openssl,zlib,phar,filter,pdo_sqlite,dom
echo "done — binary at frankenphp/frankenphp-*"
ls -lh frankenphp/frankenphp-* 2>/dev/null | tail -n 5
