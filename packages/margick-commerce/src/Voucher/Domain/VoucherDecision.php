<?php

declare(strict_types=1);

namespace Margick\Commerce\Voucher\Domain;

use Margick\Commerce\Domain\Money;

final class VoucherDecision
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?Voucher $voucher,
        public readonly ?Money $discount
    ) {}

    public static function accept(Voucher $voucher, Money $discount): self
    {
        return new self(true, 'ok', '', $voucher, $discount);
    }

    public static function reject(string $reason, string $message, ?Voucher $voucher = null): self
    {
        return new self(false, $reason, $message, $voucher, null);
    }
}
