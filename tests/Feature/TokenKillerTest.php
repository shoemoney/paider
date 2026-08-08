<?php

use App\Support\TokenKiller;

test('prune on m1/fixture stays under budget and is smaller than full dump', function () {
    $fixture = realpath(__DIR__.'/../../m1/fixture');
    $files = glob($fixture.'/src/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    $full = 0;
    foreach ($files as $f) {
        $full += strlen(file_get_contents($f));
    }
    $fullTokens = (int) ceil($full / 4);

    $pruned = TokenKiller::prune('add discount to Receipt', $files);
    $prunedTokens = (int) ceil(strlen($pruned) / 4);

    expect($prunedTokens)->toBeLessThanOrEqual(TokenKiller::budget() + 600); // excerpt adds bounded overflow
    expect($prunedTokens)->toBeLessThan($fullTokens);
    expect($pruned)->toContain('<symbols>');
    expect($pruned)->toContain('Receipt');
});

test('extractSignatures finds class and method names via tokenizer', function () {
    $php = "<?php\nclass Foo { public function bar() {} } \ninterface Baz {}";
    $sigs = TokenKiller::extractSignatures($php, '/tmp/Foo.php');
    expect($sigs)->toContain('Foo');
    expect($sigs)->toContain('bar');
});

test('prune ranks Receipt higher than Cart for discount query', function () {
    $fixture = realpath(__DIR__.'/../../m1/fixture');
    $files = glob($fixture.'/src/*.php') ?: [];
    $pruned = TokenKiller::prune('discount Receipt', $files);
    // Receipt should appear before Cart in output due to scoring
    $posReceipt = strpos($pruned, 'Receipt');
    $posCart = strpos($pruned, 'Cart');
    expect($posReceipt)->not->toBeFalse();
    if ($posCart !== false) {
        expect($posReceipt)->toBeLessThan($posCart);
    }
});
