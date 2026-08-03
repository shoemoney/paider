<?php

use App\Providers\QwenPlanKeyGuard;

it('throws when an sk-sp- key is used against a non-coding.dashscope URL', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc123', 'https://api.openai.com/v1');
})->throws(RuntimeException::class);

it('does not throw when the same key is used against a coding.dashscope URL', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc123', 'https://coding.dashscope.aliyuncs.com/v1');

    expect(true)->toBeTrue();
});

it('never throws for a non-sk-sp- key regardless of URL', function () {
    QwenPlanKeyGuard::assertSafe('sk-normal-key', 'https://api.openai.com/v1');

    expect(true)->toBeTrue();
});

it('allows the token-plan host the sk-sp- key actually serves from', function () {
    // Regression: the guard previously matched only 'coding.dashscope', so it refused
    // https://token-plan.ap-southeast-1.maas.aliyuncs.com — the real plan endpoint —
    // and the plan key could not be used at all.
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com');
})->throwsNoExceptions();

it('still refuses the pay-as-you-go dashscope endpoint', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://dashscope.aliyuncs.com/compatible-mode/v1');
})->throws(RuntimeException::class);

it('matches on host, so a plan host in the query string does not smuggle a key out', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://evil.example.com/v1?x=maas.aliyuncs.com');
})->throws(RuntimeException::class);

it('is not fooled by a lookalike suffix host', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://notmaas.aliyuncs.com.evil.test/v1');
})->throws(RuntimeException::class);
