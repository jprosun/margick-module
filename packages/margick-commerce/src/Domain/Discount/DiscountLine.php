<?php
/**
 * DiscountLine — one applied discount (PURE DOMAIN).
 * Mirrors the entries persisted in `discount_applied` on the order/booking,
 * so a frozen quote can be re-rendered later, independent of rule edits.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Discount;

use Margick\Commerce\Domain\Money;

final class DiscountLine
{
    public function __construct(
        public readonly string $key,    // 'headline:trial' | 'sibling' | 'voucher:XMAS' ...
        public readonly string $label,
        public readonly int $pct,       // 0 for a fixed-amount discount
        public readonly Money $amount
    ) {}

    public function labelWithPct(): string
    {
        return $this->pct > 0 ? sprintf('%s (%d%%)', $this->label, $this->pct) : $this->label;
    }

    /** Charge-authoritative shape stored on the order row. */
    public function toArray(): array
    {
        return [
            'key'    => $this->key,
            'label'  => $this->label,
            'pct'    => $this->pct,
            'amount' => round($this->amount->toMajor(), $this->amount->scale()),
        ];
    }
}
