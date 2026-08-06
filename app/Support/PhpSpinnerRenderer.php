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

    public function __invoke(Spinner $spinner): string
    {
        $frame = $spinner->static
            ? $this->staticFrame
            : $this->spinnerFrame($spinner->count);

        if (! $spinner->static) {
            $spinner->interval = $this->interval;
        }

        // Palette::wrap only reads cached env checks (no terminal probing), safe to call
        // every frame even though each frame renders in a freshly forked child.
        return $this->line(' 🐘 '.Palette::wrap(ColorRole::Brand, $frame)." {$spinner->message}");
    }
}
