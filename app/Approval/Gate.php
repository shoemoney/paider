<?php

namespace App\Approval;

/**
 * In-memory-only approval state. Nothing here survives process exit, and the only
 * thing ever stored is a grant — there is no way to read a cached 'deny' as though
 * it were an allow (that would conflate deny-list and allow-list semantics).
 *
 * Auto-approve ("yolo") stops the prompting and nothing else. It is deliberately scoped to
 * this class because this class only ever answers "did a human say yes" — the guards that
 * decide what is reachable at all (PathGuard on the project root, UrlGuard on private
 * addresses, SecretsGuard on what counts as sensitive) run elsewhere and are unaffected. A
 * yolo session still cannot write outside the project or fetch the LAN.
 */
class Gate
{
    public const ENV_VAR = 'PAIDER_YOLO';

    /** @var array<string, true> */
    private array $sessionGrants = [];

    public function __construct(private readonly bool $autoApprove = false) {}

    /** $flagged is the CLI flag; the environment variable is the standing default. */
    public static function forSession(bool $flagged = false): self
    {
        return new self($flagged || self::enabledInEnvironment());
    }

    /** Accepts 1/true/on/yes in any case; anything else — including unset — is off. */
    public static function enabledInEnvironment(): bool
    {
        $value = getenv(self::ENV_VAR);

        return $value !== false && filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public function autoApproves(): bool
    {
        return $this->autoApprove;
    }

    public function decide(string $action, callable $prompt): bool
    {
        if ($this->autoApprove) {
            return true;
        }

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
