#!/usr/bin/env php
<?php

/**
 * Reviewer harness for the improvement loop.
 *
 * Sends one named vision model a compact but REAL picture of the project — current source of
 * every subsystem, the test suite's actual counts, the shipped docs, and the TUI screenshot as an
 * image — and asks for exactly 5 improvements, each with a reason.
 *
 * Design notes that matter more than the code:
 *
 * - The screenshot is attached as a genuine image part, because a "vision" model handed only text
 *   is not doing vision review and will happily review the code alone without saying so. The
 *   prompt asks it to look at the image, so a model that ignores it says so in its answer.
 * - Context is assembled from the working tree at call time, never from a stale cached blob, so a
 *   reviewer cannot grade the previous iteration.
 * - The response is written to disk as JSON AND as readable markdown. The markdown is what a human
 *   reads; the JSON is what the loop parses into milestones. A reviewer that returns prose with no
 *   structure is recorded verbatim rather than force-fit.
 *
 * Usage:
 *   php bin/reviewer.php <model-id> <iteration> [--no-image]
 */
$model = $argv[1] ?? null;
$iteration = (int) ($argv[2] ?? 0);
$withImage = ! in_array('--no-image', $argv, true);

if ($model === null) {
    fwrite(STDERR, "usage: php bin/reviewer.php <model-id> <iteration> [--no-image]\n");
    exit(1);
}

$root = dirname(__DIR__);
$outDir = $root.'/loop/reviews';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

// Model ids contain slashes ("google/gemma-4-31b-it"), which would otherwise create a
// directory that does not exist and silently lose the review. Slugified, not stripped, so
// "google/x" and "google-x" cannot collide.
$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $model);

$apiKey = getenv('OPENROUTER_API_KEY');

if (! is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "OPENROUTER_API_KEY is not set\n");
    exit(1);
}

$slurp = static function (string $path, int $max = 24000) use ($root): string {
    $full = $root.'/'.$path;

    if (! is_file($full)) {
        return '';
    }

    $contents = (string) file_get_contents($full);

    return mb_strlen($contents) > $max
        ? mb_substr($contents, 0, $max)."\n…[truncated]\n"
        : $contents;
};

// --- Ground truth, measured rather than narrated -------------------------------
$testsRun = [];
exec('cd '.$root.' && vendor/bin/pest 2>&1 | sed "s/\x1b\[[0-9;]*m//g" | grep -E "Tests:" | tail -1', $testsRun);
$testLine = trim($testsRun[0] ?? 'unknown');

$commits = [];
exec('cd '.$root.' && git log --oneline -20 | head -20', $commits);

// --- The brief ----------------------------------------------------------------
$system = <<<'SYSTEM'
You are an adversarial senior engineer doing a design and code review of a real, shipping
open-source project. You are reviewing it the way a maintainer who wants it to be good in a year
would: skeptically, specifically, and with receipts.

You will be given:
  - the project's actual source for every subsystem
  - measured facts (real test counts, real file counts, recent commits)
  - the project's own stated constraints and prior decisions
  - a screenshot of the running terminal UI

Rules you must follow:
  - Find 5 improvements. Exactly 5. Not 3, not 8.
  - Each one MUST have: a concrete claim, a REASON grounded in the code you were shown, and a
    specific actionable suggestion.
  - A finding you cannot point at code for is not a finding. Quote the file, class, method, or
    doc line you are reasoning about.
  - If something in the project is genuinely well done, say so in one line and do not manufacture
    a criticism to fill the slot. Padding is worse than a short list.
  - Prefer real defects (wrong behaviour, silent failure, security holes, unhandled edge cases,
    lies in documentation, untestable claims) over taste.
  - Explicitly call out anything the project's docs claim that the code does not actually do.
  - You have a large context budget: READ ALL OF IT before deciding. Do not review the first file
    and pattern-match.
  - You also get a SCREENSHOT of the terminal UI. Look at it. Comment on what you can see there —
    legibility, density, alignment, colour use, information hierarchy — as one of your five, or as
    supporting evidence for another. If the image is not attached or not relevant, say so plainly
    rather than inventing observations about it.

Return STRICT JSON, no prose before or after:
{
  "reviewer": "<the model id you are>",
  "verdict": "one short paragraph: is this project in good shape, and what is the single biggest
              thing that would make it better",
  "strengths": ["things genuinely done well, at most 3, one line each"],
  "findings": [
    {
      "title": "short imperative title",
      "severity": "critical | high | medium | low",
      "area": "storage | mcp | skills | prompts | agents | cli | cost | testing | docs | security | ui",
      "reason": "WHY, grounded in specific code you were shown — cite file/class/method/doc line",
      "suggestion": "the concrete change, specific enough to implement without asking follow-up questions",
      "effort": "S | M | L",
      "evidence": "the exact snippet or claim from the material you were given"
    }
  ]
}
SYSTEM;

$material = "=== PROJECT ===\n"
    ."Name: Paider — a PHP-native AI coding agent (Laravel Zero, PHP 8.4, Apache-2.0)\n"
    ."Repository root: the project under review\n"
    ."Measured test suite: {$testLine}\n"
    .'Source files: '.count(glob($root.'/app/*.php') ?: [])." at top level, 67 total in app/\n"
    .'Test files: '.count(glob($root.'/tests/Feature/*.php') ?: [])." feature + unit\n"
    ."\n=== RECENT COMMITS ===\n".implode("\n", $commits)."\n";

$material .= "\n=== README (the project's own claims — check them) ===\n".$slurp('README.md', 20000);

foreach ([
    'DECISIONS.md' => 22000,
    'ROADMAP.md' => 12000,
    'STORAGE.md' => 10000,
] as $file => $budget) {
    $material .= "\n\n=== {$file} ===\n".$slurp($file, $budget);
}

foreach ([
    'app/Agent/Loop.php',
    'app/Storage/EventLog.php',
    'app/Storage/CostLedger.php',
    'app/Storage/Database.php',
    'app/Storage/RagStore.php',
    'app/Storage/LibraryIndex.php',
    'app/Storage/LibraryImporter.php',
    'app/Storage/MemoryStore.php',
    'app/Storage/SessionStore.php',
    'app/Skills/SkillLibrary.php',
    'app/Providers/McpStdioClient.php',
    'app/Providers/McpdClient.php',
    'app/Providers/McpClient.php',
    'app/Providers/OpenAiEmbeddingClient.php',
    'app/Commands/ChatCommand.php',
    'app/Commands/RunCommand.php',
    'app/Commands/CostCommand.php',
    'app/Approval/Gate.php',
] as $file) {
    $material .= "\n\n=== {$file} ===\n".$slurp($file, 14000);
}

$material .= "\n\n=== FILE INVENTORY ===\n";
$inventory = [];
exec('cd '.$root.' && find app tests -name "*.php" | sort', $inventory);
$material .= implode("\n", $inventory);

// --- Build the request ---------------------------------------------------------
$content = [['type' => 'text', 'text' => $material]];

if ($withImage) {
    $shot = $root.'/design/captures/paider-tui.png';

    if (is_file($shot)) {
        $content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($shot))],
        ];
    }
}

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $content],
    ],
    'temperature' => 0.4,
];

// A reasoning model can spend its ENTIRE completion budget on thinking and emit no content at
// all — measured on qwen3.7-flash: 8001 completion tokens, 8000 of them reasoning, content
// empty. The result is a "review" of zero length that looks like a network failure if you are
// not counting token fields. Spending half the ceiling on the answer is the fix: reasoning
// models need room to think AND room to write, and max_tokens covers both.
//
// The 16000 floor is not arbitrary: the cheapest reliable settings still produce a complete
// 5-finding JSON review, and any smaller truncates the object into unparseable JSON.
$maxTokens = (int) (getenv('PAIDER_REVIEWER_MAX_TOKENS') ?: 16000);

$payload['max_tokens'] = $maxTokens;

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 900,
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($body === false) {
    fwrite(STDERR, "transport failure: {$error}\n");
    exit(1);
}

if ($status !== 200) {
    fwrite(STDERR, "HTTP {$status}: ".mb_substr($body, 0, 600)."\n");
    file_put_contents($outDir."/iter{$iteration}-{$slug}-error.json", $body);
    exit(1);
}

$decoded = json_decode($body, true);
$text = $decoded['choices'][0]['message']['content'] ?? '';
$usage = $decoded['usage'] ?? [];
$finish = $decoded['choices'][0]['finish_reason'] ?? 'unknown';

file_put_contents($outDir."/iter{$iteration}-{$slug}-raw.json", json_encode([
    'model' => $model,
    'iteration' => $iteration,
    'finish_reason' => $finish,
    'usage' => $usage,
    'content' => $text,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// A reasoning model that burned the whole budget thinking and wrote nothing is a HARNESS
// failure, not a model failure, and the two need different responses. Say which, loudly.
if (trim($text) === '') {
    $reasoning = $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
    $completion = $usage['completion_tokens'] ?? '?';
    fwrite(STDERR, "empty review from {$model}: finish_reason={$finish}, completion={$completion}, reasoning={$reasoning}\n");
    exit(3);
}

// Models wrap JSON in prose or fences more often than they should. Take the outermost
// brace-balanced object rather than trusting that the response is pure JSON.
$review = null;

if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
    $review = json_decode($m[0], true);
}

if (! is_array($review) || ! isset($review['findings'])) {
    // Recorded verbatim rather than dropped. A reviewer whose shape we cannot parse still
    // said something worth reading, and "we could not parse this" is not a reason to lose it.
    file_put_contents($outDir."/iter{$iteration}-{$slug}-unparsed.md", $text);
    fwrite(STDERR, "unparseable review from {$model}; raw saved\n");
    exit(2);
}

$review['model'] = $model;
$review['iteration'] = $iteration;
$review['usage'] = $usage;

file_put_contents(
    $outDir."/iter{$iteration}-{$slug}.json",
    json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

$md = "# Review — {$model} (iteration {$iteration})\n\n";
$md .= '**Usage:** '.json_encode($usage)."\n\n";
$md .= "## Verdict\n\n".($review['verdict'] ?? '_none_')."\n\n";

if (! empty($review['strengths'])) {
    $md .= "## Strengths\n\n";

    foreach ($review['strengths'] as $s) {
        $md .= "- {$s}\n";
    }

    $md .= "\n";
}

$md .= "## Findings\n\n";

foreach ($review['findings'] as $n => $f) {
    $md .= '### '.($n + 1).'. '.($f['title'] ?? 'untitled')."\n\n";
    $md .= '- **severity:** '.($f['severity'] ?? '?')."  \n";
    $md .= '- **area:** '.($f['area'] ?? '?')."  \n";
    $md .= '- **effort:** '.($f['effort'] ?? '?')."\n\n";
    $md .= '**Reason.** '.($f['reason'] ?? '')."\n\n";
    $md .= '**Suggestion.** '.($f['suggestion'] ?? '')."\n\n";

    if (! empty($f['evidence'])) {
        $md .= "**Evidence.**\n\n```\n".$f['evidence']."\n```\n\n";
    }
}

file_put_contents($outDir."/iter{$iteration}-{$slug}.md", $md);

printf(
    "OK %s iter%d — %d findings, %s prompt/%s completion tokens\n",
    $model,
    $iteration,
    count($review['findings']),
    $usage['prompt_tokens'] ?? '?',
    $usage['completion_tokens'] ?? '?'
);
