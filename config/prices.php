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
*/

return [
    'anthropic/claude-opus-5' => ['in' => 5.00, 'out' => 25.00],
    'anthropic/claude-sonnet-5' => ['in' => 2.00, 'out' => 10.00],
    'anthropic/claude-haiku-4.5' => ['in' => 1.00, 'out' => 5.00],

    'openai/gpt-5.5-pro' => ['in' => 30.00, 'out' => 180.00],
    'openai/gpt-chat-latest' => ['in' => 5.00, 'out' => 30.00],
    'openai/gpt-5-nano' => ['in' => 0.05, 'out' => 0.40],

    'google/gemini-3.1-pro-preview' => ['in' => 2.00, 'out' => 12.00],
    'google/gemini-3.6-flash' => ['in' => 1.50, 'out' => 7.50],
    'google/gemma-4-26b-a4b-it' => ['in' => 0.07, 'out' => 0.34],

    'moonshotai/kimi-k3' => ['in' => 3.00, 'out' => 15.00],
    'moonshotai/kimi-k2.7-code' => ['in' => 0.73, 'out' => 3.50],
    'moonshotai/kimi-k2.6' => ['in' => 0.60, 'out' => 3.41],
    'moonshotai/kimi-k2' => ['in' => 0.57, 'out' => 2.30],

    'deepseek/deepseek-v4-pro' => ['in' => 0.435, 'out' => 0.87],
    'deepseek/deepseek-v3.2' => ['in' => 0.269, 'out' => 0.40],
    'deepseek/deepseek-v4-flash' => ['in' => 0.14, 'out' => 0.28],

    'x-ai/grok-4.5' => ['in' => 2.00, 'out' => 6.00],
    'x-ai/grok-4.3' => ['in' => 1.25, 'out' => 2.50],
    'x-ai/grok-build-0.1' => ['in' => 1.00, 'out' => 2.00],

    'qwen/qwen3.7-max' => ['in' => 1.475, 'out' => 4.425],
    'qwen/qwen3.8-max' => ['in' => 2.0, 'out' => 6.0],
    'qwen/qwen3.7-flash' => ['in' => 0.03, 'out' => 0.13],

    'z-ai/glm-5.1' => ['in' => 0.966, 'out' => 3.036],
    'z-ai/glm-5' => ['in' => 0.95, 'out' => 2.55],
    'z-ai/glm-4.7-flash' => ['in' => 0.06, 'out' => 0.40],

    'minimax/minimax-m3' => ['in' => 0.30, 'out' => 1.20],
];
