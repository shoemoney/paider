<?php

/*
 * Per-operation tier routing (v0.2).
 *
 * The defect this file exists to prevent: Loop::turn() hardcoded resolve('plan') AND hardcoded
 * 'tier' => 'orchestrator' in the event it wrote. The coder and research tiers were therefore
 * unreachable from the agent, and the cost ledger's per-tier table — the project's flagship
 * output — could only ever show one row. Every test here is written to FAIL if either literal
 * comes back, because "the routing code exists" is not the claim; "a coder-tier call is actually
 * booked as coder" is.
 */

use App\Agent\Loop;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Tools\Contracts\Tool;
use App\Tools\ToolResult;

/** Records the model each send() was asked for, and replies with a scripted script. */
final class RoutingProbeProvider implements ProviderClient
{
    /** @var array<int, string> */
    public array $requestedModels = [];

    /** @var array<int, string> */
    private array $script;

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        $this->requestedModels[] = $model;
        $content = array_shift($this->script) ?? 'done';

        return new ProviderResponse($content, 10, 5, []);
    }
}

final class RoutingProbeTool implements Tool
{
    public function __construct(private readonly string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'probe';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function execute(array $input, bool $approved = false): ToolResult
    {
        return ToolResult::ok('probe ok');
    }
}

/** Run one turn against a scripted provider and return the event log. */
function routingTurn(array $script, array $toolNames = []): EventLog
{
    $log = new EventLog(Database::connect(':memory:'));
    $tools = [];

    foreach ($toolNames as $name) {
        $tools[] = new RoutingProbeTool($name);
    }

    $loop = new Loop(
        $tools,
        new RoutingProbeProvider($script),
        new TierRouter,
        $log,
        Gate::forSession(true),
    );

    $loop->turn(loopTestSession(), 'go', fn (string $what) => 'allow-once');

    return $log;
}

/** The tiers a log booked calls against, in order. */
function routingTiers(EventLog $log): array
{
    $tiers = [];

    foreach ($log->all() as $event) {
        if ($event['type'] === 'tier_call') {
            $tiers[] = $event['payload']['tier'];
        }
    }

    return $tiers;
}

it('books the FIRST call of a turn as orchestrator', function () {
    $log = routingTurn(['no tool call, just prose']);

    expect(routingTiers($log))->toBe(['orchestrator']);
});

it('routes a turn that WRITES to the coder tier, not orchestrator', function () {
    // Turn 1: plan, proposes write_file. Turn 2: what follows a write is 'edit' -> coder.
    $log = routingTurn([
        "```tool\n{\"name\":\"write_file\",\"input\":{}}\n```",
        'prose, done',
    ], ['write_file']);

    $tiers = routingTiers($log);

    // The whole point: the second call is NOT orchestrator.
    expect($tiers)->toBe(['orchestrator', 'coder'])
        ->and($tiers)->not->toBe(['orchestrator', 'orchestrator']);
});

it('routes a turn that only READS to the research tier', function () {
    $log = routingTurn([
        "```tool\n{\"name\":\"read_file\",\"input\":{}}\n```",
        'prose, done',
    ], ['read_file']);

    expect(routingTiers($log))->toBe(['orchestrator', 'research']);
});

it('the ledger now shows more than one tier, which it never could before', function () {
    $log = routingTurn([
        "```tool\n{\"name\":\"read_file\",\"input\":{}}\n```",
        'prose, done',
    ], ['read_file']);

    $summary = (new CostLedger($log))->summary();
    $tiers = array_diff_key($summary, ['session' => null]);

    // Under the hardcoded literal this array had exactly one key, forever. A reviewer reading
    // `paider cost` would see a single orchestrator row and reasonably conclude the other
    // tiers were decorative.
    expect(array_keys($tiers))->toContain('orchestrator', 'research')
        ->and(count($tiers))->toBeGreaterThan(1);
});

it('books a cache hit against the tier that earned it, not a literal', function () {
    // Same script twice against one log: the second turn hits the in-process response cache.
    $log = new EventLog(Database::connect(':memory:'));
    $probe = new RoutingProbeProvider([
        "```tool\n{\"name\":\"read_file\",\"input\":{}}\n```",
        'prose, done',
        "```tool\n{\"name\":\"read_file\",\"input\":{}}\n```",
        'prose, done',
    ]);

    $loop = new Loop(
        [new RoutingProbeTool('read_file')],
        $probe,
        new TierRouter,
        $log,
        Gate::forSession(true),
    );

    $loop->turn(loopTestSession(), 'go', fn (string $w) => 'allow-once');
    $loop->turn(loopTestSession(), 'go', fn (string $w) => 'allow-once');

    $hits = [];
    foreach ($log->all() as $event) {
        if ($event['type'] === 'cache_hit') {
            $hits[] = $event['payload']['tier'];
        }
    }

    // A hit on the FIRST call of a turn legitimately books orchestrator — that call really was
    // orchestrator. What must never happen is a hit on the SECOND call (the research-tier one)
    // booked to orchestrator, which is exactly the literal this change removed.
    expect($hits)->not->toBeEmpty();

    $researchHits = array_filter($hits, fn (string $t) => $t !== 'orchestrator');
    expect($researchHits)->not->toBeEmpty(
        'expected at least one cache hit booked to a non-orchestrator tier, got: '.implode(',', $hits)
    );
});

it('an unknown tool name falls back to the cheap tier rather than escalating', function () {
    $log = routingTurn([
        "```tool\n{\"name\":\"brand_new_tool_from_the_future\",\"input\":{}}\n```",
        'prose, done',
    ], ['brand_new_tool_from_the_future']);

    // Total-and-conservative: a tool added later must not silently start spending a premium
    // model on every call. The safe failure direction is under-spend, not over-spend.
    expect(routingTiers($log))->toBe(['orchestrator', 'research']);
});

it('respects a session tier override', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $session = loopTestSession();
    $session->setTierOverride('orchestrator', 'test/pinned-model');

    $probe = new RoutingProbeProvider(['prose, done']);
    $loop = new Loop(
        [],
        $probe,
        new TierRouter,
        $log,
        Gate::forSession(true),
    );

    $loop->turn($session, 'go', fn (string $w) => 'allow-once');

    expect($probe->requestedModels[0])->toBe('test/pinned-model');
});
