<?php
/**
 * PaymentGateway — SEAM for payment providers (defined now, Stripe extracted later).
 * Confirm is ALWAYS from a verified webhook, never from a browser redirect
 * (the discipline already proven in the edu booking-payment-stripe.php).
 */

declare(strict_types=1);

namespace Margick\Commerce\Contracts;

use Margick\Commerce\Domain\Money;

interface PaymentGateway
{
    public function key(): string; // 'stripe' | 'paynow' | 'vnpay' ...

    /** @return array<string,mixed> checkout descriptor (session id, url, mode...) */
    public function createCheckout(int $orderId, Money $amount, string $returnUrl = ''): array;

    /**
     * Process a verified webhook event. Must be idempotent.
     * @param array<string,mixed> $event
     * @return array{ok:bool,message:string}
     */
    public function handleWebhook(array $event): array;
}
