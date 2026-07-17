<?php

defined('ABSPATH') || exit;

final class CES_WooCommerce_Events {
    public static function init(): void {
        if (did_action('plugins_loaded')) {
            self::register_items();
            return;
        }

        add_action('plugins_loaded', [__CLASS__, 'register_items'], 15);
    }

    public static function register_items(): void {
        if (!function_exists('ces_register_event') || !function_exists('ces_register_condition') || !CES_WooCommerce_Helpers::is_woocommerce_dependency_active()) {
            return;
        }

        self::register_events();
        self::register_conditions();
    }

    public static function context_from_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data): ?array {
        $context = CES_WooCommerce_Helpers::customer_context();
        $context['product_id'] = absint($product_id);
        $context['variation_id'] = absint($variation_id);
        $context['quantity'] = absint($quantity);
        $context['cart_item_key'] = sanitize_text_field((string) $cart_item_key);
        $context['cart_hash'] = CES_WooCommerce_Helpers::cart_hash();

        if (empty($context['user_id']) && empty($context['email'])) {
            return null;
        }

        $context['event_instance_id'] = CES_Event_Identity::occurrence('wc_cart_item_added', [
            CES_WooCommerce_Helpers::customer_instance_key($context),
            $context['product_id'],
            $context['variation_id'],
            $context['cart_item_key'],
        ]);
        return $context;
    }

    public static function context_from_checkout_viewed($checkout = null): ?array {
        $context = CES_WooCommerce_Helpers::customer_context();
        $context['cart_hash'] = CES_WooCommerce_Helpers::cart_hash();
        $context['checkout_url'] = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';

        if (empty($context['user_id']) && empty($context['email'])) {
            return null;
        }

        $context['event_instance_id'] = CES_Event_Identity::occurrence('wc_checkout_viewed', [
            CES_WooCommerce_Helpers::customer_instance_key($context),
            $context['cart_hash'],
        ]);
        return $context;
    }

    public static function context_from_checkout_order_processed(int $order_id, array $posted_data = [], $order = null): ?array {
        if (!$order instanceof WC_Order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) {
            return null;
        }

        return CES_WooCommerce_Helpers::order_context($order, [
            'event_instance_id' => 'wc_order_created:' . $order->get_id(),
        ]);
    }

    public static function context_from_store_api_checkout_order_processed($order): ?array {
        if (!$order instanceof WC_Order) {
            return null;
        }

        return CES_WooCommerce_Helpers::order_context($order, [
            'event_instance_id' => 'wc_order_created:' . $order->get_id(),
        ]);
    }

    public static function context_from_payment_complete(int $order_id): ?array {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }

        return CES_WooCommerce_Helpers::order_context($order, [
            'event_instance_id' => 'wc_payment_completed:' . $order->get_id(),
        ]);
    }

    public static function context_from_order_status_changed(int $order_id, string $from, string $to, $order): ?array {
        if (!$order instanceof WC_Order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) {
            return null;
        }

        return CES_WooCommerce_Helpers::order_context($order, [
            'order_previous_status' => sanitize_key($from),
            'event_instance_id'     => CES_Event_Identity::occurrence('wc_order_status_changed', [$order->get_id(), sanitize_key($from), sanitize_key($to)]),
        ]);
    }

    private static function register_events(): void {
        ces_register_event('wc_cart_item_added', [
            'label'        => __('WooCommerce cart item added', 'codi-email-scheduler'),
            'description'  => __('Fires when a product is added to the cart. Usually only useful when the user or billing email is known.', 'codi-email-scheduler'),
            'context_keys' => ['user_id', 'email', 'user_login', 'product_id', 'variation_id', 'quantity', 'cart_hash', 'cart_item_key', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['wp_user_has_email', 'wc_cart_has_items', 'wc_cart_still_contains_added_item', 'wc_customer_has_paid_order_since_event', 'wc_customer_has_ordered_added_item_since_event', 'always'],
            'triggers'     => [[
                'key'              => 'woocommerce_add_to_cart',
                'hook'             => 'woocommerce_add_to_cart',
                'priority'         => 20,
                'accepted_args'    => 6,
                'context_callback' => [__CLASS__, 'context_from_add_to_cart'],
            ]],
        ]);

        ces_register_event('wc_checkout_viewed', [
            'label'        => __('WooCommerce checkout viewed', 'codi-email-scheduler'),
            'description'  => __('Fires when the checkout form is displayed. Usually only useful when the user or billing email is known.', 'codi-email-scheduler'),
            'context_keys' => ['user_id', 'email', 'user_login', 'cart_hash', 'checkout_url', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['wp_user_has_email', 'wc_cart_has_items', 'wc_customer_has_paid_order_since_event', 'always'],
            'triggers'     => [
                [
                    'key'              => 'woocommerce_before_checkout_form',
                    'hook'             => 'woocommerce_before_checkout_form',
                    'priority'         => 20,
                    'accepted_args'    => 1,
                    'context_callback' => [__CLASS__, 'context_from_checkout_viewed'],
                ],
                [
                    'key'              => 'woocommerce_blocks_enqueue_checkout_block_scripts_before',
                    'hook'             => 'woocommerce_blocks_enqueue_checkout_block_scripts_before',
                    'priority'         => 20,
                    'accepted_args'    => 0,
                    'context_callback' => [__CLASS__, 'context_from_checkout_viewed'],
                ],
            ],
        ]);

        ces_register_event('wc_order_created', [
            'label'                   => __('WooCommerce order created', 'codi-email-scheduler'),
            'description'             => __('Fires when checkout creates an order before payment is necessarily complete.', 'codi-email-scheduler'),
            'context_keys'            => ['user_id', 'email', 'user_login', 'order_id', 'order_status', 'order_total', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'              => ['wc_order_exists', 'wc_order_is_paid', 'wc_customer_has_paid_order', 'always'],
            'trigger_dedupe_callback' => static function (array $context): string {
                return (string) absint($context['order_id'] ?? 0);
            },
            'triggers'                => [
                [
                    'key'              => 'woocommerce_checkout_order_processed',
                    'hook'             => 'woocommerce_checkout_order_processed',
                    'priority'         => 20,
                    'accepted_args'    => 3,
                    'context_callback' => [__CLASS__, 'context_from_checkout_order_processed'],
                ],
                [
                    'key'              => 'woocommerce_store_api_checkout_order_processed',
                    'hook'             => 'woocommerce_store_api_checkout_order_processed',
                    'priority'         => 20,
                    'accepted_args'    => 1,
                    'context_callback' => [__CLASS__, 'context_from_store_api_checkout_order_processed'],
                ],
            ],
        ]);

        ces_register_event('wc_payment_completed', [
            'label'        => __('WooCommerce payment completed', 'codi-email-scheduler'),
            'description'  => __('Fires when WooCommerce marks an order payment as complete.', 'codi-email-scheduler'),
            'context_keys' => ['user_id', 'email', 'user_login', 'order_id', 'order_status', 'order_total', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['wc_order_exists', 'wc_order_is_paid', 'wc_customer_has_paid_order', 'always'],
            'triggers'     => [[
                'key'              => 'woocommerce_payment_complete',
                'hook'             => 'woocommerce_payment_complete',
                'priority'         => 20,
                'accepted_args'    => 1,
                'context_callback' => [__CLASS__, 'context_from_payment_complete'],
            ]],
        ]);

        ces_register_event('wc_order_status_changed', [
            'label'        => __('WooCommerce order status changed', 'codi-email-scheduler'),
            'description'  => __('Fires when a WooCommerce order changes status.', 'codi-email-scheduler'),
            'context_keys' => ['user_id', 'email', 'user_login', 'order_id', 'order_status', 'order_previous_status', 'order_total', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['wc_order_exists', 'wc_order_is_paid', 'wc_customer_has_paid_order', 'always'],
            'triggers'     => [[
                'key'              => 'woocommerce_order_status_changed',
                'hook'             => 'woocommerce_order_status_changed',
                'priority'         => 20,
                'accepted_args'    => 4,
                'context_callback' => [__CLASS__, 'context_from_order_status_changed'],
            ]],
        ]);
    }

    private static function register_conditions(): void {
        ces_register_condition('wc_order_exists', [
            'label'       => __('WooCommerce order exists', 'codi-email-scheduler'),
            'description' => __('Passes when the context contains a valid WooCommerce order ID.', 'codi-email-scheduler'),
            'requires'    => ['order_id'],
            'callback'    => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('condition');
                }
                return CES_WooCommerce_Helpers::order_from_context($context) instanceof WC_Order;
            },
        ]);

        ces_register_condition('wc_order_is_paid', [
            'label'       => __('WooCommerce order is paid', 'codi-email-scheduler'),
            'description' => __('Passes when the context order is marked paid.', 'codi-email-scheduler'),
            'requires'    => ['order_id'],
            'callback'    => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('condition');
                }
                $order = CES_WooCommerce_Helpers::order_from_context($context);
                return $order instanceof WC_Order && $order->is_paid();
            },
        ]);

        ces_register_condition('wc_customer_has_paid_order', [
            'label'        => __('WooCommerce customer has paid order', 'codi-email-scheduler'),
            'description'  => __('Passes when the context user or email has at least one paid WooCommerce order.', 'codi-email-scheduler'),
            'requires_any' => ['user_id', 'email'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('condition');
                }
                return CES_WooCommerce_Helpers::customer_has_paid_order($context);
            },
        ]);

        ces_register_condition('wc_cart_has_items', [
            'label'       => __('WooCommerce cart has items', 'codi-email-scheduler'),
            'description' => __('Passes when the context user has a non-empty WooCommerce persistent cart.', 'codi-email-scheduler'),
            'requires'    => ['user_id'],
            'callback'    => static function (array $context): bool {
                return CES_WooCommerce_Helpers::persistent_cart_has_items($context);
            },
        ]);

        ces_register_condition('wc_cart_still_contains_added_item', [
            'label'       => __('WooCommerce cart still contains added item', 'codi-email-scheduler'),
            'description' => __('Passes when the product from the original add-to-cart event is still present in the user persistent cart.', 'codi-email-scheduler'),
            'requires'    => ['user_id', 'product_id'],
            'callback'    => static function (array $context): bool {
                return CES_WooCommerce_Helpers::persistent_cart_contains_context_product($context);
            },
        ]);

        ces_register_condition('wc_customer_has_paid_order_since_event', [
            'label'        => __('WooCommerce customer has paid order since event', 'codi-email-scheduler'),
            'description'  => __('Passes when the context user or email has a paid order created at or after the event time.', 'codi-email-scheduler'),
            'requires'     => ['event_timestamp'],
            'requires_any' => ['user_id', 'email'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('condition');
                }
                return !empty(CES_WooCommerce_Helpers::paid_orders_since_event($context, 1));
            },
        ]);

        ces_register_condition('wc_customer_has_ordered_added_item_since_event', [
            'label'        => __('WooCommerce customer ordered added item since event', 'codi-email-scheduler'),
            'description'  => __('Passes when the customer has a paid order since the event containing the product from the original add-to-cart event.', 'codi-email-scheduler'),
            'requires'     => ['event_timestamp', 'product_id'],
            'requires_any' => ['user_id', 'email'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('condition');
                }
                return CES_WooCommerce_Helpers::customer_ordered_context_product_since_event($context);
            },
        ]);
    }
}
