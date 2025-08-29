<?php
// File: vendor/Cef/Engine/ConditionEngine.php

namespace Cef\Engine;

use Cef\Registry\ConditionRegistry;

defined('ABSPATH') || exit;

/**
 * Evaluates a set of rules against a given context using registered condition providers.
 */
class ConditionEngine
{
    /** @var ConditionRegistry */
    protected $registry;

    public function __construct(ConditionRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Evaluate all rules for a given target (e.g. action_id) and context.
     *
     * @param string $target_kind 'action' or 'event' (future-proof)
     * @param int    $target_id   ID of the action/event
     * @param array  $context     Normalised event context
     * @return bool  True if conditions pass, false otherwise
     */
    public function evaluate(string $target_kind, int $target_id, array $context): bool
    {
        global $wpdb;

        $rules_table = $wpdb->prefix . 'cef_rules';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $rules_table WHERE target_kind = %s AND target_id = %d ORDER BY group_index ASC, id ASC",
                $target_kind,
                $target_id
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            // No rules means always pass
            return true;
        }

        // Group rules by group_index
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int)$row['group_index']][] = $row;
        }

        // OR across groups: if any group passes, return true
        foreach ($groups as $group_rules) {
            if ($this->evaluate_group($group_rules, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a group of rules (AND logic).
     *
     * @param array $rules
     * @param array $context
     * @return bool
     */
    protected function evaluate_group(array $rules, array $context): bool
    {
        foreach ($rules as $rule) {
            $provider = $this->registry->get($rule['provider_key']);
            if (!$provider) {
                // Missing provider: fail safe
                return false;
            }

            $operator = $rule['operator'];
            $value    = $rule['value_text'];

            // Normalise value type
            if ($provider['value_type'] === 'number') {
                $value = is_numeric($value) ? (float)$value : 0;
            } elseif ($provider['value_type'] === 'select_multi') {
                $value = is_string($value) ? array_map('trim', explode(',', $value)) : (array)$value;
            }

            $passed = (bool) call_user_func(
                $provider['eval_cb'],
                $context,
                $operator,
                $value
            );

            if (!$passed) {
                return false; // AND logic: one fail means group fails
            }
        }

        return true; // all rules in group passed
    }
}