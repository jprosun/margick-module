<?php
/**
 * QuoteRequest — input to the DiscountEngine (PURE DOMAIN).
 * ========================================================
 * The INDUSTRY decides WHAT discounts apply (headline, loyalty candidates,
 * voucher) and feeds them here. The engine only decides HOW they combine
 * (conflict → stack → cap → GST). This is the seam between edu-specific
 * eligibility (sibling/returning/trial) and generic commerce math.
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Discount;

use Margick\Commerce\Domain\Money;

final class QuoteRequest
{
    /**
     * @param DiscountLine[] $loyalty  automatic discounts on the advertised price
     */
    public function __construct(
        public readonly Money $base,                 // discountable base (e.g. rate, package base)
        public readonly ?DiscountLine $headline = null, // advertised discount — NOT capped (it is the price)
        public readonly array $loyalty = [],
        public readonly ?DiscountLine $voucher = null,
        public readonly bool $voucherStackable = false, // BR-11: non-stackable picks better-for-customer side
        public readonly int $capPct = 25,            // ceiling on stacked EXTRA discounts (% of advertised)
        public readonly int $gstPct = 0,
        public readonly bool $gstInclusive = true,
        public readonly string $lineLabel = 'Item',
        public readonly bool $voucherCapped = false // explicit opt-in: voucher shares the global EXTRA cap
    ) {}
}
