<?php
// File: vendor/Cef/Registry/ConditionRegistry.php

namespace Cef\Registry;

defined('ABSPATH') || exit;

/**
 * Stores and manages all registered condition providers.
 *
 * A condition provider is an array with:
 *  - key         (string) unique identifier
 *  - label       (string) human-readable name
 *  - value_type  (string) none|number|text|select|select_multi
 *  - operators   (array)  list of supported operators
 *  - options_cb  (callable|null) returns array of options for select types
 *  - eval_cb     (callable) evaluates the condition
 *  - available_cb(callable|null) returns bool if provider is available in current context
 */
class ConditionRegistry
{
    /** @var array<string,array> */
    protected $providers = [];

    /**
     * Register a new condition provider.
     */
    public function register(array $def): bool
    {
        if (empty($def['key']) || !is_string($def['key'])) {
            return false;
        }
        $key = sanitize_key($def['key']);

        $defaults = [
            'label'        => $key,
            'value_type'   => 'none',
            'operators'    => [],
            'options_cb'   => null,
            'eval_cb'      => null,
            'available_cb' => null,
        ];
        $def = array_merge($defaults, $def);

        if (!is_callable($def['eval_cb'])) {
            return false; // must have an evaluator
        }

        $this->providers[$key] = $def;
        return true;
    }

    /**
     * Unregister a condition provider.
     */
    public function unregister(string $key): bool
    {
        $key = sanitize_key($key);
        if (isset($this->providers[$key])) {
            unset($this->providers[$key]);
            return true;
        }
        return false;
    }

    /**
     * Get a provider definition by key.
     */
    public function get(string $key): ?array
    {
        $key = sanitize_key($key);
        return $this->providers[$key] ?? null;
    }

    /**
     * Return all providers, optionally filtered by availability.
     */
    public function all(bool $only_available = false): array
    {
        if (!$only_available) {
            return $this->providers;
        }

        $out = [];
        foreach ($this->providers as $key => $def) {
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