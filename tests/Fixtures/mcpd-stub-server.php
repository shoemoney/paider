<?php

/**
 * Minimal HTTP stub of the mcpd discovery API, for the transport-composition test.
 *
 * Prints the bound port on stdout as its first line so the parent knows where to point
 * PAIDER_MCPD_URL — a fixed port would collide with a real daemon and make a parallel run
 * flaky for reasons that have nothing to do with the code under test.
 *
 * Serves exactly the two requests McpdClient::tools() makes during discovery, then exits.
 * Not a mock library: a real socket, because the thing being tested is that the env var
 * reaches a real HTTP client in a real subprocess.
 */
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr($name, strrpos($name, ':') + 1);

fwrite(STDOUT, $port."\n");
fflush(STDOUT);

$serve = static function ($connection, string $body): void {
    $request = '';
    // Read until the headers end. One request per connection, so this never has to loop.
    while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
        $chunk = fread($connection, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }

    fwrite($connection,
        "HTTP/1.1 200 OK\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "Connection: close\r\n\r\n".$body
    );
    fclose($connection);
};

for ($i = 0; $i < 2; $i++) {
    $connection = @stream_socket_accept($server, 10);

    if ($connection === false) {
        break;
    }

    // First call: the server list. Second: that server's tool list.
    $serve($connection, $i === 0
        ? '["time"]'
        : '{"tools":[{"name":"now","description":"Time","inputSchema":{"type":"object"}}]}');
}

fclose($server);
