<?php

defined('ABSPATH') || exit;

final class CES_WooCommerce_Tokens {
    public static function init(): void {
        if (did_action('plugins_loaded')) {
            self::register_tokens();
            return;
        }

        add_action('plugins_loaded', [__CLASS__, 'register_tokens'], 15);
    }

    public static function register_tokens(): void {
        if (!class_exists('CES_Registry') || !CES_WooCommerce_Helpers::is_woocommerce_dependency_active()) {
            return;
        }

        ces_register_token('cart_url', [
            'label'        => __('Cart URL', 'codi-email-scheduler'),
            'requires_any' => ['cart_hash', 'product_id'],
            'escape'       => 'url',
            'callback'     => static function () {
                if (!function_exists('wc_get_cart_url')) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                return (string) wc_get_cart_url();
            },
        ]);

        ces_register_token('checkout_url', [
            'label'        => __('Checkout URL', 'codi-email-scheduler'),
            'requires_any' => ['checkout_url', 'cart_hash', 'product_id'],
            'escape'       => 'url',
            'callback'     => static function () {
                if (!function_exists('wc_get_checkout_url')) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                return (string) wc_get_checkout_url();
            },
        ]);

        ces_register_token('product_id', [
            'label'        => __('Product ID', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'escape'       => 'text',
            'callback'     => static function (array $context): string {
                return (string) ($context['product_id'] ?? '');
            },
        ]);

        ces_register_token('product_name', [
            'label'        => __('Product name', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? (string) $product->get_name() : '';
            },
        ]);

        ces_register_token('product_name_trimmed', [
            'label'        => __('Product name (trimmed)', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? self::trim_product_name((string) $product->get_name()) : '';
            },
        ]);

        ces_register_token('product_sku', [
            'label'        => __('Product SKU', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'escape'       => 'text',
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? (string) $product->get_sku() : '';
            },
        ]);

        ces_register_token('product_type', [
            'label'        => __('Product type', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'escape'       => 'text',
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? (string) $product->get_type() : '';
            },
        ]);

        ces_register_token('product_categories', [
            'label'        => __('Product categories', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? implode(', ', CES_WooCommerce_Helpers::product_term_names($product, 'product_cat')) : '';
            },
        ]);

        ces_register_token('product_tags', [
            'label'        => __('Product tags', 'codi-email-scheduler'),
            'requires_any' => ['product_id', 'variation_id'],
            'callback'     => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $product = CES_WooCommerce_Helpers::product_from_context($context);
                return $product ? implode(', ', CES_WooCommerce_Helpers::product_term_names($product, 'product_tag')) : '';
            },
        ]);

        ces_register_token('order_id', [
            'label'    => __('Order ID', 'codi-email-scheduler'),
            'requires' => ['order_id'],
            'escape'   => 'text',
        ]);

        ces_register_token('order_number', [
            'label'    => __('Order number', 'codi-email-scheduler'),
            'requires' => ['order_id'],
            'escape'   => 'text',
            'callback' => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $order = CES_WooCommerce_Helpers::order_from_context($context);
                return $order ? (string) $order->get_order_number() : '';
            },
        ]);

        ces_register_token('order_status', [
            'label'        => __('Order status', 'codi-email-scheduler'),
            'requires_any' => ['order_status', 'order_id'],
            'escape'       => 'text',
            'callback'     => static function (array $context): string {
                return (string) ($context['order_status'] ?? '');
            },
        ]);

        ces_register_token('order_previous_status', [
            'label'    => __('Previous order status', 'codi-email-scheduler'),
            'requires' => ['order_previous_status'],
            'escape'   => 'text',
        ]);

        ces_register_token('order_total', [
            'label'        => __('Order total', 'codi-email-scheduler'),
            'requires_any' => ['order_total', 'order_id'],
            'callback'     => static function (array $context): string {
                return (string) ($context['order_total'] ?? '');
            },
        ]);

        ces_register_token('order_pay_url', [
            'label'    => __('Order payment URL', 'codi-email-scheduler'),
            'requires' => ['order_id'],
            'escape'   => 'url',
            'callback' => static function (array $context) {
                if (!CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
                    return CES_WooCommerce_Helpers::runtime_unavailable_error('token');
                }
                $order = CES_WooCommerce_Helpers::order_from_context($context);
                return $order ? (string) $order->get_checkout_payment_url() : '';
            },
        ]);
    }

    public static function trim_product_name(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s*[-–—:]\s*/u', $name, 2);
        if (!is_array($parts) || $parts === []) {
            return $name;
        }

        $trimmed = trim((string) $parts[0]);
        return $trimmed !== '' ? $trimmed : $name;
    }
}
