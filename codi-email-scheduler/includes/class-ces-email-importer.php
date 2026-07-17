<?php

defined('ABSPATH') || exit;

final class CES_Email_Importer {
    public const FORMAT = 'codi-email-scheduler';
    public const FORMAT_VERSION = 1;
    public const MAX_FILE_BYTES = 1048576;

    /** @return array|WP_Error */
    public static function preview_upload(array $file, int $test_user_id) {
        $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error !== UPLOAD_ERR_OK) {
            return new WP_Error('import_upload_failed', __('Select a valid JSON file to import.', 'codi-email-scheduler'));
        }

        $size = isset($file['size']) ? absint($file['size']) : 0;
        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($size < 1 || $size > self::MAX_FILE_BYTES || $tmp_name === '' || !is_uploaded_file($tmp_name)) {
            return new WP_Error('import_file_invalid', __('The import file must be a non-empty JSON file no larger than 1 MB.', 'codi-email-scheduler'));
        }

        $contents = file_get_contents($tmp_name);
        if (!is_string($contents)) {
            return new WP_Error('import_file_read_failed', __('The import file could not be read.', 'codi-email-scheduler'));
        }

        return self::preview_json($contents, $test_user_id);
    }

    /** @return array|WP_Error */
    public static function preview_json(string $json, int $test_user_id) {
        $document = json_decode($json, true);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('import_json_invalid', sprintf(__('Invalid JSON: %s', 'codi-email-scheduler'), json_last_error_msg()));
        }
        $schema_error = self::validate_document_schema($document);
        if (is_wp_error($schema_error)) {
            return $schema_error;
        }
        if ($document['format'] !== self::FORMAT || $document['version'] !== self::FORMAT_VERSION) {
            return new WP_Error('import_format_invalid', __('The file is not a supported Codi Email Scheduler import document.', 'codi-email-scheduler'));
        }
        if (!$document['emails']) {
            return new WP_Error('import_emails_missing', __('The import file does not contain any email definitions.', 'codi-email-scheduler'));
        }

        $existing = CES_Storage::get_emails();
        $existing_by_key = [];
        foreach ($existing as $email) {
            $key = sanitize_key((string) ($email['import_key'] ?? ''));
            if ($key !== '') {
                if (isset($existing_by_key[$key])) {
                    return new WP_Error('existing_import_key_duplicate', sprintf(__('Existing emails contain the duplicate import key %s.', 'codi-email-scheduler'), $key));
                }
                $existing_by_key[$key] = $email;
            }
        }

        $seen = [];
        $items = [];
        foreach (array_values($document['emails']) as $index => $raw) {
            $position = $index + 1;
            if (!is_array($raw) || self::is_list($raw)) {
                $items[] = self::invalid_item('', sprintf(__('Email %d must be an object.', 'codi-email-scheduler'), $position));
                continue;
            }
            $schema_error = self::validate_email_schema($raw, $position);
            if (is_wp_error($schema_error)) {
                $items[] = self::invalid_item('', $schema_error->get_error_message(), (string) ($raw['name'] ?? ''));
                continue;
            }

            $key = sanitize_key((string) ($raw['key'] ?? ''));
            if ($key === '' || (string) ($raw['key'] ?? '') !== $key) {
                $items[] = self::invalid_item($key, sprintf(__('Email %d has an invalid key. Use lowercase letters, numbers, hyphens, or underscores.', 'codi-email-scheduler'), $position));
                continue;
            }
            if (isset($seen[$key])) {
                $items[] = self::invalid_item($key, sprintf(__('The import key %s appears more than once in the file.', 'codi-email-scheduler'), $key));
                continue;
            }
            $seen[$key] = true;

            $email = self::map_definition($raw, $key);
            $match = $existing_by_key[$key] ?? null;
            if ($match) {
                $email['delays'] = CES_Storage::reconcile_delay_ids($email['delays'], (array) ($match['delays'] ?? []));
            }
            $validated = CES_Email_Validator::validate($email, true);
            if (is_wp_error($validated)) {
                $items[] = self::invalid_item($key, $validated->get_error_message(), (string) ($email['name'] ?? ''));
                continue;
            }

            $sql_test = self::test_custom_sql($validated, $test_user_id);
            if (is_wp_error($sql_test)) {
                $items[] = self::invalid_item($key, $sql_test->get_error_message(), (string) ($validated['name'] ?? ''));
                continue;
            }

            if ($match) {
                $validated['id'] = $match['id'];
                $validated['created_at'] = $match['created_at'];
                $status = self::definitions_equal($match, $validated) ? 'unchanged' : 'update';
                $diff = $status === 'update' ? self::diff($match, $validated) : [];
            } else {
                $validated['id'] = '';
                $status = 'new';
                $diff = [];
            }

            $items[] = [
                'key'        => $key,
                'name'       => (string) $validated['name'],
                'status'     => $status,
                'errors'     => [],
                'diff'       => $diff,
                'definition' => $validated,
                'enabled_provided' => array_key_exists('enabled', $raw),
                'requested_enabled' => $raw['enabled'] ?? null,
                'existing_hash' => $match ? self::definition_hash($match) : '',
            ];
        }

        $counts = ['new' => 0, 'update' => 0, 'unchanged' => 0, 'invalid' => 0];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'invalid');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return ['items' => $items, 'counts' => $counts, 'created_at' => time()];
    }

    /** @return array|WP_Error */
    public static function apply(array $preview, string $mode, bool $enable_imported) {
        if (!in_array($mode, ['create_update', 'create_only', 'update_only'], true)) {
            return new WP_Error('import_mode_invalid', __('Select a valid import mode.', 'codi-email-scheduler'));
        }
        $items = isset($preview['items']) && is_array($preview['items']) ? $preview['items'] : [];
        if (!$items) {
            return new WP_Error('import_preview_missing', __('The import preview has expired or is invalid.', 'codi-email-scheduler'));
        }
        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'invalid') {
                return new WP_Error('import_contains_invalid', __('Resolve all invalid definitions before importing.', 'codi-email-scheduler'));
            }
        }

        $emails = CES_Storage::get_emails();
        $current_by_key = [];
        foreach ($emails as $stored) {
            $stored_key = sanitize_key((string) ($stored['import_key'] ?? ''));
            if ($stored_key !== '') {
                if (isset($current_by_key[$stored_key])) {
                    return new WP_Error('existing_import_key_duplicate', sprintf(__('Existing emails contain the duplicate import key %s. Generate a new preview after resolving the duplicate.', 'codi-email-scheduler'), $stored_key));
                }
                $current_by_key[$stored_key] = $stored;
            }
        }
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if (($status === 'new' && $mode === 'update_only') || (in_array($status, ['update', 'unchanged'], true) && $mode === 'create_only')) {
                $counts['skipped']++;
                continue;
            }

            $item_key = sanitize_key((string) ($item['key'] ?? ''));
            $current_match = $current_by_key[$item_key] ?? null;
            if ($status === 'new' && $current_match) {
                return new WP_Error('import_preview_stale', __('An imported email was added after preview. Generate a new preview.', 'codi-email-scheduler'));
            }
            if (in_array($status, ['update', 'unchanged'], true) && (!$current_match || self::definition_hash($current_match) !== (string) ($item['existing_hash'] ?? ''))) {
                return new WP_Error('import_preview_stale', __('An imported email changed after preview. Generate a new preview.', 'codi-email-scheduler'));
            }

            $definition = isset($item['definition']) && is_array($item['definition']) ? $item['definition'] : [];
            $enabled_provided = !empty($item['enabled_provided']);
            if ($status === 'new') {
                $definition['enabled'] = ($enable_imported && $enabled_provided && $item['requested_enabled'] === true) ? '1' : '0';
            } elseif ($enable_imported && $enabled_provided) {
                $definition['enabled'] = $item['requested_enabled'] === true ? '1' : '0';
            } else {
                $definition['enabled'] = (string) ($current_match['enabled'] ?? '0');
            }

            if ($status === 'unchanged' && !$enable_imported) {
                $counts['unchanged']++;
                continue;
            }
            if ($status === 'unchanged' && $current_match && $definition['enabled'] === (string) ($current_match['enabled'] ?? '0')) {
                $counts['unchanged']++;
                continue;
            }

            if ($status === 'new') {
                $definition['id'] = CES_Storage::new_email_id();
                $definition['created_at'] = current_time('mysql');
                $counts['created']++;
            } else {
                $id = sanitize_key((string) ($definition['id'] ?? ''));
                if ($id === '' || !$current_match || (string) ($current_match['id'] ?? '') !== $id || !isset($emails[$id])) {
                    return new WP_Error('import_match_missing', __('An email matched during preview but no longer exists. Generate a new preview.', 'codi-email-scheduler'));
                }
                $definition['created_at'] = $emails[$id]['created_at'];
                $counts['updated']++;
            }
            $definition['updated_at'] = current_time('mysql');
            $validated = CES_Email_Validator::validate($definition, !empty($definition['enabled']));
            if (is_wp_error($validated)) {
                return $validated;
            }
            $sql_test = self::test_custom_sql($validated, get_current_user_id());
            if (is_wp_error($sql_test)) {
                return $sql_test;
            }
            $emails[$validated['id']] = $validated;
        }

        if (!CES_Storage::save_emails($emails)) {
            return new WP_Error('import_save_failed', __('The imported email definitions could not be saved.', 'codi-email-scheduler'));
        }
        return $counts;
    }

    /** @return true|WP_Error */
    private static function validate_document_schema(array $document) {
        if (self::is_list($document)) {
            return new WP_Error('import_document_type_invalid', __('The import document must be a JSON object.', 'codi-email-scheduler'));
        }
        $unknown = array_diff(array_keys($document), ['format', 'version', 'emails']);
        if ($unknown) {
            return new WP_Error('import_document_unknown_fields', sprintf(__('Unknown top-level field: %s.', 'codi-email-scheduler'), implode(', ', $unknown)));
        }
        if (!isset($document['format']) || !is_string($document['format']) || !isset($document['version']) || !is_int($document['version'])) {
            return new WP_Error('import_document_fields_invalid', __('The import format must be a string and version must be the integer 1.', 'codi-email-scheduler'));
        }
        if (!isset($document['emails']) || !is_array($document['emails']) || !self::is_list($document['emails'])) {
            return new WP_Error('import_emails_invalid', __('The emails field must be a JSON array.', 'codi-email-scheduler'));
        }
        return true;
    }

    /** @return true|WP_Error */
    private static function validate_email_schema(array $raw, int $position) {
        $allowed = ['key', 'name', 'enabled', 'event', 'processing_mode', 'condition_mode', 'conditions', 'delays', 'subject', 'body'];
        $unknown = array_diff(array_keys($raw), $allowed);
        if ($unknown) {
            return new WP_Error('import_email_unknown_fields', sprintf(__('Email %1$d contains unknown field(s): %2$s.', 'codi-email-scheduler'), $position, implode(', ', $unknown)));
        }
        foreach (['key', 'name', 'subject', 'body'] as $field) {
            if (!array_key_exists($field, $raw) || !is_string($raw[$field])) {
                return new WP_Error('import_email_field_invalid', sprintf(__('Email %1$d field %2$s must be a string.', 'codi-email-scheduler'), $position, $field));
            }
        }
        if (isset($raw['enabled']) && !is_bool($raw['enabled'])) {
            return new WP_Error('import_email_enabled_invalid', sprintf(__('Email %d enabled must be a JSON boolean.', 'codi-email-scheduler'), $position));
        }
        if (isset($raw['processing_mode']) && (!is_string($raw['processing_mode']) || !in_array($raw['processing_mode'], ['immediate', 'shutdown'], true))) {
            return new WP_Error('import_email_processing_mode_invalid', sprintf(__('Email %d processing_mode must be immediate or shutdown.', 'codi-email-scheduler'), $position));
        }
        if (isset($raw['condition_mode']) && (!is_string($raw['condition_mode']) || !in_array($raw['condition_mode'], ['all', 'any'], true))) {
            return new WP_Error('import_email_condition_mode_invalid', sprintf(__('Email %d condition_mode must be all or any.', 'codi-email-scheduler'), $position));
        }
        if (!isset($raw['event']) || !is_array($raw['event']) || self::is_list($raw['event'])) {
            return new WP_Error('import_email_event_invalid', sprintf(__('Email %d event must be an object.', 'codi-email-scheduler'), $position));
        }
        $event_unknown = array_diff(array_keys($raw['event']), ['key', 'settings']);
        if ($event_unknown || !isset($raw['event']['key']) || !is_string($raw['event']['key']) || (isset($raw['event']['settings']) && (!is_array($raw['event']['settings']) || ($raw['event']['settings'] !== [] && self::is_list($raw['event']['settings']))))) {
            return new WP_Error('import_email_event_schema_invalid', sprintf(__('Email %d has an invalid event object.', 'codi-email-scheduler'), $position));
        }
        foreach (['conditions', 'delays'] as $field) {
            if (!isset($raw[$field]) || !is_array($raw[$field]) || !self::is_list($raw[$field])) {
                return new WP_Error('import_email_list_invalid', sprintf(__('Email %1$d field %2$s must be a JSON array.', 'codi-email-scheduler'), $position, $field));
            }
        }
        foreach ($raw['conditions'] as $condition) {
            if (!is_array($condition) || self::is_list($condition) || array_diff(array_keys($condition), ['key', 'operator', 'settings']) || !isset($condition['key']) || !is_string($condition['key']) || (isset($condition['operator']) && !is_string($condition['operator'])) || (isset($condition['settings']) && (!is_array($condition['settings']) || ($condition['settings'] !== [] && self::is_list($condition['settings']))))) {
                return new WP_Error('import_email_condition_invalid', sprintf(__('Email %d contains an invalid condition object.', 'codi-email-scheduler'), $position));
            }
        }
        foreach ($raw['delays'] as $delay) {
            if (!is_array($delay) || self::is_list($delay) || array_diff(array_keys($delay), ['value', 'unit']) || !array_key_exists('value', $delay) || !is_int($delay['value']) || $delay['value'] < 0 || !isset($delay['unit']) || !is_string($delay['unit'])) {
                return new WP_Error('import_email_delay_invalid', sprintf(__('Email %d contains an invalid delay. Delay values must be non-negative JSON integers.', 'codi-email-scheduler'), $position));
            }
        }
        return true;
    }

    private static function is_list(array $value): bool {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function map_definition(array $raw, string $key): array {
        $event = isset($raw['event']) && is_array($raw['event']) ? $raw['event'] : [];
        $conditions = [];
        foreach ((array) ($raw['conditions'] ?? []) as $condition) {
            if (!is_array($condition)) {
                $conditions[] = [];
                continue;
            }
            $conditions[] = [
                'key'      => (string) ($condition['key'] ?? ''),
                'operator' => (string) ($condition['operator'] ?? 'is_true'),
                'settings' => isset($condition['settings']) && is_array($condition['settings']) ? $condition['settings'] : [],
            ];
        }
        return [
            'id'             => '',
            'import_key'     => $key,
            'enabled'        => '1',
            'name'           => (string) ($raw['name'] ?? ''),
            'event_key'      => (string) ($event['key'] ?? ''),
            'event_settings' => isset($event['settings']) && is_array($event['settings']) ? $event['settings'] : [],
            'processing_mode' => (string) ($raw['processing_mode'] ?? 'immediate'),
            'condition_mode' => (string) ($raw['condition_mode'] ?? 'all'),
            'conditions'     => $conditions,
            'delays'         => $raw['delays'] ?? [],
            'subject'        => (string) ($raw['subject'] ?? ''),
            'body'           => (string) ($raw['body'] ?? ''),
        ];
    }

    private static function definitions_equal(array $left, array $right): bool {
        return self::comparable($left) === self::comparable($right);
    }

    private static function comparable(array $email): array {
        return [
            'import_key'     => (string) ($email['import_key'] ?? ''),
            'name'           => (string) ($email['name'] ?? ''),
            'event_key'      => (string) ($email['event_key'] ?? ''),
            'event_settings' => (array) ($email['event_settings'] ?? []),
            'processing_mode' => (string) ($email['processing_mode'] ?? 'immediate'),
            'condition_mode' => (string) ($email['condition_mode'] ?? 'all'),
            'conditions'     => (array) ($email['conditions'] ?? []),
            'delays'         => (array) ($email['delays'] ?? []),
            'subject'        => (string) ($email['subject'] ?? ''),
            'body'           => (string) ($email['body'] ?? ''),
        ];
    }

    private static function definition_hash(array $email): string {
        return hash('sha256', wp_json_encode(self::comparable($email)));
    }

    private static function diff(array $existing, array $incoming): array {
        $labels = [
            'name' => __('Name', 'codi-email-scheduler'), 'event_key' => __('Event', 'codi-email-scheduler'),
            'event_settings' => __('Event settings', 'codi-email-scheduler'), 'processing_mode' => __('Processing mode', 'codi-email-scheduler'), 'condition_mode' => __('Condition mode', 'codi-email-scheduler'),
            'conditions' => __('Conditions', 'codi-email-scheduler'), 'delays' => __('Delays', 'codi-email-scheduler'),
            'subject' => __('Subject', 'codi-email-scheduler'), 'body' => __('Body', 'codi-email-scheduler'),
        ];
        $a = self::comparable($existing); $b = self::comparable($incoming); $diff = [];
        foreach ($labels as $key => $label) {
            if ($a[$key] !== $b[$key]) { $diff[] = $label; }
        }
        return $diff;
    }

    private static function invalid_item(string $key, string $error, string $name = ''): array {
        return ['key' => $key, 'name' => $name, 'status' => 'invalid', 'errors' => [$error], 'diff' => [], 'definition' => []];
    }

    /** @return true|WP_Error */
    private static function test_custom_sql(array $email, int $user_id) {
        foreach ((array) ($email['conditions'] ?? []) as $condition) {
            if (($condition['key'] ?? '') !== 'custom_sql_scalar') { continue; }
            $sql = (string) (($condition['settings']['sql'] ?? ''));
            if (trim($sql) === '') { continue; }
            $test = CES_Custom_SQL_Condition::test_for_save($sql, absint($user_id));
            if (is_wp_error($test)) { return $test; }
        }
        return true;
    }
}
