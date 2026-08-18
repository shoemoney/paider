# diff-apply pilot corpus

Hand-built pilot cases for measuring `PatchFileTool`'s real apply behavior. These are
**pilot cases, not a certification suite** — the pass/fail per case is a fact about the
current implementation (verified by reading `app/Tools/PatchFileTool.php` directly), not
a target invented in advance. Any apply-rate threshold for CI gating gets set later from
live-model data, not from this corpus.

Each case directory contains:

- `case.json` — manifest: `path` (target filename), `stamp_mode` (`match` | `new_file` | `stale`),
  `expect_ok` (bool), and for failure cases `expect_meta` (a subset of the `ToolResult`
  meta array that must be present in the actual failure).
- `input.txt` / `input.php` — the file's content before the patch, when the case targets an
  existing file. Omitted for new-file cases.
- `diff.patch` — the exact `patch_file` tool's `diff` payload, in the unified-diff subset
  `PatchFileTool` actually parses (optional `---`/`+++` headers, `@@ -a,b +c,d @@` hunk
  headers, ` `/`+`/`-` prefixed body lines, optional trailing `\ No newline at end of file`).
- `expected.txt` — the exact resulting file content, byte-for-byte, for `expect_ok: true` cases.

Strata covered: clean apply, new-file creation, multi-hunk diffs, offset drift (diff
generated against a stale version of the file — `PatchFileTool` has no fuzzy/offset
matching, so drift is an expected-failure case here, not a success case), and deliberate
conflicts/malformed input (hallucinated content, stale content-hash stamp, malformed hunk
headers, empty diff, out-of-order hunks, CRLF/LF mismatch, and the post-apply PHP syntax
gate).

Run via `vendor/bin/pest --filter=DiffApplyPilot`.
