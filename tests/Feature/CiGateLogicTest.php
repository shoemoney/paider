<?php

/*
 * The CI security gate's decision logic, asserted in both directions.
 *
 * ## Why this file exists
 *
 * The first version of the audit gate was INVERTED. It read:
 *
 *     exit((int) ! array_filter($a['advisories'], fn($v) => in_array($v['severity'], ['high','critical'])))
 *
 * which exits 1 when the advisory list is EMPTY and 0 when a high/critical one is present. That
 * is a gate that fails every clean build and passes every vulnerable one — strictly worse than
 * having no gate, because it produces a green tick that means the opposite of the thing it
 * claims.
 *
 * I only caught it because I ran the gate against the live audit output instead of trusting the
 * `if` shell semantics. This test makes running it unnecessary: the same predicate is exercised
 * against a clean tree, a low-severity advisory, and a high one, and the shell branch is asserted
 * against the workflow file itself.
 *
 * The real `composer audit` output is not used — this is about the DECISION, not the advisories,
 * so a fixture is the honest input and cannot change when Packagist publishes something.
 */

$predicate = <<<'PHP'
$a = json_decode(stream_get_contents(STDIN), true);
// FLATTEN FIRST. composer audit returns advisories as {package: [advisory, ...]}, so filtering
// the outer map hands the callback a LIST, never an advisory — a callback reading $v['severity']
// therefore always sees nothing and reports a clean tree no matter what is installed. This was a
// second, independent bug in the first version of this gate, found by running it against a
// fixture rather than only against a real (low-advisory-only) audit.
$all = array_merge(...array_values($a['advisories'] ?? [])) ?: [];
$blocking = array_filter(
    $all,
    fn (array $v): bool => in_array($v['severity'] ?? '', ['high', 'critical'], true)
);
// 0 when a blocking advisory EXISTS, so `if <cmd>` takes the failure branch.
exit($blocking ? 0 : 1);
PHP;

it('the blocking predicate returns 0 (fail the build) only for high or critical', function () use ($predicate) {
    $run = function (array $advisories) use ($predicate): int {
        $payload = json_encode(['advisories' => $advisories]);
        $script = sys_get_temp_dir().'/paider-gate-'.bin2hex(random_bytes(6)).'.sh';

        file_put_contents($script, 'echo '.escapeshellarg($payload).' | php -r '.escapeshellarg($predicate).'
');

        try {
            // proc_open, not shell_exec: shell_exec() returns the OUTPUT and throws the exit
            // status away, so the first version of this test asserted against 0 forever — which
            // is the same class of bug as the inverted gate it exists to catch.
            $process = proc_open(['/bin/sh', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process);
        } finally {
            @unlink($script);
        }
    };

    // exit 1 == nothing blocking == the "no high or critical advisories" branch.
    expect($run([]))->toBe(1, 'an empty advisory list must NOT fail the build');

    expect($run(['pkg/a' => [['severity' => 'low']]]))->toBe(1, 'a low advisory must not block');
    expect($run(['pkg/a' => [['severity' => 'moderate']]]))->toBe(1, 'moderate must not block');

    // exit 0 == blocking found == the error branch.
    expect($run(['pkg/a' => [['severity' => 'high']]]))->toBe(0, 'a high advisory MUST block');
    expect($run(['pkg/a' => [['severity' => 'critical']]]))->toBe(0, 'a critical advisory MUST block');

    // Mixed: one low and one high. The low must not mask the high — that is the case a naive
    // "any advisory at all" check and a naive "first advisory only" check both get wrong.
    expect($run([
        'pkg/low' => [['severity' => 'low']],
        'pkg/high' => [['severity' => 'high']],
    ]))->toBe(0, 'a high alongside a low must still block');

    // Malformed / missing key must not be read as "blocked", which would break every build.
    expect($run(['pkg/x' => [['title' => 'no severity key']]]))->toBe(1, 'a missing severity must not block');
});

it('the SHIPPED gate command really blocks a high advisory and really passes a clean tree', function () {
    // This is the assertion that matters, and it runs the gate EXACTLY as CI will — the line
    // lifted out of .github/workflows/tests.yml, not a copy of the logic. Three earlier versions
    // of this test asserted against a heredoc that had quietly diverged from the workflow, which
    // is the failure this whole file exists to prevent.
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    // Lift the php -r one-liner straight out of the YAML.
    preg_match("/php -r '([^']*advisories[^']*)'/", $workflow, $m);
    expect($m)->toHaveCount(2, 'the audit gate php -r line was not found in tests.yml');

    $predicate = $m[1];

    expect($predicate)->not->toContain('exit((int) !', 'the inverted form must never come back');

    $run = function (array $advisories) use ($predicate): int {
        $payload = json_encode(['advisories' => $advisories]);
        $script = sys_get_temp_dir().'/paider-gate-'.bin2hex(random_bytes(6)).'.sh';
        file_put_contents($script, 'echo '.escapeshellarg($payload).' | php -r '.escapeshellarg($predicate)."\n");

        try {
            $process = proc_open(['/bin/sh', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process);
        } finally {
            @unlink($script);
        }
    };

    // exit 0 == blocking found == CI fails. exit 1 == clean == CI passes.
    expect($run([]))->toBe(1, 'a clean tree must PASS the shipped gate')
        ->and($run(['p/a' => [['severity' => 'low']]]))->toBe(1, 'low must not block')
        ->and($run(['p/a' => [['severity' => 'high']]]))->toBe(0, 'high must BLOCK')
        ->and($run(['p/a' => [['severity' => 'critical']]]))->toBe(0, 'critical must BLOCK')
        ->and($run([
            'p/low' => [['severity' => 'low']],
            'p/high' => [['severity' => 'high']],
        ]))->toBe(0, 'a high alongside a low must still block');
});

it('the docs-count gate reads the count rather than asserting a literal', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    // A hardcoded number in a CI gate is a number that is wrong the moment a test is added —
    // which is what made the README wrong three times in one day. The gate must DERIVE it.
    expect($workflow)->toContain("grep -E '^ *Tests:'")
        ->and($workflow)->not->toMatch('/vendor\/bin\/pest\s+--filter=.*\d{3}/');
});
