<?php
/**
 * DiscountRulesProvider — where the agency-editable discount rules come from.
 * The domain depends on this interface; the WP adapter reads wp_options.
 */

declare(strict_types=1);

namespace Margick\Commerce\Contracts;

interface DiscountRulesProvider
{
    /** @return array<string,int|bool> merged rules (factory defaults + agency overrides). */
    public function rules(): array;
}
