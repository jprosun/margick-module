<?php
/**
 * FulfillmentHandler — INDUSTRY SEAM (defined now, implemented later).
 * "Payment confirmed → what happens?" edu: confirm booking + provision login.
 * retail: decrement stock. Called ONLY after a verified payment confirmation.
 */

declare(strict_types=1);

namespace Margick\Commerce\Contracts;

interface FulfillmentHandler
{
    public function supports(string $itemType): bool;

    /** @param array<string,mixed> $order */
    public function fulfill(string $itemType, int $refId, array $order): void;
}
