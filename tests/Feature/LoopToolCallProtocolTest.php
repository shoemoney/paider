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

/** Replays a fixed queue of responses, repeating the last one if the loop asks for more. */
class QueuedProviderClient implements ProviderClient
{
    private int $cursor = 0;

    /** @param array<int, ProviderResponse> $responses */
    public function __construct(private readonly array $responses) {}

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
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
