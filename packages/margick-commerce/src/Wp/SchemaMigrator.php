<?php
/**
 * SchemaMigrator — version-gated, boot-time migration runner.
 * ===========================================================
 * SCHEMA-AND-MIGRATIONS.md §3:
 *   - Migrate on boot, but ONLY do heavy work when the applied schema version on
 *     this site differs from the code's SCHEMA_VERSION (cheap option compare on
 *     the hot path; dbDelta runs once per version bump).
 *   - Schema version is its OWN axis, per capability (not the package version).
 *   - This is how an already-shipped standalone site picks up an additive schema
 *     change: a newer module lands with a bumped SCHEMA_VERSION → the next boot
 *     sees the mismatch → runs the pending (additive) migration on live data.
 *
 * Ordering note (§3.1): core tables migrate before optional core capabilities,
 * then crosscut and industry modules migrate in their own packages.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class SchemaMigrator
{
    /**
     * Run pending migrations if the recorded version differs. Idempotent: a no-op
     * once the site is at the current version. Safe to call on every boot.
     */
    public static function maybeMigrate(): void
    {
        if (! \function_exists('get_option')) {
            return; // not in WP — nothing to do
        }

        if (\get_option(CoreSchema::VERSION_OPTION) !== CoreSchema::SCHEMA_VERSION) {
            CoreSchema::install();
            \update_option(CoreSchema::VERSION_OPTION, CoreSchema::SCHEMA_VERSION);
        }

        if (\get_option(VoucherSchema::VERSION_OPTION) !== VoucherSchema::SCHEMA_VERSION) {
            VoucherSchema::install();
            \update_option(VoucherSchema::VERSION_OPTION, VoucherSchema::SCHEMA_VERSION);
        }
    }
}
