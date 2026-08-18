<?php

/**
 * m1/bench/foreign-repo.sh is tracked (see .gitignore's `!m1/bench/foreign-repo.sh`
 * exemption), so it's always present. This test pins the one property that matters most:
 * provider keys being set must never be enough, on their own, to reach the live/spend
 * branch. Only the explicit --live flag may do that (see foreign-repo.sh's own
 * argument-parsing loop — the key check lives nested inside the `[ "$LIVE" = 1 ]` guard,
 * never the other way around).
 */
test('foreign-repo bench: provider keys alone never reach the live/spend branch without --live', function () {
    $script = base_path('m1/bench/foreign-repo.sh');

    expect($script)->toBeFile();

    // Never a hand-waved "would run" standing in for a real exec of the binary.
    expect(file_get_contents($script))->not->toContain('would run');

    // Fake, non-functional keys — if the gate is broken and this reaches the live branch,
    // it would attempt a real `paider run`/network call rather than fail on a bad key, which
    // is exactly the accidental-spend failure mode this test exists to catch.
    $cmd = 'cd '.escapeshellarg(base_path()).' && '
        .'env AIGATE_URL=https://fake.example AIGATE_TOKEN=fake-token OPENROUTER_API_KEY=fake-key-not-real '
        .'timeout 120 bash '.escapeshellarg($script).' 2>&1; echo "EXIT=$?"';

    $output = shell_exec($cmd);

    expect($output)->not->toBeNull();
    expect($output)->toContain('EXIT=0');

    // Never reached the live path: no clone of the real foreign target, no live exec banner,
    // and — the specific regression this bench used to have — no hand-waved "would run".
    expect($output)->not->toContain('LIVE run against');
    expect($output)->not->toContain('foreign target:');
    expect($output)->not->toContain('would run');

    // But it did genuinely run the hermetic path, not just exit early.
    expect($output)->toContain('preflight: all checks passed');
    expect($output)->toContain('tmp copy invariants OK');
});
