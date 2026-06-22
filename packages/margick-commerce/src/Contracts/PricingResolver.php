<?php
/**
 * PricingResolver — INDUSTRY SEAM (defined now, implemented per industry later).
 * Given an item_type + ref id, return its priceable base. The commerce core
 * calls this; it never hardcodes how an edu lesson / retail variant is priced.
 */

declare(strict_types=1);

namespace Margick\Commerce\Contracts;

use Margick\Commerce\Domain\Money;

interface PricingResolver
{
    public function supports(string $itemType): bool;

    /** @param array<string,mixed> $context */
    public function basePrice(string $itemType, int $refId, array $context = []): Money;
}
