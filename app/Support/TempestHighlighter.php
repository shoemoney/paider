<?php

namespace App\Support;

use App\Support\Contracts\Highlighter;
use Tempest\Highlight\Highlighter as VendorHighlighter;
use Tempest\Highlight\Themes\LightTerminalTheme;
use Tempest\Highlight\Themes\TerminalStyle;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;
use Throwable;

/**
 * The only file allowed to know Tempest\Highlight exists — every other call site depends on
 * the Highlighter contract instead, so swapping vendors later touches this file alone.
 *
 * No language allowlist: measured, every unknown/unsupported language tag (go, rust, diff, a
 * model-invented tag, even an empty string) already comes back byte-identical from the vendor
 * parser with no exception — see TempestHighlighterTest. Gating on a hand-picked list bought
 * nothing that guarantee doesn't already give for free, while silently suppressing highlighting
 * for languages the vendor handles fine (ts, sh, yml, py, html, css) that models emit constantly.
 */
final class TempestHighlighter implements Highlighter
{
    private ?VendorHighlighter $vendor = null;

    public function highlight(string $code, string $language): string
    {
        if (! Palette::enabled()) {
            return $code;
        }

        try {
            return ($this->vendor ??= new VendorHighlighter($this->theme()))->parse($code, strtolower($language));
        } catch (Throwable) {
            // A parser bug in untrusted-language-tag input must not break the chat reply it
            // would have decorated — see TerminalSafe's own docblock for the same discipline.
            return $code;
        }
    }

    /**
     * LightTerminalTheme with one token remapped: its VALUE colour (every string literal) is
     * SGR 30, literal ANSI black — invisible on any dark terminal, the exact
     * charcoal-on-charcoal failure Palette's own docblock exists to prevent, and PAIDER_THEME
     * has no influence over it since this is vendor code, not a Palette colour. String/value
     * tokens move to the terminal's own default foreground instead, which is legible against
     * whatever background the user actually has. An anonymous class, not a second file, so this
     * stays the one file that knows Tempest\Highlight exists (see the class docblock above).
     */
    private function theme(): LightTerminalTheme
    {
        return new class extends LightTerminalTheme
        {
            public function before(TokenType $tokenType): string
            {
                return $tokenType === TokenTypeEnum::VALUE
                    ? TerminalStyle::ESC->value.TerminalStyle::RESET->value
                    : parent::before($tokenType);
            }
        };
    }
}
