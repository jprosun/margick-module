<?php
/**
 * StripeGatewayTest — PURE PHP. Run: php tests/StripeGatewayTest.php
 * Proves the protocol mechanics produce the exact shapes the edu code expects.
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Payment/Stripe/StripeGateway.php';

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Payment\Stripe\StripeGateway;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-26s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) { $fail++; }
};

// ── checkoutParams (SGD: minor = *100) ──
$p = StripeGateway::checkoutParams(
    Money::ofMajor(40, 'SGD'), 'Trial lesson — MGK1', 'MGK1',
    'https://x/ok?session_id={CHECKOUT_SESSION_ID}', 'https://x/cancel',
    ['booking_id' => 7, 'booking_code' => 'MGK1']
);
$check('params.unit_amount',  $p['line_items[0][price_data][unit_amount]'], 4000);
$check('params.currency',     $p['line_items[0][price_data][currency]'], 'sgd');
$check('params.client_ref',   $p['client_reference_id'], 'MGK1');
$check('params.meta.bid',     $p['metadata[booking_id]'], 7);
$check('params.pi_meta.bid',  $p['payment_intent_data[metadata][booking_id]'], 7);

// ── VND (zero-decimal): minor == major, Stripe expects integer as-is ──
$pv = StripeGateway::checkoutParams(Money::ofMajor(390000, 'VND'), 'x', 'R', 'a', 'b');
$check('vnd.unit_amount',     $pv['line_items[0][price_data][unit_amount]'], 390000);
$check('vnd.currency',        $pv['line_items[0][price_data][currency]'], 'vnd');

// ── mockSession deterministic ──
$m = StripeGateway::mockSession('7', 'seed1');
$check('mock.session_prefix',  strncmp($m['session_id'], 'cs_mock_', 8) === 0, true);
$check('mock.intent_prefix',   strncmp($m['intent_id'], 'pi_mock_', 8) === 0, true);
$check('mock.deterministic',   StripeGateway::mockSession('7', 'seed1') === $m, true);

// ── parseEvent: checkout.session.completed ──
$e = StripeGateway::parseEvent([
    'id' => 'evt_1', 'type' => 'checkout.session.completed', 'account' => 'acct_9',
    'data' => ['object' => [
        'id' => 'cs_test_1', 'client_reference_id' => 'MGK1', 'amount_total' => 4000,
        'payment_intent' => 'pi_test_1', 'metadata' => ['booking_id' => '7'],
    ]],
]);
$check('event.type',          $e['type'], 'checkout.session.completed');
$check('event.client_ref',    $e['client_reference'], 'MGK1');
$check('event.amount_minor',  $e['amount_minor'], 4000);
$check('event.intent_id',     $e['intent_id'], 'pi_test_1');
$check('event.meta.bid',      $e['metadata']['booking_id'], '7');

// ── parseEvent: payment_intent.succeeded (id starts pi_) ──
$e2 = StripeGateway::parseEvent([
    'id' => 'evt_2', 'type' => 'payment_intent.succeeded',
    'data' => ['object' => ['id' => 'pi_test_2', 'amount' => 5000]],
]);
$check('pi.intent_id',        $e2['intent_id'], 'pi_test_2');
$check('pi.amount_minor',     $e2['amount_minor'], 5000);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
