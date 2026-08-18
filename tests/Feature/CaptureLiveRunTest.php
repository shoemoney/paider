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

    // Normalize dot entries: on a fresh checkout m1/runs does not exist yet, and a
    // bare scandir() diff would count '.' and '..' as "new" once it does.
    $before = is_dir($runsDir) ? array_diff(scandir($runsDir), ['.', '..']) : [];

    $cmd = 'cd '.escapeshellarg(base_path()).' && '
        .'env -u OPENROUTER_API_KEY -u AIGATE_URL -u AIGATE_TOKEN '
        .'timeout 120 bash '.escapeshellarg($script).' --dry 2>&1; echo "EXIT=$?"';

    $output = shell_exec($cmd);

    expect($output)->not->toBeNull();
    expect($output)->toContain('EXIT=0');
    expect($output)->not->toContain('LIVE run against');
    expect($output)->not->toContain('spends real money');

    $after = array_diff(scandir($runsDir), ['.', '..']);
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

    // The whole point of --dry is that artifact COLLECTION runs for real, not just
    // that the files exist -- a diff and cost report unconditionally written by the
    // script (regardless of whether collection logic ever executed) would pass the
    // five-file check above while proving nothing. Assert real content instead.
    $diff = file_get_contents("{$runDir}/target.diff");
    expect($diff)->not->toBeEmpty();
    expect($diff)->toContain('live-e2e-proof');
    expect($diff)->toContain('src/Receipt.php');

    $changedFile = "{$runDir}/changed-files/src/Receipt.php";
    expect($changedFile)->toBeFile();
    expect(file_get_contents($changedFile))->toContain('live-e2e-proof');

    // Cleanup: this is a real run artifact, not fixture -- don't leave test noise behind.
    exec('rm -rf '.escapeshellarg($runDir));
});
