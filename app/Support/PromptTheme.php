<?php

namespace App\Support;

use Laravel\Prompts\Prompt;
use Laravel\Prompts\Themes\Default\StreamRenderer;

/**
 * Themes::getRenderer() resolves a renderer on an exact `get_class($this)` match, so every
 * Prompt subclass Paider defines has to be named here or it resolves to null and fatals when
 * it first renders. Registration lives in one place because addTheme() REPLACES the whole map
 * for a theme name — two classes each registering their own would silently unregister the
 * other, and the loser only fails at the moment a user actually reaches that prompt.
 *
 * Prompts this theme doesn't name keep their stock rendering: the lookup falls back to the
 * default theme for any class the active theme leaves out.
 */
final class PromptTheme
{
    public const NAME = 'paider';

    public static function activate(): void
    {
        // Registers 'paider-<role>' Termwind styles for the active PAIDER_THEME, if any — a
        // no-op when the user hasn't opted into one. Palette::tw() also calls this itself now
        // (belt-and-suspenders after a real StyleNotFound crash reached production: ShowCommand
        // never constructs a Prompt at all, so this call site alone didn't cover it), so this is
        // no longer the only thing standing between a themed run and that fatal — but it still
        // has to run before the Prompt subclasses below render themselves.
        Palette::boot();

        Prompt::addTheme(self::NAME, [
            ProseStream::class => StreamRenderer::class,
            ChatPrompt::class => ChatPromptRenderer::class,
            PhpSpinner::class => PhpSpinnerRenderer::class,
        ]);

        Prompt::theme(self::NAME);
    }
}
