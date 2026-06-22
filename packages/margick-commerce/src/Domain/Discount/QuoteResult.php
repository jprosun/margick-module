<?php
/**
 * QuoteResult — output of the DiscountEngine (PURE DOMAIN).
 * Carries BOTH the machine numbers (for the charge) and display rows (for UI),
 * so the displayed total can never diverge from the charged total.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Discount;

use Margick\Commerce\Domain\Money;

final class QuoteResult
{
    /**
     * @param array<int,array{label:string,value:string,discount:bool}> $rows
     * @param DiscountLine[] $applied
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $applied,
        public readonly Money $base,
        public readonly Money $advertised,
        public readonly Money $subtotal,
        public readonly Money $total,   // ← exact amount the gateway charges
        public readonly Money $net,     // ← e-invoice: pre-tax
        public readonly Money $gst,     // ← e-invoice: tax component
        public readonly bool $capped,
        public readonly int $gstPct,
        public readonly bool $gstInclusive
    ) {}

    /** Persisted on order.discount_applied (charge-authoritative, frozen). */
    public function appliedToArray(): array
    {
        return array_map(static fn (DiscountLine $d) => $d->toArray(), $this->applied);
    }
}
