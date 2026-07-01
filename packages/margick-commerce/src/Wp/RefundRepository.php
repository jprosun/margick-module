<?php
/**
 * RefundRepository — the sanctioned door to the core refunds + credit_notes tables.
 * ================================================================================
 * Records a refund (idempotent by key) and issues a GAPLESS-numbered credit note.
 * PURE PERSISTENCE: the gateway HTTP call (wp_remote_post + keys) and the refundable
 * AMOUNT (RefundPolicy) live in the caller — this only stores the outcome, exactly
 * as OrderRepository is the only door to the order tables (LAW 3).
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

use Margick\Commerce\Domain\CreditNote\CreditNote;
use Margick\Commerce\Domain\Money;

final class RefundRepository
{
    /**
     * Record a refund row. Idempotent: if idempotency_key was already recorded,
     * returns the existing id (no duplicate row). Returns the refund id, or 0.
     *
     * @param array<string,mixed> $data order_id, amount, currency, is_full, mode,
     *                                   provider, provider_refund_id, reason,
     *                                   idempotency_key, credit_note_id, metadata
     */
    public static function recordRefund(array $data): int
    {
        global $wpdb;
        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        if ($orderId <= 0) {
            return 0;
        }
        $idem = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : '';
        if ($idem !== '') {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . CoreSchema::table('refunds') . ' WHERE idempotency_key = %s',
                $idem
            ));
            if ($existing > 0) {
                return $existing; // already recorded — no double refund row
            }
        }
        $now = \gmdate('Y-m-d H:i:s');
        $row = [
            'order_id'           => $orderId,
            'provider'           => isset($data['provider']) ? (string) $data['provider'] : 'stripe',
            'provider_refund_id' => isset($data['provider_refund_id']) ? (string) $data['provider_refund_id'] : null,
            'amount'             => isset($data['amount']) ? \round((float) $data['amount'], 2) : 0.00,
            'currency'           => isset($data['currency']) ? \strtoupper((string) $data['currency']) : 'SGD',
            'is_full'            => ! empty($data['is_full']) ? 1 : 0,
            'mode'               => isset($data['mode']) ? (string) $data['mode'] : 'live',
            'reason'             => isset($data['reason']) ? (string) $data['reason'] : null,
            'credit_note_id'     => isset($data['credit_note_id']) ? (int) $data['credit_note_id'] : null,
            'idempotency_key'    => $idem !== '' ? $idem : null,
            'metadata_json'      => isset($data['metadata']) ? (string) \wp_json_encode($data['metadata']) : null,
            'created_at_utc'     => $now,
        ];
        $ok = $wpdb->insert(CoreSchema::table('refunds'), $row);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Issue a credit note for an order with a GAPLESS, per-year number
     * (CN-<YYYY>-<NNNNNN>). Returns a CreditNote, or null on failure.
     *
     * @param array<string,mixed> $opts reason, refund_id, prefix, year, metadata
     */
    public static function issueCreditNote(int $orderId, Money $amount, array $opts = []): ?CreditNote
    {
        global $wpdb;
        if ($orderId <= 0) {
            return null;
        }
        $year   = isset($opts['year']) ? (string) $opts['year'] : \gmdate('Y');
        $prefix = (isset($opts['prefix']) ? (string) $opts['prefix'] : 'CN') . '-' . $year;
        $number = SequenceNumberAllocator::nextFormatted('credit_note:' . $year, $prefix, 6);
        if ($number === '') {
            return null; // allocator failed — do NOT issue an unnumbered note
        }
        $now    = \gmdate('Y-m-d H:i:s');
        $reason = isset($opts['reason']) ? (string) $opts['reason'] : '';
        $row = [
            'credit_note_no' => $number,
            'order_id'       => $orderId,
            'refund_id'      => isset($opts['refund_id']) ? (int) $opts['refund_id'] : null,
            'amount'         => \round($amount->toMajor(), 2),
            'currency'       => $amount->currency(),
            'reason'         => $reason !== '' ? $reason : null,
            'metadata_json'  => isset($opts['metadata']) ? (string) \wp_json_encode($opts['metadata']) : null,
            'issued_at_utc'  => $now,
        ];
        $ok = $wpdb->insert(CoreSchema::table('credit_notes'), $row);
        if (! $ok) {
            return null;
        }
        return new CreditNote((int) $wpdb->insert_id, $number, $orderId, $amount, $reason, $now);
    }

    /** @return array<int,array<string,mixed>> */
    public static function refundsForOrder(int $orderId): array
    {
        global $wpdb;
        if ($orderId <= 0) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . CoreSchema::table('refunds') . ' WHERE order_id = %d ORDER BY id',
            $orderId
        ), ARRAY_A) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function creditNotesForOrder(int $orderId): array
    {
        global $wpdb;
        if ($orderId <= 0) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . CoreSchema::table('credit_notes') . ' WHERE order_id = %d ORDER BY id',
            $orderId
        ), ARRAY_A) ?: [];
    }
}
