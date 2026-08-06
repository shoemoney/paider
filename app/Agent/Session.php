<?php

namespace App\Agent;

use App\Support\PathGuard;
use App\Tools\ReadFileTool;
use App\Tools\ToolResult;

/**
 * Holds one chat session's state: the files the user has `/add`ed to context, the
 * conversation history, per-session tier overrides, and the undo stack for applied
 * writes. Nothing here persists past process exit — v0.1 has no session storage layer.
 */
class Session
{
    private const TIERS = ['orchestrator', 'coder', 'research', 'fast'];

    private const CONTEXT_FILE_CANDIDATES = ['PAIDER.md', 'CLAUDE.md', 'AGENTS.md'];

    /** @var array<string, array{stamp: string, content: string}> */
    private array $files = [];

    /** @var array<int, array{role: string, content: string}> */
    private array $history = [];

    /** @var array<string, string> tier => model, session-only, never persisted */
    private array $tierOverrides = [];

    /**
     * @var array<int, array{path: string, previous: ?string, postApplyHash: ?string, sealed: bool}>
     */
    private array $undoStack = [];

    public function __construct(
        private readonly ReadFileTool $readFileTool,
        private readonly string $projectRoot,
    ) {
        foreach (self::CONTEXT_FILE_CANDIDATES as $candidate) {
            $result = $this->readFileTool->execute(['path' => $candidate]);

            if ($result->ok) {
                $this->pushHistory('system', $result->output);

                break;
            }
        }
    }

    public function addFile(string $path): ToolResult
    {
        $result = $this->readFileTool->execute(['path' => $path]);

        if (! $result->ok) {
            return $result;
        }

        $this->files[$path] = [
            'stamp' => hash('sha256', $result->output),
            'content' => $result->output,
        ];

        return $result;
    }

    public function dropFile(string $path): void
    {
        unset($this->files[$path]);
    }

    /** @return array<string, array{stamp: string, content: string}> */
    public function contextFiles(): array
    {
        return $this->files;
    }

    /**
     * Rehydrate a stored conversation. Called after construction, so the system message this
     * class derived from PAIDER.md/CLAUDE.md/AGENTS.md is already at the head of the history
     * and the replayed turns land after it in the right order.
     *
     * $messages must not contain a 'system' entry — SessionStore filters those out — or the
     * context file would be posted twice, once fresh from disk and once as a stale copy.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function replay(array $messages): void
    {
        foreach ($messages as $message) {
            $this->pushHistory($message['role'], $message['content']);
        }
    }

    public function pushHistory(string $role, string $content): void
    {
        $this->sealPendingUndo();

        $this->history[] = ['role' => $role, 'content' => $content];
    }

    /** @return array<int, array{role: string, content: string}> */
    public function history(): array
    {
        return $this->history;
    }

    public function setTierOverride(string $tier, string $model): void
    {
        if (! in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException("Unknown tier: {$tier}");
        }

        $this->tierOverrides[$tier] = $model;
    }

    /** @return array<string, string> */
    public function tierOverrides(): array
    {
        return $this->tierOverrides;
    }

    /**
     * Pushed by the caller (Loop) immediately before it dispatches a write/patch that is
     * about to apply. $previousContent is the file's content right now, or null if the
     * file doesn't exist yet (an undo of that entry deletes the file instead of restoring
     * bytes). The entry's post-apply hash isn't known yet — the write hasn't happened —
     * so it starts unsealed and is filled in lazily, see sealPendingUndo().
     */
    public function recordApply(string $path, ?string $previousContent): void
    {
        $this->sealPendingUndo();

        $this->undoStack[] = [
            'path' => $path,
            'previous' => $previousContent,
            'postApplyHash' => null,
            'sealed' => false,
        ];
    }

    /** @return array{status: 'ok'|'empty'|'conflict', path?: string} */
    public function undo(): array
    {
        if ($this->undoStack === []) {
            return ['status' => 'empty'];
        }

        // Nothing has touched the session since this entry was pushed (the common "apply
        // then immediately /undo" case) — sealing it right here is the only chance we get,
        // and it necessarily trusts whatever is on disk right now as the recorded state.
        $this->sealPendingUndo();

        $entry = array_pop($this->undoStack);
        $path = $entry['path'];

        // Belt-and-braces: Loop only ever pushes an entry after a successful, PathGuard-passed
        // write now, so this should never fire — but /undo mutates (deletes/overwrites) the raw
        // path with no approval prompt of its own, so it re-checks containment itself rather
        // than trusting the stack was built safely.
        if (! PathGuard::containedIn($this->projectRoot, $path)) {
            return ['status' => 'conflict', 'path' => $path];
        }

        $current = is_file($path) ? file_get_contents($path) : null;
        $currentHash = $current === null ? null : hash('sha256', $current);

        if ($currentHash !== $entry['postApplyHash']) {
            return ['status' => 'conflict', 'path' => $path];
        }

        if ($entry['previous'] === null) {
            if (is_file($path)) {
                unlink($path);
            }
        } else {
            file_put_contents($path, $entry['previous']);
        }

        return ['status' => 'ok', 'path' => $path];
    }

    /**
     * ponytail: the undo entry's post-apply hash can only be captured by reading the file
     * back off disk (recordApply fires before the write, so it never sees the new bytes).
     * Sealing it here — the first time ANY other session activity happens after the push —
     * means it's captured right after the real apply landed and before a later hand-edit
     * has a chance to happen, which is exactly the window /undo needs to detect. Only the
     * top entry can ever be unsealed, since sealing runs on every subsequent touch.
     */
    private function sealPendingUndo(): void
    {
        if ($this->undoStack === []) {
            return;
        }

        $top = count($this->undoStack) - 1;

        if ($this->undoStack[$top]['sealed']) {
            return;
        }

        $path = $this->undoStack[$top]['path'];
        $content = is_file($path) ? file_get_contents($path) : null;

        $this->undoStack[$top]['postApplyHash'] = $content === null ? null : hash('sha256', $content);
        $this->undoStack[$top]['sealed'] = true;
    }
}
