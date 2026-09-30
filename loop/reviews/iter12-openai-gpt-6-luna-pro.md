# Review — openai/gpt-6-luna-pro (iteration 12)

**Usage:** {"prompt_tokens":197316,"completion_tokens":12390,"total_tokens":209706,"cost":0.02029449,"is_byok":false,"prompt_tokens_details":{"cached_tokens":62579,"cache_write_tokens":0,"audio_tokens":0,"video_tokens":0},"cost_details":{"upstream_inference_cost":0.02029449,"upstream_inference_prompt_cost":0.01409949,"upstream_inference_completions_cost":0.006195},"completion_tokens_details":{"reasoning_tokens":9454,"image_tokens":0,"audio_tokens":0}}

## Verdict

Paider is in good shape for an alpha: its security boundaries and cost accounting receive unusually careful treatment, and the test suite is substantial. The biggest improvement would be to make event ordering reliable under concurrent writers; the current sequence allocation can produce ties even though readers depend on sequence order.

## Strengths

- EventLog and CostLedger make cost records append-only and preserve unknown pricing as unknown rather than silently reporting zero.
- ProjectEnv, Gate, McpClient, and LibraryImporter consistently distinguish user preferences from permissions and document the threat model at the enforcement points.
- The 573 passing tests include golden checks, a hermetic MCP stdio fixture, and optional Postgres coverage rather than relying only on mocks.

## Findings

### 1. Serialize event sequence allocation

- **severity:** medium  
- **area:** storage  
- **effort:** M

**Reason.** EventLog::nextSeq() reads MAX(seq)+1 separately from insert(), so concurrent writers can both choose the same value. EventLog::stream(), lastOf(), and lastSessionId() order only by seq; ties therefore do not provide the total insertion order these readers assume. The nextSeq() comment acknowledges ties but incorrectly says the sequence establishes a total order.

**Suggestion.** Allocate sequence numbers atomically: use a database-backed counter updated in the same transaction as the event insert, or serialize append transactions with a lock. Add a concurrent-writer test that verifies distinct sequence positions and deterministic replay order on SQLite and Postgres.

**Evidence.**

```
EventLog::nextSeq(): `SELECT COALESCE(MAX(seq), 0) FROM events` followed by `return (int) $max + 1;`; EventLog::stream(): `ORDER BY seq ASC`; nextSeq() docblock: `Two concurrent writers CAN interleave and take the same number.`
```

### 2. Make stdio tool names collision-resistant

- **severity:** medium  
- **area:** mcp  
- **effort:** S

**Reason.** McpStdioClient::qualify() claims names are collision-proof, but it concatenates server and tool names without escaping or hashing. MCP names may contain the delimiter, so distinct server/tool pairs can produce the same name. Loop::__construct() stores tools by name, silently overwriting an earlier tool when that happens.

**Suggestion.** Encode the server and tool components unambiguously or append a hash of the original pair, as McpdClient does. Also detect duplicate names when composing tools and fail with an error naming both definitions instead of silently replacing one.

**Evidence.**

```
McpStdioClient::qualify(): `return 'mcp__'.$this->serverName.'__'.$toolName;`; Loop::__construct(): `$this->tools[$tool->name()] = $tool;`; McpStdioClient comment: `The hash keeps it collision-proof`.
```

### 3. Record each successful embedding call before indexing can fail

- **severity:** high  
- **area:** cost  
- **effort:** M

**Reason.** RagStore::index() accumulates tokens across embed() calls but appends one embedding_call event only after the entire loop. If a later embedding request or storeChunks() fails, earlier provider calls may already have been billed and chunks may already have been written, but the function exits without recording any of that spend. A retry can then skip already-indexed events, making the missing cost permanent.

**Suggestion.** Append an embedding_call event immediately after each successful embed() response, using that call's lastTokenCount(), before storing its vectors. If retaining a single aggregate event is important, use a failure-safe accounting path that records completed provider calls even when subsequent indexing fails, and test a failure after the first successful batch.

**Evidence.**

```
RagStore::index(): `$tokens += $embedder->lastTokenCount();` occurs inside the loop, while `$this->events->append('embedding_call', ...)` occurs only after the loop under `if ($chunks > 0)`.
```

### 4. Correct the README's tier-routing status

- **severity:** medium  
- **area:** docs  
- **effort:** S

**Reason.** The README says every chat/run loop call executes on the orchestrator tier and that per-operation routing is future v0.2 work. The shown Loop::turn() now resolves the current operation through TierRouter on each iteration and changes the operation after tool calls, so the README describes behavior the code no longer has.

**Suggestion.** Update the README status paragraph to explain the initial plan call and subsequent edit/search routing, and revise any surrounding v0.1/v0.2 claims that depend on the old behavior. Add a documentation assertion for this status so a later routing change cannot leave the claim stale.

**Evidence.**

```
README: `Today, every chat/run loop call executes on the orchestrator tier` and `v0.2 work on per-operation tier routing will change that`; Loop::turn(): `$resolved = $this->tierRouter->resolve($operation, $session->tierOverrides());` and later `$operation = $this->operationFor($call);`.
```

### 5. Regenerate the TUI capture from the current code

- **severity:** medium  
- **area:** ui  
- **effort:** S

**Reason.** The supplied screenshot shows `exit` in the input box followed by a spinner and an `anthropic/claude-opus-` provider line, which looks like a model request is running. In the supplied ChatCommand code, `handleSlashCommand()` recognizes `exit` and returns before `Loop::turn()` is called. The capture therefore does not demonstrate the behavior of the code shown; it is stale or otherwise misleading. The same screenshot also renders the hint as `type/quitto exit`, obscuring the intended spacing.

**Suggestion.** Regenerate the checked-in capture after the current exit-handling change, verify that entering `exit` produces no provider call or tier_call event, and fix the rendered hint so it visibly reads `type /quit to exit`. Keep a capture check or focused command test to prevent this mismatch recurring.

**Evidence.**

```
Screenshot: the input contains `exit` and the output below shows a spinner with `anthropic/claude-opus-`; ChatCommand::handleSlashCommand(): `if (preg_match('/^(?:exit|quit|q)$/i', $line) === 1) { $this->quitRequested = true; return true; }`.
```

