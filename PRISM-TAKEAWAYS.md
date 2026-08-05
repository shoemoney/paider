# What `prism-php/prism` is worth to Paider

Read at **v0.100.1 / commit `5d6cc65`, 2026-03-20** — the project's last commit. Everything below
is a pattern lifted from a codebase that has since stopped. **Take the shapes, not the
maintenance.**

The question this file answers is not "should we depend on Prism" (no — see the last section) but
"they got 2,407 stars solving problems we are about to hit, so what did they learn?"

---

## The four worth taking

### 1. Structured output is a per-provider strategy, not one path

`src/Providers/Anthropic/Handlers/StructuredStrategies/` — a directory, not a method. Structured
output is treated as a **negotiated capability**: native mode where the provider supports it,
prompt-coercion plus validation where it does not, and a typed decoding failure either way
(`PrismStructuredDecodingException`).

**Why Paider needs this specifically.** `config/presets.php:95` and DECISIONS.md §4 both record
that `qwen3.7-flash` — the shipped default **coder** tier, the model that writes every diff —
reports `structured_outputs=false`. The mitigation currently in the repo is a code comment:

> *"malformed diffs are the first thing to suspect if they appear"*

That is a note, not a mechanism. A per-provider strategy turns it into behaviour: coerce, validate,
and fail loudly with a named exception instead of silently emitting a bad patch.

It is also the missing piece under a promise already made. PLAN.md's v1.0 milestone commits to a
*"measured, published diff-apply success rate on the default coder tier."* A strategy layer is what
makes that number **improvable** rather than merely observable — without it you can report the
failure rate but have nowhere to put a fix.

### 2. Typed exceptions for the failures that actually happen

```
PrismRateLimitedException        PrismProviderOverloadedException
PrismRequestTooLargeException    PrismStructuredDecodingException
PrismStreamDecodeException
```

plus a `ProviderRateLimit` value object.

**Paider today:** both clients throw a bare `\RuntimeException` for a missing key
(`AnthropicClient.php:29`, `OpenAiCompatibleClient.php:30`) and otherwise let Guzzle's exceptions
escape. There is no `app/Exceptions` directory at all.

Those five names are a map of what actually breaks in production against LLM APIs. The distinction
that matters most is **overloaded vs. rate-limited**, because they imply different responses —
retry immediately, back off, or switch tier. That is exactly the decision DECISIONS.md §5's
multi-account rotation hypothesis would have to make, and it cannot be made from a
`RuntimeException`.

### 3. `Maps/` — translation split from transport

`src/Providers/Anthropic/Maps/` holds `MessageMap`, `ToolMap`, `ToolChoiceMap`, `FinishReasonMap`,
`ImageMapper`, `DocumentMapper`, `CitationsMapper` — each a small class doing one shape
translation, kept out of the transport class.

**Paider today:** `AnthropicClient::send()` does transport *and* translation inline — system-message
extraction at lines 32-34, and the `array_merge` ordering at 42-45 that deliberately prevents
`model` from being overridden by caller options.

**This is a trigger, not a task.** At two providers, inline is correct and churning it would be
refactoring for its own sake. It becomes worth doing at the **third provider with a different
tool-call shape**, which is the moment conditionals start accumulating inside `send()`. Written
down now so the trigger is recognised rather than rediscovered.

### 4. Ship test fakes for your consumers

`src/Testing/` — `PrismFake`, `TextResponseFake`, `StructuredResponseFake`, `EmbeddingsResponseFake`,
`ImageResponseFake`, and per-step fakes. Shipped **inside the package**, so an application that
depends on Prism can test its own code without live calls.

**Paider is a package that lives inside someone's Laravel app** — that is bet #1 in the README. So
the same question applies and nobody has asked it: how does a host app test *its* code against
Paider's tools without spending money? Paider's own live suite costs real money and is opt-in for
exactly this reason. This is that problem, one level out.

---

## One to note, not copy

`src/Rectors/` ships **automated upgrade rules for its own breaking changes** — a Rector rule that
rewrites consumer code when an API moves. That is an unusually mature answer for a pre-1.0 package,
and it is directly relevant to Paider's own pinning problem: v0.2 pins `mcp/sdk` to an exact
version precisely because pre-1.0 minors may break. Prism's answer to being pre-1.0 was to make its
breaks migratable rather than to ask consumers to pin forever.

Filed as interesting. Building Rector rules for a project with no external consumers yet would be
premature.

---

## Structure worth glancing at

- **Modality separation at the top level** — `Text/`, `Structured/`, `Streaming/`, `Embeddings/`,
  `Images/`, `Audio/`, `Moderation/` as sibling directories rather than flags on one request object.
- **Per-provider internal shape** — every provider under `src/Providers/<Name>/` carries the same
  `Handlers/`, `Maps/`, `Parsers/`, `ValueObjects/`, `Enums/` layout. Thirteen providers, one shape.
- **`Contracts/`** is small — `PrismRequest`, `Schema`, `Message`, `ProviderRequestMapper`. The
  abstraction surface is narrow even though the implementation surface is wide.

---

## Why we are not adopting the package

Two independent reasons, both measured 2026-08-05:

**It is not maintained.** Last release v0.100.1 on 2026-03-20; GitHub `pushed_at` is the *same
date*, and there have been **zero commits in 90 days**. 114 open issues, not archived. Release
cadence was not lagging behind active work — the work stopped.

**It declares a dependency it does not use.** `require: laravel/framework ^11.0|^12.0|^13.0`, while
Paider is built on `laravel-zero/framework` — illuminate components without the framework. But
measured across its 359 source files, Prism actually imports only:

| import | uses |
|---|---|
| `Illuminate\Http\Client\*` (PendingRequest, Response, RequestException) | 139 |
| `Illuminate\Support\{Arr, Collection, Str, Carbon}` | 83 |
| `Illuminate\Contracts\*` | 31 files |
| Facades | 8 files |

`Illuminate\Database`, `Queue`, `Events`, `Bus` — **zero**. `Container` — one file. `app()` — twice.

So `laravel/framework` is **over-declaration**, and a fork could swap it for `illuminate/http` +
`illuminate/support` + `illuminate/contracts`. That is plausible work, not a rewrite.

**An earlier assessment in this repo called Prism "architecturally incompatible." That was wrong**
and is corrected here and in PLAN.md. The objection to forking is not structural — it is that a
fork means owning an LLM abstraction across thirteen providers' API drift, permanently, for
something that would replace only the transport layer in `app/Providers/`. Paider's named tiers,
write-time pricing and served-vs-requested reconciliation — the parts that are actually Paider —
are not things Prism models.

DECISIONS.md §1 founded this project on the argument that becoming fork #4,806 of a stalled project
is low leverage and the real opportunity is the vacuum. Prism's 307 forks with no consolidated
successor is that same vacuum shape. **A live option, deliberately not taken — not a closed door.**

---

## Method note

Everything here was read from the published dist tarball of v0.100.1, not from the README or docs.
One probe went wrong and is worth recording: an early check reported **`0 test files`**, which is
the dist tarball export-ignoring `tests/` — not Prism lacking tests. Same class of mistake as
reading a `git archive` and concluding a file was never committed. The counts above come from
`grep` over `src/` and are reproducible from the same tarball.
