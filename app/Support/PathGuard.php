<?php

namespace App\Support;

class PathGuard
{
    /**
     * $root must exist on disk; $candidate may not (e.g. a file a tool is about to create).
     * Normalizes '.'/'..' lexically in $candidate's tail — never realpath()s the candidate,
     * since the whole point is correctness before the file exists.
     */
    public static function containedIn(string $root, string $candidate): bool
    {
        $rootReal = realpath($root);

        if ($rootReal === false) {
            return false;
        }

        $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);

        if ($candidate === $rootReal) {
            $tail = '';
        } elseif (str_starts_with($candidate, $rootReal.DIRECTORY_SEPARATOR)) {
            $tail = substr($candidate, strlen($rootReal) + 1);
        } else {
            // Not even nominally under root as a path — also catches siblings that
            // merely share a string prefix, e.g. /repo-other/x against root /repo.
            return false;
        }

        $stack = [];

        foreach (explode(DIRECTORY_SEPARATOR, $tail) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack === []) {
                    // '..' tries to walk above where $root starts.
                    return false;
                }

                array_pop($stack);

                continue;
            }

            $stack[] = $segment;
        }

        $normalized = $stack === []
            ? $rootReal
            : $rootReal.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $stack);

        if ($normalized !== $rootReal && ! str_starts_with($normalized, $rootReal.DIRECTORY_SEPARATOR)) {
            return false;
        }

        // Lexical normalization above only catches '..' in the tail. It says nothing about
        // an EXISTING intermediate directory being a symlink that points outside root (e.g.
        // project/linked-dir -> /etc), which would let a contained-looking path read/write
        // straight through it. Walk up from the normalized path to the deepest segment that
        // actually exists, and realpath() just that prefix — never the possibly-nonexistent
        // leaf — to resolve any such symlink and re-check containment.
        $existing = $normalized;

        while (! file_exists($existing)) {
            $parent = dirname($existing);

            if ($parent === $existing) {
                break;
            }

            $existing = $parent;
        }

        $existingReal = realpath($existing);

        if ($existingReal === false) {
            return false;
        }

        $existingReal = rtrim($existingReal, DIRECTORY_SEPARATOR);

        return $existingReal === $rootReal || str_starts_with($existingReal, $rootReal.DIRECTORY_SEPARATOR);
    }
}
