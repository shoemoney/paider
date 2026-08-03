<?php

use App\Tools\PatchFileTool;

/**
 * Fixture-based per PLAN.md: every test writes real files to a real temp workspace on disk and
 * drives PatchFileTool against them, then re-reads the file to assert byte-for-byte outcomes —
 * no mocking of the filesystem.
 */
function patchFileWorkspace(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-patchfile-'.uniqid();
    mkdir($root, recursive: true);

    return realpath($root);
}

function writeFixture(string $root, string $name, string $content): string
{
    $path = $root.DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, $content);

    return $path;
}

function stampOf(string $content): string
{
    return hash('sha256', $content);
}

test('a clean valid diff applies and matches expected output byte-for-byte', function () {
    $root = patchFileWorkspace();
    $original = "line one\nline two\nline three\n";
    $path = writeFixture($root, 'clean.txt', $original);

    $diff = <<<DIFF
    --- a/clean.txt
    +++ b/clean.txt
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'clean.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeTrue();

    $expected = "line one\nline TWO\nline three\n";
    expect(file_get_contents($path))->toBe($expected);
    expect($result->meta['sha256'])->toBe(hash('sha256', $expected));
});

test('a malformed hunk header returns parse_error and leaves the file untouched', function () {
    $root = patchFileWorkspace();
    $original = "line one\nline two\n";
    $path = writeFixture($root, 'malformed.txt', $original);

    $diff = "@@ this is not a hunk header @@\n+line two\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'malformed.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['parse_error'] ?? null)->toBeTrue();

    expect(file_get_contents($path))->toBe($original);
});

test('a hunk whose context lines do not match current content returns conflict and leaves the file untouched', function () {
    $root = patchFileWorkspace();
    $original = "alpha\nbeta\ngamma\n";
    $path = writeFixture($root, 'conflict.txt', $original);

    $diff = <<<DIFF
    @@ -1,3 +1,3 @@
     alpha
    -THIS DOES NOT MATCH
    +beta two
     gamma
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'conflict.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['conflict'] ?? null)->toBe(true);

    expect(file_get_contents($path))->toBe($original);
});

test('a stale stamp returns stale_stamp and leaves the file untouched', function () {
    $root = patchFileWorkspace();
    $original = "current content\n";
    $path = writeFixture($root, 'stale.txt', $original);

    $diff = <<<DIFF
    @@ -1,1 +1,1 @@
    -current content
    +new content
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'stale.txt',
        'diff' => $diff,
        'stamp' => hash('sha256', 'a stamp taken before the file changed'),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['conflict'] ?? null)->toBe('stale_stamp');

    expect(file_get_contents($path))->toBe($original);
});

test('mismatched line endings between diff and file are rejected, not silently mangled', function () {
    $root = patchFileWorkspace();
    $original = "line one\r\nline two\r\nline three\r\n";
    $path = writeFixture($root, 'crlf.txt', $original);

    // Diff uses bare \n throughout, but the file is dominantly \r\n.
    $diff = "@@ -1,3 +1,3 @@\n line one\n-line two\n+line TWO\n line three\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'crlf.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['parse_error'] ?? null)->toBeTrue();

    expect(file_get_contents($path))->toBe($original);
});

test('a diff that applies cleanly but yields invalid PHP is rejected via the syntax gate', function () {
    $root = patchFileWorkspace();
    $original = "<?php\n\nfunction greet(): string\n{\n    return 'hello';\n}\n";
    $path = writeFixture($root, 'broken.php', $original);

    $diff = <<<DIFF
    @@ -3,4 +3,4 @@
     function greet(): string
     {
    -    return 'hello';
    +    return 'hello;
     }
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'broken.php',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['syntax_error'] ?? null)->toBeTrue();

    expect(file_get_contents($path))->toBe($original);
});

test('a new file is created correctly from an all-+ diff with the __new_file__ sentinel stamp', function () {
    $root = patchFileWorkspace();
    $path = $root.DIRECTORY_SEPARATOR.'brand-new.txt';

    expect(is_file($path))->toBeFalse();

    $diff = "@@ -0,0 +1,3 @@\n+first line\n+second line\n+third line\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'brand-new.txt',
        'diff' => $diff,
        'stamp' => PatchFileTool::NEW_FILE_STAMP,
    ]);

    expect($result->ok)->toBeTrue();

    $expected = "first line\nsecond line\nthird line\n";
    expect(file_get_contents($path))->toBe($expected);
});

test('creating a new sensitive-named file requires approval and is not written without it', function () {
    $root = patchFileWorkspace();
    $path = $root.DIRECTORY_SEPARATOR.'.env';

    $diff = "@@ -0,0 +1,1 @@\n+SECRET=value\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => '.env',
        'diff' => $diff,
        'stamp' => PatchFileTool::NEW_FILE_STAMP,
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'] ?? false)->toBeTrue();
    expect($result->meta['reason'] ?? null)->toBe('secrets');
    expect(is_file($path))->toBeFalse();

    $approved = $tool->execute([
        'path' => '.env',
        'diff' => $diff,
        'stamp' => PatchFileTool::NEW_FILE_STAMP,
        'approved' => true,
    ]);

    expect($approved->ok)->toBeTrue();
    expect(file_get_contents($path))->toBe("SECRET=value\n");
});

test('a diff that never touches the last line of a no-trailing-newline file does not gain one', function () {
    $root = patchFileWorkspace();
    $original = "line one\nline two\nline three";
    $path = writeFixture($root, 'no-eol.txt', $original);

    $diff = <<<DIFF
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'no-eol.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeTrue();

    $expected = "line one\nline TWO\nline three";
    expect(file_get_contents($path))->toBe($expected);
});

test('a standard "\ No newline at end of file" marker is honoured, not rejected as a parse error', function () {
    $root = patchFileWorkspace();
    $original = "line one\nline two\nline three";
    $path = writeFixture($root, 'no-eol-marker.txt', $original);

    $diff = <<<DIFF
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    \ No newline at end of file
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'no-eol-marker.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeTrue();

    $expected = "line one\nline TWO\nline three";
    expect(file_get_contents($path))->toBe($expected);
});

test('a marker on an added line replacing the no-trailing-newline last line is honoured', function () {
    $root = patchFileWorkspace();
    $original = "line one\nline two\nline three";
    $path = writeFixture($root, 'no-eol-last-line.txt', $original);

    $diff = <<<DIFF
    @@ -1,3 +1,3 @@
     line one
     line two
    -line three
    +line THREE
    \ No newline at end of file
    DIFF."\n";

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => 'no-eol-last-line.txt',
        'diff' => $diff,
        'stamp' => stampOf($original),
    ]);

    expect($result->ok)->toBeTrue();

    $expected = "line one\nline two\nline THREE";
    expect(file_get_contents($path))->toBe($expected);
});

test('a path that escapes the workspace root is denied', function () {
    $root = patchFileWorkspace();

    $tool = new PatchFileTool($root);
    $result = $tool->execute([
        'path' => '../outside.txt',
        'diff' => "@@ -0,0 +1,1 @@\n+x\n",
        'stamp' => PatchFileTool::NEW_FILE_STAMP,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->meta['denied'] ?? null)->toBeTrue();
});

test('a NUL byte in path is denied instead of crashing the process', function () {
    $root = patchFileWorkspace();

    $tool = new PatchFileTool($root);

    // json_decode faithfully turns a model tool-call's   escape into a PHP string with
    // an embedded NUL byte — exactly what Loop::parseToolCall() hands to execute(). Without
    // the PathGuard NUL guard this reaches SecretsGuard::isSensitive()'s fnmatch() call and
    // throws an uncaught ValueError, killing the whole process instead of failing the tool call.
    $result = $tool->execute([
        'path' => "evil.txt\0.php",
        'diff' => "@@ -0,0 +1,1 @@\n+PWNED\n",
        'stamp' => PatchFileTool::NEW_FILE_STAMP,
    ]);

    expect($result->ok)->toBeFalse();
});
