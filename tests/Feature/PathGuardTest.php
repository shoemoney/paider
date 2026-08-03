<?php

use App\Support\PathGuard;

function pathGuardRoot(): string
{
    static $root = null;

    if ($root === null) {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-pathguard-'.uniqid();
        mkdir($root.DIRECTORY_SEPARATOR.'src', recursive: true);
        $root = realpath($root);
    }

    return $root;
}

test('rejects the non-existent-tail dot-dot escape', function () {
    $root = pathGuardRoot();

    expect(PathGuard::containedIn($root, $root.'/src/../../etc/passwd'))->toBeFalse();
});

test('accepts a candidate that does not exist on disk yet', function () {
    $root = pathGuardRoot();

    expect(PathGuard::containedIn($root, $root.'/src/new-file.php'))->toBeTrue();
});

test('accepts a candidate equal to root', function () {
    $root = pathGuardRoot();

    expect(PathGuard::containedIn($root, $root))->toBeTrue();
});

test('rejects a sibling directory sharing only a string prefix', function () {
    $root = pathGuardRoot();
    $sibling = $root.'-other/x';

    expect(PathGuard::containedIn($root, $sibling))->toBeFalse();
});

test('rejects an unresolvable root', function () {
    expect(PathGuard::containedIn(pathGuardRoot().'/does-not-exist', pathGuardRoot().'/x'))->toBeFalse();
});
