<?php

defined('ABSPATH') || exit;

final class CES_Default_Emails {
    private const FILE = 'config/default-emails.json';
    private static int $last_skipped_count = 0;

    public static function maybe_seed(): void {
        if (CES_Storage::get_emails()) {
            return;
        }

        $replacement = self::build_default_set([]);
        if (!is_wp_error($replacement)) {
            CES_Storage::save_emails($replacement);
        }
    }

    public static function reset_defaults(): bool {
        self::$last_skipped_count = 0;
        $current = CES_Storage::get_emails();
        $default_keys = self::bundled_import_keys();
        if (is_wp_error($default_keys)) {
            return false;
        }

        foreach ($current as $id => &$email) {
            $bundled_key = self::bundled_key_for_email((string) $id, (array) $email, $default_keys);
            if ($bundled_key !== '') {
                $email['import_key'] = $bundled_key;
            }
        }
        unset($email);

        $replacement = self::build_default_set($current);
        if (is_wp_error($replacement)) {
            return false;
        }
        $replaced_ids = [];
        foreach ($current as $id => $email) {
            if (self::bundled_key_for_email((string) $id, (array) $email, $default_keys) !== '') {
                unset($current[$id]);
                $replaced_ids[] = (string) $id;
            }
        }

        foreach ($replacement as $id => $email) {
            if (in_array((string) ($email['import_key'] ?? ''), $default_keys, true)) {
                $current[$id] = $email;
            }
        }

        if (!CES_Storage::save_emails($current)) {
            return false;
        }
        foreach ($replaced_ids as $email_id) {
            CES_Backfill::delete_email_state($email_id);
        }
        return true;
    }

    public static function last_skipped_count(): int {
        return self::$last_skipped_count;
    }

    /** @return array|WP_Error */
    public static function definitions() {
        self::$last_skipped_count = 0;
        return self::build_default_set([]);
    }

    /** @return array|WP_Error */
    private static function build_default_set(array $existing) {
        $path = CES_PLUGIN_DIR . self::FILE;
        $json = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            return new WP_Error('default_email_file_unreadable', __('The bundled default email file could not be read.', 'codi-email-scheduler'));
        }

        $preview = CES_Email_Importer::preview_json($json, get_current_user_id());
        if (is_wp_error($preview)) {
            return $preview;
        }

        $existing_by_key = [];
        foreach ($existing as $email) {
            $key = sanitize_key((string) ($email['import_key'] ?? ''));
            if ($key !== '') {
                $existing_by_key[$key] = $email;
            }
        }

        $document = json_decode($json, true);
        $raw_by_key = [];
        foreach ((array) ($document['emails'] ?? []) as $raw_email) {
            if (is_array($raw_email)) {
                $raw_key = sanitize_key((string) ($raw_email['key'] ?? ''));
                if ($raw_key !== '') {
                    $raw_by_key[$raw_key] = $raw_email;
                }
            }
        }

        $defaults = [];
        $now = current_time('mysql');
        foreach ((array) ($preview['items'] ?? []) as $item) {
            $key = sanitize_key((string) ($item['key'] ?? ''));
            if (($item['status'] ?? '') === 'invalid') {
                $raw_email = (array) ($raw_by_key[$key] ?? []);
                if (self::requires_unavailable_woocommerce($raw_email)) {
                    self::$last_skipped_count++;
                    continue;
                }
                return new WP_Error('default_email_invalid', implode(' ', (array) ($item['errors'] ?? [])));
            }

            $email = (array) ($item['definition'] ?? []);
            $match = $existing_by_key[$key] ?? null;
            if ($match) {
                $email['id'] = (string) $match['id'];
                $email['created_at'] = (string) $match['created_at'];
                $email['delays'] = CES_Storage::reconcile_delay_ids((array) ($email['delays'] ?? []), (array) ($match['delays'] ?? []));
            } else {
                $email['id'] = CES_Storage::new_email_id();
                $email['created_at'] = $now;
            }
            $email['enabled'] = '0';
            $email['updated_at'] = $now;

            $validated = CES_Email_Validator::validate($email, false);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $defaults[$validated['id']] = $validated;
        }

        return $defaults;
    }


    private static function requires_unavailable_woocommerce(array $definition): bool {
        if (class_exists('CES_WooCommerce_Helpers') && CES_WooCommerce_Helpers::is_woocommerce_dependency_active()) {
            return false;
        }

        $event_key = sanitize_key((string) ($definition['event']['key'] ?? ''));
        if (strpos($event_key, 'wc_') === 0) {
            return true;
        }
        foreach ((array) ($definition['conditions'] ?? []) as $condition) {
            $condition_key = sanitize_key((string) ($condition['key'] ?? ''));
            if (strpos($condition_key, 'wc_') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function bundled_key_for_email(string $id, array $email, array $default_keys): string {
        $import_key = sanitize_key((string) ($email['import_key'] ?? ''));
        if ($import_key !== '' && in_array($import_key, $default_keys, true)) {
            return $import_key;
        }

        foreach ($default_keys as $default_key) {
            if ($id === str_replace('-', '_', $default_key)) {
                return $default_key;
            }
        }

        return '';
    }

    /** @return array|WP_Error */
    private static function bundled_import_keys() {
        $path = CES_PLUGIN_DIR . self::FILE;
        $json = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            return new WP_Error('default_email_file_unreadable', __('The bundled default email file could not be read.', 'codi-email-scheduler'));
        }

        $document = json_decode($json, true);
        if (!is_array($document) || !isset($document['emails']) || !is_array($document['emails'])) {
            return new WP_Error('default_email_file_invalid', __('The bundled default email file is invalid.', 'codi-email-scheduler'));
        }

        $keys = [];
        foreach ($document['emails'] as $definition) {
            $key = sanitize_key((string) ($definition['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                return new WP_Error('default_email_key_invalid', __('The bundled default email file contains an invalid or duplicate key.', 'codi-email-scheduler'));
            }
            $keys[$key] = true;
        }

        return array_keys($keys);
    }
}
