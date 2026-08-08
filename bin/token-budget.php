#!/usr/bin/env php
<?php

// bin/token-budget.php — measure avg tokens_in per research tier_call from .paider/paider.db
// Also probes TokenKiller pruning win on m1/fixture.
require __DIR__.'/../vendor/autoload.php';

use App\Support\TokenKiller;

$dbPath = getcwd().'/.paider/paider.db';
if (! is_file($dbPath)) {
    $dbPath = __DIR__.'/../.paider/paider.db';
}

echo "==> token-budget probe\n";

if (is_file($dbPath)) {
    $db = new PDO('sqlite:'.$dbPath);
    $rows = $db->query("SELECT json_extract(payload,'\$.tier') as tier, json_extract(payload,'\$.tokens_in') as ti, json_extract(payload,'\$.model') as model FROM events WHERE type='tier_call'")->fetchAll(PDO::FETCH_ASSOC);
    $byTier = [];
    foreach ($rows as $r) {
        $t = $r['tier'] ?? 'unknown';
        $byTier[$t][] = (int) $r['ti'];
    }
    foreach ($byTier as $tier => $vals) {
        $avg = $vals ? array_sum($vals) / count($vals) : 0;
        printf("  tier %-12s n=%3d avg_in=%.1f max=%d\n", $tier, count($vals), $avg, $vals ? max($vals) : 0);
    }
    if (! $rows) {
        echo "  no tier_call events yet — run paider chat first\n";
    }
} else {
    echo "  no DB at $dbPath\n";
}

echo "\n==> TokenKiller prune probe on m1/fixture\n";
$fixture = __DIR__.'/../m1/fixture';
$files = glob($fixture.'/src/*.php') ?: [];
if ($files) {
    $query = 'add discount to Receipt';
    $pruned = TokenKiller::prune($query, $files);
    $full = 0;
    foreach ($files as $f) {
        $full += strlen(file_get_contents($f));
    }
    $prunedTokens = (int) ceil(strlen($pruned) / 4);
    $fullTokens = (int) ceil($full / 4);
    printf("  query: %s\n", $query);
    printf("  full: %d bytes ~%d toks\n", $full, $fullTokens);
    printf("  pruned: %d bytes ~%d toks (budget %d)\n", strlen($pruned), $prunedTokens, TokenKiller::budget());
    printf("  win: %.1fx smaller\n", $fullTokens ? $fullTokens / max(1, $prunedTokens) : 0);
    echo "\n--- pruned output preview ---\n";
    echo substr($pruned, 0, 800)."\n";
} else {
    echo "  no fixture files found\n";
}

$realFlag = in_array('--real-app', $argv, true) || in_array('--real', $argv, true);
if ($realFlag) {
    echo "\n==> TokenKiller real app/ probe (6k LOC)\n";
    $appRoot = __DIR__.'/..';
    $appFiles = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot.'/app'));
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $appFiles[] = $file->getPathname();
        }
    }
    sort($appFiles);
    $count = count($appFiles);
    $fullBytes = 0;
    foreach ($appFiles as $f) {
        $fullBytes += strlen(file_get_contents($f));
    }
    $fullToks = (int) ceil($fullBytes / 4);
    $query2 = 'add discount to Receipt';
    $pruned2 = TokenKiller::prune($query2, $appFiles);
    $prunedToks2 = (int) ceil(strlen($pruned2) / 4);
    printf("  files: %d php files in app/\n", $count);
    printf("  full: %d bytes ~%d toks\n", $fullBytes, $fullToks);
    printf("  pruned: %d bytes ~%d toks (budget %d)\n", strlen($pruned2), $prunedToks2, TokenKiller::budget());
    printf("  win: %.1fx smaller\n", $fullToks ? $fullToks / max(1, $prunedToks2) : 0);
    echo "\n--- pruned output preview (real app) ---\n";
    echo substr($pruned2, 0, 1200)."\n";
}
