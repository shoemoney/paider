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

    public static function costFor(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheWrite = 0,
        int $cacheRead = 0,
    ): ?float {
        $price = config('prices')[$model] ?? null;

        if ($price === null) {
            return null;
        }

        // A completion is never free. All FOUR at zero means the usage block was never
        // parsed (differently-keyed provider response, or a 200-with-error body) --
        // that is unknown, not a real $0.00 call (LOCKED decision #3, one field over).
        //
        // Checking only in/out here would be wrong now that caching is priced: on a
        // warm Anthropic session input_tokens is routinely 0-2 while cache_read is six
        // figures, so a real, expensive turn can arrive with in=0 and be discarded as
        // unparsed. MEASURED: 403 input tokens across 209 turns, against 24.3M cached.
        if ($tokensIn === 0 && $tokensOut === 0 && $cacheWrite === 0 && $cacheRead === 0) {
            return null;
        }

        // A null cache rate means "no verified rate for this model" -- NOT zero and NOT
        // a discount. Bill those at the full input rate, which over-estimates. Under-
        // estimating is how a product gets priced into a loss. See config/prices.php.
        $writeRate = $price['cache_write'] ?? $price['in'];
        $readRate = $price['cache_read'] ?? $price['in'];

        return $tokensIn / 1e6 * $price['in']
            + $tokensOut / 1e6 * $price['out']
            + $cacheWrite / 1e6 * $writeRate
            + $cacheRead / 1e6 * $readRate;
    }
}
