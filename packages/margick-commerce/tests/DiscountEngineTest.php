<?php
/**
 * DiscountEngineTest — PURE PHP, no WordPress, no composer needed.
 * Run: php tests/DiscountEngineTest.php   (exit 0 = all pass)
 *
 * Proves the generic engine reproduces the edu mgk_quote() numbers, plus the
 * VND (zero-decimal) case that the old DECIMAL(10,2)/SGD assumption got wrong.
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Domain/Discount/DiscountLine.php';
require __DIR__ . '/../src/Domain/Discount/QuoteRequest.php';
require __DIR__ . '/../src/Domain/Discount/QuoteResult.php';
require __DIR__ . '/../src/Domain/Discount/DiscountEngine.php';

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Discount\DiscountEngine;
use Margick\Commerce\Domain\Discount\DiscountLine;
use Margick\Commerce\Domain\Discount\QuoteRequest;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-18s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) {
        $fail++;
    }
};

$eng = new DiscountEngine();

// Case 1 — trial: base 65 SGD, headline -25 (advertised 40), GST 9% incl, cap 25%.
$r1 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(65, 'SGD'),
    headline: new DiscountLine('headline:trial', 'Trial discount (40%)', 40, Money::ofMajor(25, 'SGD')),
    capPct: 25, gstPct: 9, gstInclusive: true, lineLabel: 'Trial lesson'
));
$check('trial.total', $r1->total->toMajor(), 40.00);
$check('trial.net',   $r1->net->toMajor(),   36.70);
$check('trial.gst',   $r1->gst->toMajor(),   3.30);

// Case 2 — + sibling 3% loyalty (3% of advertised 40 = 1.20), stackable.
$r2 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(65, 'SGD'),
    headline: new DiscountLine('headline:trial', 'Trial discount (40%)', 40, Money::ofMajor(25, 'SGD')),
    loyalty: [new DiscountLine('sibling', 'Sibling discount', 3, Money::ofMajor(1.20, 'SGD'))],
    capPct: 25, gstPct: 9, gstInclusive: true, lineLabel: 'Trial lesson'
));
$check('sibling.total', $r2->total->toMajor(), 38.80);

// Case 3 — cap: loyalty 30% on advertised 40 = 12, but cap 25% = 10 → only 10 taken.
$r3 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('big', 'Big', 30, Money::ofMajor(12, 'SGD'))],
    capPct: 25, gstPct: 0, gstInclusive: true, lineLabel: 'X'
));
$check('cap.total',  $r3->total->toMajor(), 30.00);
$check('cap.capped', $r3->capped, true);

// Case 4 — non-stackable voucher vs loyalty: voucher -5 beats loyalty -1.20 → voucher wins.
$r4 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('sibling', 'Sibling discount', 3, Money::ofMajor(1.20, 'SGD'))],
    voucher: new DiscountLine('voucher:SAVE5', 'Voucher SAVE5', 0, Money::ofMajor(5, 'SGD')),
    voucherStackable: false, capPct: 25, gstPct: 0, lineLabel: 'X'
));
$check('voucher.total', $r4->total->toMajor(), 35.00);

// Case 5 — VND (zero-decimal): base 500000, headline -200000 → 300000, no rounding artefacts.
$r5 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(500000, 'VND'),
    headline: new DiscountLine('h', 'Sale', 40, Money::ofMajor(200000, 'VND')),
    capPct: 25, gstPct: 0, lineLabel: 'Item'
));
$check('vnd.total', $r5->total->toMajor(), 300000.0);

// ── voucher-vs-loyalty conflict (BR-11) — the tie-break bug fix ──
$keys = static fn ($r) => array_map(static fn (DiscountLine $d) => $d->key, $r->applied);

// Case 6 — non-stackable voucher LARGER than loyalty → voucher wins.
$r6 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('sib', 'Sibling', 3, Money::ofMajor(1.20, 'SGD'))],
    voucher: new DiscountLine('voucher:V5', 'Voucher V5', 0, Money::ofMajor(5, 'SGD')),
    voucherStackable: false, capPct: 25, gstPct: 0, lineLabel: 'X'
));
$check('vch>loy.total',   $r6->total->toMajor(), 35.00);
$check('vch>loy.applied', in_array('voucher:V5', $keys($r6), true), true);

// Case 7 — non-stackable voucher SMALLER than loyalty → loyalty wins.
$r7 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('sib', 'Sibling', 20, Money::ofMajor(8, 'SGD'))], // 8 < cap(10)
    voucher: new DiscountLine('voucher:V5', 'Voucher V5', 0, Money::ofMajor(5, 'SGD')),
    voucherStackable: false, capPct: 25, gstPct: 0, lineLabel: 'X'
));
$check('loy>vch.total',   $r7->total->toMajor(), 32.00);
$check('loy>vch.applied', in_array('sib', $keys($r7), true) && ! in_array('voucher:V5', $keys($r7), true), true);

// Case 8 — TIE (loyalty == voucher) → VOUCHER wins (the fix; was loyalty under >=).
$r8 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('sib', 'Sibling', 0, Money::ofMajor(5, 'SGD'))],
    voucher: new DiscountLine('voucher:V5', 'Voucher V5', 0, Money::ofMajor(5, 'SGD')),
    voucherStackable: false, capPct: 25, gstPct: 0, lineLabel: 'X'
));
$check('tie.total',       $r8->total->toMajor(), 35.00);
$check('tie->voucher',    in_array('voucher:V5', $keys($r8), true), true);

// Case 9 — STACKABLE voucher + loyalty → both apply under cap.
$r9 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(40, 'SGD'),
    loyalty: [new DiscountLine('sib', 'Sibling', 0, Money::ofMajor(5, 'SGD'))],
    voucher: new DiscountLine('voucher:V5', 'Voucher V5', 0, Money::ofMajor(5, 'SGD')),
    voucherStackable: true, capPct: 25, gstPct: 0, lineLabel: 'X'
));
$check('stack.total',     $r9->total->toMajor(), 30.00);
$check('stack.both',      count($keys($r9)) === 2, true);

// Case 10 — merchant voucher is NOT silently limited by the loyalty/global cap.
$packageLoyalty = [
    new DiscountLine('sibling', 'Sibling', 5, Money::ofMajor(16, 'SGD')),
    new DiscountLine('returning', 'Returning', 5, Money::ofMajor(16, 'SGD')),
];
$freeVoucher = new DiscountLine('voucher:FREE', 'Voucher FREE', 100, Money::ofMajor(320, 'SGD'));
$r10 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(800, 'SGD'),
    headline: new DiscountLine('headline:package', 'Package', 60, Money::ofMajor(480, 'SGD')),
    loyalty: $packageLoyalty,
    voucher: $freeVoucher,
    voucherStackable: true,
    voucherCapped: false,
    capPct: 25,
    lineLabel: 'Package'
));
$voucherAmount = static function ($result, string $key): float {
    foreach ($result->applied as $line) {
        if ($line->key === $key) return $line->amount->toMajor();
    }
    return -1.0;
};
$check('free.total', $r10->total->toMajor(), 0.00);
$check('free.voucher.full', $voucherAmount($r10, 'voucher:FREE'), 320.00);
$check('free.loyalty.stops', count(array_intersect(['sibling', 'returning'], $keys($r10))), 0);
$check('free.not.capped', $r10->capped, false);

// Case 11 — a voucher can explicitly opt into the shared 25% cap.
$r11 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(800, 'SGD'),
    headline: new DiscountLine('headline:package', 'Package', 60, Money::ofMajor(480, 'SGD')),
    loyalty: $packageLoyalty,
    voucher: $freeVoucher,
    voucherStackable: true,
    voucherCapped: true,
    capPct: 25,
    lineLabel: 'Package'
));
$check('free.capped.total', $r11->total->toMajor(), 240.00);
$check('free.capped.amount', $voucherAmount($r11, 'voucher:FREE'), 48.00);
$check('free.capped.flag', $r11->capped, true);

// Case 12 — exclusive 100% voucher replaces loyalty and discounts advertised price fully.
$r12 = $eng->quote(new QuoteRequest(
    base: Money::ofMajor(800, 'SGD'),
    headline: new DiscountLine('headline:package', 'Package', 60, Money::ofMajor(480, 'SGD')),
    loyalty: $packageLoyalty,
    voucher: $freeVoucher,
    voucherStackable: false,
    voucherCapped: false,
    capPct: 25,
    lineLabel: 'Package'
));
$check('free.exclusive.total', $r12->total->toMajor(), 0.00);
$check('free.exclusive.amount', $voucherAmount($r12, 'voucher:FREE'), 320.00);
$check('free.exclusive.loyalty', count(array_intersect(['sibling', 'returning'], $keys($r12))), 0);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
