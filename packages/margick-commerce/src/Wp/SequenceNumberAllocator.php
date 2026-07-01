<?php
/**
 * SequenceNumberAllocator — GAPLESS, race-safe counters (invoice / credit-note no).
 * ================================================================================
 * Uses the MySQL LAST_INSERT_ID(expr) idiom: one UPSERT both seeds (first call) and
 * bumps (subsequent) the counter, and routes the new value through LAST_INSERT_ID so
 * each connection reads EXACTLY the value it claimed — no gap, no SELECT-race, even
 * under concurrent allocation (LR-033 / SRS credit-note gapless requirement).
 *
 * Why not "INSERT … ON DUP UPDATE last_value=last_value+1; SELECT last_value": the
 * separate SELECT can read another connection's later value. LAST_INSERT_ID() is
 * connection-local, so it returns this caller's own claimed number.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class SequenceNumberAllocator
{
    /**
     * Allocate the next integer for $key. Gapless + atomic. Returns 0 only on a hard
     * DB failure (caller should treat 0 as "do not issue a number").
     */
    public static function next(string $key): int
    {
        global $wpdb;
        if ($key === '') {
            return 0;
        }
        $t   = CoreSchema::table('sequences');
        $now = \gmdate('Y-m-d H:i:s');

        // First insert seeds 1 (wrapped in LAST_INSERT_ID so the seed path also sets
        // the connection value); the ON DUPLICATE path bumps last_value+1. Both route
        // the claimed number through LAST_INSERT_ID for a connection-local read.
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (seq_key, last_no, updated_at_utc)
                  VALUES (%s, LAST_INSERT_ID(1), %s)
             ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1),
                                     updated_at_utc = %s",
            $key,
            $now,
            $now
        ));
        if ($ok === false) {
            return 0;
        }
        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /** Convenience: prefix + zero-padded number, e.g. format('CN-2026', 17) → 'CN-2026-000017'. */
    public static function format(string $prefix, int $n, int $pad = 6, string $sep = '-'): string
    {
        return $prefix . $sep . \str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
    }

    /** Allocate + format in one call. Returns '' if allocation failed. */
    public static function nextFormatted(string $key, string $prefix, int $pad = 6): string
    {
        $n = self::next($key);
        return $n > 0 ? self::format($prefix, $n, $pad) : '';
    }
}
