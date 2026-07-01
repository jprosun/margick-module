<?php
/**
 * Invoice — a generic tax-invoice accounting artifact carrying a GAPLESS number.
 * PURE DOMAIN (no WP). Issued once per order when it is paid; the number is
 * allocated atomically (SequenceNumberAllocator) so it is gapless under
 * concurrency (LR-033 / SG GST tax-invoice requirement).
 *
 * Money is carried as Money VOs; the seller (legal entity) + buyer + tax
 * breakdown are a self-sufficient snapshot (CoreSchema LAW 2) so the rendered
 * document never drifts when the site's company details or GST rate later change.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Invoice;

use Margick\Commerce\Domain\Money;

final class Invoice
{
    public const STATUS_ISSUED    = 'ISSUED';
    public const STATUS_VOID      = 'VOID';

    /**
     * @param array<string,mixed> $seller  name, uen, gst_no (snapshot)
     * @param array<string,mixed> $buyer   name, email, user_id (snapshot)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $number,        // gapless, e.g. INV-2026-000042
        public readonly int $orderId,
        public readonly ?string $orderCode,
        public readonly Money $subtotal,
        public readonly Money $discount,
        public readonly Money $tax,
        public readonly Money $total,
        public readonly float $taxRate,        // e.g. 9.0 (percent)
        public readonly bool $taxInclusive,
        public readonly array $seller,
        public readonly array $buyer,
        public readonly string $issuedAtUtc,
        public readonly string $status = self::STATUS_ISSUED
    ) {}

    public function currency(): string
    {
        return $this->total->currency();
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'number'         => $this->number,
            'order_id'       => $this->orderId,
            'order_code'     => $this->orderCode,
            'status'         => $this->status,
            'currency'       => $this->currency(),
            'subtotal'       => $this->subtotal->toMajor(),
            'discount'       => $this->discount->toMajor(),
            'tax'            => $this->tax->toMajor(),
            'total'          => $this->total->toMajor(),
            'tax_rate'       => $this->taxRate,
            'tax_inclusive'  => $this->taxInclusive,
            'seller'         => $this->seller,
            'buyer'          => $this->buyer,
            'issued_at_utc'  => $this->issuedAtUtc,
        ];
    }
}
