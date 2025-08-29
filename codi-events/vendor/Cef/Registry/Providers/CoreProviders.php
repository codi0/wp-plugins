<?php
// File: vendor/Cef/Registry/Providers/CoreProviders.php

namespace Cef\Registry\Providers;

use Cef\Registry\ConditionRegistry;

defined('ABSPATH') || exit;

class CoreProviders
{
    /**
     * Register all core providers into the registry.
     */
    public static function register_all(ConditionRegistry $registry): void
    {
        // Time since trigger (in days)
        $registry->register([
            'key'        => 'time_since_trigger',
            'label'      => __('Time Since Trigger (days)', 'cef'),
            'value_type' => 'number',
            'operators'  => ['>', '>=', '<', '<='],
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                if (empty($context['triggered_at_ts'])) {
                    return false;
                }
                $elapsed_seconds = time() - (int)$context['triggered_at_ts'];
                $elapsed_days    = $elapsed_seconds / DAY_IN_SECONDS;
                return self::compare((float)$elapsed_days, $operator, (float)$value);
            },
        ]);

        // Day of week
        $registry->register([
            'key'        => 'day_of_week',
            'label'      => __('Day of Week', 'cef'),
            'value_type' => 'select_multi',
            'operators'  => ['in', 'not_in'],
            'options_cb' => function (): array {
                return [
                    'mon' => __('Monday', 'cef'),
                    'tue' => __('Tuesday', 'cef'),
                    'wed' => __('Wednesday', 'cef'),
                    'thu' => __('Thursday', 'cef'),
                    'fri' => __('Friday', 'cef'),
                    'sat' => __('Saturday', 'cef'),
                    'sun' => __('Sunday', 'cef'),
                ];
            },
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                $today = strtolower(date('D'));
                $in_list = in_array($today, (array)$value, true);
                return $operator === 'in' ? $in_list : !$in_list;
            },
        ]);

        // User role
        $registry->register([
            'key'        => 'user_role',
            'label'      => __('User Role', 'cef'),
            'value_type' => 'select_multi',
            'operators'  => ['in', 'not_in'],
            'options_cb' => function (): array {
                global $wp_roles;
                if (!isset($wp_roles)) {
                    $wp_roles = wp_roles();
                }
                return $wp_roles->get_names();
            },
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                if (empty($context['user_id'])) {
                    return false;
                }
                $user = get_userdata((int)$context['user_id']);
                if (!$user) {
                    return false;
                }
                $roles = (array)$user->roles;
                $match = array_intersect($roles, (array)$value);
                return $operator === 'in' ? !empty($match) : empty($match);
            },
        ]);

        // User meta value equals (explicit key=value format)
        $registry->register([
            'key'        => 'user_meta_value_equals',
            'label'      => __('User Meta Value Equals', 'cef'),
            'value_type' => 'text', // expects "meta_key=expected_value"
            'operators'  => ['=', '!='],
            'eval_cb'    => function (array $context, string $operator, $value): bool {
                if (empty($context['user_id']) || strpos((string)$value, '=') === false) {
                    return false;
                }
                list($meta_key, $expected) = array_map('trim', explode('=', (string)$value, 2));
                $actual = get_user_meta((int)$context['user_id'], $meta_key, true);
                $eq = (string)$actual === $expected;
                return $operator === '=' ? $eq : !$eq;
            },
        ]);
    }

    /**
     * Simple comparison helper.
     */
    protected static function compare(float $left, string $operator, float $right): bool
    {
        switch ($operator) {
            case '>':  return $left > $right;
            case '>=': return $left >= $right;
            case '<':  return $left < $right;
            case '<=': return $left <= $right;
            default:   return false;
        }
    }
}