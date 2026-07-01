<?php
/**
 * InvoicePdf — renders an Invoice (+ its line items) to a one-page A4 tax-invoice
 * PDF using the zero-dependency PdfDocument. PURE DOMAIN presentation: it takes
 * the already-issued Invoice VO (authoritative numbers/snapshot) plus display
 * line items and produces bytes. No DB, no WP — the caller supplies data.
 *
 * Layout (top-left points):
 *   seller block (name/UEN/GST) ........ TAX INVOICE + number/date/status
 *   bill-to (buyer) ...................... order ref
 *   items table (description | amount)
 *   totals (subtotal excl GST / GST / TOTAL)
 *   footer note
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Invoice;

use Margick\Commerce\Pdf\PdfDocument;

final class InvoicePdf
{
    /**
     * @param Invoice $invoice  the issued invoice (numbers + snapshot are authoritative)
     * @param array<int,array{label:string,amount:string}> $lines display rows
     *        (amount is a preformatted string, e.g. "130.00" or "-26.00")
     * @param array<string,string> $opts  title, footer, date, paid_label
     */
    public static function render(Invoice $invoice, array $lines = [], array $opts = []): string
    {
        $pdf   = new PdfDocument();
        $W     = $pdf->width();
        $mL    = 40.0;               // left margin
        $mR    = $W - 40.0;          // right edge
        $cur   = $invoice->currency();
        $money = static fn (float $v): string => number_format($v, 2, '.', ',');

        $seller = $invoice->seller;
        $buyer  = $invoice->buyer;

        // ── Header: seller (left) + TAX INVOICE meta (right) ──
        $y = 46.0;
        $pdf->text($mL, $y, (string) ($seller['name'] ?? ''), 15, true);
        $pdf->textRight($mR, $y + 2, 'TAX INVOICE', 15, true);
        $y += 20;
        $sellerMeta = trim(
            ((string) ($seller['uen'] ?? '') !== '' ? 'UEN ' . $seller['uen'] : '')
            . ((string) ($seller['gst_no'] ?? '') !== '' ? '   GST Reg ' . $seller['gst_no'] : '')
        );
        if ($sellerMeta !== '') {
            $pdf->text($mL, $y, $sellerMeta, 9);
        }
        $pdf->textRight($mR, $y, 'No.  ' . $invoice->number, 10, true);
        $y += 14;
        $date = (string) ($opts['date'] ?? substr($invoice->issuedAtUtc, 0, 10));
        $pdf->textRight($mR, $y, 'Date  ' . $date, 9);
        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            $pdf->textRight($mR, $y + 12, 'Status  ' . $invoice->status, 9);
        }

        // ── Rule under header ──
        $y += 22;
        $pdf->line($mL, $y, $mR, $y, 1.0);
        $y += 18;

        // ── Bill to ──
        $pdf->text($mL, $y, 'Bill to', 9, true);
        $y += 13;
        $bn = (string) ($buyer['name'] ?? '');
        if ($bn !== '') { $pdf->text($mL, $y, $bn, 10); $y += 13; }
        $be = (string) ($buyer['email'] ?? '');
        if ($be !== '') { $pdf->text($mL, $y, $be, 9); $y += 13; }
        if ($invoice->orderCode) {
            $pdf->text($mL, $y, 'Order ref: ' . $invoice->orderCode, 8);
            $y += 13;
        }

        // ── Items table header ──
        $y += 8;
        $pdf->fillRect($mL, $y - 2, $mR - $mL, 18, 0.92);
        $pdf->text($mL + 6, $y + 2, 'Description', 9, true);
        $pdf->textRight($mR - 6, $y + 2, 'Amount (' . $cur . ')', 9, true);
        $y += 22;

        // ── Item rows ──
        foreach ($lines as $row) {
            $label  = (string) ($row['label'] ?? '');
            $amount = (string) ($row['amount'] ?? '');
            $pdf->text($mL + 6, $y, $label, 10);
            $pdf->textRight($mR - 6, $y, $amount, 10);
            $y += 16;
        }

        // ── Totals ──
        $y += 4;
        $pdf->line($mL, $y, $mR, $y, 0.6, 0.4);
        $y += 14;
        $taxLabel = $invoice->taxInclusive
            ? sprintf('GST %s%% (incl.)', rtrim(rtrim(number_format($invoice->taxRate, 2, '.', ''), '0'), '.'))
            : sprintf('GST %s%%', rtrim(rtrim(number_format($invoice->taxRate, 2, '.', ''), '0'), '.'));
        $net = $invoice->subtotal->toMajor() - $invoice->discount->toMajor() - ($invoice->taxInclusive ? $invoice->tax->toMajor() : 0.0);

        $pdf->text($mR - 220, $y, 'Subtotal (excl. GST)', 9);
        $pdf->textRight($mR - 6, $y, $money($net), 9);
        $y += 14;
        $pdf->text($mR - 220, $y, $taxLabel, 9);
        $pdf->textRight($mR - 6, $y, $money($invoice->tax->toMajor()), 9);
        $y += 6;
        $pdf->line($mR - 220, $y + 6, $mR, $y + 6, 0.6, 0.4);
        $y += 20;
        $pdf->text($mR - 220, $y, 'TOTAL', 12, true);
        $pdf->textRight($mR - 6, $y, $cur . ' ' . $money($invoice->total->toMajor()), 12, true);

        // ── Footer ──
        $footer = (string) ($opts['footer'] ?? 'Thank you. This is a computer-generated tax invoice.');
        $pdf->line($mL, $pdf->height() - 56, $mR, $pdf->height() - 56, 0.5, 0.6);
        $pdf->text($mL, $pdf->height() - 44, $footer, 8);

        return $pdf->output();
    }
}
