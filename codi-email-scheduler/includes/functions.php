<?php

defined('ABSPATH') || exit;

if (!function_exists('ces_register_event')) {
    /**
     * Register an event that starts scheduled email timers.
     *
     * Args:
     * - label string
     * - description string
     * - context_keys string[] keys this event may provide
     * - conditions string[] optional condition suggestions shown first in admin
     * - tokens array optional event-owned token definitions keyed by token key
     * - recipient_callback callable optional; receives ($context, $email, $runtime). When present, it is authoritative; failures do not fall back to context email/user.
     * - instance_id_callback callable optional; receives ($event_key, $context)
     * - settings_fields array optional; admin fields stored per scheduled email. Field definitions are strict and must use type text or select.
     * - settings_match_callback callable optional; receives ($settings, $context, $email, $runtime)
     * - triggers array optional; WordPress/WooCommerce hooks that fire this CES event
     *   each trigger supports: hook, priority, accepted_args, context_callback
     * - trigger_dedupe_callback callable optional; receives ($context, $event, $trigger)
     * - override bool optional; when true, replace an existing event and rebind its triggers. Duplicate keys are rejected by default.
     *
     * @return true|WP_Error
     */
    function ces_register_event(string $key, array $args) {
        return CES_Registry::register_event($key, $args);
    }
}

if (!function_exists('ces_register_condition')) {
    /**
     * Register a reusable send-time condition.
     *
     * Args:
     * - label string
     * - description string
     * - requires string[] optional; all context keys required for admin relevance
     * - requires_any string[] optional; at least one context key required for admin relevance
     * - settings_fields array optional; admin fields stored per selected condition. Field definitions are strict and must use type text or select.
     * - callback callable; receives ($context, $email, $runtime)
     *   and returns bool, WP_Error, or ['passed' => bool, 'reason' => string, 'actual' => mixed, 'expected' => mixed]
     * - override bool optional; duplicate keys are rejected by default.
     *
     * @return true|WP_Error
     */
    function ces_register_condition(string $key, array $args) {
        return CES_Registry::register_condition($key, $args);
    }
}


if (!function_exists('ces_register_token')) {
    /**
     * Register an email token.
     *
     * Args:
     * - label string
     * - description string
     * - requires string[] optional; all context keys required for admin relevance
     * - requires_any string[] optional; at least one context key required for admin relevance
     * - events string[] optional; event keys this token is explicitly available for
     * - escape string optional; html, text, url, or raw. Defaults to html. Invalid values fail registration.
     * - callback callable optional; receives ($context, $email, $runtime). Defaults to context[$key].
     * - override bool optional; duplicate keys are rejected by default. Identical definitions merge their events list.
     *
     * @return true|WP_Error
     */
    function ces_register_token(string $key, array $args) {
        return CES_Registry::register_token($key, $args);
    }
}

if (!function_exists('ces_fire_event')) {
    /**
     * Manually fire an already registered event and schedule matching emails.
     *
     * Most WordPress/WooCommerce integrations should prefer event trigger definitions in
     * ces_register_event(). This function remains the lower-level API for manual tests,
     * custom integrations, and non-hook event sources.
     *
     * @return array|WP_Error Scheduled Action Scheduler IDs, or WP_Error.
     */
    function ces_fire_event(string $event_key, array $context = []) {
        return CES_Engine::fire_event($event_key, $context);
    }
}
