<?php

namespace App\Commands;

use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\CostComparison;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

/**
 * `paider cost` — token/spend usage per tier, read straight off the append-only
 * event log via CostLedger's projection. spend is summed from cost_usd priced at
 * write time; CostLedger never re-prices (LOCKED decision #2); unpriced calls are
 * excluded from spend and named below the table, per affected tier (LOCKED #3).
 *
 * --json emits the same computed arrays the table renders from — one data path,
 * so the two can't drift apart.
 */
class CostCommand extends Command
{
    /** README says "all-Opus 5" specifically — a fixed reference, not preset-derived. */
    private const COMPARISON_MODEL = 'anthropic/claude-opus-5';

    protected $signature = 'cost {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Show token/spend usage per tier from the event log';

    public function handle(): int
    {
        $eventLog = new EventLog(Database::connect());
        $summary = (new CostLedger($eventLog))->summary();

        $tiers = array_diff_key($summary, ['session' => null]);

        // Empty ledger (no tier_call events at all) is a distinct branch from "calls
        // exist but none priced" — the latter falls through to the table below with
        // a real (empty) session spend and per-tier caveats.
        if ($tiers === []) {
            render(<<<'HTML'
                <div class="px-1 my-1">no usage recorded yet — run `paider chat` or `paider commit` to start one</div>
            HTML);

            return Command::SUCCESS;
        }

        $session = $summary['session'];
        $sessionSpend = $session['spend_usd'];

        foreach ($tiers as &$row) {
            $row['share_pct'] = $sessionSpend > 0.0 ? round($row['spend_usd'] / $sessionSpend * 100, 1) : null;
        }
        unset($row);

        $unpriced = [];
        foreach ($tiers as $tier => $row) {
            if ($row['unpriced_calls'] > 0) {
                $unpriced[] = [
                    'tier' => $tier,
                    'count' => $row['unpriced_calls'],
                    'calls' => $row['calls'],
                    'models' => $row['unpriced_models'],
                ];
            }
        }

        $comparison = CostComparison::compare($summary, self::COMPARISON_MODEL);

        if ($this->option('json')) {
            $this->line(json_encode([
                'tiers' => $tiers,
                'session' => $session,
                'unpriced_calls' => $unpriced,
                'comparison' => $comparison,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = '';
        foreach ($tiers as $tier => $row) {
            $rows .= $this->row($tier, $row);
        }
        $rows .= $this->row('session', $session, isSession: true);

        render(<<<HTML
            <div class="my-1">
                <table>
                    <thead>
                        <tr><th class="px-1">tier</th><th class="px-1">calls</th><th class="px-1">tokens in</th><th class="px-1">tokens out</th><th class="px-1">spend</th><th class="px-1">share</th></tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
        HTML);

        foreach ($unpriced as $entry) {
            $models = implode(', ', $entry['models']);
            render('<div class="px-1">'.e(
                "{$entry['count']} of {$entry['calls']} {$entry['tier']} calls not priced (unknown model: {$models}) — totals exclude them."
            ).'</div>');
        }

        // Session spend of exactly 0 means either nothing was priced, or nothing was
        // spent — either way a ratio or a "you saved" figure derived from it would be
        // a fabricated result, not a measurement (LOCKED decision #3's spirit).
        if ($sessionSpend > 0.0) {
            if ($comparison['token_share_pct'] !== null && $comparison['spend_share_pct'] !== null) {
                render('<div class="px-1">'.e(sprintf(
                    '%s%% of your tokens went through tiers costing %s%% of your spend.',
                    number_format($comparison['token_share_pct'], 1),
                    number_format($comparison['spend_share_pct'], 1)
                )).'</div>');
            }

            if ($comparison['hypothetical_usd'] !== null && $comparison['saved_usd'] !== null) {
                render('<div class="px-1">'.e(sprintf(
                    'Same work on all-Opus 5: $%.2f · you saved $%.2f',
                    $comparison['hypothetical_usd'],
                    $comparison['saved_usd']
                )).'</div>');
            }
        }

        return Command::SUCCESS;
    }

    private function row(string $tier, array $row, bool $isSession = false): string
    {
        // A tier with unpriced calls must never render as a bare, confident $0.000 — that's
        // the exact silent-zero bug this feature exists to kill (LOCKED decision #3).
        $spend = $row['unpriced_calls'] > 0
            ? sprintf('$%.3f*', $row['spend_usd'])
            : sprintf('$%.3f', $row['spend_usd']);

        // The session row's own call count and share are redundant (it's the whole),
        // so they render blank — mirroring the README mockup's layout.
        $calls = $isSession ? '' : (string) $row['calls'];
        $share = $isSession ? '' : ($row['share_pct'] === null ? '—' : number_format($row['share_pct'], 1).'%');

        return sprintf(
            '<tr><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td><td class="px-1">%s</td></tr>',
            e($tier),
            e($calls),
            e($this->formatCount($row['tokens_in'])),
            e($this->formatCount($row['tokens_out'])),
            e($spend),
            e($share)
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
