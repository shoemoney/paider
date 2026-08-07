<?php

use App\Commands\ChatCommand;
use App\Commands\CommitCommand;
use App\Providers\AnthropicClient;
use App\Providers\Contracts\ProviderClient;
use App\Support\SettingsStore;

/**
 * Parity coverage for the shared ProviderResolver: ChatCommand::resolveProvider()
 * and CommitCommand::providerClient() must resolve every preset to the identical
 * endpoint and key. Both entry points are driven through reflection on real
 * instances (not by calling ProviderResolver directly) so this actually catches
 * the failure mode that prompted it — a command wired to something other than
 * the shared resolver.
 */
function withProviderRoutingEnv(array $vars, callable $callback): mixed
{
    $keys = ['ANTHROPIC_API_KEY', 'OPENROUTER_API_KEY', 'MOONSHOT_API_KEY', 'DEEPSEEK_API_KEY',
        'XAI_API_KEY', 'GLM_API_KEY', 'DASHSCOPE_API_KEY', 'DASHSCOPE_PLAN_BASE_URL'];

    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
    }

    foreach ($keys as $key) {
        putenv(array_key_exists($key, $vars) ? "{$key}={$vars[$key]}" : $key);
    }

    try {
        return $callback();
    } finally {
        foreach ($keys as $key) {
            putenv($previous[$key] === false ? $key : "{$key}={$previous[$key]}");
        }
    }
}

function resolveViaChat(string $preset): ProviderClient
{
    $originalCwd = getcwd();
    $root = sys_get_temp_dir().'/paider-provider-routing-'.uniqid();
    mkdir($root, recursive: true);
    chdir(realpath($root));

    try {
        SettingsStore::setActivePreset($preset);

        $command = new ChatCommand;
        $method = new ReflectionMethod(ChatCommand::class, 'resolveProvider');
        $method->setAccessible(true);

        return $method->invoke($command);
    } finally {
        chdir($originalCwd);
    }
}

function resolveViaCommit(string $preset): ProviderClient
{
    $command = new CommitCommand;
    $method = new ReflectionMethod(CommitCommand::class, 'providerClient');
    $method->setAccessible(true);

    return $method->invoke($command, $preset);
}

/**
 * @return array{0: ?string, 1: ?string} [class-specific identity, key env var/apiKey]
 */
function fingerprint(ProviderClient $client): array
{
    if ($client instanceof AnthropicClient) {
        return ['anthropic', null];
    }

    $ref = new ReflectionClass($client);
    $baseUrl = $ref->getProperty('baseUrl');
    $baseUrl->setAccessible(true);
    $envVar = $ref->getProperty('apiKeyEnvVar');
    $envVar->setAccessible(true);

    return [$baseUrl->getValue($client), $envVar->getValue($client)];
}

it('every preset resolves to the identical endpoint and key whether reached via chat or commit', function (string $preset) {
    $vars = ['ANTHROPIC_API_KEY' => 'a', 'OPENROUTER_API_KEY' => 'b', 'MOONSHOT_API_KEY' => 'c',
        'DEEPSEEK_API_KEY' => 'd', 'XAI_API_KEY' => 'e', 'GLM_API_KEY' => 'f',
        'DASHSCOPE_API_KEY' => 'sk-payg-g'];

    withProviderRoutingEnv($vars, function () use ($preset) {
        $chat = fingerprint(resolveViaChat($preset));
        $commit = fingerprint(resolveViaCommit($preset));

        expect($chat)->toBe($commit);
    });
})->with(['anthropic', 'kimi', 'deepseek', 'qwen', 'xai', 'glm', 'openai', 'google', 'open', 'open-frugal', 'balanced']);

it('a direct-endpoint preset with only its own key set uses the direct endpoint, in both commands', function () {
    withProviderRoutingEnv(['DEEPSEEK_API_KEY' => 'only-key'], function () {
        [$baseUrl, $envVar] = fingerprint(resolveViaChat('deepseek'));

        expect($baseUrl)->toBe('https://api.deepseek.com')
            ->and($envVar)->toBe('DEEPSEEK_API_KEY');

        expect(fingerprint(resolveViaCommit('deepseek')))->toBe([$baseUrl, $envVar]);
    });
});

it('a preset with a direct endpoint falls back to OpenRouter when only OPENROUTER_API_KEY is set', function (string $preset) {
    withProviderRoutingEnv(['OPENROUTER_API_KEY' => 'only-key'], function () use ($preset) {
        [$baseUrl, $envVar] = fingerprint(resolveViaChat($preset));

        expect($baseUrl)->toBe('https://openrouter.ai/api/v1')
            ->and($envVar)->toBe('OPENROUTER_API_KEY');

        expect(fingerprint(resolveViaCommit($preset)))->toBe([$baseUrl, $envVar]);
    });
})->with(['kimi', 'deepseek', 'xai', 'glm']);

it('a preset with no documented direct endpoint always resolves to OpenRouter', function (string $preset) {
    withProviderRoutingEnv(['OPENROUTER_API_KEY' => 'only-key'], function () use ($preset) {
        expect(fingerprint(resolveViaChat($preset)))->toBe(['https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY']);
        expect(fingerprint(resolveViaCommit($preset)))->toBe(['https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY']);
    });
})->with(['openai', 'google', 'open', 'open-frugal', 'balanced']);

it('the anthropic preset always resolves to AnthropicClient regardless of which other keys are set', function () {
    withProviderRoutingEnv(['OPENROUTER_API_KEY' => 'x', 'ANTHROPIC_API_KEY' => 'y'], function () {
        expect(resolveViaChat('anthropic'))->toBeInstanceOf(AnthropicClient::class);
        expect(resolveViaCommit('anthropic'))->toBeInstanceOf(AnthropicClient::class);
    });
});

it('qwen plan-key routing survives the shared resolver identically to before', function () {
    $plan = 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1';

    withProviderRoutingEnv(['DASHSCOPE_API_KEY' => 'sk-sp-abc', 'DASHSCOPE_PLAN_BASE_URL' => $plan], function () use ($plan) {
        expect(fingerprint(resolveViaChat('qwen')))->toBe([$plan, 'DASHSCOPE_API_KEY']);
        expect(fingerprint(resolveViaCommit('qwen')))->toBe([$plan, 'DASHSCOPE_API_KEY']);
    });
});

it('a qwen plan key with no plan URL falls back to OpenRouter when OPENROUTER_API_KEY is set, no throw', function () {
    withProviderRoutingEnv(['DASHSCOPE_API_KEY' => 'sk-sp-abc', 'OPENROUTER_API_KEY' => 'x'], function () {
        expect(fingerprint(resolveViaChat('qwen')))->toBe(['https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY']);
        expect(fingerprint(resolveViaCommit('qwen')))->toBe(['https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY']);
    });
});

it('a qwen plan key with no plan URL and no OPENROUTER_API_KEY still throws the plan-key RuntimeException', function () {
    withProviderRoutingEnv(['DASHSCOPE_API_KEY' => 'sk-sp-abc'], function () {
        expect(fn () => resolveViaChat('qwen'))->toThrow(RuntimeException::class, 'DASHSCOPE_PLAN_BASE_URL');
        expect(fn () => resolveViaCommit('qwen'))->toThrow(RuntimeException::class, 'DASHSCOPE_PLAN_BASE_URL');
    });
});
