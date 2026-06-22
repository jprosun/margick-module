<?php

declare(strict_types=1);

namespace Margick\Commerce\Voucher\Domain;

use Margick\Commerce\Domain\Money;

/** Charge-authoritative context used to decide whether a voucher is eligible. */
final class VoucherContext
{
    /** @param string[] $itemTypes */
    public function __construct(
        public readonly Money $eligibleAmount,
        public readonly array $itemTypes = [],
        public readonly ?string $customerKey = null,
        public readonly bool $firstOrder = false,
        ?\DateTimeImmutable $now = null
    ) {
        $this->now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public readonly \DateTimeImmutable $now;
}
