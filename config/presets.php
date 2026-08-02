<?php

/*
|--------------------------------------------------------------------------
| Provider presets
|--------------------------------------------------------------------------
|
| One flag picks a whole tier stack: `--provider kimi` instead of hand-setting
| --model / --weak-model / --editor-model against defaults that rot. aider and
| cecli make you wire each tier yourself, and their shipped aliases are 1-4
| generations stale (cecli's `haiku` still points at claude-3-5-haiku-20241022,
| an October 2024 model, while its `gemini` alias is current).
|
| Tiers, after the aider playbook:
|   orchestrator - plans, decomposes, reviews. Slow and expensive is fine.
|   coder        - writes the diff. Needs structured outputs to be reliable.
|   research     - reads docs, greps, summarises, fetches. HIGH token volume,
|                  LOW difficulty: you ingest 50k to extract 500. This is where
|                  agents quietly waste most of their money, and the fix is a
|                  huge-context model that costs nothing. qwen3.7-flash is 1M
|                  ctx at $0.03/$0.13 -- 77x cheaper than sonnet-5 on output.
|   fast         - commit messages, retries, one-liners.
|
| Model IDs and prices verified against the live OpenRouter catalogue
| 2026-08-02. Prices are $/Mtok in/out and are informational only: on a
| subscription the binding constraint is the rate limit, not the bill.
|
*/

return [

    'anthropic' => [
        'orchestrator' => 'anthropic/claude-opus-5',      //  $5.00 / $25.00   1M ctx
        'coder'        => 'anthropic/claude-sonnet-5',    //  $2.00 / $10.00
        'research'     => 'anthropic/claude-haiku-4.5', //  $1.00 /  $5.00
        'fast'         => 'anthropic/claude-haiku-4.5',   //  $1.00 /  $5.00
        // claude-fable-5 ($10/$50) and claude-opus-5-fast ($10/$50) trade price
        // for latency; opt in with --orchestrator when a plan is worth it.
    ],

    'openai' => [
        'orchestrator' => 'openai/gpt-5.5-pro',           // $30.00 / $180.00  1.05M ctx
        'coder'        => 'openai/gpt-chat-latest',       //  $5.00 /  $30.00
        'research'     => 'openai/gpt-5-nano',       //  $0.05 /   $0.40
        'fast'         => 'openai/gpt-5-nano',            //  $0.05 /   $0.40
    ],

    'google' => [
        'orchestrator' => 'google/gemini-3.1-pro-preview',//  $2.00 /  $12.00  1.05M ctx
        'coder'        => 'google/gemini-3.6-flash',      //  $1.50 /   $7.50
        'research'     => 'google/gemini-3.6-flash', //  $1.50 /   $7.50   1.05M ctx
        'fast'         => 'google/gemma-4-26b-a4b-it',    //  $0.07 /   $0.34
    ],

    // Jeremy's call, and the catalogue backs it: k3 orchestrates, and the fast
    // coder is k2.7-CODE rather than k2.6 -- newer and coding-specialised for
    // 13c/Mtok more on input.
    'kimi' => [
        'orchestrator' => 'moonshotai/kimi-k3',           //  $3.00 /  $15.00  1.05M ctx
        'coder'        => 'moonshotai/kimi-k2.7-code',    //  $0.73 /   $3.50   262k ctx
        'research'     => 'moonshotai/kimi-k2',      //  $0.57 /   $2.30
        'fast'         => 'moonshotai/kimi-k2',           //  $0.57 /   $2.30
    ],

    'deepseek' => [
        'orchestrator' => 'deepseek/deepseek-v4-pro',     //  $0.43 /   $0.87  1.05M ctx
        'coder'        => 'deepseek/deepseek-v3.2',       //  $0.27 /   $0.40
        'research'     => 'deepseek/deepseek-v4-flash', //  $0.14 /   $0.28
        'fast'         => 'deepseek/deepseek-v4-flash',   //  $0.14 /   $0.28
    ],

    'xai' => [
        'orchestrator' => 'x-ai/grok-4.5',                //  $2.00 /   $6.00   500k ctx
        'coder'        => 'x-ai/grok-4.3',                //  $1.25 /   $2.50     1M ctx
        'research'     => 'x-ai/grok-build-0.1',     //  $1.00 /   $2.00
        'fast'         => 'x-ai/grok-build-0.1',          //  $1.00 /   $2.00
    ],

    'qwen' => [
        'orchestrator' => 'qwen/qwen3.7-max',             //  $1.48 /   $4.42     1M ctx
        // NOT qwen3-coder-plus ($0.65/$3.25). Jeremy's call from using it:
        // it is not smart enough to orchestrate and not fast enough to be the
        // coder -- a dead zone that buys neither intelligence nor speed. The
        // coder tier runs in a loop, so latency compounds; flash wins on both
        // axes. It reports structured_outputs=false, so if malformed diffs
        // ever show up, that is the first thing to suspect.
        'coder'        => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'research'     => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'fast'         => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13
    ],

    'glm' => [
        'orchestrator' => 'z-ai/glm-5.1',                 //  $0.97 /   $3.04
        'coder'        => 'z-ai/glm-5',                   //  $0.95 /   $2.55
        'research'     => 'z-ai/glm-4.7-flash',      //  $0.06 /   $0.40
        'fast'         => 'z-ai/glm-4.7-flash',           //  $0.06 /   $0.40
    ],

    /*
    | THE DEFAULT. Mixing providers per tier is the entire point, and this is
    | the split Jeremy actually runs: Opus 5 to think, qwen3.7-flash to do.
    |
    | The orchestrator sees a plan and a review -- low volume, high value, worth
    | $25/Mtok. Coding and research are the opposite: they run in a loop and
    | ingest whole files, and at $0.13/Mtok out they are effectively free. Both
    | carry 1M context, so neither tier is the one that has to summarise.
    |
    | Spending the same rate on both is how agent bills get absurd. Reading is
    | where the volume is; thinking is where the value is; pay accordingly.
    */
    'balanced' => [
        'orchestrator' => 'anthropic/claude-opus-5',      //  $5.00 /  $25.00   1M ctx
        'coder'        => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13   1M ctx
        'research'     => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13   1M ctx
        'fast'         => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13
    ],

    /*
    | Subscription accounts, not API keys.
    |
    | Jeremy runs 3x Claude Max, 1x OpenAI, 1x Kimi. On a subscription the
    | scarce resource is the rate limit, so the useful behaviour is rotation
    | across accounts rather than cost optimisation -- which is exactly what
    | aigate does (AES-256-GCM key registry, TTL-parks a limited account and
    | retries the next healthy one).
    |
    | CAVEAT worth designing around up front: since 2026-06-15 headless
    | `claude -p` bills a separate metered credit pool, NOT the Max
    | subscription. A Max seat cannot be spent through the API. Either use API
    | keys per-token, or drive the `claude` binary as a subprocess and accept
    | metered billing. Verify the current terms before building on either.
    */
    'accounts' => [
        'rotate'   => true,
        'broker'   => 'aigate',
        'strategy' => 'rate-limit-aware',   // park a 429'd account, retry next healthy
    ],
];
