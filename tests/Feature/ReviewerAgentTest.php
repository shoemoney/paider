<?php

use App\Agent\ReviewerAgent;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;

/**
 * Phase C. The reviewer subagent shipped in c9aefd6 with a hard budget and ZERO tests, which is
 * the one shape this repo's own history says not to ship: DECISIONS.md §20 is a test that could
 * not fail, and COMPLETION-PLAN.md's C2 says a bounds test that only proves the happy path is
 * "the same defect wearing a new hat — and bounds are the only thing that keeps an agent from
 * looping until it runs out of money."
 *
 * So the cap is tested as a thing that HALTS, on a path where it is supposed to halt, and the
 * ledger is tested separately from the meter — because they are allowed to disagree, and when
 * they do the honest answer is which one is lying.
 */
class ReviewerAgentTestProvider implements ProviderClient
{
    /** @var array<int, array{model: string, messages: array}> */
    public array $dispatched = [];

    public function __construct(
        private readonly int $tokensIn = 1000,
        private readonly int $tokensOut = 500,
        private readonly ?string $servedModel = null,
    ) {}

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        $this->dispatched[] = ['model' => $model, 'messages' => $messages];

        return new ProviderResponse(
            content: 'review '.count($this->dispatched),
            tokensIn: $this->tokensIn,
            tokensOut: $this->tokensOut,
            raw: [],
            servedModel: $this->servedModel,
        );
    }
}

function reviewerAgent(
    ProviderClient $provider,
    ?EventLog $log = null,
    string $model = 'anthropic/claude-sonnet-5',
    int $maxCalls = ReviewerAgent::DEFAULT_MAX_CALLS,
    float $maxSpendUsd = ReviewerAgent::DEFAULT_MAX_SPEND_USD,
): ReviewerAgent {
    return new ReviewerAgent(
        $provider,
        $log ?? new EventLog(Database::connect(':memory:')),
        $model,
        $maxCalls,
        $maxSpendUsd,
    );
}

it('refuses a delegation with no room in it', function () {
    // A budget of zero is not a tight budget, it is a broken one, and it must fail at
    // construction rather than at the first call — where the failure would look like a provider
    // problem instead of a wiring problem.
    expect(fn () => reviewerAgent(new ReviewerAgentTestProvider, null, 'anthropic/claude-sonnet-5', 0))
        ->toThrow(RuntimeException::class, 'at least one call');

    expect(fn () => reviewerAgent(new ReviewerAgentTestProvider, null, 'anthropic/claude-sonnet-5', 3, 0.0))
        ->toThrow(RuntimeException::class, 'positive spend ceiling');
});

it('HALTS at the call ceiling instead of merely starting under it', function () {
    // The C2 shape. Five subjects, room for two calls: the delegation must stop at two, and the
    // result must SAY it stopped. An agent that quietly reviews two of five and reports success
    // is the failure mode the class docblock calls worse than an exception.
    $provider = new ReviewerAgentTestProvider;
    $agent = reviewerAgent($provider, null, 'anthropic/claude-sonnet-5', 2);

    $result = $agent->reviewAll(['a', 'b', 'c', 'd', 'e']);

    expect($result['reviews'])->toHaveCount(2)
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['reason'])->not->toBeNull()
        ->and($result['calls'])->toBe(2)
        // And the provider was called exactly twice — the cap holds on the wire, not just in the
        // bookkeeping. A counter that stops incrementing while calls keep going is not a cap.
        ->and($provider->dispatched)->toHaveCount(2);
});

it('the spend ceiling actually halts a priced delegation', function () {
    // The happy path, deliberately NOT left alone. 1000 in / 500 out on sonnet-5 ($2/$10 per 1M)
    // is $0.002 + $0.005 = $0.007 a call, so a $0.01 ceiling admits two calls ($0.014) and refuses
    // the third. maxCalls is 10, nowhere near — so this can ONLY trip on money, which is the point:
    // it proves the meter moves at all, and it makes the unpriced test below meaningful rather
    // than vacuous.
    $provider = new ReviewerAgentTestProvider(tokensIn: 1000, tokensOut: 500);
    $agent = reviewerAgent($provider, null, 'anthropic/claude-sonnet-5', 10, 0.01);

    $first = $agent->review('a');
    expect($first['ok'])->toBeTrue()
        ->and($first['spent_usd'])->toBeGreaterThan(0.0);

    $second = $agent->review('b');
    expect($second['ok'])->toBeTrue()
        ->and($second['spent_usd'])->toBeGreaterThan(0.01);

    $third = $agent->review('c');
    expect($third['ok'])->toBeFalse()
        ->and($third['reason'])->toContain('budget exhausted')
        ->and($provider->dispatched)->toHaveCount(2);
});

it('an UNPRICED model still spends against the ceiling, instead of metering as free', function () {
    // THE DEFECT THIS FILE WAS WRITTEN FOR.
    //
    // `spentUsd += $cost ?? 0.0` treats a model with no entry in config('prices') as a call that
    // cost nothing. The class docblock promises the opposite — "an absolute ceiling derived from
    // ACTUAL prices, so the cap means money and not 'tokens, which are not money'" — but for an
    // unpriced model the spend ceiling is not merely loose, it is INERT: spentUsd stays 0.00
    // forever, exhausted() can only ever fire on maxCalls, and a caller who set maxSpendUsd
    // reasonably low gets no spend protection at all and no indication of it.
    //
    // This is COMPLETION-PLAN.md's B3 trap, arrived at from the other direction: there it was a
    // cache hit priced at zero making savings vanish as null, here it is a null cost making spend
    // vanish as zero. An unpriced model must never read as a free one.
    $provider = new ReviewerAgentTestProvider(tokensIn: 1000, tokensOut: 500);
    $agent = reviewerAgent($provider, null, 'somevendor/not-in-the-price-table', 10, 0.01);

    $agent->review('a');

    // The meter must reflect that money was spent even though the table cannot say how much.
    expect($agent->spentUsd())->toBeGreaterThan(0.0);

    // And therefore the ceiling must bite, exactly as it does for a priced model.
    $second = $agent->review('b');
    expect($second['ok'])->toBeFalse()
        ->and($second['reason'])->toContain('budget exhausted')
        ->and($provider->dispatched)->toHaveCount(1);
});

it('the LEDGER still says "unpriced" for an unpriced model, even while the meter charges it', function () {
    // The two are deliberately allowed to disagree, and this pins down which one is allowed to
    // lie. cost_usd stays null in the event so the cost report SURFACES the unknown instead of
    // printing a confident $0.00 — the doctrine the whole project is built on. Only the internal
    // meter substitutes a conservative figure, because a budget that assumes free is not a budget.
    // If a future change makes the ledger report 0.00 here, this test is the thing that catches it.
    $log = new EventLog(Database::connect(':memory:'));
    $agent = reviewerAgent(new ReviewerAgentTestProvider, $log, 'somevendor/not-in-the-price-table', 5, 5.0);

    $agent->review('a');

    $calls = array_values(array_filter($log->all(), fn (array $e) => $e['type'] === 'tier_call'));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['payload']['cost_usd'])->toBeNull()
        // ...while a priced model in the same position books a real number.
        ->and($calls[0]['payload']['hypothetical_usd'])->toBeGreaterThan(0.0);
});

it('books to its OWN tier, and records the model that actually served the request', function () {
    // The defect this loop's own review found in Loop::turn, where the tier was hardcoded in both
    // the routing call and the event, collapsed the ledger's per-tier table to a single row no
    // matter what ran. The reviewer is a fourth tier and has to appear as one.
    //
    // It is also priced by the SERVED model, so an alias or a fallback cannot mis-book the spend.
    $log = new EventLog(Database::connect(':memory:'));
    $provider = new ReviewerAgentTestProvider(
        tokensIn: 1000,
        tokensOut: 500,
        servedModel: 'anthropic/claude-opus-5', // 10x the price of what was requested
    );

    reviewerAgent($provider, $log, 'anthropic/claude-sonnet-5', 5, 5.0)->review('a');

    $calls = array_values(array_filter($log->all(), fn (array $e) => $e['type'] === 'tier_call'));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['payload']['tier'])->toBe('reviewer')
        ->and($calls[0]['payload']['model'])->toBe('anthropic/claude-opus-5')
        ->and($calls[0]['payload']['requested_model'])->toBe('anthropic/claude-sonnet-5')
        // Priced at the SERVED model's rates: opus is $5/$25, so 1000 in + 500 out is
        // 1000/1e6*5 + 500/1e6*25 = $0.0175. Booking at the requested sonnet rates would have
        // recorded $0.007 — a 2.5x undercount on every fallback, silently.
        ->and($calls[0]['payload']['cost_usd'])->toBe(0.0175);
});

it('hands the model no tools at all — read-only by construction, not by convention', function () {
    // The docblock claims the worst case of a bad review is a wasted call rather than a changed
    // file. That is only true if the reviewer structurally cannot be given any, so the request it
    // builds is asserted rather than the comment.
    $provider = new ReviewerAgentTestProvider;
    reviewerAgent($provider, null, 'anthropic/claude-sonnet-5', 5, 5.0)->review('a');

    expect($provider->dispatched[0]['messages'])->toHaveCount(1)
        ->and($provider->dispatched[0]['messages'][0]['role'])->toBe('user')
        ->and($provider->dispatched[0]['messages'][0]['content'])->toContain('a')
        // No tool definitions travel with the request at all.
        ->and($provider->dispatched[0]['messages'][0])->not->toHaveKey('tools');
});

it('reports exhaustion instead of throwing, so a cut-short review does not kill the turn', function () {
    // A failure mode that destroys the surrounding work is not a failure mode. reviewAll's
    // subject list is walked, and a delegation that cannot finish reports why rather than
    // unwinding the caller's turn.
    $provider = new ReviewerAgentTestProvider;
    $agent = reviewerAgent($provider, null, 'anthropic/claude-sonnet-5', 1);

    $first = $agent->reviewAll(['only one fits']);
    $second = $agent->reviewAll(['and this one cannot start']);

    expect($first['reviews'])->toHaveCount(1)
        ->and($first['stopped_early'])->toBeFalse()
        ->and($second['reviews'])->toBeEmpty()
        ->and($second['reason'])->toContain('budget exhausted')
        ->and($provider->dispatched)->toHaveCount(1);
});
