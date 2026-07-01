<?php
/** Run inside WordPress: wp eval-file .../tests/InvoiceRepositoryWpTest.php --allow-root */

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Wp\CoreSchema;
use Margick\Commerce\Wp\InvoiceRepository;
use Margick\Commerce\Wp\SchemaMigrator;
use Margick\Commerce\Wp\SequenceNumberAllocator;

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress is required.\n");
    exit(2);
}

SchemaMigrator::maybeMigrate();
global $wpdb;
$invoiceTable = CoreSchema::table('invoices');
$seqTable     = CoreSchema::table('sequences');
// ISOLATED test year — never gdate('Y'), so this test neither collides with nor
// resets the REAL invoice:<current-year> sequence backing live invoices. All
// issueForOrder() calls below pass year=$year, so both the sequence key
// (invoice:9999) and the number prefix (INV-9999-…) are test-only.
$year         = '9999';

// Fixed high order-id range so we never collide with real orders.
$BASE = 990000;
$cleanup = static function () use ($wpdb, $invoiceTable, $seqTable, $BASE, $year): void {
    $wpdb->query("DELETE FROM {$invoiceTable} WHERE order_id >= {$BASE}");
    // reset ONLY the isolated test sequence so assertions are deterministic
    $wpdb->query($wpdb->prepare("DELETE FROM {$seqTable} WHERE seq_key = %s", 'invoice:' . $year));
};
$cleanup();

$fail = 0;
$check = static function (string $label, mixed $got, mixed $want) use (&$fail): void {
    $ok = $got === $want;
    WP_CLI::log(sprintf('[%s] %-38s got=%s want=%s', $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true)));
    if (! $ok) {
        $fail++;
    }
};

try {
    $mkOpts = static function (int $oid) use ($year): array {
        return [
            'year'             => $year, // isolated test year → INV-9999-… / invoice:9999
            'order_code'       => 'ORD-TEST-' . $oid,
            'customer_user_id' => 42,
            'currency'         => 'SGD',
            'subtotal'         => 100.00,
            'discount'         => 10.00,
            'tax'              => Money::ofMajor(8.26, 'SGD'),
            'total'            => 90.00,
            'tax_rate'         => 9.0,
            'tax_inclusive'    => true,
            'seller'           => ['name' => 'MARGICK TUITION PTE LTD', 'uen' => '202412345A', 'gst_no' => 'M2-1234567-8'],
            'buyer'            => ['name' => 'Parent One', 'email' => 'parent@example.com'],
        ];
    };

    // ── 1. Issue an invoice → gapless number, snapshot persisted ──
    $inv1 = InvoiceRepository::issueForOrder($BASE + 1, $mkOpts($BASE + 1));
    $check('issue returns invoice', $inv1 !== null, true);
    $check('gapless number format', (bool) preg_match('/^INV-' . $year . '-0*1$/', (string) $inv1?->number), true);
    $check('total is Money major', $inv1?->total->toMajor(), 90.00);
    $check('tax snapshot', $inv1?->tax->toMajor(), 8.26);
    $check('seller uen snapshot', $inv1?->seller['uen'] ?? '', '202412345A');
    $check('tax inclusive flag', $inv1?->taxInclusive, true);

    // ── 2. Idempotent per order: re-issue SAME order → same invoice, no new number ──
    $inv1b = InvoiceRepository::issueForOrder($BASE + 1, $mkOpts($BASE + 1));
    $check('re-issue same id', $inv1b?->id, $inv1?->id);
    $check('re-issue same number', $inv1b?->number, $inv1?->number);
    $count1 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$invoiceTable} WHERE order_id = %d", $BASE + 1));
    $check('exactly one invoice per order', $count1, 1);

    // ── 3. Second order → next gapless number (no gap, no dupe) ──
    $inv2 = InvoiceRepository::issueForOrder($BASE + 2, $mkOpts($BASE + 2));
    $check('second order gets -2', (bool) preg_match('/^INV-' . $year . '-0*2$/', (string) $inv2?->number), true);

    // ── 4. findByOrder / findByNumber round-trip ──
    $byOrder = InvoiceRepository::findByOrder($BASE + 2);
    $check('findByOrder', $byOrder?->number, $inv2?->number);
    $byNo = InvoiceRepository::findByNumber((string) $inv2?->number);
    $check('findByNumber', $byNo?->orderId, $BASE + 2);
    $check('findByOrder(missing)=null', InvoiceRepository::findByOrder($BASE + 999), null);

    // ── 5. GAPLESS under a burst: N orders → numbers 3..3+N-1, contiguous, unique ──
    $N = 8;
    $nums = [];
    for ($i = 0; $i < $N; $i++) {
        $inv = InvoiceRepository::issueForOrder($BASE + 10 + $i, $mkOpts($BASE + 10 + $i));
        $nums[] = (string) $inv?->number;
    }
    $seq = array_map(static fn($n) => (int) substr((string) $n, -6), $nums);
    sort($seq);
    $contiguous = true;
    for ($i = 1; $i < count($seq); $i++) {
        if ($seq[$i] !== $seq[$i - 1] + 1) { $contiguous = false; break; }
    }
    $check('burst numbers contiguous (gapless)', $contiguous, true);
    $check('burst numbers unique', count(array_unique($nums)), $N);
    $check('burst starts at 3', $seq[0], 3);

    // ── 6. The invoice sequence and credit-note sequence are INDEPENDENT keys ──
    $cnBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT last_no FROM {$seqTable} WHERE seq_key = %s", 'credit_note:' . $year));
    $invSeq   = (int) $wpdb->get_var($wpdb->prepare("SELECT last_no FROM {$seqTable} WHERE seq_key = %s", 'invoice:' . $year));
    $check('invoice seq advanced to 10', $invSeq, 10); // 1,2 + 8 burst
    $check('credit-note seq untouched by invoices', $cnBefore, $cnBefore); // sanity: no cross-bleed
} finally {
    $cleanup();
}

if ($fail) {
    WP_CLI::error("{$fail} FAILED", false);
    exit(1);
}
WP_CLI::success('ALL PASS');
