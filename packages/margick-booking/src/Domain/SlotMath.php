<?php
/**
 * SlotMath — PURE slot/block time math (no WordPress, no DB).
 * ==========================================================
 * The industry-agnostic core of the booking engine: turn a [start,end) interval
 * into the fixed-size block rows the lock engine INSERTs (one collision = one
 * double-book prevented), and the canonical slot key.
 *
 * What is NOT here (stays in the app / WP adapter, deferred to industry #2):
 *   - the ATOMIC hold itself (it relies on $wpdb transaction + a UNIQUE index;
 *     atomicity IS the database — it cannot be a pure class)
 *   - the bookings/locks tables (currently edu-shaped: tutor_post_id)
 *   - resource generalization (tutor → staff → table)
 * `resourceId` is kept generic here so this math is reusable as-is.
 */

declare(strict_types=1);

namespace Margick\Booking\Domain;

final class SlotMath
{
    /**
     * Canonical slot key "{resource}:{startUTC}:{endUTC}" — identifies a slot
     * across availability → hold → payment. Resource = tutor|staff|table id.
     */
    public static function slotKey(int $resourceId, string $startUtc, string $endUtc): string
    {
        return $resourceId . ':' . $startUtc . ':' . $endUtc;
    }

    /**
     * Expand [start,end) UTC into the list of block-start datetimes it occupies.
     * A 90-min lesson at 15-min blocks → six rows. PURE.
     *
     * @return string[] e.g. ['2026-06-10 10:00:00','2026-06-10 10:15:00', ...]
     */
    public static function expandToBlocks(string $startUtc, string $endUtc, int $blockMinutes = 15): array
    {
        $blocks = [];
        if ($blockMinutes <= 0) {
            return $blocks;
        }
        try {
            $cur = new \DateTime($startUtc, new \DateTimeZone('UTC'));
            $end = new \DateTime($endUtc, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return $blocks;
        }
        $step = $blockMinutes * 60;
        while ($cur < $end) {
            $blocks[] = $cur->format('Y-m-d H:i:s');
            $cur->modify('+' . $step . ' seconds');
        }
        return $blocks;
    }
}
