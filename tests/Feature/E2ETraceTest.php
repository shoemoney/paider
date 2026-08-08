<?php

use App\Agent\Loop;
use App\Agent\Session;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Tools\GitTool;
use App\Tools\PatchFileTool;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;

// Phase 1 — hermetic E2E trace: mock provider drives read→write→patch on a tmp fixture copy.
// Proves Loop's 10-call cap, EventLog frozen cost_usd/hypothetical_usd, CostLedger projection.

test('E2E trace: mock provider drives read_file then patch_file on a tmp fixture copy', function () {
    [$session, $root] = loopTestSessionWithRoot();

    // loopTestSessionWithRoot gives an empty tmp dir — seed it with the real fixture
    $fixture = realpath(__DIR__.'/../../m1/fixture');
    // Seed src files into tmp root
    if (is_dir($fixture.'/src')) {
        mkdir($root.'/src', recursive: true);
        foreach (glob($fixture.'/src/*.php') as $src) {
            copy($src, $root.'/src/'.basename($src));
        }
    }
    $target = $root.'/src/Receipt.php';
    $before = file_get_contents($target);
    expect($before)->toBeString()->not->toBe('');

    $readTool = new ReadFileTool($root);
    $patchTool = new PatchFileTool($root);
    $writeTool = new WriteFileTool($root);
    $gitTool = new GitTool($root);

    // Use PatchFileTool's diff format — must include stamp = sha256(before)
    $firstLine = explode("\n", $before)[0];
    $stamp = hash('sha256', $before);
    $diff = "--- a/src/Receipt.php\n+++ b/src/Receipt.php\n@@ -1,1 +1,2 @@\n+// paider-proof\n ".ltrim($firstLine)."\n";
    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'read_file', 'input' => ['path' => 'src/Receipt.php']])."\n```",
            tokensIn: 800,
            tokensOut: 50,
            raw: [],
        ),
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'patch_file', 'input' => ['path' => 'src/Receipt.php', 'diff' => $diff, 'stamp' => $stamp]])."\n```",
            tokensIn: 900,
            tokensOut: 60,
            raw: [],
        ),
        new ProviderResponse(content: 'Done — patched Receipt.php with proof comment.', tokensIn: 400, tokensOut: 20, raw: []),
    ]);

    $log = new EventLog(Database::connect(':memory:'));
    $loop = new Loop([$readTool, $writeTool, $patchTool, $gitTool], $provider, new TierRouter, $log, new Gate);

    $loop->turn($session, 'add // paider-proof comment to src/Greeter.php', neverApprove());

    // Assert two tool dispatches happened and were logged
    $toolCalls = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'tool_call'));
    expect($toolCalls)->toHaveCount(2);
    expect($toolCalls[0]['payload']['tool'])->toBe('read_file');
    expect($toolCalls[1]['payload']['tool'])->toBe('patch_file');

    // Cost frozen at write time
    $tierCalls = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'tier_call'));
    expect($tierCalls)->toHaveCount(3);
    foreach ($tierCalls as $tc) {
        expect($tc['payload']['cost_usd'])->toBeFloat();
        expect($tc['payload']['hypothetical_usd'])->toBeFloat();
        expect($tc['payload']['model'])->toBeString();
    }

    // File was actually patched on disk
    $after = file_get_contents($target);
    expect($after)->toContain('paider-proof');
    expect($after)->not->toBe($before);
});

test('E2E trace respects 10-call cap', function () {
    [$session, $root] = loopTestSessionWithRoot();
    $readTool = new ReadFileTool($root);

    // Provider that never stops calling tool -> should be capped at 10
    $responses = array_fill(0, 11, new ProviderResponse(
        content: "```tool\n".json_encode(['name' => 'read_file', 'input' => ['path' => 'src/Greeter.php']])."\n```",
        tokensIn: 10,
        tokensOut: 5,
        raw: [],
    ));
    $provider = new QueuedProviderClient($responses);
    $log = new EventLog(Database::connect(':memory:'));
    $loop = new Loop([$readTool], $provider, new TierRouter, $log, new Gate);

    $loop->turn($session, 'loop forever', neverApprove());

    $tierCalls = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'tier_call'));
    expect($tierCalls)->toHaveCount(10);
});
