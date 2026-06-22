<?php
/**
 * bootstrap.php — EXPLICIT wiring entry (NO side-effect on include).
 * =================================================================
 * Including/autoloading this file only DEFINES functions. The host
 * (theme or mu-plugin) must CALL Margick\Commerce\bootstrap() to wire anything.
 * This is what lets the same package live as an mu-plugin now and a plugin
 * later with zero rewrite.
 *
 * Current wiring is deliberately small: version-gated additive migrations and
 * expired voucher-reservation cleanup. Industry hooks remain in their adapters.
 */

declare(strict_types=1);

namespace Margick\Commerce;

if (! \function_exists(__NAMESPACE__ . '\\bootstrap')) {

    /** @param array<string,mixed> $config */
    function bootstrap(array $config = []): void
    {
        static $wired = false;
        if ($wired) {
            return;
        }
        $wired = true;

        // Core schema: version-gated migrate on boot (SCHEMA-AND-MIGRATIONS.md §3).
        // Registering a hook here (not on include) is the explicit-wiring rule —
        // the cheap version compare lives on the hot path; dbDelta only on a bump.
        if (\function_exists('add_action')) {
            \add_action('init', [Wp\SchemaMigrator::class, 'maybeMigrate'], 5);
            \add_action('mgk_voucher_cleanup', [Wp\VoucherRepository::class, 'expireReservations']);
            \add_action('init', static function (): void {
                if (\function_exists('wp_next_scheduled')
                    && ! \wp_next_scheduled('mgk_voucher_cleanup')) {
                    \wp_schedule_event(\time() + 300, 'hourly', 'mgk_voucher_cleanup');
                }
            }, 20);
        }
    }

    function version(): string
    {
        $file = \dirname(__DIR__) . '/VERSION';
        $v    = \is_readable($file) ? \trim((string) \file_get_contents($file)) : '';
        return $v !== '' ? $v : '0.0.0';
    }
}
