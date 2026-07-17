<?php

defined('ABSPATH') || exit;

final class CES_Context {
    public static function user(array $context): ?WP_User {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        if (!$user_id) {
            return null;
        }

        $user = get_user_by('id', $user_id);
        return $user instanceof WP_User ? $user : null;
    }

    public static function user_email(array $context): string {
        $user = self::user($context);
        if ($user instanceof WP_User && is_email($user->user_email)) {
            return sanitize_email((string) $user->user_email);
        }

        return !empty($context['email']) && is_email((string) $context['email'])
            ? sanitize_email((string) $context['email'])
            : '';
    }

    public static function user_roles(array $context): array {
        $user = self::user($context);
        if ($user instanceof WP_User) {
            return array_values(array_filter(array_unique(array_map('sanitize_key', (array) $user->roles))));
        }

        if (!empty($context['user_roles']) && is_array($context['user_roles'])) {
            return array_values(array_filter(array_unique(array_map('sanitize_key', $context['user_roles']))));
        }

        if (!empty($context['role']) && is_scalar($context['role'])) {
            $role = sanitize_key((string) $context['role']);
            return $role !== '' ? [$role] : [];
        }

        return [];
    }

    public static function user_meta(array $context, string $meta_key, $default = '') {
        $user = self::user($context);
        if (!$user instanceof WP_User || $meta_key === '') {
            return $default;
        }

        return get_user_meta((int) $user->ID, $meta_key, true);
    }

    public static function user_meta_exists(array $context, string $meta_key): bool {
        $user = self::user($context);
        return $user instanceof WP_User && $meta_key !== '' && metadata_exists('user', (int) $user->ID, $meta_key);
    }

    public static function organisation(array $context) {
        $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
        if (!$user_id || !function_exists('get_organisation')) {
            return null;
        }

        try {
            return get_organisation($user_id);
        } catch (Throwable $e) {
            return null;
        }
    }
}
