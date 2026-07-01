<?php
/**
 * BookingRepository — the sanctioned door to the booking capability tables.
 * =========================================================================
 * SCHEMA-AND-MIGRATIONS.md LAW 3: code must not raw-$wpdb into the capability
 * tables; reads/writes go through a repository that owns the names (via
 * BookingSchema) and the query shapes. This is the booking-side counterpart to
 * OrderRepository — the start of routing the edu engine's data access through one
 * door so downstream templates can never hand-query the booking tables.
 *
 * Migration is incremental: the shared read accessors (mgk_get_booking_row /
 * _by_code) delegate here first; the engine's remaining direct $wpdb calls move
 * over capability-by-capability without a risky big-bang sweep.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class BookingRepository
{
    /** Fetch a booking row by id as an associative array, or null. */
    public static function getRow(int $bookingId): ?array
    {
        global $wpdb;
        $table = BookingSchema::table('bookings');
        if ($table === '' || $bookingId <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $bookingId),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Fetch a booking row by its public booking_code, or null. */
    public static function getByCode(string $code): ?array
    {
        global $wpdb;
        $table = BookingSchema::table('bookings');
        if ($table === '' || $code === '') {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE booking_code = %s LIMIT 1", $code),
            ARRAY_A
        );
        return $row ?: null;
    }
}
