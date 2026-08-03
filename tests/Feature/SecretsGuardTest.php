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
