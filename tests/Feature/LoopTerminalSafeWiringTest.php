<?php

/**
 * Loop::renderProse() is the one place a model's untrusted reply reaches the user's real
 * terminal, and it forks into two code paths (stream_isatty(STDOUT) ? stream : echo). A guard
 * placed inside only one of those branches is the obvious way this bug ships, so this proves
 * TerminalSafe::clean() runs on BOTH — a genuinely piped child process for the echo path, and a
 * genuinely pty-backed one (via `script`, real on this machine) for the stream path.
 *
 * Assertion is "the OSC 52 signature is gone", not "zero \x1b in the whole capture": on the
 * real-tty path, Laravel Prompts' own Stream unconditionally toggles cursor visibility
 * (\e[?25l / \e[?25h) around its output — legitimate terminal housekeeping that has nothing to
 * do with the sanitiser and would make a blanket "no escape bytes at all" assertion false on
 * that branch regardless of whether the sanitiser is even wired up.
 */
function terminalSafeWiringProbe(): string
{
    return <<<'CODE'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        final class NeverCalledProvider implements App\Providers\Contracts\ProviderClient
        {
            public function send(array $messages, string $model, array $options = []): App\Providers\ProviderResponse
            {
                throw new RuntimeException('provider must never be called by renderProse');
            }
        }

        $loop = new App\Agent\Loop(
            [],
            new NeverCalledProvider,
            new App\Agent\TierRouter,
            new App\Storage\EventLog(App\Storage\Database::connect(':memory:')),
            new App\Approval\Gate,
        );

        $osc52 = "\e]52;c;cGF5bG9hZA==\x07";
        $content = "before {$osc52} after";

        (new ReflectionMethod(App\Agent\Loop::class, 'renderProse'))->invoke($loop, $content);
        CODE;
}

test('an OSC 52 injection in a model reply is stripped on the non-tty echo branch', function () {
    $output = shell_exec(
        'cd '.escapeshellarg(base_path()).' && '
        .'env -u NO_COLOR PAIDER_COLOR=0 php -r '.escapeshellarg(terminalSafeWiringProbe()).' 2>&1'
    );

    expect($output)->not->toBeNull()
        ->and($output)->toContain('before')
        ->and($output)->toContain('after')
        ->and($output)->not->toContain("\e]52"); // the OSC 52 introducer itself is gone
});

test('an OSC 52 injection in a model reply is stripped on the real-tty stream branch', function () {
    if (shell_exec('command -v script') === null) {
        $this->markTestSkipped('script(1) not available to allocate a pty');
    }

    // script(1) allocates a real pty for the child regardless of this test process's own stdout
    // — the only way to genuinely exercise stream_isatty(STDOUT) === true without adding a seam
    // to Loop purely for testability. Retried via ptyCapture(): see tests/Pest.php for why.
    $output = ptyCapture(terminalSafeWiringProbe(), 'before');

    expect($output)->not->toBeFalse()
        ->and($output)->toContain('before')
        ->and($output)->toContain('after')
        ->and($output)->not->toContain("\e]52"); // the OSC 52 introducer itself is gone
});
