<?php

/**
 * capture-live-run.sh wraps foreign-repo.sh and captures the four RUBRIC.md artifacts
 * per M1 live run. Its --dry mode must run the entire capture path (run dir creation,
 * teeing, artifact collection) against foreign-repo.sh's --dry-live, keylessly, with
 * no spend and no live-run banner — mirrors ForeignRepoBenchLiveGateTest's gate check
 * for the script it wraps.
 */
test('capture-live-run --dry runs the full capture path keylessly and never reaches live', function () {
    $script = base_path('m1/bench/capture-live-run.sh');
    $runsDir = base_path('m1/runs');

    expect($script)->toBeFile();

    $before = is_dir($runsDir) ? scandir($runsDir) : [];

    $cmd = 'cd '.escapeshellarg(base_path()).' && '
        .'env -u OPENROUTER_API_KEY -u AIGATE_URL -u AIGATE_TOKEN '
        .'timeout 120 bash '.escapeshellarg($script).' --dry 2>&1; echo "EXIT=$?"';

    $output = shell_exec($cmd);

    expect($output)->not->toBeNull();
    expect($output)->toContain('EXIT=0');
    expect($output)->not->toContain('LIVE run against');
    expect($output)->not->toContain('spends real money');

    $after = scandir($runsDir);
    $newRunDirs = array_values(array_diff($after, $before));

    expect($newRunDirs)->toHaveCount(1);

    $runDir = $runsDir.'/'.$newRunDirs[0];

    // Every artifact RUBRIC.md's criteria are graded from must exist, even in --dry
    // mode where there's no real target to inspect — that's the "entire capture path"
    // contract: the plumbing runs, only the content says "nothing to capture".
    foreach (['transcript.log', 'exit-code.txt', 'target.diff', 'cost-session.txt', 'changed-files'] as $artifact) {
        expect(file_exists("{$runDir}/{$artifact}"))->toBeTrue("missing artifact: {$artifact}");
    }

    expect(trim(file_get_contents("{$runDir}/exit-code.txt")))->toBe('0');
    expect(is_dir("{$runDir}/changed-files"))->toBeTrue();

    // Cleanup: this is a real run artifact, not fixture -- don't leave test noise behind.
    exec('rm -rf '.escapeshellarg($runDir));
});
