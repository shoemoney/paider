<?php

use App\Storage\Database;
use App\Storage\EventLog;
use Ramsey\Uuid\Uuid;

it('appends events and returns them via all() in insertion order with valid uuid7 ids', function () {
    $log = new EventLog(Database::connect(':memory:'));

    $id1 = $log->append('tier_call', ['tier' => 'coder', 'n' => 1]);
    $id2 = $log->append('tier_call', ['tier' => 'coder', 'n' => 2]);
    $id3 = $log->append('note', ['text' => 'hello']);

    $all = $log->all();

    expect($all)->toHaveCount(3)
        ->and($all[0]['id'])->toBe($id1)
        ->and($all[1]['id'])->toBe($id2)
        ->and($all[2]['id'])->toBe($id3)
        ->and($all[0]['type'])->toBe('tier_call')
        ->and($all[0]['payload'])->toBe(['tier' => 'coder', 'n' => 1])
        ->and($all[2]['type'])->toBe('note')
        ->and($all[2]['payload'])->toBe(['text' => 'hello']);

    foreach ([$id1, $id2, $id3] as $id) {
        expect(Uuid::isValid($id))->toBeTrue();
        expect(Uuid::fromString($id)->getVersion())->toBe(7);
    }
});

it('sets created_at on every appended event', function () {
    $log = new EventLog(Database::connect(':memory:'));

    $log->append('tier_call', ['tier' => 'fast']);

    expect($log->all()[0]['created_at'])->not->toBeEmpty();
});

it('declares no update or delete shaped public method — append-only is structural', function () {
    $methods = (new ReflectionClass(EventLog::class))->getMethods(ReflectionMethod::IS_PUBLIC);
    $forbidden = ['update', 'delete', 'remove', 'edit', 'patch', 'truncate', 'clear'];

    foreach ($methods as $method) {
        $name = strtolower($method->getName());

        foreach ($forbidden as $word) {
            expect($name)->not->toContain($word);
        }
    }
});

it('refuses to append an event whose payload cannot be encoded, rather than writing it empty', function () {
    $log = new EventLog(Database::connect(':memory:'));

    // Lone continuation byte — invalid UTF-8, exactly what raw file contents or shell output
    // can carry. Leniently encoded this returns false and silently stores an empty payload.
    expect(fn () => $log->append('tool_result', ['stdout' => "\xB1\x31"]))
        ->toThrow(JsonException::class);

    expect($log->all())->toBeEmpty();
});
