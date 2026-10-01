<?php

namespace App\Commands\Config;

use App\Agent\TierRouter;
use App\Support\ColorRole;
use App\Support\Palette;
use App\Support\SettingsStore;
use LaravelZero\Framework\Commands\Command;

/**
 * `paider config:show` — displays the model resolved for each tier under the
 * active preset, plus a best-effort price pulled from the config/presets.php
 * source comment (informational only, see config/presets.php).
 */
class ShowCommand extends Command
{
    protected $signature = 'config:show';

    protected $description = 'Show the active preset and the model resolved for each tier';

    /** One representative operation per tier, per TierRouter::OPERATION_TIERS. */
    private const TIER_OPERATIONS = [
        'orchestrator' => 'plan',
        'coder' => 'edit',
        'research' => 'search',
        'fast' => 'commit-msg',
    ];

    public function handle(TierRouter $router): int
    {
        $preset = SettingsStore::activePreset();
        $presetClass = Palette::tw(ColorRole::Success);
        $sourceLines = @file(config_path('presets.php'));

        $rows = '';

        foreach (self::TIER_OPERATIONS as $tier => $operation) {
            $model = $router->resolve($operation)['model'];
            $price = $sourceLines !== false
                ? $this->priceFor($sourceLines, $preset, $tier)
                : null;

            $rows .= sprintf(
                '<tr><td class="px-1">%s</td><td class="px-1">%s</td>'
                .'<td class="px-1" align="right">%s</td><td class="px-1" align="right">%s</td></tr>',
                e($tier),
                e($model ?? '—'),
                e($price === null ? '—' : '$'.$price['in']),
                e($price === null ? '—' : '$'.$price['out'])
            );
        }

        Palette::render(<<<HTML
            <div class="my-1">
                <div class="px-1 mb-1">Active preset: <span class="{$presetClass}">{$preset}</span></div>
                <table>
                    <thead>
                        <tr><th class="px-1">tier</th><th class="px-1">model</th><th class="px-1" align="right">in / 1M</th><th class="px-1" align="right">out / 1M</th></tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
        HTML);

        return Command::SUCCESS;
    }

    /**
     * Best-effort extraction of the trailing `// $X.XX / $Y.YY` price comment
     * from the source line declaring $tier's model under $preset. Reads the
     * raw file text rather than re-requiring config/presets.php, so it stays
     * a "read the comment" step, not a second config load.
     *
     * @param  string[]  $lines
     */
    /** @return array{in: string, out: string}|null */
    private function priceFor(array $lines, string $preset, string $tier): ?array
    {
        $currentPreset = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\'([a-zA-Z0-9_-]+)\'\s*=>\s*\[\s*$/', $line, $m)) {
                $currentPreset = $m[1];

                continue;
            }

            if ($currentPreset !== null && preg_match('/^\s*],?\s*$/', $line)) {
                $currentPreset = null;

                continue;
            }

            if ($currentPreset !== $preset) {
                continue;
            }

            $tierPattern = preg_quote($tier, '/');

            // Both halves are captured separately. Returning the raw "$5.00 / $25.00" string
            // left the reader to guess which was input and which was output — and on a table whose
            // whole job is choosing a model by cost, guessing wrong is expensive. Caught by a blind
            // visual review as an "ambiguous header".
            if (preg_match(
                "/^\s*'{$tierPattern}'\s*=>\s*'[^']+'.*?\/\/\s*\\\$([0-9.]+)\s*\/\s*\\\$([0-9.]+)/",
                $line,
                $m
            )) {
                return ['in' => $m[1], 'out' => $m[2]];
            }
        }

        return null;
    }
}
