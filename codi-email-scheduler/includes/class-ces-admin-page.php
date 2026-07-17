<?php

defined('ABSPATH') || exit;

final class CES_Admin_Page {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
    }

    public static function admin_menu(): void {
        add_management_page(
            __('Email Scheduler', 'codi-email-scheduler'),
            __('Email Scheduler', 'codi-email-scheduler'),
            'manage_options',
            CES_Admin::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'codi-email-scheduler'));
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'scheduled';
        $edit_id = isset($_GET['email_id']) ? sanitize_key(wp_unslash($_GET['email_id'])) : '';

        if (!in_array($tab, ['scheduled', 'queue', 'settings'], true)) {
            $tab = 'scheduled';
        }

        echo '<div class="wrap ces-wrap">';
        echo '<div class="ces-page-heading">';
        echo '<h1>' . esc_html__('Email Scheduler', 'codi-email-scheduler') . '</h1>';
        echo '</div>';

        self::render_messages();

        if ($action === 'edit' || $action === 'add' || $action === 'copy') {
            self::render_editor($edit_id, $action === 'copy');
            echo '</div>';
            return;
        }

        self::render_tabs($tab);
        self::render_status_box();

        if ($tab === 'queue') {
            self::render_queue_page();
        } elseif ($tab === 'settings') {
            self::render_testing_settings();
        } else {
            self::render_list();
        }

        echo '</div>';
    }

    private static function render_tabs(string $active_tab): void {
        $tabs = [
            'scheduled' => __('Emails', 'codi-email-scheduler'),
            'queue'     => __('Queue', 'codi-email-scheduler'),
            'settings'  => __('Settings', 'codi-email-scheduler'),
        ];

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Email Scheduler tabs', 'codi-email-scheduler') . '">';
        foreach ($tabs as $tab => $label) {
            $url = add_query_arg([
                'page' => CES_Admin::PAGE_SLUG,
                'tab'  => $tab,
            ], admin_url('tools.php'));
            $class = 'nav-tab' . ($active_tab === $tab ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function render_messages(): void {
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';

        if ($message === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email saved.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'validation_failed') {
            $validation = get_transient('ces_email_validation_' . get_current_user_id());
            $error_message = is_array($validation) ? sanitize_text_field((string) ($validation['message'] ?? '')) : '';
            echo '<div class="notice notice-error"><p>' . esc_html($error_message !== '' ? $error_message : __('Email configuration is invalid.', 'codi-email-scheduler')) . '</p></div>';
        }

        if ($message === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email deleted.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'delete_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Email could not be deleted.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'enabled' || $message === 'disabled') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message === 'enabled' ? __('Scheduled email enabled.', 'codi-email-scheduler') : __('Scheduled email disabled.', 'codi-email-scheduler')) . '</p></div>';
        }

        if ($message === 'toggle_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Scheduled email could not be updated.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'settings_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Testing settings saved.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'settings_save_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Testing settings could not be saved.', 'codi-email-scheduler') . '</p></div>';
        }

        if (in_array($message, ['import_preview_failed', 'import_failed'], true)) {
            $error = get_transient('ces_import_error_' . get_current_user_id());
            delete_transient('ces_import_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html(is_string($error) && $error !== '' ? $error : __('The email import could not be processed.', 'codi-email-scheduler')) . '</p></div>';
        }

        if ($message === 'import_previewed') {
            echo '<div class="notice notice-info"><p>' . esc_html__('Import preview generated. Review the changes below before applying them.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'imported') {
            $result = get_transient('ces_import_result_' . get_current_user_id());
            delete_transient('ces_import_result_' . get_current_user_id());
            $result = is_array($result) ? $result : [];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                __('Import complete: %1$d created, %2$d updated, %3$d unchanged, %4$d skipped.', 'codi-email-scheduler'),
                absint($result['created'] ?? 0), absint($result['updated'] ?? 0), absint($result['unchanged'] ?? 0), absint($result['skipped'] ?? 0)
            )) . '</p></div>';
        }

        if (in_array($message, ['bulk_enabled', 'bulk_disabled', 'bulk_deleted'], true)) {
            $count = isset($_GET['count']) ? absint(wp_unslash($_GET['count'])) : 0;
            if ($message === 'bulk_enabled') {
                $text = sprintf(_n('%d scheduled email enabled.', '%d scheduled emails enabled.', $count, 'codi-email-scheduler'), $count);
            } elseif ($message === 'bulk_disabled') {
                $text = sprintf(_n('%d scheduled email disabled.', '%d scheduled emails disabled.', $count, 'codi-email-scheduler'), $count);
            } else {
                $text = sprintf(_n('%d scheduled email deleted.', '%d scheduled emails deleted.', $count, 'codi-email-scheduler'), $count);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
        }

        if ($message === 'bulk_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Scheduled emails could not be updated.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'bulk_none') {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Select at least one scheduled email and a valid bulk action.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'bulk_partial') {
            $count = isset($_GET['count']) ? absint(wp_unslash($_GET['count'])) : 0;
            $invalid = isset($_GET['invalid']) ? absint(wp_unslash($_GET['invalid'])) : 0;
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf(__('%1$d scheduled emails updated; %2$d invalid emails were not enabled.', 'codi-email-scheduler'), $count, $invalid)) . '</p></div>';
        }

        if ($message === 'defaults_reset') {
            $skipped = isset($_GET['skipped']) ? absint(wp_unslash($_GET['skipped'])) : 0;
            if ($skipped > 0) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf(
                    _n('Default scheduled emails reset; %d integration-dependent default was unavailable.', 'Default scheduled emails reset; %d integration-dependent defaults were unavailable.', $skipped, 'codi-email-scheduler'),
                    $skipped
                )) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Default scheduled emails reset.', 'codi-email-scheduler') . '</p></div>';
            }
        }

        if ($message === 'defaults_reset_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Default scheduled emails could not be reset.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'test_sent') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Manual test email sent.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'test_send_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Manual test email could not be sent. Check the selected email, recipient address, and wp_mail/SES configuration.', 'codi-email-scheduler') . '</p></div>';
        }

        if ($message === 'test_event_fired') {
            $count = isset($_GET['count']) ? absint(wp_unslash($_GET['count'])) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('Manual test event fired. %d action was scheduled.', 'Manual test event fired. %d actions were scheduled.', $count, 'codi-email-scheduler'), $count)) . '</p></div>';
        }

        if ($message === 'test_event_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Manual test event could not be fired. Check the selected event and context.', 'codi-email-scheduler') . '</p></div>';
        }
    }

    private static function render_status_box(): void {
        $events = CES_Registry::events();
        $conditions = CES_Registry::conditions();
        $as_available = CES_Engine::action_scheduler_available();
        $action_scheduler_url = admin_url('tools.php?page=action-scheduler&s=' . rawurlencode(CES_AS_ACTION));
        $queue_url = add_query_arg([
            'page' => CES_Admin::PAGE_SLUG,
            'tab'  => 'queue',
        ], admin_url('tools.php'));
        $pending_count = self::pending_queue_count();

        echo '<div class="ces-status-grid">';
        echo '<div class="ces-status-card ces-queue-card">';
        echo '<div class="ces-status-label">' . esc_html__('Email queue', 'codi-email-scheduler') . '</div>';
        echo '<div class="ces-status-value">' . ($pending_count === null ? '&mdash;' : esc_html(sprintf(_n('%d pending action', '%d pending actions', $pending_count, 'codi-email-scheduler'), $pending_count))) . '</div>';
        echo '<div class="ces-status-note ces-status-health"><span class="ces-health-dot ' . ($as_available ? 'is-good' : 'is-bad') . '" aria-hidden="true"></span>' . esc_html(sprintf(__('Action Scheduler: %s', 'codi-email-scheduler'), CES_Engine::action_scheduler_status_label())) . '</div>';
        echo '<div class="ces-status-links"><a href="' . esc_url($queue_url) . '">' . esc_html__('View email queue', 'codi-email-scheduler') . '</a><a href="' . esc_url($action_scheduler_url) . '">' . esc_html__('Open Action Scheduler', 'codi-email-scheduler') . '</a></div>';
        echo '</div>';

        echo '<div class="ces-status-card">';
        echo '<div class="ces-status-label">' . esc_html__('Registry', 'codi-email-scheduler') . '</div>';
        echo '<div class="ces-status-value">' . esc_html(sprintf(__('%d events', 'codi-email-scheduler'), count($events))) . '</div>';
        echo '<div class="ces-status-note">' . esc_html(sprintf(__('%d conditions', 'codi-email-scheduler'), count($conditions))) . '</div>';
        echo '</div>';

        if (CES_Storage::delay_test_enabled()) {
            $settings = CES_Storage::get_plugin_settings();
            $test_delay = CES_Storage::delay_label([
                'value' => absint($settings['delay_test_value'] ?? 2),
                'unit'  => (string) ($settings['delay_test_unit'] ?? 'minutes'),
            ]);
            echo '<div class="ces-status-card ces-warning-card">';
            echo '<div class="ces-status-label">' . esc_html__('Settings', 'codi-email-scheduler') . '</div>';
            echo '<div class="ces-status-value ces-status-bad">' . esc_html__('Delay override on', 'codi-email-scheduler') . '</div>';
            echo '<div class="ces-status-note">' . esc_html(sprintf(__('Long delays may be scheduled as %s.', 'codi-email-scheduler'), $test_delay)) . '</div>';
            echo '</div>';
        }

        $registration_errors = CES_Registry::registration_errors();
        if ($registration_errors) {
            echo '<div class="ces-status-card ces-warning-card">';
            echo '<div class="ces-status-label">' . esc_html__('Registry warnings', 'codi-email-scheduler') . '</div>';
            echo '<div class="ces-status-value ces-status-bad">' . esc_html(sprintf(_n('%d definition error', '%d definition errors', count($registration_errors), 'codi-email-scheduler'), count($registration_errors))) . '</div>';
            echo '<div class="ces-status-note">' . esc_html__('One or more code-defined events, conditions, or tokens failed registration.', 'codi-email-scheduler') . '</div>';
            echo '<ul class="ces-status-list">';
            foreach (array_slice($registration_errors, 0, 5) as $error) {
                if (!$error instanceof WP_Error) {
                    continue;
                }
                $data = $error->get_error_data();
                $definition = self::registration_error_definition_label(is_array($data) ? $data : []);
                $message = $definition !== ''
                    ? sprintf('%1$s: %2$s', $definition, $error->get_error_message())
                    : $error->get_error_message();
                echo '<li><code>' . esc_html($error->get_error_code()) . '</code> ' . esc_html($message) . '</li>';
            }
            if (count($registration_errors) > 5) {
                echo '<li>' . esc_html(sprintf(__('Plus %d more definition errors.', 'codi-email-scheduler'), count($registration_errors) - 5)) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        if (self::woocommerce_runtime_warning_needed()) {
            echo '<div class="ces-status-card ces-warning-card">';
            echo '<div class="ces-status-label">' . esc_html__('WooCommerce runtime', 'codi-email-scheduler') . '</div>';
            echo '<div class="ces-status-value ces-status-bad">' . esc_html__('Not loaded', 'codi-email-scheduler') . '</div>';
            echo '<div class="ces-status-note">' . esc_html__('Enabled WooCommerce-dependent emails need WooCommerce loaded when Action Scheduler runs them.', 'codi-email-scheduler') . '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    private static function registration_error_definition_label(array $data): string {
        foreach (['event_key', 'condition_key', 'token_key', 'definition_key', 'key'] as $field) {
            if (!empty($data[$field]) && is_scalar($data[$field])) {
                return sanitize_text_field((string) $data[$field]);
            }
        }

        if (!empty($data['type']) && is_scalar($data['type'])) {
            return sanitize_text_field((string) $data['type']);
        }

        return '';
    }

    private static function render_importer(): void {
        $preview = get_transient('ces_import_preview_' . get_current_user_id());

        echo '<div class="ces-card" id="ces-import-emails">';
        echo '<h2>' . esc_html__('Import scheduled emails', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('Upload a Codi Email Scheduler JSON file. Existing emails are matched only by their stable import key; names are never used for matching.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="" enctype="multipart/form-data">';
        wp_nonce_field('ces_preview_import');
        echo '<input type="hidden" name="ces_action" value="preview_import">';
        echo '<input type="file" name="import_file" accept="application/json,.json" required> ';
        submit_button(__('Preview import', 'codi-email-scheduler'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('Maximum file size: 1 MB. Previewing does not change stored emails.', 'codi-email-scheduler') . '</p>';
        echo '</form>';

        if (is_array($preview) && !empty($preview['items'])) {
            $counts = (array) ($preview['counts'] ?? []);
            echo '<hr><h3>' . esc_html__('Import preview', 'codi-email-scheduler') . '</h3>';
            echo '<p>' . esc_html(sprintf(
                __('%1$d new, %2$d updates, %3$d unchanged, %4$d invalid.', 'codi-email-scheduler'),
                absint($counts['new'] ?? 0), absint($counts['update'] ?? 0), absint($counts['unchanged'] ?? 0), absint($counts['invalid'] ?? 0)
            )) . '</p>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Import key', 'codi-email-scheduler') . '</th><th>' . esc_html__('Email', 'codi-email-scheduler') . '</th><th>' . esc_html__('Status', 'codi-email-scheduler') . '</th><th>' . esc_html__('Details', 'codi-email-scheduler') . '</th></tr></thead><tbody>';
            foreach ($preview['items'] as $item) {
                $status = sanitize_key((string) ($item['status'] ?? 'invalid'));
                $details = [];
                if (!empty($item['diff'])) { $details[] = sprintf(__('Changed: %s', 'codi-email-scheduler'), implode(', ', array_map('sanitize_text_field', (array) $item['diff']))); }
                if (!empty($item['errors'])) { $details = array_merge($details, array_map('sanitize_text_field', (array) $item['errors'])); }
                echo '<tr><td><code>' . esc_html((string) ($item['key'] ?? '')) . '</code></td><td>' . esc_html((string) ($item['name'] ?? '')) . '</td><td>' . esc_html(ucfirst($status)) . '</td><td>' . esc_html(implode(' ', $details)) . '</td></tr>';
            }
            echo '</tbody></table>';

            if (empty($counts['invalid'])) {
                echo '<form method="post" action="" style="margin-top:16px">';
                wp_nonce_field('ces_apply_import');
                echo '<input type="hidden" name="ces_action" value="apply_import">';
                echo '<p><label for="ces-import-mode"><strong>' . esc_html__('Import mode', 'codi-email-scheduler') . '</strong></label><br>';
                echo '<select id="ces-import-mode" name="import_mode">';
                echo '<option value="create_update">' . esc_html__('Create new and update matches', 'codi-email-scheduler') . '</option>';
                echo '<option value="create_only">' . esc_html__('Create new only', 'codi-email-scheduler') . '</option>';
                echo '<option value="update_only">' . esc_html__('Update matches only', 'codi-email-scheduler') . '</option>';
                echo '</select></p>';
                echo '<p><label><input type="checkbox" name="enable_imported" value="1"> ' . esc_html__('Apply enabled values from the import file', 'codi-email-scheduler') . '</label></p>';
                echo '<p class="description">' . esc_html__('Without this option, new emails are created disabled and existing emails keep their current enabled state. Unrelated emails are never changed or deleted.', 'codi-email-scheduler') . '</p>';
                submit_button(__('Apply import', 'codi-email-scheduler'), 'primary');
                echo '</form>';
            }
        }
        echo '</div>';
    }

    private static function render_testing_settings(): void {
        $settings = CES_Storage::get_plugin_settings();
        $emails = CES_Storage::get_emails();
        $events = CES_Registry::events();
        $current_user = wp_get_current_user();
        $current_email = $current_user instanceof WP_User ? (string) $current_user->user_email : '';

        self::render_importer();

        echo '<div class="ces-card">';
        echo '<h2>' . esc_html__('Settings', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('Configure development delay testing.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="">';
        wp_nonce_field('ces_save_settings');
        echo '<input type="hidden" name="ces_action" value="save_settings">';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Delay test mode', 'codi-email-scheduler') . '</th><td>';
        echo '<label><input type="checkbox" name="delay_test_enabled" value="1" ' . checked($settings['delay_test_enabled'], '1', false) . '> ' . esc_html__('Enable delay test mode', 'codi-email-scheduler') . '</label>';
        echo '<p class="description">' . esc_html__('Saved scheduled email delays are not changed. The override only applies when new actions are scheduled.', 'codi-email-scheduler') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="delay_test_value">' . esc_html__('Test delay', 'codi-email-scheduler') . '</label></th><td>';
        echo '<input type="number" id="delay_test_value" name="delay_test_value" min="1" value="' . esc_attr((string) ($settings['delay_test_value'] ?? 2)) . '" class="small-text"> ';
        echo '<select name="delay_test_unit">';
        foreach (['minutes', 'hours', 'days'] as $unit) {
            echo '<option value="' . esc_attr($unit) . '" ' . selected($settings['delay_test_unit'], $unit, false) . '>' . esc_html(ucfirst($unit)) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="delay_test_mode">' . esc_html__('Apply to', 'codi-email-scheduler') . '</label></th><td>';
        echo '<select id="delay_test_mode" name="delay_test_mode">';
        echo '<option value="longer_than_test" ' . selected($settings['delay_test_mode'], 'longer_than_test', false) . '>' . esc_html__('Only configured delays longer than the test delay', 'codi-email-scheduler') . '</option>';
        echo '<option value="all_non_zero" ' . selected($settings['delay_test_mode'], 'all_non_zero', false) . '>' . esc_html__('All non-zero configured delays', 'codi-email-scheduler') . '</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button(__('Save settings', 'codi-email-scheduler'));
        echo '</form>';
        echo '</div>';

        echo '<div class="ces-card">';
        echo '<h2>' . esc_html__('Send test email', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('Runs the production validation, rules, recipient, token, and mail pipeline immediately, then replaces the resolved destination with the test recipient. It does not create an Action Scheduler action.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="">';
        wp_nonce_field('ces_send_test_email');
        echo '<input type="hidden" name="ces_action" value="send_test_email">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="test_email_id">' . esc_html__('Scheduled email', 'codi-email-scheduler') . '</label></th><td><select id="test_email_id" name="test_email_id">';
        foreach ($emails as $email) {
            echo '<option value="' . esc_attr($email['id']) . '">' . esc_html($email['name'] ?: $email['id']) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="test_user_id">' . esc_html__('User ID for tokens', 'codi-email-scheduler') . '</label></th><td><input type="number" id="test_user_id" name="test_user_id" min="1" value="' . esc_attr((string) get_current_user_id()) . '" class="small-text"><p class="description">' . esc_html__('Used for user tokens such as first name, display name, roles, and organisation context.', 'codi-email-scheduler') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="test_recipient">' . esc_html__('Send test to', 'codi-email-scheduler') . '</label></th><td><input type="email" id="test_recipient" name="test_recipient" value="' . esc_attr($current_email) . '" class="regular-text"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Send test email', 'codi-email-scheduler'), 'secondary');
        echo '</form>';
        echo '</div>';

        echo '<div class="ces-card">';
        echo '<h2>' . esc_html__('Fire test event', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('Fires an event through the real scheduling engine. Enabled scheduled emails for that event may queue Action Scheduler actions if their event settings and filters match.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="" data-confirm="' . esc_attr__('Fire this test event? Matching enabled scheduled emails may queue real test actions.', 'codi-email-scheduler') . '">';
        wp_nonce_field('ces_fire_test_event');
        echo '<input type="hidden" name="ces_action" value="fire_test_event">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="test_event_key">' . esc_html__('Event', 'codi-email-scheduler') . '</label></th><td><select id="test_event_key" name="test_event_key">';
        foreach ($events as $event_key => $event) {
            echo '<option value="' . esc_attr((string) $event_key) . '">' . esc_html((string) ($event['label'] ?? $event_key)) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="test_event_user_id">' . esc_html__('User ID', 'codi-email-scheduler') . '</label></th><td><input type="number" id="test_event_user_id" name="test_event_user_id" min="1" value="' . esc_attr((string) get_current_user_id()) . '" class="small-text"></td></tr>';
        echo '<tr><th scope="row"><label for="test_event_email">' . esc_html__('Context email', 'codi-email-scheduler') . '</label></th><td><input type="email" id="test_event_email" name="test_event_email" value="' . esc_attr($current_email) . '" class="regular-text"></td></tr>';
        echo '<tr><th scope="row"><label for="test_event_context">' . esc_html__('Extra context', 'codi-email-scheduler') . '</label></th><td><textarea id="test_event_context" name="test_event_context" rows="5" class="large-text code" placeholder="meta_key=first_name&#10;previous_meta_value=&#10;meta_value=Alex"></textarea><p class="description">' . esc_html__('Optional key=value lines. Useful for parameterised events such as WordPress user meta changed.', 'codi-email-scheduler') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Fire test event', 'codi-email-scheduler'), 'secondary');
        echo '</form>';
        echo '</div>';

        echo '<div class="ces-card ces-danger-zone">';
        echo '<h2>' . esc_html__('Default emails', 'codi-email-scheduler') . '</h2>';
        echo '<p>' . esc_html__('Reset the bundled default scheduled emails to the latest version. This removes old default duplicates and replaces current default templates. Custom scheduled emails are not removed.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="" data-confirm="' . esc_attr__('Reset the default scheduled emails? This will replace the bundled defaults and remove older default duplicates.', 'codi-email-scheduler') . '">';
        wp_nonce_field('ces_reset_default_emails');
        echo '<input type="hidden" name="ces_action" value="reset_default_emails">';
        submit_button(__('Reset default emails', 'codi-email-scheduler'), 'secondary');
        echo '</form>';
        echo '</div>';
    }

    private static function render_list(): void {
        $emails = CES_Storage::get_emails();
        $add_url = add_query_arg([
            'page'   => CES_Admin::PAGE_SLUG,
            'action' => 'add',
        ], admin_url('tools.php'));
        $import_url = add_query_arg([
            'page' => CES_Admin::PAGE_SLUG,
            'tab'  => 'settings',
        ], admin_url('tools.php')) . '#ces-import-emails';

        echo '<div class="ces-toolbar">';
        echo '<div>';
        echo '<h2>' . esc_html__('Scheduled Emails', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('Each scheduled email listens for one event, schedules one or more delayed actions, and sends only if its send conditions still match.', 'codi-email-scheduler') . '</p>';
        echo '</div>';
        echo '<div class="ces-toolbar-actions">';
        echo '<a class="button button-primary" href="' . esc_url($add_url) . '">' . esc_html__('Add Scheduled Email', 'codi-email-scheduler') . '</a> ';
        echo '<a class="button button-secondary" href="' . esc_url($import_url) . '">' . esc_html__('Import emails', 'codi-email-scheduler') . '</a>';
        echo '</div>';
        echo '</div>';

        if (!$emails) {
            echo '<div class="ces-card"><p>' . esc_html__('No scheduled emails configured yet.', 'codi-email-scheduler') . '</p></div>';
            return;
        }

        echo '<form method="post" action="" id="ces-bulk-email-form" data-delete-confirm="' . esc_attr__('Permanently delete the selected scheduled emails? This cannot be undone.', 'codi-email-scheduler') . '">';
        wp_nonce_field('ces_bulk_emails');
        echo '<input type="hidden" name="ces_action" value="bulk_emails">';
        echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
        echo '<label for="ces-bulk-action" class="screen-reader-text">' . esc_html__('Bulk action', 'codi-email-scheduler') . '</label>';
        echo '<select id="ces-bulk-action" name="bulk_action">';
        echo '<option value="">' . esc_html__('Bulk actions', 'codi-email-scheduler') . '</option>';
        echo '<option value="enable">' . esc_html__('Enable selected', 'codi-email-scheduler') . '</option>';
        echo '<option value="disable">' . esc_html__('Disable selected', 'codi-email-scheduler') . '</option>';
        echo '<option value="delete">' . esc_html__('Delete selected', 'codi-email-scheduler') . '</option>';
        echo '</select> ';
        submit_button(__('Apply', 'codi-email-scheduler'), 'secondary action', 'submit', false);
        echo '</div><br class="clear"></div>';

        echo '<table class="widefat striped ces-table ces-email-overview-table">';
        echo '<thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="ces-select-all-emails"></td>';
        echo '<th class="ces-col-email">' . esc_html__('Scheduled Email', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-status">' . esc_html__('Status', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-event">' . esc_html__('Event', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-delays">' . esc_html__('Delays', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-conditions">' . esc_html__('Send conditions', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-actions">' . esc_html__('Actions', 'codi-email-scheduler') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($emails as $email) {
            $edit_url = add_query_arg([
                'page'     => CES_Admin::PAGE_SLUG,
                'action'   => 'edit',
                'email_id' => $email['id'],
            ], admin_url('tools.php'));
            $copy_url = add_query_arg([
                'page'     => CES_Admin::PAGE_SLUG,
                'action'   => 'copy',
                'email_id' => $email['id'],
            ], admin_url('tools.php'));

            $enabled = $email['enabled'] === '1';
            $toggle_url = wp_nonce_url(add_query_arg([
                'page'       => CES_Admin::PAGE_SLUG,
                'ces_action' => 'toggle_email',
                'email_id'   => $email['id'],
                'enabled'    => $enabled ? '0' : '1',
            ], admin_url('tools.php')), 'ces_toggle_email_' . $email['id']);
            $toggle_label = $enabled ? __('Disable', 'codi-email-scheduler') : __('Enable', 'codi-email-scheduler');
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="email_ids[]" value="' . esc_attr($email['id']) . '"></th>';
            echo '<td class="ces-email-title-cell"><strong class="ces-email-title"><a href="' . esc_url($edit_url) . '">' . esc_html($email['name'] ?: $email['id']) . '</a></strong><code class="ces-email-key">' . esc_html($email['id']) . '</code></td>';
            echo '<td class="ces-status-cell"><span class="ces-pill ' . ($enabled ? 'ces-pill-enabled' : 'ces-pill-disabled') . '">' . ($enabled ? esc_html__('Enabled', 'codi-email-scheduler') : esc_html__('Disabled', 'codi-email-scheduler')) . '</span></td>';
            echo '<td class="ces-event-cell"><span class="ces-primary-cell-text">' . esc_html(self::event_label($email['event_key'])) . '</span><span class="description ces-cell-meta">' . esc_html(self::settings_label($email['event_settings'] ?? [])) . '</span></td>';
            echo '<td class="ces-delays-cell">' . self::render_delays_list($email['delays']) . '</td>';
            echo '<td class="ces-conditions-cell">' . esc_html(self::conditions_label($email)) . '</td>';
            echo '<td class="ces-row-actions"><a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'codi-email-scheduler') . '</a><a href="' . esc_url($copy_url) . '">' . esc_html__('Copy', 'codi-email-scheduler') . '</a><a href="' . esc_url($toggle_url) . '">' . esc_html($toggle_label) . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
            }

    private static function render_editor(string $email_id = '', bool $is_copy = false): void {
        $is_existing = $email_id !== '' && !$is_copy;
        $source_email = $email_id !== '' ? CES_Storage::get_email($email_id) : null;
        $email = $is_existing ? $source_email : null;

        if ($is_copy && $source_email) {
            $email = $source_email;
            $email['id'] = '';
            $email['import_key'] = '';
            $email['enabled'] = '0';
            $email['name'] = sprintf(__('Copy of %s', 'codi-email-scheduler'), (string) ($source_email['name'] ?: $source_email['id']));
        }

        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        if ($message === 'validation_failed') {
            $validation = get_transient('ces_email_validation_' . get_current_user_id());
            if (is_array($validation) && isset($validation['email']) && is_array($validation['email'])) {
                $email = CES_Storage::normalize_email($validation['email'], $email_id);
            }
            delete_transient('ces_email_validation_' . get_current_user_id());
        }

        if (!$email) {
            $email = CES_Storage::normalize_email([
                'enabled'        => '1',
                'processing_mode' => 'immediate',
                'condition_mode' => 'all',
                'conditions'     => [],
                'delays'         => [['value' => 0, 'unit' => 'minutes']],
            ]);
        }

        $events = CES_Registry::events();
        $conditions = CES_Registry::conditions();
        $tokens = CES_Registry::available_tokens_for_event((string) $email['event_key']);

        $back_url = add_query_arg([
            'page'   => CES_Admin::PAGE_SLUG,
        ], admin_url('tools.php'));

        $page_title = $is_existing ? __('Edit Scheduled Email', 'codi-email-scheduler') : ($is_copy ? __('Copy Scheduled Email', 'codi-email-scheduler') : __('Add Scheduled Email', 'codi-email-scheduler'));
        echo '<div class="ces-editor-header">';
        echo '<div><a class="ces-back-link" href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to scheduled emails', 'codi-email-scheduler') . '</a>';
        echo '<h2>' . esc_html($page_title) . '</h2>';
        if (!empty($email['name'])) {
            echo '<p class="ces-editor-subtitle">' . esc_html((string) $email['name']) . '</p>';
        }
        echo '</div>';
        echo '<span class="ces-pill ' . (!empty($email['enabled']) ? 'ces-pill-enabled' : 'ces-pill-disabled') . '">' . esc_html(!empty($email['enabled']) ? __('Enabled', 'codi-email-scheduler') : __('Disabled', 'codi-email-scheduler')) . '</span>';
        echo '</div>';

        if (!$events) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No events have been registered. Register events in code with ces_register_event() before this email can run.', 'codi-email-scheduler') . '</p></div>';
        }

        self::render_configuration_warnings($email);

        echo '<div class="ces-card ces-form-card">';
        echo '<form method="post" action="">';
        wp_nonce_field('ces_save_email');
        echo '<input type="hidden" name="ces_action" value="save_email">';
        echo '<input type="hidden" name="email_id" value="' . esc_attr($email['id']) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        self::render_section_heading(__('Basic settings', 'codi-email-scheduler'), __('Name the email and choose whether it can schedule when its event fires.', 'codi-email-scheduler'));
        self::render_text_row(__('Name', 'codi-email-scheduler'), 'name', $email['name'], __('Internal label shown in this admin screen.', 'codi-email-scheduler'));

        echo '<tr><th scope="row">' . esc_html__('Enabled', 'codi-email-scheduler') . '</th><td>';
        echo '<label class="ces-toggle-control"><input type="checkbox" id="ces_enabled" name="enabled" value="1" ' . checked($email['enabled'], '1', false) . '><span class="ces-toggle" aria-hidden="true"></span><span><strong>' . esc_html__('Enabled', 'codi-email-scheduler') . '</strong><span class="description">' . esc_html__('Schedule and send this email when its event fires.', 'codi-email-scheduler') . '</span></span></label>';
        echo '</td></tr>';

        self::render_section_heading(__('Trigger', 'codi-email-scheduler'), __('Choose the event that starts the clock.', 'codi-email-scheduler'));
        self::render_event_row($email, $events);
        self::render_processing_mode_row($email);
        self::render_section_heading(__('Scheduling', 'codi-email-scheduler'), __('Set one or more delays from the event time.', 'codi-email-scheduler'));
        self::render_delays_row($email);
        self::render_section_heading(__('Send rules', 'codi-email-scheduler'), __('Conditions are checked when the queued action runs.', 'codi-email-scheduler'));
        self::render_conditions_row($email, $conditions);
        self::render_section_heading(__('Email content', 'codi-email-scheduler'), __('Use simple text and links. Tokens are replaced at send time.', 'codi-email-scheduler'));
        self::render_text_row(__('Subject', 'codi-email-scheduler'), 'subject', $email['subject']);
        self::render_body_row($email);
        self::render_tokens_row($tokens);

        echo '</tbody></table>';
        echo '<div class="ces-save-bar"><span class="ces-save-note">' . esc_html__('Changes take effect after saving.', 'codi-email-scheduler') . '</span>';
        submit_button(__('Save scheduled email', 'codi-email-scheduler'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>';
        echo '</div>';

        if ($is_existing && $email['id']) {
            self::render_backfill_panel($email);
            self::render_delete_form($email['id']);
        }
    }


    private static function render_backfill_panel(array $email): void {
        $event = CES_Registry::event((string) ($email['event_key'] ?? ''));
        echo '<div class="ces-card ces-backfill-card">';
        echo '<div class="ces-panel-heading"><h2>' . esc_html__('Retrospective enrollment', 'codi-email-scheduler') . '</h2><p>' . esc_html__('Review existing users and schedule eligible recipients using this email’s current rules.', 'codi-email-scheduler') . '</p></div>';

        if (!$event || !CES_Backfill::event_supports_backfill($event)) {
            echo '<p>' . esc_html__('This event does not support current-state user enrollment.', 'codi-email-scheduler') . '</p>';
            echo '</div>';
            return;
        }

        $run = CES_Backfill::active_run_for_email((string) $email['id']);
        if (!$run) {
            $run = CES_Backfill::latest_run_for_email((string) $email['id']);
        }
        if ($run) {
            echo '<p><strong>' . esc_html__('Latest run:', 'codi-email-scheduler') . '</strong> ' . esc_html(ucfirst((string) ($run['status'] ?? 'unknown'))) . '</p>';
            echo '<p>' . esc_html(sprintf(
                __('Evaluated: %1$d · Eligible: %2$d · Scheduled actions: %3$d · Excluded: %4$d · Errors: %5$d', 'codi-email-scheduler'),
                absint($run['evaluated'] ?? 0),
                absint($run['eligible'] ?? 0),
                absint($run['scheduled'] ?? 0),
                absint($run['excluded'] ?? 0),
                absint($run['errors'] ?? 0)
            )) . '</p>';
            if (!empty($run['last_error']['message'])) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html((string) $run['last_error']['message']) . '</p></div>';
            }
            if (in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true)) {
                echo '<form method="post" action="" class="ces-inline-actions">';
                wp_nonce_field('ces_control_backfill');
                echo '<input type="hidden" name="email_id" value="' . esc_attr((string) $email['id']) . '">';
        echo '<input type="hidden" name="import_key" value="' . esc_attr((string) ($email['import_key'] ?? '')) . '">';
                echo '<input type="hidden" name="run_id" value="' . esc_attr((string) $run['id']) . '">';
                if (($run['status'] ?? '') === 'running') {
                    echo '<button type="submit" class="button" name="ces_action" value="pause_backfill">' . esc_html__('Pause', 'codi-email-scheduler') . '</button>';
                } else {
                    echo '<button type="submit" class="button" name="ces_action" value="resume_backfill">' . esc_html__('Resume', 'codi-email-scheduler') . '</button>';
                }
                echo ' ';
                echo '<button type="submit" class="button button-link-delete" name="ces_action" value="cancel_backfill">' . esc_html__('Cancel', 'codi-email-scheduler') . '</button>';
                echo '</form>';
            }
        }

        $preview_notice = get_transient('ces_backfill_preview_' . get_current_user_id());
        $preview = is_array($preview_notice) && ($preview_notice['email_id'] ?? '') === (string) $email['id']
            ? ($preview_notice['result'] ?? null)
            : null;
        if (is_array($preview)) {
            delete_transient('ces_backfill_preview_' . get_current_user_id());
            if (!empty($preview['error'])) {
                echo '<div class="notice notice-error inline"><p>' . esc_html((string) $preview['error']) . '</p></div>';
            } else {
                $candidate_label = $preview['candidate_count'] === null
                    ? __('Candidate total unavailable', 'codi-email-scheduler')
                    : sprintf(__('%d existing users', 'codi-email-scheduler'), absint($preview['candidate_count']));
                echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                    __('Sample only: %1$s total. The first %2$d users sampled included %3$d eligible, %4$d excluded, and %5$d errors. The actual run evaluates every user and checks eligibility again before sending.', 'codi-email-scheduler'),
                    $candidate_label,
                    absint($preview['sample_size'] ?? 0),
                    absint($preview['sample_eligible'] ?? 0),
                    absint($preview['sample_excluded'] ?? 0),
                    absint($preview['sample_errors'] ?? 0)
                )) . '</p></div>';
            }
        }
        $error_notice = get_transient('ces_backfill_error_' . get_current_user_id());
        $error = is_array($error_notice) && ($error_notice['email_id'] ?? '') === (string) $email['id']
            ? (string) ($error_notice['message'] ?? '')
            : '';
        if ($error !== '') {
            delete_transient('ces_backfill_error_' . get_current_user_id());
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        }

        if ($run && in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true)) {
            echo '<p class="description">' . esc_html__('Finish or cancel the active run before starting another one for this email.', 'codi-email-scheduler') . '</p>';
            echo '</div>';
            return;
        }

        echo '<p>' . esc_html__('Check every existing user against the current send rules, then schedule eligible users from the time this enrollment starts. Send rules are checked again before delivery.', 'codi-email-scheduler') . '</p>';
        echo '<form method="post" action="">';
        wp_nonce_field('ces_backfill_email');
        echo '<input type="hidden" name="email_id" value="' . esc_attr((string) $email['id']) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="backfill_rate_per_minute">' . esc_html__('Maximum scheduled actions per minute', 'codi-email-scheduler') . '</label></th><td>';
        echo '<input type="number" min="1" max="60" id="backfill_rate_per_minute" name="backfill_rate_per_minute" value="60">';
        echo '<p class="description">' . esc_html__('Actions are spread by scheduled timestamp. Workers are never delayed with sleep calls.', 'codi-email-scheduler') . '</p></td></tr>';
        echo '</tbody></table>';
        echo '<button type="submit" class="button" name="ces_action" value="preview_backfill">' . esc_html__('Preview eligibility', 'codi-email-scheduler') . '</button>';
        echo ' ';
        echo '<button type="submit" class="button button-primary" name="ces_action" value="start_backfill">' . esc_html__('Start retrospective enrollment', 'codi-email-scheduler') . '</button>';
        echo '</form>';
        echo '<p class="description">' . esc_html__('Retrospective enrollment avoids duplicate pending actions. Running it again after earlier actions have completed may schedule the same users again.', 'codi-email-scheduler') . '</p>';
        echo '</div>';
    }

    private static function render_configuration_warnings(array $email): void {
        $warnings = self::email_configuration_warnings($email);
        if (!$warnings) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Configuration warnings', 'codi-email-scheduler') . '</strong></p><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . esc_html($warning) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function email_configuration_warnings(array $email): array {
        $warnings = [];
        $event_key = sanitize_key((string) ($email['event_key'] ?? ''));
        $event = $event_key !== '' ? CES_Registry::event($event_key) : null;

        if ($event_key !== '' && !$event) {
            $warnings[] = sprintf(__('Unknown event: %s.', 'codi-email-scheduler'), $event_key);
        }

        if ($event) {
            $known_settings = array_keys((array) ($event['settings_fields'] ?? []));
            foreach (array_keys(CES_Storage::normalize_settings($email['event_settings'] ?? [])) as $setting_key) {
                if (!in_array($setting_key, $known_settings, true)) {
                    $warnings[] = sprintf(__('Unknown event setting for %1$s: %2$s.', 'codi-email-scheduler'), $event_key, $setting_key);
                }
            }
        }

        foreach (CES_Storage::normalize_conditions($email['conditions'] ?? []) as $condition_item) {
            $condition_key = sanitize_key((string) ($condition_item['key'] ?? ''));
            $condition = $condition_key !== '' ? CES_Registry::condition($condition_key) : null;
            if (!$condition) {
                $warnings[] = sprintf(__('Unknown condition: %s.', 'codi-email-scheduler'), $condition_key ?: __('empty', 'codi-email-scheduler'));
                continue;
            }

            $known_settings = array_keys((array) ($condition['settings_fields'] ?? []));
            foreach (array_keys(CES_Storage::normalize_settings($condition_item['settings'] ?? [])) as $setting_key) {
                if (!in_array($setting_key, $known_settings, true)) {
                    $warnings[] = sprintf(__('Unknown setting for condition %1$s: %2$s.', 'codi-email-scheduler'), $condition_key, $setting_key);
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private static function woocommerce_runtime_warning_needed(): bool {
        if (!class_exists('CES_WooCommerce_Helpers') || !CES_WooCommerce_Helpers::is_woocommerce_dependency_active() || CES_WooCommerce_Helpers::is_woocommerce_runtime_available()) {
            return false;
        }

        foreach (CES_Storage::get_emails() as $email) {
            if (empty($email['enabled'])) {
                continue;
            }

            if (self::email_uses_woocommerce_definition($email)) {
                return true;
            }
        }

        return false;
    }

    private static function email_uses_woocommerce_definition(array $email): bool {
        if (strpos((string) ($email['event_key'] ?? ''), 'wc_') === 0) {
            return true;
        }

        foreach (CES_Storage::condition_keys($email['conditions'] ?? []) as $key) {
            if (strpos($key, 'wc_') === 0) {
                return true;
            }
        }

        $content = (string) ($email['subject'] ?? '') . ' ' . (string) ($email['body'] ?? '');
        return (bool) preg_match('/\\{\\{\\s*(product_|order_|cart_|checkout_)/', $content);
    }

    private static function render_section_heading(string $title, string $description = ''): void {
        echo '<tr class="ces-section-row"><th colspan="2">';
        echo '<p class="ces-section-title">' . esc_html($title) . '</p>';
        if ($description !== '') {
            echo '<p class="ces-section-description">' . esc_html($description) . '</p>';
        }
        echo '</th></tr>';
    }

    private static function render_event_row(array $email, array $events): void {
        echo '<tr><th scope="row"><label for="event_key">' . esc_html__('Event', 'codi-email-scheduler') . '</label></th><td>';
        echo '<select id="event_key" name="event_key" data-ces-runtime-required="1">';
        echo '<option value="">' . esc_html__('Select event', 'codi-email-scheduler') . '</option>';
        foreach ($events as $key => $event) {
            $hooks = [];
            foreach ((array) ($event['triggers'] ?? []) as $trigger) {
                if (!empty($trigger['hook'])) {
                    $hooks[] = (string) $trigger['hook'];
                }
            }
            echo '<option value="' . esc_attr($key) . '" data-description="' . esc_attr((string) ($event['description'] ?? '')) . '" data-hooks="' . esc_attr(implode(', ', array_unique($hooks))) . '" ' . selected($email['event_key'], $key, false) . '>' . esc_html($event['label']) . '</option>';
        }
        echo '</select>';

        $selected_event = $email['event_key'] && isset($events[$email['event_key']]) ? (array) $events[$email['event_key']] : [];
        $selected_hooks = [];
        foreach ((array) ($selected_event['triggers'] ?? []) as $trigger) {
            if (!empty($trigger['hook'])) {
                $selected_hooks[] = (string) $trigger['hook'];
            }
        }
        $selected_description = (string) ($selected_event['description'] ?? '');
        $selected_hooks_text = implode(', ', array_unique($selected_hooks));
        echo '<p id="ces-event-description" class="description"' . ($selected_description === '' ? ' hidden' : '') . '>' . esc_html($selected_description) . '</p>';
        echo '<p id="ces-event-hooks" class="description" data-prefix="' . esc_attr__('Bound WordPress hooks:', 'codi-email-scheduler') . '"' . ($selected_hooks_text === '' ? ' hidden' : '') . '>' . ($selected_hooks_text !== '' ? esc_html(sprintf(__('Bound WordPress hooks: %s', 'codi-email-scheduler'), $selected_hooks_text)) : '') . '</p>';
        $settings_templates = [];
        foreach ($events as $event_key => $event) {
            if (empty($event['settings_fields'])) {
                $settings_templates[$event_key] = '';
                continue;
            }

            ob_start();
            self::render_settings_fields('event_settings', (array) $event['settings_fields'], []);
            $settings_templates[$event_key] = (string) ob_get_clean();
        }

        echo '<div id="ces-event-settings" class="ces-event-settings" data-settings-templates="' . esc_attr(wp_json_encode($settings_templates)) . '">';
        if ($email['event_key'] && !empty($events[$email['event_key']]['settings_fields'])) {
            self::render_settings_fields('event_settings', (array) $events[$email['event_key']]['settings_fields'], $email['event_settings'] ?? []);
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__('Save after changing the event to refresh relevant conditions and tokens.', 'codi-email-scheduler') . '</p>';
        echo '</td></tr>';
    }

    private static function render_processing_mode_row(array $email): void {
        $mode = (string) ($email['processing_mode'] ?? 'immediate');
        echo '<tr><th scope="row"><label for="processing_mode">' . esc_html__('Processing', 'codi-email-scheduler') . '</label></th><td>';
        echo '<select id="processing_mode" name="processing_mode">';
        echo '<option value="immediate" ' . selected($mode, 'immediate', false) . '>' . esc_html__('Immediate', 'codi-email-scheduler') . '</option>';
        echo '<option value="shutdown" ' . selected($mode, 'shutdown', false) . '>' . esc_html__('End of request (shutdown)', 'codi-email-scheduler') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Immediate evaluates this email during the event hook. Shutdown waits until the end of the current PHP request so later data changes are available.', 'codi-email-scheduler') . '</p>';
        echo '</td></tr>';
    }

    private static function render_delays_row(array $email): void {
        echo '<tr><th scope="row"><label for="delays">' . esc_html__('Delays', 'codi-email-scheduler') . '</label></th><td>';
        echo '<textarea id="delays" name="delays" rows="5" class="large-text code" data-ces-runtime-required="1">' . esc_textarea(CES_Storage::delays_to_lines($email['delays'])) . '</textarea>';
        echo '<p class="description">' . esc_html__('One delay per line. Examples: 0 minutes, 24 hours, 3 days. Zero means the email is queued immediately.', 'codi-email-scheduler') . '</p>';
        echo '</td></tr>';
    }

    private static function render_conditions_row(array $email, array $conditions): void {
        echo '<tr><th scope="row">' . esc_html__('Send if conditions match', 'codi-email-scheduler') . '</th><td>';
        echo '<div class="ces-condition-mode"><label for="ces-condition-mode">' . esc_html__('Send this email when', 'codi-email-scheduler') . '</label><select id="ces-condition-mode" name="condition_mode">';
        echo '<option value="all" ' . selected($email['condition_mode'], 'all', false) . '>' . esc_html__('all', 'codi-email-scheduler') . '</option>';
        echo '<option value="any" ' . selected($email['condition_mode'], 'any', false) . '>' . esc_html__('any', 'codi-email-scheduler') . '</option>';
        echo '</select><span>' . esc_html__('of the following conditions match.', 'codi-email-scheduler') . '</span></div>';

        $condition_items = CES_Storage::normalize_conditions($email['conditions']);
        $allowed_conditions = self::allowed_condition_keys($email['event_key'], $condition_items);
        if (!$allowed_conditions) {
            $allowed_conditions = array_keys($conditions);
        }

        echo '<table class="widefat ces-builder-table" id="ces-condition-rows">';
        echo '<thead><tr><th>' . esc_html__('Condition', 'codi-email-scheduler') . '</th><th class="ces-result-column">' . esc_html__('Required result', 'codi-email-scheduler') . '</th><th class="ces-settings-column">' . esc_html__('Settings', 'codi-email-scheduler') . '</th><th class="ces-action-column">' . esc_html__('Action', 'codi-email-scheduler') . '</th></tr></thead><tbody>';

        foreach ($condition_items as $index => $condition_item) {
            self::render_condition_builder_row((int) $index, $condition_item, $allowed_conditions, $conditions);
        }

        echo '</tbody></table>';
        echo '<p id="ces-no-conditions" class="description ces-empty-note" style="' . ($condition_items ? 'display:none;' : '') . '">' . esc_html__('No conditions selected. The email sends whenever the event matches.', 'codi-email-scheduler') . '</p>';

        echo '<div class="ces-builder-add">';
        echo '<label for="ces-condition-to-add"><strong>' . esc_html__('Add condition', 'codi-email-scheduler') . '</strong></label>';
        echo '<select id="ces-condition-to-add">';
        foreach ($allowed_conditions as $condition_key) {
            if (!isset($conditions[$condition_key])) {
                continue;
            }
            echo '<option value="' . esc_attr($condition_key) . '">' . esc_html((string) $conditions[$condition_key]['label']) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="button" id="ces-add-condition-row"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Add condition row', 'codi-email-scheduler') . '</button>';
        echo '<p class="description">' . esc_html__('Add only the conditions this email needs. Each row can require the condition to be true or false, and parameterised conditions expose their own settings.', 'codi-email-scheduler') . '</p>';
        echo '</div>';

        self::render_condition_builder_script($allowed_conditions, $conditions, count($condition_items));
        echo '</td></tr>';
    }

    private static function render_condition_builder_row(int $index, array $condition_item, array $allowed_conditions, array $conditions): void {
        $condition_key = sanitize_key((string) ($condition_item['key'] ?? ''));
        $operator = sanitize_key((string) ($condition_item['operator'] ?? 'is_true'));
        $operator = in_array($operator, ['is_true', 'is_false'], true) ? $operator : 'is_true';
        $settings = CES_Storage::normalize_settings($condition_item['settings'] ?? []);

        if ($condition_key === '' || !isset($conditions[$condition_key])) {
            $condition_key = isset($allowed_conditions[0]) ? sanitize_key((string) $allowed_conditions[0]) : '';
        }

        echo '<tr class="ces-condition-row" data-index="' . esc_attr((string) $index) . '">';
        echo '<td class="ces-builder-primary" data-label="' . esc_attr__('Condition', 'codi-email-scheduler') . '">';
        self::render_condition_select('condition_rows[' . $index . '][key]', $condition_key, $allowed_conditions, $conditions, 'ces-condition-key');
        if ($condition_key !== '' && !empty($conditions[$condition_key]['description'])) {
            echo '<br><span class="description ces-condition-description">' . esc_html((string) $conditions[$condition_key]['description']) . '</span>';
        } else {
            echo '<br><span class="description ces-condition-description"></span>';
        }
        echo '</td>';
        echo '<td class="ces-builder-result" data-label="' . esc_attr__('Required result', 'codi-email-scheduler') . '">';
        echo '<select name="condition_rows[' . esc_attr((string) $index) . '][operator]">';
        echo '<option value="is_true" ' . selected($operator, 'is_true', false) . '>' . esc_html__('Must be true', 'codi-email-scheduler') . '</option>';
        echo '<option value="is_false" ' . selected($operator, 'is_false', false) . '>' . esc_html__('Must be false', 'codi-email-scheduler') . '</option>';
        echo '</select>';
        echo '</td>';
        echo '<td class="ces-condition-settings" data-label="' . esc_attr__('Settings', 'codi-email-scheduler') . '">';
        self::render_condition_settings_for_key($index, $condition_key, $conditions, $settings);
        echo '</td>';
        echo '<td class="ces-builder-action"><button type="button" class="button-link-delete ces-remove-condition-row">' . esc_html__('Remove', 'codi-email-scheduler') . '</button></td>';
        echo '</tr>';
    }

    private static function render_condition_select(string $name, string $selected_key, array $allowed_conditions, array $conditions, string $class = ''): void {
        echo '<select name="' . esc_attr($name) . '" class="' . esc_attr($class) . '">';
        foreach ($allowed_conditions as $condition_key) {
            if (!isset($conditions[$condition_key])) {
                continue;
            }
            echo '<option value="' . esc_attr($condition_key) . '" ' . selected($selected_key, $condition_key, false) . '>' . esc_html((string) $conditions[$condition_key]['label']) . '</option>';
        }
        echo '</select>';
    }

    private static function render_condition_settings_for_key(int $index, string $condition_key, array $conditions, array $values = []): void {
        if ($condition_key === '' || empty($conditions[$condition_key]['settings_fields'])) {
            echo '<span class="description">' . esc_html__('No settings required.', 'codi-email-scheduler') . '</span>';
            return;
        }

        self::render_settings_fields('condition_rows[' . $index . '][settings]', (array) $conditions[$condition_key]['settings_fields'], $values);
    }

    private static function condition_settings_template(string $condition_key, array $conditions): string {
        if ($condition_key === '' || empty($conditions[$condition_key]['settings_fields'])) {
            return '<span class="description">' . esc_html__('No settings required.', 'codi-email-scheduler') . '</span>';
        }

        ob_start();
        self::render_settings_fields('condition_rows[__INDEX__][settings]', (array) $conditions[$condition_key]['settings_fields'], []);
        return (string) ob_get_clean();
    }

    private static function condition_select_template(array $allowed_conditions, array $conditions): string {
        ob_start();
        self::render_condition_select('condition_rows[__INDEX__][key]', '', $allowed_conditions, $conditions, 'ces-condition-key');
        return (string) ob_get_clean();
    }

    private static function render_condition_builder_script(array $allowed_conditions, array $conditions, int $next_index): void {
        $settings_templates = [];
        $descriptions = [];

        foreach ($allowed_conditions as $condition_key) {
            if (!isset($conditions[$condition_key])) {
                continue;
            }
            $settings_templates[$condition_key] = self::condition_settings_template($condition_key, $conditions);
            $descriptions[$condition_key] = (string) ($conditions[$condition_key]['description'] ?? '');
        }

        $select_template = self::condition_select_template($allowed_conditions, $conditions);
        echo '<div id="ces-condition-builder-data" class="hidden" data-next-index="' . esc_attr((string) $next_index) . '" data-settings-templates="' . esc_attr((string) wp_json_encode($settings_templates)) . '" data-descriptions="' . esc_attr((string) wp_json_encode($descriptions)) . '" data-select-template="' . esc_attr((string) wp_json_encode($select_template)) . '" data-remove-label="' . esc_attr__('Remove', 'codi-email-scheduler') . '" data-no-settings-label="' . esc_attr__('No settings required.', 'codi-email-scheduler') . '"></div>';
    }

    private static function render_body_row(array $email): void {
        echo '<tr><th scope="row">' . esc_html__('Body', 'codi-email-scheduler') . '</th><td>';
        wp_editor($email['body'], 'ces_body_editor', [
            'textarea_name' => 'body',
            'textarea_rows' => 12,
            'media_buttons' => false,
        ]);
        echo '</td></tr>';
    }

    private static function render_tokens_row(array $tokens): void {
        echo '<tr><th scope="row">' . esc_html__('Available tokens', 'codi-email-scheduler') . '</th><td>';
        if (!$tokens) {
            echo '<p>' . esc_html__('No tokens available for the selected event.', 'codi-email-scheduler') . '</p>';
            echo '<p class="description">' . esc_html__('Tokens are inferred from the selected event context keys.', 'codi-email-scheduler') . '</p>';
            echo '</td></tr>';
            return;
        }

        echo '<ul class="ces-token-list">';
        foreach ($tokens as $token_key => $token) {
            $token_key = sanitize_key((string) $token_key);
            $label = is_array($token) ? ($token['label'] ?? $token_key) : $token_key;
            echo '<li><code>{{' . esc_html($token_key) . '}}</code><span>' . esc_html((string) $label) . '</span></li>';
        }
        echo '</ul>';
        echo '<p class="description">' . esc_html__('Available tokens are inferred from the selected event context and explicit token registrations.', 'codi-email-scheduler') . '</p>';
        echo '</td></tr>';
    }

    private static function render_delete_form(string $email_id): void {
        echo '<div class="ces-danger-actions">';
        echo '<form method="post" action="" data-confirm="' . esc_attr__('Delete this scheduled email?', 'codi-email-scheduler') . '">';
        wp_nonce_field('ces_delete_email');
        echo '<input type="hidden" name="ces_action" value="delete_email">';
        echo '<input type="hidden" name="email_id" value="' . esc_attr($email_id) . '">';
        submit_button(__('Delete scheduled email', 'codi-email-scheduler'), 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    private static function render_queue_page(): void {
        $statuses = [
            'pending'     => __('Pending', 'codi-email-scheduler'),
            'in-progress' => __('In progress', 'codi-email-scheduler'),
            'complete'    => __('Completed', 'codi-email-scheduler'),
            'failed'      => __('Failed', 'codi-email-scheduler'),
            'canceled'    => __('Cancelled', 'codi-email-scheduler'),
        ];
        $status = isset($_GET['queue_status']) ? sanitize_key(wp_unslash($_GET['queue_status'])) : 'pending';
        if (!isset($statuses[$status])) {
            $status = 'pending';
        }

        $page = isset($_GET['queue_page']) ? max(1, absint(wp_unslash($_GET['queue_page']))) : 1;
        $per_page = 20;
        $actions = [];
        $error_message = '';

        if (!CES_Engine::action_scheduler_available() || !function_exists('as_get_scheduled_actions')) {
            $error_message = __('Action Scheduler is unavailable, so the queue cannot be read.', 'codi-email-scheduler');
        } else {
            try {
                $actions = as_get_scheduled_actions([
                    'hook'     => CES_AS_ACTION,
                    'group'    => CES_AS_GROUP,
                    'status'   => $status,
                    'per_page' => $per_page + 1,
                    'offset'   => ($page - 1) * $per_page,
                    'orderby'  => 'date',
                    'order'    => 'DESC',
                ], 'OBJECT');
                $actions = is_array($actions) ? $actions : [];
            } catch (Throwable $throwable) {
                $error_message = sanitize_text_field($throwable->getMessage());
            }
        }

        $has_next = count($actions) > $per_page;
        if ($has_next) {
            $actions = array_slice($actions, 0, $per_page, true);
        }

        $native_url = admin_url('tools.php?page=action-scheduler&s=' . rawurlencode(CES_AS_ACTION));

        echo '<div class="ces-card">';
        echo '<h2>' . esc_html__('Email Queue', 'codi-email-scheduler') . '</h2>';
        echo '<p class="description">' . esc_html__('This is a read-only view of Codi Email Scheduler actions stored by Action Scheduler. Completed means the callback finished; it can include deliberate skips.', 'codi-email-scheduler') . '</p>';
        echo '<p><a class="button" href="' . esc_url($native_url) . '">' . esc_html__('Open Action Scheduler', 'codi-email-scheduler') . '</a></p>';

        echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '" class="ces-filter-bar">';
        echo '<input type="hidden" name="page" value="' . esc_attr(CES_Admin::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tab" value="queue">';
        echo '<label><strong>' . esc_html__('Status', 'codi-email-scheduler') . '</strong><br><select name="queue_status">';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        submit_button(__('Filter queue', 'codi-email-scheduler'), 'secondary', 'submit', false);
        echo '</form>';

        if ($error_message !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error_message) . '</p></div>';
            echo '</div>';
            return;
        }

        if (!$actions) {
            echo '<p>' . esc_html__('No matching email actions were found.', 'codi-email-scheduler') . '</p>';
            self::render_queue_pagination($page, false, $status);
            echo '</div>';
            return;
        }

        $emails = CES_Storage::get_emails();
        $events = CES_Registry::events();

        echo '<table class="widefat striped ces-table"><thead><tr>';
        echo '<th>' . esc_html__('Action', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-status">' . esc_html__('Status', 'codi-email-scheduler') . '</th>';
        echo '<th>' . esc_html__('Scheduled', 'codi-email-scheduler') . '</th>';
        echo '<th>' . esc_html__('Email', 'codi-email-scheduler') . '</th>';
        echo '<th class="ces-col-event">' . esc_html__('Event', 'codi-email-scheduler') . '</th>';
        echo '<th>' . esc_html__('Occurrence', 'codi-email-scheduler') . '</th>';
        echo '<th>' . esc_html__('Delay', 'codi-email-scheduler') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($actions as $action_id => $action) {
            $payload = self::scheduled_action_payload($action);
            $email_id = sanitize_key((string) ($payload['email_id'] ?? ''));
            $event_key = sanitize_key((string) ($payload['event_key'] ?? ''));
            $email_name = isset($emails[$email_id]) ? (string) ($emails[$email_id]['name'] ?? $email_id) : $email_id;
            $event_name = isset($events[$event_key]) ? (string) ($events[$event_key]['label'] ?? $event_key) : $event_key;
            $delay = self::scheduled_delay_label(absint($payload['effective_delay_seconds'] ?? 0));
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $action_id) . '</code></td>';
            echo '<td>' . esc_html($statuses[$status]) . '</td>';
            echo '<td>' . esc_html(self::scheduled_action_date($action)) . '</td>';
            echo '<td>' . esc_html($email_name !== '' ? $email_name : __('Unknown email', 'codi-email-scheduler')) . ($email_id !== '' ? '<br><code>' . esc_html($email_id) . '</code>' : '') . '</td>';
            echo '<td>' . esc_html($event_name !== '' ? $event_name : __('Unknown event', 'codi-email-scheduler')) . ($event_key !== '' ? '<br><code>' . esc_html($event_key) . '</code>' : '') . '</td>';
            echo '<td><code>' . esc_html((string) ($payload['event_instance_id'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html($delay) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        self::render_queue_pagination($page, $has_next, $status);
        echo '</div>';
    }

    private static function scheduled_action_payload($action): array {
        if (!is_object($action) || !method_exists($action, 'get_args')) {
            return [];
        }

        $args = $action->get_args();
        return is_array($args) && isset($args[0]) && is_array($args[0]) ? $args[0] : [];
    }

    private static function scheduled_delay_label(int $seconds): string {
        if ($seconds === 0) {
            return __('Immediately', 'codi-email-scheduler');
        }

        foreach ([
            'weeks'   => WEEK_IN_SECONDS,
            'days'    => DAY_IN_SECONDS,
            'hours'   => HOUR_IN_SECONDS,
            'minutes' => MINUTE_IN_SECONDS,
        ] as $unit => $unit_seconds) {
            if ($seconds % $unit_seconds === 0) {
                return CES_Storage::delay_label([
                    'value' => (int) ($seconds / $unit_seconds),
                    'unit'  => $unit,
                ]);
            }
        }

        return sprintf(_n('%d second', '%d seconds', $seconds, 'codi-email-scheduler'), $seconds);
    }

    private static function scheduled_action_date($action): string {
        if (!is_object($action) || !method_exists($action, 'get_schedule')) {
            return '';
        }

        $schedule = $action->get_schedule();
        if (!is_object($schedule) || !method_exists($schedule, 'get_date')) {
            return '';
        }

        $date = $schedule->get_date();
        return $date instanceof DateTimeInterface
            ? $date->format('Y-m-d H:i:s T')
            : __('As soon as possible', 'codi-email-scheduler');
    }

    private static function render_queue_pagination(int $page, bool $has_next, string $status): void {
        if ($page <= 1 && !$has_next) {
            return;
        }

        $url = static function (int $target) use ($status): string {
            return add_query_arg([
                'page'         => CES_Admin::PAGE_SLUG,
                'tab'          => 'queue',
                'queue_status' => $status,
                'queue_page'   => max(1, $target),
            ], admin_url('tools.php'));
        };

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url($url($page - 1)) . '">' . esc_html__('Previous', 'codi-email-scheduler') . '</a> ';
        }
        echo '<span class="paging-input">' . esc_html(sprintf(__('Page %d', 'codi-email-scheduler'), $page)) . '</span>';
        if ($has_next) {
            echo ' <a class="button" href="' . esc_url($url($page + 1)) . '">' . esc_html__('Next', 'codi-email-scheduler') . '</a>';
        }
        echo '</div><br class="clear"></div>';
    }

    private static function render_settings_fields(string $base_name, array $fields, array $values): void {
        $normalized_fields = CES_Registry::normalize_settings_fields($fields, 'admin_render', sanitize_key($base_name));
        if (is_wp_error($normalized_fields)) {
            echo '<p class="description">' . esc_html($normalized_fields->get_error_message()) . '</p>';
            return;
        }

        foreach ($normalized_fields as $field_key => $field) {
            $value = isset($values[$field_key]) ? (string) $values[$field_key] : (string) ($field['default'] ?? '');
            $input_name = $base_name . '[' . $field_key . ']';
            $input_id = 'ces_' . sanitize_key(str_replace(['[', ']'], '_', $input_name));

            echo '<p class="ces-field">';
            echo '<label for="' . esc_attr($input_id) . '"><strong>' . esc_html((string) $field['label']) . '</strong></label><br>';

            $required = !empty($field['required']) ? ' data-ces-runtime-required="1"' : '';
            if (($field['type'] ?? 'text') === 'select') {
                echo '<select id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '"' . $required . '>';
                foreach ((array) ($field['options'] ?? []) as $option_value => $option_label) {
                    echo '<option value="' . esc_attr((string) $option_value) . '" ' . selected($value, (string) $option_value, false) . '>' . esc_html((string) $option_label) . '</option>';
                }
                echo '</select>';
            } elseif (($field['type'] ?? 'text') === 'textarea') {
                echo '<textarea id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" rows="7" class="large-text code"' . $required . '>' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input type="text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($value) . '" class="regular-text"' . $required . '>';
            }

            if (!empty($field['description'])) {
                echo '<br><span class="description">' . esc_html((string) $field['description']) . '</span>';
            }
            echo '</p>';
        }
    }

    private static function render_text_row(string $label, string $name, string $value, string $description = ''): void {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="large-text">';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function allowed_condition_keys(string $event_key, array $selected_conditions = []): array {
        $available = CES_Registry::available_conditions_for_event($event_key);
        $conditions = CES_Registry::conditions();
        $keys = array_keys($available);

        foreach (CES_Storage::condition_keys($selected_conditions) as $selected_key) {
            if (isset($conditions[$selected_key]) && !in_array($selected_key, $keys, true)) {
                $keys[] = $selected_key;
            }
        }

        if (!in_array('always', $keys, true) && isset($conditions['always'])) {
            array_unshift($keys, 'always');
        }

        return array_values(array_unique(array_map('sanitize_key', $keys)));
    }

    private static function event_label(string $event_key): string {
        $event = CES_Registry::event($event_key);
        return $event ? (string) $event['label'] : ($event_key ?: __('Not selected', 'codi-email-scheduler'));
    }

    private static function conditions_label(array $email): string {
        $conditions = CES_Storage::normalize_conditions($email['conditions'] ?? []);
        if (!$conditions) {
            return __('Always', 'codi-email-scheduler');
        }

        $labels = [];
        foreach ($conditions as $condition_item) {
            $condition_key = sanitize_key((string) ($condition_item['key'] ?? ''));
            $operator = sanitize_key((string) ($condition_item['operator'] ?? 'is_true'));
            $condition = CES_Registry::condition($condition_key);
            $label = $condition ? (string) $condition['label'] : $condition_key;
            $setting_label = self::settings_label($condition_item['settings'] ?? []);
            if ($setting_label !== '') {
                $label .= ' (' . $setting_label . ')';
            }
            $labels[] = $operator === 'is_false' ? sprintf(__('%s = false', 'codi-email-scheduler'), $label) : $label;
        }

        $mode = ($email['condition_mode'] ?? 'all') === 'any' ? __('ANY', 'codi-email-scheduler') : __('ALL', 'codi-email-scheduler');
        return $mode . ': ' . implode(', ', $labels);
    }

    private static function settings_label(array $settings): string {
        $settings = CES_Storage::normalize_settings($settings);
        if (!$settings) {
            return '';
        }

        $parts = [];
        foreach ($settings as $key => $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = $key . ': ' . $value;
        }

        return implode(', ', $parts);
    }


    private static function pending_queue_count(): ?int {
        if (!CES_Engine::action_scheduler_available() || !function_exists('as_get_scheduled_actions')) {
            return null;
        }

        try {
            $action_ids = as_get_scheduled_actions([
                'hook'     => CES_AS_ACTION,
                'group'    => CES_AS_GROUP,
                'status'   => 'pending',
                'per_page' => -1,
            ], 'ids');
        } catch (Throwable $throwable) {
            return null;
        }

        return is_array($action_ids) ? count($action_ids) : 0;
    }

    private static function render_delays_list(array $delays): string {
        $items = [];
        foreach ($delays as $delay) {
            $items[] = '<span>' . esc_html(CES_Storage::delay_label(is_array($delay) ? $delay : [])) . '</span>';
        }

        return '<span class="ces-delay-list">' . implode('', $items) . '</span>';
    }


}
