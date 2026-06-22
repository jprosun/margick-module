<?php
/**
 * WebhookSignature — verify a Stripe `Stripe-Signature` header (PURE, no WordPress).
 * ================================================================================
 * Standard HMAC-SHA256 scheme (t=…,v1=…) — no SDK needed. Extracted verbatim from
 * the edu booking-payment-stripe.php so the security-critical check has ONE source,
 * is unit-testable, and is reusable by any gateway-backed template.
 *
 * `$now` is injectable purely so the replay window is testable deterministically;
 * production callers omit it (defaults to time()).
 */

declare(strict_types=1);

namespace Margick\Commerce\Payment\Stripe;

final class WebhookSignature
{
    public static function verify(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = 300,
        ?int $now = null
    ): bool {
        if ($secret === '' || $sigHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            $p = explode('=', trim($kv), 2);
            if (count($p) === 2) {
                $parts[$p[0]][] = $p[1];
            }
        }

        $t  = $parts['t'][0] ?? '';
        $v1 = $parts['v1'] ?? [];
        if ($t === '' || ! $v1) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $match    = false;
        foreach ($v1 as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $match = true;
                break;
            }
        }
        if (! $match) {
            return false;
        }

        $now ??= time();
        return abs($now - (int) $t) <= $tolerance; // replay window
    }
}
