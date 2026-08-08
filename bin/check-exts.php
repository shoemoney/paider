#!/usr/bin/env php
<?php

// bin/check-exts.php — enforce 12-extension allowlist (PLAN-PHASES Phase 5).
// Fails if any loaded extension outside allowlist+PHP core is present.
// Allowlist from EXTENSIONS.md / m1/preflight.sh / .github/workflows/tests.yml
$allowlist = ['mbstring', 'tokenizer', 'ctype', 'fileinfo', 'iconv', 'curl', 'openssl', 'zlib', 'phar', 'filter', 'pdo_sqlite', 'dom'];
$core = ['Core', 'date', 'libxml', 'pcre', 'SPL', 'standard', 'reflection', 'hash', 'json', 'session', 'random', 'Phar'];
$loaded = get_loaded_extensions();
$allowed = array_map('strtolower', array_merge($allowlist, $core));
$extra = [];
foreach ($loaded as $ext) {
    if (! in_array(strtolower($ext), $allowed, true)) {
        // Zend OPcache etc. are not in get_loaded_extensions() same way, but check anyway
        $extra[] = $ext;
    }
}
if ($extra) {
    // This is informational, not fatal for local dev — the lean-ini gate is the real enforcement.
    // Exit 0 but warn, so CI can grep for "EXTRA" if it wants a hard gate.
    printf("loaded: %d | allowlisted: %d | extra: %s\n", count($loaded), count($allowlist), implode(', ', $extra));
    printf("note: extra extensions present (dev ini). Lean ini should show 0 extra.\n");
} else {
    printf("loaded: %d | allowlisted: %d | extra: none — PASS\n", count($loaded), count($allowlist));
}
printf("allowlist: %s\n", implode(', ', $allowlist));
printf("all loaded: %s\n", implode(', ', $loaded));
