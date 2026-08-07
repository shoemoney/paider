<?php

use App\Support\TerminalSafe;

test('an OSC 52 clipboard payload leaves zero 0x1B bytes, while plain SGR colour survives byte-identical', function () {
    $osc52 = "\e]52;c;cGF5bG9hZA==\x07";
    $injected = "before {$osc52} after";

    $cleaned = TerminalSafe::clean($injected);

    expect($cleaned)->not->toContain("\x1b")
        ->and($cleaned)->toBe('before  after');

    // Same call, colour surviving byte-identical — a sanitiser that strips everything
    // (including our own Palette output) would pass the first half of this test and fail this.
    expect(TerminalSafe::clean("\e[31mred\e[0m"))->toBe("\e[31mred\e[0m");
    expect(TerminalSafe::clean("\e[38;2;255;0;0mtruecolor\e[0m"))->toBe("\e[38;2;255;0;0mtruecolor\e[0m");
});

test('backspace, bare CR, ENQ and a UTF-8 C1 CSI are removed, while tab and newline survive', function () {
    $dirty = "a\x08b\x0dc\x05d\xc2\x9be\tf\ng";

    expect(TerminalSafe::clean($dirty))->toBe("abcde\tf\ng");
});

test('invalid UTF-8 mixed with a real escape returns a non-null, non-empty result', function () {
    // The /u trap: preg_replace with /u silently returns NULL on malformed input. This must
    // fail the moment anyone adds /u to a pattern in TerminalSafe.
    $malformed = "\xff\xfe\e[32mstill here\e[0m\xff";

    $cleaned = TerminalSafe::clean($malformed);

    expect($cleaned)->not->toBeNull()
        ->and($cleaned)->not->toBe('')
        ->and($cleaned)->toContain("\e[32m")
        ->and($cleaned)->toContain('still here');
});

test('SGR 8 conceal and a cursor-up-plus-erase sequence are removed', function () {
    // The conceal code itself is dropped even though it's shaped like a colour sequence; the
    // trailing reset is a legitimate SGR and correctly survives — this isn't a blanket "drop
    // every SGR touching this text" pass, only the one dangerous code.
    $conceal = "\e[8mhidden\e[0m";
    $cursorAndErase = "line one\e[1A\e[2Kline two";

    expect(TerminalSafe::clean($conceal))->toBe("hidden\e[0m")
        ->and(TerminalSafe::clean($cursorAndErase))->toBe('line oneline two');
});

test('zero-padded SGR 8 conceal is removed just like the unpadded spelling', function () {
    // ECMA-48 params are decimal with leading zeros permitted; every real terminal parser
    // accumulates param*10+digit, so '08'/'008' conceal exactly like '8' and must not survive
    // an exact string compare against the unpadded form.
    expect(TerminalSafe::clean("\e[08mhidden\e[0m"))->toBe("hidden\e[0m")
        ->and(TerminalSafe::clean("\e[008mhidden\e[0m"))->toBe("hidden\e[0m")
        ->and(TerminalSafe::clean("\e[1;08mhidden\e[0m"))->toBe("hidden\e[0m");
});
