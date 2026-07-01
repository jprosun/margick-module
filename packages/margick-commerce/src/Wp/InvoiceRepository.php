<?php
/**
 * InvoiceRepository — the sanctioned door to the core invoices table.
 * ===================================================================
 * Issues ONE gapless-numbered tax invoice per order (INV-<YYYY>-<NNNNNN>) when
 * the order is paid, and reads it back. PURE PERSISTENCE — the caller owns the
 * money/tax figures + the seller (legal entity) snapshot; this only stores the
 * outcome and allocates the number atomically, exactly like RefundRepository is
 * the only door to refunds/credit_notes (LAW 3).
 *
 * Idempotency + gaplessness under concurrency:
 *   An invoice is issued at most ONCE per order (UNIQUE order_id). Two racing
 *   callers both try to claim the order_id slot; exactly one wins the UNIQUE
 *   insert and only THEN allocates a sequence number (so a lost race never burns
 *   a number → the sequence stays gapless). The loser re-reads and returns the
 *   winner's invoice. A retried issue after success is a plain no-op read.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

use Margick\Commerce\Domain\Invoice\Invoice;
use Margick\Commerce\Domain\Money;

final class InvoiceRepository
{
    /**
     * Issue (or fetch the existing) invoice for a paid order. Returns the Invoice,
     * or null on hard failure. Idempotent per order.
     *
     * @param array<string,mixed> $opts subtotal, discount, tax, total (Money|float),
     *   currency, tax_rate (float %), tax_inclusive (bool), order_code, customer_user_id,
     *   seller[name,uen,gst_no], buyer[name,email], prefix, year, metadata, snapshot
     */
    public static function issueForOrder(int $orderId, array $opts = []): ?Invoice
    {
        global $wpdb;
        if ($orderId <= 0) {
            return null;
        }
        $table = CoreSchema::table('invoices');

        // Fast path: already issued → return it (idempotent no-op).
        $existing = self::findByOrder($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $currency      = \strtoupper((string) ($opts['currency'] ?? 'SGD'));
        $subtotal      = self::money($opts['subtotal'] ?? 0, $currency);
        $discount      = self::money($opts['discount'] ?? 0, $currency);
        $tax           = self::money($opts['tax'] ?? 0, $currency);
        $total         = self::money($opts['total'] ?? 0, $currency);
        $taxRate       = \round((float) ($opts['tax_rate'] ?? 0), 3);
        $taxInclusive  = ! empty($opts['tax_inclusive']);
        $seller        = (array) ($opts['seller'] ?? []);
        $buyer         = (array) ($opts['buyer'] ?? []);
        $orderCode     = isset($opts['order_code']) ? (string) $opts['order_code'] : null;
        $customerId    = isset($opts['customer_user_id']) ? (int) $opts['customer_user_id'] : null;
        $now           = \gmdate('Y-m-d H:i:s');

        // Step 1 — claim the order_id slot with a UNIQUE-guarded insert BEFORE
        // allocating a number, so a lost race never consumes a sequence value.
        // invoice_no is a unique per-row placeholder ('' would collide on the 2nd
        // row) until the winner fills the real gapless number in step 2.
        // A lost race is EXPECTED control flow (the loser hits UNIQUE(order_id) /
        // the placeholder), so we silence $wpdb's error output for just this insert
        // — a duplicate here is a signal, not a fault, and must not spam the log.
        $placeholder = 'PENDING:' . $orderId;
        $prevSuppress = $wpdb->suppress_errors(true);
        $prevShow     = $wpdb->hide_errors();
        $claimed = $wpdb->insert($table, [
            'invoice_no'       => $placeholder,
            'order_id'         => $orderId,
            'order_code'       => $orderCode,
            'customer_user_id' => $customerId,
            'status'           => Invoice::STATUS_ISSUED,
            'currency'         => $currency,
            'subtotal_amount'  => \round($subtotal->toMajor(), 2),
            'discount_amount'  => \round($discount->toMajor(), 2),
            'tax_amount'       => \round($tax->toMajor(), 2),
            'total_amount'     => \round($total->toMajor(), 2),
            'tax_rate'         => $taxRate,
            'tax_inclusive'    => $taxInclusive ? 1 : 0,
            'seller_name'      => self::s($seller['name'] ?? null),
            'seller_uen'       => self::s($seller['uen'] ?? null),
            'seller_gst_no'    => self::s($seller['gst_no'] ?? null),
            'buyer_name'       => self::s($buyer['name'] ?? null),
            'buyer_email'      => self::s($buyer['email'] ?? null),
            'snapshot_json'    => isset($opts['snapshot']) ? (string) \wp_json_encode($opts['snapshot']) : null,
            'metadata_json'    => isset($opts['metadata']) ? (string) \wp_json_encode($opts['metadata']) : null,
            'issued_at_utc'    => $now,
        ]);
        $wpdb->suppress_errors($prevSuppress);
        if ($prevShow) {
            $wpdb->show_errors();
        }

        if ($claimed === false) {
            // Lost the UNIQUE(order_id) race (or a transient error) — return the
            // winner's invoice if one now exists.
            return self::findByOrder($orderId);
        }
        $invoiceId = (int) $wpdb->insert_id;

        // Step 2 — winner allocates the gapless number and stamps it onto the row.
        $year   = isset($opts['year']) ? (string) $opts['year'] : \gmdate('Y');
        $prefix = (isset($opts['prefix']) ? (string) $opts['prefix'] : 'INV') . '-' . $year;
        $number = SequenceNumberAllocator::nextFormatted('invoice:' . $year, $prefix, 6);
        if ($number === '') {
            // Allocator failed — roll back the claim so the order can retry later
            // rather than being stuck with an un-numbered invoice.
            $wpdb->delete($table, ['id' => $invoiceId]);
            return null;
        }
        $wpdb->update($table, ['invoice_no' => $number], ['id' => $invoiceId]);

        return self::findById($invoiceId);
    }

    public static function findByOrder(int $orderId): ?Invoice
    {
        global $wpdb;
        if ($orderId <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . CoreSchema::table('invoices') . ' WHERE order_id = %d',
            $orderId
        ), ARRAY_A);
        return \is_array($row) ? self::hydrate($row) : null;
    }

    public static function findByNumber(string $number): ?Invoice
    {
        global $wpdb;
        if ($number === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . CoreSchema::table('invoices') . ' WHERE invoice_no = %s',
            $number
        ), ARRAY_A);
        return \is_array($row) ? self::hydrate($row) : null;
    }

    public static function findById(int $id): ?Invoice
    {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . CoreSchema::table('invoices') . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return \is_array($row) ? self::hydrate($row) : null;
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): Invoice
    {
        $currency = \strtoupper((string) ($row['currency'] ?? 'SGD'));
        return new Invoice(
            id: (int) $row['id'],
            number: (string) $row['invoice_no'],
            orderId: (int) $row['order_id'],
            orderCode: isset($row['order_code']) ? (string) $row['order_code'] : null,
            subtotal: Money::ofMajor((float) $row['subtotal_amount'], $currency),
            discount: Money::ofMajor((float) $row['discount_amount'], $currency),
            tax: Money::ofMajor((float) $row['tax_amount'], $currency),
            total: Money::ofMajor((float) $row['total_amount'], $currency),
            taxRate: (float) $row['tax_rate'],
            taxInclusive: (int) $row['tax_inclusive'] === 1,
            seller: [
                'name'   => (string) ($row['seller_name'] ?? ''),
                'uen'    => (string) ($row['seller_uen'] ?? ''),
                'gst_no' => (string) ($row['seller_gst_no'] ?? ''),
            ],
            buyer: [
                'name'  => (string) ($row['buyer_name'] ?? ''),
                'email' => (string) ($row['buyer_email'] ?? ''),
            ],
            issuedAtUtc: (string) $row['issued_at_utc'],
            status: (string) ($row['status'] ?? Invoice::STATUS_ISSUED)
        );
    }

    /** Accept a Money VO or a major-unit float. */
    private static function money(mixed $v, string $currency): Money
    {
        return $v instanceof Money ? $v : Money::ofMajor((float) $v, $currency);
    }

    private static function s(mixed $v): ?string
    {
        $v = $v === null ? '' : (string) $v;
        return $v === '' ? null : $v;
    }
}
