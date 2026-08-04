#!/bin/sh
# install.sh — install Paider via Composer.
#
# Distribution scope: composer-only. The FrankenPHP standalone-binary channel
# is intentionally NOT implemented here — see DECISIONS.md:609-613. Summary of
# why: the binary is invoked as `<binary> php-cli paider <cmd>`, not
# `./paider <cmd>`; naming a binary `paider` in a directory that also has a
# `paider` script makes PHP try to `include` the 178MB binary and OOM; and it
# untars to $TMPDIR (often world-writable) on every start. None of that is
# reachable from this script.
#
# ponytail: composer-only, add binary channel only if DECISIONS.md's three
# deferred issues (invocation, naming collision, TMPDIR world-writable untar)
# get resolved.
set -eu

PREFIX="${PREFIX:-$HOME/.local/bin}"
PACKAGE="paider/paider"
DRY_RUN=0

usage() {
    cat <<'EOF'
install.sh — install Paider (paider/paider) via Composer

Usage:
  ./install.sh [options]
  curl -fsSL <url> | sh

Options:
  -h, --help       show this help and exit
  --dry-run        run all checks, print what would happen, take no action

Environment:
  PREFIX            directory to symlink the paider binary into
                     (default: $HOME/.local/bin)

What it does:
  1. Verifies PHP >= 8.4 and required extensions (dom, mbstring, tokenizer,
     pdo_sqlite) are loaded.
  2. Installs paider/paider with `composer global require`, using an
     already-installed `composer` if found, else downloading and
     signature-verifying composer.phar into a temp dir.
  3. Symlinks the resulting `paider` binary into PREFIX.
  4. Warns if PREFIX is not on PATH.

Does NOT install a standalone binary — see the top-of-file comment in
install.sh for why the FrankenPHP channel is out of scope.
EOF
}

for arg in "$@"; do
    case "$arg" in
        -h|--help)
            usage
            exit 0
            ;;
        --dry-run)
            DRY_RUN=1
            ;;
        *)
            echo "install.sh: unknown option: $arg" >&2
            usage >&2
            exit 1
            ;;
    esac
done

echo "==> Detecting platform"
case "$(uname -s)" in
    Darwin) os=darwin ;;
    Linux) os=linux ;;
    *)
        echo "error: unsupported OS: $(uname -s)" >&2
        exit 1
        ;;
esac

case "$(uname -m)" in
    arm64|aarch64) arch=arm64 ;;
    x86_64|amd64) arch=x86_64 ;;
    *) arch="unknown ($(uname -m))" ;;
esac
echo "    os=$os arch=$arch"

echo "==> Checking PHP"
if ! command -v php >/dev/null 2>&1; then
    echo "error: PHP not found. Install PHP >= 8.4 and re-run." >&2
    exit 1
fi

php_version_line="$(php -v | head -1)"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.4.0", "<") ? 1 : 0);'; then
    echo "error: PHP version too old." >&2
    echo "    found:    $php_version_line" >&2
    echo "    required: >= 8.4" >&2
    exit 1
fi
echo "    $php_version_line"

echo "==> Checking PHP extensions"
missing=""
for ext in dom mbstring tokenizer pdo_sqlite; do
    if ! php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        missing="$missing $ext"
    fi
done
if [ -n "$missing" ]; then
    echo "error: missing required PHP extension(s):$missing" >&2
    exit 1
fi
echo "    ok: dom mbstring tokenizer pdo_sqlite"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "==> Dry run: no network or install actions will be taken"
    if command -v composer >/dev/null 2>&1; then
        echo "    would run: composer global require $PACKAGE --no-interaction"
    else
        echo "    composer not found on PATH"
        echo "    would fetch and signature-verify composer.phar into a temp dir,"
        echo "    then run: php <tmpdir>/composer.phar global require $PACKAGE --no-interaction"
    fi
    echo "    would resolve composer's global bin-dir"
    echo "    would symlink <bin-dir>/paider -> $PREFIX/paider"
    echo "    would verify $PREFIX/paider runs"
    echo "PREFIX=$PREFIX"
    exit 0
fi

echo "==> Resolving Composer"
COMPOSER_CMD=""
if command -v composer >/dev/null 2>&1; then
    COMPOSER_CMD="composer"
    echo "    using existing composer: $(command -v composer)"
else
    echo "    composer not found, fetching composer.phar"
    if ! command -v curl >/dev/null 2>&1; then
        echo "error: curl is required to fetch composer.phar but was not found." >&2
        exit 1
    fi

    tmpdir="$(mktemp -d)"
    trap 'rm -rf "$tmpdir"' EXIT

    expected_signature="$(curl -fsSL https://composer.github.io/installer.sig)"
    if [ -z "$expected_signature" ]; then
        echo "error: could not fetch composer installer signature." >&2
        exit 1
    fi

    php -r "copy('https://getcomposer.org/installer', '$tmpdir/composer-setup.php');"
    actual_signature="$(php -r "echo hash_file('sha384', '$tmpdir/composer-setup.php');")"

    if [ "$expected_signature" != "$actual_signature" ]; then
        echo "error: composer installer signature mismatch, aborting." >&2
        echo "    expected: $expected_signature" >&2
        echo "    actual:   $actual_signature" >&2
        exit 1
    fi
    echo "    signature verified"

    php "$tmpdir/composer-setup.php" --quiet --install-dir="$tmpdir" --filename=composer.phar
    COMPOSER_CMD="php $tmpdir/composer.phar"
    echo "    installed composer.phar to $tmpdir"
fi

echo "==> Installing $PACKAGE"
$COMPOSER_CMD global require "$PACKAGE" --no-interaction

bin_dir="$($COMPOSER_CMD global config bin-dir --absolute)"
if [ ! -x "$bin_dir/paider" ]; then
    echo "error: expected composer bin at $bin_dir/paider but it is missing or not executable." >&2
    exit 1
fi
echo "    composer bin-dir: $bin_dir"

echo "==> Linking into PREFIX"
mkdir -p "$PREFIX"
ln -sf "$bin_dir/paider" "$PREFIX/paider"
echo "    linked $PREFIX/paider -> $bin_dir/paider"

echo "==> Verifying install"
if ! "$PREFIX/paider" --version >/dev/null 2>&1; then
    echo "error: $PREFIX/paider did not run. Symlink or install is broken." >&2
    exit 1
fi
# ponytail: --version is a launch-confirmation only, not proof the whole app
# works — DECISIONS.md documents that as a weak smoke test in general. It's
# acceptable here because this script's job ends at "the binary launches".
echo "    ok: $PREFIX/paider --version runs"

path_status="on PATH"
case ":$PATH:" in
    *":$PREFIX:"*) ;;
    *)
        path_status="NOT on PATH"
        ;;
esac

echo ""
echo "==> Summary"
echo "    composer:  $COMPOSER_CMD"
echo "    bin-dir:   $bin_dir"
echo "    installed: $PREFIX/paider"
echo "    PATH:      $path_status"
if [ "$path_status" != "on PATH" ]; then
    echo ""
    echo "    Add this to your shell rc (~/.zshrc, ~/.bashrc, ...):"
    echo "        export PATH=\"$PREFIX:\$PATH\""
fi
