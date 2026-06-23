<?php
/**
 * DiscountEngine — generic stacking + cap + GST (PURE DOMAIN, no WordPress).
 * =========================================================================
 * Faithful port of the COMBINATION logic in the edu mgk_quote():
 *   headline (advertised, uncapped) → loyalty under the global cap, plus a
 *   voucher under its campaign policy → GST breakout. The voucher-vs-loyalty
 *   "best for the customer" rule (BR-11) is preserved.
 *
 * What is NOT here (stays in the industry module, fed in via QuoteRequest):
 *   - which item_types exist (trial / package / variant / service)
 *   - eligibility (sibling / returning / tutor rate, etc.)
 *   - voucher validation against the CPT
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain\Discount;

use Margick\Commerce\Domain\Money;

final class DiscountEngine
{
    public function quote(QuoteRequest $req): QuoteResult
    {
        $currency = $req->base->currency();
        $rows     = [];
        $applied  = [];

        $rows[] = ['label' => $req->lineLabel, 'value' => $req->base->format(), 'discount' => false];

        // Headline = advertised discount. It defines the price floor and is NOT capped.
        $advertised = $req->base;
        if ($req->headline !== null && ! $req->headline->amount->isZero()) {
            $rows[]     = ['label' => $req->headline->label, 'value' => '-' . $req->headline->amount->format(), 'discount' => true];
            $applied[]  = $req->headline;
            $advertised = $req->base->sub($req->headline->amount);
        }

        // Loyalty uses the global cap; each voucher explicitly opts in or out of it.
        $chosen     = $this->chooseExtras($req);
        $capAmount  = $advertised->percentage($req->capPct);
        $running    = $advertised;
        $stackTaken = Money::zero($currency);
        $capped     = false;

        foreach ($chosen as $d) {
            $amt = $d->amount;
            $isVoucher = $req->voucher !== null && $d === $req->voucher;
            $usesGlobalCap = ! $isVoucher || $req->voucherCapped;

            if ($usesGlobalCap) {
                $remainingCap = $capAmount->sub($stackTaken);
                if ($amt->greaterThan($remainingCap)) {
                    $amt    = $remainingCap;
                    $capped = true;
                }
            }
            // Independent discounts can never reduce the charge below zero.
            if ($amt->greaterThan($running)) {
                $amt = Money::ofMinor($running->minor(), $currency);
            }
            if ($amt->isZero()) {
                continue;
            }
            if ($usesGlobalCap) {
                $stackTaken = $stackTaken->add($amt);
            }
            $running    = $running->sub($amt);
            $line       = new DiscountLine($d->key, $d->label, $d->pct, $amt);
            $applied[]  = $line;
            $rows[]     = ['label' => $line->labelWithPct(), 'value' => '-' . $amt->format(), 'discount' => true];
        }

        $subtotal              = $running;
        [$net, $gst, $total]   = $this->applyGst($subtotal, $req->gstPct, $req->gstInclusive);

        return new QuoteResult(
            $rows, $applied, $req->base, $advertised, $subtotal,
            $total, $net, $gst, $capped, $req->gstPct, $req->gstInclusive
        );
    }

    /**
     * Non-stackable voucher (BR-11) cannot combine with loyalty → apply whichever
     * side saves MORE (best-for-customer). Otherwise voucher stacks on top of loyalty.
     *
     * @return DiscountLine[]
     */
    private function chooseExtras(QuoteRequest $req): array
    {
        $loyalty = $req->loyalty;
        $voucher = $req->voucher;

        if ($voucher !== null && ! $req->voucherStackable && $loyalty) {
            // Best-for-customer: loyalty wins ONLY if STRICTLY larger; on a tie the
            // voucher wins (matches the edu mgk_quote semantics).
            $loyaltyTotal = array_reduce($loyalty, static fn (int $c, DiscountLine $l) => $c + $l->amount->minor(), 0);
            return $loyaltyTotal > $voucher->amount->minor() ? $loyalty : [$voucher];
        }

        // An independent campaign gets its configured value first. This keeps a
        // 100% voucher visibly 100%; later loyalty lines stop when the order is zero.
        if ($voucher !== null && $req->voucherStackable && ! $req->voucherCapped) {
            return \array_merge([$voucher], $loyalty);
        }

        $chosen = $loyalty;
        if ($voucher !== null) {
            $chosen[] = $voucher;
        }
        return $chosen;
    }

    /**
     * GST breakout (BR-04). Inclusive: discounted line already contains tax → extract.
     * Exclusive: add on top → charged total grows.
     *
     * @return array{0:Money,1:Money,2:Money} [net, gst, total]
     */
    private function applyGst(Money $subtotal, int $gstPct, bool $inclusive): array
    {
        if ($gstPct <= 0) {
            return [$subtotal, Money::zero($subtotal->currency()), $subtotal];
        }
        if ($inclusive) {
            $total = $subtotal;
            $net   = Money::ofMajor($total->toMajor() / (1 + $gstPct / 100), $total->currency());
            $gst   = $total->sub($net);
        } else {
            $net   = $subtotal;
            $gst   = $net->percentage($gstPct);
            $total = $net->add($gst);
        }
        return [$net, $gst, $total];
    }
}
