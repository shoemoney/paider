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

test('rejects a dangling symlink leaf (target does not exist at check time)', function () {
    $root = pathGuardRoot();
    $link = $root.'/dangling-leaf-link';

    if (! file_exists($link) && ! is_link($link)) {
        symlink($root.'/never-created-target', $link);
    }

    expect(PathGuard::containedIn($root, $link))->toBeFalse();
});

test('rejects a path through a dangling intermediate symlink', function () {
    $root = pathGuardRoot();
    $link = $root.'/dangling-dir-link';

    if (! file_exists($link) && ! is_link($link)) {
        symlink($root.'/never-created-dir', $link);
    }

    expect(PathGuard::containedIn($root, $link.'/inner.txt'))->toBeFalse();
});

test('a live (already-resolvable) symlink whose target is inside root still passes — regression guard for the dangling-symlink fix', function () {
    $root = pathGuardRoot();
    $targetDir = $root.'/src';
    $link = $root.'/live-link';

    if (! file_exists($link) && ! is_link($link)) {
        symlink($targetDir, $link);
    }

    expect(PathGuard::containedIn($root, $link.'/whatever.php'))->toBeTrue();
});

test('rejects a NUL byte anywhere in the candidate path', function () {
    $root = pathGuardRoot();

    expect(PathGuard::containedIn($root, $root."/evil\0.php"))->toBeFalse();
});
