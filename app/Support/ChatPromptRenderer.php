<?php

namespace App\Support;

use Laravel\Prompts\Themes\Default\TextareaPromptRenderer;

/**
 * Corrects the box's footer label. TextareaPromptRenderer hardcodes "Ctrl+D to submit" inline
 * in two of its match arms, and ChatPrompt sends on Enter — leaving it would print one label
 * on the border and a contradicting one underneath.
 *
 * Overriding box() rather than __invoke() is deliberate: __invoke is a ~40-line match over the
 * prompt states and copying it would fork all of that to change one string, then silently rot
 * the moment upstream touches any other arm.
 */
final class ChatPromptRenderer extends TextareaPromptRenderer
{
    private const UPSTREAM_LABEL = 'Ctrl+D to submit';

    public const LABEL = 'Enter to send';

    protected function box(
        string $title,
        string $body,
        string $footer = '',
        string $color = 'gray',
        string $info = '',
    ): self {
        return parent::box(
            $title,
            $body,
            $footer,
            $color,
            $info === self::UPSTREAM_LABEL ? self::LABEL : $info,
        );
    }
}
