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
        'coder' => 'anthropic/claude-sonnet-5',    //  $2.00 / $10.00
        'research' => 'anthropic/claude-haiku-4.5', //  $1.00 /  $5.00
        'fast' => 'anthropic/claude-haiku-4.5',   //  $1.00 /  $5.00
        // claude-fable-5 ($10/$50) and claude-opus-5-fast ($10/$50) trade price
        // for latency; opt in with --orchestrator when a plan is worth it.
    ],

    'openai' => [
        'orchestrator' => 'openai/gpt-5.5-pro',           // $30.00 / $180.00  1.05M ctx
        'coder' => 'openai/gpt-chat-latest',       //  $5.00 /  $30.00
        'research' => 'openai/gpt-5-nano',       //  $0.05 /   $0.40
        'fast' => 'openai/gpt-5-nano',            //  $0.05 /   $0.40
    ],

    'google' => [
        'orchestrator' => 'google/gemini-3.1-pro-preview', //  $2.00 /  $12.00  1.05M ctx
        'coder' => 'google/gemini-3.6-flash',      //  $1.50 /   $7.50
        'research' => 'google/gemini-3.6-flash', //  $1.50 /   $7.50   1.05M ctx
        'fast' => 'google/gemma-4-26b-a4b-it',    //  $0.07 /   $0.34
    ],

    // Jeremy's call, and the catalogue backs it: k3 orchestrates, and the fast
    // coder is k2.7-CODE rather than k2.6 -- newer and coding-specialised for
    // 13c/Mtok more on input.
    'kimi' => [
        'orchestrator' => 'moonshotai/kimi-k3',           //  $3.00 /  $15.00  1.05M ctx
        // Direct API (api.moonshot.ai/v1) also offers kimi-k2.7-code-HIGHSPEED,
        // which Moonshot recommends "when you need higher output speed" -- exactly
        // the coder tier, where latency compounds inside a loop. It is NOT listed
        // on OpenRouter, so it is reachable only via the direct endpoint. Prefer it
        // when the user has a Moonshot key rather than an aggregator key.
        'coder' => 'moonshotai/kimi-k2.7-code',    //  $0.73 /   $3.50   262k ctx
        'research' => 'moonshotai/kimi-k2',      //  $0.57 /   $2.30
        'fast' => 'moonshotai/kimi-k2',           //  $0.57 /   $2.30
    ],

    'deepseek' => [
        'orchestrator' => 'deepseek/deepseek-v4-pro',     //  $0.435 /   $0.87  1.05M ctx
        'coder' => 'deepseek/deepseek-v3.2',       //  $0.269 /   $0.40
        'research' => 'deepseek/deepseek-v4-flash', //  $0.14 /   $0.28
        'fast' => 'deepseek/deepseek-v4-flash',   //  $0.14 /   $0.28
    ],

    'xai' => [
        'orchestrator' => 'x-ai/grok-4.5',                //  $2.00 /   $6.00   500k ctx
        'coder' => 'x-ai/grok-4.3',                //  $1.25 /   $2.50     1M ctx
        'research' => 'x-ai/grok-build-0.1',     //  $1.00 /   $2.00
        'fast' => 'x-ai/grok-build-0.1',          //  $1.00 /   $2.00
    ],

    'qwen' => [
        // 3.8-max over 3.7-max on Jeremy's bench read 2026-08-03 ("benching very well").
        // Costs more ($2.00/$6.00 vs $1.475/$4.425) and that is fine here: the
        // orchestrator is the low-volume, high-value tier this whole file is arranged
        // around. It also adds vision, which 3.7-max does not have — that model rejects
        // an image content array outright. Both are on the sk-sp- plan allowlist.
        'orchestrator' => 'qwen/qwen3.8-max',             //  $2.00  /   $6.00      1M ctx
        // NOT qwen3-coder-plus ($0.65/$3.25). Jeremy's call from using it:
        // it is not smart enough to orchestrate and not fast enough to be the
        // coder -- a dead zone that buys neither intelligence nor speed. The
        // coder tier runs in a loop, so latency compounds; flash wins on both
        // axes. It reports structured_outputs=false, so if malformed diffs
        // ever show up, that is the first thing to suspect.
        'coder' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'research' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'fast' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13
    ],

    'glm' => [
        'orchestrator' => 'z-ai/glm-5.1',                 //  $0.966 /   $3.036
        'coder' => 'z-ai/glm-5',                   //  $0.95 /   $2.55
        'research' => 'z-ai/glm-4.7-flash',      //  $0.06 /   $0.40
        'fast' => 'z-ai/glm-4.7-flash',           //  $0.06 /   $0.40
    ],

    /*
    | OPEN-WEIGHT STACK. No US frontier lab in the loop -- for people who want
    | models they could in principle self-host, or who simply will not send code
    | to Anthropic/OpenAI. A real constituency, and nobody in the PHP space
    | ships a preset for them.
    |
    | Weights: every model in this stack has published, downloadable weights.
    | K3 confirmed 2026-08-02; K2.6 and V4-Flash confirmed 2026-08-03 against the
    | HuggingFace API -- both public, ungated, with real safetensors shards (64 and
    | 46) rather than a card promising them. So this stack is genuinely
    | self-hostable end to end, which is the whole point of it.
    */
    'open' => [
        'orchestrator' => 'moonshotai/kimi-k3',           //  $3.00 /  $15.00   1.05M ctx
        // k2.6 over qwen3.7-flash 2026-08-03, Jeremy's call from use. This is the one
        // tier in this file where the cheap option is NOT taken: 20x the input rate and
        // 26x the output ($0.60/$3.41 vs $0.03/$0.13), on the tier that runs in a loop.
        // Deliberate — the coder writes the diff, and a wrong diff costs more reruns
        // than the token delta saves.
        //
        // It also drops this tier to 262k context while orchestrator and research keep
        // ~1M, so on this preset the coder IS the tier that has to summarise. If large
        // files start getting truncated here, that is the reason.
        //
        // Moonshot's own coding endpoint (api.kimi.com/coding/v1) serves
        // `kimi-for-coding-highspeed`, a faster variant absent from OpenRouter — prefer
        // it when the user has a kimi.com coding subscription rather than an aggregator
        // key, same as the k2.7-code-HIGHSPEED note on the `kimi` preset above.
        'coder' => 'moonshotai/kimi-k2.6',      //  $0.60 /   $3.41   262k ctx
        // research/fast on deepseek-v4-flash 2026-08-03, Jeremy's call from use.
        // NOTE it is 4.7x qwen3.7-flash on input ($0.14 vs $0.03), so this is not a
        // cost cut on paper — it is a quality-per-dollar call on the tier that reads
        // whole repos, and DeepSeek's context cache ($0.0028/Mtok on a hit, 50x under
        // the miss rate) is what makes it land cheaper in practice on re-read-heavy work.
        // V4-Flash weights confirmed open-weight 2026-08-03: MIT license, available on HuggingFace.
        'research' => 'deepseek/deepseek-v4-flash', //  $0.14 /   $0.28   1.05M ctx
        'fast' => 'deepseek/deepseek-v4-flash', //  $0.14 /   $0.28
    ],

    /*
    | Same idea, an order of magnitude cheaper on the thinking tier. minimax-m3
    | is $0.30/$1.20 at 1M ctx -- one twentieth of kimi-k3 -- so the whole stack
    | runs for pennies. Worth benchmarking m3 against k3 on real planning work
    | before deciding which is the default open orchestrator.
    |
    | M3 weights confirmed 2026-08-03 against the HuggingFace API: public,
    | ungated, 59 safetensors shards. This preset is self-hostable end to end,
    | which the UNVERIFIED warning that stood here until today wrongly denied.
    */
    'open-frugal' => [
        'orchestrator' => 'minimax/minimax-m3',           //  $0.30 /   $1.20   1.05M ctx
        'coder' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'research' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13     1M ctx
        'fast' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13
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
        'coder' => 'qwen/qwen3.7-flash',           //  $0.03 /   $0.13   1M ctx
        // research and fast moved to deepseek-v4-flash 2026-08-03, Jeremy's call from using
        // it. The modelled session below prices every input token as a CACHE MISS at $0.14,
        // which is the honest worst case and raises it $0.943 -> $1.159. Real cost is very
        // likely lower: DeepSeek charges $0.0028/Mtok on a context-cache HIT, 50x below the
        // miss rate, and the research tier re-reads the same repo context on every call —
        // the ideal hit profile. Break-even against qwen3.7-flash lands near an 82% hit
        // rate; at 90% this tier costs $0.039 against qwen's $0.058.
        //
        // The README quotes the worst case on purpose. A default tuned to keep the headline
        // number small rather than to do the job well is the same self-deception the Paider
        // 100 section is written against, and quoting the cache-warm figure as if it were
        // typical would be the same error pointed the other way.
        'research' => 'deepseek/deepseek-v4-flash', //  $0.14 /   $0.28
        'fast' => 'deepseek/deepseek-v4-flash',   //  $0.14 /   $0.28
    ],

    'meta' => [
        'orchestrator' => 'meta/muse-spark-1.2',           // via https://api.meta.ai/v1, key from aigate provider=meta or META_API_KEY
        'coder' => 'meta/muse-spark-1.2',
        'research' => 'meta/muse-spark-1.1',
        'fast' => 'meta/muse-spark-1.1',
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
        'rotate' => true,
        'broker' => 'aigate',
        'strategy' => 'rate-limit-aware',   // park a 429'd account, retry next healthy
    ],
];
