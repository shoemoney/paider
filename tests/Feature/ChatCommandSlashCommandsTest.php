<?php

use App\Agent\Session;
use App\Commands\ChatCommand;
use App\Tools\ReadFileTool;

/**
 * handleSlashCommand() is exercised directly against a real Session — no stdin, no REPL,
 * no model calls (ChatCommand never touches a ProviderClient on this path at all).
 */
function withChatCommand(callable $callback): void
{
    $originalCwd = getcwd();
    $root = sys_get_temp_dir().'/paider-chatcmd-'.uniqid();
    mkdir($root, recursive: true);
    $root = realpath($root);
    chdir($root);

    try {
        $command = new ChatCommand;
        $session = new Session(new ReadFileTool($root), $root);

        $callback($command, $session, $root);
    } finally {
        chdir($originalCwd);
    }
}

test('/add adds a file to the session context', function () {
    withChatCommand(function (ChatCommand $command, Session $session, string $root) {
        file_put_contents($root.'/notes.txt', 'hello world');

        $handled = $command->handleSlashCommand($session, '/add notes.txt');

        expect($handled)->toBeTrue();
        expect($session->contextFiles())->toHaveKey('notes.txt');
        expect($session->contextFiles()['notes.txt']['content'])->toBe('hello world');
    });
});

test('/drop removes a file from the session context', function () {
    withChatCommand(function (ChatCommand $command, Session $session, string $root) {
        file_put_contents($root.'/notes.txt', 'hello world');
        $session->addFile('notes.txt');

        $handled = $command->handleSlashCommand($session, '/drop notes.txt');

        expect($handled)->toBeTrue();
        expect($session->contextFiles())->not->toHaveKey('notes.txt');
    });
});

test('/tier with a valid tier name sets a session override', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        $handled = $command->handleSlashCommand($session, '/tier coder some/model-x');

        expect($handled)->toBeTrue();
        expect($session->tierOverrides())->toBe(['coder' => 'some/model-x']);
    });
});

test('/tier with an invalid tier name is handled without throwing and sets nothing', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        $handled = $command->handleSlashCommand($session, '/tier not-a-real-tier some/model');

        expect($handled)->toBeTrue();
        expect($session->tierOverrides())->toBe([]);
    });
});

test('/undo on an empty stack is handled without throwing', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        $handled = $command->handleSlashCommand($session, '/undo');

        expect($handled)->toBeTrue();
        // Still empty — nothing was ever applied, and the handler didn't invent state.
        expect($session->undo())->toBe(['status' => 'empty']);
    });
});

test('/quit is recognized, handled, and flips a distinguishable sentinel', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        expect($command->shouldQuit())->toBeFalse();

        $handled = $command->handleSlashCommand($session, '/quit');

        expect($handled)->toBeTrue();
        expect($command->shouldQuit())->toBeTrue();
    });
});

test('a non-slash line is not treated as a slash command', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        $handled = $command->handleSlashCommand($session, 'please add a login form');

        expect($handled)->toBeFalse();
    });
});

test('an unrecognized slash command is not treated as handled', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        $handled = $command->handleSlashCommand($session, '/nope whatever');

        expect($handled)->toBeFalse();
    });
});

test('a bare "exit" quits instead of being sent to the model and billed', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        // The most natural first message, and the one the project's own TUI capture shows a user
        // typing. Before this, anything without a leading '/' fell through to $loop->turn() and
        // produced a billed tier_call, then an answer explaining that it could not exit.
        foreach (['exit', 'EXIT', ' quit ', 'q', 'quit'] as $word) {
            $handled = $command->handleSlashCommand($session, $word);

            expect($handled)->toBeTrue('expected "'.$word.'" to be handled')
                ->and($command->shouldQuit())->toBeTrue('expected "'.$word.'" to quit');

            // shouldQuit() is one-way by design, so each word needs a fresh command to test.
            $command = new ChatCommand;
        }
    });
});

test('ordinary prose is still a turn, not a quit', function () {
    withChatCommand(function (ChatCommand $command, Session $session) {
        // The fix must not swallow real input: only those exact words quit.
        foreach (['exit the loop', 'quit gracefully', 'quitting the job', 'q and then some', 'q.'] as $line) {
            expect($command->handleSlashCommand($session, $line))->toBeFalse(
                'expected "'.$line.'" to fall through as a normal turn'
            );
        }

        expect($command->shouldQuit())->toBeFalse();
    });
});
