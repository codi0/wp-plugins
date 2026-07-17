<?php

defined('ABSPATH') || exit;

final class CES_Admin_Form_Handler {
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'handle_request']);
    }

    public static function handle_request(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $post_action = isset($_POST['ces_action']) ? sanitize_key(wp_unslash($_POST['ces_action'])) : '';
        if ($post_action !== '') {
            self::handle_post_action($post_action);
            return;
        }

        $get_action = isset($_GET['ces_action']) ? sanitize_key(wp_unslash($_GET['ces_action'])) : '';
        if ($get_action === 'toggle_email') {
            self::toggle_email();
        }
    }

    private static function handle_post_action(string $action): void {
        if ($action === 'preview_import') {
            self::preview_import();
        }

        if ($action === 'apply_import') {
            self::apply_import();
        }

        if ($action === 'save_email') {
            self::save_email();
        }

        if ($action === 'delete_email') {
            self::delete_email();
        }

        if ($action === 'bulk_emails') {
            self::bulk_emails();
        }

        if ($action === 'save_settings') {
            self::save_settings();
        }

        if ($action === 'reset_default_emails') {
            self::reset_default_emails();
        }

        if ($action === 'send_test_email') {
            self::send_test_email();
        }

        if ($action === 'fire_test_event') {
            self::fire_test_event();
        }

        if ($action === 'preview_backfill') {
            self::preview_backfill();
        }

        if ($action === 'start_backfill') {
            self::start_backfill();
        }

        if ($action === 'pause_backfill') {
            self::pause_backfill();
        }

        if ($action === 'resume_backfill') {
            self::resume_backfill();
        }

        if ($action === 'cancel_backfill') {
            self::cancel_backfill();
        }
    }


    private static function preview_import(): void {
        check_admin_referer('ces_preview_import');
        $file = isset($_FILES['import_file']) && is_array($_FILES['import_file']) ? $_FILES['import_file'] : [];
        $preview = CES_Email_Importer::preview_upload($file, get_current_user_id());
        if (is_wp_error($preview)) {
            set_transient('ces_import_error_' . get_current_user_id(), $preview->get_error_message(), 5 * MINUTE_IN_SECONDS);
            self::redirect_to_settings('import_preview_failed');
        }
        set_transient('ces_import_preview_' . get_current_user_id(), $preview, 15 * MINUTE_IN_SECONDS);
        self::redirect_to_settings('import_previewed');
    }

    private static function apply_import(): void {
        check_admin_referer('ces_apply_import');
        $preview = get_transient('ces_import_preview_' . get_current_user_id());
        $mode = isset($_POST['import_mode']) ? sanitize_key(wp_unslash($_POST['import_mode'])) : 'create_update';
        $enable = !empty($_POST['enable_imported']);
        $result = is_array($preview) ? CES_Email_Importer::apply($preview, $mode, $enable) : new WP_Error('import_preview_expired', __('The import preview has expired. Upload the file again.', 'codi-email-scheduler'));
        if (is_wp_error($result)) {
            set_transient('ces_import_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
            self::redirect_to_settings('import_failed');
        }
        delete_transient('ces_import_preview_' . get_current_user_id());
        set_transient('ces_import_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        self::redirect_to_settings('imported');
    }

    private static function redirect_to_settings(string $message): void {
        wp_safe_redirect(add_query_arg(['page' => CES_Admin::PAGE_SLUG, 'tab' => 'settings', 'message' => $message], admin_url('tools.php')));
        exit;
    }

    private static function save_email(): void {
        check_admin_referer('ces_save_email');

        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $conditions = self::posted_conditions();
        $event_settings = isset($_POST['event_settings']) && is_array($_POST['event_settings']) ? CES_Storage::normalize_settings(wp_unslash($_POST['event_settings'])) : [];
        $posted_delays = isset($_POST['delays']) ? sanitize_textarea_field(wp_unslash($_POST['delays'])) : '';
        $existing_email = $email_id !== '' ? CES_Storage::get_email($email_id) : null;
        $delays = is_array($existing_email)
            ? CES_Storage::reconcile_delay_ids($posted_delays, (array) ($existing_email['delays'] ?? []))
            : CES_Storage::normalize_delays($posted_delays);

        $email = [
            'id'             => $email_id,
            'import_key'     => isset($_POST['import_key']) ? sanitize_key(wp_unslash($_POST['import_key'])) : '',
            'name'           => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'enabled'        => !empty($_POST['enabled']) ? '1' : '0',
            'event_key'      => isset($_POST['event_key']) ? sanitize_key(wp_unslash($_POST['event_key'])) : '',
            'event_settings' => $event_settings,
            'processing_mode' => isset($_POST['processing_mode']) ? sanitize_key(wp_unslash($_POST['processing_mode'])) : 'immediate',
            'condition_mode' => isset($_POST['condition_mode']) ? sanitize_key(wp_unslash($_POST['condition_mode'])) : 'all',
            'conditions'     => $conditions,
            'delays'         => $delays,
            'subject'        => isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '',
            'body'           => isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '',
        ];

        $validated_email = CES_Email_Validator::validate($email, !empty($email['enabled']));
        if (!is_wp_error($validated_email)) {
            $sql_test = self::test_custom_sql_conditions($validated_email, get_current_user_id());
            if (is_wp_error($sql_test)) {
                $validated_email = $sql_test;
            }
        }

        $saved_id = is_wp_error($validated_email) ? $validated_email : CES_Storage::save_email($validated_email);
        if (is_wp_error($saved_id)) {
            set_transient('ces_email_validation_' . get_current_user_id(), [
                'message' => $saved_id->get_error_message(),
                'email'   => $email,
            ], 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg([
                'page'     => CES_Admin::PAGE_SLUG,
                'action'   => $email_id !== '' ? 'edit' : 'add',
                'email_id' => $email_id,
                'message'  => 'validation_failed',
            ], admin_url('tools.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'page'     => CES_Admin::PAGE_SLUG,
            'action'   => 'edit',
            'email_id' => $saved_id,
            'message'  => 'saved',
        ], admin_url('tools.php')));
        exit;
    }

    /**
     * Test custom SQL only during an explicit administrator save. Conditions are
     * otherwise evaluated exclusively for backfill candidates and scheduled sends.
     *
     * @return true|WP_Error
     */
    private static function test_custom_sql_conditions(array $email, int $user_id) {
        foreach ((array) ($email['conditions'] ?? []) as $condition) {
            if (sanitize_key((string) ($condition['key'] ?? '')) !== 'custom_sql_scalar') {
                continue;
            }

            $settings = CES_Storage::normalize_settings($condition['settings'] ?? []);
            $sql = (string) ($settings['sql'] ?? '');
            if (trim($sql) === '') {
                continue;
            }
            $test = CES_Custom_SQL_Condition::test_for_save($sql, absint($user_id));
            if (is_wp_error($test)) {
                return $test;
            }
        }

        return true;
    }

    private static function save_settings(): void {
        check_admin_referer('ces_save_settings');

        $saved = CES_Storage::save_plugin_settings([
            'delay_test_enabled' => !empty($_POST['delay_test_enabled']) ? '1' : '0',
            'delay_test_value'   => isset($_POST['delay_test_value']) ? absint(wp_unslash($_POST['delay_test_value'])) : 2,
            'delay_test_unit'    => isset($_POST['delay_test_unit']) ? sanitize_key(wp_unslash($_POST['delay_test_unit'])) : 'minutes',
            'delay_test_mode'    => isset($_POST['delay_test_mode']) ? sanitize_key(wp_unslash($_POST['delay_test_mode'])) : 'longer_than_test',
        ]);

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'tab'     => 'settings',
            'message' => $saved ? 'settings_saved' : 'settings_save_failed',
        ], admin_url('tools.php')));
        exit;
    }

    private static function delete_email(): void {
        check_admin_referer('ces_delete_email');

        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $deleted = $email_id !== '' && CES_Storage::delete_email($email_id);
        if ($deleted) {
            CES_Backfill::delete_email_state($email_id);
        }

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'message' => $deleted ? 'deleted' : 'delete_failed',
        ], admin_url('tools.php')));
        exit;
    }

    private static function toggle_email(): void {
        $email_id = isset($_GET['email_id']) ? sanitize_key(wp_unslash($_GET['email_id'])) : '';
        $enabled = isset($_GET['enabled']) && sanitize_key(wp_unslash($_GET['enabled'])) === '1' ? '1' : '0';

        if ($email_id === '') {
            wp_safe_redirect(add_query_arg([
                'page'    => CES_Admin::PAGE_SLUG,
                'message' => 'toggle_failed',
            ], admin_url('tools.php')));
            exit;
        }

        check_admin_referer('ces_toggle_email_' . $email_id);

        $emails = CES_Storage::get_emails();
        if (!isset($emails[$email_id])) {
            wp_safe_redirect(add_query_arg([
                'page'    => CES_Admin::PAGE_SLUG,
                'message' => 'toggle_failed',
            ], admin_url('tools.php')));
            exit;
        }

        $emails[$email_id]['enabled'] = $enabled;
        $emails[$email_id]['updated_at'] = current_time('mysql');
        $candidate = CES_Storage::normalize_email($emails[$email_id], $email_id);
        if ($enabled === '1') {
            $validated = CES_Email_Validator::validate($candidate);
            if (is_wp_error($validated)) {
                wp_safe_redirect(add_query_arg([
                    'page'    => CES_Admin::PAGE_SLUG,
                    'message' => 'toggle_failed',
                ], admin_url('tools.php')));
                exit;
            }
            $candidate = $validated;
        }
        $emails[$email_id] = $candidate;
        $saved = CES_Storage::save_emails($emails);

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'message' => $saved ? ($enabled === '1' ? 'enabled' : 'disabled') : 'toggle_failed',
        ], admin_url('tools.php')));
        exit;
    }

    private static function bulk_emails(): void {
        check_admin_referer('ces_bulk_emails');

        $bulk_action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
        $ids = isset($_POST['email_ids']) && is_array($_POST['email_ids']) ? array_map('sanitize_key', wp_unslash($_POST['email_ids'])) : [];
        $ids = array_values(array_filter(array_unique($ids)));

        if (!$ids || !in_array($bulk_action, ['enable', 'disable', 'delete'], true)) {
            wp_safe_redirect(add_query_arg([
                'page'    => CES_Admin::PAGE_SLUG,
                'message' => 'bulk_none',
            ], admin_url('tools.php')));
            exit;
        }

        $emails = CES_Storage::get_emails();
        $changed = 0;
        $invalid = 0;
        $deleted_ids = [];
        foreach ($ids as $id) {
            if (!isset($emails[$id])) {
                continue;
            }

            if ($bulk_action === 'delete') {
                unset($emails[$id]);
                $deleted_ids[] = $id;
                $changed++;
                continue;
            }

            $candidate_data = $emails[$id];
            $candidate_data['enabled'] = $bulk_action === 'enable' ? '1' : '0';
            $candidate_data['updated_at'] = current_time('mysql');
            $candidate = CES_Storage::normalize_email($candidate_data, $id);
            if ($bulk_action === 'enable') {
                $validated = CES_Email_Validator::validate($candidate);
                if (is_wp_error($validated)) {
                    $invalid++;
                    continue;
                }
                $candidate = $validated;
            }
            $emails[$id] = $candidate;
            $changed++;
        }

        $saved = CES_Storage::save_emails($emails);
        if ($saved && $bulk_action === 'delete') {
            foreach ($deleted_ids as $deleted_id) {
                CES_Backfill::delete_email_state($deleted_id);
            }
        }

        $success_message = $bulk_action === 'enable'
            ? 'bulk_enabled'
            : ($bulk_action === 'disable' ? 'bulk_disabled' : 'bulk_deleted');

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'message' => !$saved ? 'bulk_failed' : ($invalid > 0 ? 'bulk_partial' : $success_message),
            'count'   => $changed,
            'invalid' => $invalid,
        ], admin_url('tools.php')));
        exit;
    }

    private static function reset_default_emails(): void {
        check_admin_referer('ces_reset_default_emails');

        $reset = CES_Default_Emails::reset_defaults();

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'tab'     => 'settings',
            'message' => $reset ? 'defaults_reset' : 'defaults_reset_failed',
            'skipped' => $reset ? CES_Default_Emails::last_skipped_count() : 0,
        ], admin_url('tools.php')));
        exit;
    }

    private static function send_test_email(): void {
        check_admin_referer('ces_send_test_email');

        $email_id = isset($_POST['test_email_id']) ? sanitize_key(wp_unslash($_POST['test_email_id'])) : '';
        $recipient = isset($_POST['test_recipient']) ? sanitize_email(wp_unslash($_POST['test_recipient'])) : '';
        $user_id = isset($_POST['test_user_id']) ? absint(wp_unslash($_POST['test_user_id'])) : get_current_user_id();
        $email = $email_id !== '' ? CES_Storage::get_email($email_id) : null;

        if (!$email || !is_email($recipient)) {
            wp_safe_redirect(add_query_arg([
                'page'    => CES_Admin::PAGE_SLUG,
                'tab'     => 'settings',
                'message' => 'test_send_failed',
            ], admin_url('tools.php')));
            exit;
        }

        $context = self::test_context($user_id, $recipient, (string) ($email['event_key'] ?? 'manual_test'));
        $event = !empty($email['event_key']) ? CES_Registry::event((string) $email['event_key']) : null;
        if ($event) {
            $context = CES_Engine::normalize_event_context((string) $email['event_key'], $event, $context);
        }
        $runtime = [
            'phase'     => 'manual_test',
            'blog_id'   => get_current_blog_id(),
            'email_id'  => $email_id,
            'event_key' => sanitize_key((string) ($email['event_key'] ?? 'manual_test')),
        ];

        $dispatch = CES_Email_Dispatcher::dispatch($email, $context, $runtime, [
            'recipient_override' => $recipient,
        ]);
        $sent = !is_wp_error($dispatch) && (string) ($dispatch['status'] ?? '') === 'sent';
        do_action('ces_manual_test_completed', $dispatch, $email, $context, $runtime);

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'tab'     => 'settings',
            'message' => $sent ? 'test_sent' : 'test_send_failed',
        ], admin_url('tools.php')));
        exit;
    }

    private static function fire_test_event(): void {
        check_admin_referer('ces_fire_test_event');

        $event_key = isset($_POST['test_event_key']) ? sanitize_key(wp_unslash($_POST['test_event_key'])) : '';
        $user_id = isset($_POST['test_event_user_id']) ? absint(wp_unslash($_POST['test_event_user_id'])) : get_current_user_id();
        $recipient = isset($_POST['test_event_email']) ? sanitize_email(wp_unslash($_POST['test_event_email'])) : '';
        $extra_context = isset($_POST['test_event_context']) ? sanitize_textarea_field(wp_unslash($_POST['test_event_context'])) : '';
        $event = $event_key !== '' ? CES_Registry::event($event_key) : null;

        if (!$event) {
            wp_safe_redirect(add_query_arg([
                'page'    => CES_Admin::PAGE_SLUG,
                'tab'     => 'settings',
                'message' => 'test_event_failed',
            ], admin_url('tools.php')));
            exit;
        }

        $context = array_merge(
            self::test_context($user_id, $recipient, $event_key),
            self::parse_context_lines($extra_context)
        );
        $context['event_instance_id'] = CES_Event_Identity::occurrence('manual_test', [$event_key, get_current_blog_id()]);

        $result = CES_Engine::fire_event($event_key, $context);
        $scheduled_count = is_wp_error($result) ? 0 : count((array) $result);

        wp_safe_redirect(add_query_arg([
            'page'    => CES_Admin::PAGE_SLUG,
            'tab'     => 'settings',
            'message' => is_wp_error($result) ? 'test_event_failed' : 'test_event_fired',
            'count'   => $scheduled_count,
        ], admin_url('tools.php')));
        exit;
    }

    private static function test_context(int $user_id, string $recipient, string $event_key): array {
        $user = $user_id > 0 ? get_user_by('id', $user_id) : null;
        $roles = $user instanceof WP_User ? array_values(array_filter(array_map('sanitize_key', (array) $user->roles))) : [];
        $email = is_email($recipient) ? $recipient : '';

        if ($email === '' && $user instanceof WP_User && is_email($user->user_email)) {
            $email = sanitize_email($user->user_email);
        }

        return [
            'event_key'          => sanitize_key($event_key),
            'event_time_gmt'     => current_time('mysql', true),
            'event_timestamp'    => time(),
            'blog_id'            => get_current_blog_id(),
            'user_id'            => $user instanceof WP_User ? (int) $user->ID : $user_id,
            'email'              => $email,
            'user_login'         => $user instanceof WP_User ? (string) $user->user_login : '',
            'role'               => $roles[0] ?? '',
            'user_roles'         => $roles,
        ];
    }

    private static function parse_context_lines(string $lines): array {
        $context = [];
        foreach ((array) preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $key = sanitize_key($key);
            if ($key !== '') {
                $context[$key] = sanitize_text_field($value);
            }
        }
        return $context;
    }

    private static function posted_conditions(): array {
        $posted_rows = isset($_POST['condition_rows']) && is_array($_POST['condition_rows']) ? wp_unslash($_POST['condition_rows']) : [];
        $conditions = [];

        foreach ($posted_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $condition_key = sanitize_key((string) ($row['key'] ?? ''));
            if ($condition_key === '') {
                continue;
            }

            $operator = sanitize_key((string) ($row['operator'] ?? 'is_true'));
            if (!in_array($operator, ['is_true', 'is_false'], true)) {
                $operator = 'is_true';
            }

            $conditions[] = [
                'key'      => $condition_key,
                'operator' => $operator,
                'settings' => isset($row['settings']) && is_array($row['settings'])
                    ? CES_Storage::normalize_settings($row['settings'])
                    : [],
            ];
        }

        return $conditions;
    }

    private static function preview_backfill(): void {
        check_admin_referer('ces_backfill_email');
        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $preview = CES_Backfill::preview($email_id, self::posted_backfill_settings());
        set_transient('ces_backfill_preview_' . get_current_user_id(), [
            'email_id' => $email_id,
            'result' => is_wp_error($preview) ? ['error' => $preview->get_error_message()] : $preview,
        ], 10 * MINUTE_IN_SECONDS);
        self::redirect_to_email($email_id, is_wp_error($preview) ? 'backfill_preview_failed' : 'backfill_previewed');
    }

    private static function start_backfill(): void {
        check_admin_referer('ces_backfill_email');
        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $result = CES_Backfill::start($email_id, self::posted_backfill_settings());
        if (is_wp_error($result)) {
            set_transient('ces_backfill_error_' . get_current_user_id(), ['email_id' => $email_id, 'message' => $result->get_error_message()], 5 * MINUTE_IN_SECONDS);
        }
        self::redirect_to_email($email_id, is_wp_error($result) ? 'backfill_failed' : 'backfill_started');
    }

    private static function pause_backfill(): void {
        check_admin_referer('ces_control_backfill');
        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : '';
        self::redirect_to_email($email_id, CES_Backfill::pause($run_id) ? 'backfill_paused' : 'backfill_failed');
    }

    private static function resume_backfill(): void {
        check_admin_referer('ces_control_backfill');
        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : '';
        $result = CES_Backfill::resume($run_id);
        if (is_wp_error($result)) {
            set_transient('ces_backfill_error_' . get_current_user_id(), ['email_id' => $email_id, 'message' => $result->get_error_message()], 5 * MINUTE_IN_SECONDS);
        }
        self::redirect_to_email($email_id, is_wp_error($result) ? 'backfill_failed' : 'backfill_resumed');
    }

    private static function cancel_backfill(): void {
        check_admin_referer('ces_control_backfill');
        $email_id = isset($_POST['email_id']) ? sanitize_key(wp_unslash($_POST['email_id'])) : '';
        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : '';
        self::redirect_to_email($email_id, CES_Backfill::cancel($run_id) ? 'backfill_cancelled' : 'backfill_failed');
    }

    private static function posted_backfill_settings(): array {
        return [
            'rate_per_minute' => isset($_POST['backfill_rate_per_minute']) ? absint(wp_unslash($_POST['backfill_rate_per_minute'])) : 60,
        ];
    }

    private static function redirect_to_email(string $email_id, string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => CES_Admin::PAGE_SLUG,
            'action' => 'edit',
            'email_id' => $email_id,
            'message' => $message,
        ], admin_url('tools.php')));
        exit;
    }
}
