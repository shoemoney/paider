<?php

/*
 * Live provider round-trips. These cost real money and hit the real network, so they are
 * excluded from the default suite (see phpunit.xml.dist) and run with:
 *
 *     vendor/bin/pest --group=live
 *
 * They exist because every other provider test in this repo asserts against a Guzzle
 * MockHandler whose fixtures were written by the same hands as the parser. That proves the
 * code is self-consistent and nothing about whether a real provider's response shape,
 * error envelope, or usage-field placement matches what we assumed.
 */

use App\Providers\AnthropicClient;
use App\Providers\OpenAiCompatibleClient;
use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;

/** Skip rather than fail when a key is absent — CI without secrets should stay green. */
function liveKey(string $env): string
{
    $key = getenv($env) ?: '';

    if ($key === '') {
        test()->markTestSkipped("{$env} is not set; skipping live call.");
    }

    return $key;
}

test('OpenAiCompatibleClient completes a real round-trip through OpenRouter', function () {
    $client = new OpenAiCompatibleClient(
        'https://openrouter.ai/api/v1',
        'OPENROUTER_API_KEY',
        liveKey('OPENROUTER_API_KEY'),
    );

    $response = $client->send(
        [['role' => 'user', 'content' => 'Reply with exactly the word: PAIDER']],
        'qwen/qwen3-max',
    );

    expect($response->content)->toBeString()->not->toBe('')
        ->and($response->tokensIn)->toBeGreaterThan(0)
        ->and($response->tokensOut)->toBeGreaterThan(0)
        ->and($response->raw)->toBeArray()->toHaveKey('usage');

    // The parser reads usage.prompt_tokens / usage.completion_tokens. If a provider ever
    // moves or renames those, tokensIn/tokensOut silently become 0 and the ledger under-bills.
    expect($response->raw['usage']['prompt_tokens'])->toBe($response->tokensIn)
        ->and($response->raw['usage']['completion_tokens'])->toBe($response->tokensOut);
})->group('live');

test('AnthropicClient completes a real round-trip against an Anthropic-compatible endpoint', function () {
    /*
     * No Anthropic key exists on this machine, but the Anthropic WIRE FORMAT is what needs
     * proving, not the vendor. xAI serves it at https://api.x.ai/v1/messages, so an XAI_API_KEY
     * exercises the same code path: the `system` hoist, `content` block filtering, and
     * usage.input_tokens/output_tokens placement.
     *
     * Prefers a real ANTHROPIC_API_KEY when one is present. Moonshot was tried first and does
     * not work here — the vaulted kimi credential is an `sk-kim…` kimi.com coding-subscription
     * key, which 401s against both api.moonshot.ai and api.moonshot.cn (probed, not assumed).
     */
    [$key, $base, $model] = getenv('ANTHROPIC_API_KEY')
        ? [getenv('ANTHROPIC_API_KEY'), 'https://api.anthropic.com', 'claude-haiku-4-5-20251001']
        : [liveKey('XAI_API_KEY'), 'https://api.x.ai', 'grok-4'];

    $client = new AnthropicClient($key, $base);

    $response = $client->send(
        [['role' => 'user', 'content' => 'Reply with exactly the word: PAIDER']],
        $model,
    );

    // Real Anthropic-format responses interleave non-text blocks — grok-4 returns a `thinking`
    // block before its `text` block. The parser filters on type === 'text'; if that filter ever
    // breaks, content comes back with reasoning glued to the front. Fixtures we wrote ourselves
    // never exercised this, which is precisely why it is asserted here.
    $types = array_column($response->raw['content'] ?? [], 'type');
    expect($types)->toContain('text')
        ->and($response->content)->not->toContain('thinking');

    expect($response->content)->toBeString()->not->toBe('')
        ->and($response->tokensIn)->toBeGreaterThan(0)
        ->and($response->tokensOut)->toBeGreaterThan(0);

    expect($response->raw['usage']['input_tokens'])->toBe($response->tokensIn)
        ->and($response->raw['usage']['output_tokens'])->toBe($response->tokensOut);
})->group('live');

test('the cost ledger reconciles against what the provider actually reported', function () {
    // The whole product bet is "named cost tiers with a checkable ledger". Until now the
    // ledger has only ever been checked against our own arithmetic. This asserts it against
    // a real provider's own usage numbers.
    $client = new OpenAiCompatibleClient(
        'https://openrouter.ai/api/v1',
        'OPENROUTER_API_KEY',
        liveKey('OPENROUTER_API_KEY'),
    );

    $log = new EventLog(Database::connect(':memory:'));
    $ledger = new CostLedger($log);

    $priceIn = 1.20 / 1_000_000;   // qwen3-max, USD per token
    $priceOut = 6.00 / 1_000_000;

    $reportedIn = 0;
    $reportedOut = 0;

    foreach (['research', 'research', 'fast'] as $tier) {
        $r = $client->send(
            [['role' => 'user', 'content' => 'Reply with exactly the word: PAIDER']],
            'qwen/qwen3-max',
        );

        $reportedIn += $r->raw['usage']['prompt_tokens'];
        $reportedOut += $r->raw['usage']['completion_tokens'];

        $log->append('tier_call', [
            'tier' => $tier,
            'tokens_in' => $r->tokensIn,
            'tokens_out' => $r->tokensOut,
            'cost_usd' => $r->tokensIn * $priceIn + $r->tokensOut * $priceOut,
        ]);
    }

    $summary = $ledger->summary();

    expect($summary['session']['calls'])->toBe(3)
        ->and($summary['research']['calls'])->toBe(2)
        ->and($summary['fast']['calls'])->toBe(1);

    // The projection must equal the provider's own totals, not our recollection of them.
    expect($summary['session']['tokens_in'])->toBe($reportedIn)
        ->and($summary['session']['tokens_out'])->toBe($reportedOut);

    $expectedSpend = $reportedIn * $priceIn + $reportedOut * $priceOut;
    expect($summary['session']['spend_usd'])->toBeGreaterThan($expectedSpend - 1e-9)
        ->and($summary['session']['spend_usd'])->toBeLessThan($expectedSpend + 1e-9);

    // And the tiers must partition the session exactly — no double-counting, no drift.
    expect($summary['research']['tokens_in'] + $summary['fast']['tokens_in'])
        ->toBe($summary['session']['tokens_in']);
})->group('live');
