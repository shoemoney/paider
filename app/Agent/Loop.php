<?php

namespace App\Agent;

use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Storage\EventLog;
use App\Tools\Contracts\Tool;
use App\Tools\ToolResult;

use function Laravel\Prompts\stream;

/**
 * The think -> tool-call -> apply -> observe cycle. One turn is: push the user's message,
 * then repeatedly ask the orchestrator tier for a reply until it stops calling tools.
 */
class Loop
{
    private const MAX_TOOL_CALLS_PER_TURN = 10;

    private const APPROVAL_GATED_TOOLS = ['read_file', 'write_file', 'patch_file'];

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param array<int, Tool> $tools */
    public function __construct(
        array $tools,
        private readonly ProviderClient $provider,
        private readonly TierRouter $tierRouter,
        private readonly EventLog $eventLog,
        private readonly Gate $gate,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @param callable(string): string $approvalPrompt returns 'allow-once'|'allow-session'|'deny' */
    public function turn(Session $session, string $userInput, callable $approvalPrompt): void
    {
        $session->pushHistory('user', $userInput);

        for ($i = 0; $i < self::MAX_TOOL_CALLS_PER_TURN; $i++) {
            $resolved = $this->tierRouter->resolve('plan', $session->tierOverrides());
            $response = $this->provider->send($this->buildMessages($session), $resolved['model']);

            // presets.php prices are informational comments, not a billing feed — same reason
            // commit-command logs 0.0, see project brief.
            $this->eventLog->append('tier_call', [
                'tier' => 'orchestrator',
                'model' => $resolved['model'],
                'tokens_in' => $response->tokensIn,
                'tokens_out' => $response->tokensOut,
                'cost_usd' => 0.0,
            ]);

            $call = $this->parseToolCall($response->content);
            $session->pushHistory('assistant', $response->content);

            if ($call === null) {
                $this->renderProse($response->content);

                return;
            }

            $result = $this->dispatch($session, $call['name'], $call['input'], $approvalPrompt);

            $this->eventLog->append('tool_call', [
                'tool' => $call['name'],
                'input' => $call['input'],
                'ok' => $result->ok,
            ]);

            $session->pushHistory('assistant', $this->observationText($call['name'], $result));
        }

        $this->renderProse('Hit the 10 tool-call limit for this turn — stopping here. Ask again to continue.');
    }

    /**
     * Strict single-block parse: a well-formed `\`\`\`tool ... \`\`\`` fence with valid JSON
     * containing a string "name" is a tool call. Anything else — no fence, more than one
     * fence, or malformed JSON inside it — is prose, same discipline PatchFileTool's diff
     * parser uses: fail closed to "do nothing weird" rather than guess or crash.
     *
     * @return array{name: string, input: array}|null
     */
    private function parseToolCall(string $content): ?array
    {
        if (substr_count($content, '```') !== 2) {
            return null;
        }

        if (! preg_match('/```tool\s*\n(.*?)\n```/s', $content, $matches)) {
            return null;
        }

        try {
            $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['name'] ?? null)) {
            return null;
        }

        return [
            'name' => $decoded['name'],
            'input' => is_array($decoded['input'] ?? null) ? $decoded['input'] : [],
        ];
    }

    private function dispatch(Session $session, string $name, array $input, callable $approvalPrompt): ToolResult
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return ToolResult::fail("unknown tool: {$name}");
        }

        if ($name === 'run_shell') {
            return $this->dispatchShell($tool, $input, $approvalPrompt);
        }

        if ($name === 'artisan') {
            return $this->dispatchArtisan($tool, $input, $approvalPrompt);
        }

        if ($name === 'write_file' || $name === 'patch_file') {
            return $this->dispatchWrite($tool, $session, $input, $approvalPrompt);
        }

        $result = $tool->execute($input);

        if (in_array($name, self::APPROVAL_GATED_TOOLS, true) && $this->needsRetry($result, $input)) {
            $result = $this->retryWithApproval($tool, $input, $approvalPrompt);
        }

        return $result;
    }

    private function dispatchShell(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        // 'approval' is a Loop-internal field, resolved by the gate below — never trust one
        // supplied in the model's own tool-call input, or the model could self-approve.
        unset($input['approval']);

        $command = is_string($input['command'] ?? null) ? $input['command'] : '';
        $allowed = $this->gate->decide($command, fn () => $approvalPrompt($command));

        $input['approval'] = $allowed ? 'allow-once' : 'deny';

        return $tool->execute($input);
    }

    private function dispatchArtisan(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        // Same rule as dispatchShell: 'approval' is never taken from the model's input.
        unset($input['approval']);

        $subject = 'php artisan route:list --json';
        $allowed = $this->gate->decide($subject, fn () => $approvalPrompt($subject));

        $input['approval'] = $allowed ? 'allow-once' : 'deny';

        return $tool->execute($input);
    }

    /**
     * Before any Write/PatchFileTool dispatch actually applies, record the file's current
     * content so /undo can restore it. Read via the read_file tool rather than raw
     * filesystem calls — Loop doesn't hold the project root itself, only the injected
     * tools, and read_file already knows how to resolve a root-relative path safely.
     */
    private function dispatchWrite(Tool $tool, Session $session, array $input, callable $approvalPrompt): ToolResult
    {
        $path = is_string($input['path'] ?? null) ? $input['path'] : null;

        if ($path !== null) {
            $session->recordApply($path, $this->previousContentFor($path));
        }

        $result = $tool->execute($input);

        if ($this->needsRetry($result, $input)) {
            $result = $this->retryWithApproval($tool, $input, $approvalPrompt);
        }

        return $result;
    }

    private function needsRetry(ToolResult $result, array $input): bool
    {
        return ! $result->ok
            && ($result->meta['needs_approval'] ?? false)
            && ! ($input['approved'] ?? false);
    }

    private function retryWithApproval(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        $path = is_string($input['path'] ?? null) ? $input['path'] : '';
        $allowed = $this->gate->decide($path, fn () => $approvalPrompt($path));

        if (! $allowed) {
            return ToolResult::fail('denied', ['needs_approval' => true]);
        }

        $input['approved'] = true;

        return $tool->execute($input);
    }

    private function previousContentFor(string $path): ?string
    {
        $readTool = $this->tools['read_file'] ?? null;

        if ($readTool === null) {
            return null;
        }

        // approved=true: this is Loop's own bookkeeping read for the undo stack, not
        // content being disclosed to the model, so the secrets gate doesn't apply here.
        $result = $readTool->execute(['path' => $path, 'approved' => true]);

        return $result->ok ? $result->output : null;
    }

    /** @return array<int, array{role: string, content: string}> */
    private function buildMessages(Session $session): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemInstruction()]];

        // /add'ed files must be disclosed with the same sha256 stamp PatchFileTool checks —
        // otherwise the model can never supply a stamp that will actually match on apply.
        foreach ($session->contextFiles() as $path => $file) {
            $messages[] = ['role' => 'system', 'content' => $this->contextFileMessage($path, $file)];
        }

        foreach ($session->history() as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        return $messages;
    }

    /** @param array{stamp: string, content: string} $file */
    private function contextFileMessage(string $path, array $file): string
    {
        return "Context file: {$path}\nstamp: {$file['stamp']}\n```\n{$file['content']}\n```";
    }

    private function systemInstruction(): string
    {
        $toolDocs = array_map(
            fn (Tool $tool) => "- {$tool->name()}: {$tool->description()} input schema: ".json_encode($tool->inputSchema()),
            array_values($this->tools),
        );

        // v0.1: text-fenced tool-call protocol, not each provider's native function-calling —
        // avoids reconciling Anthropic's tool_use format against OpenAI's function-calling
        // format for v0.1; revisit if a model's plain-text compliance proves unreliable.
        return "You are Paider, an AI coding agent working in the user's project.\n\n"
            ."Available tools:\n".implode("\n", $toolDocs)
            ."\n\nTo call a tool, reply with EXACTLY one fenced block and nothing else meaningful "
            ."outside it:\n```tool\n{\"name\": \"<tool name>\", \"input\": {...}}\n```\n"
            .'Otherwise, reply with plain prose.';
    }

    private function observationText(string $toolName, ToolResult $result): string
    {
        $status = $result->ok ? 'ok' : 'error';

        return "[tool_result {$toolName}: {$status}]\n{$result->output}";
    }

    /**
     * Progressive per-chunk rendering of an already-complete synchronous HTTP response —
     * UX chunking, not true token streaming, since the wave-1 provider clients are
     * non-streaming request/response.
     *
     * Stream's fade effect probes the terminal (queries true-color support, may open
     * /dev/tty) — a TTY nicety with no meaning when stdout isn't a terminal at all (piped
     * output, non-interactive test runs). Fall back to a plain print there instead of
     * paying for a terminal query that has nothing to query.
     */
    private function renderProse(string $content): void
    {
        if (! stream_isatty(STDOUT)) {
            echo $content.PHP_EOL;

            return;
        }

        $out = stream();

        foreach (str_split($content, 20) ?: [''] as $chunk) {
            $out->append($chunk);
        }

        $out->close();
    }
}
