#!/usr/bin/env bash
#
# Capture the REAL Paider TUI to design/captures/paider-tui.png.
#
# WHY THIS FILE EXISTS. The hero image that shipped in the README was a double exposure: the TUI
# superimposed on a screenshot of the README page itself, with a smeared spinner across the top.
# It is the first thing every visitor to the Packagist page sees, and it looks broken. It was
# also actively harmful: an adversarial review of this project read that composite image and
# reported a "critical" TUI defect — that the banner overflows and overlaps the input box — which
# does not exist. The banner is 7 lines and 222 characters. The reviewer was reading the README
# bleed-through as UI.
#
# A corrupt artifact does not just look wrong, it makes people (and models) draw false
# conclusions about the code. So the capture is now scripted and repeatable rather than a
# hand-taken screenshot that can only be re-taken by hand.
#
# WHAT IT DOES. Runs the real `./paider chat` inside a pty, because the TUI uses interactive
# prompts that will not render to a pipe — `script` gives it a terminal, and the output is
# captured with its ANSI intact. Then converts ANSI to an image, which is the fiddly part: a
# terminal recording is a stream of escape sequences, and a naive render shows the escape codes
# as literal text (which is what a hand-made capture usually ends up looking like).
#
# Usage: bash bin/capture-tui.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

out="$root/design/captures/paider-tui.png"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

echo "==> driving the real TUI in a pty"
# 'exit' closes the session; the greeting is enough to show the prompt and the input box.
# A provider key is NOT required — the capture is the startup UI, before any model call.
# `script -q /dev/null` would discard the typescript; writing it to a file is the point.
# A wall-clock timeout is mandatory: the TUI opens an interactive prompt, and if `exit` is not
# consumed the pty just sits there waiting for a human forever.
printf 'exit\n' | timeout 25 script -q "$tmpdir/raw.ansi" ./paider chat >/dev/null 2>&1 || true

if [ ! -s "$tmpdir/raw.ansi" ]; then
    echo "capture produced no output" >&2
    exit 1
fi

echo "==> rendering ANSI to PNG"

# The renderer has to understand: SGR colour/attributes, cursor-up + erase (the prompt box is
# redrawn in place, so a naive line-by-line render double-exposes it exactly like the broken
# image did), and carriage returns.
# The renderer ships with the project rather than being a brew dependency: it is ~200 lines,
# has to understand this app's specific escape usage, and a capture tool that needs a package
# nobody installed is a capture tool that stops being run.
php "$root/bin/ansi-to-html.php" "$tmpdir/raw.ansi" "$tmpdir/capture.html"

# Frame the output on a dark ground, which is what a terminal actually looks like, and let the
# art's own colours carry it. Without the background the ANSI background colours have nothing to
# sit on and half the palette washes out.
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

"$CHROME" --headless --disable-gpu --hide-scrollbars \
    --force-device-scale-factor=2 \
    --window-size=1760,560 \
    --screenshot="$out" \
    "file://$tmpdir/capture.html" 2>/dev/null

# Crop to the content. Chrome's screenshot is the window box, and a short window still carries
# slack under short content — a hero image that is 80% empty dark pixels reads as a broken
# screenshot even though it is technically accurate. Anchored to the TOP, because sips crops
# from the centre by default and centring a short capture in a tall frame throws away the
# wordmark.
sips -c 1120 3520 --cropOffset 0 0 "$out" --out "$out" >/dev/null 2>&1 || true

echo "==> wrote $out"
sips -g pixelWidth -g pixelHeight "$out" 2>/dev/null | tail -2
