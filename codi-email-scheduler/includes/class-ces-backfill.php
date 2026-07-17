<?php

defined('ABSPATH') || exit;

final class CES_Backfill {
    private const BATCH_SIZE = 100;

    public static function init(): void {
        add_action(CES_AS_BACKFILL_ACTION, [__CLASS__, 'run_batch'], 10, 1);
    }

    public static function event_supports_backfill(array $event): bool {
        return !empty($event['retrospective']) && in_array('user_id', (array) ($event['context_keys'] ?? []), true);
    }

    public static function preview(string $email_id, array $settings = []) {
        $prepared = self::prepare($email_id, $settings);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        [$email, $event, $policy] = $prepared;
        $snapshot = self::snapshot();
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $page = self::query_users('', min(50, self::BATCH_SIZE), $snapshot);
        if (is_wp_error($page)) {
            return $page;
        }

        $eligible = 0;
        $excluded = 0;
        $errors = 0;
        foreach ($page['user_ids'] as $user_id) {
            $evaluation = self::evaluate_user($user_id, $email, $event, [
                'phase' => 'backfill_preview',
                'policy' => $policy,
            ]);
            if (is_wp_error($evaluation)) {
                if (self::is_systemic_candidate_error($evaluation)) {
                    return $evaluation;
                }
                $errors++;
            } elseif (!empty($evaluation['eligible'])) {
                $eligible++;
            } else {
                $excluded++;
            }
        }

        $candidate_count = self::count_users($snapshot);
        if (is_wp_error($candidate_count)) {
            return $candidate_count;
        }
        return [
            'candidate_count' => $candidate_count,
            'sample_size' => count($page['user_ids']),
            'sample_eligible' => $eligible,
            'sample_excluded' => $excluded,
            'sample_errors' => $errors,
        ];
    }

    public static function start(string $email_id, array $settings = []) {
        $prepared = self::prepare($email_id, $settings);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        [$email, $event, $policy] = $prepared;
        if (!CES_Engine::action_scheduler_available()) {
            return new WP_Error('backfill_scheduler_unavailable', __('Action Scheduler must be available before a retrospective enrollment can start.', 'codi-email-scheduler'));
        }

        $run_id = sanitize_key('run_' . str_replace('-', '', wp_generate_uuid4()));
        $now = time();
        $run = [
            'id' => $run_id,
            'email_id' => $email_id,
            'event_key' => (string) $email['event_key'],
            'status' => 'running',
            'started_at' => $now,
            'updated_at' => $now,
            'cursor' => '',
            'snapshot' => null,
            'policy' => $policy,
            'config_hash' => self::configuration_hash($email),
            'distribution_cursors' => [],
            'evaluated' => 0,
            'eligible' => 0,
            'scheduled' => 0,
            'excluded' => 0,
            'errors' => 0,
            'last_error' => [],
            'next_action_args' => [],
        ];
        $snapshot = self::snapshot();
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $run['snapshot'] = $snapshot;
        if (!self::create_run($run)) {
            return new WP_Error('backfill_state_save_failed', __('The retrospective enrollment could not be saved.', 'codi-email-scheduler'));
        }
        if (!self::claim_email($email_id, $run_id)) {
            self::delete_run($run_id);
            return new WP_Error('backfill_already_active', __('This email already has an active retrospective enrollment.', 'codi-email-scheduler'));
        }
        if (!self::set_latest_run($email_id, $run_id)) {
            self::release_email($email_id, $run_id);
            self::delete_run($run_id);
            return new WP_Error('backfill_state_save_failed', __('The retrospective enrollment could not be registered.', 'codi-email-scheduler'));
        }
        $scheduled = self::schedule_batch($run_id, '');
        if (is_wp_error($scheduled)) {
            $current = self::get_run($run_id);
            if ($current) {
                self::fail_run($current, $scheduled);
            }
            return $scheduled;
        }
        return $run_id;
    }

    public static function pause(string $run_id): bool {
        $run = self::get_run($run_id);
        if (!$run || (string) ($run['status'] ?? '') !== 'running') {
            return false;
        }
        $updated = $run;
        $updated['status'] = 'paused';
        $updated['next_action_args'] = [];
        if (!self::save_run_if_unchanged($run, $updated)) {
            return false;
        }
        self::unschedule_next($run);
        return true;
    }

    public static function resume(string $run_id) {
        $run = self::get_run($run_id);
        if (!$run || (string) ($run['status'] ?? '') !== 'paused') {
            return new WP_Error('backfill_not_resumable', __('This retrospective enrollment cannot be resumed.', 'codi-email-scheduler'));
        }
        $email = CES_Storage::get_email((string) $run['email_id']);
        $event = $email ? CES_Registry::event((string) ($email['event_key'] ?? '')) : null;
        if (!$email || empty($email['enabled']) || !$event || !self::event_supports_backfill($event)) {
            return new WP_Error('backfill_email_missing', __('The enabled scheduled email is unavailable.', 'codi-email-scheduler'));
        }
        $validated = CES_Email_Validator::validate($email, true);
        if (is_wp_error($validated)) {
            return $validated;
        }
        if (self::configuration_hash($validated) !== (string) ($run['config_hash'] ?? '')) {
            return new WP_Error('backfill_configuration_changed', __('Eligibility or timing settings changed. Cancel this enrollment and start a new one.', 'codi-email-scheduler'));
        }
        if (!self::claim_email((string) $run['email_id'], (string) $run['id'])) {
            return new WP_Error('backfill_already_active', __('Another retrospective enrollment is active for this email.', 'codi-email-scheduler'));
        }
        $updated = $run;
        $updated['status'] = 'running';
        $updated['last_error'] = [];
        if (!self::save_run_if_unchanged($run, $updated)) {
            return new WP_Error('backfill_state_save_failed', __('The retrospective enrollment could not be resumed because its state changed.', 'codi-email-scheduler'));
        }
        $scheduled = self::schedule_batch((string) $updated['id'], (string) $updated['cursor']);
        if (is_wp_error($scheduled)) {
            $current = self::get_run((string) $updated['id']);
            if ($current) {
                self::fail_run($current, $scheduled);
            }
            return $scheduled;
        }
        return true;
    }

    public static function cancel(string $run_id): bool {
        $run = self::get_run($run_id);
        if (!$run || !in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true)) {
            return false;
        }
        $updated = $run;
        $updated['status'] = 'cancelled';
        $updated['next_action_args'] = [];
        $updated['snapshot'] = [];
        $updated['distribution_cursors'] = [];
        if (!self::save_run_if_unchanged($run, $updated)) {
            return false;
        }
        self::unschedule_next($run);
        self::release_email((string) $updated['email_id'], (string) $updated['id']);
        do_action('ces_backfill_cancelled', $updated);
        return true;
    }

    public static function run_batch(array $payload): void {
        $run_id = sanitize_key((string) ($payload['run_id'] ?? ''));
        $cursor = (string) ($payload['cursor'] ?? '');
        $run = self::get_run($run_id);
        if (!$run || (string) ($run['cursor'] ?? '') !== $cursor || (string) ($run['status'] ?? '') !== 'running') {
            return;
        }
        $email = CES_Storage::get_email((string) $run['email_id']);
        $event = $email ? CES_Registry::event((string) ($email['event_key'] ?? '')) : null;
        if (!$email || !$event || !self::event_supports_backfill($event)) {
            self::fail_batch($run, new WP_Error('backfill_configuration_missing', __('The email or event definition is unavailable.', 'codi-email-scheduler')));
        }
        if (self::configuration_hash($email) !== (string) $run['config_hash']) {
            self::fail_batch($run, new WP_Error('backfill_configuration_changed', __('Eligibility or timing settings changed. Cancel this enrollment and start a new one.', 'codi-email-scheduler')));
        }
        $page = self::query_users($cursor, self::BATCH_SIZE, (array) $run['snapshot']);
        if (is_wp_error($page)) {
            self::fail_batch($run, $page);
        }

        $counts = ['evaluated' => 0, 'eligible' => 0, 'scheduled' => 0, 'excluded' => 0, 'errors' => 0];
        $last_error = [];
        foreach ($page['user_ids'] as $user_id) {
            $evaluation = self::evaluate_user($user_id, $email, $event, [
                'phase' => 'backfill_enrollment',
                'run_id' => $run_id,
                'policy' => $run['policy'],
            ]);
            $counts['evaluated']++;
            if (is_wp_error($evaluation)) {
                if (self::is_systemic_candidate_error($evaluation)) {
                    self::fail_batch($run, $evaluation);
                }
                $counts['errors']++;
                $last_error = array_merge(self::error_array($evaluation), ['candidate' => (string) $user_id]);
                do_action('ces_backfill_candidate_failed', $evaluation, $user_id, $run);
                continue;
            }
            if (empty($evaluation['eligible'])) {
                $counts['excluded']++;
                continue;
            }
            $counts['eligible']++;
            foreach (CES_Storage::effective_unique_delays($email['delays'] ?? []) as $delay) {
                $result = self::schedule_delay($run, $email, (array) $evaluation['context'], $delay);
                if (is_wp_error($result)) {
                    self::fail_batch($run, $result);
                }
                if ($result) {
                    $counts['scheduled']++;
                }
            }
        }

        $latest = self::get_run($run_id);
        if (!$latest || !in_array((string) ($latest['status'] ?? ''), ['running', 'paused'], true)) {
            return;
        }
        $expected = $latest;
        foreach ($counts as $key => $value) {
            $latest[$key] = absint($latest[$key] ?? 0) + $value;
        }
        if ($last_error) {
            $latest['last_error'] = $last_error;
        }
        $latest['distribution_cursors'] = $run['distribution_cursors'];
        $latest['cursor'] = (string) $page['next_cursor'];
        $latest['next_action_args'] = [];

        if ((string) $expected['status'] === 'paused') {
            self::save_run_if_unchanged($expected, $latest);
            return;
        }
        if (!empty($page['done'])) {
            $latest['status'] = 'completed';
            $latest['completed_at'] = time();
            $latest['snapshot'] = [];
            $latest['distribution_cursors'] = [];
            if (!self::save_run_if_unchanged($expected, $latest)) {
                self::handle_boundary_save_failure($expected);
                return;
            }
            self::release_email((string) $latest['email_id'], (string) $latest['id']);
            do_action('ces_backfill_completed', $latest);
            return;
        }
        if (!self::save_run_if_unchanged($expected, $latest)) {
            self::handle_boundary_save_failure($expected);
            return;
        }
        $scheduled = self::schedule_batch($run_id, (string) $latest['cursor']);
        if (is_wp_error($scheduled)) {
            self::fail_batch($latest, $scheduled);
        }
    }

    public static function delete_email_state(string $email_id): void {
        $email_id = sanitize_key($email_id);
        if ($email_id === '') return;
        $run_ids = array_values(array_unique(array_filter([
            sanitize_key((string) get_option(CES_OPTION_BACKFILL_ACTIVE_PREFIX . $email_id, '')),
            sanitize_key((string) get_option(CES_OPTION_BACKFILL_LATEST_PREFIX . $email_id, '')),
        ])));
        foreach ($run_ids as $run_id) {
            $run = self::get_run($run_id);
            if ($run) self::unschedule_next($run);
        }
        delete_option(CES_OPTION_BACKFILL_ACTIVE_PREFIX . $email_id);
        delete_option(CES_OPTION_BACKFILL_LATEST_PREFIX . $email_id);
        foreach ($run_ids as $run_id) delete_option(CES_OPTION_BACKFILL_PREFIX . $run_id);
    }

    public static function active_run_for_email(string $email_id): ?array {
        $run_id = sanitize_key((string) get_option(CES_OPTION_BACKFILL_ACTIVE_PREFIX . sanitize_key($email_id), ''));
        $run = $run_id !== '' ? self::get_run($run_id) : null;
        return $run && in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true) ? $run : null;
    }

    public static function latest_run_for_email(string $email_id): ?array {
        $run_id = sanitize_key((string) get_option(CES_OPTION_BACKFILL_LATEST_PREFIX . sanitize_key($email_id), ''));
        return $run_id !== '' ? self::get_run($run_id) : null;
    }

    private static function prepare(string $email_id, array $settings) {
        $email = CES_Storage::get_email(sanitize_key($email_id));
        if (!$email) return new WP_Error('backfill_email_missing', __('The scheduled email does not exist.', 'codi-email-scheduler'));
        if (empty($email['enabled'])) return new WP_Error('backfill_email_disabled', __('Enable the scheduled email before starting retrospective enrollment.', 'codi-email-scheduler'));
        $validated = CES_Email_Validator::validate($email, true);
        if (is_wp_error($validated)) return $validated;
        $event = CES_Registry::event((string) $validated['event_key']);
        if (!$event || !self::event_supports_backfill($event)) {
            return new WP_Error('backfill_not_supported', __('This event does not support current-state user enrollment.', 'codi-email-scheduler'));
        }
        if ((string) $validated['event_key'] === 'wp_user_meta_changed') {
            $content = (string) ($validated['subject'] ?? '') . "\n" . (string) ($validated['body'] ?? '');
            if (preg_match('/{{\s*(meta_key|meta_value|previous_meta_value|meta_change_type)\s*}}/i', $content)) {
                return new WP_Error('backfill_event_token_unsupported', __('Current-state enrollment cannot use historical user-meta event tokens.', 'codi-email-scheduler'));
            }
        }
        return [$validated, $event, self::normalize_policy($settings)];
    }

    private static function normalize_policy(array $settings): array {
        return [
            'rate_per_minute' => max(1, min(60, absint($settings['rate_per_minute'] ?? 60))),
            'backfill_started_at' => time(),
        ];
    }

    private static function snapshot() {
        global $wpdb;
        if (!isset($wpdb->users)) {
            return new WP_Error('backfill_database_unavailable', __('The user database table is unavailable.', 'codi-email-scheduler'));
        }
        $wpdb->last_error = '';
        $scope = self::user_scope_sql();
        $query = "SELECT MAX(u.ID) FROM {$wpdb->users} u{$scope['join']}" . $scope['where'];
        if ($scope['params']) {
            $query = $wpdb->prepare($query, ...$scope['params']);
        }
        $max_id = $wpdb->get_var($query);
        if ((string) $wpdb->last_error !== '') {
            return new WP_Error('backfill_database_error', __('The retrospective user snapshot could not be created.', 'codi-email-scheduler'));
        }
        return ['max_user_id' => max(0, (int) $max_id), 'blog_id' => get_current_blog_id()];
    }

    private static function count_users(array $snapshot) {
        global $wpdb;
        if (!isset($wpdb->users)) {
            return new WP_Error('backfill_database_unavailable', __('The user database table is unavailable.', 'codi-email-scheduler'));
        }
        $scope = self::user_scope_sql();
        $wpdb->last_error = '';
        $query = "SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u{$scope['join']}" . $scope['where'] . ($scope['where'] === '' ? ' WHERE' : ' AND') . ' u.ID <= %d';
        $params = array_merge($scope['params'], [absint($snapshot['max_user_id'] ?? 0)]);
        $count = $wpdb->get_var($wpdb->prepare($query, ...$params));
        if ((string) $wpdb->last_error !== '') {
            return new WP_Error('backfill_database_error', __('The retrospective user count could not be read.', 'codi-email-scheduler'));
        }
        return max(0, (int) $count);
    }

    private static function query_users(string $cursor, int $limit, array $snapshot) {
        global $wpdb;
        if (!isset($wpdb->users)) {
            return new WP_Error('backfill_database_unavailable', __('The user database table is unavailable.', 'codi-email-scheduler'));
        }
        $last_id = absint($cursor);
        $max_id = absint($snapshot['max_user_id'] ?? 0);
        $limit = max(1, min(500, $limit));
        $scope = self::user_scope_sql();
        $query = "SELECT DISTINCT u.ID FROM {$wpdb->users} u{$scope['join']}" . $scope['where'] . ($scope['where'] === '' ? ' WHERE' : ' AND') . ' u.ID > %d AND u.ID <= %d ORDER BY u.ID ASC LIMIT %d';
        $wpdb->last_error = '';
        $params = array_merge($scope['params'], [$last_id, $max_id, $limit]);
        $ids = array_map('absint', (array) $wpdb->get_col($wpdb->prepare($query, ...$params)));
        if ((string) $wpdb->last_error !== '') {
            return new WP_Error('backfill_database_error', __('The retrospective user page could not be read.', 'codi-email-scheduler'));
        }
        $next = $ids ? (string) end($ids) : (string) $last_id;
        return ['user_ids' => $ids, 'next_cursor' => $next, 'done' => count($ids) < $limit || (int) $next >= $max_id];
    }

    private static function user_scope_sql(): array {
        global $wpdb;
        if (!is_multisite()) {
            return ['join' => '', 'where' => '', 'params' => []];
        }
        $capabilities_key = $wpdb->get_blog_prefix(get_current_blog_id()) . 'capabilities';
        return [
            'join' => " INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID",
            'where' => ' WHERE um.meta_key = %s',
            'params' => [$capabilities_key],
        ];
    }

    private static function evaluate_user(int $user_id, array $email, array $event, array $runtime) {
        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) return ['eligible' => false, 'context' => []];
        $started_at = absint($runtime['policy']['backfill_started_at'] ?? time());
        $context = [
            'user_id' => (int) $user->ID,
            'email' => sanitize_email((string) $user->user_email),
            'user_login' => sanitize_text_field((string) $user->user_login),
            'user_roles' => array_values(array_filter(array_map('sanitize_key', (array) $user->roles))),
            'blog_id' => get_current_blog_id(),
            'source_hook' => 'retrospective_current_state',
            'source_trigger' => 'retrospective_current_state',
            'event_timestamp' => $started_at,
            'event_time_gmt' => gmdate('Y-m-d H:i:s', $started_at),
            'event_instance_id' => CES_Event_Identity::normalize((string) $email['event_key'] . ':current-state:' . get_current_blog_id() . ':' . (int) $user->ID),
        ];
        $context = CES_Engine::normalize_context_for_event((string) $email['event_key'], $event, $context);
        $eligibility = CES_Email_Dispatcher::evaluate_eligibility($email, $context, $runtime);
        if (is_wp_error($eligibility)) {
            $data = $eligibility->get_error_data();
            if (is_array($data) && !empty($data['runtime_failure'])) return $eligibility;
            if (in_array($eligibility->get_error_code(), ['conditions_failed', 'sending_not_allowed', 'no_recipients'], true)) {
                return ['eligible' => false, 'context' => $context];
            }
            return $eligibility;
        }
        return ['eligible' => true, 'context' => $context];
    }

    private static function schedule_delay(array &$run, array $email, array $context, array $delay) {
        $details = CES_Storage::effective_delay_details($delay);
        $delay_id = sanitize_key((string) ($delay['id'] ?? ''));
        if ($delay_id === '') return new WP_Error('backfill_delay_invalid', __('A retrospective delay is missing its stable identifier.', 'codi-email-scheduler'));
        $base = (int) $run['started_at'] + absint($details['effective_seconds']);
        $had_cursor = isset($run['distribution_cursors'][$delay_id]);
        $previous_cursor = $had_cursor ? $run['distribution_cursors'][$delay_id] : null;
        $timestamp = self::distributed_timestamp($run, $delay_id, $base, (int) $run['policy']['rate_per_minute']);
        $scheduled = CES_Engine::schedule_email_action((string) $run['email_id'], $email, $context, $delay, $timestamp, [
            'retrospective_current_state' => '1',
        ]);
        if ($scheduled === 0) {
            if ($had_cursor) {
                $run['distribution_cursors'][$delay_id] = $previous_cursor;
            } else {
                unset($run['distribution_cursors'][$delay_id]);
            }
        }
        return $scheduled;
    }

    private static function distributed_timestamp(array &$run, string $delay_id, int $base, int $rate_per_minute): int {
        $rate = max(1, min(60, $rate_per_minute));
        $base_bucket = (int) floor(max(0, $base) / 60) * 60;
        $cursors = isset($run['distribution_cursors']) && is_array($run['distribution_cursors'])
            ? $run['distribution_cursors']
            : [];
        $state = isset($cursors[$delay_id]) && is_array($cursors[$delay_id])
            ? $cursors[$delay_id]
            : ['bucket' => 0, 'count' => 0];
        $bucket = max($base_bucket, absint($state['bucket'] ?? 0));
        $count = $bucket === absint($state['bucket'] ?? 0) ? absint($state['count'] ?? 0) : 0;

        if ($count >= $rate) {
            $bucket += 60;
            $count = 0;
        }

        $offset = (int) floor(($count * 60) / $rate);
        $timestamp = max($base, $bucket + $offset);
        if ($timestamp >= $bucket + 60) {
            $bucket += 60;
            $count = 0;
            $timestamp = $bucket;
        }

        $run['distribution_cursors'][$delay_id] = [
            'bucket' => $bucket,
            'count' => $count + 1,
        ];
        return $timestamp;
    }

    private static function schedule_batch(string $run_id, string $cursor) {
        $payload = ['run_id' => sanitize_key($run_id), 'cursor' => $cursor];
        $args = [$payload];
        try {
            $action_id = as_enqueue_async_action(CES_AS_BACKFILL_ACTION, $args, CES_AS_GROUP, true);
        } catch (Throwable $throwable) {
            return new WP_Error('backfill_batch_schedule_failed', sanitize_text_field($throwable->getMessage()));
        }
        if (!$action_id && !as_has_scheduled_action(CES_AS_BACKFILL_ACTION, $args, CES_AS_GROUP)) {
            return new WP_Error('backfill_batch_schedule_failed', __('Action Scheduler did not create the retrospective batch action.', 'codi-email-scheduler'));
        }
        $run = self::get_run($run_id);
        if (!$run || (string) ($run['status'] ?? '') !== 'running' || (string) ($run['cursor'] ?? '') !== $cursor) {
            if ($action_id && function_exists('as_unschedule_action')) {
                as_unschedule_action(CES_AS_BACKFILL_ACTION, $args, CES_AS_GROUP);
            }
            return true;
        }
        $updated = $run;
        $updated['next_action_args'] = $payload;
        if (!self::save_run_if_unchanged($run, $updated)) {
            if ($action_id && function_exists('as_unschedule_action')) {
                as_unschedule_action(CES_AS_BACKFILL_ACTION, $args, CES_AS_GROUP);
            }
            return new WP_Error('backfill_state_save_failed', __('The retrospective batch was queued but its run state could not be saved.', 'codi-email-scheduler'));
        }
        return true;
    }

    private static function unschedule_next(array $run): void {
        $payload = $run['next_action_args'] ?? [];
        if ($payload && function_exists('as_unschedule_action')) {
            as_unschedule_action(CES_AS_BACKFILL_ACTION, [$payload], CES_AS_GROUP);
        }
    }

    private static function configuration_hash(array $email): string {
        $relevant = [
            'enabled' => !empty($email['enabled']) ? '1' : '0',
            'event_key' => sanitize_key((string) ($email['event_key'] ?? '')),
            'condition_mode' => sanitize_key((string) ($email['condition_mode'] ?? 'all')),
            'conditions' => CES_Storage::normalize_conditions($email['conditions'] ?? []),
            'delays' => CES_Storage::effective_unique_delays($email['delays'] ?? []),
        ];
        return hash('sha256', wp_json_encode(CES_Engine::canonicalize_value($relevant)));
    }

    private static function is_systemic_candidate_error(WP_Error $error): bool {
        $data = $error->get_error_data();
        if (is_array($data) && !empty($data['runtime_failure'])) {
            return true;
        }

        return in_array(sanitize_key($error->get_error_code()), [
            'event_definition_missing',
            'backfill_email_missing',
            'backfill_not_supported',
            'backfill_configuration_missing',
            'backfill_configuration_changed',
        ], true);
    }



    private static function fail_batch(array $run, WP_Error $error): void {
        if (!self::fail_run($run, $error)) {
            $current = self::get_run((string) ($run['id'] ?? ''));
            if ($current
                && (string) ($current['status'] ?? '') === 'running'
                && (string) ($current['cursor'] ?? '') === (string) ($run['cursor'] ?? '')) {
                self::fail_run($current, $error);
            }
        }
        throw new RuntimeException(sanitize_text_field($error->get_error_message()));
    }

    private static function handle_boundary_save_failure(array $expected): void {
        $current = self::get_run((string) ($expected['id'] ?? ''));
        if (!$current || (string) ($current['status'] ?? '') !== 'running' || (string) ($current['cursor'] ?? '') !== (string) ($expected['cursor'] ?? '')) {
            return;
        }
        self::fail_batch($current, new WP_Error('backfill_progress_save_failed', __('The completed retrospective page could not be saved.', 'codi-email-scheduler')));
    }

    private static function fail_run(array $run, WP_Error $error): bool {
        $updated = $run;
        $updated['status'] = 'failed';
        $updated['last_error'] = self::error_array($error);
        $updated['next_action_args'] = [];
        $updated['snapshot'] = [];
        $updated['distribution_cursors'] = [];
        if (!self::save_run_if_unchanged($run, $updated)) {
            return false;
        }
        self::unschedule_next($run);
        self::release_email((string) ($updated['email_id'] ?? ''), (string) ($updated['id'] ?? ''));
        do_action('ces_backfill_failed', $error, $updated);
        return true;
    }

    private static function error_array(WP_Error $error): array {
        return [
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ];
    }

    private static function claim_email(string $email_id, string $run_id): bool {
        $email_id = sanitize_key($email_id);
        $run_id = sanitize_key($run_id);
        if ($email_id === '' || $run_id === '') {
            return false;
        }
        $option = CES_OPTION_BACKFILL_ACTIVE_PREFIX . $email_id;
        $existing = sanitize_key((string) get_option($option, ''));
        if ($existing === $run_id) {
            return true;
        }
        if ($existing !== '') {
            $run = self::get_run($existing);
            if (!$run || in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true)) {
                return false;
            }
            delete_option($option);
        }
        return add_option($option, $run_id, '', false);
    }

    private static function release_email(string $email_id, string $run_id): void {
        $email_id = sanitize_key($email_id);
        $run_id = sanitize_key($run_id);
        if ($email_id === '' || $run_id === '') {
            return;
        }
        $option = CES_OPTION_BACKFILL_ACTIVE_PREFIX . $email_id;
        if (sanitize_key((string) get_option($option, '')) === $run_id) {
            delete_option($option);
        }
    }

    private static function delete_run(string $run_id): void {
        $run_id = sanitize_key($run_id);
        if ($run_id === '') {
            return;
        }
        $run = self::get_run($run_id);
        delete_option(CES_OPTION_BACKFILL_PREFIX . $run_id);
        if ($run && !empty($run['email_id'])) {
            $email_id = sanitize_key((string) $run['email_id']);
            $option = CES_OPTION_BACKFILL_LATEST_PREFIX . $email_id;
            if (sanitize_key((string) get_option($option, '')) === $run_id) {
                delete_option($option);
            }
        }
    }

    private static function get_run(string $run_id): ?array {
        $run_id = sanitize_key($run_id);
        if ($run_id === '') {
            return null;
        }
        $run = get_option(CES_OPTION_BACKFILL_PREFIX . $run_id, null);
        return is_array($run) ? $run : null;
    }

    private static function save_run_if_unchanged(array $expected, array &$updated): bool {
        $run_id = sanitize_key((string) ($expected['id'] ?? ''));
        if ($run_id === '' || $run_id !== sanitize_key((string) ($updated['id'] ?? ''))) {
            return false;
        }

        $updated['id'] = $run_id;
        $updated['updated_at'] = time();
        return self::conditional_update_option(CES_OPTION_BACKFILL_PREFIX . $run_id, $expected, $updated);
    }

    private static function create_run(array $run): bool {
        $run_id = sanitize_key((string) ($run['id'] ?? ''));
        if ($run_id === '') {
            return false;
        }
        $run['id'] = $run_id;
        $run['updated_at'] = time();
        return add_option(CES_OPTION_BACKFILL_PREFIX . $run_id, $run, '', false);
    }

    private static function set_latest_run(string $email_id, string $run_id): bool {
        $email_id = sanitize_key($email_id);
        $run_id = sanitize_key($run_id);
        if ($email_id === '' || $run_id === '') {
            return false;
        }

        $option = CES_OPTION_BACKFILL_LATEST_PREFIX . $email_id;
        $previous = sanitize_key((string) get_option($option, ''));
        $saved = $previous === ''
            ? add_option($option, $run_id, '', false)
            : update_option($option, $run_id, false);
        if (!$saved && sanitize_key((string) get_option($option, '')) !== $run_id) {
            return false;
        }

        if ($previous !== '' && $previous !== $run_id
            && sanitize_key((string) get_option(CES_OPTION_BACKFILL_ACTIVE_PREFIX . $email_id, '')) !== $previous) {
            delete_option(CES_OPTION_BACKFILL_PREFIX . $previous);
        }
        return true;
    }

    private static function conditional_update_option(string $option, $expected, $updated): bool {
        if (get_option($option, null) !== $expected) {
            return false;
        }

        global $wpdb;
        if (!(isset($wpdb) && is_object($wpdb) && isset($wpdb->options)
            && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')
            && function_exists('maybe_serialize'))) {
            return get_option($option, null) === $expected && update_option($option, $updated, false);
        }

        do_action("update_option_{$option}", $expected, $updated, $option);
        do_action('update_option', $option, $expected, $updated);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize($updated),
            $option,
            maybe_serialize($expected)
        ));
        if ($result !== 1) {
            return false;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($option, 'options');
        }
        do_action("updated_option_{$option}", $expected, $updated, $option);
        do_action('updated_option', $option, $expected, $updated);
        return true;
    }


}
