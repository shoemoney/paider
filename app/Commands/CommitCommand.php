<?php

namespace App\Commands;

use App\Agent\TierRouter;
use App\Providers\AnthropicClient;
use App\Providers\Contracts\ProviderClient;
use App\Providers\OpenAiCompatibleClient;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\ModelPricing;
use App\Tools\GitTool;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class CommitCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'commit';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Stage all changes and commit with an AI-generated message';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $git = new GitTool(getcwd());

        $unstaged = $git->execute(['op' => 'diff', 'staged' => false]);

        if (trim($unstaged->output) === '') {
            note('Nothing to commit.');

            return Command::SUCCESS;
        }

        $git->execute(['op' => 'add', 'path' => '.']);

        $staged = $git->execute(['op' => 'diff', 'staged' => true]);

        if (! $staged->ok) {
            error($staged->output);

            return Command::FAILURE;
        }

        $route = (new TierRouter)->resolve('commit-msg');

        try {
            $client = $this->providerClient($route['provider']);

            $response = $client->send([
                ['role' => 'user', 'content' => "Write a concise, conventional commit message for this diff:\n\n{$staged->output}"],
            ], $route['model']);

            // See Loop.php's identical comment: 'model' names what was actually billed,
            // the served id, not the requested one — frozen at write time.
            $requestedModel = $route['model'];
            $servedModel = $response->servedModel ?? $requestedModel;

            (new EventLog(Database::connect()))->append('tier_call', [
                'tier' => 'fast',
                'provider' => $route['provider'],
                'model' => $servedModel,
                'requested_model' => $requestedModel,
                'tokens_in' => $response->tokensIn,
                'tokens_out' => $response->tokensOut,
                'cost_usd' => ModelPricing::costFor($servedModel, $response->tokensIn, $response->tokensOut),
                // Frozen at write time, same as cost_usd -- see Loop.php's identical field.
                'hypothetical_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, $response->tokensIn, $response->tokensOut),
            ]);

            $message = trim($response->content);

            $committed = $git->execute(['op' => 'commit', 'message' => $message]);

            if (! $committed->ok) {
                error($committed->output);

                return Command::FAILURE;
            }

            note($message);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Builds the ProviderClient for the resolved preset. Overridable so tests can
     * inject a fake without a real network call. See PLAN.md's Provider layer table
     * for the base URL / env var per preset.
     */
    protected function providerClient(string $presetName): ProviderClient
    {
        return match ($presetName) {
            'anthropic' => new AnthropicClient,
            'kimi' => new OpenAiCompatibleClient('https://api.moonshot.ai/v1', 'MOONSHOT_API_KEY'),
            'deepseek' => new OpenAiCompatibleClient('https://api.deepseek.com', 'DEEPSEEK_API_KEY'),
            'qwen' => new OpenAiCompatibleClient('https://dashscope.aliyuncs.com/compatible-mode/v1', 'DASHSCOPE_API_KEY'),
            'xai' => new OpenAiCompatibleClient('https://api.x.ai/v1', 'XAI_API_KEY'),
            'glm' => new OpenAiCompatibleClient('https://open.bigmodel.cn/api/paas/v4', 'GLM_API_KEY'),
            // openai, google, open, open-frugal, balanced: no documented direct
            // endpoint in PLAN.md's Provider layer table, fall back to OpenRouter.
            default => new OpenAiCompatibleClient('https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY'),
        };
    }
}
