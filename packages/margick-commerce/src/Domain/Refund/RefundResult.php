<?php
/**
 * RefundResult — outcome of EXECUTING a refund through a gateway. PURE DOMAIN.
 * Mirrors the shape the edu booking-payment-stripe.php already returns, so the
 * theme can delegate the mechanics here with a 1:1 lift (no behaviour change).
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Refund;

use Margick\Commerce\Domain\Money;

final class RefundResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly Money $refunded,
        public readonly bool $full,
        public readonly string $mode,      // 'live' | 'mock' | 'none'
        public readonly string $refundId
    ) {}

    /** Nothing to refund (unpaid hold, or amount <= 0). */
    public static function none(string $currency): self
    {
        return new self(true, Money::zero($currency), false, 'none', '');
    }

    /** Bridge to the legacy edu return shape (float major) for the theme glue. */
    public function toArray(): array
    {
        return [
            'ok'        => $this->ok,
            'refunded'  => $this->refunded->toMajor(),
            'full'      => $this->full,
            'mode'      => $this->mode,
            'refund_id' => $this->refundId,
        ];
    }
}
