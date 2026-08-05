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
| NOT a discount: a consumer must bill those tokens at the full `in` rate. That
| OVER-estimates cost, which is the only safe direction — under-estimating is
| how a product gets priced into a loss. Fill a null in only against the
| provider's own pricing page, never by analogy to another vendor.
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
    'openai/gpt-5.5-pro' => ['in' => 30.00, 'out' => 180.00, 'cache_write' => null, 'cache_read' => null],
    'openai/gpt-chat-latest' => ['in' => 5.00, 'out' => 30.00, 'cache_write' => null, 'cache_read' => null],
    'openai/gpt-5-nano' => ['in' => 0.05, 'out' => 0.40, 'cache_write' => null, 'cache_read' => null],

    'google/gemini-3.1-pro-preview' => ['in' => 2.00, 'out' => 12.00, 'cache_write' => null, 'cache_read' => null],
    'google/gemini-3.6-flash' => ['in' => 1.50, 'out' => 7.50, 'cache_write' => null, 'cache_read' => null],
    'google/gemma-4-26b-a4b-it' => ['in' => 0.07, 'out' => 0.34, 'cache_write' => null, 'cache_read' => null],

    'moonshotai/kimi-k3' => ['in' => 3.00, 'out' => 15.00, 'cache_write' => null, 'cache_read' => null],
    'moonshotai/kimi-k2.7-code' => ['in' => 0.73, 'out' => 3.50, 'cache_write' => null, 'cache_read' => null],
    'moonshotai/kimi-k2.6' => ['in' => 0.60, 'out' => 3.41, 'cache_write' => null, 'cache_read' => null],
    'moonshotai/kimi-k2' => ['in' => 0.57, 'out' => 2.30, 'cache_write' => null, 'cache_read' => null],

    'deepseek/deepseek-v4-pro' => ['in' => 0.435, 'out' => 0.87, 'cache_write' => null, 'cache_read' => null],
    'deepseek/deepseek-v3.2' => ['in' => 0.269, 'out' => 0.40, 'cache_write' => null, 'cache_read' => null],
    'deepseek/deepseek-v4-flash' => ['in' => 0.14, 'out' => 0.28, 'cache_write' => null, 'cache_read' => null],

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
];
