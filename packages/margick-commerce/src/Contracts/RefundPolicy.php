<?php
/**
 * RefundPolicy — the SEAM for "HOW MUCH is refundable". Template-owned, exactly
 * like DiscountRulesProvider: the module executes + records the refund and issues
 * the credit note, but the AMOUNT is decided by the template's policy (edu = BR-07
 * notice tiers for trials/lessons + pro-rata-on-unused for packages).
 *
 * Keeping the policy behind this interface is what lets a beauty/clinic template
 * ship a different cancellation rule with ZERO change to the refund mechanics.
 */

declare(strict_types=1);

namespace Margick\Commerce\Contracts;

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Refund\RefundOutcome;

interface RefundPolicy
{
    /**
     * Decide the refundable outcome for a paid amount given app signals.
     *
     * @param Money               $paid    what was actually captured
     * @param array<string,mixed> $context policy signals (e.g. hours_out, plan,
     *                                      lessons_total, lessons_used, no_show)
     */
    public function outcome(Money $paid, array $context = []): RefundOutcome;
}
