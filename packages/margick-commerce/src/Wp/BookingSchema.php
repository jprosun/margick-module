<?php
/**
 * BookingSchema — the BOOKING crosscut capability tables (T2 · crosscut module).
 * =============================================================================
 * SCHEMA-AND-MIGRATIONS.md §0/§4-Phase0: the edu transaction engine was authored
 * in the theme (inc/booking/booking-schema.php). Phase 0 lifts that schema OUT of
 * the template and into this module so EVERY scheduling-shaped industry (tutoring,
 * spa, clinic, restaurant tables…) reuses ONE definition. The template owns 0
 * tables; it only configures which capability it switches on.
 *
 * Discipline encoded here (matches the doc):
 *   - §0 / LUẬT-no-rename: the tables KEEP their shipped names (mgk_bookings,
 *     mgk_slot_block_locks, mgk_payments, mgk_booking_events). Rename = a risky,
 *     pointless migration on already-shipped standalone sites. We change the OWNER
 *     (this module), not the names. DDL is byte-for-byte what the theme shipped, so
 *     dbDelta is a pure no-op on existing installs — zero data movement.
 *   - §3.2: schema version is its OWN axis, tracked in the SAME option the edu
 *     engine already uses ('mgk_booking_schema_version') for seamless continuity.
 *   - LUẬT 1: mgk_slot_block_locks UNIQUE is (tutor_post_id, block_start_at_utc)
 *     ONLY; a lock row exists only while it blocks — release = DELETE the row.
 *   - Painpoint D: mgk_bookings is the single source of truth; mg_booking CPT is a
 *     one-way read-only mirror (theme's booking-mirror.php).
 *
 * NOTE on generalization (§5): these tables still couple to a WP post
 * (tutor_post_id). The resource_type/resource_id expand/contract to a non-WP-post
 * resource is DELIBERATELY deferred until industry #2 forces the shape — building
 * it now would be guessing. This class is the home where that expand will land.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class BookingSchema
{
    /** Capability schema version (additive bumps only). Independent of package VERSION. */
    public const SCHEMA_VERSION = '0.7.0';

    /**
     * Applied-version ledger option. Reuses the edu engine's existing key so a site
     * already at 0.7.0 sees no mismatch and runs no migration on first boot after
     * ownership moves here.
     */
    public const VERSION_OPTION = 'mgk_booking_schema_version';

    /**
     * Fully-qualified table name for a logical key. THE single owner of the real
     * names — Repositories and callers MUST resolve names through here (LUẬT 3).
     *
     * @param 'bookings'|'locks'|'payments'|'events' $key
     */
    public static function table(string $key): string
    {
        global $wpdb;
        $map = [
            'bookings' => $wpdb->prefix . 'mgk_bookings',
            'locks'    => $wpdb->prefix . 'mgk_slot_block_locks',
            'payments' => $wpdb->prefix . 'mgk_payments',
            'events'   => $wpdb->prefix . 'mgk_booking_events',
        ];
        return $map[$key] ?? '';
    }

    /**
     * Create/upgrade the four booking tables via dbDelta. Idempotent — safe to call
     * repeatedly. Uses the DB's own charset/collate to match the existing install
     * (utf8mb3 on edu). ADDITIVE only — never drop/rename here.
     */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $bookings = self::table('bookings');
        $locks    = self::table('locks');
        $payments = self::table('payments');
        $events   = self::table('events');

        // ── mgk_bookings — source of truth ─────────────────────────────────
        $sql_bookings = "CREATE TABLE {$bookings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_code VARCHAR(64) NOT NULL,
            tutor_post_id BIGINT UNSIGNED NOT NULL,
            lead_id BIGINT UNSIGNED NULL,
            parent_user_id BIGINT UNSIGNED NULL,
            child_id BIGINT UNSIGNED NULL,
            student_name VARCHAR(190) NULL,
            subject VARCHAR(190) NULL,
            lesson_type VARCHAR(40) NOT NULL DEFAULT 'TRIAL',
            slot_key VARCHAR(190) NULL,
            start_at_utc DATETIME NOT NULL,
            end_at_utc DATETIME NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Singapore',
            status VARCHAR(40) NOT NULL DEFAULT 'HELD',
            payment_status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
            price_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_applied LONGTEXT NULL,
            voucher_code VARCHAR(64) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            idempotency_key VARCHAR(190) NULL,
            hold_expires_at_utc DATETIME NULL,
            confirmed_at_utc DATETIME NULL,
            cancelled_at_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_booking_code (booking_code),
            UNIQUE KEY uniq_idempotency (idempotency_key),
            KEY idx_tutor_time (tutor_post_id, start_at_utc, end_at_utc),
            KEY idx_status (status),
            KEY idx_payment_status (payment_status),
            KEY idx_hold_expires (status, hold_expires_at_utc)
        ) {$charset_collate};";

        // ── mgk_slot_block_locks — double-book prevention (LUẬT 1) ─────────
        $sql_locks = "CREATE TABLE {$locks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tutor_post_id BIGINT UNSIGNED NOT NULL,
            booking_id BIGINT UNSIGNED NOT NULL,
            block_start_at_utc DATETIME NOT NULL,
            lock_type VARCHAR(20) NOT NULL DEFAULT 'HOLD',
            expires_at_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_active_block (tutor_post_id, block_start_at_utc),
            KEY idx_booking (booking_id),
            KEY idx_expires (lock_type, expires_at_utc)
        ) {$charset_collate};";

        // ── mgk_payments — Stripe linkage + idempotency ────────────────────
        $sql_payments = "CREATE TABLE {$payments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL DEFAULT 'STRIPE',
            provider_checkout_session_id VARCHAR(190) NULL,
            provider_payment_intent_id VARCHAR(190) NULL,
            latest_webhook_event_id VARCHAR(190) NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'SGD',
            status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
            paid_at_utc DATETIME NULL,
            failed_at_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            UNIQUE KEY uniq_checkout_session (provider_checkout_session_id),
            UNIQUE KEY uniq_payment_intent (provider_payment_intent_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        // ── mgk_booking_events — audit log + webhook idempotency ───────────
        $sql_events = "CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(40) NOT NULL DEFAULT 'SYSTEM',
            actor_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            old_status VARCHAR(40) NULL,
            new_status VARCHAR(40) NULL,
            provider VARCHAR(40) NULL,
            provider_event_id VARCHAR(190) NULL,
            metadata_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_event_type (event_type),
            UNIQUE KEY uniq_provider_event (provider, provider_event_id)
        ) {$charset_collate};";

        dbDelta($sql_bookings);
        dbDelta($sql_locks);
        dbDelta($sql_payments);
        dbDelta($sql_events);
    }
}
