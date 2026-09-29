<?php

namespace App\Providers\Contracts;

/**
 * Turns text into vectors. An interface, not a class, so RAG never hard-depends on a vendor.
 *
 * Kept separate from ProviderClient on purpose: that contract is message-in/message-out for a
 * chat completion, and an embedding endpoint is a different wire format with different failure
 * modes (a batch returns an array in provider order, not a message). Forcing embeddings through
 * ProviderClient would mean either widening it for everyone's benefit or lying about the shape.
 */
interface EmbeddingClient
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>> Vectors, in the SAME ORDER as $inputs.
     */
    public function embed(array $inputs): array;

    /**
     * The exact model id used, for the cost ledger. Not a preset and not an alias: the ledger
     * prices by exact id (config/prices.php) precisely so a price can never be looked up against
     * the wrong thing.
     */
    public function model(): string;

    /**
     * Total tokens consumed by the last embed() call, for ledger reconciliation.
     *
     * Embeddings are billed per input token and produce no output tokens, so this is what
     * ModelPricing::costFor() needs. Returning 0 rather than null matters: a provider that does
     * not report usage must surface as an unpriced call, which the ledger distinguishes from a
     * genuine $0.00 — see the LOCKED all-four-zero rule in ModelPricing.
     */
    public function lastTokenCount(): int;
}
