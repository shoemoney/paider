<?php

use App\Tools\PatchFileTool;

/**
 * Replays tests/Fixtures/diff-apply-corpus/ through the real PatchFileTool apply path.
 *
 * This is a pilot measurement harness (PLAN.md v1.1 DoD), not a threshold gate: each case's
 * expected outcome was determined by reading PatchFileTool's real behavior, and the summary
 * line reports the measured apply rate without asserting it against any invented number.
 */
function diffApplyCorpusDir(): string
{
    return __DIR__.'/../Fixtures/diff-apply-corpus';
}

/**
 * @return array<int, string> absolute paths to each case directory, sorted.
 */
function diffApplyCorpusCases(): array
{
    $dirs = glob(diffApplyCorpusDir().'/*', GLOB_ONLYDIR);
    sort($dirs);

    return $dirs;
}

test('the diff-apply pilot corpus exists and has 12-20 cases', function () {
    $cases = diffApplyCorpusCases();

    expect(count($cases))->toBeGreaterThanOrEqual(12)
        ->and(count($cases))->toBeLessThanOrEqual(20);
});

test('every corpus case replays through PatchFileTool and matches its expected outcome', function () {
    $cases = diffApplyCorpusCases();
    expect($cases)->not->toBeEmpty();

    $total = 0;
    $applied = 0;

    foreach ($cases as $caseDir) {
        $name = basename($caseDir);
        $manifest = json_decode(file_get_contents($caseDir.'/case.json'), true);
        expect($manifest)->not->toBeNull("case.json for {$name} must be valid JSON");

        $diff = file_get_contents($caseDir.'/diff.patch');
        $target = $manifest['path'];

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-diff-apply-pilot-'.uniqid();
        mkdir($root, recursive: true);
        $root = realpath($root); // macOS /tmp is a symlink to /private/tmp; PathGuard needs the real path.
        $absoluteTarget = $root.DIRECTORY_SEPARATOR.$target;

        $inputPath = $caseDir.'/'.$target;
        $hadInput = is_file($inputPath);

        if ($hadInput) {
            $inputContent = file_get_contents($inputPath);
            $targetDir = dirname($absoluteTarget);
            is_dir($targetDir) || mkdir($targetDir, recursive: true);
            file_put_contents($absoluteTarget, $inputContent);
        }

        $stamp = match ($manifest['stamp_mode']) {
            'new_file' => PatchFileTool::NEW_FILE_STAMP,
            'stale' => hash('sha256', 'pilot-corpus-stale-stamp-marker'),
            default => hash('sha256', $inputContent ?? ''),
        };

        $total++;

        $tool = new PatchFileTool($root);
        $result = $tool->execute([
            'path' => $target,
            'diff' => $diff,
            'stamp' => $stamp,
        ]);

        if ($manifest['expect_ok']) {
            $applied++;

            expect($result->ok)->toBeTrue("case {$name} expected a successful apply but got failure: ".json_encode($result->meta));

            $expected = file_get_contents($caseDir.'/expected.txt');
            expect(file_get_contents($absoluteTarget))->toBe($expected, "case {$name} applied content mismatch");
        } else {
            expect($result->ok)->toBeFalse("case {$name} expected the apply to fail but it succeeded");

            foreach ($manifest['expect_meta'] ?? [] as $key => $value) {
                expect($result->meta[$key] ?? null)->toBe($value, "case {$name} meta[{$key}] mismatch");
            }

            // A failed apply must never mutate the target: the file stays exactly as it was
            // (or, for a new-file case, never gets created at all).
            if ($hadInput) {
                expect(file_get_contents($absoluteTarget))->toBe($inputContent, "case {$name} left the file mutated after a failed apply");
            } else {
                expect(is_file($absoluteTarget))->toBeFalse("case {$name} created a file despite a failed apply");
            }
        }
    }

    $rate = $total > 0 ? round($applied / $total * 100, 1) : 0.0;
    fwrite(STDERR, "\npilot apply rate: {$applied}/{$total} ({$rate}%)\n");

    expect($total)->toBeGreaterThan(0);
});
