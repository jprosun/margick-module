<?php

declare(strict_types=1);

namespace Margick\Commerce\Wp;

/** Versioned custom tables owned by the reusable voucher capability. */
final class VoucherSchema
{
    public const SCHEMA_VERSION = '1.1.0';
    public const VERSION_OPTION = 'mgk_voucher_schema_version';

    /** @param 'vouchers'|'redemptions' $key */
    public static function table(string $key): string
    {
        global $wpdb;
        $map = [
            'vouchers'    => $wpdb->prefix . 'mgk_core_vouchers',
            'redemptions' => $wpdb->prefix . 'mgk_core_voucher_redemptions',
        ];
        return $map[$key] ?? '';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $vouchers = self::table('vouchers');
        $redemptions = self::table('redemptions');

        $sqlVouchers = "CREATE TABLE {$vouchers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            discount_type VARCHAR(20) NOT NULL,
            percentage_bps INT UNSIGNED NOT NULL DEFAULT 0,
            fixed_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency VARCHAR(10) NULL,
            min_order_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_discount_minor BIGINT UNSIGNED NULL,
            stackable TINYINT(1) NOT NULL DEFAULT 0,
            respect_global_cap TINYINT(1) NOT NULL DEFAULT 0,
            usage_limit INT UNSIGNED NULL,
            usage_limit_per_customer INT UNSIGNED NULL,
            customer_key VARCHAR(190) NULL,
            first_order_only TINYINT(1) NOT NULL DEFAULT 0,
            applies_to_json LONGTEXT NULL,
            metadata_json LONGTEXT NULL,
            starts_at_utc DATETIME NULL,
            ends_at_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status_window (status, starts_at_utc, ends_at_utc),
            KEY customer_key (customer_key)
        ) {$charset};";

        $sqlRedemptions = "CREATE TABLE {$redemptions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            voucher_id BIGINT UNSIGNED NOT NULL,
            voucher_code VARCHAR(64) NOT NULL,
            voucher_name VARCHAR(190) NOT NULL,
            reference_type VARCHAR(40) NOT NULL,
            reference_id VARCHAR(190) NOT NULL,
            active_reference_key VARCHAR(190) NULL,
            customer_key VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'RESERVED',
            amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL,
            discount_type VARCHAR(20) NOT NULL,
            effect_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            idempotency_key VARCHAR(190) NULL,
            metadata_json LONGTEXT NULL,
            reserved_at_utc DATETIME NOT NULL,
            expires_at_utc DATETIME NULL,
            consumed_at_utc DATETIME NULL,
            released_at_utc DATETIME NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY active_reference (active_reference_key),
            UNIQUE KEY idempotency (idempotency_key),
            KEY voucher_status (voucher_id, status),
            KEY customer_status (voucher_id, customer_key, status),
            KEY expiry (status, expires_at_utc),
            KEY reference_history (reference_type, reference_id)
        ) {$charset};";

        \dbDelta($sqlVouchers);
        \dbDelta($sqlRedemptions);
    }
}
