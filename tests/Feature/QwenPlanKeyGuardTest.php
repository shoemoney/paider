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
