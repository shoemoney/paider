<?php

use App\Commands\CommitCommand;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A ProviderClient double that records how many times it was called and either
 * returns a fixed response or throws, so a test can assert both "the provider was
 * never hit" (the clean-repo early-out) and "a provider failure leaves the tree
 * staged but uncommitted".
 */
class SpyProviderClient implements ProviderClient
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ProviderResponse $response = null,
        private readonly ?Throwable $throws = null,
    ) {}

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->response;
    }
}

class FakeCommitCommand extends CommitCommand
{
    public function __construct(private readonly ProviderClient $client)
    {
        parent::__construct();
    }

    protected function providerClient(string $presetName): ProviderClient
    {
        return $this->client;
    }
}

/**
 * Runs a command instance the same way Artisan would, without going through Artisan's
 * command resolution — Laravel Zero's kernel resolves + caches the real CommitCommand
 * out of the container during app bootstrap (before a test body gets a chance to bind
 * a fake), so container-instance swapping arrives too late. Driving Symfony's own
 * run() directly sidesteps that and is exactly what the injected-fake requirement
 * calls for.
 */
function runCommit(CommitCommand $command, $app): int
{
    $command->setLaravel($app);

    return $command->run(new ArrayInput([]), new NullOutput);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-commit-'.uniqid();
    mkdir($this->root, recursive: true);

    exec('git init '.escapeshellarg($this->root).' 2>&1');
    exec('git -C '.escapeshellarg($this->root).' config user.email test@example.com 2>&1');
    exec('git -C '.escapeshellarg($this->root).' config user.name Test 2>&1');

    $this->root = realpath($this->root);
    $this->previousCwd = getcwd();
    chdir($this->root);
});

afterEach(function () {
    chdir($this->previousCwd);
});

test('an unmodified repo exits SUCCESS with no commit and no provider call', function () {
    $spy = new SpyProviderClient(response: new ProviderResponse('unused', 0, 0, []));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(0);
    expect($spy->calls)->toBe(0);

    exec('git -C '.escapeshellarg($this->root).' rev-list --all --count', $output);
    expect(trim($output[0] ?? ''))->toBe('0');
});

test('a dirty working tree produces a real commit matching the fake provider response', function () {
    // git diff (unstaged) never shows a brand-new untracked file, only a change to
    // a tracked one — seed and commit the file first, same as GitToolTest.
    file_put_contents($this->root.'/tracked.txt', 'original');
    exec('git -C '.escapeshellarg($this->root).' add tracked.txt 2>&1');
    exec('git -C '.escapeshellarg($this->root).' commit -m seed 2>&1');
    file_put_contents($this->root.'/tracked.txt', 'changed');

    $spy = new SpyProviderClient(response: new ProviderResponse('feat: add new file', 12, 4, []));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(0);
    expect($spy->calls)->toBe(1);

    exec('git -C '.escapeshellarg($this->root).' log -1 --format=%s', $output);
    expect($output[0] ?? '')->toBe('feat: add new file');

    // .paider/ (the event log's SQLite db, created as a side effect of the tier_call
    // append) is untracked and expected — only tracked.txt's changes must be gone.
    exec('git -C '.escapeshellarg($this->root).' status --porcelain', $statusOutput);
    expect($statusOutput)->not->toContain('M  tracked.txt')
        ->and($statusOutput)->not->toContain(' M tracked.txt');
});

test("the fast tier_call event is priced from \$route['model'], not the tier or preset name", function () {
    file_put_contents($this->root.'/tracked.txt', 'original');
    exec('git -C '.escapeshellarg($this->root).' add tracked.txt 2>&1');
    exec('git -C '.escapeshellarg($this->root).' commit -m seed 2>&1');
    file_put_contents($this->root.'/tracked.txt', 'changed');

    $spy = new SpyProviderClient(response: new ProviderResponse('feat: add new file', 12, 4, []));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(0);

    $tierCalls = array_values(array_filter(
        (new EventLog(Database::connect()))->all(),
        fn ($event) => $event['type'] === 'tier_call'
    ));

    expect($tierCalls)->toHaveCount(1);

    // Default preset is 'balanced' (no .paider/settings.json in this fresh repo), whose
    // fast tier is deepseek/deepseek-v4-flash at $0.14/$0.28 per Mtok (config/presets.php).
    // The expected cost is computed independently here, not via ModelPricing::costFor(), so
    // this fails if CommitCommand ever passes the wrong argument (e.g. the tier name
    // 'fast' or the preset name 'balanced') instead of $route['model'].
    $expectedCost = 12 / 1e6 * 0.14 + 4 / 1e6 * 0.28;

    expect($tierCalls[0]['payload']['model'])->toBe('deepseek/deepseek-v4-flash')
        ->and($tierCalls[0]['payload']['cost_usd'])->toBe($expectedCost);
});

test('a whitespace-only commit message from the provider leaves the tree staged but uncommitted and returns FAILURE', function () {
    file_put_contents($this->root.'/tracked.txt', 'original');
    exec('git -C '.escapeshellarg($this->root).' add tracked.txt 2>&1');
    exec('git -C '.escapeshellarg($this->root).' commit -m seed 2>&1');
    file_put_contents($this->root.'/tracked.txt', 'changed');

    $spy = new SpyProviderClient(response: new ProviderResponse("   \n  ", 12, 4, []));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(1);
    expect($spy->calls)->toBe(1);

    exec('git -C '.escapeshellarg($this->root).' rev-list --all --count', $output);
    expect(trim($output[0] ?? ''))->toBe('1');

    exec('git -C '.escapeshellarg($this->root).' status --porcelain', $statusOutput);
    expect($statusOutput[0] ?? '')->toBe('M  tracked.txt');
});

test('an unignored .env staged alongside a tracked change fails closed with no commit', function () {
    file_put_contents($this->root.'/tracked.txt', 'original');
    exec('git -C '.escapeshellarg($this->root).' add tracked.txt 2>&1');
    exec('git -C '.escapeshellarg($this->root).' commit -m seed 2>&1');
    file_put_contents($this->root.'/tracked.txt', 'changed');
    file_put_contents($this->root.'/.env', 'SECRET=xyz');

    $spy = new SpyProviderClient(response: new ProviderResponse('feat: add new file', 12, 4, []));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(1);
    expect($spy->calls)->toBe(0);

    exec('git -C '.escapeshellarg($this->root).' rev-list --all --count', $output);
    expect(trim($output[0] ?? ''))->toBe('1');

    exec('git -C '.escapeshellarg($this->root).' status --porcelain', $statusOutput);
    expect($statusOutput)->toContain('M  tracked.txt')
        ->and($statusOutput)->toContain('A  .env');
});

test('a provider failure leaves the tree staged but uncommitted and returns FAILURE', function () {
    file_put_contents($this->root.'/tracked.txt', 'original');
    exec('git -C '.escapeshellarg($this->root).' add tracked.txt 2>&1');
    exec('git -C '.escapeshellarg($this->root).' commit -m seed 2>&1');
    file_put_contents($this->root.'/tracked.txt', 'changed');

    $spy = new SpyProviderClient(throws: new RuntimeException('simulated provider failure'));

    $exitCode = runCommit(new FakeCommitCommand($spy), $this->app);

    expect($exitCode)->toBe(1);
    expect($spy->calls)->toBe(1);

    exec('git -C '.escapeshellarg($this->root).' rev-list --all --count', $output);
    expect(trim($output[0] ?? ''))->toBe('1');

    exec('git -C '.escapeshellarg($this->root).' status --porcelain', $statusOutput);
    expect($statusOutput[0] ?? '')->toBe('M  tracked.txt');
});

it('routes a qwen plan key to the plan endpoint, and a payg key to payg', function () {
    $call = function (string $key, string $planUrl) {
        putenv($key === '' ? 'DASHSCOPE_API_KEY' : "DASHSCOPE_API_KEY={$key}");
        putenv($planUrl === '' ? 'DASHSCOPE_PLAN_BASE_URL' : "DASHSCOPE_PLAN_BASE_URL={$planUrl}");

        $m = new ReflectionMethod(\App\Commands\CommitCommand::class, 'qwenBaseUrl');
        $m->setAccessible(true);

        return $m->invoke(app(\App\Commands\CommitCommand::class));
    };

    // A pay-as-you-go key keeps the PAYG endpoint.
    expect($call('sk-payg-abc', ''))->toBe('https://dashscope.aliyuncs.com/compatible-mode/v1');

    // A plan key with the plan URL configured uses it.
    $plan = 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1';
    expect($call('sk-sp-abc', $plan))->toBe($plan);

    // A plan key with NO plan URL must not silently fall back to PAYG — that bills
    // per token against a plan already paid for, which is the whole failure mode
    // QwenPlanKeyGuard exists to catch, arriving one layer earlier.
    expect(fn () => $call('sk-sp-abc', ''))
        ->toThrow(RuntimeException::class, 'DASHSCOPE_PLAN_BASE_URL');

    putenv('DASHSCOPE_API_KEY');
    putenv('DASHSCOPE_PLAN_BASE_URL');
});
