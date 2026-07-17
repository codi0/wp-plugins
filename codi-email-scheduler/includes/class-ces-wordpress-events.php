<?php

defined('ABSPATH') || exit;

final class CES_WordPress_Events {
    private static array $previous_user_meta_values = [];
    private static bool $support_hooks_registered = false;

    public static function init(): void {
        self::register_items();
        self::register_support_hooks();
    }

    public static function register_items(): void {
        if (!function_exists('ces_register_event') || !function_exists('ces_register_condition')) {
            return;
        }

        ces_register_event('wp_user_registered', [
            'label'        => __('WordPress user registered', 'codi-email-scheduler'),
            'description'  => __('Fires when WordPress creates a new user account.', 'codi-email-scheduler'),
            'context_keys' => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'retrospective' => true,
            'triggers'     => [[
                'key'              => 'user_register',
                'hook'             => 'user_register',
                'priority'         => 20,
                'accepted_args'    => 1,
                'context_callback' => [__CLASS__, 'context_from_user_register'],
            ]],
        ]);

        ces_register_event('wp_user_added_to_site', [
            'label'                => __('WordPress user added to site', 'codi-email-scheduler'),
            'description'          => __('Fires on multisite when a user is added to a specific site.', 'codi-email-scheduler'),
            'retrospective'           => true,
            'context_keys'         => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'   => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'triggers'     => [[
                'key'              => 'add_user_to_blog',
                'hook'             => 'add_user_to_blog',
                'priority'         => 20,
                'accepted_args'    => 3,
                'context_callback' => [__CLASS__, 'context_from_user_added_to_blog'],
            ]],
        ]);

        ces_register_event('wp_user_available_on_site', [
            'label'                   => __('WordPress user available on site', 'codi-email-scheduler'),
            'description'             => __('Fires once when a user is either registered on this site or added to this site. Use this for site onboarding/profile reminders.', 'codi-email-scheduler'),
            'context_keys'            => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'              => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'retrospective'           => true,
            'instance_id_callback'    => static function (string $event_key, array $context): string {
                return $event_key . ':' . absint($context['blog_id'] ?? get_current_blog_id()) . ':' . absint($context['user_id'] ?? 0);
            },
            'trigger_dedupe_callback' => static function (array $context): string {
                return absint($context['blog_id'] ?? get_current_blog_id()) . ':' . absint($context['user_id'] ?? 0);
            },
            'triggers'                => [
                [
                    'key'              => 'user_register',
                    'hook'             => 'user_register',
                    'priority'         => 20,
                    'accepted_args'    => 1,
                    'context_callback' => [__CLASS__, 'context_from_user_available_register'],
                ],
                [
                    'key'              => 'add_user_to_blog',
                    'hook'             => 'add_user_to_blog',
                    'priority'         => 20,
                    'accepted_args'    => 3,
                    'context_callback' => [__CLASS__, 'context_from_user_available_added_to_blog'],
                ],
            ],
        ]);

        ces_register_event('wp_user_login', [
            'label'                => __('WordPress user login', 'codi-email-scheduler'),
            'description'          => __('Fires when a user logs in.', 'codi-email-scheduler'),
            'context_keys'         => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'           => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'instance_id_callback' => static function (string $event_key, array $context): string {
                return CES_Event_Identity::occurrence($event_key, [absint($context['user_id'] ?? 0)]);
            },
            'triggers'             => [[
                'key'              => 'wp_login',
                'hook'             => 'wp_login',
                'priority'         => 20,
                'accepted_args'    => 2,
                'context_callback' => [__CLASS__, 'context_from_user_login'],
            ]],
        ]);

        ces_register_event('wp_user_meta_changed', [
            'label'                   => __('WordPress user meta changed', 'codi-email-scheduler'),
            'description'             => __('Fires when a selected user meta key changes and matches the configured trigger rule.', 'codi-email-scheduler'),
            'retrospective'           => true,
            'context_keys'            => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'meta_key', 'meta_value', 'previous_meta_value', 'meta_change_type', 'source_hook', 'source_trigger'],
            'conditions'              => ['user_exists', 'wp_user_has_email', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'settings_fields'         => self::user_meta_event_settings_fields(),
            'settings_match_callback' => [__CLASS__, 'user_meta_event_settings_match'],
            'instance_id_callback'    => static function (string $event_key, array $context): string {
                return CES_Event_Identity::occurrence($event_key, [
                    absint($context['blog_id'] ?? get_current_blog_id()),
                    absint($context['user_id'] ?? 0),
                    sanitize_key((string) ($context['meta_key'] ?? '')),
                ]);
            },
            'triggers'                => [
                [
                    'key'              => 'added_user_meta',
                    'hook'             => 'added_user_meta',
                    'priority'         => 20,
                    'accepted_args'    => 4,
                    'context_callback' => [__CLASS__, 'context_from_user_meta_added'],
                ],
                [
                    'key'              => 'updated_user_meta',
                    'hook'             => 'updated_user_meta',
                    'priority'         => 20,
                    'accepted_args'    => 4,
                    'context_callback' => [__CLASS__, 'context_from_user_meta_updated'],
                ],
                [
                    'key'              => 'deleted_user_meta',
                    'hook'             => 'deleted_user_meta',
                    'priority'         => 20,
                    'accepted_args'    => 4,
                    'context_callback' => [__CLASS__, 'context_from_user_meta_deleted'],
                ],
            ],
        ]);


        ces_register_event('wp_user_role_added', [
            'label'                   => __('WordPress user role added', 'codi-email-scheduler'),
            'description'             => __('Fires when a selected WordPress role is assigned to a user.', 'codi-email-scheduler'),
            'retrospective'           => true,
            'context_keys'            => ['user_id', 'email', 'user_login', 'role', 'user_roles', 'assigned_role', 'blog_id', 'source_hook', 'source_trigger'],
            'conditions'              => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'settings_fields'         => [
                'role' => [
                    'label'       => __('WordPress role', 'codi-email-scheduler'),
                    'type'        => 'select',
                    'options'     => self::role_options(),
                    'required'    => true,
                    'description' => __('Process the event only when this role is assigned.', 'codi-email-scheduler'),
                ],
            ],
            'settings_match_callback' => static function (array $settings, array $context): bool {
                return sanitize_key((string) ($settings['role'] ?? '')) === sanitize_key((string) ($context['assigned_role'] ?? ''));
            },
            'instance_id_callback'    => static function (string $event_key, array $context): string {
                return CES_Event_Identity::occurrence($event_key, [
                    absint($context['blog_id'] ?? get_current_blog_id()),
                    absint($context['user_id'] ?? 0),
                    sanitize_key((string) ($context['assigned_role'] ?? '')),
                ]);
            },
            'trigger_dedupe_callback' => static function (array $context): string {
                return absint($context['blog_id'] ?? get_current_blog_id()) . ':'
                    . absint($context['user_id'] ?? 0) . ':'
                    . sanitize_key((string) ($context['assigned_role'] ?? ''));
            },
            'triggers'                => [
                [
                    'key'              => 'add_user_role',
                    'hook'             => 'add_user_role',
                    'priority'         => 20,
                    'accepted_args'    => 2,
                    'context_callback' => [__CLASS__, 'context_from_user_role_added'],
                ],
                [
                    'key'              => 'set_user_role',
                    'hook'             => 'set_user_role',
                    'priority'         => 20,
                    'accepted_args'    => 3,
                    'context_callback' => [__CLASS__, 'context_from_user_role_set'],
                ],
            ],
        ]);

        self::register_conditions();
    }

    private static function register_conditions(): void {
        ces_register_condition('wp_user_has_email', [
            'label'        => __('User has email address', 'codi-email-scheduler'),
            'description'  => __('Passes when the context or user account has a valid email address.', 'codi-email-scheduler'),
            'requires_any' => ['user_id', 'email'],
            'callback'     => static function (array $context): array {
                $email = CES_Context::user_email($context);
                return [
                    'passed' => $email !== '' && is_email($email),
                    'actual' => $email,
                    'reason' => $email === '' ? 'no_valid_email' : '',
                ];
            },
        ]);

        ces_register_condition('wp_user_is_site_member', [
            'label'       => __('User belongs to this site', 'codi-email-scheduler'),
            'description' => __('Passes when the context user is a member of the current site/blog.', 'codi-email-scheduler'),
            'requires'    => ['user_id'],
            'callback'    => static function (array $context): array {
                $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
                $passed = $user_id > 0 && (is_multisite() ? is_user_member_of_blog($user_id, get_current_blog_id()) : (bool) get_user_by('id', $user_id));
                return [
                    'passed' => $passed,
                    'actual' => $user_id,
                    'reason' => $passed ? '' : 'user_not_site_member',
                ];
            },
        ]);

        ces_register_condition('organisation_exists', [
            'label'       => __('Organisation exists', 'codi-email-scheduler'),
            'description' => __('Passes when organisation context can be resolved for the event user.', 'codi-email-scheduler'),
            'requires'    => ['user_id'],
            'callback'    => static function (array $context): array {
                $passed = !empty($context['organisation_id']) || !empty($context['organisation_name']) || (bool) CES_Context::organisation($context);
                return [
                    'passed' => $passed,
                    'actual' => trim((string) (($context['organisation_id'] ?? '') . ' ' . ($context['organisation_name'] ?? ''))),
                    'reason' => $passed ? '' : 'organisation_not_found',
                ];
            },
        ]);

        ces_register_condition('wp_user_meta_matches', [
            'label'           => __('User meta matches', 'codi-email-scheduler'),
            'description'     => __('Passes when a configured user meta key matches the selected comparison.', 'codi-email-scheduler'),
            'requires'        => ['user_id'],
            'settings_fields' => self::user_meta_condition_settings_fields(),
            'callback'        => static function (array $context, array $email, array $runtime): array {
                $settings = CES_Storage::normalize_settings($runtime['condition_settings'] ?? []);
                $meta_key = trim((string) ($settings['meta_key'] ?? ''));

                $condition_user = CES_Context::user($context);
                if (!$condition_user instanceof WP_User || $meta_key === '') {
                    return [
                        'passed' => false,
                        'reason' => 'missing_user_or_meta_key',
                    ];
                }

                $comparison = sanitize_key((string) ($settings['comparison'] ?? 'truthy'));
                $expected = (string) ($settings['value'] ?? '');
                $exists = CES_Context::user_meta_exists($context, $meta_key);
                $actual = CES_Context::user_meta($context, $meta_key, '');
                $passed = self::value_matches($actual, $comparison, $expected, $exists);

                return [
                    'passed'   => $passed,
                    'actual'   => self::scalar_string($actual),
                    'expected' => $comparison . ($expected !== '' ? ':' . $expected : ''),
                    'reason'   => $passed ? '' : 'user_meta_mismatch',
                ];
            },
        ]);
    }

    private static function register_support_hooks(): void {
        if (self::$support_hooks_registered) {
            return;
        }

        self::$support_hooks_registered = true;
        add_filter('update_user_metadata', [__CLASS__, 'capture_previous_user_meta_value'], 10, 5);
        add_filter('delete_user_metadata', [__CLASS__, 'capture_previous_user_meta_delete'], 10, 5);
    }

    public static function context_from_user_register(int $user_id): ?array {
        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return null;
        }

        return self::user_context($user, [
            'blog_id'           => get_current_blog_id(),
            'event_instance_id' => 'wp_user_registered:' . $user_id,
        ]);
    }

    public static function context_from_user_available_register(int $user_id): ?array {
        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return null;
        }

        $blog_id = get_current_blog_id();
        if (is_multisite() && !is_user_member_of_blog((int) $user->ID, $blog_id)) {
            return null;
        }

        return self::user_context($user, [
            'blog_id'           => $blog_id,
            'event_instance_id' => 'wp_user_available_on_site:' . $blog_id . ':' . (int) $user->ID,
        ]);
    }

    public static function context_from_user_added_to_blog(int $user_id, string $role, int $blog_id): ?array {
        return self::user_context_for_blog($user_id, $blog_id, [
            'role'              => $role,
            'blog_id'           => $blog_id,
            'event_instance_id' => CES_Event_Identity::occurrence('wp_user_added_to_site', [$blog_id, $user_id]),
        ]);
    }

    public static function context_from_user_available_added_to_blog(int $user_id, string $role, int $blog_id): ?array {
        return self::user_context_for_blog($user_id, $blog_id, [
            'role'              => $role,
            'blog_id'           => $blog_id,
            'event_instance_id' => 'wp_user_available_on_site:' . $blog_id . ':' . $user_id,
        ]);
    }

    public static function context_from_user_login(string $user_login, WP_User $user): array {
        return self::user_context($user, [
            'blog_id'           => get_current_blog_id(),
        ]);
    }

    public static function context_from_user_role_added(int $user_id, string $role): ?array {
        return self::user_role_context($user_id, $role);
    }

    public static function context_from_user_role_set(int $user_id, string $role, array $old_roles = []): ?array {
        $role = sanitize_key($role);
        if ($role === '' || in_array($role, array_map('sanitize_key', $old_roles), true)) {
            return null;
        }
        return self::user_role_context($user_id, $role);
    }

    public static function capture_previous_user_meta_value($check, int $user_id, string $meta_key, $meta_value, $prev_value) {
        self::$previous_user_meta_values[self::meta_cache_key($user_id, $meta_key)] = get_user_meta($user_id, $meta_key, true);
        return $check;
    }

    public static function capture_previous_user_meta_delete($check, $user_ids, string $meta_key, $meta_value, bool $delete_all) {
        foreach ((array) $user_ids as $user_id) {
            $user_id = absint($user_id);
            if ($user_id > 0) {
                self::$previous_user_meta_values[self::meta_cache_key($user_id, $meta_key)] = get_user_meta($user_id, $meta_key, true);
            }
        }
        return $check;
    }

    public static function context_from_user_meta_added(int $meta_id, int $user_id, string $meta_key, $meta_value): ?array {
        return self::user_meta_context($user_id, $meta_key, $meta_value, '', 'added');
    }

    public static function context_from_user_meta_updated(int $meta_id, int $user_id, string $meta_key, $meta_value): ?array {
        $cache_key = self::meta_cache_key($user_id, $meta_key);
        $previous = self::$previous_user_meta_values[$cache_key] ?? '';
        unset(self::$previous_user_meta_values[$cache_key]);

        return self::user_meta_context($user_id, $meta_key, $meta_value, $previous, 'updated');
    }

    public static function context_from_user_meta_deleted(array $meta_ids, int $user_id, string $meta_key, $meta_value): ?array {
        $cache_key = self::meta_cache_key($user_id, $meta_key);
        $previous = self::$previous_user_meta_values[$cache_key] ?? $meta_value;
        unset(self::$previous_user_meta_values[$cache_key]);

        return self::user_meta_context($user_id, $meta_key, '', $previous, 'deleted');
    }

    public static function user_meta_event_settings_match(array $settings, array $context): bool {
        $meta_key = trim((string) ($settings['meta_key'] ?? ''));
        if ($meta_key === '' || $meta_key !== (string) ($context['meta_key'] ?? '')) {
            return false;
        }

        $trigger = sanitize_key((string) ($settings['trigger'] ?? 'changes'));
        $expected = (string) ($settings['value'] ?? '');
        $current = $context['meta_value'] ?? '';
        $previous = $context['previous_meta_value'] ?? '';

        switch ($trigger) {
            case 'becomes_truthy':
                return !self::is_truthy($previous) && self::is_truthy($current);
            case 'becomes_falsy':
                return self::is_truthy($previous) && !self::is_truthy($current);
            case 'becomes_value':
                return self::scalar_equals($current, $expected) && !self::scalar_equals($previous, $expected);
            case 'changes':
            default:
                return !self::scalar_equals($current, $previous);
        }
    }

    private static function user_context_for_blog(int $user_id, int $blog_id, array $extra = []): ?array {
        $callback = static function () use ($user_id, $extra): ?array {
            $user = get_user_by('id', $user_id);
            if (!$user instanceof WP_User) {
                return null;
            }
            return self::user_context($user, $extra);
        };

        if (is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() && function_exists('switch_to_blog')) {
            switch_to_blog($blog_id);
            try {
                return $callback();
            } finally {
                restore_current_blog();
            }
        }

        return $callback();
    }

    private static function user_meta_context(int $user_id, string $meta_key, $meta_value, $previous_value, string $change_type): ?array {
        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return null;
        }

        return self::user_context($user, [
            'blog_id'             => get_current_blog_id(),
            'meta_key'            => $meta_key,
            'meta_value'          => self::scalar_string($meta_value),
            'previous_meta_value' => self::scalar_string($previous_value),
            'meta_change_type'    => $change_type,
        ]);
    }

    private static function user_context(WP_User $user, array $extra = []): array {
        $roles = array_values(array_filter(array_map('sanitize_key', (array) $user->roles)));

        return array_merge([
            'user_id'    => (int) $user->ID,
            'email'      => (string) $user->user_email,
            'user_login' => (string) $user->user_login,
            'role'       => $roles[0] ?? '',
            'user_roles' => $roles,
        ], $extra);
    }

    private static function user_role_context(int $user_id, string $role): ?array {
        $role = sanitize_key($role);
        $user = get_user_by('id', $user_id);
        if ($role === '' || !$user instanceof WP_User) {
            return null;
        }

        return self::user_context($user, [
            'blog_id'       => get_current_blog_id(),
            'assigned_role' => $role,
        ]);
    }

    private static function role_options(): array {
        $options = [];
        if (function_exists('wp_roles')) {
            foreach ((array) wp_roles()->roles as $role_key => $role_data) {
                $role_key = sanitize_key((string) $role_key);
                if ($role_key === '') {
                    continue;
                }
                $role_name = isset($role_data['name']) ? (string) $role_data['name'] : $role_key;
                if (function_exists('translate_user_role')) {
                    $role_name = translate_user_role($role_name);
                }
                $options[$role_key] = sprintf('%s (%s)', $role_name, $role_key);
            }
        }
        return $options ?: ['subscriber' => __('Subscriber', 'codi-email-scheduler')];
    }

    private static function user_meta_event_settings_fields(): array {
        return [
            'meta_key' => [
                'label'       => __('Meta key', 'codi-email-scheduler'),
                'description' => __('The user meta key to watch. Example: profile_complete.', 'codi-email-scheduler'),
                'type'        => 'text',
                'required'    => true,
            ],
            'trigger' => [
                'label'       => __('Trigger when', 'codi-email-scheduler'),
                'type'        => 'select',
                'default'     => 'becomes_truthy',
                'options'     => [
                    'changes'        => __('Value changes', 'codi-email-scheduler'),
                    'becomes_truthy' => __('Value becomes truthy', 'codi-email-scheduler'),
                    'becomes_falsy'  => __('Value becomes falsy', 'codi-email-scheduler'),
                    'becomes_value'  => __('Value becomes specific value', 'codi-email-scheduler'),
                ],
            ],
            'value' => [
                'label'       => __('Value', 'codi-email-scheduler'),
                'description' => __('Used only for “becomes specific value”.', 'codi-email-scheduler'),
                'type'        => 'text',
            ],
        ];
    }

    private static function user_meta_condition_settings_fields(): array {
        return [
            'meta_key' => [
                'label'       => __('Meta key', 'codi-email-scheduler'),
                'description' => __('The user meta key to check. Example: first_name.', 'codi-email-scheduler'),
                'type'        => 'text',
                'required'    => true,
            ],
            'comparison' => [
                'label'   => __('Comparison', 'codi-email-scheduler'),
                'type'    => 'select',
                'default' => 'truthy',
                'options' => [
                    'exists'     => __('Exists', 'codi-email-scheduler'),
                    'not_empty'  => __('Is not empty', 'codi-email-scheduler'),
                    'truthy'     => __('Is truthy', 'codi-email-scheduler'),
                    'falsy'      => __('Is falsy', 'codi-email-scheduler'),
                    'equals'     => __('Equals value', 'codi-email-scheduler'),
                    'not_equals' => __('Does not equal value', 'codi-email-scheduler'),
                ],
            ],
            'value' => [
                'label'       => __('Value', 'codi-email-scheduler'),
                'description' => __('Used only for equals / does not equal.', 'codi-email-scheduler'),
                'type'        => 'text',
            ],
        ];
    }

    private static function value_matches($actual, string $comparison, string $expected, bool $exists): bool {
        switch ($comparison) {
            case 'exists':
                return $exists;
            case 'not_empty':
                return self::scalar_string($actual) !== '';
            case 'falsy':
                return !self::is_truthy($actual);
            case 'equals':
                return self::scalar_equals($actual, $expected);
            case 'not_equals':
                return !self::scalar_equals($actual, $expected);
            case 'truthy':
            default:
                return self::is_truthy($actual);
        }
    }

    private static function is_truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }

        $value = strtolower(trim(self::scalar_string($value)));
        if ($value === '') {
            return false;
        }

        if (in_array($value, ['0', 'false', 'no', 'off', 'null', 'none'], true)) {
            return false;
        }

        return true;
    }

    private static function scalar_equals($left, $right): bool {
        return self::scalar_string($left) === self::scalar_string($right);
    }

    private static function scalar_string($value): string {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    private static function split_key_list(string $raw): array {
        $items = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $keys = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $key = sanitize_key((string) $item);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private static function meta_cache_key(int $user_id, string $meta_key): string {
        return $user_id . ':' . $meta_key;
    }
}
