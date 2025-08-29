<?php
// File: vendor/Cef/Registry/EventRegistry.php

namespace Cef\Registry;

defined('ABSPATH') || exit;

/**
 * Stores and manages all registered events.
 *
 * An event definition is an array with:
 *  - key        (string) WordPress action hook to bind to
 *  - label      (string) human-readable name
 *  - enabled    (bool)   whether the event is active
 *  - debounce   (int)    seconds to suppress duplicate triggers for same dedupe_key
 *  - callback    (callable) returns ['context' => array, 'dedupe_key' => string|null]
 */
class EventRegistry
{
    /** @var array<string,array> */
    protected $events = [];

    /**
     * Register a new event definition.
     */
    public function register(array $def): bool
    {
        if (empty($def['key']) || !is_string($def['key'])) {
            return false;
        }
        if (!is_callable($def['callback'])) {
            return false;
        }

        $key = sanitize_key($def['key']);

        $defaults = [
            'label'    => $key,
            'enabled'  => true,
            'debounce' => 0,
        ];
        $def = array_merge($defaults, $def);

        $this->events[$key] = $def;
        return true;
    }

    /**
     * Unregister an event definition.
     */
    public function unregister(string $key): bool
    {
        $key = sanitize_key($key);
        if (isset($this->events[$key])) {
            unset($this->events[$key]);
            return true;
        }
        return false;
    }

    /**
     * Get an event definition by key.
     */
    public function get(string $key): ?array
    {
        $key = sanitize_key($key);
        return $this->events[$key] ?? null;
    }

    /**
     * Return all events, optionally only enabled ones.
     */
    public function all(bool $only_enabled = false): array
    {
        if (!$only_enabled) {
            return $this->events;
        }

        return array_filter($this->events, function ($def) {
            return !empty($def['enabled']);
        });
    }

    /**
     * Bind all enabled events to their WordPress hooks.
     *
     * @param callable $enqueue_cb Callback to enqueue a job: function($event_key, $context, $dedupe_key, $debounce)
     */
    public function bind_all(callable $enqueue_cb): void
    {
        foreach ($this->all(true) as $key => $def) {
            add_action($key, function () use ($key, $def, $enqueue_cb) {
				$hook_args = func_get_args();
                $result = call_user_func_array($def['callback'], $hook_args);

                if (!is_array($result) || !isset($result['context'])) {
                    return;
                }

                $context    = $result['context'];
                $dedupe_key = $result['dedupe_key'] ?? null;
                $debounce   = (int)($def['debounce'] ?? 0);

                call_user_func($enqueue_cb, $key, $context, $dedupe_key, $debounce);
            }, 10, 10);
        }
    }

    /**
     * Link an event to an action in the DB so only mapped actions are enqueued.
     */
    public function link_action(string $event_key, int $action_id, bool $enabled = true): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cef_event_actions';

        $data = [
            'event_key'  => sanitize_text_field($event_key),
            'action_id'  => (int)$action_id,
            'enabled'    => $enabled ? 1 : 0,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];

        // Check if link exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE event_key = %s AND action_id = %d LIMIT 1",
            $data['event_key'],
            $data['action_id']
        ));

        if ($exists) {
            return (bool) $wpdb->update($table, [
                'enabled'    => $data['enabled'],
                'updated_at' => $data['updated_at'],
            ], ['id' => (int)$exists]);
        }

        return (bool) $wpdb->insert($table, $data);
    }
}