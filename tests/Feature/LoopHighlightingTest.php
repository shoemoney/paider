<?php

use App\Agent\Loop;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;

/** Never actually called — splitFences() doesn't touch the provider. */
class HighlightTestNeverCalledProvider implements ProviderClient
{
    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        throw new RuntimeException('provider must never be called by splitFences');
    }
}

test('splitFences hands back the fenced code as a single, byte-identical segment — the thing renderProse must then hand to append() in one call', function () {
    // Long enough to span several 20-byte str_split boundaries if it were ever re-chunked —
    // proving the splitter itself never fragments a fence's code is what closes off the one
    // place that fragmentation would have to be reintroduced for the ANSI-bisection bug (see
    // renderProse's docblock) to come back.
    $code = str_repeat('x', 5)."\nfunction f() {\n    return 1;\n}\n".str_repeat('y', 50);
    $content = "before\n```php\n{$code}\n```\nafter";

    $loop = new Loop([], new HighlightTestNeverCalledProvider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);
    $segments = (new ReflectionMethod(Loop::class, 'splitFences'))->invoke($loop, $content);

    $codeSegments = array_values(array_filter($segments, fn (array $s) => $s['fence']));

    expect($codeSegments)->toHaveCount(1)
        ->and($codeSegments[0]['code'])->toBe($code)
        ->and($codeSegments[0]['language'])->toBe('php');
});

test('splitFences does not mistake a nested ```lang opener for the outer fence\'s closer', function () {
    // Same length backticks, nested — a ```php example inside a ```markdown explanation. The
    // outer closer must be the LAST bare ``` (no language tag), not the first same-length ```
    // the lazy .*? finds, or the inner language tag leaks into the output as bare prose text.
    $content = "a\n```markdown\nhere:\n```php\n<?php echo 1;\n```\n```\nb";

    $loop = new Loop([], new HighlightTestNeverCalledProvider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);
    $segments = (new ReflectionMethod(Loop::class, 'splitFences'))->invoke($loop, $content);

    expect($segments)->not->toContain(['fence' => false, 'text' => "php\n<?php echo 1;\n```\n```\nb"]);

    $prose = implode('', array_column(array_filter($segments, fn (array $s) => ! $s['fence']), 'text'));
    expect($prose)->not->toContain('php'.PHP_EOL.'<?php');
});

test('splitFences tolerates trailing whitespace after the language tag', function () {
    $content = "a\n```php   \n<?php echo 1;\n```\nb";

    $loop = new Loop([], new HighlightTestNeverCalledProvider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);
    $segments = (new ReflectionMethod(Loop::class, 'splitFences'))->invoke($loop, $content);

    $codeSegments = array_values(array_filter($segments, fn (array $s) => $s['fence']));

    expect($codeSegments)->toHaveCount(1)
        ->and($codeSegments[0]['language'])->toBe('php');
});

test('splitAnsiSafe never bisects an SGR escape sequence across a chunk boundary', function () {
    $loop = new Loop([], new HighlightTestNeverCalledProvider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    // The escape starts at byte 18, straddling the 20-byte chunk boundary str_split would cut at.
    $text = str_repeat('A', 18)."\x1b[31mDANGER\x1b[0m".str_repeat('B', 30);
    $chunks = (new ReflectionMethod(Loop::class, 'splitAnsiSafe'))->invoke($loop, $text);

    foreach ($chunks as $chunk) {
        // Any chunk touching an escape must be the WHOLE sequence — never a partial one whose
        // tail (e.g. bare "31m") would print as literal text on screen for a frame or more.
        if (str_contains($chunk, "\x1b")) {
            expect($chunk)->toMatch('/^\x1b\[[0-9;]*m$/');
        }
    }

    expect(implode('', $chunks))->toBe($text);
});

/**
 * Loop::renderProse() is only reachable through a real terminal for the code paths under test
 * here — highlighting is colour, and colour on the stream path only ever renders through a real
 * tty — so these run the same way LoopTerminalSafeWiringTest does: a genuine pty via macOS
 * `script(1)`, invoking the private method directly by reflection to bypass turn()'s provider
 * call and spinner (irrelevant to what's under test, and the spinner would add its own escape
 * bytes to the very stream these tests assert exact bytes on).
 */
function highlightProbeScript(string $content, string $highlighterClassSource, string $highlighterExpr): string
{
    return '<?php'.PHP_EOL
        ."require getcwd().'/vendor/autoload.php';".PHP_EOL
        ."\$app = require getcwd().'/bootstrap/app.php';".PHP_EOL
        .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'.PHP_EOL
        .'final class NeverCalledProvider implements App\Providers\Contracts\ProviderClient {'.PHP_EOL
        .'    public function send(array $messages, string $model, array $options = []): App\Providers\ProviderResponse {'.PHP_EOL
        ."        throw new RuntimeException('provider must never be called by renderProse');".PHP_EOL
        .'    }'.PHP_EOL
        .'}'.PHP_EOL
        .$highlighterClassSource.PHP_EOL
        .'$loop = new App\Agent\Loop([], new NeverCalledProvider, new App\Agent\TierRouter, '
        .'new App\Storage\EventLog(App\Storage\Database::connect(":memory:")), new App\Approval\Gate, '
        .$highlighterExpr.');'.PHP_EOL
        .'$content = '.var_export($content, true).';'.PHP_EOL
        .'(new ReflectionMethod(App\Agent\Loop::class, "renderProse"))->invoke($loop, $content);'.PHP_EOL;
}

function renderProseInPty(string $content, string $paiderColor = '1', string $highlighterClassSource = '', string $highlighterExpr = 'new App\Support\TempestHighlighter'): string
{
    $scriptFile = tempnam(sys_get_temp_dir(), 'paider-highlight-').'.php';
    file_put_contents($scriptFile, highlightProbeScript($content, $highlighterClassSource, $highlighterExpr));

    // Retried on an EMPTY capture only. Allocating a pty via script(1) competes for system
    // resources, and under load the child is starved and writes nothing — which then reads as
    // "the renderer produced no output", a failure with nothing to do with the code under test.
    // An empty capture is never a legitimate result here (every probe prints something), so it
    // is a safe retry condition that cannot paper over a real regression: a probe that renders
    // the WRONG thing still returns non-empty and is still asserted on.
    $output = '';

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $captureFile = tempnam(sys_get_temp_dir(), 'paider-tty-');

        shell_exec(
            'cd '.escapeshellarg(base_path()).' && '
            .'env -u NO_COLOR PAIDER_COLOR='.escapeshellarg($paiderColor).' script -q '.escapeshellarg($captureFile).' '
            .'php '.escapeshellarg($scriptFile).' >/dev/null 2>&1'
        );

        $output = (string) file_get_contents($captureFile);
        unlink($captureFile);

        if (trim($output) !== '') {
            break;
        }

        usleep(50_000 * ($attempt + 1));
    }

    unlink($scriptFile);

    return $output;
}

test('a reply shaped like the tool-fence collision — a real tool call plus a second fence — renders without exception and without added colour', function () {
    if (shell_exec('command -v script') === null) {
        $this->markTestSkipped('script(1) not available to allocate a pty');
    }

    // Four ``` total: parseToolCall's exactly-two-backtick check fails this in the real flow
    // (turn() would fall through to renderProse with it), so this is the shape renderProse must
    // survive on its own downstream of that check.
    $content = "Sure, let me do that:\n```tool\n{\"name\": \"fake_tool\", \"input\": {}}\n```\n"
        ."Also, some context:\n```note\nplain text, not a real language\n```\n";

    // Colour off, so ProseStream's own fade-in wrapping is bypassed too (see
    // ProseStream::fadeOut()) and the no-colour assertion below isn't testing the stream's
    // generic fade effect instead of the thing this test is actually named for: that the
    // 4-backtick shape renders without throwing once it falls through parseToolCall.
    $output = renderProseInPty($content, '0');

    expect($output)->toContain('Sure')
        ->and($output)->toContain('fake_tool')
        ->and($output)->toContain('plain text')
        ->and($output)->not->toMatch('/\e\[[0-9;]*m/');
});

test('a highlighter that throws internally does not break renderProse', function () {
    if (shell_exec('command -v script') === null) {
        $this->markTestSkipped('script(1) not available to allocate a pty');
    }

    $classSource = 'final class ThrowingHighlighter implements App\Support\Contracts\Highlighter {'
        .'    public function highlight(string $code, string $language): string {'
        ."        throw new RuntimeException('boom');"
        .'    }'
        .'}';

    $content = "Here's the fix:\n```php\n<?php echo 1;\n```\nDone.";

    $output = renderProseInPty($content, '0', $classSource, 'new ThrowingHighlighter');

    expect($output)->toContain('Here')
        ->and($output)->toContain('Done')
        // The throwing highlighter never got to run, so the raw, unhighlighted code survives —
        // proof this is Loop's own defence, not just TempestHighlighter's internal try/catch.
        ->and($output)->toContain('echo 1;')
        // If Loop's try/catch were missing, the exception would escape uncaught and Laravel's
        // own renderer would dump ITS class name and message into this same pty capture instead
        // — these two strings only ever appear together in that crash dump, never in the
        // correct "caught, fell back to raw code" path.
        ->and($output)->not->toContain('RuntimeException')
        ->and($output)->not->toContain('boom');
});

test('a fenced code block keeps its ``` delimiters and language tag in tty output — matching the non-tty echo path even with colour and highlighting both off', function () {
    if (shell_exec('command -v script') === null) {
        $this->markTestSkipped('script(1) not available to allocate a pty');
    }

    $content = "Here's the fix:\n```php\n<?php echo 1;\n```\nDone.";
    $output = renderProseInPty($content, '0');

    // Stream redraws the whole accumulated content on every append (cursor-up + erase, then
    // rewrite), so the capture legitimately contains several frames and several repeats of the
    // same text — toContain, not an exact count, is what's meaningful here. Before this fix,
    // both delimiters were dropped from every frame (0 backticks anywhere in the capture).
    expect($output)->toContain('```php')
        ->and($output)->toContain("```\r\n Done.");
});
