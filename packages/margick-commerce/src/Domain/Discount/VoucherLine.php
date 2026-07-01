<?php
/**
 * VoucherLine — one voucher to stack, evaluated on the REMAINING amount (PURE DOMAIN).
 * ===================================================================================
 * Unlike a loyalty DiscountLine (a fixed pre-computed amount on the advertised
 * price), a stacked voucher must be re-evaluated on whatever is left AFTER the
 * vouchers before it — so a percent voucher takes its cut of the running balance,
 * not the original base. This VO carries the voucher's *intent* (percent/fixed +
 * value + per-voucher cap) so the engine can compute the real deduction in order.
 *
 * Stacking metadata (MVP):
 *   - standalone: this voucher cannot be combined with ANY other voucher.
 *   - stackGroup: at most one voucher per non-empty group may apply (dedupe by
 *     group). Empty group = ungrouped (no group restriction).
 * Industry adapters (edu, retail, …) translate their voucher CPT/rows into these.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Discount;

use Margick\Commerce\Domain\Money;

final class VoucherLine
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED   = 'fixed';

    public function __construct(
        public readonly string $code,             // customer-facing code (also the dedupe identity)
        public readonly string $label,            // display label, e.g. "Voucher XMAS20"
        public readonly string $type,             // TYPE_PERCENT | TYPE_FIXED
        public readonly float $value,             // percent (e.g. 20) OR fixed major amount (e.g. 5.00)
        public readonly ?Money $maxDiscount = null, // per-voucher ceiling (percent vouchers), null = none
        public readonly bool $standalone = false,   // cannot combine with any other voucher
        public readonly string $stackGroup = '',    // at most one applied per non-empty group
        public readonly int $priority = 0           // lower applies first (stable order for ties)
    ) {}

    /**
     * The deduction this voucher takes from the CURRENT remaining balance.
     * Percent → % of remaining, capped by maxDiscount; fixed → the fixed amount.
     * Both are clamped to the remaining balance by the engine (never negative).
     */
    public function deductionOn(Money $remaining): Money
    {
        if ($this->type === self::TYPE_FIXED) {
            $amt = Money::ofMajor($this->value, $remaining->currency());
        } else {
            $amt = $remaining->percentage($this->value);
            if ($this->maxDiscount !== null && $amt->greaterThan($this->maxDiscount)) {
                $amt = $this->maxDiscount;
            }
        }
        // Never deduct more than what's left.
        return $amt->greaterThan($remaining) ? Money::ofMinor($remaining->minor(), $remaining->currency()) : $amt;
    }

    /** Percentage shown in the breakdown line (0 for fixed vouchers). */
    public function displayPct(): int
    {
        return $this->type === self::TYPE_PERCENT ? (int) round($this->value) : 0;
    }
}
