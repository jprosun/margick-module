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

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
