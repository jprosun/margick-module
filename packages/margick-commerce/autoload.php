<?php
/**
 * Standalone PSR-4 autoloader for Margick\Commerce.
 * ================================================
 * Lets the package load WITHOUT composer (fits the WP/theme/mu-plugin world).
 * When composer IS available, vendor/autoload.php supersedes this — both map
 * Margick\Commerce\ → src/, so behaviour is identical.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Margick\\Commerce\\';
    $len    = \strlen($prefix);
    if (\strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $rel  = \str_replace('\\', '/', \substr($class, $len));
    $file = __DIR__ . '/src/' . $rel . '.php';
    if (\is_file($file)) {
        require $file;
    }
});

// Function-style API (bootstrap/version) is not autoloadable → load explicitly.
require_once __DIR__ . '/src/bootstrap.php';
