<?php

/*
|--------------------------------------------------------------------------
| Model prices
|--------------------------------------------------------------------------
|
| USD per Mtok in/out, keyed by EXACT model id (not preset, not tier) — the
| same model id appears under several presets/tiers in config/presets.php,
| so keying by preset would duplicate each price 20+ times and recreate the
| drift this fixes. Values are transcribed from the trailing "// $X / $Y"
| comments in config/presets.php; tests/Feature/PricesSyncTest.php asserts
| the two never drift apart.
|
| Used only at write time by the cost ledger — see app/Storage/CostLedger.php
| for why cost_usd is priced once and never re-derived from this file later.
|
|--------------------------------------------------------------------------
| Cache columns: cache_write / cache_read
|--------------------------------------------------------------------------
|
| MEASURED, on a real two-day household transcript: cache read + cache write
| were 93% of the bill, and plain input tokens were 0.002% of it. A price table
| carrying only in/out under-reports a cached workload by more than an order of
| magnitude, so a ledger built on in/out alone is not a rounding error — it is
| the wrong number.
|
| null means "no verified cache rate for this model". A null is NOT zero and is
| NOT a discount. Fill one in only against the provider's own pricing page, never
| by analogy to another vendor — MEASURED, DeepSeek's two models have different
| hit ratios as of 2026-08-05 (0.020x vs 0.0083x of the miss rate), so even the
| same vendor is not internally consistent.
|
| A null READ and a null WRITE do NOT fall back the same way, and getting this
| backwards is how the safe direction becomes the unsafe one:
|
|   cache_read  null -> bill at the full `in` rate. A read is ALWAYS a discount
|                       (0.10x Anthropic, 0.10x OpenAI, 0.02x DeepSeek), so the
|                       full rate over-estimates. Safe.
|   cache_write null -> bill at 1.25x `in`. A write carries a PREMIUM where it is
|                       charged at all (1.25x Anthropic, 1.25x OpenAI; DeepSeek
|                       charges nothing extra). Falling back to 1.00x here would
|                       UNDER-estimate by 25% — the one direction that prices a
|                       product into a loss.
|
| Over-estimating is the only safe direction. The asymmetry above is what makes
| that true for both columns instead of just one.
|
|--------------------------------------------------------------------------
| Where this schema does not reach: Google
|--------------------------------------------------------------------------
|
| Gemini context caching bills a per-token cached rate PLUS a STORAGE charge per
| million tokens per HOUR ($1.00/Mtok/hr on the Flash tier, $4.50 on 2.5 Pro).
| Two rate columns cannot express a cost with a time dimension, so a Gemini row
| here is INCOMPLETE by construction, not merely unverified — the cached rate is
| recorded and the storage cost is silently absent.
|
| Do not price a long-lived Gemini cache off this file. If Gemini ever becomes a
| real target, `cache_storage_per_mtok_hour` is the missing column and the ledger
| needs the cache's age to use it.
|
| Anthropic's are the multipliers Anthropic publishes: a cache WRITE is 1.25x
| base input (5-minute TTL) and a cache READ is 0.10x base input. A 1-hour TTL
| write is 2.00x instead; if a caller ever opts into that, it needs its own
| column rather than a silent re-use of this one.
|
*/

return [
    // in, out, cache_write, cache_read — all USD per Mtok
    'anthropic/claude-opus-5' => ['in' => 5.00, 'out' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
    'anthropic/claude-sonnet-5' => ['in' => 2.00, 'out' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
    'anthropic/claude-haiku-4.5' => ['in' => 1.00, 'out' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],

    // Everything below is UNVERIFIED for caching. Several of these providers do
    // offer prompt/context caching and some price it very differently from
    // Anthropic (a cache-hit input rate rather than a write+read pair, or an
    // hourly storage charge). Verify per provider before trusting a number here.
    'openai/gpt-5.5-pro' => ['in' => 30.00, 'out' => 180.00, 'cache_write' => 30.00, 'cache_read' => 30.00],  // caching NOT offered for this model — no discount exists
    'openai/gpt-chat-latest' => ['in' => 5.00, 'out' => 30.00, 'cache_write' => null, 'cache_read' => 0.50],  // cached input verified; write premium not published for this id
    'openai/gpt-5-nano' => ['in' => 0.05, 'out' => 0.40, 'cache_write' => null, 'cache_read' => 0.005],  // cached input verified exactly

    'google/gemini-3.1-pro-preview' => ['in' => 2.00, 'out' => 12.00, 'cache_write' => null, 'cache_read' => null],
    'google/gemini-3.6-flash' => ['in' => 1.50, 'out' => 7.50, 'cache_write' => null, 'cache_read' => 0.15],  // cached input verified — BUT SEE THE GEMINI NOTE: hourly storage is unrepresentable here
    'google/gemma-4-26b-a4b-it' => ['in' => 0.07, 'out' => 0.34, 'cache_write' => null, 'cache_read' => null],  // context caching not available on the paid tier

    // Moonshot publishes cache-HIT vs cache-MISS input and no separate write charge.
    // Ratios are NOT uniform across their own models (0.10x k3, 0.168x k2.6, 0.20x
    // k2.7-code) — the third vendor in this file to prove a borrowed multiplier is a
    // guess. Only k3's base matches the vendor page, so only k3's hit rate is safe to
    // record here; see the mismatch note below.
    'moonshotai/kimi-k3' => ['in' => 3.00, 'out' => 15.00, 'cache_write' => 3.00, 'cache_read' => 0.30],  // hit 0.10x miss; no write surcharge
    'moonshotai/kimi-k2.7-code' => ['in' => 0.73, 'out' => 3.50, 'cache_write' => null, 'cache_read' => null],
    'moonshotai/kimi-k2.6' => ['in' => 0.60, 'out' => 3.41, 'cache_write' => null, 'cache_read' => null],
    'moonshotai/kimi-k2' => ['in' => 0.57, 'out' => 2.30, 'cache_write' => null, 'cache_read' => null],

    // ⚠️ UNRESOLVED, 2026-08-05 — these two disagree with Moonshot's own pricing page:
    //
    //     model              here          platform.moonshot.ai
    //     kimi-k2.7-code     0.73 / 3.50   0.95 / 4.00   (hit 0.19)
    //     kimi-k2.6          0.60 / 3.41   0.95 / 4.00   (hit 0.16)
    //
    // CommitCommand routes 'kimi' to https://api.moonshot.ai/v1 — DIRECT — while these
    // look like OpenRouter rates, which is also where the `moonshotai/` id prefix comes
    // from and where the default client falls back to. Both numbers can be right for
    // their own route; only one can be right for the route actually taken.
    //
    // If the direct endpoint is what runs, every kimi call is under-billed ~30% on input
    // and ~14% on output. Left as-is rather than "fixed" because picking a side silently
    // is how a ledger starts lying confidently, and because these values are asserted
    // against presets.php comments by PricesSyncTest — the two must move together.
    //
    // Their cache-hit rates stay null for the same reason: grafting Moonshot's 0.16/0.19
    // onto an OpenRouter base would blend two price sources into one row.

    'deepseek/deepseek-v4-pro' => ['in' => 0.435, 'out' => 0.87, 'cache_write' => 0.435, 'cache_read' => 0.003625],  // hit 0.0083x miss; no write surcharge
    'deepseek/deepseek-v3.2' => ['in' => 0.269, 'out' => 0.40, 'cache_write' => null, 'cache_read' => null],
    'deepseek/deepseek-v4-flash' => ['in' => 0.14, 'out' => 0.28, 'cache_write' => 0.14, 'cache_read' => 0.0028],  // hit 0.020x miss; no write surcharge

    'x-ai/grok-4.5' => ['in' => 2.00, 'out' => 6.00, 'cache_write' => null, 'cache_read' => null],
    'x-ai/grok-4.3' => ['in' => 1.25, 'out' => 2.50, 'cache_write' => null, 'cache_read' => null],
    'x-ai/grok-build-0.1' => ['in' => 1.00, 'out' => 2.00, 'cache_write' => null, 'cache_read' => null],

    'qwen/qwen3.7-max' => ['in' => 1.475, 'out' => 4.425, 'cache_write' => null, 'cache_read' => null],
    'qwen/qwen3.8-max' => ['in' => 2.0, 'out' => 6.0, 'cache_write' => null, 'cache_read' => null],
    'qwen/qwen3.7-flash' => ['in' => 0.03, 'out' => 0.13, 'cache_write' => null, 'cache_read' => null],

    'z-ai/glm-5.1' => ['in' => 0.966, 'out' => 3.036, 'cache_write' => null, 'cache_read' => null],
    'z-ai/glm-5' => ['in' => 0.95, 'out' => 2.55, 'cache_write' => null, 'cache_read' => null],
    'z-ai/glm-4.7-flash' => ['in' => 0.06, 'out' => 0.40, 'cache_write' => null, 'cache_read' => null],

    'minimax/minimax-m3' => ['in' => 0.30, 'out' => 1.20, 'cache_write' => null, 'cache_read' => null],

    'meta/muse-spark-1.2' => ['in' => 0.50, 'out' => 1.50, 'cache_write' => null, 'cache_read' => null],
    'meta/muse-spark-1.2-contributor' => ['in' => 0.50, 'out' => 1.50, 'cache_write' => null, 'cache_read' => null],
    'meta/muse-spark-1.1' => ['in' => 0.30, 'out' => 0.90, 'cache_write' => null, 'cache_read' => null],
];
