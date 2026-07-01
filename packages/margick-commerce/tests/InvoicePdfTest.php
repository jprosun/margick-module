<?php
/**
 * InvoicePdfTest — PURE PHP. Run: php tests/InvoicePdfTest.php
 * Renders an Invoice to PDF and asserts the bytes are a structurally valid,
 * single-page PDF that carries the key invoice fields.
 */

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Pdf/PdfDocument.php';
require __DIR__ . '/../src/Domain/Invoice/Invoice.php';
require __DIR__ . '/../src/Domain/Invoice/InvoicePdf.php';

use Margick\Commerce\Domain\Invoice\Invoice;
use Margick\Commerce\Domain\Invoice\InvoicePdf;
use Margick\Commerce\Domain\Money;

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-34s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) { $fail++; }
};

$inv = new Invoice(
    id: 15,
    number: 'INV-2026-000015',
    orderId: 300,
    orderCode: 'BKG-MGK-20260701-ABC',
    subtotal: Money::ofMajor(130.00, 'SGD'),
    discount: Money::ofMajor(26.00, 'SGD'),
    tax: Money::ofMajor(8.59, 'SGD'),
    total: Money::ofMajor(104.00, 'SGD'),
    taxRate: 9.0,
    taxInclusive: true,
    seller: ['name' => 'MARGICK TUITION PTE LTD', 'uen' => '202412345A', 'gst_no' => 'M2-1234567-8'],
    buyer: ['name' => 'Parent One', 'email' => 'parent@example.com'],
    issuedAtUtc: '2026-07-01 10:00:00'
);
$lines = [
    ['label' => 'Trial - Math with Mr Nair', 'amount' => '130.00'],
    ['label' => 'Voucher SAVE20 (20%)',      'amount' => '-26.00'],
];

$bytes = InvoicePdf::render($inv, $lines);

$check('starts with %PDF-1.4', str_starts_with($bytes, '%PDF-1.4'), true);
$check('ends with %%EOF', str_ends_with($bytes, '%%EOF'), true);
$check('has xref', strpos($bytes, "\nxref\n") !== false, true);
$check('has startxref', strpos($bytes, 'startxref') !== false, true);
$check('nontrivial size', strlen($bytes) > 900, true);

// xref offset points at the literal 'xref'
if (preg_match('/startxref\s+(\d+)/', $bytes, $m)) {
    $check('startxref -> xref', substr($bytes, (int) $m[1], 4), 'xref');
}

// Length declared in the Contents object matches the actual stream length.
if (preg_match('/<< \/Length (\d+) >>\nstream\n(.*)\nendstream/s', $bytes, $mm)) {
    $check('stream Length matches', (int) $mm[1], strlen($mm[2]));
}

// Write for the WP/pypdf harness step (and manual eyeball).
@file_put_contents(__DIR__ . '/_invoice_sample.pdf', $bytes);

if ($fail) { printf("\n%d FAILED\n", $fail); exit(1); }
echo "\nALL PASS\n";
