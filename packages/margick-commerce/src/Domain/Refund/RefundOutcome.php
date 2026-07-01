<?php
/**
 * RefundOutcome — what a RefundPolicy DECIDES: how much of a paid amount is
 * refundable, and why. PURE DOMAIN (no I/O). The module executes + records;
 * the template's policy (e.g. edu BR-07 tiers / package pro-rata) decides this.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Refund;

use Margick\Commerce\Domain\Money;

final class RefundOutcome
{
    public function __construct(
        public readonly Money $amount,   // amount to refund (always <= paid)
        public readonly int $pct,        // 0..100, for display
        public readonly string $tier,    // policy tier key e.g. 'full'|'half'|'none'|'prorata'
        public readonly string $basis    // human basis e.g. '50% (24-48h)' / '3 of 8 lessons unused'
    ) {}

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    /** @return array{amount:float,pct:int,tier:string,basis:string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount->toMajor(),
            'pct'    => $this->pct,
            'tier'   => $this->tier,
            'basis'  => $this->basis,
        ];
    }
}
