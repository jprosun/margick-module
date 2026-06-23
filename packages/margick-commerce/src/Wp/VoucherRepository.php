<?php

declare(strict_types=1);

namespace Margick\Commerce\Wp;

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Voucher\Domain\Voucher;
use Margick\Commerce\Voucher\Domain\VoucherContext;
use Margick\Commerce\Voucher\Domain\VoucherDecision;
use Margick\Commerce\Voucher\Domain\VoucherValidator;

/**
 * Persistence boundary for vouchers and their reservation lifecycle.
 * All quota decisions made by reserve() are serialized by a voucher-row lock.
 */
final class VoucherRepository
{
    /**
     * Create or update a voucher by normalized code.
     * Monetary inputs are integer minor units; percentages are basis points.
     *
     * @param array<string,mixed> $data
     */
    public static function upsert(array $data): int
    {
        global $wpdb;
        $code = Voucher::normalizeCode((string) ($data['code'] ?? ''));
        if ($code === '' || ! \preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $code)) {
            return 0;
        }

        $now = \gmdate('Y-m-d H:i:s');
        $row = [
            'code'                       => $code,
            'name'                       => (string) ($data['name'] ?? $code),
            'status'                     => (string) ($data['status'] ?? Voucher::STATUS_ACTIVE),
            'discount_type'              => (string) ($data['discount_type'] ?? Voucher::TYPE_PERCENT),
            'percentage_bps'             => \max(0, (int) ($data['percentage_bps'] ?? 0)),
            'fixed_amount_minor'          => \max(0, (int) ($data['fixed_amount_minor'] ?? 0)),
            'currency'                    => self::nullableUpper($data['currency'] ?? null),
            'min_order_minor'             => \max(0, (int) ($data['min_order_minor'] ?? 0)),
            'max_discount_minor'          => self::nullablePositiveInt($data['max_discount_minor'] ?? null),
            'stackable'                   => ! empty($data['stackable']) ? 1 : 0,
            'respect_global_cap'          => ! empty($data['respect_global_cap']) ? 1 : 0,
            'usage_limit'                 => self::nullablePositiveInt($data['usage_limit'] ?? null),
            'usage_limit_per_customer'    => self::nullablePositiveInt($data['usage_limit_per_customer'] ?? null),
            'customer_key'                => Voucher::normalizeCustomerKey(isset($data['customer_key']) ? (string) $data['customer_key'] : null),
            'first_order_only'            => ! empty($data['first_order_only']) ? 1 : 0,
            'applies_to_json'             => self::encodeStringList((array) ($data['applies_to'] ?? [])),
            'metadata_json'               => isset($data['metadata']) ? (string) \wp_json_encode($data['metadata']) : null,
            'starts_at_utc'               => self::nullableDate($data['starts_at_utc'] ?? null),
            'ends_at_utc'                 => self::nullableDate($data['ends_at_utc'] ?? null),
            'updated_at_utc'              => $now,
        ];

        $table = VoucherSchema::table('vouchers');
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $code));
        if ($id > 0) {
            return $wpdb->update($table, $row, ['id' => $id]) === false ? 0 : $id;
        }
        $row['created_at_utc'] = $now;
        return $wpdb->insert($table, $row) ? (int) $wpdb->insert_id : 0;
    }

    public static function findByCode(string $code): ?Voucher
    {
        global $wpdb;
        $table = VoucherSchema::table('vouchers');
        $normalized = Voucher::normalizeCode($code);
        if ($normalized === '') {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE code = %s", $normalized),
            ARRAY_A
        );
        return \is_array($row) ? self::hydrate($row) : null;
    }

    public static function setStatus(string $code, string $status): bool
    {
        global $wpdb;
        $normalized = Voucher::normalizeCode($code);
        if ($normalized === '') {
            return false;
        }
        $table = VoucherSchema::table('vouchers');
        return $wpdb->update($table, [
            'status' => $status,
            'updated_at_utc' => \gmdate('Y-m-d H:i:s'),
        ], ['code' => $normalized]) !== false;
    }

    /** Import a legacy aggregate counter as immutable consumed audit rows. */
    public static function importLegacyUsage(string $code, int $count): int
    {
        global $wpdb;
        $voucher = self::findByCode($code);
        if ($voucher === null || $count <= 0) {
            return 0;
        }
        $table = VoucherSchema::table('redemptions');
        $stamp = \gmdate('Y-m-d H:i:s');
        $effectValue = $voucher->discountType === Voucher::TYPE_PERCENT
            ? $voucher->percentageBps
            : $voucher->fixedAmountMinor;
        $inserted = 0;
        for ($i = 1; $i <= $count; $i++) {
            $key = 'legacy-voucher:' . $voucher->id . ':' . $i;
            $row = [
                'voucher_id' => $voucher->id,
                'voucher_code' => $voucher->code,
                'voucher_name' => $voucher->name,
                'reference_type' => 'legacy',
                'reference_id' => (string) $i,
                'active_reference_key' => null,
                'customer_key' => null,
                'status' => 'CONSUMED',
                'amount_minor' => 0,
                'currency' => $voucher->currency ?? 'SGD',
                'discount_type' => $voucher->discountType,
                'effect_value' => $effectValue,
                'idempotency_key' => $key,
                'metadata_json' => (string) \wp_json_encode(['source' => 'legacy_counter']),
                'reserved_at_utc' => $stamp,
                'expires_at_utc' => null,
                'consumed_at_utc' => $stamp,
                'released_at_utc' => null,
                'updated_at_utc' => $stamp,
            ];
            if ($wpdb->insert($table, $row)) {
                $inserted++;
            }
        }
        return $inserted;
    }

    public static function preview(
        string $code,
        VoucherContext $context,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): VoucherDecision
    {
        self::expireReservations($context->now);
        $voucher = self::findByCode($code);
        if ($voucher === null) {
            return VoucherDecision::reject('not_found', 'Invalid voucher code.');
        }
        $usage = self::activeUsageCount($voucher->id);
        $customerUsage = self::customerActiveUsageCount($voucher->id, $context->customerKey);
        if ($referenceType !== null && $referenceId !== null) {
            $existing = self::findActiveByReference($referenceType, $referenceId);
            if (\is_array($existing) && (int) $existing['voucher_id'] === $voucher->id) {
                $usage--;
                if (Voucher::normalizeCustomerKey((string) ($existing['customer_key'] ?? ''))
                    === Voucher::normalizeCustomerKey($context->customerKey)) {
                    $customerUsage--;
                }
            }
        }
        return (new VoucherValidator())->evaluate(
            $voucher,
            $context,
            \max(0, $usage),
            \max(0, $customerUsage)
        );
    }

    /**
     * Atomically reserve or replace the single voucher attached to a reference.
     *
     * @return array{ok:bool,reason:string,message:string,reservation_id:int,decision:VoucherDecision}
     */
    public static function reserve(
        string $code,
        VoucherContext $context,
        string $referenceType,
        string $referenceId,
        int $ttlSeconds = 900,
        string $idempotencyKey = '',
        array $metadata = [],
        ?Money $appliedDiscount = null
    ): array {
        global $wpdb;
        $referenceKey = self::referenceKey($referenceType, $referenceId);
        if ($referenceKey === '') {
            return self::failedReservation('invalid_reference', 'A voucher reference is required.');
        }
        self::expireReservations($context->now);

        $voucherTable = VoucherSchema::table('vouchers');
        $redemptionTable = VoucherSchema::table('redemptions');
        $now = $context->now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $expires = $context->now->modify('+' . \max(60, $ttlSeconds) . ' seconds')
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $normalizedCode = Voucher::normalizeCode($code);

        $wpdb->query('START TRANSACTION');
        try {
            $voucherRow = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$voucherTable} WHERE code = %s FOR UPDATE", $normalizedCode),
                ARRAY_A
            );
            if (! \is_array($voucherRow)) {
                $wpdb->query('ROLLBACK');
                return self::failedReservation('not_found', 'Invalid voucher code.');
            }
            $voucher = self::hydrate($voucherRow);

            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$redemptionTable} WHERE active_reference_key = %s FOR UPDATE", $referenceKey),
                ARRAY_A
            );
            if (! \is_array($existing) && $idempotencyKey !== '') {
                $idempotent = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$redemptionTable} WHERE idempotency_key = %s FOR UPDATE", $idempotencyKey),
                    ARRAY_A
                );
                if (\is_array($idempotent)) {
                    $sameIdentity = (int) $idempotent['voucher_id'] === $voucher->id
                        && (string) $idempotent['reference_type'] === \strtolower(\trim($referenceType))
                        && (string) $idempotent['reference_id'] === \trim($referenceId);
                    if (! $sameIdentity || ! \in_array((string) $idempotent['status'], ['RELEASED', 'EXPIRED'], true)) {
                        $wpdb->query('ROLLBACK');
                        return self::failedReservation('idempotency_conflict', 'This voucher request was already used.');
                    }
                    $existing = $idempotent;
                }
            }
            if (\is_array($existing) && (string) $existing['status'] === 'CONSUMED') {
                $wpdb->query('ROLLBACK');
                return self::failedReservation('already_consumed', 'This order already consumed a voucher.');
            }

            $sameReservation = \is_array($existing) && (int) $existing['voucher_id'] === $voucher->id;
            $sameActiveReservation = $sameReservation
                && \in_array((string) $existing['status'], ['RESERVED', 'CONSUMED'], true);
            $usage = self::activeUsageCount($voucher->id) - ($sameActiveReservation ? 1 : 0);
            $customerUsage = self::customerActiveUsageCount($voucher->id, $context->customerKey);
            if ($sameActiveReservation
                && Voucher::normalizeCustomerKey((string) ($existing['customer_key'] ?? '')) === Voucher::normalizeCustomerKey($context->customerKey)) {
                $customerUsage--;
            }
            $decision = (new VoucherValidator())->evaluate(
                $voucher,
                $context,
                \max(0, $usage),
                \max(0, $customerUsage)
            );
            if (! $decision->valid || $decision->discount === null) {
                $wpdb->query('ROLLBACK');
                return self::failedReservation($decision->reason, $decision->message, $decision);
            }
            if ($appliedDiscount !== null) {
                if ($appliedDiscount->currency() !== $decision->discount->currency()
                    || $appliedDiscount->isZero()
                    || $appliedDiscount->minor() > $decision->discount->minor()) {
                    $wpdb->query('ROLLBACK');
                    return self::failedReservation(
                        'invalid_applied_amount',
                        'The applied voucher amount does not match its validated benefit.'
                    );
                }
                $decision = VoucherDecision::accept($voucher, $appliedDiscount);
            }

            if (\is_array($existing) && ! $sameReservation) {
                $released = $wpdb->update($redemptionTable, [
                    'status'               => 'RELEASED',
                    'active_reference_key' => null,
                    'released_at_utc'      => $now,
                    'updated_at_utc'       => $now,
                ], ['id' => (int) $existing['id']]);
                if ($released === false) {
                    throw new \RuntimeException('Unable to release the previous voucher.');
                }
            }

            $effectValue = $voucher->discountType === Voucher::TYPE_PERCENT
                ? $voucher->percentageBps
                : $voucher->fixedAmountMinor;
            $row = [
                'voucher_id'           => $voucher->id,
                'voucher_code'         => $voucher->code,
                'voucher_name'         => $voucher->name,
                'reference_type'       => \strtolower(\trim($referenceType)),
                'reference_id'         => \trim($referenceId),
                'active_reference_key' => $referenceKey,
                'customer_key'         => Voucher::normalizeCustomerKey($context->customerKey),
                'status'               => 'RESERVED',
                'amount_minor'         => $decision->discount->minor(),
                'currency'             => $decision->discount->currency(),
                'discount_type'        => $voucher->discountType,
                'effect_value'         => $effectValue,
                'idempotency_key'      => $idempotencyKey !== '' ? $idempotencyKey : null,
                'metadata_json'        => $metadata !== [] ? (string) \wp_json_encode($metadata) : null,
                'reserved_at_utc'      => $now,
                'expires_at_utc'       => $expires,
                'consumed_at_utc'      => null,
                'released_at_utc'      => null,
                'updated_at_utc'       => $now,
            ];

            if ($sameReservation) {
                $reservationId = (int) $existing['id'];
                if ($wpdb->update($redemptionTable, $row, ['id' => $reservationId]) === false) {
                    throw new \RuntimeException('Unable to refresh the voucher reservation.');
                }
            } else {
                if (! $wpdb->insert($redemptionTable, $row)) {
                    throw new \RuntimeException('Unable to create the voucher reservation.');
                }
                $reservationId = (int) $wpdb->insert_id;
            }

            $wpdb->query('COMMIT');
            return [
                'ok' => true,
                'reason' => 'ok',
                'message' => '',
                'reservation_id' => $reservationId,
                'decision' => $decision,
            ];
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::failedReservation('persistence_error', $e->getMessage());
        }
    }

    public static function consume(string $referenceType, string $referenceId, ?\DateTimeImmutable $now = null): bool
    {
        global $wpdb;
        $key = self::referenceKey($referenceType, $referenceId);
        if ($key === '') {
            return false;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::expireReservations($now);
        $table = VoucherSchema::table('redemptions');
        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE active_reference_key = %s FOR UPDATE",
            $key
        ), ARRAY_A);
        if (! \is_array($row)) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        if ((string) $row['status'] === 'CONSUMED') {
            $wpdb->query('COMMIT');
            return true;
        }
        if ((string) $row['status'] !== 'RESERVED') {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $ok = $wpdb->update($table, [
            'status'          => 'CONSUMED',
            'expires_at_utc'  => null,
            'consumed_at_utc' => $stamp,
            'updated_at_utc'  => $stamp,
        ], ['id' => (int) $row['id']]) !== false;
        $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        return $ok;
    }

    /** Only unconsumed reservations are releasable; paid usage remains auditable. */
    public static function release(string $referenceType, string $referenceId, ?\DateTimeImmutable $now = null): bool
    {
        global $wpdb;
        $key = self::referenceKey($referenceType, $referenceId);
        if ($key === '') {
            return false;
        }
        $stamp = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $table = VoucherSchema::table('redemptions');
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'RELEASED', active_reference_key = NULL,
                 released_at_utc = %s, updated_at_utc = %s
             WHERE active_reference_key = %s AND status = 'RESERVED'",
            $stamp,
            $stamp,
            $key
        )) !== false;
    }

    public static function expireReservations(?\DateTimeImmutable $now = null): int
    {
        global $wpdb;
        $stamp = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $table = VoucherSchema::table('redemptions');
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'EXPIRED', active_reference_key = NULL, updated_at_utc = %s
             WHERE status = 'RESERVED' AND expires_at_utc IS NOT NULL AND expires_at_utc <= %s",
            $stamp,
            $stamp
        ));
        return $changed === false ? 0 : (int) $changed;
    }

    /** @return array<string,mixed>|null */
    public static function findActiveByReference(string $referenceType, string $referenceId): ?array
    {
        global $wpdb;
        $key = self::referenceKey($referenceType, $referenceId);
        if ($key === '') {
            return null;
        }
        $table = VoucherSchema::table('redemptions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE active_reference_key = %s",
            $key
        ), ARRAY_A);
        return \is_array($row) ? $row : null;
    }

    /** @return array{reserved:int,consumed:int,total_active:int} */
    public static function usageStats(string $code): array
    {
        global $wpdb;
        $voucher = self::findByCode($code);
        if ($voucher === null) {
            return ['reserved' => 0, 'consumed' => 0, 'total_active' => 0];
        }
        $table = VoucherSchema::table('redemptions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS qty FROM {$table}
             WHERE voucher_id = %d AND status IN ('RESERVED','CONSUMED') GROUP BY status",
            $voucher->id
        ), ARRAY_A) ?: [];
        $stats = ['reserved' => 0, 'consumed' => 0, 'total_active' => 0];
        foreach ($rows as $row) {
            $key = \strtolower((string) $row['status']);
            if (\array_key_exists($key, $stats)) {
                $stats[$key] = (int) $row['qty'];
            }
        }
        $stats['total_active'] = $stats['reserved'] + $stats['consumed'];
        return $stats;
    }

    private static function activeUsageCount(int $voucherId): int
    {
        global $wpdb;
        $table = VoucherSchema::table('redemptions');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE voucher_id = %d AND status IN ('RESERVED','CONSUMED')",
            $voucherId
        ));
    }

    private static function customerActiveUsageCount(int $voucherId, ?string $customerKey): int
    {
        global $wpdb;
        $key = Voucher::normalizeCustomerKey($customerKey);
        if ($key === null) {
            return 0;
        }
        $table = VoucherSchema::table('redemptions');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE voucher_id = %d AND customer_key = %s AND status IN ('RESERVED','CONSUMED')",
            $voucherId,
            $key
        ));
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): Voucher
    {
        $appliesTo = \json_decode((string) ($row['applies_to_json'] ?? ''), true);
        return new Voucher(
            id: (int) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            status: (string) $row['status'],
            discountType: (string) $row['discount_type'],
            percentageBps: (int) $row['percentage_bps'],
            fixedAmountMinor: (int) $row['fixed_amount_minor'],
            currency: self::nullableUpper($row['currency'] ?? null),
            minOrderMinor: (int) $row['min_order_minor'],
            maxDiscountMinor: self::nullablePositiveInt($row['max_discount_minor'] ?? null),
            stackable: (int) $row['stackable'] === 1,
            respectGlobalCap: (int) ($row['respect_global_cap'] ?? 0) === 1,
            usageLimit: self::nullablePositiveInt($row['usage_limit'] ?? null),
            usageLimitPerCustomer: self::nullablePositiveInt($row['usage_limit_per_customer'] ?? null),
            customerKey: Voucher::normalizeCustomerKey(isset($row['customer_key']) ? (string) $row['customer_key'] : null),
            firstOrderOnly: (int) $row['first_order_only'] === 1,
            appliesTo: \is_array($appliesTo) ? \array_values(\array_filter($appliesTo, 'is_string')) : [],
            startsAt: self::dateObject($row['starts_at_utc'] ?? null),
            endsAt: self::dateObject($row['ends_at_utc'] ?? null)
        );
    }

    private static function referenceKey(string $type, string $id): string
    {
        $type = \strtolower((string) \preg_replace('/[^a-z0-9_-]/i', '', \trim($type)));
        $id = \trim($id);
        if ($type === '' || $id === '') {
            return '';
        }
        return \substr($type . ':' . $id, 0, 190);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private static function nullableUpper(mixed $value): ?string
    {
        $string = \strtoupper(\trim((string) $value));
        return $string === '' ? null : $string;
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $string = \trim((string) $value);
        if ($string === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($string, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function dateObject(mixed $value): ?\DateTimeImmutable
    {
        $string = \trim((string) $value);
        if ($string === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($string, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param string[] $values */
    private static function encodeStringList(array $values): ?string
    {
        $values = \array_values(\array_unique(\array_filter(\array_map(
            static fn (mixed $value): string => \trim((string) $value),
            $values
        ))));
        return $values === [] ? null : (string) \wp_json_encode($values);
    }

    /** @return array{ok:bool,reason:string,message:string,reservation_id:int,decision:VoucherDecision} */
    private static function failedReservation(
        string $reason,
        string $message,
        ?VoucherDecision $decision = null
    ): array {
        return [
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
            'reservation_id' => 0,
            'decision' => $decision ?? VoucherDecision::reject($reason, $message),
        ];
    }
}
