<?php

namespace App\Agent;

use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Storage\EventLog;
use App\Support\ModelPricing;
use App\Support\PhpSpinner;
use App\Support\ProseStream;
use App\Tools\Contracts\Tool;
use App\Tools\ToolResult;
use Symfony\Component\Console\Terminal;

use function Termwind\render;

/**
 * The think -> tool-call -> apply -> observe cycle. One turn is: push the user's message,
 * then repeatedly ask the orchestrator tier for a reply until it stops calling tools.
 */
class Loop
{
    private const MAX_TOOL_CALLS_PER_TURN = 10;

    private const RETRY_ON_APPROVAL_TOOLS = ['read_file', 'write_file', 'patch_file', 'git'];

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

            // The provider call is a blocking synchronous HTTP request that can sit for
            // minutes; without this the terminal reads as hung. Spinner degrades itself to a
            // single static line when stdout isn't decorated or pcntl is missing, so it
            // neither forks nor animates under the test suite or a piped run.
            $response = PhpSpinner::while(
                $resolved['model'],
                fn () => $this->provider->send($this->buildMessages($session), $resolved['model']),
            );

            // 'model' now names what was actually billed, not what was asked for — a
            // provider can alias/route/fallback to a different id than requested, and
            // pricing must follow the id that was actually served. Frozen at write time,
            // same write-time-freeze discipline as cost_usd (LOCKED #2/#3).
            $requestedModel = $resolved['model'];
            $servedModel = $response->servedModel ?? $requestedModel;

            $this->eventLog->append('tier_call', [
                'tier' => 'orchestrator',
                'model' => $servedModel,
                'requested_model' => $requestedModel,
                'tokens_in' => $response->tokensIn,
                'tokens_out' => $response->tokensOut,
                'tokens_cache_write' => $response->cacheWrite,
                'tokens_cache_read' => $response->cacheRead,
                'cost_usd' => ModelPricing::costFor($servedModel, $response->tokensIn, $response->tokensOut, $response->cacheWrite, $response->cacheRead),
                // Frozen at write time, same as cost_usd (LOCKED decision #2, one field
                // over) -- so a later config/prices.php edit can't silently move the
                // "same work on all-Opus 5" comparison onto a different price basis.
                'hypothetical_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, $response->tokensIn, $response->tokensOut, $response->cacheWrite, $response->cacheRead),
            ]);

            $call = $this->parseToolCall($response->content);
            $session->pushHistory('assistant', $response->content);

            if ($call === null) {
                $this->renderProse($response->content);

                return;
            }

            $this->renderToolCall($call['name'], $call['input']);

            $startedAt = microtime(true);
            $result = $this->dispatch($session, $call['name'], $call['input'], $approvalPrompt);

            $this->renderToolResult($result, microtime(true) - $startedAt);

            $this->eventLog->append('tool_call', [
                'tool' => $call['name'],
                'input' => $call['input'],
                'ok' => $result->ok,
            ]);

            // Observations go back as 'user', not 'assistant'. The next iteration re-sends the
            // whole history, so an assistant-role observation would leave the conversation
            // ENDING on an assistant turn — which Anthropic reads as a prefill and rejects
            // outright ("This model does not support assistant message prefill. The
            // conversation must end with a user message."), 400ing every turn that called a
            // tool. Applies to both clients: AnthropicClient hits it natively, and OpenRouter
            // surfaces the same provider error through the OpenAI-compatible path.
            $session->pushHistory('user', $this->observationText($call['name'], $result));
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

        // 'approval' (run_shell/artisan) and 'approved' (read_file/write_file/patch_file/git,
        // legacy — those four now take approval via Tool::execute()'s second parameter, which
        // $input can never reach) are Loop-internal fields, set only after Gate::decide()
        // actually runs. This unset() remains the sole defence for run_shell/artisan; for the
        // four it's a second, independent belt-and-suspenders layer on top of the tools' own
        // refusal to read approval out of $input at all.
        unset($input['approval'], $input['approved']);

        if ($name === 'run_shell') {
            return $this->dispatchShell($tool, $input, $approvalPrompt);
        }

        if ($name === 'artisan') {
            return $this->dispatchArtisan($tool, $input, $approvalPrompt);
        }

        if ($name === 'fetch_url') {
            return $this->dispatchFetch($tool, $input, $approvalPrompt);
        }

        if ($name === 'write_file' || $name === 'patch_file') {
            return $this->dispatchWrite($tool, $session, $input, $approvalPrompt);
        }

        $result = $tool->execute($input);

        if (in_array($name, self::RETRY_ON_APPROVAL_TOOLS, true) && $this->needsRetry($result, $input)) {
            $result = $this->retryWithApproval($tool, $input, $approvalPrompt);
        }

        return $result;
    }

    private function dispatchShell(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        // Fail closed on a non-string command BEFORE the gate ever sees it. proc_open()
        // also accepts an argv-array command; letting that through here would show the
        // human approver an empty '' subject (is_string() coerces it away) while the real
        // argv still executes, and a single grant on '' would then silently authorize every
        // future array-form command for the rest of the session.
        if (! is_string($input['command'] ?? null) || $input['command'] === '') {
            return ToolResult::fail('command must be a string');
        }

        return $this->dispatchGated($tool, $input, $input['command'], $approvalPrompt);
    }

    /**
     * The approval subject is the URL itself, so the human sees exactly where the request is
     * going. Same fail-closed-before-the-gate discipline as dispatchShell: a non-string url
     * would show the approver an empty subject, and a session grant on '' would then stand in
     * for every later fetch.
     */
    private function dispatchFetch(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        if (! is_string($input['url'] ?? null) || $input['url'] === '') {
            return ToolResult::fail('url must be a string');
        }

        return $this->dispatchGated($tool, $input, $input['url'], $approvalPrompt);
    }

    private function dispatchArtisan(Tool $tool, array $input, callable $approvalPrompt): ToolResult
    {
        return $this->dispatchGated($tool, $input, 'php artisan route:list --json', $approvalPrompt);
    }

    private function dispatchGated(Tool $tool, array $input, string $subject, callable $approvalPrompt): ToolResult
    {
        $allowed = $this->gate->decide($subject, fn () => $approvalPrompt($subject));

        $input['approval'] = $allowed ? 'allow-once' : 'deny';

        return $tool->execute($input);
    }

    /**
     * Capture the file's current content (for /undo) via the read_file tool rather than raw
     * filesystem calls — Loop doesn't hold the project root itself, only the injected tools,
     * and read_file already knows how to resolve a root-relative path safely. The undo entry
     * is only pushed once the write/patch actually SUCCEEDED: a rejected write (e.g. a path
     * PathGuard bounces as outside the project root) must never poison the undo stack, or a
     * later /undo seals against — and then deletes — a file the write never touched.
     */
    private function dispatchWrite(Tool $tool, Session $session, array $input, callable $approvalPrompt): ToolResult
    {
        $path = is_string($input['path'] ?? null) ? $input['path'] : null;
        $previous = $path === null ? null : $this->previousContentFor($path);

        $result = $tool->execute($input);

        if ($this->needsRetry($result, $input)) {
            $result = $this->retryWithApproval($tool, $input, $approvalPrompt);
        }

        if ($result->ok && $path !== null) {
            $session->recordApply($path, $previous);
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
        // A git diff carries no 'path' (it's a repo-wide op) — fall back to the tool's own
        // name so the human approver sees "git", not an empty prompt.
        $subject = $path !== '' ? $path : $tool->name();
        $allowed = $this->gate->decide($subject, fn () => $approvalPrompt($subject));

        if (! $allowed) {
            return ToolResult::fail('denied', ['needs_approval' => true]);
        }

        return $tool->execute($input, true);
    }

    private function previousContentFor(string $path): ?string
    {
        $readTool = $this->tools['read_file'] ?? null;

        if ($readTool === null) {
            return null;
        }

        // approved=true (2nd execute() param, not an $input key): this is Loop's own
        // bookkeeping read for the undo stack, not content being disclosed to the model,
        // so the secrets gate doesn't apply here.
        $result = $readTool->execute(['path' => $path], true);

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

        // Do NOT pre-wrap $content here: the stream already word-wraps every line itself, and
        // wrapping first only means those already-broken lines get re-wrapped, which renders
        // as alternating long/short ragged lines. Prose wrapping is the stream's job — see
        // ProseStream for the margin — and the tool lines below wrap themselves because they
        // don't go through it at all.
        $out = new ProseStream;

        foreach (str_split($content, 20) ?: [''] as $chunk) {
            $out->append($chunk);
        }

        $out->close();
    }

    /**
     * Tool activity is the part of a turn that used to print nothing at all — the loop would
     * shell out, block on an approval prompt and apply writes in complete silence, which reads
     * as a hung terminal. htmlspecialchars because Termwind parses its argument as markup and
     * tool input is arbitrary text: an unescaped `>` in a shell redirect would be swallowed.
     */
    private function renderToolCall(string $name, array $input): void
    {
        $subject = htmlspecialchars($this->summarizeInput($input), ENT_QUOTES);
        $label = htmlspecialchars($name, ENT_QUOTES);

        // w-12/ml-* rather than str_pad and literal spaces: Termwind trims whitespace at the
        // edges of an element, so padding baked into the text collapses and the columns lose
        // their alignment.
        render("<div class=\"mt-1\"><span class=\"text-cyan w-12\">⚒ {$label}</span><span class=\"text-gray\">{$subject}</span></div>");
    }

    private function renderToolResult(ToolResult $result, float $seconds): void
    {
        $lines = $result->output === '' ? 0 : substr_count($result->output, "\n") + 1;
        $detail = sprintf('%.1fs · %d line%s', $seconds, $lines, $lines === 1 ? '' : 's');

        render($result->ok
            ? "<div><span class=\"text-green ml-2\">✓ ok</span><span class=\"text-gray ml-2\">{$detail}</span></div>"
            : '<div><span class="text-red ml-2">✗ '.htmlspecialchars($this->oneLine($result->output), ENT_QUOTES).'</span></div>');
    }

    /**
     * The one field worth showing per tool — the shell command, the file being touched, the git
     * subcommand. Falls back to the first string in the input so a tool added later still shows
     * something rather than a bare name.
     */
    private function summarizeInput(array $input): string
    {
        foreach (['command', 'path', 'op'] as $key) {
            if (is_string($input[$key] ?? null) && $input[$key] !== '') {
                return $this->oneLine($input[$key]);
            }
        }

        foreach ($input as $value) {
            if (is_string($value) && $value !== '') {
                return $this->oneLine($value);
            }
        }

        return '';
    }

    private function oneLine(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $width = max(20, (new Terminal)->getWidth() - 16);

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width - 1).'…' : $text;
    }
}
