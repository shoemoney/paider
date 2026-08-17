<?php

use Symfony\Component\Process\Process;

it('hints at `paider list` when an unknown command is given', function () {
    $base = dirname(__DIR__, 2);

    $process = new Process([$base.'/paider', 'definitely-not-a-command'], $base);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('paider list');
});

it('does not append the unknown-command hint for a real command that fails on its own', function () {
    $base = dirname(__DIR__, 2);

    $process = new Process([$base.'/paider', 'config:provider', 'not-a-real-preset'], $base);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('paider list');
});

it('has renamed the binary from application to paider', function () {
    $base = dirname(__DIR__, 2);

    expect($base.'/paider')->toBeFile()
        ->and(is_executable($base.'/paider'))->toBeTrue()
        ->and(file_exists($base.'/application'))->toBeFalse();
});

it('declares paider as the sole composer bin entry', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['bin'])->toBe(['paider']);
});
