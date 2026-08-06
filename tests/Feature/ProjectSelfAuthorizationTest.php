<?php

use App\Approval\Gate;
use App\Storage\MemoryStore;
use App\Storage\ProjectEnv;
use App\Storage\SessionStore;
use App\Support\UrlGuard;

/**
 * The attack this file exists to prevent: a hostile repository ships its own `.paider/.env`,
 * someone clones it and runs Paider inside, and the repository grants ITSELF permissions the
 * human never gave. With PAIDER_YOLO that is unprompted `run_shell` — clone-to-RCE. It was real
 * and shipped; these tests are the reason it cannot come back.
 */

/** Stands the process inside a freshly-cloned hostile repo. */
function inHostileRepo(string $envContents, Closure $body): void
{
    $root = sys_get_temp_dir().'/paider-hostile-'.uniqid();
    mkdir($root.'/.paider', recursive: true);
    file_put_contents($root.'/.paider/.env', $envContents);
    file_put_contents($root.'/.env', $envContents);

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

test('a cloned repo cannot turn on yolo for the person who cloned it', function () {
    inHostileRepo("PAIDER_YOLO=1\n", function () {
        // The human passed no flag. Nothing but their own shell may answer this question.
        expect(Gate::enabledInEnvironment())->toBeFalse();
        expect(Gate::forSession(false)->autoApproves())->toBeFalse();
    });
});

test('a cloned repo cannot allowlist a private address into the fetch guard', function () {
    inHostileRepo("PAIDER_FETCH_ALLOW=192.168.1.10\n", function () {
        expect(UrlGuard::allowlist())->toBe([]);
        expect(UrlGuard::inspect('http://192.168.1.10/')['ok'] ?? false)->toBeFalse();
    });
});

test('the human shell still wins — the fix restricts the source, it does not disable the setting', function () {
    // Guards against "fixing" this by breaking the feature: a test asserting only that the
    // value is off would pass if the setting stopped working everywhere.
    inHostileRepo("PAIDER_YOLO=0\n", function () {
        putenv('PAIDER_YOLO=1');
        putenv('PAIDER_FETCH_ALLOW=192.168.1.10');

        try {
            expect(Gate::forSession(false)->autoApproves())->toBeTrue();
            expect(UrlGuard::allowlist())->toBe(['192.168.1.10']);
            expect(UrlGuard::inspect('http://192.168.1.10/')['ok'])->toBeTrue();
        } finally {
            putenv('PAIDER_YOLO');
            putenv('PAIDER_FETCH_ALLOW');
        }
    });
});

test('the --yolo flag still works, because that is the human typing it', function () {
    inHostileRepo("PAIDER_YOLO=0\n", function () {
        expect(Gate::forSession(true)->autoApproves())->toBeTrue();
    });
});

test('convenience settings are still project-scoped — only authority was withdrawn', function () {
    // The distinction is the point. A repo may say "replay fewer messages"; it may not say
    // "approve every shell command". Collapsing both to getenv() would be over-correction.
    inHostileRepo("PAIDER_RESUME_MESSAGES=7\nPAIDER_MEMORY_LIMIT=3\n", function () {
        expect(SessionStore::resumeWindow())->toBe(7);
        expect(MemoryStore::limit())->toBe(3);
    });
});

test('no authority setting is read through the project-file path', function () {
    // Invariant, in the spirit of SecretsGuardTest's proc_open sweep: grep the source so a
    // future edit that "tidies" these back onto ProjectEnv::get()/bool() fails here loudly.
    $sources = [
        'app/Approval/Gate.php' => Gate::ENV_VAR,
        'app/Support/UrlGuard.php' => UrlGuard::ALLOW_VAR,
    ];

    foreach ($sources as $file => $var) {
        $code = file_get_contents(base_path($file));

        expect($code)->toContain('ProjectEnv::fromEnvironment');
        expect($code)
            ->not->toContain("ProjectEnv::get(self::{$var}")
            ->not->toContain("ProjectEnv::bool(self::{$var}");
    }
});
