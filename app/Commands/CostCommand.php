<?php

namespace App\Commands;

use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

/**
 * `paider cost` — token/spend usage per tier, read straight off the append-only
 * event log via CostLedger's projection. cost_usd is 0.0 on every tier_call today
 * (see the comment in Loop.php / CommitCommand.php: presets.php prices are
 * informational, not a billing feed) so spend here reflects that, deliberately.
 */
class CostCommand extends Command
{
    protected $signature = 'cost';

    protected $description = 'Show token/spend usage per tier from the event log';

    public function handle(): int
    {
        $eventLog = new EventLog(Database::connect());
        $summary = (new CostLedger($eventLog))->summary();

        $tiers = array_diff_key($summary, ['session' => null]);

        if ($tiers === []) {
            render(<<<'HTML'
                <div class="px-1 my-1">no usage recorded yet</div>
            HTML);

            return Command::SUCCESS;
        }

        $rows = '';

        foreach ($tiers as $tier => $row) {
            $rows .= $this->row($tier, $row);
        }

        $rows .= $this->row('session', $summary['session']);

        render(<<<HTML
            <div class="my-1">
                <table>
                    <thead>
                        <tr><th class="px-1">tier</th><th class="px-1">calls</th><th class="px-1">tokens in</th><th class="px-1">tokens out</th><th class="px-1">spend</th></tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
        HTML);

        if ($summary['session']['calls'] > 0 && $summary['session']['spend_usd'] === 0.0) {
            render('<div class="px-1">spend not priced yet — tier_call events currently log cost_usd 0.0</div>');
        }

        return Command::SUCCESS;
    }

    private function row(string $tier, array $row): string
    {
        return sprintf(
            '<tr><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td></tr>',
            e($tier),
            e((string) $row['calls']),
            e($this->formatCount($row['tokens_in'])),
            e($this->formatCount($row['tokens_out'])),
            e(sprintf('$%.3f', $row['spend_usd']))
        );
    }

    /** Mirrors the README mockup's 61.2k / 1.4M style, without chasing an exact match. */
    private function formatCount(int $n): string
    {
        if ($n >= 1_000_000) {
            return number_format($n / 1_000_000, 1).'M';
        }

        if ($n >= 1_000) {
            return number_format($n / 1_000, 1).'k';
        }

        return (string) $n;
    }
}
