<?php
/**
 * SlotMathTest — PURE PHP. Run: php tests/SlotMathTest.php
 * Locks the block-expansion + slot-key math the overbooking guard depends on.
 */

declare(strict_types=1);

namespace Margick\Booking\Tests;

require __DIR__ . '/../src/Domain/SlotMath.php';

use Margick\Booking\Domain\SlotMath;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-22s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) { $fail++; }
};

// slot key (matches edu mgk_slot_key format exactly)
$check('slotKey', SlotMath::slotKey(305, '2027-09-09 10:00:00', '2027-09-09 11:00:00'),
    '305:2027-09-09 10:00:00:2027-09-09 11:00:00');

// 60-min @ 15 → 4 blocks
$b60 = SlotMath::expandToBlocks('2027-09-09 10:00:00', '2027-09-09 11:00:00');
$check('60min.count', count($b60), 4);
$check('60min.first', $b60[0], '2027-09-09 10:00:00');
$check('60min.last',  $b60[3], '2027-09-09 10:45:00');

// 90-min @ 15 → 6 blocks
$check('90min.count', count(SlotMath::expandToBlocks('2027-09-09 10:00:00', '2027-09-09 11:30:00')), 6);

// 60-min @ 30 → 2 blocks (block size configurable, no longer a constant)
$check('30block.count', count(SlotMath::expandToBlocks('2027-09-09 10:00:00', '2027-09-09 11:00:00', 30)), 2);

// half-open: end == start → 0 blocks
$check('empty.count', count(SlotMath::expandToBlocks('2027-09-09 10:00:00', '2027-09-09 10:00:00')), 0);

// invalid datetime → [] (no throw)
$check('invalid.count', count(SlotMath::expandToBlocks('not-a-date', 'nope')), 0);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
