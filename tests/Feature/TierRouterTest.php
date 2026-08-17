<?php

use App\Agent\TierRouter;

it('resolves edit to the coder tier under the default balanced preset', function () {
    $result = (new TierRouter)->resolve('edit');

    expect($result)->toBe([
        'provider' => 'balanced',
        'tier' => 'coder',
        'model' => 'meta/muse-spark-1.2-contributor',
    ]);
});

it('resolves plan to the orchestrator tier under the default balanced preset', function () {
    $result = (new TierRouter)->resolve('plan');

    expect($result)->toBe([
        'provider' => 'balanced',
        'tier' => 'orchestrator',
        'model' => 'anthropic/claude-opus-5',
    ]);
});

it('throws on an unknown operation', function () {
    (new TierRouter)->resolve('does-not-exist');
})->throws(InvalidArgumentException::class);

it('lets a session override win without mutating config', function () {
    $result = (new TierRouter)->resolve('edit', ['coder' => 'override/model-x']);

    expect($result['model'])->toBe('override/model-x')
        ->and(config('presets.balanced.coder'))->toBe('meta/muse-spark-1.2-contributor');
});
