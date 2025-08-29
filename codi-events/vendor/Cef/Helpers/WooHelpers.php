<?php
// File: vendor/Cef/Helpers/WooHelpers.php

namespace Cef\Helpers;

defined('ABSPATH') || exit;

class WooHelpers
{
    /**
     * Capture a normalised cart context for WooCommerce events.
     *
     * @return array ['context' => array, 'dedupe_key' => string|null]
     */
    public static function capture_cart_context(): array
    {
        $ctx = self::build_base_context();

        // Identify user or guest session
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $ctx['user_id']    = (int) $u->ID;
            $ctx['user_email'] = $u->user_email;
            $ctx['user_login'] = $u->user_login;
            $dedupe = 'user:' . $u->ID;
        } else {
            if (function_exists('WC') && WC()->session) {
                $sid = WC()->session->get_customer_id();
                $ctx['wc_session_id'] = (string) $sid;
                $dedupe = 'wc_session:' . (string) $sid;
            } else {
                $dedupe = 'guest:' . wp_get_session_token();
            }
        }

        // Snapshot persistent cart for logged-in users
        if (!empty($ctx['user_id'])) {
            $meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
            $ctx['wc_persistent_cart'] = get_user_meta((int) $ctx['user_id'], $meta_key, true);
        }

        // Trigger metadata
        $ctx['event_key']       = 'wc_cart_activity';
        $ctx['triggered_at']    = current_time('mysql', true);
        $ctx['triggered_at_ts'] = time();

        return [
            'context'    => $ctx,
            'dedupe_key' => $dedupe ?? null,
        ];
    }

    /**
     * Build a base context array with common keys.
     */
    protected static function build_base_context(): array
    {
        return [
            'site_url'   => site_url(),
            'blog_id'    => get_current_blog_id(),
            'wp_version' => get_bloginfo('version'),
        ];
    }
}