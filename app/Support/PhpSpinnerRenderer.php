<?php

namespace App\Support;

use Laravel\Prompts\Spinner;
use Laravel\Prompts\Themes\Default\SpinnerRenderer;

/**
 * A turning ring next to the PHP elephant, tinted PHP purple.
 *
 * The glyph cannot literally rotate — a terminal cell is not a canvas and an emoji can't be
 * transformed — so the elephant holds still and the ring beside it turns. Half-disc frames
 * read as rotation far better than an orbiting dot, which is what the stock braille frames
 * already were.
 */
final class PhpSpinnerRenderer extends SpinnerRenderer
{
    /** Half-discs, so the filled side sweeps once per cycle. */
    protected array $frames = ['◐', '◓', '◑', '◒'];

    protected string $staticFrame = '◍';

    /** Slower than the stock 75ms — the larger glyph reads as frantic at that rate. */
    protected int $interval = 120;

    /**
     * 256-colour 103 (#8787af) is the closest widely-supported approximation of PHP's #777BB4.
     * Deliberately not truecolor: this renders in a forked child on every frame, and terminals
     * without truecolor would print the escape as literal text.
     */
    private const PURPLE = "\e[38;5;103m";

    public function __invoke(Spinner $spinner): string
    {
        $frame = $spinner->static
            ? $this->staticFrame
            : $this->spinnerFrame($spinner->count);

        if (! $spinner->static) {
            $spinner->interval = $this->interval;
        }

        return $this->line(' 🐘 '.self::PURPLE.$frame."\e[0m {$spinner->message}");
    }
}
