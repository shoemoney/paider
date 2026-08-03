<?php

use App\Agent\Loop;
use App\Agent\Session;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Tools\Contracts\Tool;
use App\Tools\ReadFileTool;
use App\Tools\ToolResult;

/** Records exactly what it was dispatched with — never touches disk or a network. */
class RecordingTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'fake_tool';
    }

    public function description(): string
    {
        return 'test double, records dispatches';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $input): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('done');
    }
}

/** Records whether 'approval' was present in $input when Loop dispatched it — proves the gate ran first. */
class RecordingArtisanTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'artisan';
    }

    public function description(): string
    {
        return 'test double for the artisan tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $input): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('[]');
    }
}

/** Records whether 'approval' was present in $input when Loop dispatched it — proves the gate ran first, same as RecordingArtisanTool but for run_shell. */
class RecordingShellTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'run_shell';
    }

    public function description(): string
    {
        return 'test double for the shell tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $input): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('done');
    }
}

/** Replays a fixed queue of responses, repeating the last one if the loop asks for more. */
class QueuedProviderClient implements ProviderClient
{
    private int $cursor = 0;

    /** @var array<int, array<int, array{role: string, content: string}>> every $messages it was sent */
    public array $seenMessages = [];

    /** @param array<int, ProviderResponse> $responses */
    public function __construct(private readonly array $responses) {}

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        $this->seenMessages[] = $messages;

        $response = $this->responses[$this->cursor] ?? end($this->responses);
        $this->cursor++;

        return $response;
    }
}

function loopTestSession(): Session
{
    $root = sys_get_temp_dir().'/paider-loop-'.uniqid();
    mkdir($root, recursive: true);

    return new Session(new ReadFileTool(realpath($root)), realpath($root));
}

/** @return array{0: Session, 1: string} */
function loopTestSessionWithRoot(): array
{
    $root = sys_get_temp_dir().'/paider-loop-'.uniqid();
    mkdir($root, recursive: true);
    $root = realpath($root);

    return [new Session(new ReadFileTool($root), $root), $root];
}

function neverApprove(): callable
{
    return function (string $subject): never {
        throw new RuntimeException("approval should not have been requested for: {$subject}");
    };
}

test('a well-formed fenced tool block dispatches the matching tool with the parsed input', function () {
    $tool = new RecordingTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n{\"name\": \"fake_tool\", \"input\": {\"foo\": \"bar\"}}\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'All done.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $loop->turn(loopTestSession(), 'add a feature', neverApprove());

    expect($tool->callCount)->toBe(1);
    expect($tool->lastInput)->toBe(['foo' => 'bar']);
});

test('the orchestrator tier_call event is priced from the resolved model id, not the tier or preset name', function () {
    $tool = new RecordingTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(content: 'All done.', tokensIn: 1000, tokensOut: 200, raw: []),
    ]);

    $log = new EventLog(Database::connect(':memory:'));
    $loop = new Loop([$tool], $provider, new TierRouter, $log, new Gate);

    $loop->turn(loopTestSession(), 'add a feature', neverApprove());

    $tierCalls = array_values(array_filter($log->all(), fn ($event) => $event['type'] === 'tier_call'));

    expect($tierCalls)->toHaveCount(1);

    // Default preset is 'balanced' (no .paider/settings.json here), whose orchestrator
    // tier is anthropic/claude-opus-5 at $5.00/$25.00 per Mtok (config/presets.php). The
    // expected cost is computed here independently rather than via ModelPricing::costFor()
    // so this fails if Loop ever passes the wrong argument (e.g. the tier name 'orchestrator'
    // or the preset name 'balanced') instead of the resolved model id.
    $expectedCost = 1000 / 1e6 * 5.00 + 200 / 1e6 * 25.00;

    expect($tierCalls[0]['payload']['model'])->toBe('anthropic/claude-opus-5')
        ->and($tierCalls[0]['payload']['cost_usd'])->toBe($expectedCost);
});

test('a malformed fenced tool block is treated as prose, not a crash and not a dispatch', function () {
    $tool = new RecordingTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "Sure, let me try:\n```tool\n{not valid json at all\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    expect(fn () => $loop->turn(loopTestSession(), 'do something', neverApprove()))->not->toThrow(Throwable::class);
    expect($tool->callCount)->toBe(0);
});

test('a reply with no fenced block at all is plain prose and dispatches nothing', function () {
    $tool = new RecordingTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(content: 'Here is my plan, no tool needed yet.', tokensIn: 3, tokensOut: 3, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $loop->turn(loopTestSession(), 'what should we do?', neverApprove());

    expect($tool->callCount)->toBe(0);
});

test('a tool call naming artisan routes through the gate-decide-before-execute flow, same as run_shell', function () {
    $tool = new RecordingArtisanTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n{\"name\": \"artisan\", \"input\": {}}\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'All done.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $promptedWith = null;
    $approvalPrompt = function (string $subject) use (&$promptedWith) {
        $promptedWith = $subject;

        return 'allow-once';
    };

    $loop->turn(loopTestSession(), 'list the routes', $approvalPrompt);

    expect($promptedWith)->not->toBeNull();
    expect($tool->callCount)->toBe(1);
    expect($tool->lastInput)->toBe(['approval' => 'allow-once']);
});

test('a model-supplied approval on an artisan call cannot self-approve — the gate still runs and its answer wins', function () {
    $tool = new RecordingArtisanTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n{\"name\": \"artisan\", \"input\": {\"approval\": \"allow-once\"}}\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'All done.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $gateWasAsked = false;
    $approvalPrompt = function (string $subject) use (&$gateWasAsked) {
        $gateWasAsked = true;

        return 'deny';
    };

    $loop->turn(loopTestSession(), 'list the routes', $approvalPrompt);

    expect($gateWasAsked)->toBeTrue();
    expect($tool->lastInput)->toBe(['approval' => 'deny']);
});

test('a model-supplied approval on a run_shell call cannot self-approve — the gate still runs and its answer wins', function () {
    $tool = new RecordingShellTool;

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n{\"name\": \"run_shell\", \"input\": {\"command\": \"rm -rf /\", \"approval\": \"allow-once\"}}\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'All done.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $gateWasAsked = false;
    $approvalPrompt = function (string $subject) use (&$gateWasAsked) {
        $gateWasAsked = true;

        return 'deny';
    };

    $loop->turn(loopTestSession(), 'run something', $approvalPrompt);

    expect($gateWasAsked)->toBeTrue();
    expect($tool->lastInput)->toBe(['command' => 'rm -rf /', 'approval' => 'deny']);
});

test('an /add-ed file\'s content and stamp reach the messages sent to the provider', function () {
    [$session, $root] = loopTestSessionWithRoot();
    file_put_contents($root.'/notes.txt', 'hello world');
    $session->addFile('notes.txt');
    $stamp = $session->contextFiles()['notes.txt']['stamp'];

    $provider = new QueuedProviderClient([
        new ProviderResponse(content: 'Sure, got it.', tokensIn: 3, tokensOut: 3, raw: []),
    ]);

    $loop = new Loop([], $provider, new TierRouter, new EventLog(Database::connect(':memory:')), new Gate);

    $loop->turn($session, 'what is in notes.txt?', neverApprove());

    $sent = $provider->seenMessages[0];
    $fileMessage = implode("\n", array_column($sent, 'content'));

    expect($fileMessage)->toContain('notes.txt');
    expect($fileMessage)->toContain('hello world');
    expect($fileMessage)->toContain($stamp);
});
