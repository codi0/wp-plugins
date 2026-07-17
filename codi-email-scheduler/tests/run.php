<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('CES_OPTION_EMAILS', 'ces_emails');
define('CES_OPTION_SETTINGS', 'ces_settings');
define('CES_PLUGIN_DIR', dirname(__DIR__) . '/');
define('CES_AS_ACTION', 'ces_run_scheduled_email');
define('CES_AS_BACKFILL_ACTION', 'ces_run_retrospective_batch');
define('CES_OPTION_BACKFILL_LATEST_PREFIX', 'ces_backfill_latest_');
define('CES_OPTION_BACKFILL_PREFIX', 'ces_backfill_run_');
define('CES_OPTION_BACKFILL_ACTIVE_PREFIX', 'ces_backfill_active_');
define('CES_AS_GROUP', 'codi-email-scheduler');
define('CES_ACTION_SCHEDULER_MIN_VERSION', '4.0.0');
function get_current_user_id(): int { return 99; }
function sanitize_textarea_field(string $value): string { return trim(str_replace("\r", '', $value)); }
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

final class WP_Error {
    private string $code;
    private string $message;
    private $data;

    public function __construct(string $code = '', string $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(?string $code = null) { return $this->data; }
}

class WP_User {
    public int $ID = 0;
    public string $user_email = '';
    public string $user_login = '';
    public array $roles = [];
}

final class ActionScheduler {
    public static function is_initialized(): bool { return (bool) $GLOBALS['ces_test_action_scheduler_initialized']; }
    public static function init(): void {
        $GLOBALS['ces_test_action_scheduler_init_calls']++;
        $GLOBALS['ces_test_action_scheduler_initialized'] = true;
    }
}

final class ActionScheduler_Versions {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ?? (self::$instance = new self());
    }

    public function latest_version(): string {
        return (string) $GLOBALS['ces_test_action_scheduler_version'];
    }
}

$GLOBALS['ces_test_action_scheduler_version'] = '4.0.0';
$GLOBALS['ces_test_action_scheduler_initialized'] = true;
$GLOBALS['ces_test_did_actions'] = ['plugins_loaded' => 1, 'init' => 1, 'action_scheduler_init' => 1];
$GLOBALS['ces_test_filters'] = [];
$GLOBALS['ces_test_actions'] = [];
$GLOBALS['ces_test_mail'] = [];
$GLOBALS['ces_test_mail_results'] = [];
$GLOBALS['ces_test_options'] = [
    CES_OPTION_EMAILS => [],
    CES_OPTION_SETTINGS => [],
];
$GLOBALS['ces_test_scheduled_actions'] = [];
$GLOBALS['ces_test_next_action_id'] = 1;
$GLOBALS['ces_test_unschedule_all_calls'] = [];
$GLOBALS['ces_test_fail_async_schedule'] = false;
$GLOBALS['ces_test_email_schedule_calls'] = 0;
$GLOBALS['ces_test_as_get_scheduled_actions_calls'] = 0;
$GLOBALS['ces_test_fail_email_schedule_on_call'] = 0;

$GLOBALS['ces_test_wpdb_fail_next'] = false;
$GLOBALS['ces_test_wpdb_before_query'] = null;
$GLOBALS['ces_test_wpdb_fail_predicate'] = null;
$GLOBALS['ces_test_cache_deletes'] = [];
$GLOBALS['ces_test_update_option_fail_next'] = false;
$GLOBALS['ces_test_update_option_fail_for'] = [];
$GLOBALS['ces_test_update_option_after'] = null;
$GLOBALS['ces_test_delete_option_after'] = null;
$GLOBALS['ces_test_deleted_options'] = [];
$GLOBALS['ces_test_users'] = [];

function maybe_serialize($value): string { return serialize($value); }
function wp_cache_delete(string $key, string $group = ''): bool { $GLOBALS['ces_test_cache_deletes'][] = [$key, $group]; return true; }

final class CES_Test_WPDB {
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public $next_get_var = 1;
    public bool $throw_next_get_var = false;
    public int $get_var_calls = 0;
    public array $get_var_queries = [];

    public function get_var($query) {
        $this->get_var_calls++;
        $query_text = is_array($query) ? (string) ($query['query'] ?? '') : (string) $query;
        $this->get_var_queries[] = $query_text;
        $this->last_error = '';
        if ($this->throw_next_get_var) {
            $this->throw_next_get_var = false;
            throw new RuntimeException('Database drop-in exception');
        }
        if (stripos($query_text, 'INVALID_TABLE') !== false) {
            $this->last_error = 'Table does not exist';
            return null;
        }
        if (stripos($query_text, 'MAX(') !== false && stripos($query_text, '.ID)') !== false) {
            return $GLOBALS['ces_test_users'] ? max(array_keys($GLOBALS['ces_test_users'])) : 0;
        }
        if (stripos($query_text, 'COUNT(') !== false && stripos($query_text, 'wp_users') !== false) {
            $args = is_array($query) ? ($query['args'] ?? []) : [];
            $max = (int) ($args[0] ?? PHP_INT_MAX);
            return count(array_filter(array_keys($GLOBALS['ces_test_users']), static fn($id): bool => (int) $id <= $max));
        }
        return $this->next_get_var;
    }

    public function prepare(string $query, ...$args): array {
        return ['query' => $query, 'args' => $args];
    }

    public function esc_like(string $value): string {
        return addcslashes($value, '_%\\');
    }

    public function get_col($prepared): array {
        $query = is_array($prepared) ? (string) ($prepared['query'] ?? '') : (string) $prepared;
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        if (stripos($query, 'FROM wp_users') !== false) {
            $last = (int) ($args[0] ?? 0);
            $max = (int) ($args[1] ?? PHP_INT_MAX);
            $limit = (int) ($args[2] ?? 100);
            $ids = array_values(array_filter(array_map('intval', array_keys($GLOBALS['ces_test_users'])), static fn(int $id): bool => $id > $last && $id <= $max));
            sort($ids);
            return array_slice($ids, 0, $limit);
        }
        $pattern = (string) ($args[0] ?? '');
        $prefix = preg_replace('/%$/', '', $pattern) ?? $pattern;
        $prefix = stripcslashes($prefix);
        return array_values(array_filter(array_keys($GLOBALS['ces_test_options']), static function ($name) use ($prefix): bool {
            return strpos((string) $name, $prefix) === 0;
        }));
    }

    public function query($prepared): int {
        if (!empty($GLOBALS['ces_test_wpdb_before_query']) && is_callable($GLOBALS['ces_test_wpdb_before_query'])) {
            $callback = $GLOBALS['ces_test_wpdb_before_query'];
            $GLOBALS['ces_test_wpdb_before_query'] = null;
$GLOBALS['ces_test_wpdb_fail_predicate'] = null;
            $callback($prepared);
        }
        if (!empty($GLOBALS['ces_test_wpdb_fail_predicate']) && is_callable($GLOBALS['ces_test_wpdb_fail_predicate'])
            && ($GLOBALS['ces_test_wpdb_fail_predicate'])($prepared)) {
            return 0;
        }
        if (!empty($GLOBALS['ces_test_wpdb_fail_next'])) {
            $GLOBALS['ces_test_wpdb_fail_next'] = false;
            return 0;
        }
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        if (count($args) !== 3) {
            return 0;
        }
        [$serialized_updated, $option, $serialized_expected] = $args;
        $current = $GLOBALS['ces_test_options'][$option] ?? null;
        if (serialize($current) !== $serialized_expected) {
            return 0;
        }
        $GLOBALS['ces_test_options'][$option] = unserialize($serialized_updated);
        return 1;
    }
}

$GLOBALS['wpdb'] = new CES_Test_WPDB();

function __(string $text, string $domain = ''): string { return $text; }
function _n(string $single, string $plural, int $number, string $domain = ''): string { return $number === 1 ? $single : $plural; }
function absint($value): int { return abs((int) $value); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_title(string $value): string { return sanitize_key(str_replace(' ', '-', $value)); }
function sanitize_email(string $value): string { return filter_var(trim($value), FILTER_SANITIZE_EMAIL) ?: ''; }
function is_email(string $value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_SANITIZE_URL) ?: ''; }
function wp_kses_post(string $value): string { return $value; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function wp_json_encode($value): string { return (string) json_encode($value); }
function wp_parse_args($args, array $defaults = []): array { return array_merge($defaults, is_array($args) ? $args : []); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_current_blog_id(): int { return 1; }
function current_time(string $type, bool $gmt = false) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); }
function get_option(string $name, $default = false) { return $GLOBALS['ces_test_options'][$name] ?? $default; }
function update_option(string $name, $value, bool $autoload = false): bool {
    if (!empty($GLOBALS['ces_test_update_option_fail_next'])) {
        $GLOBALS['ces_test_update_option_fail_next'] = false;
        return false;
    }
    if (!empty($GLOBALS['ces_test_update_option_fail_for'][$name])) {
        return false;
    }
    $changed = !array_key_exists($name, $GLOBALS['ces_test_options']) || $GLOBALS['ces_test_options'][$name] !== $value;
    $GLOBALS['ces_test_options'][$name] = $value;
    if (!empty($GLOBALS['ces_test_update_option_after']) && is_callable($GLOBALS['ces_test_update_option_after'])) {
        ($GLOBALS['ces_test_update_option_after'])($name, $value);
    }
    return $changed;
}
function add_option(string $name, $value = '', string $deprecated = '', bool $autoload = true): bool { if (array_key_exists($name, $GLOBALS['ces_test_options'])) { return false; } $GLOBALS['ces_test_options'][$name] = $value; return true; }
function delete_option(string $name): bool {
    unset($GLOBALS['ces_test_options'][$name]);
    $GLOBALS['ces_test_deleted_options'][] = $name;
    if (!empty($GLOBALS['ces_test_delete_option_after']) && is_callable($GLOBALS['ces_test_delete_option_after'])) {
        ($GLOBALS['ces_test_delete_option_after'])($name);
    }
    return true;
}
function get_site_option(string $name, $default = false) { return $default; }
function is_multisite(): bool { return !empty($GLOBALS['ces_test_is_multisite']); }
function is_user_member_of_blog(int $user_id, int $blog_id = 0): bool {
    $key = $blog_id . ':' . $user_id;
    return !array_key_exists($key, $GLOBALS['ces_test_site_memberships'] ?? []) || !empty($GLOBALS['ces_test_site_memberships'][$key]);
}
function did_action(string $hook): int { return (int) ($GLOBALS['ces_test_did_actions'][$hook] ?? 0); }
function current_filter(): string { return ''; }
function get_user_by(string $field, $value) { return $GLOBALS['ces_test_users'][(int) $value] ?? false; }
function get_bloginfo(string $show = ''): string { return 'Test Site'; }
function wp_login_url(): string { return 'https://example.test/login'; }
function wp_generate_uuid4(): string {
    static $counter = 0;
    $counter++;
    return sprintf('00000000-0000-4000-8000-%012d', $counter);
}
function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['ces_test_actions'][$hook][] = $callback; }
function remove_action(string $hook, $callback, int $priority = 10): void {}
function do_action(string $hook, ...$args): void {
    foreach ($GLOBALS['ces_test_actions'][$hook] ?? [] as $callback) {
        $callback(...$args);
    }
}
function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['ces_test_filters'][$hook][] = $callback; }
function apply_filters(string $hook, $value, ...$args) {
    foreach ($GLOBALS['ces_test_filters'][$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}
function wp_mail(string $to, string $subject, string $body, $headers = [], $attachments = []): bool {
    $GLOBALS['ces_test_mail'][] = compact('to', 'subject', 'body', 'headers', 'attachments');
    if ($GLOBALS['ces_test_mail_results']) {
        return (bool) array_shift($GLOBALS['ces_test_mail_results']);
    }
    return true;
}
function ces_test_action_key(string $hook, array $args, string $group): string {
    return hash('sha256', $hook . '|' . serialize($args) . '|' . $group);
}
function ces_test_schedule_action(int $timestamp, string $hook, array $args, string $group, bool $unique): int {
    if ($hook === CES_AS_ACTION) {
        $GLOBALS['ces_test_email_schedule_calls']++;
        if ((int) $GLOBALS['ces_test_fail_email_schedule_on_call'] === (int) $GLOBALS['ces_test_email_schedule_calls']) {
            return 0;
        }
    }
    $key = ces_test_action_key($hook, $args, $group);
    if ($unique && isset($GLOBALS['ces_test_scheduled_actions'][$key])) {
        return 0;
    }
    $id = $GLOBALS['ces_test_next_action_id']++;
    $GLOBALS['ces_test_scheduled_actions'][$key] = compact('id', 'timestamp', 'hook', 'args', 'group', 'unique');
    return $id;
}
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {
    return ces_test_schedule_action($timestamp, $hook, $args, $group, $unique);
}
function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {
    if (!empty($GLOBALS['ces_test_fail_async_schedule'])) {
        return 0;
    }
    return ces_test_schedule_action(time(), $hook, $args, $group, $unique);
}
function as_has_scheduled_action(string $hook, array $args = [], string $group = ''): bool {
    return isset($GLOBALS['ces_test_scheduled_actions'][ces_test_action_key($hook, $args, $group)]);
}
final class CES_Test_Action_Object {
    private array $args;
    public function __construct(array $args) { $this->args = $args; }
    public function get_args(): array { return $this->args; }
}
function as_get_scheduled_actions(array $query = [], string $return_format = 'OBJECT'): array {
    $GLOBALS['ces_test_as_get_scheduled_actions_calls']++;
    $items = [];
    foreach ($GLOBALS['ces_test_scheduled_actions'] as $action) {
        if (!empty($query['hook']) && ($action['hook'] ?? '') !== $query['hook']) { continue; }
        if (!empty($query['group']) && ($action['group'] ?? '') !== $query['group']) { continue; }
        $items[] = new CES_Test_Action_Object((array) ($action['args'] ?? []));
    }
    return $items;
}
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void {
    $GLOBALS['ces_test_unschedule_all_calls'][] = compact('hook', 'args', 'group');
}
function as_unschedule_action(string $hook, array $args = [], string $group = ''): int {
    $key = ces_test_action_key($hook, $args, $group);
    if (!isset($GLOBALS['ces_test_scheduled_actions'][$key])) {
        return 0;
    }
    $id = (int) $GLOBALS['ces_test_scheduled_actions'][$key]['id'];
    unset($GLOBALS['ces_test_scheduled_actions'][$key]);
    return $id;
}

$base = dirname(__DIR__) . '/includes/';
require_once $base . 'class-ces-storage.php';
require_once $base . 'class-ces-context.php';
require_once $base . 'class-ces-custom-sql-condition.php';
require_once $base . 'class-ces-callable-condition.php';
require_once $base . 'class-ces-registry.php';
require_once $base . 'class-ces-custom-hook-events.php';
require_once $base . 'class-ces-custom-hook-events.php';
if (!function_exists('ces_fire_event')) { function ces_fire_event(string $event_key, array $context = []) { return CES_Engine::fire_event($event_key, $context); } }
require_once $base . 'class-ces-email-validator.php';
require_once $base . 'class-ces-email-importer.php';
require_once $base . 'class-ces-event-identity.php';
require_once $base . 'class-ces-engine.php';
require_once $base . 'class-ces-email-dispatcher.php';
require_once $base . 'class-ces-backfill.php';
require_once $base . 'class-ces-default-emails.php';
require_once $base . 'class-ces-woocommerce-tokens.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

function ces_test_callable_without_user(): bool { return true; }
function ces_test_callable_with_user(int $user_id): bool {
    $GLOBALS['ces_test_callable_user_id'] = $user_id;
    return $user_id === 42;
}
function ces_test_callable_invalid_result() { return 'yes'; }
function ces_test_callable_throws(): bool { throw new RuntimeException('Sensitive callable failure'); }
function ces_test_callable_returns_error() {
    return new WP_Error('sensitive_callable_code', 'Sensitive callable error', ['secret' => 'organisation-42']);
}
function ces_test_callable_requires_two_args(int $user_id, string $required): bool { return true; }
function ces_test_callable_trusted_side_effect(): bool {
    $GLOBALS['ces_test_callable_side_effects'] = ($GLOBALS['ces_test_callable_side_effects'] ?? 0) + 1;
    return true;
}
final class CES_Test_Static_Callable {
    public static function check(int $user_id): bool { return $user_id === 42; }
    public function instanceCheck(int $user_id): bool { return $user_id === 42; }
}

CES_Registry::register_core_items();
CES_Custom_Hook_Events::init();
CES_Custom_Hook_Events::init();
$callable_condition = CES_Registry::condition('callable_check');
$assert(is_array($callable_condition), 'The callable condition must be registered.');
$callable_callback = is_array($callable_condition) ? ($callable_condition['callback'] ?? null) : null;
$callable_match = is_callable($callable_callback) ? $callable_callback(
    ['user_id' => 42],
    [],
    ['condition_settings' => ['callable' => 'ces_test_callable_with_user', 'pass_user_id' => 'yes']]
) : false;
$assert(is_array($callable_match) && !empty($callable_match['passed']), 'The callable condition must pass the event user ID when configured.');
$assert(($GLOBALS['ces_test_callable_user_id'] ?? 0) === 42, 'The callable condition must pass the evaluated user ID, not the logged-in administrator ID.');
$callable_no_arg = is_callable($callable_callback) ? $callable_callback(
    [],
    [],
    ['condition_settings' => ['callable' => 'ces_test_callable_without_user', 'pass_user_id' => 'no']]
) : false;
$assert(is_array($callable_no_arg) && !empty($callable_no_arg['passed']), 'The callable condition must support zero-argument functions.');
$callable_callback_error = is_callable($callable_callback) ? $callable_callback(
    [],
    [],
    ['condition_settings' => ['callable' => 'ces_test_callable_returns_error', 'pass_user_id' => 'no']]
) : false;
$assert(is_wp_error($callable_callback_error) && $callable_callback_error->get_error_code() === 'ces_callable_returned_error', 'Callable condition callbacks must expose only the redacted plugin error.');
$callable_static = CES_Callable_Condition::execute('CES_Test_Static_Callable::check', true, 42);
$assert($callable_static === true, 'The callable condition must support public static class methods.');
$callable_missing_user = CES_Callable_Condition::execute('ces_test_callable_with_user', true, 0);
$assert(is_wp_error($callable_missing_user), 'The callable condition must fail closed when user ID passing is enabled without a user context.');
$callable_invalid = CES_Callable_Condition::execute('ces_test_callable_invalid_result', false, 0);
$assert(is_wp_error($callable_invalid), 'The callable condition must reject non-boolean results.');
$callable_exception = CES_Callable_Condition::execute('ces_test_callable_throws', false, 0);
$assert(is_wp_error($callable_exception) && strpos($callable_exception->get_error_message(), 'Sensitive') === false, 'Callable exceptions must fail closed without exposing exception details.');
$callable_returned_error = CES_Callable_Condition::execute('ces_test_callable_returns_error', false, 0);
$assert(is_wp_error($callable_returned_error), 'Callable-returned WP_Error values must fail closed.');
$assert($callable_returned_error->get_error_code() === 'ces_callable_returned_error', 'Callable-returned WP_Error codes must be replaced with a plugin-owned code.');
$assert(strpos($callable_returned_error->get_error_message(), 'Sensitive') === false, 'Callable-returned WP_Error messages must not expose callable details.');
$assert($callable_returned_error->get_error_data() === null, 'Callable-returned WP_Error data must not be exposed.');
$callable_signature_failure = CES_Callable_Condition::execute('ces_test_callable_requires_two_args', true, 42);
$assert(is_wp_error($callable_signature_failure) && $callable_signature_failure->get_error_code() === 'ces_callable_failed', 'Incompatible callable signatures must fail closed at runtime.');
$GLOBALS['ces_test_callable_side_effects'] = 0;
$callable_side_effect = CES_Callable_Condition::execute('ces_test_callable_trusted_side_effect', false, 0);
$assert($callable_side_effect === true && $GLOBALS['ces_test_callable_side_effects'] === 1, 'Configured callables are trusted executable code and must run only during condition evaluation.');
$callable_not_found = CES_Callable_Condition::resolve('missing_callable_function');
$assert(is_wp_error($callable_not_found), 'Missing callable functions must be rejected.');
$callable_bad_format = CES_Callable_Condition::resolve('SomeClass->method');
$assert(is_wp_error($callable_bad_format), 'Instance-method syntax must be rejected.');
$callable_non_static = CES_Callable_Condition::resolve('CES_Test_Static_Callable::instanceCheck');
$assert(is_wp_error($callable_non_static), 'Non-static class methods must be rejected.');
$callable_validated = CES_Email_Validator::validate([
    'id' => 'callable-valid',
    'enabled' => '0',
    'event_key' => '',
    'delays' => [['value' => 1, 'unit' => 'hours']],
    'conditions' => [[
        'key' => 'callable_check',
        'must_be' => 'true',
        'settings' => ['callable' => 'ces_test_callable_with_user', 'pass_user_id' => 'yes'],
    ]],
    'subject' => 'Callable',
    'body' => 'Body',
], false);
$assert(!is_wp_error($callable_validated), 'Valid callable conditions must pass structural email validation without executing the callable.');

$role_condition = CES_Registry::condition('wp_user_has_role');
$assert(is_array($role_condition), 'The core WordPress role condition must be registered.');
$role_callback = is_array($role_condition) ? ($role_condition['callback'] ?? null) : null;
$role_match = is_callable($role_callback) ? $role_callback(
    ['user_roles' => ['provider', 'premium_member']],
    [],
    ['condition_settings' => ['role' => 'provider']]
) : false;
$assert(is_array($role_match) && !empty($role_match['passed']), 'The role condition must pass for a matching role.');
$role_miss = is_callable($role_callback) ? $role_callback(
    ['user_roles' => ['provider']],
    [],
    ['condition_settings' => ['role' => 'premium_member']]
) : false;
$assert(is_array($role_miss) && empty($role_miss['passed']), 'The role condition must fail for an absent role.');
$role_invalid = is_callable($role_callback) ? $role_callback(
    ['user_roles' => ['provider']],
    [],
    ['condition_settings' => ['role' => '']]
) : false;
$assert(is_wp_error($role_invalid), 'The role condition must fail closed when its role setting is empty.');

$sql_condition = CES_Registry::condition('custom_sql_scalar');
$assert(is_array($sql_condition), 'The custom SQL scalar condition must be registered.');
$sql_callback = is_array($sql_condition) ? ($sql_condition['callback'] ?? null) : null;
$sql_match = is_callable($sql_callback) ? $sql_callback(
    ['user_id' => 42],
    [],
    ['condition_settings' => [
        'sql' => 'SELECT EXISTS (SELECT 1 FROM {prefix}example WHERE user_id = {user_id})',
        'comparison' => 'truthy',
    ]]
) : false;
$assert(is_array($sql_match) && !empty($sql_match['passed']), 'The custom SQL condition must compare the scalar result.');
$assert(($sql_match['actual'] ?? null) === '[scalar]', 'Custom SQL condition diagnostics must redact non-null scalar results.');
$assert(($sql_match['expected'] ?? null) === null, 'Custom SQL diagnostics must not expose an empty comparison value.');
$sql_equals = is_callable($sql_callback) ? $sql_callback(
    ['user_id' => 42],
    [],
    ['condition_settings' => [
        'sql' => 'SELECT {user_id}',
        'comparison' => 'equals',
        'expected' => '1',
    ]]
) : false;
$assert(is_array($sql_equals) && ($sql_equals['expected'] ?? null) === '[configured]', 'Custom SQL diagnostics must redact configured comparison values.');
$sql_unknown = CES_Custom_SQL_Condition::validate_and_resolve('SELECT {current_user_id}', 42);
$assert(is_wp_error($sql_unknown), 'Unknown SQL placeholders must be rejected.');
$sql_write = CES_Custom_SQL_Condition::validate_and_resolve('DELETE FROM wp_users', 42);
$assert(is_wp_error($sql_write), 'Non-SELECT SQL must be rejected.');
$sql_resolved = CES_Custom_SQL_Condition::validate_and_resolve('SELECT {user_id} FROM {prefix}users', 42);
$assert($sql_resolved === 'SELECT 42 FROM wp_users', 'Supported SQL placeholders must resolve exactly.');

$sql_uppercase = CES_Custom_SQL_Condition::validate_and_resolve('SELECT {USER_ID}', 42);
$assert(is_wp_error($sql_uppercase), 'SQL placeholders must use their documented lowercase spelling.');
$sql_literal_keyword = CES_Custom_SQL_Condition::validate_and_resolve("SELECT 'UPDATE; # not syntax'", 42);
$assert($sql_literal_keyword === "SELECT 'UPDATE; # not syntax'", 'Keywords, comment markers, and semicolons inside quoted strings must remain valid.');
$sql_trailing_semicolon = CES_Custom_SQL_Condition::validate_and_resolve("SELECT 1;\n", 42);
$assert($sql_trailing_semicolon === 'SELECT 1', 'One harmless trailing semicolon must be accepted and removed.');
$sql_stacked = CES_Custom_SQL_Condition::validate_and_resolve('SELECT 1; SELECT 2', 42);
$assert(is_wp_error($sql_stacked), 'Stacked SQL statements must be rejected.');
$sql_sleep = CES_Custom_SQL_Condition::validate_and_resolve('SELECT SLEEP(1)', 42);
$assert(is_wp_error($sql_sleep), 'Delay functions must be rejected.');
$sql_load_file = CES_Custom_SQL_Condition::validate_and_resolve("SELECT LOAD_FILE('/tmp/a')", 42);
$assert(is_wp_error($sql_load_file), 'File-reading functions must be rejected.');
$sql_join = CES_Custom_SQL_Condition::validate_and_resolve("SELECT o.has_membership\nFROM {prefix}organisations o\nINNER JOIN {prefix}organisation_users u ON u.organisation_id = o.id\nWHERE u.user_id = {user_id}\nLIMIT 1", 42);
$assert(strpos((string) $sql_join, 'FROM wp_organisations') !== false && strpos((string) $sql_join, 'u.user_id = 42') !== false, 'Custom-table joins and multiline SQL must resolve correctly.');
$assert(CES_Custom_SQL_Condition::compare(null, 'equals', '') === false, 'NULL must not equal an empty string.');
$assert(CES_Custom_SQL_Condition::compare(null, 'not_equals', '') === true, 'NULL must remain distinct from an empty string for not-equals.');
$GLOBALS['wpdb']->next_get_var = null;
$sql_null = CES_Custom_SQL_Condition::execute('SELECT 1 WHERE 0', 42);
$assert($sql_null === null, 'A valid query returning no row must not be treated as an execution error.');
$sql_admin_error = CES_Custom_SQL_Condition::execute('SELECT 1 FROM INVALID_TABLE', 42, true);
$assert(is_wp_error($sql_admin_error) && strpos($sql_admin_error->get_error_message(), 'Table does not exist') !== false, 'Save-time execution errors must include a sanitised database error.');
$sql_runtime_error = CES_Custom_SQL_Condition::execute('SELECT 1 FROM INVALID_TABLE', 42, false);
$assert(is_wp_error($sql_runtime_error) && strpos($sql_runtime_error->get_error_message(), 'Table does not exist') === false, 'Runtime execution errors must not expose database details.');
$GLOBALS['wpdb']->throw_next_get_var = true;
$sql_admin_exception = CES_Custom_SQL_Condition::execute('SELECT 1', 42, true);
$assert(is_wp_error($sql_admin_exception) && strpos($sql_admin_exception->get_error_message(), 'Database drop-in exception') !== false, 'Save-time database exceptions must be converted to actionable WP_Error results.');
$GLOBALS['wpdb']->throw_next_get_var = true;
$sql_runtime_exception = CES_Custom_SQL_Condition::execute('SELECT 1', 42, false);
$assert(is_wp_error($sql_runtime_exception) && strpos($sql_runtime_exception->get_error_message(), 'Database drop-in exception') === false, 'Runtime database exceptions must fail closed without exposing details.');
$GLOBALS['wpdb']->next_get_var = 1;
$before_backfill_calls = $GLOBALS['wpdb']->get_var_calls;
$sql_backfill = is_callable($sql_callback) ? $sql_callback(
    ['user_id' => 42],
    [],
    ['phase' => 'backfill_enrollment', 'condition_settings' => ['sql' => 'SELECT {user_id}', 'comparison' => 'truthy']]
) : false;
$assert(is_array($sql_backfill) && !empty($sql_backfill['passed']), 'Custom SQL conditions must be evaluated for retrospective candidates.');
$assert($GLOBALS['wpdb']->get_var_calls === $before_backfill_calls + 1, 'A retrospective condition evaluation must execute custom SQL exactly once.');
$sql_fields = is_array($sql_condition) ? ($sql_condition['settings_fields'] ?? []) : [];
$assert(($sql_fields['sql']['type'] ?? '') === 'textarea', 'The custom SQL admin field must remain a multiline textarea.');

CES_Registry::register_event('test_sql_validation', [
    'label' => 'SQL validation event',
    'context_keys' => ['user_id', 'email'],
    'recipient_callback' => static fn(array $context): array => [(string) ($context['email'] ?? '')],
]);

$validator_email = CES_Storage::normalize_email([
    'id' => 'sql_validation_only',
    'name' => 'SQL validation only',
    'enabled' => '1',
    'event_key' => 'test_sql_validation',
    'conditions' => [[
        'key' => 'custom_sql_scalar',
        'operator' => 'is_true',
        'settings' => ['sql' => 'SELECT {user_id}', 'comparison' => 'truthy'],
    ]],
    'delays' => [['value' => 1, 'unit' => 'hours']],
    'subject' => 'Subject',
    'body' => 'Body',
]);
$before_validation_calls = $GLOBALS['wpdb']->get_var_calls;
$validator_result = CES_Email_Validator::validate($validator_email, true);
$assert(!is_wp_error($validator_result), 'A structurally valid custom SQL condition must validate.');
$assert($GLOBALS['wpdb']->get_var_calls === $before_validation_calls, 'Generic email validation must never execute custom SQL.');
$invalid_validator_email = $validator_email;
$invalid_validator_email['conditions'][0]['settings']['sql'] = 'DELETE FROM wp_users';
$before_invalid_validation_calls = $GLOBALS['wpdb']->get_var_calls;
$invalid_validator_result = CES_Email_Validator::validate($invalid_validator_email, true);
$assert(is_wp_error($invalid_validator_result), 'Generic validation must reject structurally unsafe custom SQL.');
$assert($GLOBALS['wpdb']->get_var_calls === $before_invalid_validation_calls, 'Structural custom SQL validation must not execute a database query.');

$missing_expected_email = $validator_email;
$missing_expected_email['conditions'][0]['settings']['comparison'] = 'equals';
$missing_expected_result = CES_Email_Validator::validate($missing_expected_email, true);
$assert(is_wp_error($missing_expected_result), 'Equals comparisons must require a comparison value.');

$nonnumeric_expected_email = $validator_email;
$nonnumeric_expected_email['conditions'][0]['settings']['comparison'] = 'greater_than';
$nonnumeric_expected_email['conditions'][0]['settings']['expected'] = 'not-a-number';
$nonnumeric_expected_result = CES_Email_Validator::validate($nonnumeric_expected_email, true);
$assert(is_wp_error($nonnumeric_expected_result), 'Numeric custom SQL comparisons must reject nonnumeric comparison values.');

$unary_expected_email = $validator_email;
$unary_expected_email['conditions'][0]['settings']['comparison'] = 'is_null';
$unary_expected_email['conditions'][0]['settings']['expected'] = 'ignored';
$unary_expected_result = CES_Email_Validator::validate($unary_expected_email, true);
$assert(!is_wp_error($unary_expected_result), 'Unary custom SQL comparisons must remain valid when stale expected data is present.');
$assert(!isset($unary_expected_result['conditions'][0]['settings']['expected']), 'Unary custom SQL comparisons must discard irrelevant expected values.');

$GLOBALS['wpdb']->next_get_var = 0;
$before_save_test_calls = $GLOBALS['wpdb']->get_var_calls;
$sql_save_test = CES_Custom_SQL_Condition::test_for_save('SELECT {user_id}', 99);
$assert($sql_save_test === true, 'Save-time SQL testing must ignore the scalar result when the query executes.');
$assert($GLOBALS['wpdb']->get_var_calls === $before_save_test_calls + 1, 'An explicit save-time SQL test must execute exactly once.');
$GLOBALS['wpdb']->next_get_var = 1;

$before_runtime_condition_calls = $GLOBALS['wpdb']->get_var_calls;
$runtime_condition_result = CES_Engine::evaluate_conditions($validator_email, ['user_id' => 42], ['phase' => 'scheduled_send']);
$assert(!empty($runtime_condition_result['passed']), 'Scheduled-send condition evaluation must apply custom SQL.');
$assert($GLOBALS['wpdb']->get_var_calls === $before_runtime_condition_calls + 1, 'A scheduled-send condition evaluation must execute custom SQL exactly once.');
$last_query = end($GLOBALS['wpdb']->get_var_queries);
$assert(strpos((string) $last_query, 'SELECT 42') !== false, 'Scheduled-send SQL must resolve the recipient user ID.');

// Conditions must fail closed, including negated and ANY-mode rows.
CES_Registry::register_condition('test_error', [
    'label' => 'Error',
    'callback' => static fn() => new WP_Error('dependency_missing', 'Dependency missing.'),
]);
CES_Registry::register_condition('test_true', [
    'label' => 'True',
    'callback' => static fn() => true,
]);
CES_Registry::register_condition('test_false', [
    'label' => 'False',
    'callback' => static fn() => false,
]);

$assert(!empty(CES_Engine::evaluate_conditions([
    'condition_mode' => 'all',
    'conditions' => [['key' => 'test_error', 'operator' => 'is_false']],
], [])['passed']) === false, 'A WP_Error condition must fail closed under Must be false.');
$assert(!empty(CES_Engine::evaluate_conditions([
    'condition_mode' => 'any',
    'conditions' => [
        ['key' => 'test_true', 'operator' => 'is_true'],
        ['key' => 'test_error', 'operator' => 'is_true'],
    ],
], [])['passed']) === false, 'ANY mode must not hide a later condition error.');
$assert(!empty(CES_Engine::evaluate_conditions([
    'condition_mode' => 'all',
    'conditions' => [['key' => 'test_false', 'operator' => 'is_false']],
], [])['passed']) === true, 'A valid false condition must pass Must be false.');

// The required Action Scheduler version protects argument-aware unique actions.
$GLOBALS['ces_test_action_scheduler_version'] = '3.9.9';
$assert(CES_Engine::action_scheduler_status() === 'version_unsupported', 'Action Scheduler older than 4.0.0 must be rejected.');
$GLOBALS['ces_test_action_scheduler_version'] = '4.0.0';
$GLOBALS['ces_test_action_scheduler_initialized'] = true;
$GLOBALS['ces_test_did_actions'] = ['plugins_loaded' => 1, 'init' => 1, 'action_scheduler_init' => 1];
$assert(CES_Engine::action_scheduler_status() === 'ready', 'Action Scheduler 4.0.0 must be accepted.');

// Events fired before Action Scheduler initialization fail once and are not replayed later.
$GLOBALS['ces_test_action_scheduler_initialized'] = false;
$GLOBALS['ces_test_did_actions']['init'] = 0;
$GLOBALS['ces_test_did_actions']['action_scheduler_init'] = 0;

// Scheduling uses complete action payloads and Action Scheduler uniqueness.
CES_Registry::register_event('test_schedule', [
    'label' => 'Schedule test',
    'context_keys' => ['email', 'custom_value', 'metadata', 'schedule_allowed'],
    'recipient_callback' => static fn(): array => ['recipient@example.com'],
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = [
    'scheduled_email' => CES_Storage::normalize_email([
        'id' => 'scheduled_email',
        'name' => 'Scheduled email',
        'enabled' => '1',
        'event_key' => 'test_schedule',
        'event_settings' => [],
        'condition_mode' => 'all',
        'conditions' => [],
        'delays' => [['value' => 0, 'unit' => 'minutes']],
        'subject' => 'Hello',
        'body' => 'Body',
    ]),
];
$context = [
    'event_instance_id' => 'test_schedule:occurrence-1',
    'email' => 'recipient@example.com',
    'custom_value' => 'preserved',
    'metadata' => ['outer_b' => ['z' => 'last', 'a' => 'first'], 'outer_a' => 'value'],
    'event_time_gmt' => '2026-07-14 12:00:00',
    'event_timestamp' => 1784030400,
];
$early = CES_Engine::fire_event('test_schedule', $context);
$assert(is_wp_error($early) && $early->get_error_code() === 'ces_action_scheduler_not_initialized_yet', 'An early event must fail deterministically when Action Scheduler is not initialized.');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === 0, 'An early event must not be deferred or scheduled later implicitly.');
$GLOBALS['ces_test_action_scheduler_initialized'] = true;
$GLOBALS['ces_test_did_actions']['init'] = 1;
$GLOBALS['ces_test_did_actions']['action_scheduler_init'] = 1;

$first = CES_Engine::fire_event('test_schedule', $context);
$reordered_context = [
    'metadata' => ['outer_a' => 'value', 'outer_b' => ['a' => 'first', 'z' => 'last']],
    'custom_value' => 'preserved',
    'email' => 'recipient@example.com',
    'event_timestamp' => 1784030400,
    'event_time_gmt' => '2026-07-14 12:00:00',
    'event_instance_id' => 'test_schedule:occurrence-1',
];
$second = CES_Engine::fire_event('test_schedule', $reordered_context);
$assert(!is_wp_error($first) && count($first) === 1, 'A valid event must schedule one action.');
$assert(!is_wp_error($second) && count($second) === 0, 'Equivalent context with different associative key order must not schedule a duplicate action.');
$assert($GLOBALS['ces_test_as_get_scheduled_actions_calls'] === 0, 'Scheduling duplicate checks must not scan the full Action Scheduler queue.');
$scheduled = reset($GLOBALS['ces_test_scheduled_actions']);
$payload = is_array($scheduled) && isset($scheduled['args'][0]) && is_array($scheduled['args'][0]) ? $scheduled['args'][0] : [];
$assert(($scheduled['unique'] ?? false) === true, 'CES actions must use Action Scheduler uniqueness.');
$assert(($payload['email_id'] ?? '') === 'scheduled_email', 'The action payload must contain the email ID.');
$assert(($payload['event_instance_id'] ?? '') === 'test_schedule:occurrence-1', 'The action payload must contain the occurrence ID.');
$assert(($payload['context']['custom_value'] ?? '') === 'preserved', 'The action payload must contain sanitized execution context.');
$assert(array_keys($payload['context']['metadata'] ?? []) === ['outer_a', 'outer_b'], 'Canonicalization must sort nested associative context keys.');

// Live events evaluate conditions before queueing and send-time delivery still rechecks them.
CES_Registry::register_condition('test_schedule_gate', [
    'label' => 'Schedule gate',
    'events' => ['test_schedule'],
    'callback' => static fn(array $context): bool => !empty($context['schedule_allowed']),
]);
$gated_email = $GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email'];
$gated_email['conditions'] = [['key' => 'test_schedule_gate', 'operator' => 'is_true', 'settings' => []]];
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email'] = $gated_email;
$before_gated = count($GLOBALS['ces_test_scheduled_actions']);
$gated_context = $context;
$gated_context['event_instance_id'] = 'test_schedule:occurrence-gated';
$gated_context['schedule_allowed'] = false;
$gated_result = CES_Engine::fire_event('test_schedule', $gated_context);
$assert(!is_wp_error($gated_result) && count($gated_result) === 0, 'A live event whose conditions do not pass must not queue actions.');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_gated, 'Failed live-event conditions must create no Action Scheduler entries.');
$gated_context['event_instance_id'] = 'test_schedule:occurrence-allowed';
$gated_context['schedule_allowed'] = true;
$allowed_result = CES_Engine::fire_event('test_schedule', $gated_context);
$assert(!is_wp_error($allowed_result) && count($allowed_result) === 1, 'A live event whose conditions pass must queue its action.');

// Event-setting callback failures fail closed at queue time and never create unrecoverable email actions.
$settings_failure_count = 0;
add_action('ces_email_schedule_eligibility_failed', static function (array $result) use (&$settings_failure_count): void {
    if (!empty($result['failure']) && (($result['failure']['source'] ?? '') === 'event_settings_callback')) {
        $settings_failure_count++;
    }
});
CES_Registry::register_event('test_settings_failure', [
    'label' => 'Settings failure test',
    'context_keys' => ['email'],
    'recipient_callback' => static fn(): array => ['recipient@example.com'],
    'settings_match_callback' => static fn() => new WP_Error('settings_dependency_unavailable', 'Settings dependency unavailable.'),
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['settings_failure_email'] = CES_Storage::normalize_email([
    'id' => 'settings_failure_email',
    'name' => 'Settings failure email',
    'enabled' => '1',
    'event_key' => 'test_settings_failure',
    'event_settings' => [],
    'conditions' => [],
    'delays' => [['value' => 1, 'unit' => 'days']],
    'subject' => 'Settings failure',
    'body' => 'Body',
]);
$before_settings_failure = count($GLOBALS['ces_test_scheduled_actions']);
$settings_failure_result = CES_Engine::fire_event('test_settings_failure', [
    'event_instance_id' => 'test_settings_failure:1',
    'email' => 'recipient@example.com',
]);
$assert(!is_wp_error($settings_failure_result) && count($settings_failure_result) === 0, 'An event-setting callback failure must return no scheduled actions.');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_settings_failure, 'An event-setting callback failure must not create a delayed email action.');
$assert($settings_failure_count === 1, 'An event-setting callback failure must emit the queue-time eligibility diagnostic once.');

// Deliberate skips complete normally and immediate mail failures fail the action.
$skip_count = 0;
add_action('ces_email_skipped', static function () use (&$skip_count): void { $skip_count++; });
CES_Registry::register_condition('test_skip', [
    'label' => 'Skip',
    'events' => ['test_schedule'],
    'callback' => static fn() => false,
]);
$skipped_email = $GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email'];
$skipped_email['conditions'] = [['key' => 'test_skip', 'operator' => 'is_true', 'settings' => []]];
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email'] = $skipped_email;
$mail_before_skip = count($GLOBALS['ces_test_mail']);
CES_Engine::run_scheduled_email($payload);
$assert($skip_count === 1, 'A deliberate condition skip must emit ces_email_skipped.');
$assert(count($GLOBALS['ces_test_mail']) === $mail_before_skip, 'A deliberate skip must not call wp_mail.');

// Operational callback failures must fail the Action Scheduler action rather than look like deliberate skips.
CES_Registry::register_condition('test_runtime_error', [
    'label' => 'Runtime error',
    'events' => ['test_schedule'],
    'callback' => static fn() => new WP_Error('dependency_unavailable', 'Dependency unavailable.'),
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email']['conditions'] = [['key' => 'test_runtime_error', 'operator' => 'is_true', 'settings' => []]];
$runtime_failed = false;
try {
    CES_Engine::run_scheduled_email($payload);
} catch (RuntimeException $exception) {
    $runtime_failed = $exception->getMessage() === 'Dependency unavailable.';
}
$assert($runtime_failed, 'A condition callback error must fail the scheduled action.');
$assert($skip_count === 1, 'An operational callback error must not emit an additional deliberate skip.');

// A manual test recipient override must bypass the event's production recipient callback.
CES_Registry::register_event('test_override_recipient', [
    'label' => 'Override recipient test',
    'recipient_callback' => static fn() => new WP_Error('missing_event_context', 'Production recipient context is unavailable.'),
]);
$override_email = CES_Storage::normalize_email([
    'id' => 'override_email',
    'name' => 'Override email',
    'enabled' => '1',
    'event_key' => 'test_override_recipient',
    'event_settings' => [],
    'conditions' => [],
    'delays' => [['value' => 0, 'unit' => 'minutes']],
    'subject' => 'Override test',
    'body' => 'Body',
]);
$mail_before_override = count($GLOBALS['ces_test_mail']);
$override_result = CES_Email_Dispatcher::dispatch(
    $override_email,
    [],
    ['manual_test' => true],
    ['recipient_override' => 'manual@example.com']
);
$assert(!is_wp_error($override_result), 'A valid manual recipient override must bypass the production recipient callback.');
$assert(count($GLOBALS['ces_test_mail']) === $mail_before_override + 1, 'A manual test with a valid override must call wp_mail once.');
$last_override_mail = $GLOBALS['ces_test_mail'][count($GLOBALS['ces_test_mail']) - 1] ?? [];
$assert(($last_override_mail['to'] ?? '') === 'manual@example.com', 'A manual test must send to the explicit override recipient.');

// Token callback failures must stop delivery rather than send incomplete content.
CES_Registry::register_token('test_broken_token', [
    'label' => 'Broken token',
    'events' => ['test_schedule'],
    'callback' => static fn() => new WP_Error('token_dependency_unavailable', 'Token dependency unavailable.'),
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email']['conditions'] = [];
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email']['subject'] = 'Hello {{test_broken_token}}';
$mail_before_token_failure = count($GLOBALS['ces_test_mail']);
$token_failed = false;
try {
    CES_Engine::run_scheduled_email($payload);
} catch (RuntimeException $exception) {
    $token_failed = $exception->getMessage() === 'Token dependency unavailable.';
}
$assert($token_failed, 'A token callback error must fail the scheduled action.');
$assert(count($GLOBALS['ces_test_mail']) === $mail_before_token_failure, 'A token callback error must prevent wp_mail.');
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email']['subject'] = 'Hello';

$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['scheduled_email']['conditions'] = [];
$GLOBALS['ces_test_mail_results'] = [false];
$failed = false;
try {
    CES_Engine::run_scheduled_email($payload);
} catch (RuntimeException $exception) {
    $failed = true;
}
$assert($failed, 'An immediate wp_mail failure must throw so Action Scheduler marks the action failed.');

// A partial multi-recipient send also fails once and is not requeued by CES.
CES_Registry::register_event('test_partial', [
    'label' => 'Partial test',
    'recipient_callback' => static fn(): array => ['one@example.com', 'two@example.com'],
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['partial_email'] = CES_Storage::normalize_email([
    'id' => 'partial_email',
    'name' => 'Partial email',
    'enabled' => '1',
    'event_key' => 'test_partial',
    'event_settings' => [],
    'conditions' => [],
    'delays' => [['value' => 0, 'unit' => 'minutes']],
    'subject' => 'Partial',
    'body' => 'Body',
]);
$partial_payload = [
    'blog_id' => 1,
    'email_id' => 'partial_email',
    'delay_id' => '0_minutes',
    'event_key' => 'test_partial',
    'event_instance_id' => 'test_partial:1',
    'configured_delay_seconds' => 0,
    'effective_delay_seconds' => 0,
    'delay_test_applied' => '0',
    'context' => ['event_instance_id' => 'test_partial:1'],
];
$actions_before_partial = count($GLOBALS['ces_test_scheduled_actions']);
$GLOBALS['ces_test_mail_results'] = [true, false];
$partial_failed = false;
try {
    CES_Engine::run_scheduled_email($partial_payload);
} catch (RuntimeException $exception) {
    $partial_failed = true;
}
$assert($partial_failed, 'A partial multi-recipient send must fail the Action Scheduler action.');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $actions_before_partial, 'CES must not automatically schedule a retry after partial failure.');

// Retrospective enrollment scans current user state, ignores historical event settings, and schedules from now.
CES_Registry::register_condition('test_current_state_eligible', [
    'label' => 'Current state eligible',
    'events' => ['test_current_state'],
    'callback' => static fn(array $context): bool => in_array('eligible', (array) ($context['user_roles'] ?? []), true),
]);
CES_Registry::register_event('test_current_state', [
    'label' => 'Current-state test',
    'context_keys' => ['user_id', 'email', 'user_roles'],
    'conditions' => ['test_current_state_eligible'],
    'recipient_callback' => static fn(array $context): array => [(string) ($context['email'] ?? '')],
    'settings_fields' => ['required_transition' => ['label' => 'Transition', 'type' => 'text', 'required' => true]],
    'settings_match_callback' => static fn(): bool => false,
    'retrospective' => true,
]);
foreach ([1 => ['one@example.com', ['eligible']], 2 => ['two@example.com', []], 3 => ['three@example.com', ['eligible']]] as $id => $data) {
    $user = new WP_User();
    $user->ID = $id;
    $user->user_email = $data[0];
    $user->user_login = 'user' . $id;
    $user->roles = $data[1];
    $GLOBALS['ces_test_users'][$id] = $user;
}
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['backfill_email'] = CES_Storage::normalize_email([
    'id' => 'backfill_email', 'name' => 'Backfill email', 'enabled' => '1', 'event_key' => 'test_current_state',
    'event_settings' => ['required_transition' => 'historical-only'],
    'condition_mode' => 'all',
    'conditions' => [['key' => 'test_current_state_eligible', 'operator' => 'is_true', 'settings' => []]],
    'delays' => [['value' => 2, 'unit' => 'hours'], ['value' => 4, 'unit' => 'days']],
    'subject' => 'Backfill', 'body' => 'Body',
]);
$preview = CES_Backfill::preview('backfill_email', ['rate_per_minute' => 60]);
$assert(!is_wp_error($preview) && ($preview['candidate_count'] ?? null) === 3, 'Current-state preview must count existing users.');
$assert(($preview['sample_eligible'] ?? null) === 2 && ($preview['sample_excluded'] ?? null) === 1, 'Current-state preview must evaluate only current send rules.');
$run_id = CES_Backfill::start('backfill_email', ['rate_per_minute' => 60]);
$assert(is_string($run_id) && $run_id !== '', 'A current-state retrospective enrollment must start.');
$batch_action = null;
foreach ($GLOBALS['ces_test_scheduled_actions'] as $action) {
    if (($action['hook'] ?? '') === CES_AS_BACKFILL_ACTION && (($action['args'][0]['run_id'] ?? '') === $run_id)) {
        $batch_action = $action;
        break;
    }
}
$assert(is_array($batch_action), 'Starting current-state enrollment must queue a batch action.');
CES_Backfill::run_batch((array) ($batch_action['args'][0] ?? []));
$run = CES_Backfill::latest_run_for_email('backfill_email');
$assert(($run['status'] ?? '') === 'completed', 'The current-state enrollment must complete.');
$assert(($run['evaluated'] ?? 0) === 3 && ($run['eligible'] ?? 0) === 2 && ($run['excluded'] ?? 0) === 1, 'Enrollment counts must reflect current send-rule results.');
$backfill_actions = array_values(array_filter($GLOBALS['ces_test_scheduled_actions'], static fn(array $action): bool => ($action['hook'] ?? '') === CES_AS_ACTION && (($action['args'][0]['email_id'] ?? '') === 'backfill_email')));
$assert(count($backfill_actions) === 4, 'Two eligible users and two delays must create four normal scheduled-email actions.');
$GLOBALS['ces_test_mail'] = [];
CES_Engine::run_scheduled_email((array) ($backfill_actions[0]['args'][0] ?? []));
$assert(count($GLOBALS['ces_test_mail']) === 1, 'A retrospectively scheduled email must use the standard delivery pipeline and recheck current send rules.');

// Every scheduled user email refreshes current user state and enforces multisite membership.
$normal_payload = (array) ($backfill_actions[0]['args'][0] ?? []);
unset($normal_payload['retrospective_current_state']);
$GLOBALS['ces_test_is_multisite'] = true;
$normal_user_id = (int) ($normal_payload['context']['user_id'] ?? 0);
$GLOBALS['ces_test_site_memberships']['1:' . $normal_user_id] = false;
$GLOBALS['ces_test_mail'] = [];
CES_Engine::run_scheduled_email($normal_payload);
$assert(count($GLOBALS['ces_test_mail']) === 0, 'Standard scheduled delivery must suppress a user who no longer belongs to the target site.');
$GLOBALS['ces_test_site_memberships']['1:' . $normal_user_id] = true;
$GLOBALS['ces_test_users'][$normal_user_id]->user_email = 'refreshed@example.test';
CES_Engine::run_scheduled_email($normal_payload);
$assert(count($GLOBALS['ces_test_mail']) === 1 && ($GLOBALS['ces_test_mail'][0]['to'] ?? '') === 'refreshed@example.test', 'Standard scheduled delivery must refresh current user data before resolving recipients.');
$GLOBALS['ces_test_is_multisite'] = false;
unset($GLOBALS['ces_test_site_memberships']['1:' . $normal_user_id]);
$started_at = (int) ($run['started_at'] ?? 0);
foreach ($backfill_actions as $action) {
    $delay_id = (string) ($action['args'][0]['delay_id'] ?? '');
    $email = $GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['backfill_email'];
    $delay = null;
    foreach ($email['delays'] as $candidate_delay) if (($candidate_delay['id'] ?? '') === $delay_id) $delay = $candidate_delay;
    $expected_min = $started_at + CES_Storage::effective_delay_details((array) $delay)['effective_seconds'];
    $assert((int) ($action['timestamp'] ?? 0) >= $expected_min, 'Retrospective delays must start from enrollment time.');
}
$assert(!CES_Backfill::event_supports_backfill(['retrospective' => true, 'context_keys' => ['product_id']]), 'Events without user context must not support current-state enrollment.');

// A later run skips exact actions while they are still pending, even when it starts in a later second.
$first_backfill_payload = (array) ($backfill_actions[0]['args'][0] ?? []);
$assert(array_keys((array) ($first_backfill_payload['context'] ?? [])) === ['blog_id', 'event_instance_id', 'user_id'], 'Retrospective actions must store only stable identity context.');
$assert(!isset($first_backfill_payload['configured_delay_seconds'], $first_backfill_payload['effective_delay_seconds'], $first_backfill_payload['delay_test_applied']), 'Retrospective action identity must exclude changing delay metadata.');
foreach ($GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['backfill_email']['delays'] as &$changed_delay) {
    $changed_delay['value'] = (int) $changed_delay['value'] + 1;
}
unset($changed_delay);
CES_Storage::save_plugin_settings([
    'delay_test_enabled' => '1',
    'delay_test_value' => 5,
    'delay_test_unit' => 'minutes',
]);
sleep(1);
$second_run_id = CES_Backfill::start('backfill_email', ['rate_per_minute' => 60]);
$assert(is_string($second_run_id) && $second_run_id !== '', 'A later retrospective scan may run again.');
$second_batch = null;
foreach ($GLOBALS['ces_test_scheduled_actions'] as $action) {
    if (($action['hook'] ?? '') === CES_AS_BACKFILL_ACTION && (($action['args'][0]['run_id'] ?? '') === $second_run_id)) {
        $second_batch = $action;
    }
}
$assert(is_array($second_batch), 'A later retrospective scan must queue its batch action.');
CES_Backfill::run_batch((array) ($second_batch['args'][0] ?? []));
$second_run = CES_Backfill::latest_run_for_email('backfill_email');
$assert(($second_run['status'] ?? '') === 'completed' && ($second_run['scheduled'] ?? -1) === 0, 'A later scan must skip equivalent actions while they remain pending.');
$assert(empty($second_run['distribution_cursors'] ?? []), 'Pending duplicates must not consume retrospective distribution slots.');
CES_Storage::save_plugin_settings(['delay_test_enabled' => '0']);
foreach (array_keys($GLOBALS['ces_test_scheduled_actions']) as $action_key) {
    $action = $GLOBALS['ces_test_scheduled_actions'][$action_key];
    if (($action['hook'] ?? '') === CES_AS_ACTION && (($action['args'][0]['email_id'] ?? '') === 'backfill_email')) {
        unset($GLOBALS['ces_test_scheduled_actions'][$action_key]);
    }
}
$third_run_id = CES_Backfill::start('backfill_email', ['rate_per_minute' => 60]);
$third_batch = null;
foreach ($GLOBALS['ces_test_scheduled_actions'] as $action) {
    if (($action['hook'] ?? '') === CES_AS_BACKFILL_ACTION && (($action['args'][0]['run_id'] ?? '') === $third_run_id)) {
        $third_batch = $action;
    }
}
$assert(is_array($third_batch), 'A later scan after completed actions are removed must queue its batch action.');
CES_Backfill::run_batch((array) ($third_batch['args'][0] ?? []));
$third_run = CES_Backfill::latest_run_for_email('backfill_email');
$assert(($third_run['status'] ?? '') === 'completed' && ($third_run['scheduled'] ?? 0) === 4, 'A later scan may schedule the same currently eligible users after earlier actions are gone.');

// Failed retrospective runs are terminal, release the active claim, and cannot resume.
$failed_run_id = 'run_failed_terminal';
$GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_PREFIX . $failed_run_id] = [
    'id' => $failed_run_id,
    'email_id' => 'backfill_email',
    'status' => 'failed',
    'cursor' => '',
    'config_hash' => '',
];
$GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_ACTIVE_PREFIX . 'backfill_email'] = $failed_run_id;
$assert(CES_Backfill::active_run_for_email('backfill_email') === null, 'Failed retrospective runs must not remain active.');
$assert(is_wp_error(CES_Backfill::resume($failed_run_id)), 'Failed retrospective runs must not be resumable.');
$replacement_run_id = CES_Backfill::start('backfill_email', ['rate_per_minute' => 60]);
$assert(is_string($replacement_run_id) && $replacement_run_id !== '', 'A new retrospective scan must be able to replace a failed run.');
$assert(CES_Backfill::cancel($replacement_run_id), 'The replacement retrospective scan must remain cancellable.');

$wp_login_event = CES_Registry::event('wp_user_login');
$assert(!$wp_login_event || empty($wp_login_event['retrospective']), 'User-login events must remain live-only.');

// Cleanup remains atomic and clears blocking references first.
$cleanup_email_id = 'cleanup_order_email';
$cleanup_run_id = 'run_cleanup_order';
$GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_PREFIX . $cleanup_run_id] = ['id' => $cleanup_run_id, 'email_id' => $cleanup_email_id, 'status' => 'paused'];
$GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_ACTIVE_PREFIX . $cleanup_email_id] = $cleanup_run_id;
$GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_LATEST_PREFIX . $cleanup_email_id] = $cleanup_run_id;
$GLOBALS['ces_test_deleted_options'] = [];
CES_Backfill::delete_email_state($cleanup_email_id);
$active_delete_position = array_search(CES_OPTION_BACKFILL_ACTIVE_PREFIX . $cleanup_email_id, $GLOBALS['ces_test_deleted_options'], true);
$run_delete_position = array_search(CES_OPTION_BACKFILL_PREFIX . $cleanup_run_id, $GLOBALS['ces_test_deleted_options'], true);
$assert($active_delete_position !== false && $run_delete_position !== false && $active_delete_position < $run_delete_position, 'Cleanup must clear the active claim before deleting its run record.');
$pause_run = ['id' => $run_id];

// Register the built-in definitions used by the bundled JSON fixture.
CES_Registry::register_condition('wp_user_has_email', ['label' => 'Has email', 'callback' => static fn(): bool => true, 'override' => true]);
CES_Registry::register_condition('wp_user_meta_matches', [
    'label' => 'User meta matches', 'callback' => static fn(): bool => true, 'override' => true,
    'settings_fields' => [
        'meta_key' => ['type' => 'text', 'required' => true],
        'comparison' => ['type' => 'select', 'required' => true, 'options' => ['truthy' => 'Truthy', 'falsy' => 'Falsy']],
        'value' => ['type' => 'text'],
    ],
]);
foreach (['wc_customer_has_paid_order', 'wc_cart_still_contains_added_item', 'wc_customer_has_ordered_added_item_since_event'] as $condition_key) {
    CES_Registry::register_condition($condition_key, ['label' => $condition_key, 'callback' => static fn(): bool => true, 'override' => true]);
}
CES_Registry::register_event('wp_user_available_on_site', [
    'label' => 'User available', 'conditions' => ['wp_user_has_email', 'wp_user_meta_matches'], 'retrospective' => true, 'override' => true,
]);
CES_Registry::register_event('wp_user_meta_changed', [
    'label' => 'User meta changed', 'conditions' => ['wp_user_has_email', 'wp_user_meta_matches', 'wc_customer_has_paid_order'], 'retrospective' => true, 'override' => true,
    'settings_fields' => [
        'meta_key' => ['type' => 'text', 'required' => true],
        'trigger' => ['type' => 'select', 'required' => true, 'options' => ['becomes_truthy' => 'Becomes truthy']],
        'value' => ['type' => 'text'],
    ],
]);
CES_Registry::register_event('wc_cart_item_added', [
    'label' => 'Cart item added', 'conditions' => ['wp_user_has_email', 'wc_cart_still_contains_added_item', 'wc_customer_has_ordered_added_item_since_event'], 'override' => true,
]);
foreach (['first_name', 'product_name', 'product_name_trimmed', 'checkout_url'] as $token_key) {
    CES_Registry::register_token($token_key, ['label' => $token_key, 'callback' => static fn(): string => '', 'override' => true]);
}

$assert(CES_WooCommerce_Tokens::trim_product_name('Membership - 1 to 20 employees') === 'Membership', 'Trimmed product names must remove hyphen suffixes.');
$assert(CES_WooCommerce_Tokens::trim_product_name('Membership: 1 to 20 employees') === 'Membership', 'Trimmed product names must remove colon suffixes.');
$assert(CES_WooCommerce_Tokens::trim_product_name('Membership – 1 to 20 employees') === 'Membership', 'Trimmed product names must remove en-dash suffixes.');
$assert(CES_WooCommerce_Tokens::trim_product_name('Membership — 1 to 20 employees') === 'Membership', 'Trimmed product names must remove em-dash suffixes.');
$assert(CES_WooCommerce_Tokens::trim_product_name('Membership') === 'Membership', 'Trimmed product names must preserve names without separators.');

// Bundled defaults are loaded through the strict JSON import schema and use first_name consistently.
$bundled_defaults_result = CES_Default_Emails::definitions();
$assert(!is_wp_error($bundled_defaults_result), 'The bundled JSON defaults must validate.');
$bundled_defaults = [];
foreach ((array) $bundled_defaults_result as $default_email) {
    $bundled_defaults[(string) ($default_email['import_key'] ?? '')] = $default_email;
}
$incomplete_default = $bundled_defaults['default-complete-profile'] ?? [];
$profile_default = $bundled_defaults['default-profile-complete-no-purchase'] ?? [];
$profile_condition_keys = array_map(static fn(array $condition): string => (string) ($condition['key'] ?? ''), (array) ($profile_default['conditions'] ?? []));
$incomplete_meta = array_values(array_filter((array) ($incomplete_default['conditions'] ?? []), static fn(array $condition): bool => ($condition['key'] ?? '') === 'wp_user_meta_matches'))[0] ?? [];
$complete_meta = array_values(array_filter((array) ($profile_default['conditions'] ?? []), static fn(array $condition): bool => ($condition['key'] ?? '') === 'wp_user_meta_matches'))[0] ?? [];
$assert(($profile_default['event_settings']['meta_key'] ?? '') === 'first_name', 'The bundled profile-complete event must watch first_name.');
$assert(($incomplete_meta['settings']['meta_key'] ?? '') === 'first_name' && ($incomplete_meta['settings']['comparison'] ?? '') === 'falsy', 'The incomplete-profile default must check first_name is empty.');
$assert(($complete_meta['settings']['meta_key'] ?? '') === 'first_name' && ($complete_meta['settings']['comparison'] ?? '') === 'truthy', 'The profile-complete default must check first_name is populated.');
$assert(in_array('wc_customer_has_paid_order', $profile_condition_keys, true), 'The bundled profile-complete email must check current purchase state.');
$assert(!in_array('wc_customer_has_paid_order_since_event', $profile_condition_keys, true), 'The bundled profile-complete email must not use synthetic event-relative purchase state.');


// Bundled defaults skip only definitions that require an unavailable optional integration.
$registry_reflection = new ReflectionClass(CES_Registry::class);
$events_property = $registry_reflection->getProperty('events');
$conditions_property = $registry_reflection->getProperty('conditions');
$events_property->setAccessible(true);
$conditions_property->setAccessible(true);
$saved_events = $events_property->getValue();
$saved_conditions = $conditions_property->getValue();
$events_without_wc = $saved_events;
$conditions_without_wc = $saved_conditions;
unset($events_without_wc['wc_cart_item_added']);
foreach (['wc_customer_has_paid_order', 'wc_cart_still_contains_added_item', 'wc_customer_has_ordered_added_item_since_event'] as $condition_key) {
    unset($conditions_without_wc[$condition_key]);
}
$events_property->setValue(null, $events_without_wc);
$conditions_property->setValue(null, $conditions_without_wc);
$generic_defaults_result = CES_Default_Emails::definitions();
$assert(!is_wp_error($generic_defaults_result), 'Generic bundled defaults must still load when WooCommerce definitions are unavailable.');
$generic_default_keys = array_values(array_map(static fn(array $email): string => (string) ($email['import_key'] ?? ''), (array) $generic_defaults_result));
$assert($generic_default_keys === ['default-complete-profile'], 'Only the generic bundled default should load without WooCommerce.');
$assert(CES_Default_Emails::last_skipped_count() === 2, 'Unavailable WooCommerce defaults must be reported as skipped.');
$events_property->setValue(null, $saved_events);
$conditions_property->setValue(null, $saved_conditions);

// Defaults seed only an empty installation; reset replaces bundled import keys and preserves custom emails.
$emails_before_seed_test = $GLOBALS['ces_test_options'][CES_OPTION_EMAILS];
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = [];
CES_Default_Emails::maybe_seed();
$seeded = CES_Storage::get_emails();
$seeded_keys = array_map(static fn(array $email): string => (string) ($email['import_key'] ?? ''), $seeded);
$assert(in_array('default-complete-profile', $seeded_keys, true), 'An empty installation must seed the bundled JSON defaults.');
$seeded_count = count($seeded);
CES_Default_Emails::maybe_seed();
$assert(count(CES_Storage::get_emails()) === $seeded_count, 'Default seeding must not duplicate an existing set.');
$custom = CES_Storage::normalize_email([
    'id' => 'custom_email', 'name' => 'Custom email', 'enabled' => '0', 'event_key' => 'test_schedule',
    'conditions' => [['key' => 'test_true', 'operator' => 'is_true', 'settings' => []]],
    'delays' => [['value' => 1, 'unit' => 'hours']], 'subject' => 'Custom', 'body' => '<p>Custom</p>',
], 'custom_email');
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['custom_email'] = $custom;
foreach ($GLOBALS['ces_test_options'][CES_OPTION_EMAILS] as &$seeded_email) {
    if (($seeded_email['import_key'] ?? '') === 'default-complete-profile') {
        $seeded_email['subject'] = 'Modified default';
    }
}
unset($seeded_email);
$assert(CES_Default_Emails::reset_defaults(), 'Reset defaults must succeed from the bundled JSON file.');
$reset_emails = CES_Storage::get_emails();
$assert(isset($reset_emails['custom_email']), 'Reset defaults must preserve custom emails.');
$reset_complete = array_values(array_filter($reset_emails, static fn(array $email): bool => ($email['import_key'] ?? '') === 'default-complete-profile'))[0] ?? [];
$assert(($reset_complete['subject'] ?? '') === 'Complete your profile', 'Reset defaults must restore the bundled JSON definition.');

// Reset upgrades reserved pre-import default IDs in place instead of duplicating them.
$legacy_defaults = CES_Default_Emails::definitions();
$assert(!is_wp_error($legacy_defaults), 'Bundled defaults must be available for the legacy-ID reset test.');
$legacy_store = ['custom_email' => $custom];
foreach ((array) $legacy_defaults as $legacy_email) {
    $legacy_key = (string) ($legacy_email['import_key'] ?? '');
    $legacy_id = str_replace('-', '_', $legacy_key);
    $legacy_email['id'] = $legacy_id;
    $legacy_email['import_key'] = '';
    $legacy_store[$legacy_id] = $legacy_email;
}
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = $legacy_store;
$assert(CES_Default_Emails::reset_defaults(), 'Reset defaults must replace reserved legacy default IDs.');
$legacy_reset = CES_Storage::get_emails();
$legacy_reset_keys = array_values(array_filter(array_map(static fn(array $email): string => (string) ($email['import_key'] ?? ''), $legacy_reset)));
$assert(count($legacy_reset) === 4, 'Reset must not leave duplicate bundled defaults when reserved legacy IDs exist.');
$assert(count(array_intersect($legacy_reset_keys, ['default-complete-profile', 'default-profile-complete-no-purchase', 'default-abandoned-basket-24h'])) === 3, 'Reserved legacy defaults must receive their bundled import keys.');
$assert(isset($legacy_reset['default_complete_profile']), 'The complete-profile legacy default must be upgraded in place.');
$assert(isset($legacy_reset['default_profile_complete_no_purchase']), 'The profile-complete legacy default must be upgraded in place.');
$assert(isset($legacy_reset['default_abandoned_basket_24h']), 'The basket legacy default must be upgraded in place.');
$assert(isset($legacy_reset['custom_email']), 'Resetting reserved legacy defaults must preserve custom emails.');

// Reset removes unavailable bundled defaults while preserving unrelated custom emails.
$events_property->setValue(null, $events_without_wc);
$conditions_property->setValue(null, $conditions_without_wc);
$assert(CES_Default_Emails::reset_defaults(), 'Reset defaults must succeed when optional integrations become unavailable.');
$reset_without_wc = CES_Storage::get_emails();
$reset_without_wc_keys = array_values(array_map(static fn(array $email): string => (string) ($email['import_key'] ?? ''), $reset_without_wc));
$assert(in_array('default-complete-profile', $reset_without_wc_keys, true), 'The generic bundled default must remain after resetting without WooCommerce.');
$assert(!in_array('default-profile-complete-no-purchase', $reset_without_wc_keys, true), 'Unavailable WooCommerce bundled defaults must be removed during reset.');
$assert(!in_array('default-abandoned-basket-24h', $reset_without_wc_keys, true), 'Unavailable WooCommerce basket defaults must be removed during reset.');
$assert(isset($reset_without_wc['custom_email']), 'Reset without WooCommerce must preserve custom emails.');
$assert(CES_Default_Emails::last_skipped_count() === 2, 'Reset without WooCommerce must report unavailable bundled defaults.');
$events_property->setValue(null, $saved_events);
$conditions_property->setValue(null, $saved_conditions);

$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = $emails_before_seed_test;


// JSON importer validates, previews, creates, updates, and avoids name-based matching.
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = [];
$import_json = json_encode([
    'format' => 'codi-email-scheduler',
    'version' => 1,
    'emails' => [[
        'key' => 'import-profile-reminder',
        'name' => 'Imported profile reminder',
        'enabled' => true,
        'event' => ['key' => 'test_schedule', 'settings' => []],
        'condition_mode' => 'all',
        'conditions' => [['key' => 'test_true', 'operator' => 'is_true', 'settings' => []]],
        'delays' => [['value' => 24, 'unit' => 'hours']],
        'subject' => 'Complete {{site_name}}',
        'body' => '<p>Complete profile</p>',
    ]],
]);
$import_preview = CES_Email_Importer::preview_json($import_json, 99);
$assert(!is_wp_error($import_preview), 'A valid importer document must preview successfully.');
$assert(($import_preview['items'][0]['status'] ?? '') === 'new', 'A previously unseen import key must preview as new.');
$import_result = CES_Email_Importer::apply($import_preview, 'create_update', false);
$assert(!is_wp_error($import_result) && ($import_result['created'] ?? 0) === 1, 'Applying a new preview must create one email.');
$imported_emails = CES_Storage::get_emails();
$imported_email = reset($imported_emails);
$assert(($imported_email['import_key'] ?? '') === 'import-profile-reminder', 'Imported emails must persist their stable import key.');
$assert(($imported_email['enabled'] ?? '') === '0', 'Imported emails must default to disabled when enabled values are not honored.');

$updated_document = json_decode($import_json, true);
$updated_document['emails'][0]['subject'] = 'Updated subject';
$update_preview = CES_Email_Importer::preview_json(json_encode($updated_document), 99);
$assert(($update_preview['items'][0]['status'] ?? '') === 'update', 'A changed definition with the same import key must preview as an update.');
$assert(in_array('Subject', (array) ($update_preview['items'][0]['diff'] ?? []), true), 'Update preview must identify changed fields.');
$update_result = CES_Email_Importer::apply($update_preview, 'create_update', true);
$assert(!is_wp_error($update_result) && ($update_result['updated'] ?? 0) === 1, 'Applying an update preview must update the matched email.');
$updated_emails = CES_Storage::get_emails();
$updated_email = reset($updated_emails);
$assert(($updated_email['subject'] ?? '') === 'Updated subject' && ($updated_email['enabled'] ?? '') === '1', 'Update import must apply content and explicitly honored enabled state.');

$invalid_import = CES_Email_Importer::preview_json('{"format":"wrong","version":1,"emails":[]}', 99);
$assert(is_wp_error($invalid_import), 'Unsupported importer formats must be rejected.');
$duplicate_keys = json_decode($import_json, true);
$duplicate_keys['emails'][] = $duplicate_keys['emails'][0];
$duplicate_preview = CES_Email_Importer::preview_json(json_encode($duplicate_keys), 99);
$assert(($duplicate_preview['counts']['invalid'] ?? 0) === 1, 'Duplicate import keys in one file must be reported as invalid.');

// A stale preview must not overwrite a definition edited after preview.
$stale_preview = CES_Email_Importer::preview_json(json_encode($updated_document), 99);
$stored = CES_Storage::get_emails();
$stored_id = array_key_first($stored);
$stored[$stored_id]['subject'] = 'Manual edit after preview';
CES_Storage::save_emails($stored);
$stale_result = CES_Email_Importer::apply($stale_preview, 'create_update', false);
$after_stale_apply = CES_Storage::get_emails();
$assert(is_wp_error($stale_result) && (($after_stale_apply[$stored_id]['subject'] ?? '') === 'Manual edit after preview'), 'An unchanged preview item changed after preview must be rejected without overwriting the manual edit.');


// Import schema is strict and does not coerce ambiguous JSON values.
$unknown_field_document = json_decode($import_json, true);
$unknown_field_document['emails'][0]['condition'] = [];
$unknown_field_preview = CES_Email_Importer::preview_json(json_encode($unknown_field_document), 99);
$assert(($unknown_field_preview['counts']['invalid'] ?? 0) === 1, 'Unknown email fields must be reported as invalid.');
$fractional_version = json_decode($import_json, true);
$fractional_version['version'] = 1.5;
$assert(is_wp_error(CES_Email_Importer::preview_json(json_encode($fractional_version), 99)), 'A fractional import version must be rejected.');
$string_enabled = json_decode($import_json, true);
$string_enabled['emails'][0]['enabled'] = 'false';
$string_enabled_preview = CES_Email_Importer::preview_json(json_encode($string_enabled), 99);
$assert(($string_enabled_preview['counts']['invalid'] ?? 0) === 1, 'Enabled must be a JSON boolean.');
$negative_delay = json_decode($import_json, true);
$negative_delay['emails'][0]['delays'][0]['value'] = -1;
$negative_delay_preview = CES_Email_Importer::preview_json(json_encode($negative_delay), 99);
$assert(($negative_delay_preview['counts']['invalid'] ?? 0) === 1, 'Negative import delays must be rejected rather than normalised.');

// Honoring enabled state applies even when content is otherwise unchanged.
$current = CES_Storage::get_emails();
$current_id = array_key_first($current);
$current[$current_id]['enabled'] = '0';
CES_Storage::save_emails($current);
$enable_only_document = $updated_document;
$enable_only_document['emails'][0]['enabled'] = true;
$enable_only_preview = CES_Email_Importer::preview_json(json_encode($enable_only_document), 99);
$enable_only_result = CES_Email_Importer::apply($enable_only_preview, 'create_update', true);
$enabled_after_import = CES_Storage::get_emails();
$assert(!is_wp_error($enable_only_result) && (($enabled_after_import[$current_id]['enabled'] ?? '') === '1'), 'An unchanged imported definition must honor an explicitly requested enabled state.');


// An unchanged preview must be stale-checked before applying an enabled-state change.
$stale_enabled_preview = CES_Email_Importer::preview_json(json_encode($enable_only_document), 99);
$stale_enabled_emails = CES_Storage::get_emails();
$stale_enabled_emails[$current_id]['subject'] = 'Manual edit before enabled apply';
CES_Storage::save_emails($stale_enabled_emails);
$stale_enabled_result = CES_Email_Importer::apply($stale_enabled_preview, 'create_update', true);
$stale_enabled_after = CES_Storage::get_emails();
$assert(is_wp_error($stale_enabled_result) && (($stale_enabled_after[$current_id]['subject'] ?? '') === 'Manual edit before enabled apply'), 'An unchanged matched email changed after preview must be rejected before enabled-state application.');

// Omitting enabled means no enabled-state instruction for an existing email.
$omitted_enabled_document = $updated_document;
unset($omitted_enabled_document['emails'][0]['enabled']);
$omitted_current = CES_Storage::get_emails();
$omitted_current[$current_id]['subject'] = 'Updated subject';
$omitted_current[$current_id]['enabled'] = '1';
CES_Storage::save_emails($omitted_current);
$omitted_enabled_preview = CES_Email_Importer::preview_json(json_encode($omitted_enabled_document), 99);
$omitted_enabled_result = CES_Email_Importer::apply($omitted_enabled_preview, 'create_update', true);
$omitted_enabled_after = CES_Storage::get_emails();
$assert(!is_wp_error($omitted_enabled_result) && (($omitted_enabled_after[$current_id]['enabled'] ?? '') === '1'), 'Omitting enabled must preserve the current enabled state even when enabled values are being applied.');

// Apply re-tests custom SQL after preview.
$sql_document = json_decode($import_json, true);
$sql_document['emails'][0]['key'] = 'import-sql-check';
$sql_document['emails'][0]['conditions'] = [[
    'key' => 'custom_sql_scalar',
    'operator' => 'is_true',
    'settings' => ['sql' => 'SELECT 1', 'comparison' => 'truthy'],
]];
$sql_preview = CES_Email_Importer::preview_json(json_encode($sql_document), 99);
$GLOBALS['wpdb']->throw_next_get_var = true;
$sql_apply = CES_Email_Importer::apply($sql_preview, 'create_update', false);
$assert(is_wp_error($sql_apply), 'Custom SQL must be re-tested when an import is applied.');

// Manual saves cannot introduce duplicate import keys.
$existing_for_duplicate = CES_Storage::get_emails();
$first_existing = reset($existing_for_duplicate);
$duplicate_manual = $first_existing;
$duplicate_manual['id'] = '';
$duplicate_manual['name'] = 'Duplicate import key';
$duplicate_manual_result = CES_Storage::save_email($duplicate_manual);
$assert(is_wp_error($duplicate_manual_result), 'Manual saves must reject duplicate import keys.');


// Apply rechecks duplicate import-key integrity in case storage changed after preview.
$integrity_document = $updated_document;
$integrity_preview = CES_Email_Importer::preview_json(json_encode($integrity_document), 99);
$integrity_emails = CES_Storage::get_emails();
$integrity_copy = reset($integrity_emails);
$integrity_copy['id'] = 'forced_duplicate_import_key';
$integrity_copy['name'] = 'Forced duplicate';
$integrity_emails[$integrity_copy['id']] = $integrity_copy;
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS] = $integrity_emails;
$integrity_result = CES_Email_Importer::apply($integrity_preview, 'create_update', false);
$assert(is_wp_error($integrity_result), 'Import apply must reject duplicate existing import keys introduced after preview.');
unset($GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['forced_duplicate_import_key']);

$admin_page_source = file_get_contents(dirname(__DIR__) . '/includes/class-ces-admin-page.php') ?: '';
$assert(strpos($admin_page_source, "\$email['import_key'] = '';") !== false, 'Copying an imported email must clear its stable import key.');

$admin_handler_source = file_get_contents(dirname(__DIR__) . '/includes/class-ces-admin-form-handler.php') ?: '';
$admin_js_source = file_get_contents(dirname(__DIR__) . '/assets/admin.js') ?: '';
$assert(strpos($admin_page_source, 'value="delete"') !== false, 'The scheduled-email list must offer a bulk delete action.');
$assert(strpos($admin_page_source, 'data-delete-confirm') !== false, 'Bulk deletion must require an explicit confirmation message.');
$assert(strpos($admin_handler_source, "['enable', 'disable', 'delete']") !== false, 'The bulk handler must accept the delete action.');
$assert(strpos($admin_handler_source, 'CES_Backfill::delete_email_state($deleted_id);') !== false, 'Bulk deletion must clean retrospective state for each deleted email.');
$assert(strpos($admin_js_source, "action.value !== 'delete'") !== false, 'The admin script must confirm only destructive bulk deletion.');

// Architecture boundary: no CES queue/log tables or removed persistence classes.
$root = dirname(__DIR__);
$php_files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $php_files[] = $file->getPathname();
}
// Email processing modes: immediate evaluates in-hook; shutdown waits until request shutdown.
CES_Registry::register_event('test_processing_modes', [
    'trigger_dedupe_callback' => static fn(array $context): string => (string) ($context['user_id'] ?? 0),
    'triggers' => [[
        'hook' => 'test_processing_modes_hook',
        'accepted_args' => 1,
        'context_callback' => static fn(int $user_id): array => ['user_id' => $user_id],
    ]],
]);
$GLOBALS['ces_test_immediate_condition_ready'] = true;
$GLOBALS['ces_test_shutdown_condition_ready'] = false;
CES_Registry::register_condition('test_immediate_ready', [
    'label' => 'Immediate state ready',
    'events' => ['test_processing_modes'],
    'callback' => static fn(): bool => !empty($GLOBALS['ces_test_immediate_condition_ready']),
]);
CES_Registry::register_condition('test_shutdown_ready', [
    'label' => 'Shutdown state ready',
    'events' => ['test_processing_modes'],
    'callback' => static fn(): bool => !empty($GLOBALS['ces_test_shutdown_condition_ready']),
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['immediate_email'] = CES_Storage::normalize_email([
    'id' => 'immediate_email', 'name' => 'Immediate email', 'enabled' => '1',
    'event_key' => 'test_processing_modes', 'processing_mode' => 'immediate', 'event_settings' => [],
    'condition_mode' => 'all', 'conditions' => [['key' => 'test_immediate_ready', 'operator' => 'is_true', 'settings' => []]],
    'delays' => [['value' => 1, 'unit' => 'days']], 'subject' => 'Immediate', 'body' => 'Body',
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['shutdown_email'] = CES_Storage::normalize_email([
    'id' => 'shutdown_email', 'name' => 'Shutdown email', 'enabled' => '1',
    'event_key' => 'test_processing_modes', 'processing_mode' => 'shutdown', 'event_settings' => [],
    'condition_mode' => 'all', 'conditions' => [['key' => 'test_shutdown_ready', 'operator' => 'is_true', 'settings' => []]],
    'delays' => [['value' => 1, 'unit' => 'days']], 'subject' => 'Shutdown', 'body' => 'Body',
]);
$before_processing_schedule = count($GLOBALS['ces_test_scheduled_actions']);
do_action('test_processing_modes_hook', 303);
do_action('test_processing_modes_hook', 303);
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_processing_schedule + 1, 'Immediate email processing must occur during the originating hook and deduplicate repeated triggers.');
$GLOBALS['ces_test_shutdown_condition_ready'] = true;
do_action('shutdown');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_processing_schedule + 2, 'Shutdown email processing must evaluate later request state and deduplicate repeated triggers.');
$assert(CES_Storage::normalize_email([])['processing_mode'] === 'immediate', 'Email processing mode must default to immediate.');

// Manual/custom events process immediate emails now and shutdown emails later.
$GLOBALS['ces_test_shutdown_condition_ready'] = false;
$before_manual_processing = count($GLOBALS['ces_test_scheduled_actions']);
$manual_result = ces_fire_event('test_processing_modes', ['user_id' => 404]);
$assert(!is_wp_error($manual_result) && count($manual_result) === 1, 'Manual events must schedule matching immediate emails during the call.');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_manual_processing + 1, 'Manual events must not process shutdown emails before shutdown.');
$GLOBALS['ces_test_shutdown_condition_ready'] = true;
do_action('shutdown');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $before_manual_processing + 2, 'Manual events must process shutdown-configured emails at shutdown.');

$source = '';
foreach ($php_files as $file) {
    $source .= file_get_contents($file) ?: '';
}
$assert(!is_file($root . '/includes/class-ces-job-table.php'), 'The removed CES job-table class must not return.');
$assert(!is_file($root . '/includes/class-ces-log-table.php'), 'The removed CES log-table class must not return.');
foreach (['CREATE TABLE', 'dbDelta(', 'CES_Job_Table', 'CES_Log_Table', 'ces_email_jobs', 'ces_email_logs'] as $forbidden) {
    $assert(stripos($source, $forbidden) === false, 'CES PHP source must not contain custom queue/log persistence: ' . $forbidden);
}
$assert(stripos($source, 'flush_deferred_events') === false, 'Deferred event replay must not return.');
$assert(stripos($source, 'send_minimum_iteration_microseconds') === false, 'Recipient-loop pacing must not return.');
$assert(!is_file($root . '/composer.json'), 'Composer metadata must remain absent while CES has no Composer dependencies.');

// Delay identifiers are immutable across timing edits made through the editor path.
$original_delay_rows = CES_Storage::normalize_delays("1 day
4 days");
$edited_delay_rows = CES_Storage::reconcile_delay_ids("2 days
4 days", $original_delay_rows);
$assert(($edited_delay_rows[0]['id'] ?? '') === ($original_delay_rows[0]['id'] ?? ''), 'Editing a delay value must preserve its immutable delay ID.');
$assert(($edited_delay_rows[1]['id'] ?? '') === ($original_delay_rows[1]['id'] ?? ''), 'Unchanged delay rows must preserve their immutable delay IDs.');
$assert(($edited_delay_rows[0]['value'] ?? 0) === 2, 'Reconciled delays must retain the edited timing value.');
$added_delay_rows = CES_Storage::reconcile_delay_ids("2 days
4 days
1 week", $edited_delay_rows);
$assert(strpos((string) ($added_delay_rows[2]['id'] ?? ''), 'delay_') === 0, 'New delay rows must receive generated immutable IDs.');
$assert(!in_array($added_delay_rows[2]['id'] ?? '', array_column($edited_delay_rows, 'id'), true), 'New delay IDs must not reuse existing row identities.');
$reordered_delay_rows = CES_Storage::reconcile_delay_ids("4 days
2 days
1 week", $added_delay_rows);
$assert(($reordered_delay_rows[0]['id'] ?? '') === ($added_delay_rows[1]['id'] ?? ''), 'Reordering an unchanged delay must preserve its identity by timing match.');
$assert(($reordered_delay_rows[1]['id'] ?? '') === ($added_delay_rows[0]['id'] ?? ''), 'Reordering edited delay rows must preserve their identities.');

// Configurable custom WordPress hooks require an explicit valid user ID argument.
$custom_user = new WP_User();
$custom_user->ID = 515;
$custom_user->user_email = 'custom-hook@example.com';
$custom_user->user_login = 'custom-hook-user';
$GLOBALS['ces_test_users'][515] = $custom_user;
$custom_event_settings = [
    'hook_name' => 'my_plugin_user_ready',
    'accepted_args' => '2',
    'user_id_argument' => '2',
];
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['custom_hook_email'] = CES_Storage::normalize_email([
    'id' => 'custom_hook_email',
    'name' => 'Custom hook email',
    'enabled' => true,
    'event_key' => 'custom_wp_hook',
    'event_settings' => $custom_event_settings,
    'processing_mode' => 'immediate',
    'conditions' => [],
    'delays' => [['value' => 1, 'unit' => 'minutes']],
    'subject' => 'Custom hook',
    'body' => 'Hello {{user_login}}',
]);
$GLOBALS['ces_test_options'][CES_OPTION_EMAILS]['custom_hook_shutdown_email'] = CES_Storage::normalize_email([
    'id' => 'custom_hook_shutdown_email',
    'name' => 'Custom hook shutdown email',
    'enabled' => true,
    'event_key' => 'custom_wp_hook',
    'event_settings' => $custom_event_settings,
    'processing_mode' => 'shutdown',
    'conditions' => [],
    'delays' => [['value' => 2, 'unit' => 'minutes']],
    'subject' => 'Custom hook shutdown',
    'body' => 'Hello {{user_login}}',
]);
CES_Custom_Hook_Events::init();
$custom_before = count($GLOBALS['ces_test_scheduled_actions']);
do_action('my_plugin_user_ready', 'payload', 515);
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $custom_before + 1, 'A configured custom hook must schedule its immediate email when the selected argument contains a valid integer user ID.');
$custom_action = end($GLOBALS['ces_test_scheduled_actions']);
$custom_context = $custom_action['args'][0]['context'] ?? [];
$assert(($custom_context['user_id'] ?? 0) === 515, 'The custom hook must map the selected one-based argument position to user_id.');
$assert(($custom_context['hook_arg_1'] ?? '') === 'payload', 'Custom hooks must expose scalar hook arguments as numbered context values.');
do_action('shutdown');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $custom_before + 2, 'A configured custom hook must schedule its shutdown email during the shutdown action.');

$digit_string_before = count($GLOBALS['ces_test_scheduled_actions']);
do_action('my_plugin_user_ready', 'payload-string', '515');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $digit_string_before + 1, 'A custom hook must accept a digit-only string user ID.');
do_action('shutdown');
$assert(count($GLOBALS['ces_test_scheduled_actions']) === $digit_string_before + 2, 'A digit-only string user ID must also support shutdown processing.');

foreach (['515abc', -515, 515.9, '515.0', '', '0', 0, 999999] as $invalid_user_id) {
    $invalid_custom_before = count($GLOBALS['ces_test_scheduled_actions']);
    do_action('my_plugin_user_ready', 'payload', $invalid_user_id);
    do_action('shutdown');
    $assert(count($GLOBALS['ces_test_scheduled_actions']) === $invalid_custom_before, 'A custom hook must reject malformed, non-positive, non-integral, or nonexistent user IDs.');
}

$invalid_position = CES_Email_Validator::validate([
    'id' => 'invalid_custom_hook',
    'enabled' => true,
    'event_key' => 'custom_wp_hook',
    'event_settings' => ['hook_name' => 'my_hook', 'accepted_args' => '1', 'user_id_argument' => '2'],
    'delays' => [['value' => 1, 'unit' => 'days']],
], true);
$assert(is_wp_error($invalid_position), 'Custom hook validation must reject a user ID argument position outside the accepted argument count.');
$disabled_custom_draft = CES_Email_Validator::validate([
    'id' => 'disabled_custom_hook_draft',
    'enabled' => false,
    'event_key' => 'custom_wp_hook',
    'event_settings' => [],
], false);
$assert(!is_wp_error($disabled_custom_draft), 'A disabled custom-hook email must be saveable as an incomplete draft.');
$custom_callbacks = $GLOBALS['ces_test_actions']['my_plugin_user_ready'] ?? [];
$assert(count($custom_callbacks) === 1, 'Emails sharing the same custom-hook settings must share one WordPress callback binding.');

// Uninstall initializes Action Scheduler when needed, then cancels every pending CES action.
$GLOBALS['ces_test_options']['ces_backfill_active_orphaned_email'] = 'run_orphaned_uninstall';
$GLOBALS['ces_test_options']['ces_backfill_latest_orphaned_email'] = 'run_orphaned_uninstall';
$GLOBALS['ces_test_options']['ces_backfill_run_run_orphaned_uninstall'] = ['id' => 'run_orphaned_uninstall', 'status' => 'failed'];
$GLOBALS['ces_test_action_scheduler_initialized'] = false;
$GLOBALS['ces_test_action_scheduler_init_calls'] = 0;
define('WP_UNINSTALL_PLUGIN', true);
require $root . '/uninstall.php';
$assert($GLOBALS['ces_test_action_scheduler_init_calls'] === 1, 'Uninstall must initialize Action Scheduler before cancellation when needed.');
$unschedule_call = $GLOBALS['ces_test_unschedule_all_calls'][0] ?? [];
$assert(($unschedule_call['hook'] ?? null) === CES_AS_ACTION, 'Uninstall must cancel the CES scheduled-email hook.');
$assert(($unschedule_call['args'] ?? null) === [], 'Uninstall must cancel the CES group without an argument filter.');
$assert(($unschedule_call['group'] ?? null) === CES_AS_GROUP, 'Uninstall must restrict cancellation to the CES Action Scheduler group.');
$backfill_unschedule_call = $GLOBALS['ces_test_unschedule_all_calls'][1] ?? [];
$assert(($backfill_unschedule_call['hook'] ?? null) === CES_AS_BACKFILL_ACTION, 'Uninstall must cancel the retrospective batch hook.');
$assert(!isset($GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_LATEST_PREFIX . 'backfill_email']), 'Uninstall must delete the latest retrospective run reference.');
$assert(!isset($GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_PREFIX . ($pause_run['id'] ?? '')]), 'Uninstall must delete the latest referenced retrospective run option.');
$assert(!isset($GLOBALS['ces_test_options'][CES_OPTION_BACKFILL_ACTIVE_PREFIX . 'backfill_email']), 'Uninstall must delete retrospective active-email claims.');
$assert(!isset($GLOBALS['ces_test_options']['ces_backfill_active_orphaned_email']), 'Uninstall must delete orphaned retrospective active claims by prefix.');
$assert(!isset($GLOBALS['ces_test_options']['ces_backfill_latest_orphaned_email']), 'Uninstall must delete orphaned retrospective latest references by prefix.');
$assert(!isset($GLOBALS['ces_test_options']['ces_backfill_run_run_orphaned_uninstall']), 'Uninstall must delete orphaned retrospective run options by prefix.');

if ($failures) {
    fwrite(STDERR, "Regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "All focused regression tests passed.\n";
