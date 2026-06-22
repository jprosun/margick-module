<?php
/**
 * bootstrap.php — EXPLICIT wiring entry (NO side-effect on include).
 * =================================================================
 * Including/autoloading this file only DEFINES functions. The host
 * (theme or mu-plugin) must CALL Margick\Commerce\bootstrap() to wire anything.
 * This is what lets the same package live as an mu-plugin now and a plugin
 * later with zero rewrite.
 *
 * Sprint 1 (Discount): pull-based, no hooks to register → bootstrap() is a
 * marker. Future (Payment/Booking/Order): register webhooks, fulfillment
 * dispatch, and run migrations here.
 */

declare(strict_types=1);

namespace Margick\Commerce;

if (! \function_exists(__NAMESPACE__ . '\\bootstrap')) {

    /** @param array<string,mixed> $config */
    function bootstrap(array $config = []): void
    {
        // Intentionally empty in sprint 1. Wiring is added per capability,
        // never as an include side-effect.
    }

    function version(): string
    {
        $file = \dirname(__DIR__) . '/VERSION';
        $v    = \is_readable($file) ? \trim((string) \file_get_contents($file)) : '';
        return $v !== '' ? $v : '0.0.0';
    }
}
