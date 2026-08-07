<?php

namespace App\Support;

/**
 * Terminal-escape allowlist for untrusted text (model replies, tool output, anything that can
 * carry a prompt injection) before it ever reaches the user's real terminal. See Loop::renderProse().
 *
 * ALLOWLIST, not a blocklist: the only escape sequences that survive are plain SGR colour
 * sequences matching `\e[[0-9;]*m` — our own Palette output, or a model just using colour.
 * Everything else is dropped whole: every other CSI (cursor movement, erase, mode toggles,
 * including one with no final byte at all — an attacker-supplied introducer left to be
 * "completed" by whatever the caller happens to print next), all OSC/DCS/APC/PM/SOS (OSC 52
 * clipboard write among them), and every Fp/Fe/Fs escape (ESC c full-reset among them) whether
 * or not it has intermediate bytes. The final pass below is what actually guarantees that last
 * part: rather than enumerate every 2/3-byte escape family by hand, it strips any ESC that
 * isn't the head of an already-kept SGR match, so nothing shaped like `ESC <anything else>`
 * survives regardless of which family it belongs to. SGR conceal is dropped too even when it
 * isn't spelled as literal code 8 — a foreground and background pair set to the same colour
 * hides text exactly as well and is checked for explicitly, since it hides text outright,
 * which is exactly the threat this class exists to close.
 *
 * No /u modifier anywhere below. Model output and git diffs routinely carry invalid UTF-8;
 * preg_replace with /u returns NULL on malformed input, which would blank the whole string
 * instead of sanitising it. Every pattern here runs in byte mode on purpose.
 */
final class TerminalSafe
{
    public static function clean(string $text): string
    {
        // C1 controls in their UTF-8 form (\xc2\x80-\x9f). An ESC-less CSI (\xc2\x9b) is real
        // and a byte-oriented \x1b filter alone would never see it.
        $text = preg_replace('/\xc2[\x80-\x9f]/', '', $text) ?? $text;

        // OSC, terminated by BEL or ST (\e\). Bounded to stop at the next \x1b if a terminator
        // is missing, so a truncated/malformed sequence can't swallow the rest of the string.
        $text = preg_replace('/\x1b\x5d[^\x07\x1b]*(?:\x07|\x1b\x5c)?/', '', $text) ?? $text;

        // DCS / APC / PM / SOS (\eP \e_ \e^ \eX), terminated by ST.
        $text = preg_replace('/\x1b[\x50\x58\x5e\x5f][^\x1b]*(?:\x1b\x5c)?/', '', $text) ?? $text;

        // Every CSI (\e[ params intermediates final). Kept byte-identical only when it's
        // exactly an SGR sequence with digit/semicolon params and no SGR-8 (conceal) token;
        // every other CSI — cursor movement, erase, ? private modes — is dropped whole.
        $text = preg_replace_callback(
            '/\x1b\x5b[\x30-\x3f]*[\x20-\x2f]*[\x40-\x7e]/',
            static function (array $m): string {
                if (! preg_match('/^\x1b\x5b([\x30-\x39\x3b]*)\x6d$/', $m[0], $sgr)) {
                    return '';
                }

                $params = explode(';', $sgr[1]);

                // Zero-padded decimal ("08", "008") is a legal ECMA-48 spelling of SGR 8 — every
                // terminal parser accumulates param*10+digit, so an exact string compare against
                // '8' alone lets '\e[08m' conceal text right past this allowlist.
                return in_array(8, array_map('intval', $params), true) || self::sgrConceals($params) ? '' : $m[0];
            },
            $text,
        ) ?? $text;

        // nF sequences (\e + intermediates + final, e.g. charset select) and any leftover
        // single-byte Fe escape not already consumed above. Excludes P/X/^/_ (DCS/SOS/PM/APC,
        // handled above) and [/] (CSI/OSC introducers) — matching those here would strip a
        // bare "\e[" left behind by an already-kept SGR match and orphan its digits as text.
        $text = preg_replace('/\x1b[\x20-\x2f]+[\x30-\x7e]/', '', $text) ?? $text;
        $text = preg_replace('/\x1b[\x40-\x4f\x51-\x57\x59\x5a\x5c]/', '', $text) ?? $text;

        // Catch-all for every ESC the passes above didn't resolve one way or another: a 2-byte
        // Fp/Fs escape with no intermediate byte (ESC c / RIS among them — the nF pass above
        // only fires when at least one intermediate byte is present), or a CSI/OSC/DCS
        // introducer with no valid terminator before the string ends (the dangling-CSI splice —
        // the caller's own next `echo` would otherwise supply the missing final byte). The
        // lookahead is the only thing standing between this and stripping a kept SGR sequence's
        // own ESC, so it must match that pass's keep condition exactly: ESC immediately
        // followed by '[', digit/semicolon params, then 'm'.
        $text = preg_replace('/\x1b(?!\x5b[\x30-\x39\x3b]*\x6d)/', '', $text) ?? $text;

        // C0 controls minus \t/\n AND minus \x1b: backspace, bare CR, ENQ, BEL are gone, without
        // eating the newlines/indentation every prose reply and diff depends on. \x1b is
        // deliberately excluded here — the escape-sequence passes above already resolved every
        // ESC byte one way or another (kept whole inside a surviving SGR match, or dropped
        // whole with its sequence); stripping \x1b again here as a blanket C0 byte would tear
        // the ESC off the front of every SGR sequence this class exists to preserve.
        return preg_replace('/[\x00-\x08\x0b-\x1a\x1c-\x1f\x7f]/', '', $text) ?? $text;
    }

    /**
     * True when the params set foreground and background to the identical colour — text hidden
     * exactly like SGR 8 conceal, just spelled differently (30;40, 38;5;N;48;5;N, or
     * 38;2;r;g;b;48;2;r;g;b all collapse fg into bg). Cheap linear scan over the semicolon-split
     * digits; the explicit '8' check above still catches literal conceal.
     *
     * @param  list<string>  $params
     */
    private static function sgrConceals(array $params): bool
    {
        $fg = $bg = null;

        for ($i = 0, $n = count($params); $i < $n; $i++) {
            $p = $params[$i];

            if ($p === '38' && ($params[$i + 1] ?? null) === '5') {
                $fg = $params[$i + 2] ?? null;
                $i += 2;
            } elseif ($p === '48' && ($params[$i + 1] ?? null) === '5') {
                $bg = $params[$i + 2] ?? null;
                $i += 2;
            } elseif ($p === '38' && ($params[$i + 1] ?? null) === '2') {
                $fg = implode(',', array_slice($params, $i + 2, 3));
                $i += 4;
            } elseif ($p === '48' && ($params[$i + 1] ?? null) === '2') {
                $bg = implode(',', array_slice($params, $i + 2, 3));
                $i += 4;
            } elseif (ctype_digit($p) && (int) $p >= 30 && (int) $p <= 37) {
                $fg = (string) ((int) $p - 30);
            } elseif (ctype_digit($p) && (int) $p >= 90 && (int) $p <= 97) {
                $fg = (string) ((int) $p - 90);
            } elseif (ctype_digit($p) && (int) $p >= 40 && (int) $p <= 47) {
                $bg = (string) ((int) $p - 40);
            } elseif (ctype_digit($p) && (int) $p >= 100 && (int) $p <= 107) {
                $bg = (string) ((int) $p - 100);
            }
        }

        return $fg !== null && $fg === $bg;
    }
}
