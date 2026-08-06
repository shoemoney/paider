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

    /**
     * A blue -> steel -> white sweep down the diagonal, after toilet's `--metal`. Reproducing
     * the sweep rather than pasting toilet's escape bytes keeps one plain copy of the art,
     * which is what the undecorated branch below prints.
     *
     * toilet's own palette runs through bright-black (1;30), which on a dark terminal renders
     * as barely-visible charcoal and made the lower half of the mark look washed out. This
     * brightens toward the bottom-right instead, so every row stays legible.
     */
    private const METAL = ['0;34', '0;94', '0;37', '1;37'];

    public static function render(): string
    {
        $decorated = stream_isatty(STDOUT);
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
                $shade = self::METAL[min(count(self::METAL) - 1, (int) ($t * count(self::METAL)))];

                $out .= "\e[{$shade}m{$char}\e[0m";
            }

            if ($row === self::SUBTITLE_ROW) {
                $out .= str_repeat(' ', max(1, self::SUBTITLE_COL - $width));
                $out .= $decorated ? "\e[0;37m".self::SUBTITLE."\e[0m" : self::SUBTITLE;
            }

            $out .= PHP_EOL;
        }

        return $out;
    }
}
