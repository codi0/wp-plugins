<?php

defined('ABSPATH') || exit;

final class CES_Email_Validator {
    /**
     * @return array|WP_Error Normalized, validated email definition or an error.
     */
    public static function validate(array $email, bool $require_runtime_definitions = true) {
        $normalized = CES_Storage::normalize_email($email, (string) ($email['id'] ?? ''));
        $errors = [];

        if ($require_runtime_definitions && $normalized['event_key'] === '') {
            $errors[] = __('An enabled email requires an event.', 'codi-email-scheduler');
        }
        if ($require_runtime_definitions && !$normalized['delays']) {
            $errors[] = __('An enabled email requires at least one valid delay.', 'codi-email-scheduler');
        }

        $event = $normalized['event_key'] !== '' ? CES_Registry::event($normalized['event_key']) : null;
        if ($normalized['event_key'] !== '' && !$event && $require_runtime_definitions) {
            $errors[] = sprintf(__('Unknown event: %s.', 'codi-email-scheduler'), $normalized['event_key']);
        }
        if ($event) {
            $normalized['event_settings'] = self::validate_settings(
                (array) ($event['settings_fields'] ?? []),
                $normalized['event_settings'],
                sprintf(__('event %s', 'codi-email-scheduler'), $normalized['event_key']),
                $errors,
                $require_runtime_definitions
            );
        }

        if ($normalized['event_key'] === 'custom_wp_hook' && $require_runtime_definitions) {
            $custom_hook_error = CES_Custom_Hook_Events::validate_settings($normalized['event_settings']);
            if ($custom_hook_error instanceof WP_Error) {
                $errors[] = $custom_hook_error->get_error_message();
            }
        }


        $validated_conditions = [];
        foreach ($normalized['conditions'] as $condition_item) {
            $condition_key = sanitize_key((string) ($condition_item['key'] ?? ''));
            $condition = $condition_key !== '' ? CES_Registry::condition($condition_key) : null;
            if (!$condition) {
                if ($require_runtime_definitions) {
                    $errors[] = sprintf(__('Unknown condition: %s.', 'codi-email-scheduler'), $condition_key ?: __('empty', 'codi-email-scheduler'));
                } else {
                    $validated_conditions[] = $condition_item;
                }
                continue;
            }

            $condition_item['settings'] = self::validate_settings(
                (array) ($condition['settings_fields'] ?? []),
                CES_Storage::normalize_settings($condition_item['settings'] ?? []),
                sprintf(__('condition %s', 'codi-email-scheduler'), $condition_key),
                $errors,
                $require_runtime_definitions
            );

            if ($condition_key === 'callable_check') {
                $callable_name = (string) ($condition_item['settings']['callable'] ?? '');
                if (trim($callable_name) !== '') {
                    $callable_validation = CES_Callable_Condition::resolve($callable_name);
                    if (is_wp_error($callable_validation)) {
                        $errors[] = $callable_validation->get_error_message();
                    }
                }
            }

            if ($condition_key === 'custom_sql_scalar') {
                $sql = (string) ($condition_item['settings']['sql'] ?? '');
                if (trim($sql) !== '') {
                    $sql_validation = CES_Custom_SQL_Condition::validate_and_resolve($sql, 0);
                    if (is_wp_error($sql_validation)) {
                        $errors[] = $sql_validation->get_error_message();
                    }
                }

                $comparison = sanitize_key((string) ($condition_item['settings']['comparison'] ?? 'truthy'));
                $requires_expected = in_array($comparison, ['equals', 'not_equals', 'greater_than', 'less_than'], true);
                $expected = (string) ($condition_item['settings']['expected'] ?? '');
                if ($requires_expected && trim($expected) === '') {
                    $errors[] = __('A comparison value is required for the selected custom SQL comparison.', 'codi-email-scheduler');
                }
                if (in_array($comparison, ['greater_than', 'less_than'], true) && trim($expected) !== '' && !is_numeric($expected)) {
                    $errors[] = __('The custom SQL comparison value must be numeric for greater-than and less-than comparisons.', 'codi-email-scheduler');
                }
                if (!$requires_expected) {
                    unset($condition_item['settings']['expected']);
                }
            }

            $validated_conditions[] = $condition_item;
        }
        $normalized['conditions'] = $validated_conditions;

        if ($errors) {
            return new WP_Error('invalid_email_configuration', implode(' ', array_values(array_unique($errors))), [
                'errors' => array_values(array_unique($errors)),
            ]);
        }

        return $normalized;
    }

    private static function validate_settings(array $fields, array $settings, string $definition_label, array &$errors, bool $enforce_required): array {
        $normalized_fields = CES_Registry::normalize_settings_fields($fields, 'email_validation', sanitize_key($definition_label));
        if (is_wp_error($normalized_fields)) {
            $errors[] = $normalized_fields->get_error_message();
            return [];
        }

        $validated = [];
        foreach ($settings as $key => $value) {
            if (!isset($normalized_fields[$key])) {
                $errors[] = sprintf(__('Unknown setting %1$s for %2$s.', 'codi-email-scheduler'), $key, $definition_label);
            }
        }

        foreach ($normalized_fields as $field_key => $field) {
            $raw_value = array_key_exists($field_key, $settings) ? (string) $settings[$field_key] : (string) ($field['default'] ?? '');
            $value = (($field['type'] ?? 'text') === 'textarea') ? sanitize_textarea_field($raw_value) : sanitize_text_field($raw_value);

            if ($enforce_required && !empty($field['required']) && trim($value) === '') {
                $errors[] = sprintf(__('%1$s is required for %2$s.', 'codi-email-scheduler'), (string) $field['label'], $definition_label);
            }

            if (($field['type'] ?? 'text') === 'select' && $value !== '' && !array_key_exists($value, (array) ($field['options'] ?? []))) {
                $errors[] = sprintf(__('Invalid value for %1$s on %2$s.', 'codi-email-scheduler'), (string) $field['label'], $definition_label);
            }

            if ($value !== '' || ($enforce_required && !empty($field['required']))) {
                $validated[$field_key] = $value;
            }
        }

        return $validated;
    }
}
