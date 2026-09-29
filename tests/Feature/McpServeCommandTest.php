<?php

it('serves MCP file tools over stdio with explicit write opt-in', function (bool $writes) {
    $root = sys_get_temp_dir().'/paider-mcp-'.bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root.'/hello.txt', 'hello MCP');
    file_put_contents($root.'/.env', 'SECRET=hidden');
    $command = [PHP_BINARY, base_path('paider'), 'mcp:serve', '--root='.$root];
    if ($writes) {
        $command[] = '--allow-writes';
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 5);
    $rpc = function (int $id, string $method, array $params) use ($pipes): array {
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params])."\n");
        fflush($pipes[0]);
        $line = fgets($pipes[1]);
        expect($line)->not->toBeFalse();

        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    };
    try {
        $init = $rpc(1, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        expect($init['result']['serverInfo']['name'])->toBe('paider');
        fwrite($pipes[0], '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n");
        fflush($pipes[0]);
        $list = $rpc(2, 'tools/list', []);
        expect(array_column($list['result']['tools'], 'name'))->toBe($writes ? ['read_file', 'write_file', 'patch_file'] : ['read_file']);
        $read = $rpc(3, 'tools/call', ['name' => 'read_file', 'arguments' => ['path' => 'hello.txt']]);
        expect($read['result']['content'][0]['text'])->toBe('hello MCP');
        $secret = $rpc(4, 'tools/call', ['name' => 'read_file', 'arguments' => ['path' => '.env']]);
        expect($secret['result']['isError'])->toBeTrue();
        $escape = $rpc(5, 'tools/call', ['name' => 'read_file', 'arguments' => ['path' => '../outside']]);
        expect($escape['result']['isError'])->toBeTrue();
        if ($writes) {
            $write = $rpc(6, 'tools/call', ['name' => 'write_file', 'arguments' => ['path' => 'new.txt', 'content' => 'created']]);
            expect($write['result']['isError'])->toBeFalse();
            expect(file_get_contents($root.'/new.txt'))->toBe('created');
        }
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        foreach (['hello.txt', '.env', 'new.txt'] as $file) {
            if (is_file($root.'/'.$file)) {
                unlink($root.'/'.$file);
            }
        }
        rmdir($root);
    }
})->with([false, true]);
