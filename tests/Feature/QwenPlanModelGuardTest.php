<?php

use App\Providers\QwenPlanKeyGuard;

it('refuses an sk-sp- key paired with the known plan model allowlist mismatch', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc123', 'https://coding.dashscope.aliyuncs.com/v1', 'qwen/qwen3.7-flash');
})->throws(RuntimeException::class);

it('names the plan model allowlist mismatch in the refusal message', function () {
    try {
        QwenPlanKeyGuard::assertSafe('sk-sp-abc123', 'https://coding.dashscope.aliyuncs.com/v1', 'qwen/qwen3.7-flash');
        expect(false)->toBeTrue('expected assertSafe to throw');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('qwen/qwen3.7-flash')
            ->toContain('allowlist');
    }
});

it('still refuses the sk-sp- plan model mismatch even on the plan-billing host', function () {
    // Host allowlist alone would let this through -- the model check must fire
    // independently of, not instead of, the host check.
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com', 'qwen/qwen3.7-flash');
})->throws(RuntimeException::class);

it('does not refuse the sk-sp- plan model mismatch model when no model is given', function () {
    // Callers that do not pass a model (none currently, but the guard must stay
    // backward compatible) get the old host-only behaviour.
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://coding.dashscope.aliyuncs.com/v1');

    expect(true)->toBeTrue();
});

it('never refuses a non-sk-sp- key for the plan model mismatch, even against OpenRouter', function () {
    // The OpenRouter path legitimately serves qwen/qwen3.7-flash -- must not break it.
    QwenPlanKeyGuard::assertSafe('sk-or-v1-realkey', 'https://openrouter.ai/api/v1', 'qwen/qwen3.7-flash');

    expect(true)->toBeTrue();
});

it('does not refuse the sk-sp- plan model mismatch for an unrelated model on the plan host', function () {
    QwenPlanKeyGuard::assertSafe('sk-sp-abc', 'https://coding.dashscope.aliyuncs.com/v1', 'qwen/qwen3.8-max');

    expect(true)->toBeTrue();
});
