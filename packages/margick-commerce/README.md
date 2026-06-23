# margick/commerce

Reusable, **industry-agnostic** commerce domain. Pure PHP domain + thin WP adapters. No side-effects on include.

## Layout
```
src/
  Domain/
    Money.php                  value object (integer minor units + per-currency scale; VND/JPY = 0 decimals)
    Discount/
      DiscountLine.php         one applied discount {key,label,pct,amount}
      QuoteRequest.php         input: base, headline, loyalty[], voucher policy, cap, GST
      QuoteResult.php          output: rows[] + applied[] + subtotal/total/net/gst (charge === display)
      DiscountEngine.php       PURE: headline → loyalty cap + voucher policy → GST. BR-11 conflict rule.
  Voucher/Domain/
    Voucher.php                immutable code + benefit + restrictions
    VoucherContext.php         charge-authoritative amount/item/customer context
    VoucherValidator.php       PURE eligibility + effect evaluator
    VoucherDecision.php        accepted discount or stable rejection reason
  Contracts/
    DiscountRulesProvider.php  rules() source
    PricingResolver.php        SEAM (industry implements later)
    FulfillmentHandler.php     SEAM (industry implements later)
    PaymentGateway.php         SEAM (Stripe extracted later; webhook-only confirm)
  Wp/
    CoreSchema.php              generic orders + polymorphic order items
    OrderRepository.php         guarded access to core order tables
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
Both `RESERVED` and `CONSUMED` count toward global/per-customer limits, so two
concurrent checkouts cannot both take the final use. An unpaid hold releases its
reservation; a consumed redemption remains counted after cancellation/refund.

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

## Versioning
`VERSION` + `composer.json` version. Schema = public API: additive only, never break.

## Test

Pure PHP:

```bash
php tests/DiscountEngineTest.php
php tests/StripeGatewayTest.php
php tests/WebhookSignatureTest.php
php tests/VoucherValidatorTest.php
```

WordPress DB integration (temporary records are cleaned up):

```bash
wp eval-file tests/VoucherRepositoryWpTest.php
```
