<?php
/**
 * WebhookSignatureTest — PURE PHP, no WordPress. Run: php tests/WebhookSignatureTest.php
 * Proves the HMAC-SHA256 verify accepts a valid signature and rejects tamper / stale / wrong-secret.
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Payment/Stripe/WebhookSignature.php';

use Margick\Commerce\Payment\Stripe\WebhookSignature;

$fail = 0;
$check = static function (string $label, bool $got, bool $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-22s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) {
        $fail++;
    }
};

$secret  = 'whsec_test_123';
$payload = '{"id":"evt_1","type":"checkout.session.completed"}';
$ts      = 1_700_000_000;                       // fixed timestamp (deterministic)
$sig     = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$header  = "t={$ts},v1={$sig}";

$check('valid signature',     WebhookSignature::verify($payload, $header, $secret, 300, $ts),        true);
$check('within tolerance',    WebhookSignature::verify($payload, $header, $secret, 300, $ts + 299),  true);
$check('stale (replay)',      WebhookSignature::verify($payload, $header, $secret, 300, $ts + 301),  false);
$check('tampered payload',    WebhookSignature::verify($payload . 'x', $header, $secret, 300, $ts),  false);
$check('wrong secret',        WebhookSignature::verify($payload, $header, 'whsec_wrong', 300, $ts),  false);
$check('empty secret',        WebhookSignature::verify($payload, $header, '', 300, $ts),             false);
$check('garbage header',      WebhookSignature::verify($payload, 'nonsense', $secret, 300, $ts),     false);

// Multiple v1 candidates (Stripe sends >1 during secret rotation) — any match passes.
$check('multi v1 (one good)', WebhookSignature::verify($payload, "t={$ts},v1=deadbeef,v1={$sig}", $secret, 300, $ts), true);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
