<?php

use App\Support\ProseStream;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

/**
 * Pins the width so the assertion is real. Reading the actual terminal made this test skip
 * under the runner (no tty, narrow cols), which is a test that can never fail — the margin
 * could regress all the way back to the stock gutter and nothing would say so.
 */
function withTerminalCols(int $cols, Closure $body): void
{
    $property = new ReflectionProperty(Prompt::class, 'terminal');
    $original = $property->isInitialized() ? $property->getValue() : null;

    $property->setValue(null, new class($cols) extends Terminal
    {
        public function __construct(private readonly int $width) {}

        public function cols(): int
        {
            return $this->width;
        }
    });

    try {
        $body();
    } finally {
        $property->setValue(null, $original ?? new Terminal);
    }
}

test('prose wraps at the terminal width less a small margin, not the stock cols-20 gutter', function () {
    withTerminalCols(100, function () {
        $maxWidth = (new ReflectionProperty(ProseStream::class, 'maxWidth'))->getValue(new ProseStream);

        expect($maxWidth)->toBe(96);
        // Stream's inherited default would be 80 here — if this ever reads 80 the override was lost.
        expect($maxWidth)->not->toBe(80);
    });
});

test('a terminal too narrow for the margin still leaves a usable measure', function () {
    withTerminalCols(10, function () {
        expect((new ReflectionProperty(ProseStream::class, 'maxWidth'))->getValue(new ProseStream))->toBe(20);
    });
});

test('the subclass is registered with a renderer, so rendering it does not fatal', function () {
    // Themes::getRenderer() looks the renderer up by exact class name and instantiates the
    // result — an unregistered Stream subclass resolves to null and fatals on `new (null)`.
    // Appending is what triggers that lookup, so this is the check that the theme took.
    $stream = new ProseStream;
    $stream->append('hello');

    expect($stream->value())->toBe('hello');
});
