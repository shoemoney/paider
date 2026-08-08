# m1 Runbook — E2E Rehearsal

**Goal:** Prove `paider chat` drives a real edit in a foreign repo (`m1/fixture` receipt calculator) and `paider cost` reconciles.

## One-time setup
```bash
composer install
bash m1/preflight.sh
bash m1/preflight.sh /tmp/my-copy  # verifies fixture copy
```

## Hermetic (no spend)
```bash
vendor/bin/pest --filter=E2ETrace  # mock provider drives read_file→patch_file, asserts tier_call frozen cost, 10-call cap
make token-budget  # TokenKiller 337 vs 434 toks on fixture
```

## Live (spends)
```bash
AIGATE_URL=https://aigate.shoemoney.ai AIGATE_TOKEN=... OPENROUTER_API_KEY=... bash m1/live-smoke.sh
# or directly:
tmp=$(mktemp -d) && cp -a m1/fixture $tmp/copy && cd $tmp/copy && paider chat
# prompt: "add // paider-proof comment to src/Receipt.php"
# then: paider cost --json | jq .tiers
```

## Verify
- `vendor/bin/pest` 461 passed, `vendor/bin/pest --group=live` 3 passed with key
- `php build/paider.phar --version` 222ms, `m1/preflight.sh` 6 OK
