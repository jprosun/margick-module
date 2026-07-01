<?php
/**
 * RefundMechanicsTest — PURE PHP. Run: php tests/RefundMechanicsTest.php
 * Proves the refund protocol params, mock id shape, and value objects match the
 * exact shapes the edu booking-payment-stripe.php expects (1:1 lift, mock = byte-identical).
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Domain/Refund/RefundOutcome.php';
require __DIR__ . '/../src/Domain/Refund/RefundResult.php';
require __DIR__ . '/../src/Domain/CreditNote/CreditNote.php';
require __DIR__ . '/../src/Payment/Stripe/StripeGateway.php';

use Margick\Commerce\Domain\CreditNote\CreditNote;
use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Refund\RefundOutcome;
use Margick\Commerce\Domain\Refund\RefundResult;
use Margick\Commerce\Payment\Stripe\StripeGateway;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-30s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) { $fail++; }
};

// ── refundParams (SGD: minor = *100; metadata mirrored) ──
$rp = StripeGateway::refundParams('pi_123', Money::ofMajor(40, 'SGD'), ['booking_id' => 7]);
$check('refund.payment_intent', $rp['payment_intent'], 'pi_123');
$check('refund.amount_minor',   $rp['amount'], 4000);
$check('refund.meta.bid',       $rp['metadata[booking_id]'], 7);

// ── refundParams partial (SGD 20.00 of a 40 paid) ──
$rpp = StripeGateway::refundParams('pi_123', Money::ofMajor(20, 'SGD'));
$check('refund.partial_minor',  $rpp['amount'], 2000);

// ── VND zero-decimal: minor == major ──
$rv = StripeGateway::refundParams('pi_v', Money::ofMajor(390000, 'VND'));
$check('refund.vnd_minor',      $rv['amount'], 390000);

// ── mockRefund: deterministic + edu id shape (rf_mock_ + md5(ref:minor:seed)[0..18]) ──
$mr  = StripeGateway::mockRefund('7', 4000, 'SEED');
$exp = 'rf_mock_' . substr(md5('7:4000:SEED'), 0, 18);
$check('mock.refund_id',         $mr['refund_id'], $exp);
$check('mock.mode',              $mr['mode'], 'mock');
$check('mock.deterministic',     StripeGateway::mockRefund('7', 4000, 'SEED')['refund_id'], $mr['refund_id']);

// ── idempotency key shape (matches edu 'mgk_refund_<ref>_<minor>') ──
$check('idem.key',               StripeGateway::refundIdempotencyKey('7', 4000), 'mgk_refund_7_4000');

// ── RefundResult value object + legacy bridge ──
$res = new RefundResult(true, Money::ofMajor(20, 'SGD'), false, 'mock', 'rf_mock_x');
$check('result.toArray.refunded', $res->toArray()['refunded'], 20.0);
$check('result.toArray.full',     $res->toArray()['full'], false);
$check('result.toArray.refundid', $res->toArray()['refund_id'], 'rf_mock_x');
$none = RefundResult::none('SGD');
$check('result.none.refunded',    $none->toArray()['refunded'], 0.0);
$check('result.none.mode',        $none->mode, 'none');

// ── RefundOutcome (policy result) ──
$out = new RefundOutcome(Money::ofMajor(20, 'SGD'), 50, 'half', '50% (24-48h)');
$check('outcome.toArray.amount', $out->toArray()['amount'], 20.0);
$check('outcome.toArray.tier',   $out->toArray()['tier'], 'half');
$check('outcome.isZero',         (new RefundOutcome(Money::zero('SGD'), 0, 'none', '<24h'))->isZero(), true);

// ── CreditNote value object ──
$cn = new CreditNote(5, 'CN-2026-000017', 88, Money::ofMajor(40, 'SGD'), 'Trial cancelled', '2026-06-29 00:00:00');
$check('creditnote.number',      $cn->toArray()['number'], 'CN-2026-000017');
$check('creditnote.amount',      $cn->toArray()['amount'], 40.0);
$check('creditnote.currency',    $cn->toArray()['currency'], 'SGD');

echo $fail === 0 ? "\nALL PASS\n" : "\n{$fail} FAILED\n";
exit($fail === 0 ? 0 : 1);
