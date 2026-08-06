<?php

namespace App\Support;

use Laravel\Prompts\Stream;

/**
 * Laravel Prompts' Stream word-wraps prose at `terminal cols - 20`, which throws away a fifth
 * of the width on every line. StreamRenderer indents each line by exactly one space, so a
 * margin of 4 leaves that space on the left and three columns of slack on the right — enough
 * that nothing collides with the edge, without the wide dead gutter.
 */
final class ProseStream extends Stream
{
    private const MARGIN = 4;

    public function __construct()
    {
        // See ChatPrompt: registration goes before the parent constructor renders anything,
        // so the class cannot be built into an unregistered-renderer fatal.
        PromptTheme::activate();

        parent::__construct();

        // Set after parent::__construct(), which is where the cols - 20 default is assigned.
        $this->maxWidth = max(20, self::terminal()->cols() - self::MARGIN);
    }

    /**
     * Stock Stream::fadeOut() ignores Palette entirely: it gates only on
     * Terminal::supportsTrueColor() (COLORTERM), so under NO_COLOR/PAIDER_COLOR=0/non-tty it
     * still calls Terminal::queryColors(), which unconditionally fwrite()s an OSC 10/11 probe
     * to STDOUT with no tty or NO_COLOR check (vendor/laravel/prompts/src/Terminal.php:184),
     * and still emits raw truecolor \e[38;2;R;G;Bm (Stream.php:118) — falsifying Palette's own
     * "single source of colour" contract on the app's primary output surface. Skipping the
     * parent's colour-probing branch entirely when colour is off removes both.
     */
    protected function fadeOut(int $steps = 10): array
    {
        return Palette::enabled() ? parent::fadeOut($steps) : [fn (string $text) => $text];
    }
}
