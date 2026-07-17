<?php

defined('ABSPATH') || exit;

final class CES_Event_Identity {
    public static function normalize(string $identity): string {
        $identity = sanitize_text_field($identity);
        return function_exists('mb_substr') ? mb_substr($identity, 0, 190) : substr($identity, 0, 190);
    }

    public static function occurrence(string $prefix, array $parts = []): string {
        $segments = [sanitize_key($prefix)];
        foreach ($parts as $part) {
            if (is_bool($part) || $part === null || !is_scalar($part)) {
                continue;
            }
            $value = sanitize_text_field((string) $part);
            if ($value !== '') {
                $segments[] = $value;
            }
        }
        $uuid = wp_generate_uuid4();
        $identity_prefix = trim(implode(':', $segments), ':');
        $prefix_limit = 190 - strlen($uuid) - 1;
        if ($identity_prefix === '') {
            return self::normalize($uuid);
        }

        return self::normalize(substr($identity_prefix, 0, max(0, $prefix_limit)) . ':' . $uuid);
    }
}
