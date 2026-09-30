<?php

/*
 * Credential scrubbing in the event log.
 *
 * ## The bug this prevents
 *
 * Every conversation message went through `ProseStream::scrubSecrets()` before reaching the log.
 * The `tool_call` event wrote `$call['input']` RAW. Since a coding agent is routinely asked to
 * run `curl -H "Authorization: Bearer …"` against somebody else's API, that string landed in
 * plaintext `.paider/paider.db` — and `RagStore` EMBEDS that log and ships it to
 * `PAIDER_EMBEDDING_URL`. A credential in a tool argument was on an outbound path.
 *
 * Found by an adversarial review (glm-5.3-flash) that noticed the asymmetry by reading the code
 * against the surrounding scrub calls. Proved before fixing: a `sk-live-…` written through a
 * `tool_call` append was found verbatim in the stored payload.
 */

use App\Agent\Loop;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Tools\Contracts\Tool;
use App\Tools\ToolResult;

final class SecretProbeProvider implements ProviderClient
{
    /** @var array<int, string> */
    private array $script;

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        return new ProviderResponse(array_shift($this->script) ?? 'done', 10, 5, []);
    }
}

final class SecretProbeTool implements Tool
{
    public function name(): string
    {
        return 'run_shell';
    }

    public function description(): string
    {
        return 'probe';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function execute(array $input, bool $approved = false): ToolResult
    {
        return ToolResult::ok('ran');
    }
}

/** Run a turn that proposes a tool call, and return the log. */
function secretProbeTurn(array $toolInput): EventLog
{
    $log = new EventLog(Database::connect(':memory:'));

    $call = json_encode(['name' => 'run_shell', 'input' => $toolInput], JSON_THROW_ON_ERROR);

    $loop = new Loop(
        [new SecretProbeTool],
        new SecretProbeProvider(["```tool\n".$call."\n```", 'done']),
        new TierRouter,
        $log,
        Gate::forSession(true),
    );

    $loop->turn(loopTestSession(), 'go', fn (string $w) => 'allow-once');

    return $log;
}

/** The tool_call payload as it is actually stored. */
function secretProbePayload(EventLog $log): string
{
    foreach ($log->all() as $event) {
        if ($event['type'] === 'tool_call') {
            return json_encode($event['payload'], JSON_THROW_ON_ERROR);
        }
    }

    return '';
}

it('does NOT write a bearer token from a shell command into the log', function () {
    $secret = 'sk-live-THISISAREALSECRET1234';

    $payload = secretProbePayload(secretProbeTurn([
        'command' => 'curl -H "Authorization: Bearer '.$secret.'" https://api.example.com',
    ]));

    // Which label wins is an implementation detail — the provider-prefixed `sk-` pattern is
    // applied before the generic auth-header one, so this is redacted as [redacted:sk-]. What
    // matters is that the SECRET IS GONE and the command is still readable.
    expect($payload)->not->toContain($secret)
        ->and($payload)->toContain('[redacted:')
        // The COMMAND itself must still be readable: a log that redacted everything would be
        // useless for working out what the agent did.
        ->and($payload)->toContain('curl')
        ->and($payload)->toContain('api.example.com');
});

it('redacts every common provider key shape', function (string $secret, string $label) {
    $payload = secretProbePayload(secretProbeTurn(['command' => 'deploy --credential '.$secret.' now']));

    expect($payload)->not->toContain($secret)
        ->and($payload)->toContain('[redacted:');
})->with([
    ['sk-live-ABCDEFGHIJKLMNOPQRSTUV', 'openai'],
    ['ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', 'github'],
    ['github_pat_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123', 'github-pat'],
    ['AKIAIOSFODNN7EXAMPLE', 'aws'],
    ['AIzaSyD1234567890abcdefghijklmnopqrstu', 'google'],
    ['xoxb-123456789012-abcdefghijkl', 'slack'],
]);

it('redacts api_key / token assignments', function () {
    $payload = secretProbePayload(secretProbeTurn([
        'command' => 'curl -d "api_key=supersecretvalue123" https://api.example.com',
    ]));

    expect($payload)->not->toContain('supersecretvalue123');
});

it('scrubs NESTED input, not just top-level strings', function () {
    $secret = 'ghp_NESTEDSECRETVALUE1234567890';

    $payload = secretProbePayload(secretProbeTurn([
        'command' => 'deploy',
        'env' => ['GITHUB_TOKEN' => $secret],          // nested value
        'headers' => ['Authorization' => 'Bearer '.$secret], // nested header
    ]));

    // A non-recursive scrub would protect the top-level 'command' and miss both of these.
    expect($payload)->not->toContain($secret);
});

it('scrubs a secret hiding in an ARGUMENT KEY, not only in values', function () {
    $secret = 'sk-live-KEYPOSITIONLEAK12345';

    $payload = secretProbePayload(secretProbeTurn([
        'command' => 'deploy',
        'Authorization: Bearer '.$secret => 'header',   // the KEY is the credential
    ]));

    expect($payload)->not->toContain($secret);
});

it('does NOT over-redact ordinary text a log reader needs', function (string $text) {
    // A redactor that eats hashes, ids and base64 is worse than none: it silently corrupts the
    // log it exists to protect. Each of these is a real thing this project's logs carry.
    //
    // Compared on the DECODED command, not the raw JSON: json_encode() escapes "/" as "\/", so
    // a substring assertion on a URL can never match and would pass for the wrong reason.
    $stored = json_decode(secretProbePayload(secretProbeTurn(['command' => $text])), true);

    expect($stored['input']['command'])->toBe($text);
})->with([
    'commit hash' => 'git show 8a3f2b1c9d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8',
    'sha256' => 'verify 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
    'base64' => 'echo aGVsbG8gd29ybGQgdGhpcyBub3QgYSBzZWNyZXQ=',
    'url with query' => 'curl https://example.com/api/v1/users?limit=100&offset=20',
    'prose with the word token' => 'see README line 42 for the token budget',
]);

it('a scrubbed tool_call is still a usable record of what ran', function () {
    $payload = json_decode(secretProbePayload(secretProbeTurn([
        'command' => 'export AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE && ./deploy.sh --prod',
    ])), true);

    expect($payload['tool'])->toBe('run_shell')
        ->and($payload['ok'])->toBeTrue()
        // Command, the flag, and the structure all survive — only the credential is gone.
        ->and($payload['input']['command'])->toContain('deploy.sh')
        ->and($payload['input']['command'])->toContain('--prod')
        ->and($payload['input']['command'])->not->toContain('AKIAIOSFODNN7EXAMPLE');
});
