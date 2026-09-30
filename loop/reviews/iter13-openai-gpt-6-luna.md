# Review — openai/gpt-6-luna (iteration 13)

**Usage:** {"prompt_tokens":62582,"completion_tokens":7084,"total_tokens":69666,"cost":0.00416809,"is_byok":false,"prompt_tokens_details":{"cached_tokens":62579,"cache_write_tokens":0,"audio_tokens":0,"video_tokens":0},"cost_details":{"upstream_inference_cost":0.00416809,"upstream_inference_prompt_cost":0.00062609,"upstream_inference_completions_cost":0.003542},"completion_tokens_details":{"reasoning_tokens":5773,"image_tokens":0,"audio_tokens":0}}

## Verdict

Paider is in good shape for an alpha: the code and documentation show careful attention to safety and accounting, but several guarantees are stronger in the prose than in the implementation. The biggest improvement would be to make the storage and trust-boundary invariants enforceable under concurrency and through every public API, rather than relying on callers to preserve them.

## Strengths

- The 573 passing tests and 3,377 assertions provide substantial coverage, with Postgres-only tests explicitly identified as environment-dependent.
- The supplied TUI screenshot is legible: the resumed-session line and input box are distinct, and cyan accents separate commands from surrounding text.

## Findings

### 1. Serialize sequence allocation

- **severity:** medium  
- **area:** storage  
- **effort:** M

**Reason.** `EventLog::nextSeq()` reads `MAX(seq) + 1` separately from `insert()` and explicitly allows concurrent writers to take the same value. But `stream()` orders only by `seq`, so ties have no defined order. Concurrent chat processes can therefore replay session messages in an arbitrary order, despite the comments describing `seq` as establishing a total order.

**Suggestion.** Allocate sequence values atomically and enforce uniqueness: use a driver-specific atomic counter/sequence behind the storage seam, or serialize allocation and insertion in a transaction with an appropriate SQLite/Postgres lock. Add a concurrent-writer test that verifies distinct, stable ordering and correct session replay.

**Evidence.**

```
`EventLog::nextSeq()`: “Two concurrent writers CAN interleave and take the same number.” `EventLog::stream()`: `ORDER BY seq ASC`.
```

### 2. Create private SQLite storage

- **severity:** medium  
- **area:** security  
- **effort:** S

**Reason.** `Database::connectSqlite()` creates the storage directory with mode `0755` and does not restrict the database file or SQLite sidecars. With a common `umask 022`, the database can be readable by other local users, while `EventLog` stores conversation messages and tool results there. `.gitignore` does not protect data from other users on the same machine.

**Suggestion.** Create `.paider` with mode `0700` and ensure the database and WAL/SHM files are restricted to the current user (normally `0600`). Apply the protection to existing storage files as well as newly created ones, and add a test for the resulting permissions on supported Unix platforms.

**Evidence.**

```
`Database::connectSqlite()`: `mkdir($dir, 0755, true)`. `Loop::remember()` appends user and assistant content to `EventLog`.
```

### 3. Account for library embeddings

- **severity:** medium  
- **area:** cost  
- **effort:** M

**Reason.** `LibraryIndex::importItems()` calls `$embedder->embed(...)` but records no `embedding_call` event. The cost projection only folds embedding events written to the log, so paid skill or prompt indexing through this path is absent from `paider cost`. This contradicts the embedding-accounting claim in `OpenAiEmbeddingClient`’s documentation that every `embed()` is recorded.

**Suggestion.** Record the aggregate token usage and model for library-index embedding operations in the same cost ledger path used by RAG. Prefer a shared metering wrapper or accounting service so adding another `EmbeddingClient` caller cannot silently omit spend or double-count it.

**Evidence.**

```
`LibraryIndex::importItems()`: `$vectors = $embedder->embed(array_map(...))`. `OpenAiEmbeddingClient` docblock: “Every embed() appends an `embedding_call` event (see RagStore).”
```

### 4. Make library trust checks unbypassable

- **severity:** medium  
- **area:** security  
- **effort:** M

**Reason.** `LibraryImporter::fromPath()` checks `LibraryIndex::refusesPath()`, but `LibraryImporter::collect()` is public and reads skill files without that check. Separately, public `LibraryIndex::importItems()` accepts arbitrary items directly. A caller can therefore bypass the importer’s refusal path, despite the `LibraryIndex` documentation claiming the writer cannot be used to skip the trust check.

**Suggestion.** Make raw collection and indexing writes internal to a single validated import path. For example, require a vetted-source value object that only the importer can construct after checking the resolved path, and reject direct unvetted writes. Add tests that attempt to import from a project-local skill directory through every public entry point.

**Evidence.**

```
`LibraryImporter::collect(string $directory)` is public and immediately walks the directory. `LibraryIndex::importItems(array $items, ...)` writes its input without checking provenance. `LibraryIndex` docblock: “the writer cannot be talked into skipping it by a future caller.”
```

### 5. Make partial RAG indexing recoverable

- **severity:** medium  
- **area:** storage  
- **effort:** M

**Reason.** `RagStore::storeChunks()` inserts chunks individually without a transaction. If an insert fails after some chunks for an event have been stored, the next `index()` call sees that event in `indexedEventIds()` and skips it entirely. The missing chunks are then never repaired, leaving a silently incomplete index.

**Suggestion.** Store all chunks for one event atomically in a transaction, so failure leaves no rows and a retry can re-embed it. Alternatively, track expected chunk count and treat incomplete events as unindexed. Add a test that forces a mid-event insert failure and verifies a subsequent index completes the event.

**Evidence.**

```
`RagStore::index()` obtains `$seen = $this->indexedEventIds()` and skips any seen event. `RagStore::storeChunks()` loops over `$pieces` and executes each insert without starting a transaction.
```

