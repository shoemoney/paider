<?php

namespace App\Support;

/**
 * Looks up config/prices.php by exact model id and prices a call at full float
 * precision (rounding happens at render, not here).
 *
 * EXACT match only -- no prefix, fuzzy, or normalised (trim/lowercase) matching.
 * A typo'd model id silently matching a similar-but-wrong one would misprice a
 * call with no signal anything went wrong; returning null for anything that
 * isn't a byte-for-byte match is the safer failure.
 */
class ModelPricing
{
    public static function costFor(string $model, int $tokensIn, int $tokensOut): ?float
    {
        $price = config('prices')[$model] ?? null;

        if ($price === null) {
            return null;
        }

        return $tokensIn / 1e6 * $price['in'] + $tokensOut / 1e6 * $price['out'];
    }
}
