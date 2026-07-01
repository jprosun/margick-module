<?php
/**
 * CoreSchema — the generic, industry-BLIND commerce core tables (T2).
 * ===================================================================
 * Owns ONLY tables every industry shares (SCHEMA-AND-MIGRATIONS.md §0):
 *
 *   {prefix}mgk_core_orders        — one row per order (the money envelope)
 *   {prefix}mgk_core_order_items   — line items; the polymorphic SELL seam
 *
 * Discipline encoded here:
 *   - LAW 1: the seam (item_type, item_ref_id) is polymorphic + NO hard FK; the
 *            core never knows an industry table. order_id is an indexed logical
 *            ref toward orders (app-enforced, like edu's payments.booking_id —
 *            WP custom tables don't use DB FK constraints).
 *   - LAW 2: order_items carries a SELF-SUFFICIENT snapshot (name/sku/unit_price/
 *            qty/options/line_*), so an order renders + refunds + reports even
 *            after the source row (course/variant) is gone or repriced.
 *   - §2:    money = DECIMAL(15,2) + currency (never float); status = VARCHAR
 *            (not ENUM, so new states need no ALTER); qty = DECIMAL(12,3) +
 *            sell_unit so kg/litre/metre sell with ZERO schema change.
 *   - LAW 4: options_json / metadata_json are OPEN bags only — never the home of
 *            a key used in queries/logic (those earn a real column, additive).
 *
 * Schema version is its OWN axis (SCHEMA-AND-MIGRATIONS.md §3.2), tracked per
 * capability in the option below — NOT the package/composer version.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class CoreSchema
{
    /** Capability schema version (additive bumps only). Independent of package VERSION. */
    public const SCHEMA_VERSION = '1.2.0'; // 1.1.0: + refunds, credit_notes, sequences; 1.2.0: + invoices

    /** Option that records the applied schema version on THIS site (the mini-ledger). */
    public const VERSION_OPTION = 'mgk_core_schema_version';

    /**
     * Fully-qualified table name for a logical key. Single place that owns the
     * real names — Repositories and callers MUST resolve names through here.
     *
     * @param 'orders'|'order_items'|'refunds'|'credit_notes'|'sequences'|'invoices' $key
     */
    public static function table(string $key): string
    {
        global $wpdb;
        $map = [
            'orders'       => $wpdb->prefix . 'mgk_core_orders',
            'order_items'  => $wpdb->prefix . 'mgk_core_order_items',
            'refunds'      => $wpdb->prefix . 'mgk_core_refunds',
            'credit_notes' => $wpdb->prefix . 'mgk_core_credit_notes',
            'sequences'    => $wpdb->prefix . 'mgk_core_sequences',
            'invoices'     => $wpdb->prefix . 'mgk_core_invoices',
        ];
        return $map[$key] ?? '';
    }

    /**
     * Create/upgrade the core tables via dbDelta. Idempotent — safe to call
     * repeatedly. Uses the DB's own charset/collate to match the existing
     * install (utf8mb3 on edu). ADDITIVE only — never drop/rename here.
     */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $orders = self::table('orders');
        $items  = self::table('order_items');

        // ── mgk_core_orders — the money envelope (industry-blind) ──────────
        $sql_orders = "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_code VARCHAR(64) NOT NULL,
            customer_user_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            subtotal_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            metadata_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_code (order_code),
            KEY status (status),
            KEY customer (customer_user_id)
        ) {$charset_collate};";

        // ── mgk_core_order_items — polymorphic SELL seam + LAW-2 snapshot ──
        $sql_items = "CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            item_type VARCHAR(40) NOT NULL,
            item_ref_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            sku VARCHAR(190) NULL,
            sell_unit VARCHAR(16) NOT NULL DEFAULT 'piece',
            unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            options_json LONGTEXT NULL,
            line_discount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            line_tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY item_ref (item_type, item_ref_id)
        ) {$charset_collate};";

        // ── mgk_core_refunds — money RETURNED (append-only ledger of refunds) ──
        // order_id is a logical ref (LAW 1, no hard FK). idempotency_key makes a
        // retried refund of the same amount a no-op at record time.
        $refunds = self::table('refunds');
        $sql_refunds = "CREATE TABLE {$refunds} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
            provider_refund_id VARCHAR(190) NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            is_full TINYINT(1) NOT NULL DEFAULT 0,
            mode VARCHAR(10) NOT NULL DEFAULT 'live',
            reason VARCHAR(190) NULL,
            credit_note_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(190) NULL,
            metadata_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY order_id (order_id)
        ) {$charset_collate};";

        // ── mgk_core_credit_notes — negative invoice w/ GAPLESS number (LR-033) ──
        $credit = self::table('credit_notes');
        $sql_credit = "CREATE TABLE {$credit} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            credit_note_no VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            refund_id BIGINT UNSIGNED NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            reason VARCHAR(190) NULL,
            metadata_json LONGTEXT NULL,
            issued_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY credit_note_no (credit_note_no),
            KEY order_id (order_id)
        ) {$charset_collate};";

        // ── mgk_core_invoices — the tax invoice w/ GAPLESS number (LR-033 / GST) ──
        // One invoice per order (UNIQUE order_id), issued once on PAID. Carries a
        // LAW-2 self-sufficient snapshot of the seller legal entity + buyer + tax
        // breakdown, so the document renders/exports unchanged even after the site's
        // company details, GST rate or the source order later change. amount_* mirror
        // the order money envelope at issue time; invoice_no is gapless per-year.
        $invoices = self::table('invoices');
        $sql_invoices = "CREATE TABLE {$invoices} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_no VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_code VARCHAR(64) NULL,
            customer_user_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ISSUED',
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            subtotal_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
            tax_inclusive TINYINT(1) NOT NULL DEFAULT 1,
            seller_name VARCHAR(190) NULL,
            seller_uen VARCHAR(64) NULL,
            seller_gst_no VARCHAR(64) NULL,
            buyer_name VARCHAR(190) NULL,
            buyer_email VARCHAR(190) NULL,
            snapshot_json LONGTEXT NULL,
            metadata_json LONGTEXT NULL,
            issued_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_no (invoice_no),
            UNIQUE KEY order_id (order_id),
            KEY customer (customer_user_id)
        ) {$charset_collate};";

        // ── mgk_core_sequences — gapless atomic counters (invoice/credit-note no) ──
        // PK is the seq_key (no AUTO_INCREMENT); allocation uses the LAST_INSERT_ID
        // idiom so each connection reads its OWN value with no gap, no SELECT race.
        $sequences = self::table('sequences');
        // last_no (NOT 'last_value' — LAST_VALUE is a reserved window-function word in MySQL 8).
        $sql_sequences = "CREATE TABLE {$sequences} (
            seq_key VARCHAR(64) NOT NULL,
            last_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (seq_key)
        ) {$charset_collate};";

        dbDelta($sql_orders);
        dbDelta($sql_items);
        dbDelta($sql_refunds);
        dbDelta($sql_credit);
        dbDelta($sql_invoices);
        dbDelta($sql_sequences);
    }
}
