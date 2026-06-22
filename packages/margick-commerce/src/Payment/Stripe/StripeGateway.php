<?php
/**
 * StripeGateway — PURE Stripe protocol mechanics (no WordPress, no I/O, no booking).
 * ================================================================================
 * The reusable "how Stripe is shaped" knowledge: build Checkout Session params,
 * synthesize a MOCK session, normalize a webhook event. Extracted from the edu
 * booking-payment-stripe.php so every gateway-backed template shares ONE source.
 *
 * What stays in the app (edu): the HTTP transport (wp_remote_post + keys), the
 * booking row read/write, status transitions, locks, fulfillment. This class only
 * knows the Stripe wire format.
 */

declare(strict_types=1);

namespace Margick\Commerce\Payment\Stripe;

use Margick\Commerce\Domain\Money;

final class StripeGateway
{
    /**
     * Build Checkout Session params. PURE — caller supplies the success/cancel URLs
     * already built (those need app routing). Amount drives Stripe's smallest-unit
     * `unit_amount` via Money (correct for zero-decimal currencies like VND too).
     *
     * @param array<string,int|string> $metadata mirrored into session + payment_intent
     * @return array<string,int|string>
     */
    public static function checkoutParams(
        Money $amount,
        string $productName,
        string $clientReference,
        string $successUrl,
        string $cancelUrl,
        array $metadata = []
    ): array {
        $params = [
            'mode'                                          => 'payment',
            'success_url'                                   => $successUrl,
            'cancel_url'                                    => $cancelUrl,
            'client_reference_id'                           => $clientReference,
            'line_items[0][quantity]'                       => 1,
            'line_items[0][price_data][currency]'           => strtolower($amount->currency()),
            'line_items[0][price_data][unit_amount]'        => $amount->minor(),
            'line_items[0][price_data][product_data][name]' => $productName,
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[$k]"]                       = $v;
            $params["payment_intent_data[metadata][$k]"]  = $v;
        }
        return $params;
    }

    /**
     * Deterministic MOCK session/intent ids (used when no live key). PURE.
     * @return array{mode:string,session_id:string,intent_id:string}
     */
    public static function mockSession(string $reference, string $seed): array
    {
        return [
            'mode'       => 'mock',
            'session_id' => 'cs_mock_' . substr(md5($reference . $seed), 0, 20),
            'intent_id'  => 'pi_mock_' . substr(md5('pi' . $reference . $seed), 0, 20),
        ];
    }

    /**
     * Normalize a Stripe-style event into the fields fulfillment needs. PURE.
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function parseEvent(array $event): array
    {
        $object = (array) ($event['data']['object'] ?? []);
        return [
            'id'               => (string) ($event['id'] ?? ''),
            'type'             => (string) ($event['type'] ?? ''),
            'account'          => (string) ($event['account'] ?? ''),
            'object'           => $object,
            'metadata'         => (array) ($object['metadata'] ?? []),
            'client_reference' => (string) ($object['client_reference_id'] ?? ''),
            'session_id'       => (string) ($object['id'] ?? ''),
            'intent_id'        => self::paymentIntentId($object),
            'amount_minor'     => isset($object['amount_total']) ? (int) $object['amount_total']
                                : (isset($object['amount']) ? (int) $object['amount'] : null),
        ];
    }

    /** Extract a PaymentIntent id from a Checkout Session or PI object. PURE. */
    public static function paymentIntentId(array $object): string
    {
        $id = (string) ($object['id'] ?? '');
        if (strncmp($id, 'pi_', 3) === 0) {
            return $id;
        }
        $pi = $object['payment_intent'] ?? '';
        if (is_array($pi)) {
            $pi = (string) ($pi['id'] ?? '');
        }
        $pi = (string) $pi;
        return strncmp($pi, 'pi_', 3) === 0 ? $pi : '';
    }
}
