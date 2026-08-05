<?php

/*
 * config/prices.php is transcribed by hand from the trailing "// $X / $Y" price comments in
 * config/presets.php. This keeps the two from drifting apart: every model id any non-'accounts'
 * preset references must have a prices.php entry, and that entry must match the comment exactly.
 */

/** Every model id => its trailing "// $in / $out" comment, parsed straight out of presets.php. */
function modelPricesFromPresetsSource(): array
{
    $lines = file(config_path('presets.php'));

    $currentKey = null;
    $found = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*\'([a-zA-Z0-9_-]+)\'\s*=>\s*\[\s*$/', $line, $m)) {
            $currentKey = $m[1];

            continue;
        }

        if ($currentKey !== null && preg_match('/^\s*],?\s*$/', $line)) {
            $currentKey = null;

            continue;
        }

        if ($currentKey === null || $currentKey === 'accounts') {
            continue;
        }

        if (preg_match(
            "/^\s*'[a-zA-Z0-9_-]+'\s*=>\s*'([^']+)'.*?\/\/\s*\\\$([0-9.]+)\s*\/\s*\\\$([0-9.]+)/",
            $line,
            $m
        )) {
            $found[$m[1]] = ['in' => (float) $m[2], 'out' => (float) $m[3]];
        }
    }

    return $found;
}

it('has a prices.php entry for every model id referenced by a non-accounts preset', function () {
    // Sourced from the real runtime config, not the price-comment regex below: a tier
    // line with a missing/malformed "// $X / $Y" comment is invisible to that regex, so
    // deriving coverage from it too would let an unpriced model through both tests green.
    foreach (config('presets') as $preset => $tiers) {
        if ($preset === 'accounts') {
            continue;
        }

        foreach ($tiers as $modelId) {
            if (! is_string($modelId)) {
                continue;
            }

            expect(config('prices'))->toHaveKey($modelId);
        }
    }
});

it('matches each prices.php entry to its trailing comment in presets.php', function () {
    // Model ids contain dots (e.g. "claude-haiku-4.5"), so index config('prices') directly
    // rather than through dot-notation config("prices.{$modelId}"), which would split on them.
    $prices = config('prices');

    foreach (modelPricesFromPresetsSource() as $modelId => $price) {
        expect($prices[$modelId]['in'])->toBe($price['in'])
            ->and($prices[$modelId]['out'])->toBe($price['out']);
    }
});

it('declares all four price columns on every model, null meaning "unverified"', function () {
    // A MISSING cache key and a deliberately-null one must stay distinguishable.
    // Without this, a model added later silently lacks the columns and a consumer
    // reads null-by-accident as "free" instead of "bill at the full input rate".
    foreach (config('prices') as $modelId => $price) {
        expect($price)->toHaveKeys(['in', 'out', 'cache_write', 'cache_read'], $modelId);

        foreach (['cache_write', 'cache_read'] as $col) {
            expect($price[$col] === null || is_float($price[$col]))
                ->toBeTrue("{$modelId}.{$col} must be a float or null");
        }
    }
});

it('keeps anthropic cache rates in step with its input rate', function () {
    // Anthropic prices a cache WRITE at 1.25x base input and a cache READ at 0.10x.
    // Editing `in` without editing these would silently misprice the 93% of a cached
    // workload that is not plain input — which is the whole reason the columns exist.
    foreach (config('prices') as $modelId => $price) {
        if (! str_starts_with($modelId, 'anthropic/')) {
            continue;
        }

        expect($price['cache_write'])->toEqualWithDelta($price['in'] * 1.25, 0.0001, $modelId)
            ->and($price['cache_read'])->toEqualWithDelta($price['in'] * 0.10, 0.0001, $modelId);
    }
});
