<?php
// File: vendor/Cef/Registry/Events/WooCommerceEvents.php

namespace Cef\Registry\Events;

use Cef\Registry\EventRegistry;

defined('ABSPATH') || exit;

/**
 * Registers a set of common WordPress core events with sensible context payloads.
 * These will appear in the "Trigger Events" section of ActionsController immediately.
 */
class WooCommerceEvents
{
    /**
     * Register all core events into the given EventRegistry.
     *
     * @param EventRegistry $registry
     */
    public static function register_all(EventRegistry $registry): void
    {
		if (!class_exists('WooCommerce')) {
			return;
		}
        
        $registry->register([
			'key'         => 'woocommerce_order_status_changed',
			'label'       => __('Order status changed', 'cef'),
			'description' => __('Triggered when a WooCommerce order changes status.', 'cef'),
			'callback'     => function ($order_id, $old_status, $new_status, $order) {
				return [
					'context'    => [
						'order_id'    => $order_id,
						'old_status'  => $old_status,
						'new_status'  => $new_status,
						'total'       => $order->get_total(),
						'customer_id' => $order->get_customer_id(),
					],
					'dedupe_key' => (string) $order_id,
				];
			},
		]);
		
    }
}