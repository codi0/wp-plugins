<?php

defined('ABSPATH') || exit;

final class CES_Custom_SQL_Condition {
    private const ALLOWED_PLACEHOLDERS = ['user_id', 'prefix', 'base_prefix'];
    private const MAX_QUERY_LENGTH = 10000;
    private const SLOW_QUERY_SECONDS = 0.5;

    public static function validate_and_resolve(string $sql, int $user_id) {
        global $wpdb;

        $sql = trim($sql);
        if ($sql === '') {
            return new WP_Error('ces_sql_required', __('A SQL SELECT query is required.', 'codi-email-scheduler'));
        }
        if (strlen($sql) > self::MAX_QUERY_LENGTH) {
            return new WP_Error('ces_sql_too_long', __('The SQL query is too long.', 'codi-email-scheduler'));
        }

        $analysis = self::analyse($sql);
        if (is_wp_error($analysis)) {
            return $analysis;
        }
        $sql = $analysis['sql'];

        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $sql, $matches);
        foreach (array_unique($matches[1] ?? []) as $placeholder) {
            if (!in_array((string) $placeholder, self::ALLOWED_PLACEHOLDERS, true)) {
                return new WP_Error('ces_sql_unknown_placeholder', sprintf(__('Unknown SQL placeholder: {%s}. Placeholders must use the documented lowercase spelling.', 'codi-email-scheduler'), $placeholder));
            }
        }
        if (preg_match('/\{[^}]+\}/', preg_replace('/\{(?:user_id|prefix|base_prefix)\}/', '', $sql))) {
            return new WP_Error('ces_sql_unknown_placeholder', __('The SQL query contains an unsupported or malformed placeholder.', 'codi-email-scheduler'));
        }

        return str_replace(
            ['{user_id}', '{prefix}', '{base_prefix}'],
            [(string) absint($user_id), (string) ($wpdb->prefix ?? ''), (string) ($wpdb->base_prefix ?? ($wpdb->prefix ?? ''))],
            $sql
        );
    }

    public static function execute(string $sql, int $user_id, bool $include_database_error = false) {
        global $wpdb;

        $resolved = self::validate_and_resolve($sql, $user_id);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $wpdb->last_error = '';
        $started = microtime(true);
        try {
            $value = $wpdb->get_var($resolved);
        } catch (Throwable $throwable) {
            $message = __('The custom SQL query could not be executed.', 'codi-email-scheduler');
            if ($include_database_error) {
                $detail = sanitize_text_field($throwable->getMessage());
                if ($detail !== '') {
                    $message .= ' ' . sprintf(__('Database error: %s', 'codi-email-scheduler'), $detail);
                }
            }
            return new WP_Error('ces_sql_execution_failed', $message);
        }
        $duration = microtime(true) - $started;

        if ($duration >= self::SLOW_QUERY_SECONDS) {
            do_action('ces_custom_sql_slow_query', $duration, hash('sha256', $sql), $user_id);
        }

        $database_error = trim((string) ($wpdb->last_error ?? ''));
        if ($database_error !== '') {
            $message = __('The custom SQL query could not be executed.', 'codi-email-scheduler');
            if ($include_database_error) {
                $message .= ' ' . sprintf(__('Database error: %s', 'codi-email-scheduler'), sanitize_text_field($database_error));
            }
            return new WP_Error('ces_sql_execution_failed', $message);
        }

        return $value;
    }

    /**
     * Execute a query during an explicit administrator save solely to confirm
     * that the database accepts it. The scalar result is intentionally ignored.
     *
     * @return true|WP_Error
     */
    public static function test_for_save(string $sql, int $user_id) {
        $result = self::execute($sql, $user_id, true);
        return is_wp_error($result) ? $result : true;
    }

    public static function compare($actual, string $comparison, string $expected = ''): bool {
        switch ($comparison) {
            case 'truthy':
                return $actual !== null && $actual !== '' && $actual !== 0 && $actual !== '0';
            case 'falsy':
                return $actual === null || $actual === '' || $actual === 0 || $actual === '0';
            case 'equals':
                return $actual !== null && (string) $actual === $expected;
            case 'not_equals':
                return $actual === null || (string) $actual !== $expected;
            case 'greater_than':
                return is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected;
            case 'less_than':
                return is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected;
            case 'is_null':
                return $actual === null;
            case 'not_null':
                return $actual !== null;
            default:
                return false;
        }
    }

    private static function analyse(string $sql) {
        $length = strlen($sql);
        $code = '';
        $state = 'code';
        $last_code_position = -1;
        $semicolon_positions = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'single') {
                if ($char === '\\' && $next !== '') { $i++; continue; }
                if ($char === "'" && $next === "'") { $i++; continue; }
                if ($char === "'") { $state = 'code'; }
                continue;
            }
            if ($state === 'double') {
                if ($char === '\\' && $next !== '') { $i++; continue; }
                if ($char === '"' && $next === '"') { $i++; continue; }
                if ($char === '"') { $state = 'code'; }
                continue;
            }
            if ($state === 'backtick') {
                if ($char === '`' && $next === '`') { $i++; continue; }
                if ($char === '`') { $state = 'code'; }
                continue;
            }

            if ($char === "'") { $state = 'single'; $code .= ' '; continue; }
            if ($char === '"') { $state = 'double'; $code .= ' '; continue; }
            if ($char === '`') { $state = 'backtick'; $code .= ' '; continue; }
            if (($char === '-' && $next === '-') || ($char === '/' && $next === '*') || $char === '#') {
                return new WP_Error('ces_sql_comments_forbidden', __('SQL comments are not allowed.', 'codi-email-scheduler'));
            }
            if ($char === ';') { $semicolon_positions[] = $i; }
            if (!ctype_space($char)) { $last_code_position = $i; }
            $code .= $char;
        }

        if ($state !== 'code') {
            return new WP_Error('ces_sql_unclosed_quote', __('The SQL query contains an unclosed quoted value or identifier.', 'codi-email-scheduler'));
        }
        if (!preg_match('/^\s*SELECT\b/i', $code)) {
            return new WP_Error('ces_sql_select_only', __('Custom SQL conditions must contain a single SELECT query.', 'codi-email-scheduler'));
        }
        if ($semicolon_positions) {
            if (count($semicolon_positions) !== 1 || $semicolon_positions[0] !== $last_code_position) {
                return new WP_Error('ces_sql_semicolon_forbidden', __('Stacked SQL statements are not allowed.', 'codi-email-scheduler'));
            }
            $sql = rtrim(substr($sql, 0, $semicolon_positions[0]));
            $code = rtrim(substr($code, 0, $semicolon_positions[0]));
        }

        if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|RENAME|GRANT|REVOKE|CALL|LOAD|HANDLER|LOCK|UNLOCK)\b/i', $code)
            || preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $code)) {
            return new WP_Error('ces_sql_unsafe_keyword', __('The SQL query contains a prohibited operation.', 'codi-email-scheduler'));
        }
        if (preg_match('/\b(?:SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|LOAD_FILE)\s*\(/i', $code)) {
            return new WP_Error('ces_sql_unsafe_function', __('The SQL query contains a prohibited database function.', 'codi-email-scheduler'));
        }

        return ['sql' => $sql, 'code' => $code];
    }
}
