<?php

declare(strict_types=1);

namespace Margick\Commerce\Tests;

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Voucher/Domain/Voucher.php';
require __DIR__ . '/../src/Voucher/Domain/VoucherContext.php';
require __DIR__ . '/../src/Voucher/Domain/VoucherDecision.php';
require __DIR__ . '/../src/Voucher/Domain/VoucherValidator.php';

use Margick\Commerce\Domain\Money;
use Margick\Commerce\Voucher\Domain\Voucher;
use Margick\Commerce\Voucher\Domain\VoucherContext;
use Margick\Commerce\Voucher\Domain\VoucherValidator;

$fail = 0;
$check = static function (string $label, mixed $got, mixed $want) use (&$fail): void {
    $ok = $got === $want;
    printf("[%s] %-27s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
    if (! $ok) {
        $fail++;
    }
};

$at = new \DateTimeImmutable('2026-06-22 12:00:00', new \DateTimeZone('UTC'));
$voucher = static function (array $data = []) use ($at): Voucher {
    $d = array_merge([
        'id' => 1,
        'code' => 'SAVE10',
        'name' => 'Save ten',
        'status' => Voucher::STATUS_ACTIVE,
        'discountType' => Voucher::TYPE_PERCENT,
        'percentageBps' => 1000,
        'fixedAmountMinor' => 0,
        'currency' => null,
        'minOrderMinor' => 0,
        'maxDiscountMinor' => null,
        'stackable' => false,
        'usageLimit' => null,
        'usageLimitPerCustomer' => null,
        'customerKey' => null,
        'firstOrderOnly' => false,
        'appliesTo' => [],
        'startsAt' => $at->modify('-1 day'),
        'endsAt' => $at->modify('+1 day'),
    ], $data);
    return new Voucher(...$d);
};
$context = static function (array $data = []) use ($at): VoucherContext {
    $d = array_merge([
        'eligibleAmount' => Money::ofMinor(4000, 'SGD'),
        'itemTypes' => ['edu_trial'],
        'customerKey' => 'Parent@Example.com',
        'firstOrder' => true,
        'now' => $at,
    ], $data);
    return new VoucherContext(...$d);
};
$validator = new VoucherValidator();

$check('normalize code', Voucher::normalizeCode(" save 10 \n"), 'SAVE10');
$check('normalize customer', Voucher::normalizeCustomerKey(' Parent@Example.COM '), 'parent@example.com');

$accepted = $validator->evaluate($voucher(), $context());
$check('percent.valid', $accepted->valid, true);
$check('percent.amount', $accepted->discount?->minor(), 400);

$fixed = $voucher([
    'discountType' => Voucher::TYPE_FIXED,
    'percentageBps' => 0,
    'fixedAmountMinor' => 5000,
    'currency' => 'SGD',
]);
$check('fixed.clamped', $validator->evaluate($fixed, $context())->discount?->minor(), 4000);
$check('fixed.currency', $validator->evaluate($fixed, $context([
    'eligibleAmount' => Money::ofMinor(100000, 'VND'),
]))->reason, 'currency_mismatch');

$check('not started', $validator->evaluate($voucher([
    'startsAt' => $at->modify('+1 second'),
]), $context())->reason, 'not_started');
$check('end is exclusive', $validator->evaluate($voucher([
    'endsAt' => $at,
]), $context())->reason, 'expired');
$check('minimum order', $validator->evaluate($voucher([
    'currency' => 'SGD',
    'minOrderMinor' => 4001,
]), $context())->reason, 'minimum_order');
$check('item scope', $validator->evaluate($voucher([
    'appliesTo' => ['retail_variant'],
]), $context())->reason, 'not_applicable');
$check('global usage', $validator->evaluate($voucher([
    'usageLimit' => 1,
]), $context(), 1)->reason, 'usage_limit');
$check('customer required', $validator->evaluate($voucher([
    'usageLimitPerCustomer' => 1,
]), $context(['customerKey' => null]))->reason, 'customer_required');
$check('customer usage', $validator->evaluate($voucher([
    'usageLimitPerCustomer' => 1,
]), $context(), 0, 1)->reason, 'customer_usage_limit');
$check('customer restriction', $validator->evaluate($voucher([
    'customerKey' => 'other@example.com',
]), $context())->reason, 'customer_restricted');
$check('first order', $validator->evaluate($voucher([
    'firstOrderOnly' => true,
]), $context(['firstOrder' => false]))->reason, 'first_order_only');
$check('maximum discount', $validator->evaluate($voucher([
    'percentageBps' => 5000,
    'maxDiscountMinor' => 750,
]), $context())->discount?->minor(), 750);
$check('VND percentage', $validator->evaluate($voucher(), $context([
    'eligibleAmount' => Money::ofMinor(300000, 'VND'),
]))->discount?->minor(), 30000);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
