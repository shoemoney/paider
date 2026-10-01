# Review — google/gemma-4-26b-a4b-it (iteration 27)

**Usage:** {"prompt_tokens":64266,"completion_tokens":1391,"total_tokens":65657,"cost":0.0104745,"is_byok":false,"prompt_tokens_details":{"cached_tokens":7,"cache_write_tokens":0,"audio_tokens":0,"video_tokens":0},"cost_details":{"upstream_inference_cost":0.0104745,"upstream_inference_prompt_cost":0.0096399,"upstream_inference_completions_cost":0.0008346},"completion_tokens_details":{"reasoning_tokens":0,"image_tokens":0,"audio_tokens":0}}

## Verdict

The project is exceptionally well-engineered for an alpha, particularly in its handling of the 'clone-to-RCE' security boundary and its append-only event log architecture. However, the single biggest thing that would make it better is the completion of the multi-agent roster, as the current 'loop' is a single-agent state machine that doesn't yet leverage the tiered routing for true autonomous decomposition.

## Strengths

- The append-only event log with a portable 'seq' column is a masterclass in driver-agnostic architecture.
- Security boundaries for skills and MCP configs are structurally enforced rather than just documented.
- The cost ledger's 'unpriced' handling prevents silent undercounting of expenses.

## Findings

### 1. Inconsistent cost reporting for embedding calls

- **severity:** medium  
- **area:** cost  
- **effort:** S

**Reason.** In `app/Storage/RagStore.php`, the `index()` method accumulates tokens in a local `$tokens` variable and appends a single `embedding_call` event at the end. However, `app/Storage/CostLedger.php` projects this by summing the `tokens_in` from that single event. If `index()` is called multiple times or fails mid-loop, the ledger only sees the last batch's tokens, making the 'reconciles to provider usage' claim in the README technically false for partial indexing runs.

**Suggestion.** Append an `embedding_call` event immediately after each individual `embed()` call within the loop in `RagStore::index()`, rather than aggregating them into a single event at the end.

**Evidence.**

```
app/Storage/RagStore.php: lines 119-145
```

### 2. Unreliable session resumption for context files

- **severity:** medium  
- **area:** agents  
- **effort:** M

**Reason.** In `app/Commands/ChatCommand.php`, the `resume()` method re-adds files to the session using `$session->addFile($path)`. If a user has manually removed a file from the project since the last session, `addFile` will fail, but the `SessionStore::contextFiles()` (which drives the resumption) will still return that path because it is projected from the event log. This creates a mismatch between the 'remembered' context and the actual filesystem state during the first turn of a resumed session.

**Suggestion.** In `ChatCommand::resume()`, verify the existence of the file on disk before calling `$session->addFile()`, or ensure `SessionStore::contextFiles()` performs a liveness check.

**Evidence.**

```
app/Commands/ChatCommand.php: lines 186-193
```

### 3. Potential for silent failure in skill loading due to byte-truncation

- **severity:** low  
- **area:** skills  
- **effort:** M

**Reason.** In `app/Skills/SkillLibrary.php`, `load()` uses `mb_strcut` to enforce `MAX_BODY_BYTES`. While `mb_strcut` prevents invalid UTF-8, it can still truncate a skill body in the middle of a critical instruction or a closing delimiter. Since the agent relies on these instructions for tool usage, a truncated skill might lead to malformed prompts that the model cannot recover from, without any error being raised to the user.

**Suggestion.** Instead of hard truncation, implement a 'soft' limit that warns the user via the TUI if a skill is too large, or use a more intelligent chunking strategy that respects instruction boundaries.

**Evidence.**

```
app/Skills/SkillLibrary.php: lines 128-133
```

### 4. Information hierarchy defect in Cost TUI

- **severity:** low  
- **area:** ui  
- **effort:** S

**Reason.** The `paider cost` command (as seen in the code and implied by the README's logic) renders the per-tier table first, then the total spend at the bottom. For a tool whose primary value proposition is 'seeing exactly where the money went', the most critical piece of information—the total—is buried under the granular breakdown, forcing the user to scan the entire output to find the one number they likely care about most.

**Suggestion.** Move the 'Total spend' rendering to the top of the `handle()` method in `app/Commands/CostCommand.php`, immediately after the summary is calculated.

**Evidence.**

```
app/Commands/CostCommand.php: lines 118-122
```

### 5. Redundant and potentially misleading 'unpriced' count in Cost summary

- **severity:** low  
- **area:** cost  
- **effort:** M

**Reason.** In `app/Storage/CostLedger.php`, the `summary()` method calculates `unpriced_calls` by checking if `cost_usd === null`. However, in `app/Commands/CostCommand.php`, the UI renders this as 'unpriced calls' and then provides a breakdown. If a model is known but the provider returns 0 tokens (an all-zero call), the current logic in `RagStore` might trigger an `embedding_call` that `ModelPricing` treats as `unknown` (per the 'all-zero-means-unknown' rule), leading to a confusing UI where a user sees 'unpriced calls' for a call that clearly had 0 tokens.

**Suggestion.** Differentiate between 'unknown model' (no price entry) and 'zero usage reported' (model known, but 0 tokens) in both the `CostLedger` projection and the `CostCommand` output.

**Evidence.**

```
app/Storage/CostLedger.php: lines 105-110; app/Commands/CostCommand.php: lines 133-137
```

