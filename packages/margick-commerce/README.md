# margick/commerce

Reusable, **industry-agnostic** commerce domain. Pure PHP domain + thin WP adapters. No side-effects on include.

## Layout
```
src/
  Domain/
    Money.php                  value object (integer minor units + per-currency scale; VND/JPY = 0 decimals)
    Discount/
      DiscountLine.php         one applied discount {key,label,pct,amount}
      QuoteRequest.php         input: base, headline, loyalty[], voucher, cap, GST
      QuoteResult.php          output: rows[] + applied[] + subtotal/total/net/gst (charge === display)
      DiscountEngine.php       PURE: headline → stack(loyalty+voucher) → cap → GST. BR-11 conflict rule.
  Contracts/
    DiscountRulesProvider.php  rules() source
    PricingResolver.php        SEAM (industry implements later)
    FulfillmentHandler.php     SEAM (industry implements later)
    PaymentGateway.php         SEAM (Stripe extracted later; webhook-only confirm)
  Wp/
    WpDiscountRulesProvider.php reads option `mgk_discount_rules` (only WP-aware file)
  bootstrap.php                explicit Margick\Commerce\bootstrap() — no-op in v0.1
```

## What it is / isn't
- **IS** the generic *combination* math (stacking, cap, GST, voucher-vs-loyalty).
- **IS NOT** edu eligibility (sibling/returning), item_types (trial/package), tutor rates — those stay in the edu module and are *fed in* via `QuoteRequest`. That separation is why this package is reusable across industries.

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
`php tests/DiscountEngineTest.php`
