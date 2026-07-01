# margick/commerce

Reusable, **industry-agnostic** commerce domain. Pure PHP domain + thin WP adapters. No side-effects on include.

## Layout
```
src/
  Domain/
    Money.php                  value object (integer minor units + per-currency scale; VND/JPY = 0 decimals)
    Discount/
      DiscountLine.php         one applied discount {key,label,pct,amount}
      VoucherLine.php          stacked voucher discount input
      QuoteRequest.php         input: base, headline, loyalty[], voucher policy, cap, GST
      QuoteResult.php          output: rows[] + applied[] + subtotal/total/net/gst (charge === display)
      DiscountEngine.php       PURE: headline → loyalty cap + voucher policy → GST. BR-11 conflict rule.
    Invoice/
      Invoice.php              tax-invoice value object w/ gapless number
      InvoicePdf.php           zero-dependency one-page tax invoice renderer
  Voucher/Domain/
    Voucher.php                immutable code + benefit + restrictions
    VoucherContext.php         charge-authoritative amount/item/customer context
    VoucherValidator.php       PURE eligibility + effect evaluator
    VoucherDecision.php        accepted discount or stable rejection reason
  Domain/
    Refund/
      RefundOutcome.php        policy result {amount,pct,tier,basis} (how much is refundable)
      RefundResult.php         gateway execution result {ok,refunded,full,mode,refundId}
    CreditNote/
      CreditNote.php           negative-invoice value object w/ a gapless number
  Contracts/
    DiscountRulesProvider.php  rules() source
    RefundPolicy.php           SEAM: HOW MUCH is refundable (template: edu BR-07 tiers / pro-rata)
    PricingResolver.php        SEAM (industry implements later)
    FulfillmentHandler.php     SEAM (industry implements later)
    PaymentGateway.php         SEAM (Stripe extracted later; webhook-only confirm)
  Payment/Stripe/
    StripeGateway.php           PURE wire format: checkout + REFUND params + mock + event parse
  Pdf/
    PdfDocument.php             minimal zero-dependency PDF writer
  Wp/
    CoreSchema.php              generic orders + order items + refunds + credit_notes + invoices + sequences
    OrderRepository.php         guarded access to core order tables
    InvoiceRepository.php       idempotent invoice issuance + gapless number allocation
    RefundRepository.php        idempotent refund recording + gapless credit-note issuance
    SequenceNumberAllocator.php GAPLESS, race-safe counters (invoice/credit-note no)
    BookingSchema.php           lifted booking crosscut schema owner
    BookingRepository.php       guarded read access to booking tables
    VoucherSchema.php           vouchers + reservation/redemption ledger
    VoucherRepository.php       atomic reserve/replace/consume/release lifecycle
    SchemaMigrator.php          version-gated additive dbDelta runner
    WpDiscountRulesProvider.php reads option `mgk_discount_rules`
  bootstrap.php                explicit wiring: migrations + voucher cleanup cron
```

## What it is / isn't
- **IS** the generic *combination* math (stacking, automatic-discount cap, GST,
  voucher-vs-loyalty and explicit per-voucher cap policy).
- **IS** the voucher lifecycle: code normalization, percent/fixed benefit, item scope,
  date/minimum/customer/usage restrictions and concurrency-safe redemption.
- **IS NOT** edu eligibility (sibling/returning), item_types (trial/package), tutor rates — those stay in the edu module and are *fed in* via `QuoteRequest`. That separation is why this package is reusable across industries.

## Voucher lifecycle (v0.5)

The custom tables are owned by this package, never by a template:

- `{prefix}mgk_core_vouchers` — one normalized customer-facing code and its generic rules.
- `{prefix}mgk_core_voucher_redemptions` — immutable snapshot + lifecycle ledger.

```text
RESERVED -> CONSUMED
         -> RELEASED
         -> EXPIRED
```

`VoucherRepository::reserve()` locks the voucher row in an InnoDB transaction.
By default, only `CONSUMED` rows count toward global/per-customer limits: a
voucher is "used" when payment succeeds, not when a checkout hold is created.
For scarce campaigns, `VoucherRepository::setUsagePolicy('active')` also counts
`RESERVED` holds. An unpaid hold releases its reservation; a consumed redemption
remains counted after cancellation/refund.

Industry/template adapters provide exact `item_type` values and customer context.
They do not query or mutate the voucher tables directly.

### Discount cap policy

The global `capPct` limits automatic/loyalty discounts. A voucher is governed by
its own campaign configuration: `maxDiscountMinor` limits its monetary benefit,
and `respectGlobalCap=true` explicitly makes it share the global cap. The latter
defaults to `false`, so a configured 100% voucher is not silently reduced to the
remaining loyalty headroom. Every discount is still clamped at the remaining
order value, so totals cannot become negative.

## Usage (industry feeds candidates, engine combines)
```php
use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Discount\{DiscountEngine, DiscountLine, QuoteRequest};

$result = (new DiscountEngine())->quote(new QuoteRequest(
    base:     Money::ofMajor(65, 'SGD'),
    headline: new DiscountLine('headline:trial', 'Trial discount (40%)', 40, Money::ofMajor(25, 'SGD')),
    loyalty:  [ new DiscountLine('sibling', 'Sibling discount', 3, Money::ofMajor(1.20, 'SGD')) ],
    capPct: 25, gstPct: 9, gstInclusive: true, lineLabel: 'Trial lesson'
));
$result->total->toMajor();       // 38.80  ← exact charge
$result->appliedToArray();        // persist on order.discount_applied (frozen)
```

## Refund, Credit Note & Invoice (v0.8.0)

Generic refund **mechanics** live here; the refund **policy** (how much) stays in the
template behind `RefundPolicy` — same split as `DiscountEngine` ↔ `DiscountRulesProvider`.

- `StripeGateway::refundParams()` / `mockRefund()` / `refundIdempotencyKey()` — PURE wire
  format (lifted 1:1 from edu `booking-payment-stripe.php`; mock id is byte-identical).
- `RefundRepository::recordRefund()` — append to `mgk_core_refunds`; **idempotent** by
  `idempotency_key` (a retried refund of the same amount records once, never double-refunds).
- `RefundRepository::issueCreditNote()` — a negative-invoice row in `mgk_core_credit_notes`
  with a **gapless** number `CN-<YYYY>-<NNNNNN>`.
- `InvoiceRepository::issueForOrder()` — one tax invoice per paid order with a
  **gapless** number `INV-<YYYY>-<NNNNNN>` and frozen seller/buyer/tax snapshots.
- `InvoicePdf::render()` — produces a single-page A4 tax invoice PDF without
  external dependencies.
- `SequenceNumberAllocator::next()` — gapless, race-safe counter via the MySQL
  `LAST_INSERT_ID(expr)` idiom (connection-local read; no gap, no SELECT-race). *Validated:
  50 concurrent connections → 1..50, zero gaps, zero dups (MySQL 8.0.46).*

What stays in the template (edu): the BR-07 notice tiers (≥48h full / 24–48h 50% / <24h 0%
/ no-show) + package pro-rata, implemented as a `RefundPolicy`; the gateway HTTP transport
(`wp_remote_post` + keys); booking status transitions; the S12/S13/S26 UI.

## Versioning
`VERSION` + `composer.json` version. Schema = public API: additive only, never break.
Schema capability version is its OWN axis: `CoreSchema::SCHEMA_VERSION` is now `1.2.0`
(added refunds/credit_notes/sequences/invoices) — an already-shipped site picks these up
on the next boot via the version-gated `SchemaMigrator`.

## Test

Pure PHP:

```bash
php tests/DiscountEngineTest.php
php tests/StripeGatewayTest.php
php tests/WebhookSignatureTest.php
php tests/VoucherValidatorTest.php
php tests/MultiVoucherStackTest.php
php tests/RefundMechanicsTest.php   # refund params + mock + value objects (v0.6.0)
php tests/InvoiceVoTest.php
php tests/InvoicePdfTest.php
```

WordPress DB integration (temporary records are cleaned up):

```bash
wp eval-file tests/VoucherRepositoryWpTest.php
wp eval-file tests/RefundRepositoryWpTest.php   # gapless number + idempotent refund (v0.6.0)
wp eval-file tests/InvoiceRepositoryWpTest.php
```
