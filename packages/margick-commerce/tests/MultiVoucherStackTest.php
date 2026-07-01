<?php
/**
 * Pure test: multi-voucher stacking on the running balance.
 * Run: php tests/MultiVoucherStackTest.php  (or inside the container's php).
 */
declare(strict_types=1);

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Domain/Discount/DiscountLine.php';
require __DIR__ . '/../src/Domain/Discount/VoucherLine.php';
require __DIR__ . '/../src/Domain/Discount/QuoteRequest.php';
require __DIR__ . '/../src/Domain/Discount/QuoteResult.php';
require __DIR__ . '/../src/Domain/Discount/DiscountEngine.php';

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Discount\{DiscountEngine, DiscountLine, VoucherLine, QuoteRequest};

$eng = new DiscountEngine();
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $name\n"; }
    else { $fail++; echo "  FAIL $name  $extra\n"; }
}
function pct(float $v, string $code, array $o = []): VoucherLine {
    return new VoucherLine($code, "Voucher $code", VoucherLine::TYPE_PERCENT, $v,
        $o['max'] ?? null, $o['standalone'] ?? false, $o['group'] ?? '', $o['priority'] ?? 0);
}
function fixed(float $v, string $code, array $o = []): VoucherLine {
    return new VoucherLine($code, "Voucher $code", VoucherLine::TYPE_FIXED, $v,
        null, $o['standalone'] ?? false, $o['group'] ?? '', $o['priority'] ?? 0);
}
function total(QuoteRequest $r): float { global $eng; return round($eng->quote($r)->total->toMajor(), 2); }

$SGD = 'SGD';
$base = Money::ofMajor(100, $SGD);

// 1. Two percent vouchers stack SEQUENTIALLY on the remaining balance (not both on base).
//    100 → -20% = 80 → -10% of 80 = 72   (NOT 100 - 30 = 70)
$r = new QuoteRequest(base: $base, vouchers: [pct(20, 'A'), pct(10, 'B')]);
ok('seq percent-on-remaining', total($r) === 72.00, 'got ' . total($r));

// 2. Percent + fixed sequential: 100 → -20% = 80 → -$30 = 50
$r = new QuoteRequest(base: $base, vouchers: [pct(20, 'A'), fixed(30, 'C')]);
ok('percent then fixed', total($r) === 50.00, 'got ' . total($r));

// 3. Clamp at zero: 100 → -$80 = 20 → -$50 = 0 (not -30)
$r = new QuoteRequest(base: $base, vouchers: [fixed(80, 'C'), fixed(50, 'D')]);
ok('clamp at zero', total($r) === 0.00, 'got ' . total($r));

// 4. A fixed voucher larger than the whole order → total 0, never negative.
$r = new QuoteRequest(base: $base, vouchers: [fixed(999, 'BIG')]);
ok('single oversize fixed clamps to 0', total($r) === 0.00, 'got ' . total($r));

// 5. Duplicate code applies only once: [A20, A20] == [A20] → 80
$r = new QuoteRequest(base: $base, vouchers: [pct(20, 'A'), pct(20, 'A')]);
ok('duplicate code once', total($r) === 80.00, 'got ' . total($r));

// 6. One per stack_group: two vouchers same group → only the first applies. 100 → -20% = 80
$r = new QuoteRequest(base: $base, vouchers: [pct(20, 'A', ['group' => 'promo']), pct(50, 'B', ['group' => 'promo'])]);
ok('one per group', total($r) === 80.00, 'got ' . total($r));

// 7. Standalone excludes all others: [STANDALONE(-10%), pct(50)] → only standalone → 90
$r = new QuoteRequest(base: $base, vouchers: [pct(10, 'S', ['standalone' => true]), pct(50, 'B')]);
ok('standalone excludes others', total($r) === 90.00, 'got ' . total($r));

// 8. Percent voucher maxDiscount cap: 50% of 100 = 50, capped at $15 → total 85
$r = new QuoteRequest(base: $base, vouchers: [pct(50, 'CAP', ['max' => Money::ofMajor(15, $SGD)])]);
ok('percent maxDiscount cap', total($r) === 85.00, 'got ' . total($r));

// 9. Loyalty (business rule) unchanged + vouchers stack AFTER it on the remainder.
//    base 100, headline -0, loyalty sibling -3 (capped fine), then voucher -10% of 97 = 9.70 → 87.30
$loyalty = [ new DiscountLine('sibling', 'Sibling discount', 3, Money::ofMajor(3, $SGD)) ];
$r = new QuoteRequest(base: $base, loyalty: $loyalty, capPct: 25, vouchers: [pct(10, 'A')]);
ok('loyalty then voucher on remainder', total($r) === 87.30, 'got ' . total($r));

// 10. Priority ordering: lower priority applies first. fixed $50 (prio 0) then 50% (prio 1):
//     100 -50 = 50 -50% = 25 . Reverse priority → 100 -50% = 50 -50 = 0.
$r1 = new QuoteRequest(base: $base, vouchers: [fixed(50, 'F', ['priority' => 0]), pct(50, 'P', ['priority' => 1])]);
$r2 = new QuoteRequest(base: $base, vouchers: [fixed(50, 'F', ['priority' => 1]), pct(50, 'P', ['priority' => 0])]);
ok('priority order matters', total($r1) === 25.00 && total($r2) === 0.00, 'got ' . total($r1) . ' / ' . total($r2));

// 11. GST inclusive still extracted on the final subtotal (9%). 100 → -20% = 80 incl GST.
$r = new QuoteRequest(base: $base, gstPct: 9, gstInclusive: true, vouchers: [pct(20, 'A')]);
$q = $eng->quote($r);
ok('gst inclusive on stacked total', round($q->total->toMajor(), 2) === 80.00 && $q->gst->toMajor() > 0, 'total ' . round($q->total->toMajor(),2));

// 12. Applied lines carry each voucher separately (for per-code UI + audit).
$r = new QuoteRequest(base: $base, vouchers: [pct(20, 'A'), fixed(10, 'C')]);
$q = $eng->quote($r);
$keys = array_map(fn ($l) => $l->key, $q->applied);
ok('per-voucher applied lines', in_array('voucher:A', $keys, true) && in_array('voucher:C', $keys, true), implode(',', $keys));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
