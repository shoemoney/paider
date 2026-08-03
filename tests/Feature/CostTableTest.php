<?php

/*
 * The `paider cost` mockup in README.md is the product's flagship claim, and the paragraph
 * under it says "a cost claim you cannot check is marketing." It shipped 5.26x wrong.
 *
 * This recomputes every row from the balanced preset's own prices. If someone edits the
 * README table, the prices, or the token volumes without redoing the arithmetic, this fails.
 */

/** Prices per Mtok for the `balanced` preset, read from config/prices.php at test time. */
function PRICES(): array
{
    $models = config('presets.balanced');
    $prices = config('prices');

    $out = [];
    foreach (['orchestrator', 'coder', 'research', 'fast'] as $tier) {
        $out[$tier] = [$prices[$models[$tier]]['in'], $prices[$models[$tier]]['out']];
    }

    return $out;
}

/** Parse the fenced `$ paider cost` block out of README.md. */
function costBlock(): string
{
    $readme = file_get_contents(__DIR__.'/../../README.md');

    expect($readme)->toContain('$ paider cost');

    preg_match('/\$ paider cost\n(.*?)\n```/s', $readme, $m);

    return $m[1] ?? '';
}

/** "61.2k" => 61200, "1.4M" => 1400000 */
function tokens(string $n): float
{
    $mult = ['k' => 1e3, 'M' => 1e6][substr($n, -1)] ?? 1;

    return (float) rtrim($n, 'kM') * $mult;
}

function rows(): array
{
    preg_match_all(
        '/^\s+(orchestrator|coder|research|fast)\s+\d+\s+([\d.]+[kM])\s+([\d.]+[kM])\s+\$([\d.]+)/m',
        costBlock(),
        $m,
        PREG_SET_ORDER
    );

    return $m;
}

it('finds every tier row in the README cost table', function () {
    expect(rows())->toHaveCount(4);
});

it('prices each tier row correctly against the balanced preset', function () {
    foreach (rows() as [, $tier, $in, $out, $stated]) {
        [$pIn, $pOut] = PRICES()[$tier];
        $actual = tokens($in) / 1e6 * $pIn + tokens($out) / 1e6 * $pOut;

        // Rows are printed to 3dp, so compare at that precision — a tolerance band of exactly
        // half the last place sits right on the boundary and trips on float representation.
        expect((float) $stated)->toBe(round($actual, 3));
    }
});

it('sums the session total from its own rows', function () {
    $sum = 0.0;
    foreach (rows() as [, $tier, $in, $out]) {
        [$pIn, $pOut] = PRICES()[$tier];
        $sum += tokens($in) / 1e6 * $pIn + tokens($out) / 1e6 * $pOut;
    }

    preg_match('/session\s+[\d.]+[kM]\s+[\d.]+[kM]\s+\$([\d.]+)/', costBlock(), $m);

    expect((float) $m[1])->toBeGreaterThan($sum - 0.001)
        ->and((float) $m[1])->toBeLessThan($sum + 0.001);
});

it('states the all-Opus comparison and the saving consistently', function () {
    $block = costBlock();

    $tokIn = $tokOut = 0.0;
    $routed = 0.0;
    foreach (rows() as [, $tier, $in, $out]) {
        [$pIn, $pOut] = PRICES()[$tier];
        $tokIn += tokens($in);
        $tokOut += tokens($out);
        $routed += tokens($in) / 1e6 * $pIn + tokens($out) / 1e6 * $pOut;
    }

    [$oIn, $oOut] = PRICES()['orchestrator'];
    $allOpus = $tokIn / 1e6 * $oIn + $tokOut / 1e6 * $oOut;

    preg_match('/all-Opus 5: \$([\d.]+)\s+·\s+you saved \$([\d.]+)/', $block, $m);

    expect(round((float) $m[1], 2))->toBe(round($allOpus, 2))
        ->and(round((float) $m[2], 2))->toBe(round($allOpus - $routed, 2));
});

it('states the token and spend shares consistently', function () {
    $cheapTok = $allTok = $cheapSpend = $allSpend = 0.0;

    foreach (rows() as [, $tier, $in, $out]) {
        [$pIn, $pOut] = PRICES()[$tier];
        $tok = tokens($in) + tokens($out);
        $spend = tokens($in) / 1e6 * $pIn + tokens($out) / 1e6 * $pOut;

        $allTok += $tok;
        $allSpend += $spend;

        if ($tier !== 'orchestrator') {
            $cheapTok += $tok;
            $cheapSpend += $spend;
        }
    }

    preg_match('/([\d.]+)% of your tokens went through tiers costing ([\d.]+)% of your spend/', costBlock(), $m);

    expect(round((float) $m[1], 1))->toBe(round($cheapTok / $allTok * 100, 1))
        ->and(round((float) $m[2], 1))->toBe(round($cheapSpend / $allSpend * 100, 1));
});
