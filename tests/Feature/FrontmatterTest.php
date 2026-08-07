<?php

use App\Skills\Frontmatter;

// --- accepted forms, one per shape ------------------------------------------------------------

test('plain scalars', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: does the thing
        ---
        # Body
        MD);

    expect($result)->toBe(['name' => 'my-skill', 'description' => 'does the thing', 'error' => null]);
});

test('double-quoted scalars', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: "does X, then Y"
        ---
        MD);

    expect($result['description'])->toBe('does X, then Y');
    expect($result['error'])->toBeNull();
});

test('single-quoted scalars, including a doubled embedded quote', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: 'it''s fine'
        ---
        MD);

    expect($result['description'])->toBe("it's fine");
    expect($result['error'])->toBeNull();
});

test('a quoted value that is itself a bare block-scalar character is not treated as a block opener', function () {
    // The real trap: description: ">" must parse to the one-character string ">", not swallow
    // every key after it the way a substring scan for '>' would.
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: ">"
        version: "1.0.0"
        ---
        MD);

    expect($result['description'])->toBe('>');
    expect($result['name'])->toBe('my-skill');
    expect($result['error'])->toBeNull();
});

test('folded block scalar (>) joins continuation lines with spaces', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: >
          Context scoping for writing handoffs. Use when deciding what
          story context to pass.
        ---
        MD);

    expect($result['description'])->toBe('Context scoping for writing handoffs. Use when deciding what story context to pass.');
    expect($result['error'])->toBeNull();
});

test('literal block scalar (|) joins continuation lines with newlines', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: |
          line one
          line two
        ---
        MD);

    expect($result['description'])->toBe("line one\nline two");
    expect($result['error'])->toBeNull();
});

test('block scalar chomping variants (>- |- >+ |+) are all accepted', function () {
    foreach (['>-', '|-', '>+', '|+'] as $indicator) {
        $result = Frontmatter::parse(<<<MD
            ---
            name: my-skill
            description: {$indicator}
              body text
            ---
            MD);

        expect($result['error'])->toBeNull("indicator {$indicator} should parse");
        expect($result['description'])->toBe('body text');
    }
});

// --- refusals, one per reason ------------------------------------------------------------------

test('refuses an unterminated quote', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: "never closed
        ---
        MD);

    expect($result['error'])->toBe('unterminated quote');
    expect($result['name'])->toBeNull();
    expect($result['description'])->toBeNull();
});

test('refuses a backslash escape inside a quoted value', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: "line one\nline two"
        ---
        MD);

    expect($result['error'])->toBe('backslash escape inside a quoted value');
});

test('refuses tab indentation', function () {
    $result = Frontmatter::parse("---\nname: my-skill\ndescription: >\n\tindented with a tab\n---\n");

    expect($result['error'])->toBe('tab indentation');
});

test('refuses a duplicate name key', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: first
        name: second
        description: does the thing
        ---
        MD);

    expect($result['error'])->toBe('duplicate name key');
});

test('refuses a duplicate description key', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: first
        description: second
        ---
        MD);

    expect($result['error'])->toBe('duplicate description key');
});

test('refuses a missing name key', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        description: does the thing
        ---
        MD);

    expect($result['error'])->toBe('missing required key: name');
});

test('refuses a missing description key', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        ---
        MD);

    expect($result['error'])->toBe('missing required key: description');
});

test('refuses an indented continuation with no block opener', function () {
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description:
          this looks like a continuation but there was no > or |
        ---
        MD);

    expect($result['error'])->toBe('indented continuation with no block opener');
});

test('refuses an indented continuation after a NON-empty plain scalar too, rather than silently dropping it', function () {
    // The class's own docblock: "every ambiguous case here refuses with a reason instead of
    // guessing" — this used to only check the empty-value case and fall through to a plain
    // return for a non-empty one, silently truncating "line one\n  line two" down to "line one"
    // with error === null, exactly the wrong-value-with-no-error failure mode the docblock
    // calls out as the dangerous one.
    $result = Frontmatter::parse(<<<'MD'
        ---
        name: my-skill
        description: line one
          line two
        ---
        MD);

    expect($result['error'])->toBe('indented continuation with no block opener');
    expect($result['description'])->toBeNull();
});

test('refuses frontmatter missing its opening delimiter', function () {
    $result = Frontmatter::parse("name: my-skill\ndescription: does the thing\n");

    expect($result['error'])->toBe('missing frontmatter opening delimiter');
});

test('refuses frontmatter missing its closing delimiter', function () {
    $result = Frontmatter::parse("---\nname: my-skill\ndescription: does the thing\n");

    expect($result['error'])->toBe('missing frontmatter closing delimiter');
});

// --- the real proof: every SKILL.md actually on this machine -----------------------------------

test('parses every SKILL.md under ~/.claude/skills with a refusal rate under 5%', function () {
    $root = getenv('HOME').'/.claude/skills';

    if (! is_dir($root)) {
        $this->markTestSkipped("{$root} does not exist on this machine");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
    );

    foreach ($iterator as $file) {
        if ($file->getFilename() === 'SKILL.md') {
            $files[] = $file->getPathname();
        }
    }

    expect($files)->not->toBeEmpty('found no SKILL.md files under '.$root);

    // This refusal-rate assertion alone was measured blind to the exact failure mode this
    // class's own docblock calls dangerous: a WRONG value with error === null (e.g. a mutated
    // BLOCK_INDICATORS list silently "parsing" a `>-`/`|-` block to its own two-character
    // token). A corpus-wide check for that specific shape turned out to have a real false
    // positive here (~/.claude/skills/cover-letter/SKILL.md legitimately declares
    // `description: ">"` — a quoted single-character value that IS one of the block-indicator
    // tokens, correctly parsed, not a fallthrough bug) with no way to tell the two apart from
    // parse()'s output alone. The gap is closed at its source instead: parseScalar's
    // 'indented continuation with no block opener' refusal (see the two tests above) now fires
    // for a non-empty scalar too, so any indicator that falls through its BLOCK_INDICATORS
    // check on a real multi-line block (the only realistic corpus shape) is refused rather than
    // silently mis-parsed — and 'block scalar chomping variants (>- |- >+ |+) are all accepted'
    // above pins that per-indicator, directly, without a corpus heuristic.
    $refusals = [];

    foreach ($files as $path) {
        $result = Frontmatter::parse(file_get_contents($path));

        if ($result['error'] !== null) {
            $refusals[$path] = $result['error'];
        }
    }

    $rate = count($refusals) / count($files);

    if ($refusals !== []) {
        fwrite(STDERR, sprintf("\nFrontmatter refusals (%d/%d, %.1f%%):\n", count($refusals), count($files), $rate * 100));

        foreach ($refusals as $path => $reason) {
            fwrite(STDERR, "  {$reason} — {$path}\n");
        }
    }

    expect($rate)->toBeLessThan(0.05);
});
