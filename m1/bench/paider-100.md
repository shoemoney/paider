# Paider 100 — Diff-apply benchmark corpus (scaffold)

Goal: measured diff-apply success rate on qwen3.7-flash across 100 fixture patches.

Corpus: `m1/fixture` × 50 variants (prompt: "add DiscountCode, Receipt discount, Cart qty guard, etc.")
Run: `m1/bench/paider-100.sh` loops mock provider through PatchFileTool, reports success rate.
CI gate: success ≥95% or fails.

Scaffold — not yet implemented, tracked for v1.0.
