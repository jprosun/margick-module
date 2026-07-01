<?php
/**
 * CreditNote — a generic "reduce charge" accounting artifact (a negative invoice)
 * carrying a GAPLESS number. PURE DOMAIN. Issued when a refund is processed; the
 * number is allocated atomically (SequenceNumberAllocator) so it is gapless under
 * concurrency (LR-033 / SRS BR-07 credit-note requirement).
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\CreditNote;

use Margick\Commerce\Domain\Money;

final class CreditNote
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,     // gapless, e.g. CN-2026-000017
        public readonly int $orderId,
        public readonly Money $amount,
        public readonly string $reason,
        public readonly string $issuedAtUtc
    ) {}

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'number'        => $this->number,
            'order_id'      => $this->orderId,
            'amount'        => $this->amount->toMajor(),
            'currency'      => $this->amount->currency(),
            'reason'        => $this->reason,
            'issued_at_utc' => $this->issuedAtUtc,
        ];
    }
}
