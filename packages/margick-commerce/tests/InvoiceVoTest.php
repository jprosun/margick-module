<?php
/**
 * InvoiceVoTest — PURE PHP. Run: php tests/InvoiceVoTest.php
 * Proves the Invoice value object's shape/accessors (no DB).
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Domain/Invoice/Invoice.php';

use Margick\Commerce\Domain\Invoice\Invoice;
use Margick\Commerce\Domain\Money;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-28s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) { $fail++; }
};

$inv = new Invoice(
    id: 7,
    number: 'INV-2026-000042',
    orderId: 300,
    orderCode: 'ORD-1',
    subtotal: Money::ofMajor(100.00, 'SGD'),
    discount: Money::ofMajor(10.00, 'SGD'),
    tax: Money::ofMajor(8.26, 'SGD'),
    total: Money::ofMajor(90.00, 'SGD'),
    taxRate: 9.0,
    taxInclusive: true,
    seller: ['name' => 'MARGICK TUITION PTE LTD', 'uen' => '202412345A', 'gst_no' => 'M2-1'],
    buyer: ['name' => 'P', 'email' => 'p@example.com'],
    issuedAtUtc: '2026-07-01 10:00:00'
);

$check('number', $inv->number, 'INV-2026-000042');
$check('currency from total', $inv->currency(), 'SGD');
$check('default status ISSUED', $inv->status, Invoice::STATUS_ISSUED);
$arr = $inv->toArray();
$check('toArray total major', $arr['total'], 90.00);
$check('toArray tax major', $arr['tax'], 8.26);
$check('toArray tax_rate', $arr['tax_rate'], 9.0);
$check('toArray seller uen', $arr['seller']['uen'], '202412345A');
$check('toArray currency', $arr['currency'], 'SGD');

if ($fail) { printf("\n%d FAILED\n", $fail); exit(1); }
echo "\nALL PASS\n";
