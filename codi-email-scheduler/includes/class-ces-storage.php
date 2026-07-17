<?php

defined('ABSPATH') || exit;

final class CES_Storage {
    public static function get_emails(): array {
        $emails = get_option(CES_OPTION_EMAILS, []);
        if (!is_array($emails)) {
            return [];
        }

        $clean = [];
        foreach ($emails as $id => $email) {
            if (!is_array($email)) {
                continue;
            }
            $normalized = self::normalize_email($email, (string) $id);
            if ($normalized['id'] !== '') {
                $clean[$normalized['id']] = $normalized;
            }
        }

        return $clean;
    }

    public static function has_enabled_email_for_event(string $event_key, string $processing_mode): bool {
        $event_key = sanitize_key($event_key);
        $processing_mode = sanitize_key($processing_mode);
        foreach (self::get_emails() as $email) {
            if (!empty($email['enabled']) && $email['event_key'] === $event_key && ($email['processing_mode'] ?? 'immediate') === $processing_mode) {
                return true;
            }
        }
        return false;
    }

    public static function get_email(string $id): ?array {
        $id = sanitize_key($id);
        if ($id === '') {
            return null;
        }

        $emails = self::get_emails();
        return $emails[$id] ?? null;
    }

    /**
     * @return string|WP_Error Saved email ID or a validation error.
     */
    public static function save_email(array $email) {
        $emails = self::get_emails();
        $id = sanitize_key((string) ($email['id'] ?? ''));
        if ($id === '') {
            $id = self::new_email_id();
        }

        $email['id'] = $id;
        $email['updated_at'] = current_time('mysql');
        $email['created_at'] = !empty($email['created_at']) ? $email['created_at'] : ($emails[$id]['created_at'] ?? current_time('mysql'));
        $validated = CES_Email_Validator::validate($email, !empty($email['enabled']));
        if (is_wp_error($validated)) {
            return $validated;
        }

        $import_key = (string) ($validated['import_key'] ?? '');
        if ($import_key !== '') {
            foreach ($emails as $existing_id => $existing_email) {
                if ((string) $existing_id !== $id && (string) ($existing_email['import_key'] ?? '') === $import_key) {
                    return new WP_Error('email_import_key_duplicate', __('Another email already uses this import key.', 'codi-email-scheduler'));
                }
            }
        }

        $emails[$id] = $validated;
        if (!self::save_emails($emails)) {
            return new WP_Error('email_save_failed', __('The scheduled email could not be saved.', 'codi-email-scheduler'));
        }

        return $id;
    }

    public static function delete_email(string $id): bool {
        $id = sanitize_key($id);
        $emails = self::get_emails();

        if (!isset($emails[$id])) {
            return false;
        }

        unset($emails[$id]);
        return self::save_emails($emails);
    }

    public static function save_emails(array $emails): bool {
        return self::save_option(CES_OPTION_EMAILS, $emails);
    }


    public static function get_plugin_settings(): array {
        return self::normalize_plugin_settings(get_option(CES_OPTION_SETTINGS, []));
    }

    public static function save_plugin_settings(array $settings): bool {
        return self::save_option(CES_OPTION_SETTINGS, self::normalize_plugin_settings($settings));
    }

    private static function save_option(string $option, $value): bool {
        return update_option($option, $value, false) || get_option($option, null) === $value;
    }

    private static function normalize_plugin_settings($raw_settings): array {
        $raw_settings = is_array($raw_settings) ? $raw_settings : [];
        $delay_value = isset($raw_settings['delay_test_value']) ? absint($raw_settings['delay_test_value']) : 2;
        $delay_unit = self::normalize_delay_unit((string) ($raw_settings['delay_test_unit'] ?? 'minutes'));
        $delay_mode = sanitize_key((string) ($raw_settings['delay_test_mode'] ?? 'longer_than_test'));

        if ($delay_value < 1) {
            $delay_value = 2;
        }

        if ($delay_unit === '') {
            $delay_unit = 'minutes';
        }

        if (!in_array($delay_mode, ['longer_than_test', 'all_non_zero'], true)) {
            $delay_mode = 'longer_than_test';
        }


        return [
            'delay_test_enabled' => !empty($raw_settings['delay_test_enabled']) ? '1' : '0',
            'delay_test_value'   => $delay_value,
            'delay_test_unit'    => $delay_unit,
            'delay_test_mode'    => $delay_mode,
        ];
    }

    public static function delay_test_enabled(): bool {
        $settings = self::get_plugin_settings();
        return $settings['delay_test_enabled'] === '1';
    }

    public static function effective_delay_details(array $delay): array {
        $configured_seconds = self::delay_to_seconds($delay);
        $configured_label = self::delay_label($delay);
        $settings = self::get_plugin_settings();
        $test_delay = [
            'value' => absint($settings['delay_test_value'] ?? 2),
            'unit'  => self::normalize_delay_unit((string) ($settings['delay_test_unit'] ?? 'minutes')) ?: 'minutes',
        ];
        $test_seconds = self::delay_to_seconds($test_delay);
        $effective_seconds = $configured_seconds;
        $applied = false;

        if ($settings['delay_test_enabled'] === '1' && $configured_seconds > 0 && $test_seconds > 0) {
            if ($settings['delay_test_mode'] === 'all_non_zero') {
                $effective_seconds = $test_seconds;
                $applied = $configured_seconds !== $effective_seconds;
            } elseif ($configured_seconds > $test_seconds) {
                $effective_seconds = $test_seconds;
                $applied = true;
            }
        }

        return [
            'configured_seconds' => $configured_seconds,
            'effective_seconds'  => $effective_seconds,
            'configured_label'   => $configured_label,
            'effective_label'    => $applied ? self::delay_label($test_delay) : $configured_label,
            'test_applied'       => $applied,
        ];
    }

    public static function normalize_email(array $email, string $fallback_id = ''): array {
        $id = sanitize_key((string) ($email['id'] ?? $fallback_id));
        $mode = sanitize_key((string) ($email['condition_mode'] ?? 'all'));
        $processing_mode = sanitize_key((string) ($email['processing_mode'] ?? 'immediate'));

        if (!in_array($mode, ['all', 'any'], true)) {
            $mode = 'all';
        }
        if (!in_array($processing_mode, ['immediate', 'shutdown'], true)) {
            $processing_mode = 'immediate';
        }

        return [
            'id'             => $id,
            'import_key'     => sanitize_key((string) ($email['import_key'] ?? '')),
            'name'           => sanitize_text_field((string) ($email['name'] ?? '')),
            'enabled'        => !empty($email['enabled']) ? '1' : '0',
            'event_key'      => sanitize_key((string) ($email['event_key'] ?? '')),
            'processing_mode' => $processing_mode,
            'condition_mode' => $mode,
            'event_settings' => self::normalize_settings($email['event_settings'] ?? []),
            'conditions'     => self::normalize_conditions($email['conditions'] ?? []),
            'delays'         => self::normalize_delays($email['delays'] ?? []),
            'subject'        => sanitize_text_field((string) ($email['subject'] ?? '')),
            'body'           => wp_kses_post((string) ($email['body'] ?? '')),
            'created_at'     => sanitize_text_field((string) ($email['created_at'] ?? '')),
            'updated_at'     => sanitize_text_field((string) ($email['updated_at'] ?? '')),
        ];
    }

    public static function normalize_conditions($raw_conditions): array {
        if (!is_array($raw_conditions)) {
            return [];
        }

        $conditions = [];
        foreach ($raw_conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $key = sanitize_key((string) ($condition['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $operator = sanitize_key((string) ($condition['operator'] ?? 'is_true'));
            if (!in_array($operator, ['is_true', 'is_false'], true)) {
                $operator = 'is_true';
            }

            $conditions[] = [
                'key'      => $key,
                'operator' => $operator,
                'settings' => self::normalize_settings($condition['settings'] ?? []),
            ];
        }

        return $conditions;
    }

    public static function normalize_settings($raw_settings): array {
        if (!is_array($raw_settings)) {
            return [];
        }

        $settings = [];
        foreach ($raw_settings as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $settings[$key] = sanitize_textarea_field((string) $value);
        }

        return $settings;
    }

    public static function condition_keys(array $conditions): array {
        $keys = [];
        foreach (self::normalize_conditions($conditions) as $condition) {
            $keys[] = $condition['key'];
        }
        return array_values(array_unique($keys));
    }


    public static function effective_unique_delays($raw_delays): array {
        $unique = [];
        foreach (self::normalize_delays($raw_delays) as $delay) {
            $details = self::effective_delay_details($delay);
            $seconds = absint($details['effective_seconds']);
            if (!array_key_exists($seconds, $unique)) {
                $unique[$seconds] = $delay;
            }
        }
        return array_values($unique);
    }

    public static function normalize_delays($raw_delays): array {
        if (is_string($raw_delays)) {
            $raw_delays = self::parse_delay_lines($raw_delays);
        }

        if (!is_array($raw_delays)) {
            return [];
        }

        $delays = [];
        foreach ($raw_delays as $delay) {
            if (is_string($delay)) {
                $delay = self::parse_delay_string($delay);
            }

            if (!is_array($delay)) {
                continue;
            }

            $value = isset($delay['value']) ? absint($delay['value']) : 0;
            $unit = self::normalize_delay_unit((string) ($delay['unit'] ?? 'minutes'));
            if ($unit === '') {
                continue;
            }

            $id = sanitize_key((string) ($delay['id'] ?? ''));
            if ($id === '') {
                $id = self::new_delay_id();
            }

            $delays[$id] = [
                'id'    => $id,
                'value' => $value,
                'unit'  => $unit,
            ];
        }

        return array_values($delays);
    }

    private static function parse_delay_lines(string $lines): array {
        $delays = [];
        foreach ((array) preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $parsed = self::parse_delay_string((string) $line);
            if ($parsed) {
                $delays[] = $parsed;
            }
        }
        return $delays;
    }

    private static function parse_delay_string(string $input): ?array {
        $input = trim(strtolower($input));
        if ($input === '' || preg_match('/^(\d+)\s*([a-z]*)$/', $input, $matches) !== 1) {
            return null;
        }

        $value = absint($matches[1]);
        $unit = self::normalize_delay_unit($matches[2] ?: 'minutes');
        if ($unit === '') {
            return null;
        }

        return [
            'value' => $value,
            'unit'  => $unit,
        ];
    }

    private static function delay_to_seconds(array $delay): int {
        $value = isset($delay['value']) ? absint($delay['value']) : 0;
        $unit = self::normalize_delay_unit((string) ($delay['unit'] ?? 'minutes'));

        switch ($unit) {
            case 'weeks':
                return $value * WEEK_IN_SECONDS;
            case 'days':
                return $value * DAY_IN_SECONDS;
            case 'hours':
                return $value * HOUR_IN_SECONDS;
            case 'minutes':
            default:
                return $value * MINUTE_IN_SECONDS;
        }
    }

    public static function delay_label(array $delay): string {
        $value = isset($delay['value']) ? absint($delay['value']) : 0;
        $unit = self::normalize_delay_unit((string) ($delay['unit'] ?? 'minutes'));

        if ($value === 0) {
            return __('Immediately', 'codi-email-scheduler');
        }

        $singular = rtrim($unit, 's');
        return sprintf('%d %s', $value, $value === 1 ? $singular : $unit);
    }

    public static function delays_to_lines(array $delays): string {
        $lines = [];
        foreach (self::normalize_delays($delays) as $delay) {
            $lines[] = sprintf('%d %s', absint($delay['value']), $delay['unit']);
        }
        return implode("\n", $lines);
    }

    private static function normalize_delay_unit(string $unit): string {
        $map = [
            'm' => 'minutes', 'min' => 'minutes', 'mins' => 'minutes', 'minute' => 'minutes', 'minutes' => 'minutes',
            'h' => 'hours', 'hr' => 'hours', 'hrs' => 'hours', 'hour' => 'hours', 'hours' => 'hours',
            'd' => 'days', 'day' => 'days', 'days' => 'days',
            'w' => 'weeks', 'week' => 'weeks', 'weeks' => 'weeks',
        ];

        $unit = strtolower(trim($unit));
        return $map[$unit] ?? '';
    }

    public static function reconcile_delay_ids($raw_delays, array $existing_delays): array {
        $incoming = self::normalize_delays($raw_delays);
        $existing = self::normalize_delays($existing_delays);
        $used = [];

        foreach ($incoming as $index => &$delay) {
            foreach ($existing as $existing_index => $candidate) {
                if (isset($used[$existing_index])) {
                    continue;
                }
                if ((int) $candidate['value'] === (int) $delay['value'] && (string) $candidate['unit'] === (string) $delay['unit']) {
                    $delay['id'] = (string) $candidate['id'];
                    $used[$existing_index] = true;
                    continue 2;
                }
            }

            if (isset($existing[$index]) && !isset($used[$index])) {
                $delay['id'] = (string) $existing[$index]['id'];
                $used[$index] = true;
            }
        }
        unset($delay);

        return self::normalize_delays($incoming);
    }

    public static function new_delay_id(): string {
        return 'delay_' . str_replace('-', '', wp_generate_uuid4());
    }

    public static function new_email_id(): string {
        return 'email_' . str_replace('-', '', wp_generate_uuid4());
    }
}
