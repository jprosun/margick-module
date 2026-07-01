<?php
/** Run inside WordPress: wp eval-file .../tests/VoucherRepositoryWpTest.php */

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Voucher\Domain\VoucherContext;
use Margick\Commerce\Wp\SchemaMigrator;
use Margick\Commerce\Wp\VoucherRepository;
use Margick\Commerce\Wp\VoucherSchema;

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress is required.\n");
    exit(2);
}

SchemaMigrator::maybeMigrate();
global $wpdb;
$voucherTable = VoucherSchema::table('vouchers');
$redemptionTable = VoucherSchema::table('redemptions');
$codes = ['MGKTEST10', 'MGKTEST5'];

$cleanup = static function () use ($wpdb, $voucherTable, $redemptionTable, $codes): void {
    $ids = $wpdb->get_col("SELECT id FROM {$voucherTable} WHERE code IN ('" . implode("','", $codes) . "')");
    if ($ids) {
        $wpdb->query("DELETE FROM {$redemptionTable} WHERE voucher_id IN (" . implode(',', array_map('intval', $ids)) . ')');
    }
    $wpdb->query("DELETE FROM {$voucherTable} WHERE code IN ('" . implode("','", $codes) . "')");
};
$cleanup();

$fail = 0;
$check = static function (string $label, mixed $got, mixed $want) use (&$fail): void {
    $ok = $got === $want;
    WP_CLI::log(sprintf('[%s] %-25s got=%s want=%s', $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true)));
    if (! $ok) {
        $fail++;
    }
};

try {
    $firstId = VoucherRepository::upsert([
        'code' => ' mgk test 10 ',
        'name' => 'Integration 10%',
        'discount_type' => 'percent',
        'percentage_bps' => 1000,
        'currency' => 'SGD',
        'usage_limit' => 1,
        'usage_limit_per_customer' => 1,
        'applies_to' => ['edu_trial'],
        'respect_global_cap' => true,
    ]);
    $secondId = VoucherRepository::upsert([
        'code' => 'MGKTEST5',
        'name' => 'Integration fixed',
        'discount_type' => 'fixed',
        'fixed_amount_minor' => 500,
        'currency' => 'SGD',
        'applies_to' => ['edu_trial'],
    ]);
    $check('upsert first', $firstId > 0, true);
    $check('upsert second', $secondId > 0, true);
    $check('cap policy persisted', VoucherRepository::findByCode('MGKTEST10')?->respectGlobalCap, true);

    $now = new DateTimeImmutable('2026-06-22 12:00:00', new DateTimeZone('UTC'));
    $ctx = new VoucherContext(Money::ofMinor(4000, 'SGD'), ['edu_trial'], 'parent@example.com', true, $now);
    $check('preview amount', VoucherRepository::preview('mgktest10', $ctx)->discount?->minor(), 400);

    // ── Default policy: 'consumed' — only PAID redemptions count toward limits.
    //    A RESERVED hold costs nothing, so applying the same code on a second
    //    in-progress order (or re-applying) is allowed; ONLY a CONSUMED order
    //    counts. This is the "a voucher is used only when payment succeeds" rule.
    $check('default policy is consumed', VoucherRepository::usagePolicy(), 'consumed');
    $holdA = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'paid-a', 900, 'paid-a-10');
    $check('consumed-policy reserve A', $holdA['ok'], true);
    $holdB = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'paid-b', 900, 'paid-b-10');
    $check('consumed-policy 2nd HOLD allowed (limit 1)', $holdB['ok'], true); // RESERVED does not block
    $check('consumed-policy consume A', VoucherRepository::consume('booking', 'paid-a', $now), true);
    // A is now PAID → the single global use is spent; a fresh order is blocked.
    $blockedPaid = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'paid-c', 900, 'paid-c-10');
    $check('consumed-policy PAID blocks new order', $blockedPaid['reason'], 'usage_limit');
    // Clean the consumed-policy fixtures before the strict-mode block reuses limits.
    $wpdb->query("DELETE FROM {$redemptionTable} WHERE reference_id IN ('paid-a','paid-b','paid-c')");

    // ── Strict anti-oversell policy: 'active' — RESERVED holds also count. ──
    VoucherRepository::setUsagePolicy('active');
    $one = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'test-a', 900, 'test-a-10');
    $check('reserve first', $one['ok'], true);
    $check('reserved ledger count', VoucherRepository::usageStats('MGKTEST10')['reserved'], 1);
    $check('self preview stays valid', VoucherRepository::preview('MGKTEST10', $ctx, 'booking', 'test-a')->valid, true);
    $blocked = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'test-b', 900, 'test-b-10');
    $check('reserved counts quota', $blocked['reason'], 'usage_limit');
    $check('release first', VoucherRepository::release('booking', 'test-a', $now), true);

    $reapplied = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'test-a', 900, 'test-a-10');
    $check('reapply same idempotency', $reapplied['ok'], true);
    $check('release reapplied', VoucherRepository::release('booking', 'test-a', $now), true);

    $two = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'test-b', 900, 'test-b-10');
    $check('reserve after release', $two['ok'], true);
    $check('consume', VoucherRepository::consume('booking', 'test-b', $now), true);
    $check('consume idempotent', VoucherRepository::consume('booking', 'test-b', $now), true);
    $check('consumed snapshot', VoucherRepository::findActiveByReference('booking', 'test-b')['status'] ?? '', 'CONSUMED');
    $check('consumed ledger count', VoucherRepository::usageStats('MGKTEST10')['consumed'], 1);

    $three = VoucherRepository::reserve('MGKTEST5', $ctx, 'booking', 'test-c', 900, 'test-c-5');
    $check('reserve replacement base', $three['ok'], true);
    $replacement = VoucherRepository::reserve('MGKTEST10', $ctx, 'booking', 'test-c', 900, 'test-c-10');
    $check('consumed quota blocks replacement', $replacement['reason'], 'usage_limit');

    $expiredCtx = new VoucherContext(Money::ofMinor(4000, 'SGD'), ['edu_trial'], 'next@example.com', true, $now->modify('+1 hour'));
    $check('expire cleanup', VoucherRepository::expireReservations($expiredCtx->now) >= 1, true);
    $replacement = VoucherRepository::reserve('MGKTEST5', $expiredCtx, 'booking', 'test-c', 900, 'test-c-5b');
    $check('reserve after expiry', $replacement['ok'], true);
} finally {
    VoucherRepository::setUsagePolicy('consumed'); // restore module default
    $cleanup();
}

if ($fail) {
    WP_CLI::error("{$fail} FAILED", false);
    exit(1);
}
WP_CLI::success('ALL PASS');
