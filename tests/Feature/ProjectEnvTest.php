<?php

use App\Approval\Gate;
use App\Storage\ProjectEnv;
use App\Storage\SessionStore;

/** Runs $body with cwd set to a throwaway project holding the given files. */
function inProject(array $files, Closure $body): void
{
    $root = sys_get_temp_dir().'/paider-env-'.uniqid();
    mkdir($root.'/.paider', recursive: true);

    foreach ($files as $relative => $contents) {
        file_put_contents($root.'/'.$relative, $contents);
    }

    $previous = getcwd();
    chdir($root);
    ProjectEnv::forget();

    try {
        $body($root);
    } finally {
        chdir($previous);
        ProjectEnv::forget();
    }
}

test('a .env in the project being worked in is read', function () {
    // The gap this class exists to close: Laravel Zero boots with basePath set to Paider's
    // INSTALL directory, so its own dotenv never looks at the user's project. Measured before
    // writing this — a setting in a project .env resolved to NULL.
    //
    // Demonstrated with a CONVENIENCE setting on purpose. This test originally used PAIDER_YOLO
    // and asserted the gate auto-approved, which was asserting a vulnerability: a cloned repo
    // ships its own .paider/.env, so anything reachable here is controlled by the repository.
    // See ProjectSelfAuthorizationTest — authority is read from the real environment only.
    inProject(['.env' => "PAIDER_RESUME_MESSAGES=7\n"], function () {
        expect(ProjectEnv::get('PAIDER_RESUME_MESSAGES'))->toBe('7');
        expect(SessionStore::resumeWindow())->toBe(7);
    });
});

test('a project file cannot reach an authority setting even though the file is read', function () {
    inProject(['.env' => "PAIDER_YOLO=1\n"], function () {
        // ProjectEnv still parses it — the file IS read, that is the feature.
        expect(ProjectEnv::get('PAIDER_YOLO'))->toBe('1');
        // But the gate does not consult this path, so the repo cannot approve itself.
        expect(Gate::forSession(false)->autoApproves())->toBeFalse();
    });
});

test('.paider/.env is read too, and outranks the project-wide .env', function () {
    inProject([
        '.env' => "PAIDER_RESUME_MESSAGES=10\n",
        '.paider/.env' => "PAIDER_RESUME_MESSAGES=99\n",
    ], function () {
        expect(ProjectEnv::get('PAIDER_RESUME_MESSAGES'))->toBe('99');
    });
});

test('a real environment variable beats any file', function () {
    inProject(['.env' => "PAIDER_YOLO=0\n"], function () {
        putenv('PAIDER_YOLO=1');

        try {
            // `PAIDER_YOLO=1 paider chat` has to win, or the flag becomes unpredictable
            // depending on a file the user may have forgotten is there.
            expect(ProjectEnv::get('PAIDER_YOLO'))->toBe('1');
        } finally {
            putenv('PAIDER_YOLO');
        }
    });
});

test('a project with no env files reads as empty rather than erroring', function () {
    inProject([], function () {
        expect(ProjectEnv::values())->toBe([]);
        expect(ProjectEnv::get('PAIDER_YOLO'))->toBeNull();
        expect(ProjectEnv::get('PAIDER_YOLO', 'fallback'))->toBe('fallback');
    });
});

test('a malformed .env falls back instead of making the tool unusable', function () {
    inProject(['.env' => "this is not = a valid\n\x00env file\n"], function () {
        expect(fn () => ProjectEnv::values())->not->toThrow(Throwable::class);
        expect(Gate::forSession(false)->autoApproves())->toBeFalse();
    });
});

test('loading never exports into the process environment, so subprocesses stay clean', function () {
    // DECISIONS.md §17 scrubbed the child environment specifically so an approved subprocess
    // could not inherit live secrets. If loading a project .env called putenv(), every value in
    // it would leak into every command ShellTool spawns and quietly undo that.
    inProject(['.env' => "PAIDER_SECRET_CANARY=leaked\n"], function () {
        ProjectEnv::values();

        expect(getenv('PAIDER_SECRET_CANARY'))->toBeFalse();
        expect($_ENV['PAIDER_SECRET_CANARY'] ?? null)->toBeNull();
        expect($_SERVER['PAIDER_SECRET_CANARY'] ?? null)->toBeNull();
    });
});

test('bool() reads the documented truthy set and fails closed on anything else', function () {
    inProject(['.env' => "T1=1\nT2=true\nT3=on\nT4=YES\nF1=0\nF2=false\nF3=banana\n"], function () {
        foreach (['T1', 'T2', 'T3', 'T4'] as $key) {
            expect(ProjectEnv::bool($key))->toBeTrue();
        }

        foreach (['F1', 'F2', 'F3'] as $key) {
            expect(ProjectEnv::bool($key))->toBeFalse();
        }

        expect(ProjectEnv::bool('ABSENT', true))->toBeTrue();
    });
});
