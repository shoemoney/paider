<?php

use App\Support\SecretsGuard;

test('deny-list matches common secret filenames', function () {
    expect(SecretsGuard::isSensitive('/home/user/project/.env'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/.ssh/id_rsa'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/certs/foo.pem'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/project/.env.production'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/keys/server.key'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/certs/bundle.p12'))->toBeTrue()
        ->and(SecretsGuard::isSensitive('/home/user/.aws/credentials'))->toBeTrue();
});

test('an ordinary php path is not sensitive', function () {
    expect(SecretsGuard::isSensitive(__DIR__.'/SecretsGuardTest.php'))->toBeFalse();
});

test('a git-ignored path under vendor/ is flagged via git check-ignore', function () {
    $vendorPath = realpath(__DIR__.'/../../vendor/autoload.php');

    expect($vendorPath)->not->toBeFalse();
    expect(SecretsGuard::isSensitive($vendorPath))->toBeTrue();
});

test('every proc_open in app/ passes a scrubbed ShellEnv, with no exceptions', function () {
    // DECISIONS.md §15's architectural caveat: a proc_open call site that skips
    // ShellEnv::build() reopens the provider-key leak §17 closed. Three call sites exist
    // (ShellTool, GitTool, SecretsGuard) and the third was inheriting the full parent
    // environment until 2026-08-05. Guarding the INVARIANT rather than the three known
    // sites is what makes a fourth impossible to add quietly — reviewing for this by eye
    // is exactly how the third survived.
    //
    // Tokenised, not grepped: four files mention proc_open in prose, and a naive string
    // search reports all of them. ext-tokenizer is already a hard requirement, so the
    // parser that knows a comment from a call is free.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all(file_get_contents($file->getPathname()));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'proc_open') {
                continue;
            }

            // Walk forward to this call's closing paren, tracking depth, and look for
            // ShellEnv::build() inside its own argument list only.
            $depth = 0;
            $args = '';

            for ($j = $i + 1; $j < count($tokens); $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $args .= $text;

                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            if (! str_contains($args, 'ShellEnv::build')) {
                $offenders[] = basename($file->getPathname()).' line '.$token[2];
            }
        }
    }

    expect($offenders)->toBe([], 'proc_open without ShellEnv::build(): '.implode(', ', $offenders));
});
