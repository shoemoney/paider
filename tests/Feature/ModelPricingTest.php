<?php

use App\Support\ModelPricing;

beforeEach(function () {
    config(['prices.known/model-x' => ['in' => 1.00, 'out' => 5.00]]);
});

it('prices a known model at several token volumes', function () {
    expect(ModelPricing::costFor('known/model-x', 1_000_000, 0))->toBe(1.0)
        ->and(ModelPricing::costFor('known/model-x', 0, 1_000_000))->toBe(5.0)
        ->and(ModelPricing::costFor('known/model-x', 500_000, 200_000))
        ->toBe(500_000 / 1e6 * 1.00 + 200_000 / 1e6 * 5.00);
});

it('returns null, not 0.0, for a known model with zero usage on both sides -- a completion is never free', function () {
    // 0 in AND 0 out means the usage block was never parsed (differently-keyed
    // provider response, or a 200-with-error body), not a genuinely free call.
    $cost = ModelPricing::costFor('known/model-x', 0, 0);

    expect($cost)->toBeNull()
        ->and($cost)->not->toBe(0.0);
});

it('returns null, not 0.0, for an unknown model', function () {
    $cost = ModelPricing::costFor('nobody/does-not-exist', 1_000, 1_000);

    expect($cost)->toBeNull()
        ->and($cost)->not->toBe(0.0);
});

it('returns null for a near-miss model id -- exact match only', function () {
    expect(ModelPricing::costFor('known/model-x ', 1_000, 1_000))->toBeNull()
        ->and(ModelPricing::costFor('Known/Model-X', 1_000, 1_000))->toBeNull()
        ->and(ModelPricing::costFor('known/model-x', 1_000, 1_000))->not->toBeNull();
});

it('prices a dotted model id from the shipped config -- dot-notation config lookup would split on it', function () {
    $price = config('prices')['anthropic/claude-haiku-4.5'];

    expect(ModelPricing::costFor('anthropic/claude-haiku-4.5', 1_000_000, 1_000_000))
        ->toBe(1_000_000 / 1e6 * $price['in'] + 1_000_000 / 1e6 * $price['out'])
        ->toBe(6.0);
});

it('prices cache tokens, not just input and output', function () {
    config()->set('prices', ['known/cached' => [
        'in' => 1.00, 'out' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10,
    ]]);

    expect(ModelPricing::costFor('known/cached', 0, 0, 1_000_000, 0))->toBe(1.25)
        ->and(ModelPricing::costFor('known/cached', 0, 0, 0, 1_000_000))->toBe(0.10)
        ->and(ModelPricing::costFor('known/cached', 1_000_000, 1_000_000, 1_000_000, 1_000_000))
        ->toBe(1.00 + 5.00 + 1.25 + 0.10);
});

it('falls back ASYMMETRICALLY on an unverified cache rate, never as free', function () {
    // null means "no verified rate", which must over-estimate in BOTH columns --
    // and symmetry is the bug. A cache READ is always a discount (0.10x Anthropic
    // and OpenAI, 0.02x DeepSeek), so the full input rate over-estimates it. A
    // cache WRITE carries a premium wherever it is charged (1.25x Anthropic, 1.25x
    // OpenAI), so falling back to 1.00x would UNDER-estimate by 25% -- the one
    // direction that prices a product into a loss.
    config()->set('prices', ['unverified/model' => [
        'in' => 2.00, 'out' => 10.00, 'cache_write' => null, 'cache_read' => null,
    ]]);

    expect(ModelPricing::costFor('unverified/model', 0, 0, 1_000_000, 0))
        ->toBe(2.50, 'an unverified WRITE must assume the 1.25x premium')
        ->and(ModelPricing::costFor('unverified/model', 0, 0, 0, 1_000_000))
        ->toBe(2.00, 'an unverified READ falls back to the undiscounted input rate');
});

it('honours a verified cache rate over the fallback', function () {
    // DeepSeek charges NOTHING extra to write and 0.020x to read. If the verified
    // numbers were ignored in favour of the conservative default, a real workload
    // would be over-billed by 25% on every write -- wrong in the safe direction is
    // still wrong once the real rate is known.
    config()->set('prices', ['deepseek/deepseek-v4-flash' => [
        'in' => 0.14, 'out' => 0.28, 'cache_write' => 0.14, 'cache_read' => 0.0028,
    ]]);

    expect(ModelPricing::costFor('deepseek/deepseek-v4-flash', 0, 0, 1_000_000, 0))
        ->toBe(0.14, 'verified: no write surcharge, so NOT 1.25x')
        ->and(ModelPricing::costFor('deepseek/deepseek-v4-flash', 0, 0, 0, 1_000_000))
        ->toBe(0.0028);
});

it('never lets a cache read cost more than uncached input', function () {
    // A read is a discount at every vendor surveyed. A row claiming otherwise is a
    // transcription error, and it would silently inflate every cached workload.
    // Read the SHIPPED file, not config() -- an earlier test in this file replaces
    // the prices config wholesale, and asserting against whatever the app happens
    // to hold makes this pass or fail on test ORDER rather than on the data.
    foreach (require config_path('prices.php') as $modelId => $price) {
        if ($price['cache_read'] === null) {
            continue;
        }

        expect($price['cache_read'])->toBeLessThanOrEqual($price['in'], $modelId);
    }
});

it('does not discard a warm cached turn as unparsed usage', function () {
    // MEASURED on a real transcript: 403 input tokens across 209 turns against 24.3M
    // cached. Guarding on in===0 && out===0 alone would throw away a six-figure turn
    // as "the usage block never parsed" and under-report the bill to zero.
    config()->set('prices', ['known/cached' => [
        'in' => 1.00, 'out' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10,
    ]]);

    expect(ModelPricing::costFor('known/cached', 0, 0, 0, 170_000))->toBe(0.017)
        ->and(ModelPricing::costFor('known/cached', 0, 0, 0, 0))->toBeNull();
});
