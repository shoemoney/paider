<?php

namespace App\Support;

use Closure;
use Laravel\Prompts\Spinner;

/**
 * The thinking indicator: the PHP elephant with a ring turning beside it, in place of Prompts'
 * default braille dots.
 *
 * This class carries no behaviour — Spinner already does the forking and frame timing. It
 * exists so the theme has a distinct class name to hang PhpSpinnerRenderer on, which is the
 * only way to replace the frames (they live in a trait the renderer pulls in, not on Spinner).
 */
final class PhpSpinner extends Spinner
{
    /** @return mixed whatever $callback returns */
    public static function while(string $message, Closure $callback): mixed
    {
        // Before spin(), which forks — the child renders immediately and would hit an
        // unregistered-renderer fatal in a process whose crash is easy to miss.
        PromptTheme::activate();

        return (new self($message))->spin($callback);
    }
}
