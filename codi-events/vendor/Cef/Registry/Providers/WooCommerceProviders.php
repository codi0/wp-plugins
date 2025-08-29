<?php
// File: vendor/Cef/Registry/Providers/WooCommerceProviders.php

namespace Cef\Registry\Providers;

use Cef\Registry\ConditionRegistry;

defined('ABSPATH') || exit;

class WooCommerceProviders
{
    public static function register_all(ConditionRegistry $registry): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Cart item count
        $registry->register([
            'key'        => 'wc_cart_item_count',
            'label'      => __('WC: Cart Item Count', 'cef'),
            'value_type' => 'number',
            'operators'  => ['>', '>=', '=', '<=', '<'],
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                $count = 0;
                if (!empty($context['wc_persistent_cart']['cart']) && is_array($context['wc_persistent_cart']['cart'])) {
                    $count = count($context['wc_persistent_cart']['cart']);
                }
                return self::compare((float)$count, $operator, (float)$value);
            },
        ]);

        // Cart total (with fallback to line_subtotal)
        $registry->register([
            'key'        => 'wc_cart_total',
            'label'      => __('WC: Cart Total', 'cef'),
            'value_type' => 'number',
            'operators'  => ['>', '>=', '=', '<=', '<'],
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                $total = 0.0;
                if (!empty($context['wc_persistent_cart']['cart']) && is_array($context['wc_persistent_cart']['cart'])) {
                    foreach ($context['wc_persistent_cart']['cart'] as $item) {
                        if (isset($item['line_total'])) {
                            $total += (float)$item['line_total'];
                        } elseif (isset($item['line_subtotal'])) {
                            $total += (float)$item['line_subtotal'];
                        }
                    }
                }
                return self::compare((float)$total, $operator, (float)$value);
            },
        ]);

        // Has purchased since trigger (paid statuses only)
        $registry->register([
            'key'        => 'wc_has_purchased_since_trigger',
            'label'      => __('WC: Has Purchased Since Trigger', 'cef'),
            'value_type' => 'none',
            'operators'  => ['=', '!='], // '=' means "has purchased", '!=' means "has not purchased"
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                if (empty($context['user_id']) || empty($context['triggered_at_ts'])) {
                    return false;
                }
                $orders = wc_get_orders([
                    'customer_id'  => (int)$context['user_id'],
                    'date_created' => '>' . gmdate('Y-m-d H:i:s', (int)$context['triggered_at_ts']),
                    'status'       => ['processing', 'completed'],
                    'limit'        => 1,
                    'return'       => 'ids',
                ]);
                $has_purchased = !empty($orders);
                return $operator === '=' ? $has_purchased : !$has_purchased;
            },
        ]);
    }

    protected static function compare(float $left, string $operator, float $right): bool
    {
        switch ($operator) {
            case '>':  return $left > $right;
            case '>=': return $left >= $right;
            case '=':  return $left == $right;
            case '<=': return $left <= $right;
            case '<':  return $left < $right;
            default:   return false;
        }
    }
}