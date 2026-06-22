<?php

declare(strict_types=1);

namespace Margick\Commerce\Voucher\Domain;

/** Pure eligibility/effect evaluator. Persistence supplies the atomic usage counts. */
final class VoucherValidator
{
    public function evaluate(
        Voucher $voucher,
        VoucherContext $context,
        int $activeUsage = 0,
        int $customerActiveUsage = 0
    ): VoucherDecision {
        if ($voucher->status !== Voucher::STATUS_ACTIVE) {
            return VoucherDecision::reject('inactive', 'This voucher is inactive.', $voucher);
        }
        if ($voucher->startsAt !== null && $context->now < $voucher->startsAt) {
            return VoucherDecision::reject('not_started', 'This voucher is not active yet.', $voucher);
        }
        if ($voucher->endsAt !== null && $context->now >= $voucher->endsAt) {
            return VoucherDecision::reject('expired', 'This voucher has expired.', $voucher);
        }
        if ($voucher->usageLimit !== null && $activeUsage >= $voucher->usageLimit) {
            return VoucherDecision::reject('usage_limit', 'This voucher has reached its usage limit.', $voucher);
        }

        $customerKey = Voucher::normalizeCustomerKey($context->customerKey);
        $restrictedCustomer = Voucher::normalizeCustomerKey($voucher->customerKey);
        if ($restrictedCustomer !== null && $restrictedCustomer !== $customerKey) {
            return VoucherDecision::reject('customer_restricted', 'This voucher is not assigned to this customer.', $voucher);
        }
        if ($voucher->usageLimitPerCustomer !== null) {
            if ($customerKey === null) {
                return VoucherDecision::reject('customer_required', 'Sign in or provide an email to use this voucher.', $voucher);
            }
            if ($customerActiveUsage >= $voucher->usageLimitPerCustomer) {
                return VoucherDecision::reject('customer_usage_limit', 'This customer has already used this voucher.', $voucher);
            }
        }
        if ($voucher->firstOrderOnly && ! $context->firstOrder) {
            return VoucherDecision::reject('first_order_only', 'This voucher is for first orders only.', $voucher);
        }

        if ($voucher->currency !== null && \strtoupper($voucher->currency) !== $context->eligibleAmount->currency()) {
            return VoucherDecision::reject('currency_mismatch', 'This voucher is not valid for this currency.', $voucher);
        }
        if ($voucher->minOrderMinor > $context->eligibleAmount->minor()) {
            return VoucherDecision::reject('minimum_order', 'The order does not meet this voucher minimum.', $voucher);
        }

        if ($voucher->appliesTo !== []) {
            $eligibleTypes = \array_values(\array_intersect($voucher->appliesTo, $context->itemTypes));
            if ($eligibleTypes === []) {
                return VoucherDecision::reject('not_applicable', 'This voucher does not apply to these items.', $voucher);
            }
        }

        if ($voucher->discountType === Voucher::TYPE_PERCENT
            && ($voucher->percentageBps <= 0 || $voucher->percentageBps > 10_000)) {
            return VoucherDecision::reject('misconfigured', 'This voucher is misconfigured.', $voucher);
        }
        if ($voucher->discountType === Voucher::TYPE_FIXED && $voucher->fixedAmountMinor <= 0) {
            return VoucherDecision::reject('misconfigured', 'This voucher is misconfigured.', $voucher);
        }

        $discount = $voucher->discountFor($context->eligibleAmount);
        if ($discount->isZero()) {
            return VoucherDecision::reject('zero_discount', 'This voucher has no value for this order.', $voucher);
        }
        return VoucherDecision::accept($voucher, $discount);
    }
}
