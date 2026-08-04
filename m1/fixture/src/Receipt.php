<?php

/**
 * Builds a receipt from a Cart: subtotal, flat 8% tax on the subtotal, total.
 * No discount step yet — the rehearsal task adds one, applied to the
 * subtotal BEFORE tax is computed.
 */
class Receipt
{
    private const TAX_RATE = 0.08;

    public function build(Cart $cart): array
    {
        $subtotalCents = $cart->subtotalCents();
        $taxCents = (int) round($subtotalCents * self::TAX_RATE);
        $totalCents = $subtotalCents + $taxCents;

        return [
            'subtotalCents' => $subtotalCents,
            'taxCents' => $taxCents,
            'totalCents' => $totalCents,
        ];
    }
}
