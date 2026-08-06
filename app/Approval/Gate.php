<?php

namespace App\Approval;

use App\Storage\ProjectEnv;

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

    /**
     * Accepts 1/true/on/yes in any case; anything else — including unset — is off.
     *
     * Read from the REAL environment only, never a project file. This used to go through
     * ProjectEnv::bool(), which also reads <project>/.paider/.env — meaning a repository could
     * ship a file that turned auto-approval on for anyone who cloned it and ran Paider inside.
     * That is unprompted shell execution granted by the code under review to itself. A project
     * may state preferences; it may not grant itself permissions.
     */
    public static function enabledInEnvironment(): bool
    {
        return (bool) filter_var(ProjectEnv::fromEnvironment(self::ENV_VAR), FILTER_VALIDATE_BOOL);
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
