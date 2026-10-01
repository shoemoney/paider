<?php

namespace App\Agent;

use App\Providers\Contracts\ProviderClient;
use App\Storage\EventLog;
use App\Support\ModelPricing;
use RuntimeException;

/**
 * ONE reviewer subagent, with a hard budget. Not the three-role roster.
 *
 * ## Why one role and not the roster
 *
 * `PLAN.md` designs a three-role machine (orchestrator plans, coder edits, reviewer checks) with
 * a 3-round shared counter. That design is sound on paper and has never been run — and a state
 * machine nobody has executed is a guess, not an architecture. This ships the single piece whose
 * behaviour is hardest to retrofit later: a bounded delegation whose budget is ENFORCED rather
 * than merely documented.
 *
 * When the reviewer proves out, the orchestrator/coder roles slot in around this same shape.
 *
 * ## The budget is the feature
 *
 * An agent that can call a model can spend money, and an agent that loops can spend it
 * indefinitely. `MAX_TOOL_CALLS_PER_TURN` bounds a single `Loop::turn`; nothing bounded a
 * *delegation*, because nothing delegated. So the constraints here are absolute and checked at
 * every step, not advisory:
 *
 *   - `maxCalls`  — total provider calls for this delegation, hard stop
 *   - `maxSpendUsd` — an absolute ceiling derived from ACTUAL prices, so the cap means money and
 *                     not "tokens, which are not money"
 *
 * Both raise rather than warn. A budget that logs "over budget, continuing anyway" is not a
 * budget. `BudgetExceeded` is a first-class outcome the caller is expected to handle and report —
 * an agent that silently stops mid-task and reports success is worse than one that says it ran
 * out.
 *
 * ## Read-only by construction
 *
 * The reviewer receives NO tools. It cannot edit, run, or fetch, so the worst case of a bad review
 * is a wasted call, not a changed file. That is what makes it safe to ship before the rest of the
 * roster, and it is enforced by the type: `tools` is empty in `review()`.
 */
final class ReviewerAgent
{
    /**
     * Absolute ceiling on provider calls for one delegation.
     *
     * Small on purpose: a review is one judgement, not a task. Three is enough to look, judge and
     * summarise; ten is how you discover you built a loop.
     */
    public const DEFAULT_MAX_CALLS = 3;

    /**
     * Absolute spend ceiling in USD.
     *
     * `null` means "derive from maxCalls and the model's own price", which is the honest default:
     * a budget expressed in tokens is not a budget, because a token's worth depends entirely on
     * which model consumed it.
     */
    public const DEFAULT_MAX_SPEND_USD = 0.25;

    private int $callsMade = 0;

    private float $spentUsd = 0.0;

    public function __construct(
        private readonly ProviderClient $provider,
        private readonly EventLog $eventLog,
        private readonly string $model,
        private readonly int $maxCalls = self::DEFAULT_MAX_CALLS,
        private readonly float $maxSpendUsd = self::DEFAULT_MAX_SPEND_USD,
    ) {
        if ($this->maxCalls < 1) {
            throw new RuntimeException('A reviewer delegation needs at least one call');
        }

        if ($this->maxSpendUsd <= 0) {
            throw new RuntimeException('A reviewer delegation needs a positive spend ceiling');
        }
    }

    public function callsMade(): int
    {
        return $this->callsMade;
    }

    public function spentUsd(): float
    {
        return $this->spentUsd;
    }

    /** True once the next call would breach either ceiling. Checked BEFORE spending. */
    public function exhausted(): bool
    {
        return $this->callsMade >= $this->maxCalls || $this->spentUsd >= $this->maxSpendUsd;
    }

    /**
     * Ask for a review of $subject and return the model's words.
     *
     * Never throws on budget: it RETURNS the exhaustion as a structured result, because the
     * caller has to be able to say "the review was cut short" rather than have the whole turn die
     * with an exception the user cannot interpret. A failure mode that destroys the surrounding
     * work is not a failure mode, it is a bug.
     *
     * @return array{ok: bool, review: ?string, reason: ?string, calls: int, spent_usd: float}
     */
    public function review(string $subject): array
    {
        if ($this->exhausted()) {
            return $this->halted('budget exhausted before the review could start');
        }

        $messages = [[
            'role' => 'user',
            'content' => 'Review the following for correctness, then state plainly whether it is '
                ."sound. Be specific and brief.\n\n".$subject,
        ]];

        $response = $this->provider->send($messages, $this->model);

        // Counted and priced AFTER the call, because that is when the cost exists. Using the
        // REQUESTED model for pricing rather than the served one would mis-book every fallback,
        // which is the same class of error DECISIONS.md §19 exists to prevent.
        $served = $response->servedModel ?? $this->model;
        $cost = ModelPricing::costFor(
            $served,
            $response->tokensIn,
            $response->tokensOut,
            $response->cacheWrite,
            $response->cacheRead,
        );

        $this->callsMade++;
        $this->spentUsd += $cost ?? 0.0;

        // Booked to its OWN tier, not a literal 'orchestrator' — the defect this loop's own
        // review found in Loop::turn, where the tier was hardcoded in both the routing call and
        // the event, making the ledger's per-tier table a single row no matter what.
        $this->eventLog->append('tier_call', [
            'tier' => 'reviewer',
            'model' => $served,
            'requested_model' => $this->model,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'tokens_cache_write' => $response->cacheWrite,
            'tokens_cache_read' => $response->cacheRead,
            'cost_usd' => $cost,
            'hypothetical_usd' => ModelPricing::costFor(
                ModelPricing::REFERENCE_MODEL,
                $response->tokensIn,
                $response->tokensOut,
                $response->cacheWrite,
                $response->cacheRead,
            ),
        ]);

        return [
            'ok' => true,
            'review' => $response->content,
            // Set when this call BROKE the ceiling. Reported rather than hidden: a caller that
            // treats "ran one call over budget" as "finished normally" is how a cap stops being a
            // cap.
            'reason' => $this->exhausted() ? 'budget exhausted during review' : null,
            'calls' => $this->callsMade,
            'spent_usd' => $this->spentUsd,
        ];
    }

    /**
     * Run several reviews under ONE shared budget — the shape a roster will need, proven here
     * before the other roles exist.
     *
     * @param  array<int, string>  $subjects
     * @return array{reviews: array<int, string>, stopped_early: bool, calls: int, spent_usd: float, reason: ?string}
     */
    public function reviewAll(array $subjects): array
    {
        $reviews = [];
        $reason = null;

        foreach ($subjects as $subject) {
            // Checked before each item, not after — an exhausted budget must not start work it
            // cannot finish.
            if ($this->exhausted()) {
                $reason ??= 'budget exhausted';
                break;
            }

            $result = $this->review($subject);

            if (! $result['ok']) {
                $reason ??= $result['reason'];
                break;
            }

            if ($result['review'] !== null) {
                $reviews[] = $result['review'];
            }

            if ($result['reason'] !== null) {
                $reason ??= $result['reason'];
                break;
            }
        }

        return [
            'reviews' => $reviews,
            'stopped_early' => count($reviews) < count($subjects),
            'calls' => $this->callsMade,
            'spent_usd' => $this->spentUsd,
            'reason' => $reason,
        ];
    }

    /** @return array{ok: bool, review: null, reason: string, calls: int, spent_usd: float} */
    private function halted(string $reason): array
    {
        return [
            'ok' => false,
            'review' => null,
            'reason' => $reason,
            'calls' => $this->callsMade,
            'spent_usd' => $this->spentUsd,
        ];
    }
}
