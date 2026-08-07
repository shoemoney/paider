<?php

namespace App\Support\Contracts;

/**
 * Mirrors ProviderClient's shape: one method, so the vendor behind it stays swappable. No
 * Tempest type may appear on this interface — see TempestHighlighter's docblock.
 */
interface Highlighter
{
    public function highlight(string $code, string $language): string;
}
