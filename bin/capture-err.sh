#!/usr/bin/env bash
#
# Capture the REAL error path to .aaa/err.html and .aaa/shot-err.png.
#
# WHY THIS FILE EXISTS. bin/capture-tui.sh exists because a hand-taken screenshot of the TUI had
# been composited into a double exposure, and an adversarial reviewer then read the bleed-through
# as a real UI defect — a "critical" banner overflow that does not exist. The lesson was that a
# capture nobody can retake is a capture that quietly stops matching the code.
#
# The error path then got judged in cycle 8, and it was captured BY HAND — a malformed mcp.json,
# a run, and an image assembled in an ad-hoc shell line that left no record of itself. That is the
# same failure one cycle earlier, in a different costume: the frame in .aaa/ was real, but there
# was no way to take it again. When the crash rolled back the working tree, the recipe went with
# the temporary directory and the frame could not be regenerated. This script is the recipe.
#
# WHAT IT DOES. Writes a deliberately malformed MCP config — a trailing comma, the single most
# common way a hand-edited JSON file breaks — points PAIDER_MCP_CONFIG at it, and runs a real
# command with MCP enabled. McpClient::tools() is called at `chat` startup, so the parse failure
# surfaces as a genuine uncaught RuntimeException rendered by Collision, on a real terminal, with
# real ANSI. Then the same renderer the TUI capture uses turns it into HTML, and Chrome shoots it.
#
# The frame is the EVIDENCE for cycle 8's one real finding: that the message named the file and
# the parse error but not the remedy. Collision renders the trace either way, so the actionable
# half has to live in the message itself.
#
# Usage: bash bin/capture-err.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

outdir="$root/.aaa"
raw="$(mktemp)"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir" "$raw"' EXIT

# The demo lives under /tmp, never in the repo: it is a broken config on purpose, and a checked-in
# file that fails to parse is a trap for the next person who runs a glob over the tree. The path is
# FIXED rather than mktemp'd, because this frame is committed as evidence — a capture whose own text
# changes every run cannot be diffed against the last one, and "did the message change" is the only
# question this file exists to answer. Wiped and rebuilt on every run so nothing is inherited.
demo=/tmp/paider-errdemo
rm -rf "$demo"
mkdir -p "$demo/.paider"
printf '{ "mcpServers": { "demo": { "command": "nope" }, } }\n' > "$demo/.paider/mcp.json"

echo "==> malformed config at $demo/.paider/mcp.json (trailing comma)"
echo "==> running the real command with MCP on"

# A pty is not strictly required — Collision detects a non-tty and drops colour, which would make
# the frame less useful for judging the palette. `script` gives it a terminal so the SGR survives.
# The timeout is mandatory: `chat` opens an interactive prompt, and a run that is not bounded here
# sits waiting for a human forever.
printf 'exit\n' | PAIDER_MCP=1 PAIDER_MCP_CONFIG="$demo/.paider/mcp.json" \
    timeout 30 script -q "$raw" ./paider chat >/dev/null 2>&1 || true

if [ ! -s "$raw" ]; then
    echo "capture produced no output" >&2
    exit 1
fi

# The gate the loop's own DOSSIER step demands: a frame that is present but blank is worse than no
# frame, because a reviewer judges an empty image as a rendering bug and files it. Byte size plus
# a real content marker, so "the file exists" can never be mistaken for "the frame has content".
if [ "$(wc -c < "$raw")" -lt 400 ]; then
    echo "capture is too small to be a real error frame ($(wc -c < "$raw") bytes)" >&2
    exit 1
fi
grep -q 'RuntimeException' "$raw" || {
    echo "capture contains no RuntimeException — the error path did not fire" >&2
    exit 1
}

echo "==> rendering ANSI to HTML"
# The renderer reports how many rows it drew. That number is what sizes the window below, which
# is the whole reason to capture it rather than discard it.
rows="$(php "$root/bin/ansi-to-html.php" "$raw" "$outdir/err.html" | sed -n 's/^rendered \([0-9]*\) rows.*/\1/p')"

if [ -z "$rows" ]; then
    echo "could not read the rendered row count out of the renderer" >&2
    exit 1
fi

if command -v chromium >/dev/null 2>&1; then
    CHROME=chromium
elif command -v google-chrome >/dev/null 2>&1; then
    CHROME=google-chrome
elif [ -x "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" ]; then
    CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
else
    echo "need chrome/chromium to rasterise the HTML" >&2
    exit 1
fi

# Size the window to the content, in CSS pixels: 22.5px per row is the stylesheet's own
# 15px/1.5 line-height, plus the 30px top and bottom padding and a little slack.
#
# WHY NOT `sips -c` afterwards. That is what this script did first, copying bin/capture-tui.sh,
# and it is WRONG here: `sips --cropOffset 0 0` did not anchor to the top — it cropped from the
# middle, which silently removed the `RuntimeException` header and the entire remedy line, i.e.
# precisely the two lines the frame exists to prove. A crop that eats the subject produces a
# plausible, non-empty, well-formed PNG, so nothing fails and the evidence is quietly wrong.
# Sizing the window to the content means there is nothing to crop and nothing to get wrong.
height=$(( rows * 23 + 76 ))

echo "==> shooting PNG ($rows rows, ${height}px window, no crop)"
"$CHROME" --headless --disable-gpu --hide-scrollbars \
    --force-device-scale-factor=2 \
    --window-size=1760,"$height" \
    --screenshot="$outdir/shot-err.png" \
    "file://$outdir/err.html" 2>/dev/null

# The gate, restated on the artifact rather than on the stream: a committed evidence frame that
# lost the message is worse than no frame, because it looks like proof. Both halves are checked —
# the remedy is present, and the malformed config's contents are NOT.
grep -q 'Fix the JSON' "$outdir/err.html" || {
    echo "frame is missing the remedy line — the capture is not evidence of anything" >&2
    exit 1
}
if grep -q 'nope' "$outdir/err.html"; then
    echo "frame leaks the malformed config's contents" >&2
    exit 1
fi

echo "==> wrote $outdir/err.html and $outdir/shot-err.png"
sips -g pixelWidth -g pixelHeight "$outdir/shot-err.png" 2>/dev/null | tail -2
