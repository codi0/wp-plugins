<?php

defined('ABSPATH') || exit;

final class CES_WooCommerce_Helpers {
    public static function is_woocommerce_dependency_active(): bool {
        if (self::is_woocommerce_runtime_available()) {
            return true;
        }

        $plugin = 'woocommerce/woocommerce.php';
        $active_plugins = (array) get_option('active_plugins', []);
        if (in_array($plugin, $active_plugins, true)) {
            return true;
        }

        if (is_multisite()) {
            $network_plugins = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network_plugins[$plugin])) {
                return true;
            }
        }

        return false;
    }

    public static function is_woocommerce_runtime_available(): bool {
        return class_exists('WooCommerce') || function_exists('wc_get_order') || function_exists('wc_get_product') || function_exists('wc_get_orders');
    }

    public static function runtime_unavailable_error(string $feature = ''): WP_Error {
        return new WP_Error('woocommerce_unavailable', __('WooCommerce is required for this WooCommerce Email Scheduler feature but is not loaded in this request.', 'codi-email-scheduler'), [
            'feature' => sanitize_key($feature),
        ]);
    }

    public static function customer_context(): array {
        $context = [
            'user_id' => get_current_user_id(),
            'blog_id' => get_current_blog_id(),
        ];

        if (!empty($context['user_id'])) {
            $user = get_user_by('id', absint($context['user_id']));
            if ($user instanceof WP_User) {
                $context['email'] = (string) $user->user_email;
                $context['user_login'] = (string) $user->user_login;
            }
        }

        if (empty($context['email']) && function_exists('WC') && WC() && isset(WC()->customer) && WC()->customer) {
            $email = WC()->customer->get_billing_email();
            if ($email && is_email($email)) {
                $context['email'] = $email;
            }
        }

        return $context;
    }

    public static function order_context(WC_Order $order, array $extra = []): array {
        $user_id = (int) $order->get_user_id();
        $context = [
            'user_id'      => $user_id,
            'email'        => (string) $order->get_billing_email(),
            'order_id'     => (int) $order->get_id(),
            'order_status' => (string) $order->get_status(),
            'order_total'  => (string) $order->get_total(),
            'blog_id'      => get_current_blog_id(),
        ];

        if ($user_id > 0) {
            $user = get_user_by('id', $user_id);
            if ($user instanceof WP_User) {
                $context['user_login'] = (string) $user->user_login;
            }
        }

        return array_merge($context, $extra);
    }

    public static function cart_hash(): string {
        if (!function_exists('WC') || !WC() || !isset(WC()->cart) || !WC()->cart) {
            return '';
        }

        $hash = WC()->cart->get_cart_hash();
        return is_string($hash) ? sanitize_text_field($hash) : '';
    }

    public static function customer_instance_key(array $context): string {
        if (!empty($context['user_id'])) {
            return 'user_' . absint($context['user_id']);
        }

        if (!empty($context['email']) && is_email((string) $context['email'])) {
            return 'email_' . md5(strtolower((string) $context['email']));
        }

        return 'guest';
    }

    public static function product_from_context(array $context) {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $product_id = !empty($context['variation_id']) ? absint($context['variation_id']) : absint($context['product_id'] ?? 0);
        return $product_id ? wc_get_product($product_id) : null;
    }

    public static function order_from_context(array $context) {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order_id = isset($context['order_id']) ? absint($context['order_id']) : 0;
        return $order_id ? wc_get_order($order_id) : null;
    }

    public static function customer_has_paid_order(array $context): bool {
        if (!function_exists('wc_get_orders')) {
            return false;
        }

        return !empty(self::customer_paid_orders($context, 1, false));
    }

    public static function persistent_cart_items(array $context): array {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        if (!$user_id) {
            return [];
        }

        $blog_id = isset($context['blog_id']) ? absint($context['blog_id']) : get_current_blog_id();
        $blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
        $cart = get_user_meta($user_id, '_woocommerce_persistent_cart_' . $blog_id, true);

        return is_array($cart) && isset($cart['cart']) && is_array($cart['cart']) ? $cart['cart'] : [];
    }

    public static function persistent_cart_has_items(array $context): bool {
        foreach (self::persistent_cart_items($context) as $item) {
            if (is_array($item) && absint($item['quantity'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    public static function persistent_cart_contains_context_product(array $context): bool {
        $product_id = isset($context['product_id']) ? absint($context['product_id']) : 0;
        $variation_id = isset($context['variation_id']) ? absint($context['variation_id']) : 0;

        if (!$product_id) {
            return false;
        }

        foreach (self::persistent_cart_items($context) as $item) {
            if (!is_array($item) || absint($item['quantity'] ?? 0) === 0) {
                continue;
            }

            if (absint($item['product_id'] ?? 0) !== $product_id) {
                continue;
            }

            if ($variation_id > 0 && absint($item['variation_id'] ?? 0) !== $variation_id) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function paid_orders_since_event(array $context, int $limit = 10): array {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $timestamp = self::event_timestamp($context);
        if ($timestamp <= 0) {
            return [];
        }

        return self::customer_paid_orders($context, $limit, true, $timestamp);
    }

    public static function customer_ordered_context_product_since_event(array $context): bool {
        $product_id = isset($context['product_id']) ? absint($context['product_id']) : 0;
        $variation_id = isset($context['variation_id']) ? absint($context['variation_id']) : 0;

        if (!$product_id) {
            return false;
        }

        foreach (self::paid_orders_since_event($context, 20) as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                    continue;
                }

                if (absint($item->get_product_id()) !== $product_id) {
                    continue;
                }

                $item_variation_id = method_exists($item, 'get_variation_id') ? absint($item->get_variation_id()) : 0;
                if ($variation_id > 0 && $item_variation_id !== $variation_id) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private static function customer_paid_orders(array $context, int $limit, bool $objects, int $since = 0): array {
        $queries = self::customer_order_query_args_list($context, $limit);
        $results = [];
        foreach ($queries as $args) {
            $args['return'] = $objects ? 'objects' : 'ids';
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            if ($since > 0) {
                $args['date_created'] = '>=' . $since;
            }
            foreach ((array) wc_get_orders($args) as $order) {
                $id = is_object($order) && method_exists($order, 'get_id') ? absint($order->get_id()) : absint($order);
                if ($id > 0) {
                    $results[$id] = $order;
                }
            }
        }
        return array_values($results);
    }

    public static function customer_order_query_args_list(array $context, int $limit): array {
        $base = self::customer_order_query_args($context, $limit);
        if (!$base) {
            return [];
        }
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        $email = CES_Context::user_email($context);
        $queries = [];
        if ($user_id > 0) {
            $queries[] = array_merge($base, ['customer_id' => $user_id]);
        }
        if ($email !== '' && is_email($email)) {
            $queries[] = array_merge($base, ['billing_email' => $email]);
        }
        return $queries;
    }

    public static function customer_order_query_args(array $context, int $limit): array {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        $email = isset($context['email']) ? sanitize_email((string) $context['email']) : '';

        if ($user_id <= 0 && (!$email || !is_email($email))) {
            return [];
        }

        $statuses = function_exists('wc_get_is_paid_statuses') ? array_values(array_filter(array_map('sanitize_key', (array) wc_get_is_paid_statuses()))) : [];
        if (!$statuses) {
            $statuses = ['processing', 'completed'];
        }

        $args = [
            'status' => $statuses,
            'limit'  => max(1, $limit),
            'return' => 'ids',
        ];

        return $args;
    }

    public static function products_from_context(array $context): array {
        $products = [];
        $context_product = self::product_from_context($context);
        if ($context_product) {
            $products[] = $context_product;
        }

        $order = self::order_from_context($context);
        if ($order instanceof WC_Order) {
            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product')) {
                    continue;
                }
                $product = $item->get_product();
                if ($product) {
                    $products[] = $product;
                }
            }
        }

        $unique = [];
        foreach ($products as $product) {
            if (is_object($product) && method_exists($product, 'get_id')) {
                $unique[(int) $product->get_id()] = $product;
            }
        }

        return array_values($unique);
    }

    public static function context_order_status_matches(array $context, array $statuses): bool {
        $statuses = array_values(array_unique(array_map('sanitize_key', $statuses)));
        if (!$statuses) {
            return true;
        }

        $status = isset($context['order_status']) ? sanitize_key((string) $context['order_status']) : '';
        if ($status === '') {
            $order = self::order_from_context($context);
            if ($order instanceof WC_Order) {
                $status = sanitize_key((string) $order->get_status());
            }
        }

        return $status !== '' && in_array($status, $statuses, true);
    }

    public static function context_product_id_matches(array $context, array $product_ids): bool {
        $product_ids = array_values(array_unique(array_map('absint', $product_ids)));
        if (!$product_ids) {
            return true;
        }

        foreach (self::products_from_context($context) as $product) {
            $ids = [(int) $product->get_id()];
            if (method_exists($product, 'get_parent_id')) {
                $parent_id = (int) $product->get_parent_id();
                if ($parent_id > 0) {
                    $ids[] = $parent_id;
                }
            }
            if (array_intersect($ids, $product_ids)) {
                return true;
            }
        }

        return false;
    }

    public static function context_product_terms_match(array $context, string $taxonomy, array $filters): bool {
        if (!$filters) {
            return true;
        }

        foreach (self::products_from_context($context) as $product) {
            if (self::product_matches_terms($product, $taxonomy, $filters)) {
                return true;
            }
        }

        return false;
    }

    public static function product_matches_terms($product, string $taxonomy, array $filters): bool {
        if (!function_exists('has_term') || !$product || !method_exists($product, 'get_id')) {
            return false;
        }

        $ids_to_check = [(int) $product->get_id()];
        if (method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $ids_to_check[] = $parent_id;
            }
        }

        foreach ($filters as $filter) {
            $term = ctype_digit((string) $filter) ? absint($filter) : sanitize_title((string) $filter);
            if ($term === '' || $term === 0) {
                continue;
            }
            foreach ($ids_to_check as $product_id) {
                if (has_term($term, $taxonomy, $product_id)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function product_term_names($product, string $taxonomy): array {
        if (!function_exists('get_the_terms') || !$product || !method_exists($product, 'get_id')) {
            return [];
        }

        $product_id = (int) $product->get_id();
        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            $product_id = (int) $product->get_parent_id();
        }

        $terms = get_the_terms($product_id, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        $names = [];
        foreach ($terms as $term) {
            if (is_object($term) && isset($term->name)) {
                $names[] = (string) $term->name;
            }
        }

        return $names;
    }

    public static function event_timestamp(array $context): int {
        if (!empty($context['event_timestamp'])) {
            return absint($context['event_timestamp']);
        }

        if (!empty($context['event_time_gmt'])) {
            $raw = trim((string) $context['event_time_gmt']);
            $has_timezone = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $raw);
            $timestamp = strtotime($has_timezone ? $raw : $raw . ' GMT');
            return $timestamp !== false ? (int) $timestamp : 0;
        }

        return 0;
    }
}
