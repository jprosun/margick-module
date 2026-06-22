<?php
/**
 * WpDiscountRulesProvider — WP ADAPTER (the only WordPress-aware part of Discount).
 * Reads the agency-editable `mgk_discount_rules` option and merges over defaults,
 * exactly like the edu mgk_discount_rules(). Domain code never touches wp_options.
 */

declare(strict_types=1);

namespace Margick\Commerce\Wp;

use Margick\Commerce\Contracts\DiscountRulesProvider;

final class WpDiscountRulesProvider implements DiscountRulesProvider
{
    /** @param array<string,int|bool> $defaults */
    public function __construct(
        private readonly string $optionKey = 'mgk_discount_rules',
        private readonly array $defaults = []
    ) {}

    public function rules(): array
    {
        $saved = \function_exists('get_option') ? \get_option($this->optionKey, []) : [];
        if (! \is_array($saved)) {
            $saved = [];
        }
        $rules = \array_merge($this->defaults, $saved);

        return \function_exists('apply_filters') ? \apply_filters('mgk_discount_rules', $rules) : $rules;
    }
}
