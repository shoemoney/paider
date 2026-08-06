<?php

namespace App\Storage;

/**
 * Pure read-side projection over EventLog, same shape as CostLedger: holds no state, is never
 * mutated, and forgetting is an event rather than a delete. Conversation history is rebuilt by
 * replaying `session_*` events in insertion order.
 *
 * v0.2 has ONE implicit session per project — the whole project log is the conversation.
 * Addressable, named sessions are v0.3 (PLAN.md, "Session identity"), and nothing here should
 * anticipate that shape beyond leaving room for it.
 */
class SessionStore
{
    public const MESSAGE = 'session_message';

    public const FILE_ADDED = 'session_file_added';

    public const FILE_DROPPED = 'session_file_dropped';

    /**
     * Replaying an unbounded log would grow the prompt without limit and re-bill every earlier
     * turn on the first message of every later run. The window is a cost ceiling, not a
     * correctness one — see resumeWindow() for how it is configured.
     */
    public const DEFAULT_WINDOW = 50;

    public function __construct(private readonly EventLog $events) {}

    /**
     * The stored conversation, oldest first, trimmed to the most recent $window entries.
     *
     * The system message from PAIDER.md/CLAUDE.md/AGENTS.md is deliberately NOT among these —
     * Session's constructor re-derives it from disk on every run. Replaying a stored copy on
     * top of that would double-post it (PLAN.md names this gotcha), and would also pin a stale
     * copy of a file the user has since edited. Re-reading is both simpler and more correct.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(?int $window = null): array
    {
        $window ??= self::resumeWindow();

        // Checked before the scan, not folded into the slice below: a `$window > 0` guard on
        // the slice alone falls through to "return everything", which is the exact opposite of
        // what 0 means here.
        if ($window <= 0) {
            return [];
        }

        $messages = [];

        foreach ($this->events->stream() as $event) {
            if ($event['type'] !== self::MESSAGE) {
                continue;
            }

            $role = $event['payload']['role'] ?? null;
            $content = $event['payload']['content'] ?? null;

            // A malformed row is skipped rather than thrown on: the log is append-only and
            // may hold rows written by an older build, and one bad row must not make an
            // otherwise good conversation unresumable.
            if (! is_string($role) || ! is_string($content) || $role === 'system') {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        return count($messages) > $window ? array_slice($messages, -$window) : $messages;
    }

    /**
     * Paths the user has `/add`ed and not `/drop`ed, in the order they were first added.
     * Only the path is projected — the CONTENT is re-read from disk on resume, because a file
     * added three days ago has almost certainly changed, and PatchFileTool's sha256 stamp must
     * describe what is on disk now or no patch it produces will ever apply.
     *
     * @return array<int, string>
     */
    public function contextFiles(): array
    {
        $paths = [];

        foreach ($this->events->stream() as $event) {
            $path = $event['payload']['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            if ($event['type'] === self::FILE_ADDED) {
                $paths[$path] = true;
            } elseif ($event['type'] === self::FILE_DROPPED) {
                unset($paths[$path]);
            }
        }

        return array_keys($paths);
    }

    public function isEmpty(): bool
    {
        foreach ($this->events->stream() as $event) {
            if ($event['type'] === self::MESSAGE) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many stored messages a resume replays. 0 disables resume entirely, which is the
     * escape hatch for anyone who does not want a conversation surviving process exit at all.
     */
    public static function resumeWindow(): int
    {
        $value = ProjectEnv::get('PAIDER_RESUME_MESSAGES');

        if ($value === null || ! is_numeric($value)) {
            return self::DEFAULT_WINDOW;
        }

        return max(0, (int) $value);
    }
}
