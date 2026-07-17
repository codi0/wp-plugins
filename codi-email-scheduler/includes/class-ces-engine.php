<?php

defined('ABSPATH') || exit;

final class CES_Engine {
    private static $deferred_manual_events = [];
    private static $manual_shutdown_flush_registered = false;

    public static function init(): void {
        add_action(CES_AS_ACTION, [__CLASS__, 'run_scheduled_email'], 10, 1);
    }

    public static function action_scheduler_available(): bool {
        return self::action_scheduler_status() === 'ready';
    }

    public static function action_scheduler_status(): string {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_enqueue_async_action')) {
            return did_action('plugins_loaded') ? 'functions_unavailable' : 'not_initialized_yet';
        }

        if (!class_exists('ActionScheduler_Versions') || !method_exists('ActionScheduler_Versions', 'instance')) {
            return 'version_unavailable';
        }

        $versions = ActionScheduler_Versions::instance();
        if (!is_object($versions) || !method_exists($versions, 'latest_version')) {
            return 'version_unavailable';
        }

        $version = (string) $versions->latest_version();
        if ($version === '' || version_compare($version, CES_ACTION_SCHEDULER_MIN_VERSION, '<')) {
            return 'version_unsupported';
        }

        if (!class_exists('ActionScheduler') || !method_exists('ActionScheduler', 'is_initialized')) {
            return 'functions_unavailable';
        }

        if (!ActionScheduler::is_initialized()) {
            return (did_action('action_scheduler_init') || did_action('init')) ? 'not_initialized' : 'not_initialized_yet';
        }

        return 'ready';
    }

    public static function action_scheduler_status_label(): string {
        switch (self::action_scheduler_status()) {
            case 'ready':
                return __('Available', 'codi-email-scheduler');
            case 'functions_unavailable':
                return __('Required plugin not loaded', 'codi-email-scheduler');
            case 'version_unavailable':
                return __('Version unavailable', 'codi-email-scheduler');
            case 'version_unsupported':
                return __('Update required', 'codi-email-scheduler');
            case 'not_initialized_yet':
                return __('Initializing', 'codi-email-scheduler');
            case 'not_initialized':
                return __('Not initialized', 'codi-email-scheduler');
            default:
                return __('Unavailable', 'codi-email-scheduler');
        }
    }

    private static function action_scheduler_status_error_code(string $status): string {
        return $status === 'not_initialized_yet'
            ? 'ces_action_scheduler_not_initialized_yet'
            : 'ces_action_scheduler_dependency_unavailable';
    }

    private static function action_scheduler_status_message(string $status): string {
        switch ($status) {
            case 'functions_unavailable':
                return __('Action Scheduler is required, but its public API is unavailable in this request.', 'codi-email-scheduler');
            case 'version_unavailable':
                return __('Action Scheduler is active, but its version could not be determined.', 'codi-email-scheduler');
            case 'version_unsupported':
                return sprintf(
                    __('Action Scheduler %s or newer is required.', 'codi-email-scheduler'),
                    CES_ACTION_SCHEDULER_MIN_VERSION
                );
            case 'not_initialized':
                return __('Action Scheduler is loaded but did not initialize in this request.', 'codi-email-scheduler');
            case 'not_initialized_yet':
                return __('Action Scheduler is not initialized yet. Fire CES events during or after action_scheduler_init.', 'codi-email-scheduler');
            default:
                return __('Action Scheduler is unavailable.', 'codi-email-scheduler');
        }
    }


    /**
     * Fire a manual/custom event, processing immediate emails now and deferring
     * shutdown-configured emails until WordPress shutdown.
     *
     * @return array|WP_Error Scheduled immediate Action Scheduler IDs, or WP_Error.
     */
    public static function fire_event(string $event_key, array $context = []) {
        $target_blog_id = isset($context['blog_id']) ? absint($context['blog_id']) : 0;
        if (is_multisite() && $target_blog_id > 0 && $target_blog_id !== get_current_blog_id() && function_exists('switch_to_blog')) {
            switch_to_blog($target_blog_id);
            try {
                return self::fire_event($event_key, $context);
            } finally {
                restore_current_blog();
            }
        }

        $result = self::fire_event_for_mode($event_key, $context, 'immediate');
        if (is_wp_error($result)) {
            return $result;
        }

        $normalized_event_key = sanitize_key($event_key);
        if (CES_Storage::has_enabled_email_for_event($normalized_event_key, 'shutdown')) {
            self::$deferred_manual_events[] = [$normalized_event_key, $context];

            if (!self::$manual_shutdown_flush_registered) {
                self::$manual_shutdown_flush_registered = true;
                add_action('shutdown', [__CLASS__, 'flush_deferred_manual_events'], PHP_INT_MAX);
            }
        }

        return $result;
    }

    public static function flush_deferred_manual_events(): void {
        $pending = self::$deferred_manual_events;
        self::$deferred_manual_events = [];
        self::$manual_shutdown_flush_registered = false;

        foreach ($pending as $item) {
            [$event_key, $context] = $item;
            $result = self::fire_event_for_mode($event_key, $context, 'shutdown');
            if (is_wp_error($result)) {
                do_action('ces_event_trigger_failed', $result, $event_key, [], [], []);
            }
        }
    }

    /**
     * Process one explicit email processing mode. Internal trigger integrations
     * use this to keep immediate and shutdown deduplication independent.
     *
     * @return array|WP_Error Scheduled Action Scheduler IDs, or WP_Error.
     */
    public static function fire_event_for_mode(string $event_key, array $context = [], string $processing_mode = 'immediate') {
        $event_key = sanitize_key($event_key);
        $processing_mode = sanitize_key($processing_mode);
        if (!in_array($processing_mode, ['immediate', 'shutdown'], true)) {
            return new WP_Error('ces_invalid_processing_mode', __('Email processing mode must be immediate or shutdown.', 'codi-email-scheduler'));
        }
        $event = CES_Registry::event($event_key);

        if (!$event) {
            return new WP_Error('ces_unknown_event', __('Unknown Email Scheduler event.', 'codi-email-scheduler'), [
                'event_key' => $event_key,
            ]);
        }

        $target_blog_id = isset($context['blog_id']) ? absint($context['blog_id']) : 0;
        if (is_multisite() && $target_blog_id > 0 && $target_blog_id !== get_current_blog_id() && function_exists('switch_to_blog')) {
            switch_to_blog($target_blog_id);
            try {
                return self::fire_event_for_mode($event_key, $context, $processing_mode);
            } finally {
                restore_current_blog();
            }
        }

        $context = self::normalize_event_context($event_key, $event, $context);
        $event_instance_id = self::event_instance_id($event_key, $event, $context);
        if (is_wp_error($event_instance_id)) {
            return $event_instance_id;
        }
        $context['event_instance_id'] = $event_instance_id;

        $blog_id = isset($context['blog_id']) ? absint($context['blog_id']) : get_current_blog_id();
        $blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
        $schedule_items = [];

        foreach (CES_Storage::get_emails() as $email_id => $email) {
            if (empty($email['enabled']) || $email['event_key'] !== $event_key || ($email['processing_mode'] ?? 'immediate') !== $processing_mode) {
                continue;
            }

            $validated_email = CES_Email_Validator::validate($email);
            if (is_wp_error($validated_email)) {
                do_action('ces_email_configuration_invalid', $validated_email, $email, $context);
                continue;
            }
            $email = $validated_email;

            $runtime = [
                'phase'             => 'schedule',
                'blog_id'           => $blog_id,
                'email_id'          => $email_id,
                'event_key'         => $event_key,
                'event_instance_id' => $event_instance_id,
            ];

            $settings_result = self::evaluate_event_settings($event, $email, $context, $runtime);
            if (empty($settings_result['passed'])) {
                if (!empty($settings_result['failure'])) {
                    do_action('ces_email_schedule_eligibility_failed', $settings_result, $email, $context, $runtime);
                }
                continue;
            }

            $conditions_result = self::evaluate_conditions($email, $context, $runtime);
            if (empty($conditions_result['passed'])) {
                if (!empty($conditions_result['failure'])) {
                    do_action('ces_email_schedule_eligibility_failed', $conditions_result, $email, $context, $runtime);
                }
                continue;
            }

            foreach (CES_Storage::effective_unique_delays($email['delays'] ?? []) as $delay) {
                $delay_details = CES_Storage::effective_delay_details($delay);
                $payload = [
                    'blog_id'                  => $blog_id,
                    'email_id'                 => $email_id,
                    'delay_id'                 => sanitize_key((string) ($delay['id'] ?? '')),
                    'event_key'                => $event_key,
                    'event_instance_id'        => $event_instance_id,
                    'configured_delay_seconds' => absint($delay_details['configured_seconds']),
                    'effective_delay_seconds'  => absint($delay_details['effective_seconds']),
                    'delay_test_applied'       => !empty($delay_details['test_applied']) ? '1' : '0',
                    'context'                  => $context,
                ];
                $schedule_items[] = [
                    'email'   => $email,
                    'delay'   => $delay,
                    'payload' => $payload,
                ];
            }
        }

        if (!$schedule_items) {
            return [];
        }

        $action_scheduler_status = self::action_scheduler_status();
        if ($action_scheduler_status !== 'ready') {
            return new WP_Error(
                self::action_scheduler_status_error_code($action_scheduler_status),
                self::action_scheduler_status_message($action_scheduler_status),
                [
                    'event_key'               => $event_key,
                    'action_scheduler_status' => $action_scheduler_status,
                ]
            );
        }

        $scheduled = [];

        foreach ($schedule_items as $item) {
            $email = (array) $item['email'];
            $delay = (array) $item['delay'];
            $payload = self::canonicalize_action_payload((array) $item['payload']);
            $delay_seconds = absint($payload['effective_delay_seconds'] ?? 0);
            $args = [$payload];

            try {
                if ($delay_seconds === 0) {
                    $action_id = as_enqueue_async_action(CES_AS_ACTION, $args, CES_AS_GROUP, true);
                } else {
                    $action_id = as_schedule_single_action(time() + $delay_seconds, CES_AS_ACTION, $args, CES_AS_GROUP, true);
                }
            } catch (Throwable $throwable) {
                $error = new WP_Error('action_scheduler_schedule_failed', sanitize_text_field($throwable->getMessage()), [
                    'exception_class' => get_class($throwable),
                ]);
                do_action('ces_email_schedule_failed', $error, $email, $delay, $context, $payload);
                continue;
            }

            if (!$action_id) {
                $duplicate = as_has_scheduled_action(CES_AS_ACTION, $args, CES_AS_GROUP);
                $error = new WP_Error(
                    $duplicate ? 'duplicate_scheduled_action' : 'action_scheduler_no_action_id',
                    $duplicate
                        ? __('An identical email action is already pending or running.', 'codi-email-scheduler')
                        : __('Action Scheduler did not return an action ID.', 'codi-email-scheduler')
                );
                do_action($duplicate ? 'ces_email_schedule_duplicate' : 'ces_email_schedule_failed', $error, $email, $delay, $context, $payload);
                continue;
            }

            $scheduled[] = $action_id;
            do_action('ces_email_scheduled', $action_id, $email, $delay, $context, $event_instance_id);
        }

        return $scheduled;
    }

    /**
     * Schedule one normal CES email action at an absolute timestamp.
     *
     * @return int|WP_Error Action ID, zero for an identical pending/running action, or WP_Error.
     */
    public static function schedule_email_action(string $email_id, array $email, array $context, array $delay, int $timestamp, array $extra_payload = []) {
        if (!self::action_scheduler_available()) {
            return new WP_Error('ces_action_scheduler_dependency_unavailable', self::action_scheduler_status_message(self::action_scheduler_status()));
        }

        $event_key = sanitize_key((string) ($email['event_key'] ?? ''));
        $event_instance_id = sanitize_text_field((string) ($context['event_instance_id'] ?? ''));
        if ($email_id === '' || $event_key === '' || $event_instance_id === '') {
            return new WP_Error('invalid_email_action_payload', __('The email action payload is incomplete.', 'codi-email-scheduler'));
        }

        $details = CES_Storage::effective_delay_details($delay);
        $is_retrospective = !empty($extra_payload['retrospective_current_state']);
        if ($is_retrospective) {
            $context = [
                'user_id'           => absint($context['user_id'] ?? 0),
                'blog_id'           => isset($context['blog_id']) ? absint($context['blog_id']) : get_current_blog_id(),
                'event_instance_id' => $event_instance_id,
            ];
        }

        $payload = [
            'blog_id'           => isset($context['blog_id']) ? absint($context['blog_id']) : get_current_blog_id(),
            'email_id'          => sanitize_key($email_id),
            'delay_id'          => sanitize_key((string) ($delay['id'] ?? '')),
            'event_key'         => $event_key,
            'event_instance_id' => $event_instance_id,
            'context'           => $context,
        ];
        if (!$is_retrospective) {
            $payload['configured_delay_seconds'] = absint($details['configured_seconds']);
            $payload['effective_delay_seconds'] = absint($details['effective_seconds']);
            $payload['delay_test_applied'] = !empty($details['test_applied']) ? '1' : '0';
        }
        $payload = array_merge($payload, $extra_payload);
        $payload = self::canonicalize_action_payload($payload);
        $args = [$payload];

        try {
            $action_id = $timestamp <= time()
                ? as_enqueue_async_action(CES_AS_ACTION, $args, CES_AS_GROUP, true)
                : as_schedule_single_action($timestamp, CES_AS_ACTION, $args, CES_AS_GROUP, true);
        } catch (Throwable $throwable) {
            return new WP_Error('action_scheduler_schedule_failed', sanitize_text_field($throwable->getMessage()), [
                'exception_class' => get_class($throwable),
            ]);
        }

        if (!$action_id && !as_has_scheduled_action(CES_AS_ACTION, $args, CES_AS_GROUP)) {
            return new WP_Error('action_scheduler_no_action_id', __('Action Scheduler did not return an action ID.', 'codi-email-scheduler'));
        }

        return absint($action_id);
    }

    public static function normalize_context_for_event(string $event_key, array $event, array $context): array {
        return self::normalize_event_context($event_key, $event, $context);
    }

    public static function canonicalize_value(array $value): array {
        return self::canonicalize_array($value);
    }

    public static function run_scheduled_email(array $payload): void {
        $email_id = isset($payload['email_id']) ? sanitize_key((string) $payload['email_id']) : '';
        $event_key = isset($payload['event_key']) ? sanitize_key((string) $payload['event_key']) : '';
        $blog_id = isset($payload['blog_id']) ? absint($payload['blog_id']) : get_current_blog_id();
        $blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();

        if ($email_id === '' || $event_key === '' || !isset($payload['context']) || !is_array($payload['context'])) {
            throw new RuntimeException('Email Scheduler action payload is invalid.');
        }

        if (is_multisite() && $blog_id !== get_current_blog_id() && function_exists('switch_to_blog')) {
            switch_to_blog($blog_id);
            try {
                self::execute_scheduled_email($payload);
            } finally {
                restore_current_blog();
            }
            return;
        }

        self::execute_scheduled_email($payload);
    }

    public static function evaluate_event_settings(array $event, array $email, array $context, array $runtime = []): array {
        $callback = $event['settings_match_callback'] ?? null;
        if ($callback === null || !is_callable($callback)) {
            return ['passed' => true, 'result' => [], 'failure' => []];
        }

        $settings = CES_Storage::normalize_settings($email['event_settings'] ?? []);
        $raw = CES_Registry::call_callback($callback, [$settings, $context, $email, $runtime], 'event_settings_callback_failed');
        if (is_wp_error($raw)) {
            return self::event_settings_failure($raw, $event, $email, $context, $runtime);
        }

        if (!is_bool($raw)) {
            return self::event_settings_failure(new WP_Error(
                'event_settings_callback_invalid_value',
                __('Event settings callback must return bool or WP_Error.', 'codi-email-scheduler'),
                ['return_type' => gettype($raw)]
            ), $event, $email, $context, $runtime);
        }

        return [
            'passed'  => $raw,
            'result'  => ['passed' => $raw],
            'failure' => [],
        ];
    }

    private static function event_settings_failure(WP_Error $error, array $event, array $email, array $context, array $runtime): array {
        $result = [
            'passed'     => false,
            'reason'     => $error->get_error_code(),
            'message'    => $error->get_error_message(),
            'error_data' => $error->get_error_data(),
        ];
        $failure = self::runtime_failure('event_settings_callback', $error, [
            'phase' => sanitize_key((string) ($runtime['phase'] ?? '')),
        ]);
        do_action('ces_event_settings_callback_failed', $error, $event, $email, $context, $runtime);
        return ['passed' => false, 'result' => $result, 'failure' => $failure];
    }

    public static function evaluate_conditions(array $email, array $context, array $runtime = []): array {
        $conditions = CES_Storage::normalize_conditions($email['conditions'] ?? []);
        if (!$conditions) {
            return ['passed' => true, 'results' => [], 'failure' => []];
        }

        $mode = sanitize_key((string) ($email['condition_mode'] ?? 'all'));
        $mode = in_array($mode, ['all', 'any'], true) ? $mode : 'all';
        $passed_any = false;
        $results = [];

        foreach ($conditions as $condition_item) {
            $condition_key = sanitize_key((string) ($condition_item['key'] ?? ''));
            $operator = sanitize_key((string) ($condition_item['operator'] ?? 'is_true'));
            $operator = in_array($operator, ['is_true', 'is_false'], true) ? $operator : 'is_true';
            $condition = $condition_key !== '' ? CES_Registry::condition($condition_key) : null;
            $result = [
                'condition_key' => $condition_key,
                'operator'      => $operator,
                'actual'        => null,
                'passed'        => false,
                'valid'         => false,
                'reason'        => $condition ? '' : 'condition_not_found',
            ];
            $failure = [];

            if ($condition) {
                $condition_runtime = array_merge($runtime, [
                    'condition_key'      => $condition_key,
                    'condition_operator' => $operator,
                    'condition_settings' => CES_Storage::normalize_settings($condition_item['settings'] ?? []),
                    'condition_item'     => $condition_item,
                ]);
                $evaluation = self::evaluate_condition($condition, $context, $email, $condition_runtime);
                $evaluation_valid = !empty($evaluation['valid']);
                $evaluation_passed = $evaluation_valid && (bool) ($evaluation['passed'] ?? false);
                $passed = $evaluation_valid && ($operator === 'is_false' ? !$evaluation_passed : $evaluation_passed);
                $failure = isset($evaluation['failure']) && is_array($evaluation['failure']) ? $evaluation['failure'] : [];
                unset($evaluation['failure']);
                $result = array_merge($evaluation, [
                    'condition_key'     => $condition_key,
                    'operator'          => $operator,
                    'valid'             => $evaluation_valid,
                    'evaluation_passed' => $evaluation_passed,
                    'actual'            => array_key_exists('actual', $evaluation) ? $evaluation['actual'] : $evaluation_passed,
                    'passed'            => $passed,
                ]);
            }

            $results[] = $result;
            do_action('ces_condition_checked', $condition_key, (bool) $result['passed'], $email, $context, array_merge($runtime, [
                'condition_operator' => $operator,
                'condition_actual'   => $result['actual'],
                'condition_result'   => $result,
            ]));

            if (empty($result['valid'])) {
                return ['passed' => false, 'results' => $results, 'failure' => $failure];
            }
            if ($mode === 'all' && empty($result['passed'])) {
                return ['passed' => false, 'results' => $results, 'failure' => []];
            }
            if ($mode === 'any' && !empty($result['passed'])) {
                $passed_any = true;
            }
        }

        return ['passed' => $mode === 'all' || $passed_any, 'results' => $results, 'failure' => []];
    }

    private static function evaluate_condition(array $condition, array $context, array $email, array $runtime): array {
        $callback = $condition['callback'] ?? null;
        if (!is_callable($callback)) {
            $error = new WP_Error('condition_callback_missing', __('Condition callback is missing or not callable.', 'codi-email-scheduler'));
            return [
                'valid'   => false,
                'passed'  => false,
                'reason'  => $error->get_error_code(),
                'error'   => $error->get_error_message(),
                'failure' => self::runtime_failure('condition_callback', $error, [
                    'condition_key' => sanitize_key((string) ($runtime['condition_key'] ?? ($condition['key'] ?? ''))),
                    'phase'         => sanitize_key((string) ($runtime['phase'] ?? '')),
                ]),
            ];
        }

        $raw = CES_Registry::call_callback($callback, [$context, $email, $runtime], 'condition_callback_failed');
        if (is_wp_error($raw)) {
            $failure = self::runtime_failure('condition_callback', $raw, [
                'condition_key' => sanitize_key((string) ($runtime['condition_key'] ?? ($condition['key'] ?? ''))),
                'phase'         => sanitize_key((string) ($runtime['phase'] ?? '')),
            ]);
            do_action('ces_condition_callback_failed', $condition, $raw, $email, $context, $runtime);
            return [
                'valid'    => false,
                'passed'   => false,
                'reason'   => $raw->get_error_code(),
                'actual'   => null,
                'expected' => null,
                'error'    => $raw->get_error_message(),
                'failure'  => $failure,
            ];
        }

        if (is_array($raw)) {
            if (!array_key_exists('passed', $raw) || !is_bool($raw['passed'])) {
                $error = new WP_Error(
                    'condition_callback_invalid_result',
                    __('Condition callback result arrays must include a boolean passed value.', 'codi-email-scheduler'),
                    ['actual' => $raw, 'expected' => 'array{passed:bool}']
                );
                $failure = self::runtime_failure('condition_callback', $error, [
                    'condition_key' => sanitize_key((string) ($runtime['condition_key'] ?? ($condition['key'] ?? ''))),
                    'phase'         => sanitize_key((string) ($runtime['phase'] ?? '')),
                ]);
                do_action('ces_condition_callback_failed', $condition, $error, $email, $context, $runtime);
                return [
                    'valid'    => false,
                    'passed'   => false,
                    'reason'   => $error->get_error_code(),
                    'actual'   => $raw,
                    'expected' => 'array{passed:bool}',
                    'error'    => $error->get_error_message(),
                    'failure'  => $failure,
                ];
            }

            return [
                'valid'    => true,
                'passed'   => $raw['passed'],
                'reason'   => isset($raw['reason']) ? sanitize_text_field((string) $raw['reason']) : '',
                'actual'   => $raw['actual'] ?? null,
                'expected' => $raw['expected'] ?? null,
                'failure'  => [],
            ];
        }

        if (is_bool($raw)) {
            return ['valid' => true, 'passed' => $raw, 'reason' => '', 'failure' => []];
        }

        $error = new WP_Error(
            'condition_callback_invalid_value',
            __('Condition callback returned an invalid value.', 'codi-email-scheduler'),
            ['actual' => gettype($raw), 'expected' => 'bool|WP_Error|array']
        );
        $failure = self::runtime_failure('condition_callback', $error, [
            'condition_key' => sanitize_key((string) ($runtime['condition_key'] ?? ($condition['key'] ?? ''))),
            'phase'         => sanitize_key((string) ($runtime['phase'] ?? '')),
        ]);
        do_action('ces_condition_callback_failed', $condition, $error, $email, $context, $runtime);
        return [
            'valid'    => false,
            'passed'   => false,
            'reason'   => $error->get_error_code(),
            'actual'   => gettype($raw),
            'expected' => 'bool|WP_Error|array',
            'error'    => $error->get_error_message(),
            'failure'  => $failure,
        ];
    }

    private static function runtime_failure(string $source, WP_Error $error, array $details = []): array {
        return [
            'reason'  => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'source'  => sanitize_key($source),
            'details' => array_merge([
                'source'     => sanitize_key($source),
                'error_data' => $error->get_error_data(),
            ], $details),
        ];
    }

    /**
     * @return array|WP_Error
     */
    public static function resolve_recipients(array $email, array $context, array $runtime = []) {
        $recipients = [];
        $event = CES_Registry::event((string) ($email['event_key'] ?? ''));

        if ($event && !empty($event['recipient_callback']) && is_callable($event['recipient_callback'])) {
            $raw_recipients = CES_Registry::call_callback($event['recipient_callback'], [$context, $email, $runtime], 'recipient_callback_failed');
            if (is_wp_error($raw_recipients)) {
                do_action('ces_recipient_callback_failed', $raw_recipients, $email, $context, $runtime);
                return $raw_recipients;
            }

            $normalized_recipients = self::normalize_recipients($raw_recipients);
            if (!$normalized_recipients) {
                $error = new WP_Error(
                    'recipient_callback_no_valid_recipients',
                    __('Recipient callback returned no valid email recipients.', 'codi-email-scheduler'),
                    ['return_type' => gettype($raw_recipients)]
                );
                do_action('ces_recipient_callback_failed', $error, $email, $context, $runtime);
                return $error;
            }

            return self::normalize_recipients(apply_filters('ces_email_recipients', $normalized_recipients, $email, $context, $runtime));
        }

        $recipient = CES_Context::user_email($context);
        if ($recipient !== '') {
            $recipients[] = $recipient;
        }

        return self::normalize_recipients(apply_filters('ces_email_recipients', $recipients, $email, $context, $runtime));
    }

    /**
     * @return string|WP_Error
     */
    public static function replace_tokens(string $content, array $context, array $email, array $runtime = []) {
        if ($content === '') {
            return '';
        }

        if (!preg_match_all('/{{\s*([a-zA-Z0-9_\-]+)\s*}}/', $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        $tokens = CES_Registry::tokens();
        $replacements = [];
        $token_values = [];

        foreach ($matches as $match) {
            $placeholder = $match[0];
            $token_key = sanitize_key((string) ($match[1] ?? ''));
            if ($token_key === '' || isset($replacements[$placeholder]) || empty($tokens[$token_key])) {
                continue;
            }

            if (!array_key_exists($token_key, $token_values)) {
                $token = $tokens[$token_key];
                if (empty($token['callback']) || !is_callable($token['callback'])) {
                    $error = new WP_Error('token_callback_missing', __('Token callback is missing or not callable.', 'codi-email-scheduler'), [
                        'token_key' => $token_key,
                    ]);
                    self::handle_token_callback_failure($token_key, $error, $email, $context, $runtime);
                    return $error;
                }

                $raw_value = CES_Registry::call_callback($token['callback'], [$context, $email, $runtime], 'token_callback_failed');
                if (is_wp_error($raw_value)) {
                    self::handle_token_callback_failure($token_key, $raw_value, $email, $context, $runtime);
                    return $raw_value;
                }

                if (!is_scalar($raw_value) && $raw_value !== null) {
                    $error = new WP_Error('token_callback_invalid_value', __('Token callback returned a non-scalar value.', 'codi-email-scheduler'), [
                        'token_key'   => $token_key,
                        'return_type' => gettype($raw_value),
                    ]);
                    self::handle_token_callback_failure($token_key, $error, $email, $context, $runtime);
                    return $error;
                }

                $token_values[$token_key] = self::escape_token_value((string) $raw_value, $token, $runtime);
            }

            $replacements[$placeholder] = $token_values[$token_key];
        }

        $replacements = apply_filters('ces_token_replacements', $replacements, $content, $context, $email, $runtime);
        return strtr($content, $replacements);
    }

    private static function handle_token_callback_failure(string $token_key, WP_Error $error, array $email, array $context, array $runtime): void {
        do_action('ces_token_callback_failed', $token_key, $error, $email, $context, $runtime);
    }

    private static function escape_token_value(string $value, array $token, array $runtime): string {
        $escape = sanitize_key((string) ($token['escape'] ?? 'html'));
        if (!in_array($escape, ['html', 'text', 'url', 'raw'], true)) {
            $escape = 'html';
        }

        if (sanitize_key((string) ($runtime['token_context'] ?? 'body')) === 'subject') {
            return sanitize_text_field(wp_strip_all_tags($value));
        }

        if ($escape === 'raw') {
            return $value;
        }

        if ($escape === 'url') {
            return esc_url($value);
        }

        if ($escape === 'text') {
            return sanitize_text_field(wp_strip_all_tags($value));
        }

        return esc_html($value);
    }

    public static function normalize_event_context(string $event_key, array $event, array $context): array {
        $allowed_keys = CES_Registry::event_context_keys($event);
        $context['event_key'] = $event_key;
        $context['blog_id'] = $context['blog_id'] ?? get_current_blog_id();
        $context['event_time_gmt'] = $context['event_time_gmt'] ?? current_time('mysql', true);
        $context['event_timestamp'] = $context['event_timestamp'] ?? time();

        $clean = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || !in_array($key, $allowed_keys, true)) {
                continue;
            }
            $clean[$key] = self::sanitize_context_value($key, $value);
        }

        $clean = self::enrich_organisation_context($clean, $event_key, $event);

        return (array) apply_filters('ces_normalized_event_context', $clean, $event_key, $event, $context);
    }

    private static function enrich_organisation_context(array $context, string $event_key, array $event): array {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        if (!$user_id || !function_exists('get_organisation')) {
            return (array) apply_filters('ces_organisation_context', $context, null, $user_id, $event_key, $event);
        }

        $organisation = null;

        try {
            $organisation = get_organisation($user_id);
        } catch (Throwable $e) {
            $organisation = null;
        }

        if ($organisation) {
            $organisation_id = self::organisation_value($organisation, ['id', 'ID', 'organisation_id']);
            $organisation_name = self::organisation_value($organisation, ['name', 'organisation_name', 'title']);

            if ($organisation_id !== null && absint($organisation_id) > 0) {
                $context['organisation_id'] = absint($organisation_id);
            }

            if ($organisation_name !== null && trim((string) $organisation_name) !== '') {
                $context['organisation_name'] = sanitize_text_field((string) $organisation_name);
            }
        }

        return (array) apply_filters('ces_organisation_context', $context, $organisation, $user_id, $event_key, $event);
    }

    private static function organisation_value($organisation, array $keys) {
        foreach ($keys as $key) {
            if (is_array($organisation) && array_key_exists($key, $organisation)) {
                return $organisation[$key];
            }

            if (is_object($organisation) && isset($organisation->{$key})) {
                return $organisation->{$key};
            }
        }

        return null;
    }

    private static function execute_scheduled_email(array $payload): void {
        $email_id = sanitize_key((string) ($payload['email_id'] ?? ''));
        $event_key = sanitize_key((string) ($payload['event_key'] ?? ''));
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $runtime = [
            'phase'                     => 'send',
            'blog_id'                   => absint($payload['blog_id'] ?? get_current_blog_id()),
            'email_id'                  => $email_id,
            'delay_id'                  => sanitize_key((string) ($payload['delay_id'] ?? '')),
            'event_key'                 => $event_key,
            'event_instance_id'         => sanitize_text_field((string) ($payload['event_instance_id'] ?? '')),
            'configured_delay_seconds'  => absint($payload['configured_delay_seconds'] ?? 0),
            'effective_delay_seconds'   => absint($payload['effective_delay_seconds'] ?? 0),
            'delay_test_applied'        => !empty($payload['delay_test_applied']) ? '1' : '0',
            'retrospective_current_state' => !empty($payload['retrospective_current_state']) ? '1' : '0',
        ];

        $email = CES_Storage::get_email($email_id);
        if ($email) {
            $refreshed_context = self::refresh_scheduled_context($event_key, $context, absint($runtime['blog_id']));
            if (is_wp_error($refreshed_context)) {
                self::skip_scheduled_email($refreshed_context->get_error_code(), $email, $context, $runtime, $refreshed_context->get_error_message());
                return;
            }
            $context = $refreshed_context;
        }

        if (!$email || empty($email['enabled'])) {
            self::skip_scheduled_email('email_missing_or_disabled', $email, $context, $runtime);
            return;
        }

        if ($email['event_key'] !== $event_key) {
            self::skip_scheduled_email('event_mismatch', $email, $context, $runtime);
            return;
        }

        try {
            $dispatch = CES_Email_Dispatcher::dispatch($email, $context, $runtime);
            if (is_wp_error($dispatch)) {
                $error_data = $dispatch->get_error_data();
                if (is_array($error_data)) {
                    foreach (['condition_results', 'event_settings_result', 'runtime_failure'] as $key) {
                        if (array_key_exists($key, $error_data)) {
                            $runtime[$key] = $error_data[$key];
                        }
                    }
                }

                if (!empty($runtime['runtime_failure'])) {
                    throw new RuntimeException($dispatch->get_error_message());
                }

                self::skip_scheduled_email($dispatch->get_error_code(), $email, $context, $runtime, $dispatch->get_error_message());
                return;
            }

            if (!empty($dispatch['failed'])) {
                throw new RuntimeException((string) ($dispatch['message'] ?? 'Email Scheduler failed to deliver the email.'));
            }

            $status = sanitize_key((string) ($dispatch['status'] ?? 'sent'));
            do_action('ces_scheduled_email_completed', $status, $dispatch, $email, $context, $runtime);
        } catch (Throwable $throwable) {
            do_action('ces_scheduled_email_failed', $throwable, $email, $context, $runtime);
            throw $throwable;
        }
    }

    /**
     * Refresh current user data for every scheduled user-based email.
     *
     * Event-specific context is preserved, while mutable user fields are replaced
     * with their current values. On multisite, membership of the target site is a
     * mandatory delivery invariant rather than an optional configured condition.
     *
     * @return array|WP_Error
     */
    private static function refresh_scheduled_context(string $event_key, array $stored_context, int $blog_id) {
        $user_id = absint($stored_context['user_id'] ?? 0);
        if ($user_id <= 0) {
            return $stored_context;
        }

        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return new WP_Error('scheduled_user_not_found', __('The scheduled email user no longer exists.', 'codi-email-scheduler'));
        }

        $blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
        if (is_multisite() && !is_user_member_of_blog($user_id, $blog_id)) {
            return new WP_Error('scheduled_user_not_site_member', __('The scheduled email user no longer belongs to this site.', 'codi-email-scheduler'));
        }

        $event = CES_Registry::event($event_key);
        if (!$event) {
            return new WP_Error('scheduled_event_not_found', __('The scheduled email event no longer exists.', 'codi-email-scheduler'));
        }

        return self::normalize_event_context($event_key, $event, array_merge($stored_context, [
            'user_id'    => (int) $user->ID,
            'email'      => sanitize_email((string) $user->user_email),
            'user_login' => sanitize_text_field((string) $user->user_login),
            'user_roles' => array_values(array_filter(array_map('sanitize_key', (array) $user->roles))),
            'blog_id'    => $blog_id,
        ]));
    }

    private static function skip_scheduled_email(string $reason, ?array $email, array $context, array $runtime, string $fallback_message = ''): void {
        $failure = isset($runtime['runtime_failure']) && is_array($runtime['runtime_failure']) ? $runtime['runtime_failure'] : [];
        $resolved_reason = !empty($failure['reason']) ? sanitize_key((string) $failure['reason']) : sanitize_key($reason);
        $message = !empty($failure['message'])
            ? sanitize_text_field((string) $failure['message'])
            : ($fallback_message !== '' ? sanitize_text_field($fallback_message) : $resolved_reason);

        $runtime['skip_message'] = $message;
        do_action('ces_email_skipped', $resolved_reason, $email, $context, $runtime);
    }

    private static function event_instance_id(string $event_key, array $event, array $context) {
        if (!empty($context['event_instance_id'])) {
            return CES_Event_Identity::normalize((string) $context['event_instance_id']);
        }

        if (!empty($event['instance_id_callback']) && is_callable($event['instance_id_callback'])) {
            $id = CES_Registry::call_callback($event['instance_id_callback'], [$event_key, $context], 'instance_id_callback_failed');
            if (is_wp_error($id)) {
                self::handle_instance_id_callback_failure($id, $event_key, $event, $context);
                return $id;
            }

            if (!is_scalar($id) || is_bool($id) || trim((string) $id) === '') {
                $error = new WP_Error('instance_id_callback_invalid_value', __('Instance ID callback must return a non-empty scalar or WP_Error.', 'codi-email-scheduler'), [
                    'return_type' => gettype($id),
                ]);
                self::handle_instance_id_callback_failure($error, $event_key, $event, $context);
                return $error;
            }

            return CES_Event_Identity::normalize(trim((string) $id));
        }

        if (!empty($context['user_id'])) {
            return CES_Event_Identity::normalize($event_key . ':' . absint($context['user_id']));
        }

        if (!empty($context['email'])) {
            return CES_Event_Identity::normalize($event_key . ':' . sanitize_email((string) $context['email']));
        }

        return CES_Event_Identity::normalize(wp_generate_uuid4());
    }

    private static function handle_instance_id_callback_failure(WP_Error $error, string $event_key, array $event, array $context): void {
        do_action('ces_instance_id_callback_failed', $error, $event_key, $event, $context);
    }

    private static function normalize_recipients($recipients): array {
        if (is_string($recipients)) {
            $recipients = [$recipients];
        }

        if (!is_array($recipients)) {
            return [];
        }

        $clean = [];
        foreach ($recipients as $recipient) {
            if (is_array($recipient) && isset($recipient['email'])) {
                $recipient = $recipient['email'];
            }

            $email = sanitize_email((string) $recipient);
            if ($email && is_email($email)) {
                $clean[] = $email;
            }
        }

        return array_values(array_unique($clean));
    }

    private static function canonicalize_action_payload(array $payload): array {
        return self::canonicalize_array($payload);
    }

    private static function canonicalize_array(array $value): array {
        $is_list = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize_array($item);
            }
        }

        return $value;
    }

    private static function sanitize_context_value(string $key, $value) {
        if ($key === 'user_roles') {
            return is_array($value) ? array_values(array_filter(array_map('sanitize_key', $value))) : [];
        }

        if (is_array($value)) {
            return array_map(static function ($item) use ($key) {
                return self::sanitize_context_value($key, $item);
            }, $value);
        }

        if ($value === null) {
            return null;
        }

        if ($key === 'event_instance_id') {
            return sanitize_text_field((string) $value);
        }

        if (in_array($key, ['user_id', 'blog_id', 'product_id', 'variation_id', 'quantity', 'order_id', 'event_timestamp', 'organisation_id'], true)) {
            return absint($value);
        }

        if ($key === 'email' || substr($key, -6) === '_email') {
            return sanitize_email((string) $value);
        }

        if (substr($key, -4) === '_url') {
            return esc_url_raw((string) $value);
        }

        if (in_array($key, ['order_status', 'order_previous_status', 'role', 'product_type'], true) || substr($key, -7) === '_status') {
            return sanitize_key((string) $value);
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }
}
