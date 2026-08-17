<?php

namespace App\Commands;

use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\CostComparison;
use App\Support\Palette;
use LaravelZero\Framework\Commands\Command;

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
    protected $signature = 'cost {--json : Emit machine-readable JSON instead of a table} {--session : Only show current session, not all-time}';

    protected $description = 'Show token/spend usage per tier from the event log';

    public function handle(): int
    {
        $eventLog = new EventLog(Database::connect());
        $sessionId = null;
        if ($this->option('session')) {
            // Find most recent session_id from stream (last session_start's id)
            $lastSessionId = null;
            foreach ($eventLog->stream() as $event) {
                if ($event['type'] === 'session_start') {
                    $lastSessionId = $event['payload']['session_id'] ?? null;
                } elseif (isset($event['payload']['session_id'])) {
                    $lastSessionId = $event['payload']['session_id'];
                }
            }
            $sessionId = $lastSessionId;
        }
        $summary = (new CostLedger($eventLog))->summary($sessionId);

        $tiers = array_diff_key($summary, ['session' => null]);

        // Empty ledger (no tier_call events at all) is a distinct branch from "calls
        // exist but none priced" — the latter falls through to the table below with
        // a real (empty) session spend and per-tier caveats. --json still needs the
        // real (zeroed) shape below, not human prose, so only short-circuit for the
        // table render.
        if ($tiers === [] && ! $this->option('json')) {
            if ($this->option('session')) {
                Palette::render(<<<'HTML'
                    <div class="px-1 my-1">no usage in this session — run `paider chat` or `paider commit` to start one</div>
                HTML);
            } else {
                Palette::render(<<<'HTML'
                    <div class="px-1 my-1">no usage recorded yet — run `paider chat` or `paider commit` to start one</div>
                HTML);
            }

            return Command::SUCCESS;
        }

        $session = $summary['session'];
        $sessionSpend = $session['spend_usd'];

        // Session spend already excludes unpriced calls by construction (LOCKED #3),
        // so with ANY unpriced call anywhere in the session, every row's share_pct
        // would be a fraction of a total known to be wrong -- not an estimate, a
        // number that can read as an inverted ratio (a tier that ate most of the real
        // spend showing 0%, a tier that ate almost none showing 100%). Null it session-
        // wide rather than only marking the offending row.
        $sharable = $sessionSpend > 0.0 && $session['unpriced_calls'] === 0;

        foreach ($tiers as &$row) {
            $row['share_pct'] = $sharable ? round($row['spend_usd'] / $sessionSpend * 100, 1) : null;
        }
        unset($row);

        $unpriced = $this->collectFlagged($tiers, 'unpriced_calls', 'unpriced_models');
        $mismatches = $this->collectFlagged($tiers, 'mismatched_calls', 'mismatched_models');

        $comparison = CostComparison::compare($summary);

        if ($this->option('json')) {
            $this->line(json_encode([
                'tiers' => (object) $tiers,
                'session' => $session,
                'unpriced_calls' => $unpriced,
                'model_mismatches' => $mismatches,
                'comparison' => $comparison,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = '';
        foreach ($tiers as $tier => $row) {
            $rows .= $this->row($tier, $row);
        }
        $rows .= $this->row('session', $session, isSession: true);

        // Finding 5: the total was buried below the table, after every per-tier row and
        // any unpriced/mismatch notes — a reader has to scan past all of it to find the
        // one number they came for. Print it first.
        $totalSpend = $session['unpriced_calls'] > 0
            ? sprintf('$%.3f*', $sessionSpend)
            : sprintf('$%.3f', $sessionSpend);
        Palette::render('<div class="px-1 mt-1">Total spend: '.e($totalSpend).'</div>');

        Palette::render(<<<HTML
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

        $prices = config('prices');
        foreach ($unpriced as $entry) {
            // A known model with 0/0 tokens (usage block never parsed) and a genuinely
            // unknown model id are different failures -- name the one that happened
            // instead of always blaming "unknown model" (ModelPricing::costFor()).
            $parts = array_map(
                fn ($model) => (array_key_exists($model, $prices) ? 'no usage reported' : 'unknown model').": {$model}",
                $entry['models']
            );
            Palette::render('<div class="px-1">'.e(
                "{$entry['count']} of {$entry['calls']} {$entry['tier']} calls not priced (".implode(', ', $parts).') — totals exclude them.'
            ).'</div>');
        }

        foreach ($mismatches as $entry) {
            Palette::render('<div class="px-1">'.e(
                "{$entry['count']} of {$entry['calls']} {$entry['tier']} calls served a different model than requested: ".implode(', ', $entry['models'])
            ).'</div>');
        }

        // Session spend of exactly 0 means either nothing was priced, or nothing was
        // spent — either way a ratio or a "you saved" figure derived from it would be
        // a fabricated result, not a measurement (LOCKED decision #3's spirit).
        if ($sessionSpend > 0.0) {
            if ($comparison['token_share_pct'] !== null && $comparison['spend_share_pct'] !== null) {
                Palette::render('<div class="px-1">'.e(sprintf(
                    '%s%% of your tokens went through tiers costing %s%% of your spend.',
                    number_format($comparison['token_share_pct'], 1),
                    number_format($comparison['spend_share_pct'], 1)
                )).'</div>');
            }

            if ($comparison['hypothetical_usd'] !== null && $comparison['saved_usd'] !== null) {
                // Float residue (e.g. -1e-13 from a 100%-Opus session) must not print as
                // "$-0.00", and a session pricier than the reference model per-token is a
                // real, reachable negative saving that reads backwards under "you saved".
                $saved = abs($comparison['saved_usd']) < 0.005 ? 0.0 : $comparison['saved_usd'];

                Palette::render('<div class="px-1">'.e($saved >= 0
                    ? sprintf('Same work on all-Opus 5: $%.2f · you saved $%.2f', $comparison['hypothetical_usd'], $saved)
                    : sprintf('Same work on all-Opus 5: $%.2f · this session cost $%.2f more', $comparison['hypothetical_usd'], -$saved)
                ).'</div>');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * $unpriced and $mismatches were the same tier-filtering loop with different keys —
     * one shared shape instead of two copies that could drift out of sync.
     *
     * @return list<array{tier: string, count: int, calls: int, models: array}>
     */
    private function collectFlagged(array $tiers, string $countKey, string $modelsKey): array
    {
        $flagged = [];
        foreach ($tiers as $tier => $row) {
            if ($row[$countKey] > 0) {
                $flagged[] = [
                    'tier' => $tier,
                    'count' => $row[$countKey],
                    'calls' => $row['calls'],
                    'models' => $row[$modelsKey],
                ];
            }
        }

        return $flagged;
    }

    private function row(string $tier, array $row, bool $isSession = false): string
    {
        // A tier with unpriced calls must never render as a bare, confident $0.000 — that's
        // the exact silent-zero bug this feature exists to kill (LOCKED decision #3).
        $spend = $row['unpriced_calls'] > 0
            ? sprintf('$%.3f*', $row['spend_usd'])
            : sprintf('$%.3f', $row['spend_usd']);

        // The session row's own call count and share are redundant (it's the whole),
        // so they render as a dash rather than blank cells a reader can mistake for
        // missing data (Finding 6).
        $calls = $isSession ? '—' : (string) $row['calls'];
        $share = $isSession ? '—' : ($row['share_pct'] === null ? '—' : number_format($row['share_pct'], 1).'%');

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
