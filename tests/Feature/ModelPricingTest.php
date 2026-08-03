<?php

use App\Support\ModelPricing;

beforeEach(function () {
    config(['prices.known/model-x' => ['in' => 1.00, 'out' => 5.00]]);
});

it('prices a known model at several token volumes', function () {
    expect(ModelPricing::costFor('known/model-x', 0, 0))->toBe(0.0)
        ->and(ModelPricing::costFor('known/model-x', 1_000_000, 0))->toBe(1.0)
        ->and(ModelPricing::costFor('known/model-x', 0, 1_000_000))->toBe(5.0)
        ->and(ModelPricing::costFor('known/model-x', 500_000, 200_000))
            ->toBe(500_000 / 1e6 * 1.00 + 200_000 / 1e6 * 5.00);
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
