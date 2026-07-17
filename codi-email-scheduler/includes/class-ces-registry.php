<?php

defined('ABSPATH') || exit;

final class CES_Registry {
    private static array $events = [];
    private static array $conditions = [];
    private static array $tokens = [];
    private static array $event_trigger_bindings = [];
    private static array $event_trigger_dedupe = [];
    private static array $deferred_event_triggers = [];
    private static bool $shutdown_flush_registered = false;
    private static array $registration_errors = [];

    private static function registration_error(string $code, string $message, array $data = []): WP_Error {
        $error = new WP_Error(sanitize_key($code), $message, $data);
        self::$registration_errors[] = $error;
        do_action('ces_registration_error', $error, $data);
        return $error;
    }

    public static function registration_errors(): array {
        return self::$registration_errors;
    }

    public static function register_event(string $key, array $args) {
        $key = sanitize_key($key);
        if ($key === '') {
            return self::registration_error('invalid_event_key', __('Event key is required.', 'codi-email-scheduler'));
        }

        $event = wp_parse_args($args, [
            'label'                   => $key,
            'description'             => '',
            'context_keys'            => [],
            'conditions'              => [],
            'tokens'                  => [],
            'recipient_callback'      => null,
            'instance_id_callback'    => null,
            'settings_fields'         => [],
            'settings_match_callback' => null,
            'triggers'                => [],
            'trigger_dedupe_callback' => null,
            'retrospective'           => false,
            'override'                => false,
        ]);

        $override = !empty($event['override']);
        unset($event['override']);

        if (isset(self::$events[$key]) && !$override) {
            return self::definition_conflict('event', $key, self::$events[$key], $event);
        }

        if ($event['recipient_callback'] !== null && !is_callable($event['recipient_callback'])) {
            return self::registration_error('invalid_recipient_callback', __('Event recipient_callback must be callable.', 'codi-email-scheduler'), ['event_key' => $key]);
        }

        if ($event['instance_id_callback'] !== null && !is_callable($event['instance_id_callback'])) {
            return self::registration_error('invalid_instance_id_callback', __('Event instance_id_callback must be callable.', 'codi-email-scheduler'), ['event_key' => $key]);
        }

        if ($event['settings_match_callback'] !== null && !is_callable($event['settings_match_callback'])) {
            return self::registration_error('invalid_settings_match_callback', __('Event settings_match_callback must be callable.', 'codi-email-scheduler'), ['event_key' => $key]);
        }

        if ($event['trigger_dedupe_callback'] !== null && !is_callable($event['trigger_dedupe_callback'])) {
            return self::registration_error('invalid_trigger_dedupe_callback', __('Event trigger_dedupe_callback must be callable.', 'codi-email-scheduler'), ['event_key' => $key]);
        }

        $event['retrospective'] = !empty($event['retrospective']);

        $event['key'] = $key;
        $event['label'] = sanitize_text_field((string) $event['label']);
        $event['description'] = sanitize_text_field((string) $event['description']);
        $event['context_keys'] = self::sanitize_key_list((array) $event['context_keys']);
        $event['conditions'] = self::sanitize_key_list((array) $event['conditions']);

        $settings_fields = self::normalize_settings_fields((array) $event['settings_fields'], 'event', $key);
        if (is_wp_error($settings_fields)) {
            return $settings_fields;
        }
        $event['settings_fields'] = $settings_fields;

        $triggers = self::normalize_event_triggers((array) $event['triggers'], $key);
        if (is_wp_error($triggers)) {
            return $triggers;
        }
        $event['triggers'] = $triggers;

        $owned_token_definitions = self::normalize_owned_definitions((array) $event['tokens'], 'token', $key);
        if (is_wp_error($owned_token_definitions)) {
            return $owned_token_definitions;
        }
        $event['tokens'] = $owned_token_definitions;


        $owned_tokens = [];
        foreach ($event['tokens'] as $token_key => $token_args) {
            $token_args['events'] = self::sanitize_key_list(array_merge((array) ($token_args['events'] ?? []), [$key]));
            $token_args['owner_events'] = self::sanitize_key_list(array_merge((array) ($token_args['owner_events'] ?? []), [$key]));
            $allowed = self::token_registration_allowed($token_key, $token_args, $override ? $key : '');
            if (is_wp_error($allowed)) {
                return $allowed;
            }
            $owned_tokens[$token_key] = $token_args;
        }


        if ($override) {
            self::unbind_event_triggers($key);
            self::remove_event_owned_definitions($key);
        }

        foreach ($owned_tokens as $token_key => $token_args) {
            $registered = self::register_token($token_key, $token_args);
            if (is_wp_error($registered)) {
                return $registered;
            }
        }


        self::$events[$key] = $event;
        self::bind_event_triggers($key, self::$events[$key]);
        return true;
    }

    public static function register_condition(string $key, array $args) {
        $key = sanitize_key($key);
        $callback = $args['callback'] ?? null;
        if ($key === '') {
            return self::registration_error('invalid_condition_key', __('Condition key is required.', 'codi-email-scheduler'));
        }

        if (!is_callable($callback)) {
            return self::registration_error('invalid_condition_callback', __('Condition callback must be callable.', 'codi-email-scheduler'), ['condition_key' => $key]);
        }

        $condition = wp_parse_args($args, [
            'label'           => $key,
            'description'     => '',
            'requires'        => [],
            'requires_any'    => [],
            'callback'        => $callback,
            'settings_fields' => [],
            'override'        => false,
        ]);

        $override = !empty($condition['override']);
        unset($condition['override']);

        $condition['key'] = $key;
        $condition['label'] = sanitize_text_field((string) $condition['label']);
        $condition['description'] = sanitize_text_field((string) $condition['description']);
        $condition['requires'] = self::sanitize_key_list((array) $condition['requires']);
        $condition['requires_any'] = self::sanitize_key_list((array) $condition['requires_any']);

        $settings_fields = self::normalize_settings_fields((array) $condition['settings_fields'], 'condition', $key);
        if (is_wp_error($settings_fields)) {
            return $settings_fields;
        }
        $condition['settings_fields'] = $settings_fields;
        $condition['callback'] = $callback;

        if (isset(self::$conditions[$key]) && !$override) {
            return self::definition_conflict('condition', $key, self::$conditions[$key], $condition);
        }

        self::$conditions[$key] = $condition;
        return true;
    }

    private static function token_registration_allowed(string $key, array $args, string $replacing_event_key = '') {
        $override = !empty($args['override']);
        unset($args['override']);

        $token = self::normalize_token($key, $args);
        if (is_wp_error($token)) {
            return $token;
        }

        $key = $token['key'];
        $existing = self::$tokens[$key] ?? null;
        if ($existing && $replacing_event_key !== '' && in_array($replacing_event_key, (array) ($existing['owner_events'] ?? []), true)) {
            $existing = self::definition_without_event_owner($existing, $replacing_event_key);
            if (!$existing) {
                $existing = null;
            }
        }

        if (!$existing || $override || self::definitions_compatible($existing, $token)) {
            return true;
        }

        return self::definition_conflict('token', $key, $existing, $token);
    }

    public static function register_token(string $key, array $args) {
        $override = !empty($args['override']);
        unset($args['override']);

        $token = self::normalize_token($key, $args);
        if (is_wp_error($token)) {
            return $token;
        }

        $key = $token['key'];
        if (isset(self::$tokens[$key]) && !$override) {
            if (self::definitions_compatible(self::$tokens[$key], $token)) {
                self::$tokens[$key]['events'] = self::merge_definition_events(self::$tokens[$key], $token);
                self::$tokens[$key]['owner_events'] = self::merge_definition_owner_events(self::$tokens[$key], $token);
                self::$tokens[$key]['global_definition'] = !empty(self::$tokens[$key]['global_definition']) || !empty($token['global_definition']);
                return true;
            }

            return self::definition_conflict('token', $key, self::$tokens[$key], $token);
        }

        self::$tokens[$key] = $token;
        return true;
    }

    public static function events(): array {
        return self::$events;
    }

    public static function event(string $key): ?array {
        $key = sanitize_key($key);
        return self::$events[$key] ?? null;
    }

    public static function conditions(): array {
        return self::$conditions;
    }

    public static function condition(string $key): ?array {
        $key = sanitize_key($key);
        return self::$conditions[$key] ?? null;
    }

    public static function available_conditions_for_event(string $event_key): array {
        $event = self::event($event_key);
        $conditions = self::conditions();

        if (!$event) {
            return $conditions;
        }

        $context_keys = self::event_context_keys($event);
        $suggested_keys = self::sanitize_key_list((array) ($event['conditions'] ?? []));
        $available = [];

        foreach ($suggested_keys as $condition_key) {
            if (isset($conditions[$condition_key])) {
                $available[$condition_key] = $conditions[$condition_key];
            }
        }

        foreach ($conditions as $condition_key => $condition) {
            if (self::definition_matches_context($condition, $context_keys)) {
                $available[$condition_key] = $condition;
            }
        }

        return $available;
    }

    public static function tokens(): array {
        return self::$tokens;
    }

    public static function token(string $key): ?array {
        $key = sanitize_key($key);
        return self::$tokens[$key] ?? null;
    }

    public static function available_tokens_for_event(string $event_key): array {
        $event_key = sanitize_key($event_key);
        $event = self::event($event_key);
        $tokens = self::tokens();

        if (!$event) {
            return $tokens;
        }

        $context_keys = self::event_context_keys($event);
        $available = [];

        foreach ($tokens as $token_key => $token) {
            if (self::definition_matches_event($token, $event_key) || self::definition_matches_context($token, $context_keys)) {
                $available[$token_key] = $token;
            }
        }

        return $available;
    }

    public static function event_context_keys(array $event): array {
        $event_key = sanitize_key((string) ($event['key'] ?? ''));
        $keys = array_merge(
            self::sanitize_key_list((array) ($event['context_keys'] ?? [])),
            self::standard_context_keys()
        );

        if ($event_key !== '') {
            foreach (self::$tokens as $token) {
                if (!self::definition_matches_event($token, $event_key)) {
                    continue;
                }

                $keys[] = (string) ($token['key'] ?? '');
                $keys = array_merge($keys, (array) ($token['requires'] ?? []), (array) ($token['requires_any'] ?? []));
            }

        }

        return self::sanitize_key_list($keys);
    }

    public static function standard_context_keys(): array {
        return [
            'event_key',
            'event_instance_id',
            'event_time_gmt',
            'event_timestamp',
            'blog_id',
            'source_hook',
            'source_trigger',
        ];
    }

    private static function normalize_event_triggers(array $triggers, string $event_key = '') {
        $normalized = [];

        foreach ($triggers as $index => $trigger) {
            if (!is_array($trigger)) {
                return self::registration_error('invalid_event_trigger', __('Event triggers must be arrays.', 'codi-email-scheduler'), [
                    'event_key'     => sanitize_key($event_key),
                    'trigger_index' => $index,
                ]);
            }

            $hook = trim((string) ($trigger['hook'] ?? ''));
            $callback = $trigger['context_callback'] ?? null;
            if ($hook === '' || !is_callable($callback)) {
                return self::registration_error('invalid_event_trigger', __('Event triggers require a hook and callable context_callback.', 'codi-email-scheduler'), [
                    'event_key'     => sanitize_key($event_key),
                    'trigger_index' => $index,
                ]);
            }

            $accepted_args = isset($trigger['accepted_args']) ? absint($trigger['accepted_args']) : 1;
            $priority = isset($trigger['priority']) ? (int) $trigger['priority'] : 10;

            $normalized[] = [
                'key'              => sanitize_key((string) ($trigger['key'] ?? ('trigger_' . $index))),
                'hook'             => $hook,
                'priority'         => $priority,
                'accepted_args'    => $accepted_args,
                'context_callback' => $callback,
            ];
        }

        return array_values($normalized);
    }

    private static function bind_event_triggers(string $event_key, array $event): void {
        foreach ((array) ($event['triggers'] ?? []) as $index => $trigger) {
            $hook = (string) ($trigger['hook'] ?? '');
            $priority = (int) ($trigger['priority'] ?? 10);
            $accepted_args = absint($trigger['accepted_args'] ?? 1);
            $binding_key = $event_key . ':' . $index;

            if ($hook === '' || isset(self::$event_trigger_bindings[$binding_key])) {
                continue;
            }

            $callback = static function (...$hook_args) use ($event_key, $index): void {
                self::handle_event_trigger($event_key, $index, $hook_args);
            };

            self::$event_trigger_bindings[$binding_key] = [
                'event_key'     => $event_key,
                'hook'          => $hook,
                'priority'      => $priority,
                'accepted_args' => $accepted_args,
                'callback'      => $callback,
            ];

            add_action($hook, $callback, $priority, $accepted_args);
        }
    }

    private static function unbind_event_triggers(string $event_key): void {
        $event_key = sanitize_key($event_key);
        if ($event_key === '') {
            return;
        }

        foreach (self::$event_trigger_bindings as $binding_key => $binding) {
            if (($binding['event_key'] ?? '') !== $event_key) {
                continue;
            }

            if (!empty($binding['hook']) && !empty($binding['callback'])) {
                remove_action((string) $binding['hook'], $binding['callback'], (int) ($binding['priority'] ?? 10));
            }

            unset(self::$event_trigger_bindings[$binding_key]);
        }
    }

    private static function handle_event_trigger(string $event_key, int $trigger_index, array $hook_args): void {
        $event = self::event($event_key);
        if (!$event) {
            return;
        }

        $trigger = $event['triggers'][$trigger_index] ?? null;
        if (!is_array($trigger) || empty($trigger['context_callback']) || !is_callable($trigger['context_callback'])) {
            return;
        }

        $accepted_args = absint($trigger['accepted_args'] ?? 0);
        $callback_args = $accepted_args > 0 ? array_slice($hook_args, 0, $accepted_args) : [];
        $context = self::call_callback($trigger['context_callback'], $callback_args, 'trigger_context_callback_failed');

        if (is_wp_error($context)) {
            do_action('ces_event_trigger_failed', $context, $event_key, $event, $trigger, $hook_args);
            return;
        }

        if ($context === null || $context === false) {
            do_action('ces_event_trigger_skipped', 'trigger_context_empty', $event_key, [], $event, $trigger);
            return;
        }

        if (!is_array($context)) {
            $error = new WP_Error(
                'trigger_context_invalid_type',
                __('Event trigger context callback returned an invalid value. Expected array, null, false, or WP_Error.', 'codi-email-scheduler'),
                ['return_type' => gettype($context)]
            );
            do_action('ces_event_trigger_failed', $error, $event_key, $event, $trigger, $hook_args);
            return;
        }

        $context['source_hook'] = $context['source_hook'] ?? (string) ($trigger['hook'] ?? '');
        $context['source_trigger'] = $context['source_trigger'] ?? sanitize_key((string) ($trigger['key'] ?? ('trigger_' . $trigger_index)));

        do_action('ces_event_trigger_observed', $event_key, $context, $event, $trigger, $hook_args);

        if (CES_Storage::has_enabled_email_for_event($event_key, 'immediate')) {
            self::process_event_trigger($event_key, $context, $event, $trigger, $hook_args, null, 'immediate');
        }
        if (CES_Storage::has_enabled_email_for_event($event_key, 'shutdown')) {
            self::defer_event_trigger($event_key, $context, $event, $trigger, $hook_args);
        }
    }

    private static function defer_event_trigger(string $event_key, array $context, array $event, array $trigger, array $hook_args): void {
        $dedupe = self::trigger_dedupe_key($event_key, $context, $event, $trigger);
        if (is_wp_error($dedupe)) {
            do_action('ces_event_trigger_failed', $dedupe, $event_key, $event, $trigger, $hook_args);
            return;
        }

        $buffer_key = $dedupe !== ''
            ? $dedupe
            : $event_key . ':' . hash('sha256', wp_json_encode([$context, $trigger['key'] ?? '']));

        self::$deferred_event_triggers[$buffer_key] = [$event_key, $context, $event, $trigger, $hook_args, $dedupe];

        if (!self::$shutdown_flush_registered) {
            self::$shutdown_flush_registered = true;
            add_action('shutdown', [__CLASS__, 'flush_deferred_event_triggers'], PHP_INT_MAX);
        }
    }

    public static function flush_deferred_event_triggers(): void {
        $pending = self::$deferred_event_triggers;
        self::$deferred_event_triggers = [];
        self::$shutdown_flush_registered = false;

        foreach ($pending as $item) {
            [$event_key, $context, $event, $trigger, $hook_args, $dedupe_key] = $item;
            self::process_event_trigger($event_key, $context, $event, $trigger, $hook_args, $dedupe_key, 'shutdown');
        }
    }

    private static function process_event_trigger(string $event_key, array $context, array $event, array $trigger, array $hook_args, $known_dedupe_key = null, string $processing_mode = 'immediate'): void {
        $dedupe_key = $known_dedupe_key !== null
            ? $known_dedupe_key
            : self::trigger_dedupe_key($event_key, $context, $event, $trigger);
        if (is_wp_error($dedupe_key)) {
            do_action('ces_event_trigger_failed', $dedupe_key, $event_key, $event, $trigger, $hook_args);
            return;
        }

        if ($dedupe_key !== '') {
            $dedupe_key .= ':' . $processing_mode;
            if (isset(self::$event_trigger_dedupe[$dedupe_key])) {
                do_action('ces_event_trigger_skipped', 'trigger_dedupe_suppressed', $event_key, $context, $event, $trigger);
                return;
            }
            self::$event_trigger_dedupe[$dedupe_key] = true;
        }

        $result = CES_Engine::fire_event_for_mode($event_key, $context, $processing_mode);
        if (is_wp_error($result)) {
            do_action('ces_event_trigger_failed', $result, $event_key, $event, $trigger, $hook_args);
        } elseif (!$result) {
            do_action('ces_event_trigger_scheduled_zero_actions', $event_key, $context, $event, $trigger);
        }

        do_action('ces_event_trigger_fired', $event_key, $context, $result, $event, $trigger);
    }

    private static function trigger_dedupe_key(string $event_key, array $context, array $event, array $trigger) {
        if (empty($event['trigger_dedupe_callback']) || !is_callable($event['trigger_dedupe_callback'])) {
            return '';
        }

        $dedupe_key = self::call_callback($event['trigger_dedupe_callback'], [$context, $event, $trigger], 'trigger_dedupe_callback_failed');
        if (is_wp_error($dedupe_key)) {
            return $dedupe_key;
        }

        if ($dedupe_key === null) {
            return '';
        }

        if (!is_scalar($dedupe_key) || is_bool($dedupe_key) || trim((string) $dedupe_key) === '') {
            return new WP_Error(
                'trigger_dedupe_callback_invalid_value',
                __('Event trigger dedupe callback returned an invalid value. Expected null or a non-empty scalar.', 'codi-email-scheduler'),
                ['return_type' => gettype($dedupe_key)]
            );
        }

        return $event_key . ':' . trim((string) $dedupe_key);
    }

    public static function register_core_items(): void {
        self::register_condition('always', [
            'label'       => __('Always', 'codi-email-scheduler'),
            'description' => __('Always passes. Use this when the event itself is enough.', 'codi-email-scheduler'),
            'callback'    => static function (): bool {
                return true;
            },
        ]);

        self::register_condition('user_exists', [
            'label'       => __('User exists', 'codi-email-scheduler'),
            'description' => __('Passes when the context contains a valid user_id.', 'codi-email-scheduler'),
            'requires'    => ['user_id'],
            'callback'    => static function (array $context): bool {
                $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
                return $user_id > 0 && (bool) get_user_by('id', $user_id);
            },
        ]);


        $role_options = [];
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
                $role_options[$role_key] = sprintf('%s (%s)', $role_name, $role_key);
            }
        }

        if (!$role_options) {
            $role_options['subscriber'] = __('Subscriber', 'codi-email-scheduler');
        }

        self::register_condition('wp_user_has_role', [
            'label'        => __('User has role', 'codi-email-scheduler'),
            'description'  => __('Passes when the user currently has the selected WordPress role. Use “Must be false” to require that the role is absent.', 'codi-email-scheduler'),
            'requires_any' => ['user_id', 'role', 'user_roles'],
            'settings_fields' => [
                'role' => [
                    'label'       => __('WordPress role', 'codi-email-scheduler'),
                    'type'        => 'select',
                    'options'     => $role_options,
                    'required'    => true,
                    'description' => __('Choose an installed WordPress role.', 'codi-email-scheduler'),
                ],
            ],
            'callback' => static function (array $context, array $email, array $runtime) {
                $settings = CES_Storage::normalize_settings($runtime['condition_settings'] ?? []);
                $expected = sanitize_key((string) ($settings['role'] ?? ''));
                if ($expected === '') {
                    return new WP_Error('wp_user_role_required', __('A WordPress role is required.', 'codi-email-scheduler'));
                }

                $roles = CES_Context::user_roles($context);
                return [
                    'passed'   => in_array($expected, $roles, true),
                    'reason'   => 'wp_user_role_checked',
                    'actual'   => $roles,
                    'expected' => $expected,
                ];
            },
        ]);

        self::register_condition('callable_check', [
            'label'       => __('Callable check', 'codi-email-scheduler'),
            'description' => __('Calls a trusted global function or static class method and requires a boolean result. Use “Must be false” to invert the result.', 'codi-email-scheduler'),
            'settings_fields' => [
                'callable' => [
                    'label'       => __('Function or static method', 'codi-email-scheduler'),
                    'type'        => 'text',
                    'required'    => true,
                    'description' => __('Examples: organisation_has_active_order or Organisation_Service::has_active_order.', 'codi-email-scheduler'),
                ],
                'pass_user_id' => [
                    'label'       => __('Pass user ID', 'codi-email-scheduler'),
                    'type'        => 'select',
                    'required'    => true,
                    'default'     => 'yes',
                    'options'     => [
                        'yes' => __('Yes', 'codi-email-scheduler'),
                        'no'  => __('No', 'codi-email-scheduler'),
                    ],
                    'description' => __('When enabled, the evaluated event user ID is passed as the only argument.', 'codi-email-scheduler'),
                ],
            ],
            'callback' => static function (array $context, array $email, array $runtime) {
                $settings = CES_Storage::normalize_settings($runtime['condition_settings'] ?? []);
                $pass_user_id = (($settings['pass_user_id'] ?? 'yes') === 'yes');
                $result = CES_Callable_Condition::execute(
                    (string) ($settings['callable'] ?? ''),
                    $pass_user_id,
                    absint($context['user_id'] ?? 0)
                );
                if (is_wp_error($result)) {
                    return $result;
                }
                return [
                    'passed'   => $result,
                    'reason'   => 'callable_checked',
                    'actual'   => $result,
                    'expected' => true,
                ];
            },
        ]);

        self::register_condition('custom_sql_scalar', [
            'label'        => __('Custom SQL scalar check', 'codi-email-scheduler'),
            'description'  => __('Runs one read-only SELECT query and compares the first column of the first row. Available placeholders: {user_id}, {prefix}, {base_prefix}.', 'codi-email-scheduler'),
            'requires'     => ['user_id'],
            'settings_fields' => [
                'sql' => [
                    'label'       => __('SQL SELECT query', 'codi-email-scheduler'),
                    'type'        => 'textarea',
                    'required'    => true,
                    'description' => __('The query must be a single read-only SELECT. It runs once per recipient, so filter and join columns should be indexed. It is tested on save using your user ID only to verify that it executes. During retrospective enrollment it runs once per candidate, and it is checked again before delivery.', 'codi-email-scheduler'),
                ],
                'comparison' => [
                    'label'    => __('Comparison', 'codi-email-scheduler'),
                    'type'     => 'select',
                    'required' => true,
                    'default'  => 'truthy',
                    'options'  => [
                        'truthy'       => __('Result is truthy', 'codi-email-scheduler'),
                        'falsy'        => __('Result is falsy', 'codi-email-scheduler'),
                        'equals'       => __('Result equals value', 'codi-email-scheduler'),
                        'not_equals'   => __('Result does not equal value', 'codi-email-scheduler'),
                        'greater_than' => __('Result is greater than value', 'codi-email-scheduler'),
                        'less_than'    => __('Result is less than value', 'codi-email-scheduler'),
                        'is_null'      => __('Result is NULL / no row', 'codi-email-scheduler'),
                        'not_null'     => __('Result is not NULL', 'codi-email-scheduler'),
                    ],
                ],
                'expected' => [
                    'label'       => __('Comparison value', 'codi-email-scheduler'),
                    'type'        => 'text',
                    'description' => __('Used only by equals, does not equal, greater than, and less than comparisons.', 'codi-email-scheduler'),
                ],
            ],
            'callback' => static function (array $context, array $email, array $runtime) {
                $settings = CES_Storage::normalize_settings($runtime['condition_settings'] ?? []);
                $user_id = absint($context['user_id'] ?? 0);
                if (!$user_id) {
                    return new WP_Error('ces_sql_user_required', __('The custom SQL condition requires a user_id.', 'codi-email-scheduler'));
                }
                $actual = CES_Custom_SQL_Condition::execute((string) ($settings['sql'] ?? ''), $user_id);
                if (is_wp_error($actual)) {
                    return $actual;
                }
                $comparison = sanitize_key((string) ($settings['comparison'] ?? 'truthy'));
                $expected = (string) ($settings['expected'] ?? '');
                return [
                    'passed'   => CES_Custom_SQL_Condition::compare($actual, $comparison, $expected),
                    'reason'   => 'custom_sql_scalar_compared',
                    'actual'   => $actual === null ? null : '[scalar]',
                    'expected' => $expected === '' ? null : '[configured]',
                ];
            },
        ]);

        self::register_token('user_role', [
            'label'        => __('User role', 'codi-email-scheduler'),
            'requires_any' => ['role', 'user_roles', 'user_id'],
            'callback'     => static function (array $context): string {
                $roles = CES_Context::user_roles($context);
                return $roles[0] ?? '';
            },
        ]);

        self::register_token('user_roles', [
            'label'        => __('User roles', 'codi-email-scheduler'),
            'requires_any' => ['user_roles', 'user_id', 'role'],
            'callback'     => static function (array $context): string {
                return implode(', ', CES_Context::user_roles($context));
            },
        ]);

        self::register_token('user_email', [
            'label'        => __('User email', 'codi-email-scheduler'),
            'escape'       => 'text',
            'requires_any' => ['user_id', 'email'],
            'callback'     => static function (array $context): string {
                $user = self::user_from_context($context);
                return $user ? (string) $user->user_email : (string) ($context['email'] ?? '');
            },
        ]);

        self::register_token('first_name', [
            'label'    => __('First name', 'codi-email-scheduler'),
            'requires' => ['user_id'],
            'callback' => static function (array $context): string {
                $user = self::user_from_context($context);
                return $user ? (string) get_user_meta((int) $user->ID, 'first_name', true) : '';
            },
        ]);

        self::register_token('last_name', [
            'label'    => __('Last name', 'codi-email-scheduler'),
            'requires' => ['user_id'],
            'callback' => static function (array $context): string {
                $user = self::user_from_context($context);
                return $user ? (string) get_user_meta((int) $user->ID, 'last_name', true) : '';
            },
        ]);

        self::register_token('display_name', [
            'label'    => __('Display name', 'codi-email-scheduler'),
            'requires' => ['user_id'],
            'callback' => static function (array $context): string {
                $user = self::user_from_context($context);
                return $user ? (string) $user->display_name : '';
            },
        ]);

        self::register_token('site_name', [
            'label'    => __('Site name', 'codi-email-scheduler'),
            'callback' => static function (): string {
                return get_bloginfo('name');
            },
        ]);

        self::register_token('site_url', [
            'label'    => __('Site URL', 'codi-email-scheduler'),
            'escape'   => 'url',
            'callback' => static function (): string {
                return home_url('/');
            },
        ]);

        self::register_token('account_url', [
            'label'    => __('Account URL', 'codi-email-scheduler'),
            'escape'   => 'url',
            'callback' => static function (): string {
                return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : wp_login_url();
            },
        ]);

        self::register_token('event_time_gmt', [
            'label'    => __('Event time GMT', 'codi-email-scheduler'),
            'requires' => ['event_time_gmt'],
        ]);

        self::register_token('organisation_id', [
            'label'        => __('Organisation ID', 'codi-email-scheduler'),
            'requires_any' => ['organisation_id', 'user_id'],
            'callback'     => static function (array $context): string {
                return isset($context['organisation_id']) ? (string) absint($context['organisation_id']) : '';
            },
        ]);

        self::register_token('organisation_name', [
            'label'        => __('Organisation name', 'codi-email-scheduler'),
            'requires_any' => ['organisation_name', 'organisation_id', 'user_id'],
            'callback'     => static function (array $context): string {
                return isset($context['organisation_name']) ? (string) $context['organisation_name'] : '';
            },
        ]);


        self::register_token('meta_key', [
            'label'    => __('Meta key', 'codi-email-scheduler'),
            'requires' => ['meta_key'],
        ]);

        self::register_token('meta_value', [
            'label'    => __('Meta value', 'codi-email-scheduler'),
            'requires' => ['meta_value'],
        ]);

        self::register_token('previous_meta_value', [
            'label'    => __('Previous meta value', 'codi-email-scheduler'),
            'requires' => ['previous_meta_value'],
        ]);

        self::register_token('meta_change_type', [
            'label'    => __('Meta change type', 'codi-email-scheduler'),
            'requires' => ['meta_change_type'],
        ]);
    }

    private static function normalize_owned_definitions(array $definitions, string $definition_type, string $event_key) {
        $normalized = [];
        $definition_type = sanitize_key($definition_type);
        $event_key = sanitize_key($event_key);

        foreach ($definitions as $key => $definition) {
            if (!is_array($definition)) {
                return self::registration_error('invalid_event_owned_definition', __('Event-owned definitions must be arrays.', 'codi-email-scheduler'), [
                    'event_key'       => $event_key,
                    'definition_type' => $definition_type,
                    'definition_key'  => is_scalar($key) ? (string) $key : '',
                ]);
            }

            $definition_key = sanitize_key(is_string($key) ? $key : (string) ($definition['key'] ?? ''));
            if ($definition_key === '') {
                return self::registration_error('invalid_event_owned_definition_key', __('Event-owned definitions require a valid key.', 'codi-email-scheduler'), [
                    'event_key'       => $event_key,
                    'definition_type' => $definition_type,
                ]);
            }

            unset($definition['key']);
            $normalized[$definition_key] = $definition;
        }

        return $normalized;
    }

    private static function normalize_token(string $key, array $args) {
        $key = sanitize_key($key);
        if ($key === '') {
            return self::registration_error('invalid_token_key', __('Token key is required.', 'codi-email-scheduler'));
        }

        $token = wp_parse_args($args, [
            'label'        => $key,
            'description'  => '',
            'requires'     => [],
            'requires_any' => [],
            'events'       => [],
            'owner_events' => [],
            'callback'     => null,
            'escape'       => 'html',
        ]);

        $escape = sanitize_key((string) $token['escape']);
        if (!in_array($escape, ['html', 'text', 'url', 'raw'], true)) {
            return self::registration_error('invalid_token_escape', __('Token escape must be html, text, url, or raw.', 'codi-email-scheduler'), [
                'token_key' => $key,
                'escape'    => is_scalar($token['escape']) ? (string) $token['escape'] : gettype($token['escape']),
            ]);
        }
        $uses_default_callback = $token['callback'] === null;

        $token['key'] = $key;
        $token['label'] = sanitize_text_field((string) $token['label']);
        $token['description'] = sanitize_text_field((string) $token['description']);
        $token['requires'] = self::sanitize_key_list((array) $token['requires']);
        $token['requires_any'] = self::sanitize_key_list((array) $token['requires_any']);
        $token['events'] = self::sanitize_key_list((array) $token['events']);
        $token['owner_events'] = self::sanitize_key_list((array) $token['owner_events']);
        $token['global_definition'] = empty($token['owner_events']);
        $token['escape'] = $escape;
        $token['callback_source'] = $uses_default_callback ? 'default_context' : 'custom';

        if ($token['callback'] !== null && !is_callable($token['callback'])) {
            return self::registration_error('invalid_token_callback', __('Token callback must be callable.', 'codi-email-scheduler'), ['token_key' => $key]);
        }

        if ($token['callback'] === null) {
            $token['callback'] = static function (array $context) use ($key): string {
                $value = $context[$key] ?? '';
                return (is_scalar($value) || $value === null) ? (string) $value : '';
            };
        }

        return $token;
    }

    private static function definitions_compatible(array $existing, array $incoming): bool {
        unset($existing['events'], $incoming['events'], $existing['owner_events'], $incoming['owner_events'], $existing['global_definition'], $incoming['global_definition']);
        return self::definition_signature($existing) === self::definition_signature($incoming);
    }

    private static function definition_without_event_owner(array $definition, string $event_key): ?array {
        $event_key = sanitize_key($event_key);
        if ($event_key === '') {
            return $definition;
        }

        $definition['events'] = array_values(array_diff(self::sanitize_key_list((array) ($definition['events'] ?? [])), [$event_key]));
        $definition['owner_events'] = array_values(array_diff(self::sanitize_key_list((array) ($definition['owner_events'] ?? [])), [$event_key]));

        if (empty($definition['events']) && empty($definition['owner_events']) && empty($definition['global_definition'])) {
            return null;
        }

        return $definition;
    }

    private static function remove_event_owned_definitions(string $event_key): void {
        $event_key = sanitize_key($event_key);
        if ($event_key === '') {
            return;
        }

        foreach (self::$tokens as $token_key => $token) {
            if (!in_array($event_key, (array) ($token['owner_events'] ?? []), true)) {
                continue;
            }

            $updated = self::definition_without_event_owner($token, $event_key);
            if ($updated) {
                self::$tokens[$token_key] = $updated;
            } else {
                unset(self::$tokens[$token_key]);
            }
        }

    }

    private static function definition_signature(array $definition): array {
        if (($definition['callback_source'] ?? '') === 'default_context') {
            $definition['callback'] = 'default_context_callback';
        } elseif (array_key_exists('callback', $definition)) {
            $definition['callback'] = self::callback_signature($definition['callback']);
        }
        if (array_key_exists('recipient_callback', $definition)) {
            $definition['recipient_callback'] = self::callback_signature($definition['recipient_callback']);
        }
        if (array_key_exists('instance_id_callback', $definition)) {
            $definition['instance_id_callback'] = self::callback_signature($definition['instance_id_callback']);
        }
        if (array_key_exists('settings_match_callback', $definition)) {
            $definition['settings_match_callback'] = self::callback_signature($definition['settings_match_callback']);
        }
        if (array_key_exists('trigger_dedupe_callback', $definition)) {
            $definition['trigger_dedupe_callback'] = self::callback_signature($definition['trigger_dedupe_callback']);
        }

        ksort($definition);
        return $definition;
    }

    private static function callback_signature($callback): string {
        if ($callback === null) {
            return 'null';
        }

        if ($callback instanceof Closure) {
            return 'closure:' . spl_object_hash($callback);
        }

        if (is_string($callback)) {
            return 'function:' . strtolower($callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            $target = $callback[0];
            $method = (string) $callback[1];
            if (is_object($target)) {
                return 'object:' . spl_object_hash($target) . '::' . strtolower($method);
            }
            return 'class:' . strtolower((string) $target) . '::' . strtolower($method);
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return 'invokable:' . spl_object_hash($callback);
        }

        return gettype($callback);
    }

    private static function merge_definition_events(array $existing, array $incoming): array {
        return self::sanitize_key_list(array_merge((array) ($existing['events'] ?? []), (array) ($incoming['events'] ?? [])));
    }

    private static function merge_definition_owner_events(array $existing, array $incoming): array {
        return self::sanitize_key_list(array_merge((array) ($existing['owner_events'] ?? []), (array) ($incoming['owner_events'] ?? [])));
    }

    private static function definition_conflict(string $type, string $key, array $existing, array $incoming): WP_Error {
        $type = sanitize_key($type);
        $key = sanitize_key($key);
        $error = self::registration_error($type . '_definition_conflict', sprintf(__('A %1$s definition is already registered for key %2$s.', 'codi-email-scheduler'), $type, $key), [
            'definition_type' => $type,
            'definition_key'  => $key,
            'existing'        => $existing,
            'incoming'        => $incoming,
        ]);
        do_action('ces_registry_definition_conflict', $type, $key, $existing, $incoming, $error);
        return $error;
    }

    public static function call_callback($callback, array $args, string $error_code) {
        if (!is_callable($callback)) {
            return new WP_Error(sanitize_key($error_code), __('Extension callback is not callable.', 'codi-email-scheduler'));
        }

        try {
            return call_user_func_array($callback, $args);
        } catch (Throwable $e) {
            return new WP_Error(sanitize_key($error_code), $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_code'  => $e->getCode(),
            ]);
        }
    }

    private static function definition_matches_event(array $definition, string $event_key): bool {
        $event_key = sanitize_key($event_key);
        $events = self::sanitize_key_list((array) ($definition['events'] ?? []));
        return $event_key !== '' && $events && in_array($event_key, $events, true);
    }

    private static function definition_matches_context(array $definition, array $context_keys): bool {
        $requires = self::sanitize_key_list((array) ($definition['requires'] ?? []));
        $requires_any = self::sanitize_key_list((array) ($definition['requires_any'] ?? []));

        if ($requires && array_diff($requires, $context_keys)) {
            return false;
        }

        if ($requires_any && !array_intersect($requires_any, $context_keys)) {
            return false;
        }

        return true;
    }

    public static function normalize_settings_fields(array $fields, string $definition_type = 'definition', string $definition_key = '') {
        $normalized = [];
        $definition_type = sanitize_key($definition_type);
        $definition_key = sanitize_key($definition_key);

        foreach ($fields as $key => $field) {
            $field_key = sanitize_key((string) $key);
            if ($field_key === '') {
                return self::registration_error('invalid_settings_field_key', __('Settings fields require valid keys.', 'codi-email-scheduler'), [
                    'definition_type' => $definition_type,
                    'definition_key'  => $definition_key,
                ]);
            }

            if (!is_array($field)) {
                return self::registration_error('invalid_settings_field_definition', __('Settings field definitions must be arrays.', 'codi-email-scheduler'), [
                    'definition_type' => $definition_type,
                    'definition_key'  => $definition_key,
                    'field_key'       => $field_key,
                ]);
            }

            $type = sanitize_key((string) ($field['type'] ?? 'text'));
            if (!in_array($type, ['text', 'select', 'textarea'], true)) {
                return self::registration_error('invalid_settings_field_type', __('Settings field type must be text, textarea, or select.', 'codi-email-scheduler'), [
                    'definition_type' => $definition_type,
                    'definition_key'  => $definition_key,
                    'field_key'       => $field_key,
                    'field_type'      => is_scalar($field['type'] ?? null) ? (string) ($field['type'] ?? '') : gettype($field['type'] ?? null),
                ]);
            }

            $options = [];
            if (!empty($field['options'])) {
                if (!is_array($field['options'])) {
                    return self::registration_error('invalid_settings_field_options', __('Settings field options must be an array.', 'codi-email-scheduler'), [
                        'definition_type' => $definition_type,
                        'definition_key'  => $definition_key,
                        'field_key'       => $field_key,
                    ]);
                }

                foreach ($field['options'] as $option_value => $option_label) {
                    $option_value = sanitize_key((string) $option_value);
                    if ($option_value === '') {
                        return self::registration_error('invalid_settings_field_option_key', __('Settings field options require valid keys.', 'codi-email-scheduler'), [
                            'definition_type' => $definition_type,
                            'definition_key'  => $definition_key,
                            'field_key'       => $field_key,
                        ]);
                    }
                    $options[$option_value] = sanitize_text_field((string) $option_label);
                }
            }

            if ($type === 'select' && !$options) {
                return self::registration_error('invalid_settings_field_select_options', __('Select settings fields require at least one option.', 'codi-email-scheduler'), [
                    'definition_type' => $definition_type,
                    'definition_key'  => $definition_key,
                    'field_key'       => $field_key,
                ]);
            }

            $normalized[$field_key] = [
                'key'         => $field_key,
                'label'       => sanitize_text_field((string) ($field['label'] ?? $field_key)),
                'description' => sanitize_text_field((string) ($field['description'] ?? '')),
                'type'        => $type,
                'default'     => sanitize_text_field((string) ($field['default'] ?? '')),
                'options'     => $options,
                'required'    => !empty($field['required']),
            ];
        }

        return $normalized;
    }

    private static function sanitize_key_list(array $items): array {
        $keys = [];

        foreach ($items as $item) {
            $key = sanitize_key((string) $item);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private static function user_from_context(array $context): ?WP_User {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        if (!$user_id) {
            return null;
        }

        $user = get_user_by('id', $user_id);
        return $user instanceof WP_User ? $user : null;
    }
}
