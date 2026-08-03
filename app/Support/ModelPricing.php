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
    /**
     * The README's "same work on all-Opus 5" reference model. One shared constant so
     * Loop.php, CommitCommand.php, and CostCommand.php's write-time hypothetical_usd
     * and read-time rendering can't drift onto different models.
     */
    public const REFERENCE_MODEL = 'anthropic/claude-opus-5';

    public static function costFor(string $model, int $tokensIn, int $tokensOut): ?float
    {
        $price = config('prices')[$model] ?? null;

        if ($price === null) {
            return null;
        }

        // A completion is never free. 0 in AND 0 out means the usage block was never
        // parsed (differently-keyed provider response, or a 200-with-error body) --
        // that is unknown, not a real $0.00 call (LOCKED decision #3, one field over).
        if ($tokensIn === 0 && $tokensOut === 0) {
            return null;
        }

        return $tokensIn / 1e6 * $price['in'] + $tokensOut / 1e6 * $price['out'];
    }
}
