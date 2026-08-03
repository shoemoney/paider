<?php

namespace App\Approval;

/**
 * In-memory-only approval state. Nothing here survives process exit, and the only
 * thing ever stored is a grant — there is no way to read a cached 'deny' as though
 * it were an allow (that would conflate deny-list and allow-list semantics).
 */
class Gate
{
    /** @var array<string, true> */
    private array $sessionGrants = [];

    public function decide(string $action, callable $prompt): bool
    {
        if (isset($this->sessionGrants[$action])) {
            return true;
        }

        $decision = $prompt();

        if ($decision === 'allow-session') {
            $this->sessionGrants[$action] = true;
        }

        return $decision === 'allow-once' || $decision === 'allow-session';
    }
}
