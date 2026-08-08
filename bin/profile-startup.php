#!/usr/bin/env php
<?php
// bin/profile-startup.php — measure 48.6ms floor vs lean Laravel Zero cold start.
// See DECISIONS.md §3/§8/§9, PLAN-PHASES-1-10.md Phase 4.
// Usage: php -n -c lean.ini bin/profile-startup.php  (or plain php bin/profile-startup.php)
$t0 = hrtime(true);
require __DIR__.'/../vendor/autoload.php';
$t1 = hrtime(true);

// Boot Laravel Zero app without running a command — measures bootstrap tax.
$app = require __DIR__.'/../bootstrap/app.php';
$t2 = hrtime(true);

// Touch config loading — only after app boot, but app is not fully booted here
// so config() may not be resolvable. Best-effort.
$configTime = 0;
try {
    if (function_exists('config') && isset($app) && $app->bound('config')) {
        $c0 = hrtime(true);
        config('app.version');
        $c1 = hrtime(true);
        $configTime = ($c1 - $c0) / 1e6;
    }
} catch (\Throwable $e) {
    $configTime = 0;
}

$autoloadMs = ($t1 - $t0) / 1e6;
$bootMs = ($t2 - $t1) / 1e6;
$totalMs = ($t2 - $t0) / 1e6;

printf("autoload: %.2f ms\n", $autoloadMs);
printf("bootstrap: %.2f ms\n", $bootMs);
printf("config(app.version): %.2f ms\n", $configTime);
printf("total (autoload+bootstrap): %.2f ms\n", $totalMs);
printf("php: %s | extensions: %d | lean_ini: %s\n", PHP_VERSION, count(get_loaded_extensions()), php_ini_loaded_file() ?: 'none');
printf("gate: %.2fms total %s 120ms lean budget\n", $totalMs, $totalMs <= 120 ? 'PASS ≤' : 'FAIL >');
