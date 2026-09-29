<?php

/**
 * Hermetic stdio MCP server for tests. Plain JSON-RPC over stdin/stdout — no SDK, no network,
 * no `npx`, no binary download, no child processes. That is the point: the v0.2 plan requires a
 * fixture that runs in CI on a clean runner, and an `npx`-based fixture is a network call
 * wearing a test's clothes.
 *
 * Serves two tools:
 *   echo  — returns its `message` argument, so a test can prove a round trip end to end
 *   env   — returns the child's environment as JSON, so a test can PROVE the §17 scrub held
 *           (a stub asserting "we scrubbed it" proves nothing; this one reports ground truth)
 *
 * Runs itself as a subprocess: `php tests/Fixtures/stdio-server.php`.
 */
$tools = [
    [
        'name' => 'echo',
        'description' => 'Echo the message argument back to the caller.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ],
    ],
    [
        'name' => 'env',
        'description' => 'Return the environment visible to this server process as JSON.',
        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
    ],
];

$send = static function (array $payload): void {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES)."\n");
    fflush(STDOUT);
};

$ok = static fn (string $text) => [
    'content' => [['type' => 'text', 'text' => $text]],
    'isError' => false,
];

$err = static fn (string $text) => [
    'content' => [['type' => 'text', 'text' => $text]],
    'isError' => true,
];

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $msg = json_decode($line, true);

    if (! is_array($msg)) {
        continue;
    }

    // Notifications carry no id and expect no reply.
    $id = $msg['id'] ?? null;

    if ($id === null) {
        continue;
    }

    $method = $msg['method'] ?? '';
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($method) {
        case 'initialize':
            $send([
                'jsonrpc' => '2.0', 'id' => $id,
                'result' => [
                    'protocolVersion' => $params['protocolVersion'] ?? '2025-11-25',
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => ['name' => 'paider-fixture', 'version' => '1.0.0'],
                ],
            ]);
            break;

        case 'tools/list':
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
            break;

        case 'tools/call':
            $name = $params['name'] ?? '';
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

            if ($name === 'echo') {
                $message = $args['message'] ?? null;
                if (! is_string($message)) {
                    $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $err('echo requires a string "message"')]);
                    break;
                }
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $ok($message)]);
                break;
            }

            if ($name === 'env') {
                // Ground truth from inside the child. getenv() with no args returns the
                // environment this process actually received, which is exactly what the §17
                // scrub is supposed to constrain.
                $env = getenv();
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $ok(json_encode($env, JSON_UNESCAPED_SLASHES))]);
                break;
            }

            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $err("unknown tool {$name}")]);
            break;

        default:
            $send([
                'jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => "method not found: {$method}"],
            ]);
    }
}
