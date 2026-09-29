# Review — google/gemma-4-31b-it (iteration 1)

**Usage:** {"prompt_tokens":60428,"completion_tokens":1242,"total_tokens":61670,"cost":0.00646508,"is_byok":false,"prompt_tokens_details":{"cached_tokens":0,"cache_write_tokens":0,"audio_tokens":0,"video_tokens":0},"cost_details":{"upstream_inference_cost":0.00646508,"upstream_inference_prompt_cost":0.0060428,"upstream_inference_completions_cost":0.00042228},"completion_tokens_details":{"reasoning_tokens":0,"image_tokens":0,"audio_tokens":0}}

## Verdict

The project is in remarkably good shape for an alpha, demonstrating a level of rigor in measurement and security boundaries (especially regarding clone-to-RCE) that is rare in AI agent projects. The single biggest improvement would be implementing the per-operation tier routing in the Loop, as the project currently claims a 'research' and 'coder' tier in the README and cost ledger, but hardcodes every turn to the 'orchestrator' tier in the code.

## Strengths

- Structural append-only event log ensures the cost ledger is a verifiable projection rather than a mutable balance.
- Strong security posture on trust boundaries, specifically the refusal of project-local skills to prevent RCE via cloned repos.
- Exceptional commitment to measured facts over estimates, particularly regarding FrankenPHP cold starts and binary sizes.

## Findings

### 1. Tier routing is hardcoded to orchestrator

- **severity:** high  
- **area:** agents  
- **effort:** M

**Reason.** The README and CostCommand claim a multi-tier system (orchestrator, coder, research, fast), but app/Agent/Loop.php:86 explicitly hardcodes 'plan' as the routing key for every turn: `$resolved = $this->tierRouter->resolve('plan', $session->tierOverrides());`. This means the 'coder' and 'research' tiers are currently unreachable in the main loop.

**Suggestion.** Implement the per-operation routing logic in Loop::turn to select different tiers based on the current state (e.g., switching to 'coder' when a tool call is proposed or 'research' when reading large files).

**Evidence.**

```
app/Agent/Loop.php:86: `$resolved = $this->tierRouter->resolve('plan', $session->tierOverrides());`
```

### 2. MCP server process leak on failure

- **severity:** medium  
- **area:** mcp  
- **effort:** S

**Reason.** In app/Providers/McpStdioClient.php, the `withClient` method connects a transport and then executes work. While there is a `finally` block calling `$client->disconnect()`, if the `Client::builder()->build()` or `connect()` calls themselves throw an exception before the client is fully instantiated or the transport is registered, the child process spawned by `StdioTransport` may not be reaped, leading to orphaned processes.

**Suggestion.** Wrap the transport creation and connection in a more robust try-catch-finally block that explicitly ensures the process handle is closed if the connection fails.

**Evidence.**

```
app/Providers/McpStdioClient.php:134-150
```

### 3. Potential for memory exhaustion in EventLog::all()

- **severity:** medium  
- **area:** storage  
- **effort:** S

**Reason.** While `EventLog::stream()` correctly uses a Generator for constant memory, `EventLog::all()` (app/Storage/EventLog.php:148) calls `iterator_to_array()` on that stream. In a long-running project with thousands of events, `RunCommand::handle()` (app/Commands/RunCommand.php:78) calls `all()` and then reverses the array, which will cause a memory spike or OOM.

**Suggestion.** Replace `all()` with a method that allows reverse iteration or use a SQL `ORDER BY seq DESC` query to fetch only the necessary events for the CI check.

**Evidence.**

```
app/Storage/EventLog.php:148: `return iterator_to_array($this->stream());`
```

### 4. Inconsistent session identification in CostCommand

- **severity:** low  
- **area:** cost  
- **effort:** S

**Reason.** In app/Commands/CostCommand.php:32-41, the logic to find the 'current session' for the `--session` flag iterates through the entire event log to find the last `session_start` event. This is inefficient and potentially incorrect if multiple sessions were started but not completed. It relies on the order of the stream rather than a dedicated session registry.

**Suggestion.** Implement a simple `sessions` table or a more efficient way to retrieve the most recent active session ID without scanning the entire event log.

**Evidence.**

```
app/Commands/CostCommand.php:33-41
```

### 5. TUI information hierarchy issues in YOLO mode

- **severity:** low  
- **area:** ui  
- **effort:** S

**Reason.** Looking at the provided screenshot, when YOLO mode is active, the 'YOLO approving everything' badge is placed above the 'type /quit to exit' and 'resumed' messages. While visible, the high-density text and lack of clear separation between system status and session history make the 'resumed' notice blend into the background, potentially confusing the user about the current state of their context.

**Suggestion.** Increase the vertical padding or use a distinct border/separator between the global status badges (YOLO) and the session-specific status (resumed messages).

**Evidence.**

```
Screenshot: YOLO badge and 'resumed' text are closely packed with similar muted colors.
```

