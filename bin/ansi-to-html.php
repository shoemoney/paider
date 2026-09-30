<?php

/**
 * Render a captured ANSI terminal stream to HTML, so it can be rasterised to a PNG.
 *
 * The reason this exists: the README's hero image shipped as a DOUBLE EXPOSURE — the TUI
 * superimposed on a screenshot of the README page, with a smeared spinner over the top. Making
 * that by hand is what went wrong. Rendering the real capture instead means the image is
 * generated, repeatable, and cannot silently become a different picture than the code produces.
 *
 * ## Why not just pipe it to a file
 *
 * Because a terminal stream is not text. Three things have to be understood or the output is
 * visibly wrong:
 *
 *   1. SGR sequences (`ESC[...m`) carry colour and attributes. Ignored, you get a page of escape
 *      codes as literal characters — which is what a hand-rolled capture usually looks like.
 *   2. The prompt box is REDRAWN IN PLACE: `ESC[nA` to move up, `ESC[J` to erase down. A renderer
 *      that appends instead of honouring those double-exposes the box over itself — producing
 *      precisely the ghosting that made the original image look broken.
 *   3. Carriage returns move the cursor to column 0 without clearing. The spinner is `\r`-driven,
 *      so ignoring them paints every frame on top of each other.
 *
 * So this is a small terminal emulator, not a filter: it keeps a screen buffer and replays
 * cursor motion onto it. Bounded to what the capture actually uses.
 *
 * Usage: php bin/ansi-to-html.php <raw.ansi> <out.html>
 */
$raw = $argv[1] ?? null;
$out = $argv[2] ?? null;

if ($raw === null || $out === null || ! is_file($raw)) {
    fwrite(STDERR, "usage: php bin/ansi-to-html.php <raw.ansi> <out.html>\n");
    exit(1);
}

$stream = (string) file_get_contents($raw);

const DEFAULT_COLS = 180;
const DEFAULT_ROWS = 40;

/** @var array<int, array<int, array{0: string, 1: array<string, mixed>}>> $screen */
$screen = [];

/** @var array<int, array{fg: ?string, bg: ?string, bold: bool, dim: bool, italic: bool, underline: bool, inverse: bool}> $style */
$style = [];

// Baseline style: no colour, no attributes.
$currentStyle = static fn (): array => [
    'fg' => null, 'bg' => null, 'bold' => false, 'dim' => false,
    'italic' => false, 'underline' => false, 'inverse' => false,
];

$style[0] = $currentStyle();

$row = 0;
$col = 0;

$put = static function (string $char) use (&$screen, &$style, &$row, &$col): void {
    if (! isset($screen[$row][$col])) {
        $screen[$row][$col] = [$char, $style[$row] ?? $style[0]];
    }
};

$length = strlen($stream);
$i = 0;

while ($i < $length) {
    $byte = $stream[$i];

    // ESC [ ... <final byte>  — a CSI sequence.
    if ($byte === "\x1b" && ($stream[$i + 1] ?? '') === '[') {
        $j = $i + 2;
        $params = '';

        // Parameter and intermediate bytes run until a final byte in 0x40-0x7E. The '?' and
        // '<' intermediates (private modes like ESC[?25l, cursor-shape changes) are part of the
        // sequence and MUST be consumed — letting them fall through to the text layer is what
        // printed literal "[;4m" all over the first attempt at this renderer.
        while ($j < $length && ! (ord($stream[$j]) >= 0x40 && ord($stream[$j]) <= 0x7E)) {
            $params .= $stream[$j];
            $j++;
        }

        $final = $stream[$j] ?? '';
        $private = str_contains($params, '?') || str_contains($params, '<');
        $nums = $private
            ? []
            : array_map('intval', array_values(array_filter(explode(';', $params), 'strlen')));

        // 24-bit colour is used heavily by the palette this app ships, so it is the common case,
        // not an edge case: ESC[38;2;R;G;Bm foreground and ESC[48;2;R;G;Bm background.
        $hasTrueColour = ! $private
            && ($final === 'm')
            && (in_array(38, $nums, true) || in_array(48, $nums, true));

        if ($hasTrueColour) {
            $s = $style[$row] ?? $style[0];

            for ($n = 0; $n < count($nums); $n++) {
                $code = $nums[$n];

                if (($code === 38 || $code === 48) && ($nums[$n + 1] ?? null) === 2) {
                    $hex = sprintf('#%02x%02x%02x', $nums[$n + 2] ?? 0, $nums[$n + 3] ?? 0, $nums[$n + 4] ?? 0);

                    if ($code === 38) {
                        $s['fg'] = $hex;
                    } else {
                        $s['bg'] = $hex;
                    }

                    $n += 4;
                }
            }

            $style[$row] = $s;
            $i = $j + 1;

            continue;
        }

        switch ($final) {
            case 'm': // SGR — colour and attributes
                $s = $style[$row] ?? $style[0];

                foreach ($nums === [] ? [0] : $nums as $n) {
                    if ($n === 0) {
                        $s = $currentStyle();
                    } elseif ($n === 1) {
                        $s['bold'] = true;
                    } elseif ($n === 2) {
                        $s['dim'] = true;
                    } elseif ($n === 3) {
                        $s['italic'] = true;
                    } elseif ($n === 4) {
                        $s['underline'] = true;
                    } elseif ($n === 7) {
                        $s['inverse'] = true;
                    } elseif ($n === 22) {
                        $s['bold'] = false;
                        $s['dim'] = false;
                    } elseif ($n === 23) {
                        $s['italic'] = false;
                    } elseif ($n === 24) {
                        $s['underline'] = false;
                    } elseif ($n === 27) {
                        $s['inverse'] = false;
                    } elseif ($n >= 30 && $n <= 37) {
                        $s['fg'] = ansiToHex($n - 30);
                    } elseif ($n === 39) {
                        $s['fg'] = null;
                    } elseif ($n >= 40 && $n <= 47) {
                        $s['bg'] = ansiToHex($n - 40);
                    } elseif ($n === 49) {
                        $s['bg'] = null;
                    } elseif ($n >= 90 && $n <= 97) {
                        $s['fg'] = ansiToHex($n - 90, bright: true);
                    } elseif ($n >= 100 && $n <= 107) {
                        $s['bg'] = ansiToHex($n - 100, bright: true);
                    }
                }

                $style[$row] = $s;
                break;

            case 'H': // cursor position — 1-based
            case 'f':
                $row = max(0, ($nums[0] ?? 1) - 1);
                $col = max(0, ($nums[1] ?? 1) - 1);
                break;

            case 'A': // cursor up
                $row = max(0, $row - max(1, $nums[0] ?? 1));
                break;

            case 'B': // cursor down
                $row += max(1, $nums[0] ?? 1);
                break;

            case 'C': // cursor forward
                $col += max(1, $nums[0] ?? 1);
                break;

            case 'D': // cursor back
                $col = max(0, $col - max(1, $nums[0] ?? 1));
                break;

            case 'G': // cursor to column
                $col = max(0, ($nums[0] ?? 1) - 1);
                break;

            case 'J': // erase in display
                $mode = $nums[0] ?? 0;

                if ($mode === 2) {
                    $screen = [];
                } elseif ($mode === 0) {
                    // Erase from cursor to end of screen.
                    for ($r = $row; $r <= max(array_keys($screen) ?: [$row]); $r++) {
                        if ($r === $row) {
                            $screen[$row] = array_slice($screen[$row] ?? [], 0, $col, true);
                        } else {
                            unset($screen[$r]);
                        }
                    }
                }
                break;

            case 'K': // erase in line
                $mode = $nums[0] ?? 0;

                if ($mode === 0) {
                    $screen[$row] = array_slice($screen[$row] ?? [], 0, $col, true);
                } elseif ($mode === 1) {
                    for ($c = 0; $c <= $col; $c++) {
                        unset($screen[$row][$c]);
                    }
                } else {
                    unset($screen[$row]);
                }
                break;
        }

        // private-mode and other sequences (?25l etc) are consumed and ignored
        $i = $j + 1;

        continue;
    }

    if ($byte === "\x1b") {
        $i += 2;

        continue;
    }

    if ($byte === "\r") {
        $col = 0;
        $i++;

        continue;
    }

    if ($byte === "\n") {
        $row++;
        $style[$row] = $style[$row] ?? $style[max(0, $row - 1)] ?? $currentStyle();
        $i++;

        continue;
    }

    if ($byte === "\x08") {
        $col = max(0, $col - 1);
        $i++;

        continue;
    }

    if (ord($byte) < 0x20) {
        $i++;

        continue;
    }

    // Regular character. Read one UTF-8 sequence, not one byte, or every glyph after the first
    // becomes mojibake — which is precisely how the ASCII wordmark art and the box-drawing
    // characters get shredded.
    //
    // ansiUtf8() advances $i BY REFERENCE and the caller must NOT advance it again. It did, and
    // the double increment stepped the cursor INTO the middle of every escape sequence, so
    // "a ESC[0;34m b" rendered as "a[;4b[m" — the sequence's own bytes leaking into the output
    // as text. This is the exact class of bug the original double-exposed screenshot is.
    $char = ansiUtf8($stream, $i);
    $put($char);
    $col++;
}

// --- Render the buffer ---------------------------------------------------------

$html = '';

// The pty echoes piped input before the app paints, so row 0 is often "^Dit" — the literal
// characters of the `exit` we typed, captured before the TUI took the terminal. It is capture
// scaffolding, not the product, so it is dropped rather than shipped as the first thing a
// visitor reads. Anything before the wordmark's own top row is noise by construction.
$startRow = 0;
foreach ($screen as $r => $line) {
    $text = '';
    foreach ($line as $cell) {
        $text .= $cell[0];
    }

    if (str_contains($text, 'mm') && str_contains($text, '#')) {
        $startRow = $r;
        break;
    }
}

foreach ($screen as $r => $line) {
    if ($r < $startRow || $r > $startRow + 60) {
        continue;
    }

    // Blank the word typed into the prompt box, keeping the box itself.
    //
    // "exit" is typed only to make laravel/prompts draw its multi-line box, and shipping that
    // word in the README hero reads as "this is what typing exit does" — which is how a reviewer
    // reading our own screenshot correctly concluded it started a billed model request. The first
    // attempt at this blanked the whole box, borders and prompt label included, which was worse;
    // the fix is to clear only the CONTENT row (the one starting with the box's vertical bar and
    // not its top-left corner), leaving ┌ paider> ─┐ and └────┘ intact.
    $rowText = '';
    foreach ($line as $cell) {
        $rowText .= $cell[0];
    }

    if (preg_match('/^\s*│/u', $rowText) === 1) {
        // Blank the LOCAL copy, not $screen[$r]. This loop iterates `foreach ($screen as $r =>
        // $line)`, so $line is a by-value copy taken before this point — writing to $screen[$r]
        // has no effect on the render that follows. The first version of this feature did
        // exactly that and the word survived into the image anyway.
        $line = [];
    }

    $text = '';
    $open = null;

    foreach ($line as $c => [$char, $s]) {
        $css = styleToCss($s);

        if ($css !== $open) {
            $text .= $open === null ? '' : '</span>';
            $text .= $css === '' ? '' : '<span style="'.$css.'">';
            $open = $css;
        }

        $text .= htmlspecialchars($char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    if ($open !== null) {
        $text .= '</span>';
    }

    $html .= rtrim($text)."\n";
}

$full = <<<HTML
<!doctype html><meta charset="utf-8"><style>
html,body{margin:0;padding:0;background:#0d0d12;}
pre{margin:0;padding:30px 34px;font:15px/1.5 "SF Mono",Menlo,monospace;color:#d6d6de;
    display:inline-block;min-width:160ch;white-space:pre;}
</style><pre>{$html}</pre>
HTML;

file_put_contents($out, $full);

fwrite(STDOUT, sprintf("rendered %d rows to %s\n", count($screen), $out));

/** @return array{0: string, 1: array<string, mixed>} */
function styleToCss(array $s): string
{
    $css = [];

    $fg = $s['fg'];
    $bg = $s['bg'];

    if ($s['inverse']) {
        // Swap rather than ignore: the YOLO badge relies on an inverse-video slot, and dropping
        // it would render the one alert in the UI invisible — precisely the failure the badge's
        // own comment says NO_COLOR must not cause.
        $tmp = $fg;
        $fg = $bg ?? '#0d0d12';
        $bg = $tmp ?? '#d6d6de';
    }

    if ($fg !== null) {
        $css[] = 'color:'.$fg;
    }

    if ($bg !== null) {
        $css[] = 'background:'.$bg;
    }

    if ($s['bold']) {
        $css[] = 'font-weight:700';
    }

    if ($s['dim']) {
        $css[] = 'opacity:.62';
    }

    if ($s['italic']) {
        $css[] = 'font-style:italic';
    }

    if ($s['underline']) {
        $css[] = 'text-decoration:underline';
    }

    return implode(';', $css);
}

function ansiToHex(int $n, bool $bright = false): string
{
    $base = [
        0 => ['#1c1c22', '#e0e0e8'], // black
        1 => ['#c0392b', '#ff6b5e'], // red
        2 => ['#27ae60', '#4ade80'], // green
        3 => ['#c9a227', '#f5d442'], // yellow
        4 => ['#3b6fd4', '#7aa5ff'], // blue
        5 => ['#8e44ad', '#c084fc'], // magenta
        6 => ['#16a0a8', '#4fd6d6'], // cyan
        7 => ['#8a8a96', '#d6d6de'], // white
    ];

    $pair = $base[$n] ?? $base[7];

    return $bright ? $pair[1] : $pair[0];
}

/** Read one UTF-8 character starting at $offset, advancing it past the sequence. */
function ansiUtf8(string $s, int &$offset): string
{
    $b = ord($s[$offset]);

    $len = match (true) {
        $b < 0x80 => 1,
        $b >= 0xF0 => 4,
        $b >= 0xE0 => 3,
        default => 2,
    };

    $slice = substr($s, $offset, $len);

    // Truncated or invalid: fall back to a single byte rather than emitting a broken sequence,
    // which would render as a replacement diamond in the middle of the wordmark.
    if (strlen($slice) !== $len || ! mb_check_encoding($slice, 'UTF-8')) {
        return '?';
    }

    $offset += $len;

    return $slice;
}
