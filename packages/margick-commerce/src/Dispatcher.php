<?php
/**
 * Dispatcher — the industry seam router (the glue the Contracts were missing).
 * ============================================================================
 * The core (OrderRepository) is industry-BLIND: it never interprets item_type.
 * But "what does X cost?" and "payment confirmed → what happens?" ARE industry
 * questions. The Contracts define those seams (PricingResolver, FulfillmentHandler);
 * this is where industries REGISTER their implementations and the core asks by
 * item_type — without ever naming an industry.
 *
 * Dispatch rule: the first registered handler whose supports($itemType) is true
 * wins. Pricing returns null when no resolver claims the type (some items carry
 * an explicit price and don't need a resolver). Fulfillment runs EVERY matching
 * handler and reports whether at least one ran (so a missing handler is visible,
 * never a silent no-op on a paid order).
 *
 * Static by design — matches the codebase idiom (CoreSchema/OrderRepository) and
 * fits WP's one-process-per-request model where industries wire themselves at
 * boot. reset() exists for tests only.
 */

declare(strict_types=1);

namespace Margick\Commerce;

use Margick\Commerce\Contracts\FulfillmentHandler;
use Margick\Commerce\Contracts\PricingResolver;
use Margick\Commerce\Domain\Money;

final class Dispatcher
{
    /** @var array<int,PricingResolver> */
    private static array $pricers = [];

    /** @var array<int,FulfillmentHandler> */
    private static array $fulfillers = [];

    public static function registerPricing(PricingResolver $resolver): void
    {
        self::$pricers[] = $resolver;
    }

    public static function registerFulfillment(FulfillmentHandler $handler): void
    {
        self::$fulfillers[] = $handler;
    }

    /**
     * Resolve the base price for a sellable. Returns null when no resolver claims
     * the item_type — the caller then falls back to an explicit price (e.g. the
     * snapshot it already holds).
     *
     * @param array<string,mixed> $context
     */
    public static function priceFor(string $itemType, int $refId, array $context = []): ?Money
    {
        foreach (self::$pricers as $resolver) {
            if ($resolver->supports($itemType)) {
                return $resolver->basePrice($itemType, $refId, $context);
            }
        }
        return null;
    }

    public static function hasPricing(string $itemType): bool
    {
        foreach (self::$pricers as $resolver) {
            if ($resolver->supports($itemType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run fulfillment for a paid item across every matching handler. Returns true
     * if at least one handler ran — false means NOTHING handled a paid item, which
     * a caller should treat as an error/alert, not a success.
     *
     * @param array<string,mixed> $order the core order row (LAW-2 self-sufficient)
     */
    public static function fulfill(string $itemType, int $refId, array $order): bool
    {
        $ran = false;
        foreach (self::$fulfillers as $handler) {
            if ($handler->supports($itemType)) {
                $handler->fulfill($itemType, $refId, $order);
                $ran = true;
            }
        }
        return $ran;
    }

    public static function hasFulfillment(string $itemType): bool
    {
        foreach (self::$fulfillers as $handler) {
            if ($handler->supports($itemType)) {
                return true;
            }
        }
        return false;
    }

    /** Test-only: clear all registrations. */
    public static function reset(): void
    {
        self::$pricers    = [];
        self::$fulfillers = [];
    }
}
