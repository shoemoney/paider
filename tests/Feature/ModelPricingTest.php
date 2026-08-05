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

it('bills an unverified cache rate at the FULL input rate, never as free', function () {
    // null means "no verified rate", which must over-estimate. Treating it as 0.0
    // would report a large cached workload as costing nothing at all -- the exact
    // shape of wrongness that priced the household at $0 instead of $869/mo.
    config()->set('prices', ['unverified/model' => [
        'in' => 2.00, 'out' => 10.00, 'cache_write' => null, 'cache_read' => null,
    ]]);

    expect(ModelPricing::costFor('unverified/model', 0, 0, 1_000_000, 0))->toBe(2.00)
        ->and(ModelPricing::costFor('unverified/model', 0, 0, 0, 1_000_000))->toBe(2.00);
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
