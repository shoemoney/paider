<?php

namespace App\Commands;

use App\Agent\TierRouter;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResolver;
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
                'tokens_cache_write' => $response->cacheWrite,
                'tokens_cache_read' => $response->cacheRead,
                'cost_usd' => ModelPricing::costFor($servedModel, $response->tokensIn, $response->tokensOut, $response->cacheWrite, $response->cacheRead),
                // Frozen at write time, same as cost_usd -- see Loop.php's identical field.
                'hypothetical_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, $response->tokensIn, $response->tokensOut, $response->cacheWrite, $response->cacheRead),
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
     * inject a fake without a real network call. Delegates to ProviderResolver,
     * shared with ChatCommand::resolveProvider(), so a preset resolves to the
     * identical endpoint and key in both commands.
     */
    protected function providerClient(string $presetName): ProviderClient
    {
        return ProviderResolver::forPreset($presetName);
    }

    /**
     * Thin delegate to ProviderResolver::qwenBaseUrl() -- kept here so the existing
     * reflection-based test in tests/Feature/CommitCommandTest.php (which invokes
     * CommitCommand::qwenBaseUrl() directly) keeps passing unmodified.
     */
    private function qwenBaseUrl(): string
    {
        return ProviderResolver::qwenBaseUrl();
    }
}
