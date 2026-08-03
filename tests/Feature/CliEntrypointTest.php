<?php

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
