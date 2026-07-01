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

        // ── Stacked vouchers (multi-voucher path) ─────────────────────────────
        // Each voucher is evaluated on the RUNNING balance in order — a percent
        // voucher takes its cut of what's left after earlier vouchers, a fixed
        // voucher subtracts its amount, and every deduction is clamped so the
        // charge can never go below zero. Vouchers are NOT bound by the loyalty
        // global cap (that governs automatic/business-rule discounts only); the
        // per-voucher maxDiscount is their ceiling. Loyalty above is untouched.
        foreach ($this->selectVouchers($req->vouchers) as $v) {
            $amt = $v->deductionOn($running);
            if ($amt->isZero()) {
                continue;
            }
            $running   = $running->sub($amt);
            $line      = new DiscountLine('voucher:' . $v->code, $v->label, $v->displayPct(), $amt);
            $applied[] = $line;
            $rows[]    = ['label' => $line->labelWithPct(), 'value' => '-' . $amt->format(), 'discount' => true];
            if ($running->isZero()) {
                break; // nothing left to discount — later vouchers are no-ops
            }
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
     * Filter the voucher stack down to the set that may actually apply, in order:
     *   - de-duplicate by code (a code applies at most once per order),
     *   - honour a standalone voucher (if any standalone is present, ONLY the first
     *     one applies — it cannot be combined with any other voucher),
     *   - at most one voucher per non-empty stackGroup (first wins),
     *   - stable-sort by priority (lower first), preserving input order on ties.
     * Validation/eligibility is the industry's job (done before building the list);
     * this is purely the generic "which of these may stack together" gate.
     *
     * @param  VoucherLine[] $vouchers
     * @return VoucherLine[]
     */
    private function selectVouchers(array $vouchers): array
    {
        if (! $vouchers) {
            return [];
        }

        // Stable sort by priority (usort is not stable pre-8.0; we run on 8.x, but
        // guard order explicitly with an index tiebreak for determinism).
        $indexed = [];
        foreach ($vouchers as $i => $v) {
            $indexed[] = [$i, $v];
        }
        usort($indexed, static function (array $a, array $b): int {
            return ($a[1]->priority <=> $b[1]->priority) ?: ($a[0] <=> $b[0]);
        });

        $seenCodes  = [];
        $seenGroups = [];
        $selected   = [];
        foreach ($indexed as [, $v]) {
            $code = strtoupper($v->code);
            if (isset($seenCodes[$code])) {
                continue; // same code twice → apply once
            }
            $group = $v->stackGroup;
            if ($group !== '' && isset($seenGroups[$group])) {
                continue; // one voucher per group
            }
            $seenCodes[$code] = true;
            if ($group !== '') {
                $seenGroups[$group] = true;
            }
            $selected[] = $v;
        }

        // A standalone voucher cannot be combined with any other → keep only the
        // first standalone (highest priority after the sort) and drop the rest.
        foreach ($selected as $v) {
            if ($v->standalone) {
                return [$v];
            }
        }

        return $selected;
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
