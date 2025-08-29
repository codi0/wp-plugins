<?php
// File: vendor/Cef/Registry/ActionRegistry.php

namespace Cef\Registry;

defined('ABSPATH') || exit;

/**
 * Stores and manages all registered action types.
 *
 * An action type is an array with:
 *  - key        (string) unique identifier
 *  - label      (string) human-readable name
 *  - execute_cb (callable) executes the action
 *  - available_cb (callable|null) returns bool if action is available in current context
 *  - config_schema (array|null) describes expected config fields for admin UI
 */
class ActionRegistry
{
    /** @var array<string,array> */
    protected $actions = [];

    /**
     * Register a new action type.
     */
    public function register(array $def): bool
    {
        if (empty($def['key']) || !is_string($def['key'])) {
            return false;
        }
        $key = sanitize_key($def['key']);

        $defaults = [
            'label'         => $key,
            'execute_cb'    => null,
            'available_cb'  => null,
            'config_schema' => null,
        ];
        $def = array_merge($defaults, $def);

        if (!is_callable($def['execute_cb'])) {
            return false; // must have an executor
        }

        $this->actions[$key] = $def;
        return true;
    }

    /**
     * Unregister an action type.
     */
    public function unregister(string $key): bool
    {
        $key = sanitize_key($key);
        if (isset($this->actions[$key])) {
            unset($this->actions[$key]);
            return true;
        }
        return false;
    }

    /**
     * Get an action type definition by key.
     */
    public function get(string $key): ?array
    {
        $key = sanitize_key($key);
        return $this->actions[$key] ?? null;
    }

    /**
     * Return all action types, optionally filtered by availability.
     */
    public function all(bool $only_available = false): array
    {
        if (!$only_available) {
            return $this->actions;
        }

        $out = [];
        foreach ($this->actions as $key => $def) {
            if (is_callable($def['available_cb'])) {
                if (!call_user_func($def['available_cb'])) {
                    continue;
                }
            }
            $out[$key] = $def;
        }
        return $out;
    }
}