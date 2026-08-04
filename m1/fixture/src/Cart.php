<?php

/**
 * A shopping cart: a flat list of line items. No discount concept lives
 * here yet — that is exactly what the M1 rehearsal task adds.
 */
class Cart
{
    /** @var array<int, array{name: string, unitPriceCents: int, qty: int}> */
    private array $items = [];

    public function addItem(string $name, int $unitPriceCents, int $qty): void
    {
        if ($unitPriceCents < 0) {
            throw new InvalidArgumentException("unitPriceCents cannot be negative: {$unitPriceCents}");
        }

        if ($qty < 1) {
            throw new InvalidArgumentException("qty must be at least 1: {$qty}");
        }

        $this->items[] = [
            'name' => $name,
            'unitPriceCents' => $unitPriceCents,
            'qty' => $qty,
        ];
    }

    /** @return array<int, array{name: string, unitPriceCents: int, qty: int}> */
    public function items(): array
    {
        return $this->items;
    }

    public function subtotalCents(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item['unitPriceCents'] * $item['qty'];
        }

        return $total;
    }
}
