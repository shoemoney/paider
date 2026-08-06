<?php

namespace App\Support;

/**
 * The startup wordmark. The art is the `toilet` default font baked in rather than shelled
 * out to — toilet is a Homebrew package almost no user of a PHP CLI will have installed,
 * and a banner is never worth a hard dependency (or a proc_open) at boot.
 */
final class Banner
{
    /**
     * Rows are stored rstrip'ed; the gradient walks each row's real width, so a trailing-space
     * column would just paint air.
     */
    private const ART = [
        '         mm     "        #',
        ' mmmm     ##   mmm     mmm#   mmm    m mm',
        ' #" "#   #  #    #    #" "#  #"  #   #"  "',
        ' #   #   #mm#    #    #   #  #""""   #',
        ' ##m#"  #    # mm#mm  "#m##  "#mm"   #',
        ' #',
        ' "',
    ];

    private const SUBTITLE = 'The PHP Aider';

    /**
     * The subtitle is set into the wordmark rather than printed beneath it: row 5 holds only
     * the p's descender, leaving the whole line clear from column 8 rightward. Column 8 is
     * where the A's left stroke lands, so the text reads as tucked inside the mark.
     */
    private const SUBTITLE_ROW = 5;

    private const SUBTITLE_COL = 8;

    public static function render(): string
    {
        // Palette::gradient() is [] whenever colour is off for any reason (NO_COLOR, PAIDER_COLOR=0,
        // TERM=dumb, non-TTY) — that single check replaces this file's own stream_isatty() call.
        $shades = Palette::gradient();
        $decorated = $shades !== [];
        $rows = count(self::ART);
        $cols = max(array_map('strlen', self::ART));
        $out = '';

        foreach (self::ART as $row => $line) {
            $width = strlen($line);

            for ($col = 0; $col < $width; $col++) {
                $char = $line[$col];

                if ($char === ' ' || ! $decorated) {
                    $out .= $char;

                    continue;
                }

                // Diagonal position in [0,1], quantised onto the palette. The max(1, ...)
                // guards the single-row/column case from a division by zero.
                $t = ($row / max(1, $rows - 1) + $col / max(1, $cols - 1)) / 2;
                $shade = $shades[min(count($shades) - 1, (int) ($t * count($shades)))];

                $out .= "\e[{$shade}m{$char}\e[0m";
            }

            if ($row === self::SUBTITLE_ROW) {
                $out .= str_repeat(' ', max(1, self::SUBTITLE_COL - $width));

                if ($decorated) {
                    // Second-brightest shade, not the brightest — keeps the subtitle a notch
                    // quieter than the wordmark's hottest corner.
                    $subtitleShade = $shades[max(0, count($shades) - 2)];
                    $out .= "\e[{$subtitleShade}m".self::SUBTITLE."\e[0m";
                } else {
                    $out .= self::SUBTITLE;
                }
            }

            $out .= PHP_EOL;
        }

        return $out;
    }
}
