<?php

defined('ABSPATH') || exit;

final class CES_Custom_Hook_Events {
    private const EVENT_KEY = 'custom_wp_hook';
    private const MAX_ACCEPTED_ARGS = 10;
    private static array $bindings = [];

    public static function init(): void {
        self::register_event();
        self::bind_configured_hooks();
    }

    private static function register_event(): void {
        if (CES_Registry::event(self::EVENT_KEY)) {
            return;
        }

        CES_Registry::register_event(self::EVENT_KEY, [
            'label'       => __('Custom WordPress hook', 'codi-email-scheduler'),
            'description' => __('Fires when a manually entered WordPress action hook runs. The configured argument must contain a valid WordPress user ID. Immediate processing requires the hook to fire during or after Action Scheduler initialization; use shutdown mode for earlier hooks.', 'codi-email-scheduler'),
            'context_keys' => array_merge(
                ['user_id', 'email', 'user_login', 'role', 'user_roles', 'blog_id', 'custom_hook_name', 'accepted_args', 'user_id_argument'],
                array_map(static fn(int $index): string => 'hook_arg_' . $index, range(1, self::MAX_ACCEPTED_ARGS))
            ),
            'conditions' => ['user_exists', 'wp_user_has_email', 'wp_user_is_site_member', 'wp_user_has_role', 'wp_user_meta_matches', 'organisation_exists', 'always'],
            'settings_fields' => [
                'hook_name' => [
                    'label'       => __('WordPress hook name', 'codi-email-scheduler'),
                    'type'        => 'text',
                    'required'    => true,
                    'description' => __('Action hook to listen to, for example my_plugin_user_ready. For immediate processing, the hook must fire during or after action_scheduler_init; earlier hooks should use shutdown mode.', 'codi-email-scheduler'),
                ],
                'accepted_args' => [
                    'label'       => __('Accepted arguments', 'codi-email-scheduler'),
                    'type'        => 'select',
                    'required'    => true,
                    'default'     => '1',
                    'options'     => self::number_options(),
                    'description' => __('Number of hook arguments WordPress should pass to the event callback.', 'codi-email-scheduler'),
                ],
                'user_id_argument' => [
                    'label'       => __('User ID argument position', 'codi-email-scheduler'),
                    'type'        => 'select',
                    'required'    => true,
                    'default'     => '1',
                    'options'     => self::number_options(),
                    'description' => __('One-based position of the hook argument containing the WordPress user ID.', 'codi-email-scheduler'),
                ],
            ],
            'settings_match_callback' => [__CLASS__, 'settings_match'],
            'instance_id_callback' => static function (string $event_key, array $context): string {
                return CES_Event_Identity::occurrence($event_key, [
                    (string) ($context['custom_hook_name'] ?? ''),
                    absint($context['user_id'] ?? 0),
                    wp_json_encode(array_intersect_key($context, array_flip(array_map(static fn(int $index): string => 'hook_arg_' . $index, range(1, self::MAX_ACCEPTED_ARGS))))),
                ]);
            },
        ]);
    }

    private static function number_options(): array {
        $options = [];
        for ($index = 1; $index <= self::MAX_ACCEPTED_ARGS; $index++) {
            $options[(string) $index] = (string) $index;
        }
        return $options;
    }

    public static function settings_match(array $settings, array $context): bool {
        return self::normalize_hook_name((string) ($settings['hook_name'] ?? '')) === (string) ($context['custom_hook_name'] ?? '')
            && absint($settings['accepted_args'] ?? 0) === absint($context['accepted_args'] ?? 0)
            && absint($settings['user_id_argument'] ?? 0) === absint($context['user_id_argument'] ?? 0);
    }

    private static function bind_configured_hooks(): void {
        foreach (CES_Storage::get_emails() as $email) {
            if (empty($email['enabled']) || ($email['event_key'] ?? '') !== self::EVENT_KEY) {
                continue;
            }

            $settings = CES_Storage::normalize_settings($email['event_settings'] ?? []);
            $hook_name = self::normalize_hook_name((string) ($settings['hook_name'] ?? ''));
            $accepted_args = absint($settings['accepted_args'] ?? 0);
            $user_id_argument = absint($settings['user_id_argument'] ?? 0);

            if ($hook_name === '' || $accepted_args < 1 || $accepted_args > self::MAX_ACCEPTED_ARGS || $user_id_argument < 1 || $user_id_argument > $accepted_args) {
                continue;
            }

            $binding_key = hash('sha256', $hook_name . '|' . $accepted_args . '|' . $user_id_argument);
            if (isset(self::$bindings[$binding_key])) {
                continue;
            }

            $callback = static function (...$hook_args) use ($hook_name, $accepted_args, $user_id_argument): void {
                $args = array_slice($hook_args, 0, $accepted_args);
                $raw_user_id = $args[$user_id_argument - 1] ?? null;
                $is_valid_integer = is_int($raw_user_id)
                    || (is_string($raw_user_id) && $raw_user_id !== '' && ctype_digit($raw_user_id));
                $user_id = $is_valid_integer ? (int) $raw_user_id : 0;
                if ($user_id < 1 || !get_user_by('id', $user_id)) {
                    do_action('ces_event_trigger_skipped', 'custom_hook_invalid_user_id', self::EVENT_KEY, [
                        'custom_hook_name' => $hook_name,
                        'user_id_argument' => $user_id_argument,
                    ], CES_Registry::event(self::EVENT_KEY), []);
                    return;
                }

                $context = [
                    'user_id'          => $user_id,
                    'custom_hook_name'  => $hook_name,
                    'accepted_args'     => $accepted_args,
                    'user_id_argument'  => $user_id_argument,
                    'source_hook'       => $hook_name,
                    'source_trigger'    => 'custom_wp_hook',
                ];

                foreach ($args as $index => $value) {
                    if (is_scalar($value) || $value === null) {
                        $context['hook_arg_' . ($index + 1)] = $value;
                    }
                }

                $result = ces_fire_event(self::EVENT_KEY, $context);
                if (is_wp_error($result)) {
                    do_action('ces_event_trigger_failed', $result, self::EVENT_KEY, CES_Registry::event(self::EVENT_KEY), [], $args);
                }
            };

            self::$bindings[$binding_key] = $callback;
            add_action($hook_name, $callback, 10, $accepted_args);
        }
    }

    public static function validate_settings(array $settings): ?WP_Error {
        $hook_name = self::normalize_hook_name((string) ($settings['hook_name'] ?? ''));
        $accepted_args = absint($settings['accepted_args'] ?? 0);
        $user_id_argument = absint($settings['user_id_argument'] ?? 0);

        if ($hook_name === '') {
            return new WP_Error('ces_invalid_custom_hook_name', __('Custom WordPress hook name must be a non-empty hook name without whitespace or control characters.', 'codi-email-scheduler'));
        }
        if ($accepted_args < 1 || $accepted_args > self::MAX_ACCEPTED_ARGS) {
            return new WP_Error('ces_invalid_custom_hook_args', __('Custom WordPress hooks must accept between 1 and 10 arguments.', 'codi-email-scheduler'));
        }
        if ($user_id_argument < 1 || $user_id_argument > $accepted_args) {
            return new WP_Error('ces_invalid_custom_hook_user_arg', __('The user ID argument position must be within the accepted argument count.', 'codi-email-scheduler'));
        }
        return null;
    }

    private static function normalize_hook_name(string $hook_name): string {
        $hook_name = trim(sanitize_text_field($hook_name));
        if ($hook_name === '' || strlen($hook_name) > 191 || preg_match('/[\x00-\x20\x7f]/', $hook_name)) {
            return '';
        }
        return $hook_name;
    }
}
