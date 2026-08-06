<?php

namespace App\Storage;

use App\Support\ModelPricing;

/**
 * The one correct way to record a response-cache hit.
 *
 * This class exists because the naive recording is wrong in a way that looks right.
 * `ModelPricing::costFor()` deliberately returns NULL for a 0-in/0-out/0-cache call — all four
 * at zero means the usage block was never parsed, which is unknown, not a real $0.00 (LOCKED
 * decision #3). A cache hit makes no provider call, so the obvious event carries four zeros,
 * and the saving it was supposed to prove evaporates as `null` on every single hit. The ledger's
 * flagship claim is that it reconciles against provider-reported usage; get this wrong and the
 * feature starts lying in the direction of its own marketing.
 *
 * So a hit is priced from the ORIGINAL response's token counts — what the call WOULD have cost
 * had it been made. Those numbers are real and non-zero, so costFor() returns a real saving.
 *
 * What a hit is NOT allowed to touch:
 *   - `calls`   — no provider call happened
 *   - `spend_usd` — no money was spent
 *   - `tokens_in`/`tokens_out`/cache token columns — nothing was billed
 * Folding a hit into any of those would inflate exactly the numbers the ledger's credibility
 * rests on. Savings are their own accounting line, never a discount applied to spend.
 */
class CacheLedger
{
    public const HIT = 'cache_hit';

    /**
     * @param  string  $model  the model that would have served this, priced as it would have billed
     * @param  int  $tokensIn  the ORIGINAL response's usage, not zeros — see the class docblock
     */
    public static function recordHit(
        EventLog $events,
        string $tier,
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheWrite = 0,
        int $cacheRead = 0,
    ): string {
        return $events->append(self::HIT, [
            'tier' => $tier,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'tokens_cache_write' => $cacheWrite,
            'tokens_cache_read' => $cacheRead,
            // Frozen at write time, same discipline as cost_usd (LOCKED #2/#3): a later edit
            // to config/prices.php must not silently restate savings already claimed.
            'cache_saved_usd' => ModelPricing::costFor($model, $tokensIn, $tokensOut, $cacheWrite, $cacheRead),
        ]);
    }
}
