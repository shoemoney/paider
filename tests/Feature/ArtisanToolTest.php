<?php

use App\Tools\ArtisanTool;

function artisanToolRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-artisantool-'.uniqid();
    mkdir($root, recursive: true);

    return realpath($root);
}

function fakeArtisan(string $root, string $body): void
{
    file_put_contents($root.'/artisan', "#!/usr/bin/env php\n<?php\n{$body}\n");
    chmod($root.'/artisan', 0755);
}

test('inputSchema does not advertise an approval field to the model', function () {
    $tool = new ArtisanTool(artisanToolRoot());

    expect($tool->inputSchema()['properties'])->not->toHaveKey('approval');
});

test('missing approval key fails closed and runs nothing', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'file_put_contents(__DIR__."/marker", "ran");');
    $tool = new ArtisanTool($root);

    $result = $tool->execute([]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'])->toBeTrue();
    expect(file_exists($root.'/marker'))->toBeFalse();
});

test('an out-of-enum approval value fails closed and runs nothing', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'file_put_contents(__DIR__."/marker", "ran");');
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'nope']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'])->toBeTrue();
    expect(file_exists($root.'/marker'))->toBeFalse();
});

test('deny fails and runs nothing', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'file_put_contents(__DIR__."/marker", "ran");');
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'deny']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('denied');
    expect(file_exists($root.'/marker'))->toBeFalse();
});

test('allow-once runs route:list and maps rows to route/method/action', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, <<<'PHP'
        if ($argv[1] === 'route:list' && $argv[2] === '--json') {
            echo json_encode([
                ['uri' => 'api/users', 'method' => 'GET', 'action' => 'UserController@index'],
                ['uri' => 'api/users', 'method' => 'POST', 'action' => 'UserController@store'],
            ]);
            exit(0);
        }
        exit(1);
        PHP);
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'allow-once']);

    expect($result->ok)->toBeTrue();
    $rows = json_decode($result->output, true);
    expect($rows)->toBe([
        ['route' => 'api/users', 'method' => 'GET', 'action' => 'UserController@index'],
        ['route' => 'api/users', 'method' => 'POST', 'action' => 'UserController@store'],
    ]);
});

test('allow-session also runs the command', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'echo json_encode([]); exit(0);');
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'allow-session']);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('[]');
});

test('a nonzero exit fails with stderr, not a thrown exception', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'fwrite(STDERR, "boom"); exit(1);');
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'allow-once']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('boom');
});

test('garbage JSON on stdout fails without throwing', function () {
    $root = artisanToolRoot();
    fakeArtisan($root, 'echo "not json at all"; exit(0);');
    $tool = new ArtisanTool($root);

    $result = $tool->execute(['approval' => 'allow-once']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('could not parse route:list output');
});
