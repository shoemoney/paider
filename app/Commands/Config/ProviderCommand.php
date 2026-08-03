<?php

namespace App\Commands\Config;

use App\Support\SettingsStore;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

/**
 * `paider config:provider <preset>` — sets the active tier stack.
 */
class ProviderCommand extends Command
{
    protected $signature = 'config:provider {preset}';

    protected $description = 'Set the active provider preset (tier stack)';

    public function handle(): int
    {
        $preset = $this->argument('preset');

        try {
            SettingsStore::setActivePreset($preset);
        } catch (\InvalidArgumentException) {
            $valid = array_keys(array_diff_key(config('presets'), ['accounts' => null]));

            error("Unknown preset '{$preset}'. Valid presets: ".implode(', ', $valid));

            return Command::FAILURE;
        }

        note("Active preset set to '{$preset}'.");

        return Command::SUCCESS;
    }
}
