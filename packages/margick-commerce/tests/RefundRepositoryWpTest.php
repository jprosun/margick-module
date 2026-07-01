<?php
/** Run inside WordPress: wp eval-file .../tests/RefundRepositoryWpTest.php
 * Exercises the v0.6.0 refund mechanics against a real wpdb: schema install,
 * GAPLESS credit-note numbering, idempotent refund recording. (The raw gapless
 * idiom is also proven standalone on MySQL 8; this confirms the wpdb wrapper.)
 */

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Wp\CoreSchema;
use Margick\Commerce\Wp\RefundRepository;
use Margick\Commerce\Wp\SchemaMigrator;
use Margick\Commerce\Wp\SequenceNumberAllocator;

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress is required.\n");
    exit(2);
}

SchemaMigrator::maybeMigrate(); // installs orders/order_items/refunds/credit_notes/sequences

global $wpdb;
$seqT = CoreSchema::table('sequences');
$rfT  = CoreSchema::table('refunds');
$cnT  = CoreSchema::table('credit_notes');

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    WP_CLI::log(sprintf('[%s] %-28s got=%s want=%s', $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true)));
    if (! $ok) { $fail++; }
};

// ── clean test rows ──
$wpdb->query("DELETE FROM {$seqT} WHERE seq_key = 'wptest:seq'");
$wpdb->query("DELETE FROM {$rfT}  WHERE order_id = 999999");
$wpdb->query("DELETE FROM {$cnT}  WHERE order_id = 999999");

// ── gapless allocator: 5 sequential calls → 1..5, no gap ──
$nums = [];
for ($i = 0; $i < 5; $i++) { $nums[] = SequenceNumberAllocator::next('wptest:seq'); }
$check('alloc.sequence', $nums, [1, 2, 3, 4, 5]);
$check('alloc.format',   SequenceNumberAllocator::format('CN-2026', 7), 'CN-2026-000007');

// ── idempotent refund recording: same key twice → ONE row ──
$id1 = RefundRepository::recordRefund(['order_id' => 999999, 'amount' => 40.00, 'currency' => 'SGD', 'is_full' => true, 'mode' => 'mock', 'idempotency_key' => 'mgk_refund_999999_4000']);
$id2 = RefundRepository::recordRefund(['order_id' => 999999, 'amount' => 40.00, 'currency' => 'SGD', 'is_full' => true, 'mode' => 'mock', 'idempotency_key' => 'mgk_refund_999999_4000']);
$check('refund.idempotent', $id1, $id2);
$rowCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rfT} WHERE order_id = %d", 999999));
$check('refund.one_row', $rowCount, 1);

// ── credit note: gapless number + persisted ──
$cn = RefundRepository::issueCreditNote(999999, Money::ofMajor(40, 'SGD'), ['reason' => 'Trial cancelled', 'refund_id' => $id1, 'year' => '2026']);
$check('creditnote.issued', $cn !== null, true);
if ($cn) {
    $check('creditnote.number_fmt', (bool) preg_match('/^CN-2026-\d{6}$/', $cn->number), true);
    $check('creditnote.amount', $cn->amount->toMajor(), 40.0);
}

// cleanup
$wpdb->query("DELETE FROM {$seqT} WHERE seq_key IN ('wptest:seq','credit_note:2026')");
$wpdb->query("DELETE FROM {$rfT}  WHERE order_id = 999999");
$wpdb->query("DELETE FROM {$cnT}  WHERE order_id = 999999");

WP_CLI::log($fail === 0 ? 'ALL PASS' : "{$fail} FAILED");
exit($fail === 0 ? 0 : 1);
