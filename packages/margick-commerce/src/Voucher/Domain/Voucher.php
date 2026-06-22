<?php

declare(strict_types=1);

namespace Margick\Commerce\Voucher\Domain;

use Margick\Commerce\Domain\Money;

/** Immutable, industry-blind definition of one customer-facing voucher code. */
final class Voucher
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const STATUS_ACTIVE = 'active';

    /**
     * @param string[] $appliesTo Exact item_type values. Empty means every item type.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $status,
        public readonly string $discountType,
        public readonly int $percentageBps = 0,
        public readonly int $fixedAmountMinor = 0,
        public readonly ?string $currency = null,
        public readonly int $minOrderMinor = 0,
        public readonly ?int $maxDiscountMinor = null,
        public readonly bool $stackable = false,
        public readonly ?int $usageLimit = null,
        public readonly ?int $usageLimitPerCustomer = null,
        public readonly ?string $customerKey = null,
        public readonly bool $firstOrderOnly = false,
        public readonly array $appliesTo = [],
        public readonly ?\DateTimeImmutable $startsAt = null,
        public readonly ?\DateTimeImmutable $endsAt = null
    ) {}

    public static function normalizeCode(string $code): string
    {
        return \strtoupper((string) \preg_replace('/\s+/', '', \trim($code)));
    }

    public static function normalizeCustomerKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        $normalized = \strtolower(\trim($key));
        return $normalized === '' ? null : $normalized;
    }

    public function discountFor(Money $eligibleAmount): Money
    {
        if ($this->discountType === self::TYPE_FIXED) {
            if ($this->currency === null || \strtoupper($this->currency) !== $eligibleAmount->currency()) {
                return Money::zero($eligibleAmount->currency());
            }
            $minor = $this->fixedAmountMinor;
        } elseif ($this->discountType === self::TYPE_PERCENT) {
            $minor = (int) \round($eligibleAmount->minor() * $this->percentageBps / 10_000);
        } else {
            return Money::zero($eligibleAmount->currency());
        }

        if ($this->maxDiscountMinor !== null) {
            $minor = \min($minor, $this->maxDiscountMinor);
        }
        return Money::ofMinor(\max(0, \min($minor, $eligibleAmount->minor())), $eligibleAmount->currency());
    }
}
