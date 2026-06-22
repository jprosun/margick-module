<?php
/**
 * OrderRepository — the ONLY sanctioned door to the core order tables.
 * ====================================================================
 * SCHEMA-AND-MIGRATIONS.md LAW 3: templates must never touch these tables with
 * raw $wpdb. Every read/write goes through here (table names + guards live in
 * one place), so a template can never ALTER or hand-query the core.
 *
 * Money math for the line snapshot is rounded to the stored DECIMAL(15,2) scale.
 * The seam is industry-blind: callers pass item_type + item_ref_id + a complete
 * snapshot (LAW 2). The repository never interprets item_type.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

final class OrderRepository
{
    /**
     * Create an order envelope. Generates an order_code when absent. Returns the
     * new order id, or 0 on failure.
     *
     * @param array<string,mixed> $data customer_user_id, currency, status, metadata
     */
    public static function createOrder(array $data = []): int
    {
        global $wpdb;
        $now = \gmdate('Y-m-d H:i:s');
        $row = [
            'order_code'       => isset($data['order_code']) ? (string) $data['order_code'] : self::generateCode(),
            'customer_user_id' => isset($data['customer_user_id']) ? (int) $data['customer_user_id'] : null,
            'status'           => isset($data['status']) ? (string) $data['status'] : 'PENDING',
            'currency'         => isset($data['currency']) ? \strtoupper((string) $data['currency']) : 'SGD',
            'subtotal_amount'  => 0.00,
            'discount_amount'  => 0.00,
            'tax_amount'       => 0.00,
            'total_amount'     => 0.00,
            'metadata_json'    => isset($data['metadata']) ? (string) \wp_json_encode($data['metadata']) : null,
            'created_at_utc'   => $now,
            'updated_at_utc'   => $now,
        ];
        $ok = $wpdb->insert(CoreSchema::table('orders'), $row);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Append a SELF-SUFFICIENT (LAW 2) line item to an order. line_total is
     * computed from the snapshot when not supplied. Returns the item id, or 0.
     *
     * @param array<string,mixed> $item item_type, item_ref_id, name, sku,
     *                                   sell_unit, unit_price, qty, options,
     *                                   line_discount, line_tax
     */
    public static function addItem(int $orderId, array $item): int
    {
        global $wpdb;
        if ($orderId <= 0 || empty($item['item_type']) || ! isset($item['name'])) {
            return 0; // guard: a line MUST be attributable + named (snapshot)
        }

        $unitPrice    = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
        $qty          = isset($item['qty']) ? (float) $item['qty'] : 1.0;
        $lineDiscount = isset($item['line_discount']) ? (float) $item['line_discount'] : 0.0;
        $lineTax      = isset($item['line_tax']) ? (float) $item['line_tax'] : 0.0;
        $lineTotal    = \array_key_exists('line_total', $item)
            ? (float) $item['line_total']
            : \round($unitPrice * $qty - $lineDiscount + $lineTax, 2);

        $row = [
            'order_id'      => $orderId,
            'item_type'     => (string) $item['item_type'],
            'item_ref_id'   => isset($item['item_ref_id']) ? (int) $item['item_ref_id'] : null,
            'name'          => (string) $item['name'],
            'sku'           => isset($item['sku']) ? (string) $item['sku'] : null,
            'sell_unit'     => isset($item['sell_unit']) ? (string) $item['sell_unit'] : 'piece',
            'unit_price'    => \round($unitPrice, 2),
            'qty'           => $qty,
            'options_json'  => isset($item['options']) ? (string) \wp_json_encode($item['options']) : null,
            'line_discount' => \round($lineDiscount, 2),
            'line_tax'      => \round($lineTax, 2),
            'line_total'    => \round($lineTotal, 2),
            'created_at_utc' => \gmdate('Y-m-d H:i:s'),
        ];
        $ok = $wpdb->insert(CoreSchema::table('order_items'), $row);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Resolve an existing order id by its (unique) order_code. Returns 0 if none.
     * Lets an industry adapter make its dual-write idempotent with a deterministic
     * code (e.g. 'BKG-<booking_code>') instead of tracking a link column.
     */
    public static function findIdByCode(string $code): int
    {
        global $wpdb;
        if ($code === '') {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . CoreSchema::table('orders') . ' WHERE order_code = %s', $code)
        );
    }

    /** @return array<string,mixed>|null */
    public static function getOrder(int $orderId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . CoreSchema::table('orders') . ' WHERE id = %d', $orderId),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function getItems(int $orderId): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . CoreSchema::table('order_items') . ' WHERE order_id = %d ORDER BY id', $orderId),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Order + its line items in one structure (the read shape a dashboard/invoice
     * wants). Repo-only: callers never hand-join the core tables.
     *
     * @return array<string,mixed>|null
     */
    public static function getOrderWithItems(int $orderId): ?array
    {
        $order = self::getOrder($orderId);
        if (! $order) {
            return null;
        }
        $order['items'] = self::getItems($orderId);
        return $order;
    }

    /**
     * Orders belonging to a customer, newest first. The sanctioned way for an
     * industry surface (e.g. a parent dashboard) to list orders without raw SQL.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerUserId, int $limit = 20): array
    {
        global $wpdb;
        if ($customerUserId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . CoreSchema::table('orders')
                . ' WHERE customer_user_id = %d ORDER BY id DESC LIMIT %d',
                $customerUserId,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Attach a customer to an order that doesn't have one yet. Used when the
     * payer account is created late (e.g. edu creates the parent only at confirm,
     * after the PENDING order was opened at hold time). Never overwrites an
     * existing customer.
     */
    public static function assignCustomer(int $orderId, int $customerUserId): bool
    {
        global $wpdb;
        if ($orderId <= 0 || $customerUserId <= 0) {
            return false;
        }
        $n = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . CoreSchema::table('orders')
                . ' SET customer_user_id = %d, updated_at_utc = %s'
                . ' WHERE id = %d AND (customer_user_id IS NULL OR customer_user_id = 0)',
                $customerUserId,
                \gmdate('Y-m-d H:i:s'),
                $orderId
            )
        );
        return (int) $n > 0;
    }

    /** Update an order's status (lifecycle transitions). Returns true on change. */
    public static function updateStatus(int $orderId, string $status): bool
    {
        global $wpdb;
        if ($orderId <= 0 || $status === '') {
            return false;
        }
        $n = $wpdb->update(
            CoreSchema::table('orders'),
            ['status' => $status, 'updated_at_utc' => \gmdate('Y-m-d H:i:s')],
            ['id' => $orderId]
        );
        return $n !== false;
    }

    /**
     * Recompute order money from its items and persist. subtotal = Σ(unit_price×qty),
     * discount = Σ line_discount, tax = Σ line_tax, total = Σ line_total.
     */
    public static function recalcTotals(int $orderId): void
    {
        global $wpdb;
        $subtotal = 0.0; $discount = 0.0; $tax = 0.0; $total = 0.0;
        foreach (self::getItems($orderId) as $it) {
            $subtotal += (float) $it['unit_price'] * (float) $it['qty'];
            $discount += (float) $it['line_discount'];
            $tax      += (float) $it['line_tax'];
            $total    += (float) $it['line_total'];
        }
        $wpdb->update(
            CoreSchema::table('orders'),
            [
                'subtotal_amount' => \round($subtotal, 2),
                'discount_amount' => \round($discount, 2),
                'tax_amount'      => \round($tax, 2),
                'total_amount'    => \round($total, 2),
                'updated_at_utc'  => \gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $orderId]
        );
    }

    private static function generateCode(): string
    {
        // ORD-YYYYMMDD-XXXXXX (uppercase, collision-resistant enough for one site).
        $rand = \strtoupper(\substr(\str_replace(['+', '/', '='], '', \base64_encode(\random_bytes(6))), 0, 6));
        return 'ORD-' . \gmdate('Ymd') . '-' . $rand;
    }
}
