<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

use CodiMcp\Core\SourceAttribution;
use CodiMcp\Core\Security\SensitiveDataRedactor;

final class DiagnosticRuntime
{
    private const MAX_ERRORS = 100;
    private const MAX_STATE = 200;
    private const MAX_HTTP = 100;
    private const MAX_REDIRECTS = 50;
    private const MAX_MAIL = 50;
    private const MAX_CAPABILITIES = 100;
    private const MAX_TIMELINE = 500;
    private const MAX_ASSETS = 150;
    private const MAX_BLOCK_TYPES = 100;
    private const MAX_EXPLAINS = 3;
    private const MIN_EXPLAIN_MS = 5.0;

    private DiagnosticStore $store;
    private SourceAttribution $attribution;
    private QuerySanitizer $querySanitizer;
    private SensitiveDataRedactor $redactor;
    private bool $active = false;
    private bool $finished = false;
    private array $authorization = array();
    private float $startedAt = 0.0;
    private float $startedUnix = 0.0;
    private int $queryStartIndex = 0;
    private int $queryCountAtStart = 0;
    private bool $queryCaptureAvailable = false;
    private mixed $previousErrorHandler = null;
    private array $phpErrors = array();
    private array $wpErrors = array();
    private array $doingItWrong = array();
    private array $wpDie = array();
    private array $stateChanges = array();
    private array $httpRequests = array();
    private array $httpPending = array();
    private int $httpCounter = 0;
    private array $redirects = array();
    private array $mail = array();
    private array $capabilityChecks = array();
    private array $securityEvents = array();
    private array $hookTimings = array();
    private array $hookTimingStacks = array();
    private array $timeline = array();
    private array $screen = array();
    private array $rest = array();
    private array $template = array();
    private array $blocks = array();
    private array $blogSwitches = array();
    private array $cacheBaseline = array();
    private mixed $diagnosticSessionManager = null;
    private string $diagnosticSessionToken = '';
    private array $diagnosticCookieNames = array();
    private bool $suppressDiagnosticState = false;

    public function __construct(
        ?DiagnosticStore $store = null,
        ?SourceAttribution $attribution = null,
        ?QuerySanitizer $querySanitizer = null,
        ?SensitiveDataRedactor $redactor = null
    ) {
        $this->store = $store ?? new DiagnosticStore();
        $this->attribution = $attribution ?? new SourceAttribution();
        $this->querySanitizer = $querySanitizer ?? new QuerySanitizer();
        $this->redactor = $redactor ?? new SensitiveDataRedactor();
    }

    public function register(): void
    {
        if ($this->active || !function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }

        $token = trim((string) ($_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] ?? ''));
        if ($token === '') {
            return;
        }

        $authorization = $this->store->consume(
            $token,
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );
        if ($authorization instanceof \WP_Error) {
            return;
        }

        $this->active = true;
        $this->authorization = $authorization;
        if ($this->usesFixedIdentity()) {
            $this->establishAuthorizedUser();
            $this->establishDiagnosticAuthContext();
        }
        $this->startedAt = microtime(true);
        $this->startedUnix = $this->startedAt;
        $this->cacheBaseline = $this->cacheSnapshot();

        global $wpdb;
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        $this->queryCaptureAvailable = defined('SAVEQUERIES') && SAVEQUERIES;
        $this->queryStartIndex = is_object($wpdb) && is_array($wpdb->queries ?? null) ? count($wpdb->queries) : 0;
        $this->queryCountAtStart = is_object($wpdb) ? max(0, (int) ($wpdb->num_queries ?? 0)) : 0;

        if ($this->usesFixedIdentity()) {
            add_filter('determine_current_user', array($this, 'determineCurrentUser'), -1000000, 1);
            add_action('plugins_loaded', array($this, 'establishAuthorizedUser'), -1000000, 0);
            add_action('plugins_loaded', array($this, 'establishDiagnosticAuthContext'), -999999, 0);
        }
        add_filter('log_query_custom_data', array($this, 'queryCustomData'), 10, 5);
        add_filter('http_request_args', array($this, 'httpRequestArgs'), 10, 2);
        add_filter('pre_http_request', array($this, 'preHttpRequest'), 9999, 3);
        add_action('http_api_debug', array($this, 'httpDebug'), 10, 5);
        add_filter('wp_redirect', array($this, 'redirect'), 9999, 2);
        add_filter('template_include', array($this, 'templateInclude'), 9999, 1);
        add_filter('rest_request_before_callbacks', array($this, 'restBeforeCallbacks'), 9999, 3);
        add_filter('render_block_data', array($this, 'renderBlockData'), 9999, 3);
        add_action('current_screen', array($this, 'currentScreen'), 9999, 1);
        add_action('wp_error_added', array($this, 'wpErrorAdded'), 10, 4);
        add_action('doing_it_wrong_run', array($this, 'doingItWrong'), 10, 3);
        foreach (array('wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler') as $dieHandlerFilter) {
            add_filter($dieHandlerFilter, array($this, 'wrapDieHandler'), PHP_INT_MAX, 1);
        }
        add_action('wp_verify_nonce_failed', array($this, 'nonceFailed'), 10, 4);
        add_action('check_admin_referer', array($this, 'adminRefererChecked'), 10, 2);
        add_action('check_ajax_referer', array($this, 'ajaxRefererChecked'), 10, 2);
        add_filter('pre_wp_mail', array($this, 'preWpMail'), PHP_INT_MAX, 2);
        add_action('wp_mail_succeeded', array($this, 'mailSucceeded'), 10, 1);
        add_action('wp_mail_failed', array($this, 'mailFailed'), 10, 1);
        add_action('switch_blog', array($this, 'switchBlog'), 10, 3);

        $this->registerStateHooks();
        $this->registerCapabilityHook();
        $this->registerTargetHookTiming();

        $this->previousErrorHandler = set_error_handler(array($this, 'phpError'));
        register_shutdown_function(array($this, 'finish'));
    }

    private function usesFixedIdentity(): bool
    {
        return (string) ($this->authorization['identity_mode'] ?? 'fixed') !== 'request';
    }

    public function establishAuthorizedUser(): void
    {
        $userId = max(0, (int) ($this->authorization['user_id'] ?? 0));
        if (!function_exists('wp_set_current_user')) {
            return;
        }
        $previousSuppression = $this->suppressDiagnosticState;
        $this->suppressDiagnosticState = true;
        try {
            wp_set_current_user($userId);
        } finally {
            $this->suppressDiagnosticState = $previousSuppression;
        }
    }

    public function establishDiagnosticAuthContext(): void
    {
        if ($this->diagnosticSessionToken !== '') {
            return;
        }

        $userId = max(0, (int) ($this->authorization['user_id'] ?? 0));
        if ($userId < 1 || !class_exists('WP_Session_Tokens') || !function_exists('wp_generate_auth_cookie')) {
            return;
        }

        try {
            $manager = \WP_Session_Tokens::get_instance($userId);
            if (!is_object($manager) || !method_exists($manager, 'create') || !method_exists($manager, 'destroy')) {
                return;
            }

            $expiration = time() + 120;
            $previousSuppression = $this->suppressDiagnosticState;
            $this->suppressDiagnosticState = true;
            try {
                $token = (string) $manager->create($expiration);
                if ($token === '') {
                    return;
                }

                $cookieNames = array();
            foreach (array(
                'auth' => defined('AUTH_COOKIE') ? (string) AUTH_COOKIE : '',
                'secure_auth' => defined('SECURE_AUTH_COOKIE') ? (string) SECURE_AUTH_COOKIE : '',
                'logged_in' => defined('LOGGED_IN_COOKIE') ? (string) LOGGED_IN_COOKIE : '',
            ) as $scheme => $name) {
                if ($name === '') {
                    continue;
                }
                $cookie = (string) wp_generate_auth_cookie($userId, $expiration, $scheme, $token);
                if ($cookie === '') {
                    continue;
                }
                $_COOKIE[$name] = $cookie;
                $cookieNames[] = $name;
            }

                if ($cookieNames === array()) {
                    $manager->destroy($token);
                    return;
                }

                $this->diagnosticSessionManager = $manager;
                $this->diagnosticSessionToken = $token;
                $this->diagnosticCookieNames = $cookieNames;
                $this->establishAuthorizedUser();
            } finally {
                $this->suppressDiagnosticState = $previousSuppression;
            }
        } catch (\Throwable) {
        }
    }

    public function determineCurrentUser($userId)
    {
        return max(0, (int) ($this->authorization['user_id'] ?? 0));
    }

    public function queryCustomData(array $data, string $query, float $queryTime, string $queryCallstack, float $queryStart): array
    {
        $data['codi_mcp_diagnostic'] = array(
            'source' => $this->attribution->fromBacktrace(),
            'internal' => $this->suppressDiagnosticState,
        );
        return $data;
    }

    public function httpRequestArgs(array $args, string $url): array
    {
        $id = 'http-' . (++$this->httpCounter);
        $args['_codi_mcp_diagnostic_id'] = $id;
        $this->httpPending[$id] = array(
            'started_at' => microtime(true),
            'method' => strtoupper((string) ($args['method'] ?? 'GET')),
            'url' => $this->safeUrl($url),
            'source' => $this->attribution->fromBacktrace(),
        );
        return $args;
    }

    public function preHttpRequest($pre, array $args, string $url)
    {
        if ($pre !== false) {
            $this->completeHttpRequest($pre, $args, $url, true);
        }
        return $pre;
    }

    public function httpDebug($response, string $context, string $class, array $args, string $url): void
    {
        if ($context === 'response') {
            $this->completeHttpRequest($response, $args, $url, false);
        }
    }

    public function redirect(string $location, int $status): string
    {
        if (count($this->redirects) < self::MAX_REDIRECTS) {
            $row = array(
                'status' => $status,
                'location' => $this->safeUrl($location),
                'source' => $this->attribution->fromBacktrace(),
                'offset_ms' => $this->offsetMs(),
            );
            $this->redirects[] = $row;
            $this->timeline('redirect', $status . ' ' . $row['location'], $row['source']);
        }
        return $location;
    }

    public function templateInclude(string $template): string
    {
        $this->template = $this->attribution->describeFile($template);
        return $template;
    }

    public function restBeforeCallbacks($response, array $handler, $request)
    {
        $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        $method = is_object($request) && method_exists($request, 'get_method') ? (string) $request->get_method() : '';
        $callback = $handler['callback'] ?? null;
        $this->rest = array(
            'route' => substr($route, 0, 500),
            'method' => substr($method, 0, 20),
            'callback_source' => $callback !== null ? $this->attribution->fromCallable($callback) : $this->emptySource(),
        );
        return $response;
    }

    public function renderBlockData(array $parsedBlock, array $sourceBlock = array(), $parentBlock = null): array
    {
        $name = trim((string) ($parsedBlock['blockName'] ?? ''));
        if ($name !== '' && (isset($this->blocks[$name]) || count($this->blocks) < self::MAX_BLOCK_TYPES)) {
            $this->blocks[$name] = (int) ($this->blocks[$name] ?? 0) + 1;
        }
        return $parsedBlock;
    }

    public function currentScreen($screen): void
    {
        if (!is_object($screen)) {
            return;
        }
        $this->screen = array(
            'id' => substr((string) ($screen->id ?? ''), 0, 191),
            'base' => substr((string) ($screen->base ?? ''), 0, 191),
            'post_type' => substr((string) ($screen->post_type ?? ''), 0, 191),
            'taxonomy' => substr((string) ($screen->taxonomy ?? ''), 0, 191),
            'parent_base' => substr((string) ($screen->parent_base ?? ''), 0, 191),
        );
    }

    public function wpErrorAdded($code, string $message, $data, $wpError): void
    {
        if (count($this->wpErrors) >= self::MAX_ERRORS) {
            return;
        }
        $row = array(
            'code' => substr((string) $code, 0, 191),
            'message' => $this->safeMessage($message),
            'source' => $this->attribution->fromBacktrace(),
            'offset_ms' => $this->offsetMs(),
        );
        $this->wpErrors[] = $row;
        $this->timeline('wp_error', $row['code'] . ': ' . $row['message'], $row['source']);
    }

    public function doingItWrong(string $functionName, string $message, string $version): void
    {
        if (count($this->doingItWrong) >= self::MAX_ERRORS) {
            return;
        }
        $row = array(
            'function' => substr($functionName, 0, 191),
            'message' => $this->safeMessage($message),
            'version' => substr($version, 0, 50),
            'source' => $this->attribution->fromBacktrace(),
            'offset_ms' => $this->offsetMs(),
        );
        $this->doingItWrong[] = $row;
        $this->timeline('doing_it_wrong', $row['function'], $row['source']);
    }

    public function phpError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        $this->recordPhpError($severity, $message, $file, $line);

        if (is_callable($this->previousErrorHandler)) {
            return (bool) call_user_func($this->previousErrorHandler, $severity, $message, $file, $line);
        }
        return false;
    }

    public function wrapDieHandler($handler)
    {
        if (!is_callable($handler)) {
            return $handler;
        }
        return function ($message = '', $title = '', $args = array()) use ($handler) {
            if (count($this->wpDie) < self::MAX_ERRORS) {
                $source = $this->attribution->fromBacktrace();
                $response = is_array($args) ? (int) ($args['response'] ?? 0) : (is_int($args) ? $args : 0);
                if ($message instanceof \WP_Error) {
                    $code = (string) $message->get_error_code();
                    $text = (string) $message->get_error_message();
                } else {
                    $code = '';
                    $text = is_scalar($message) ? (string) $message : '';
                }
                $row = array(
                    'code' => substr($code, 0, 191),
                    'message' => $this->safeMessage($text),
                    'title' => is_scalar($title) ? $this->safeMessage((string) $title) : '',
                    'response' => $response,
                    'source' => $source,
                    'offset_ms' => $this->offsetMs(),
                );
                $this->wpDie[] = $row;
                $this->timeline('wp_die', ($row['code'] !== '' ? $row['code'] . ': ' : '') . $row['message'], $source);
            }
            return call_user_func($handler, $message, $title, $args);
        };
    }

    public function nonceFailed(string $nonce, $action, $user, string $token): void
    {
        $this->recordSecurityFailure('nonce', (string) $action, is_object($user) ? (int) ($user->ID ?? 0) : 0);
    }

    public function adminRefererChecked($action, $result): void
    {
        if ($result === false) {
            $this->recordSecurityFailure('admin_referer', (string) $action, $this->currentUserId());
        }
    }

    public function ajaxRefererChecked($action, $result): void
    {
        if ($result === false) {
            $this->recordSecurityFailure('ajax_referer', (string) $action, $this->currentUserId());
        }
    }

    public function preWpMail($return, array $atts)
    {
        if ($return !== null) {
            $this->recordMail('preempted', $atts, is_bool($return) && !$return ? 'pre_wp_mail returned false' : '');
        }
        return $return;
    }

    public function mailSucceeded(array $mailData): void
    {
        $this->recordMail('succeeded', $mailData, '');
    }

    public function mailFailed($error): void
    {
        $code = $error instanceof \WP_Error ? (string) $error->get_error_code() : 'mail_failed';
        $message = $error instanceof \WP_Error ? (string) $error->get_error_message() : '';
        $this->recordMail('failed', array(), $code . ($message !== '' ? ': ' . $message : ''));
    }

    public function switchBlog(int $newBlogId, int $previousBlogId, string $context = 'switch'): void
    {
        if (count($this->blogSwitches) >= 50) {
            return;
        }
        $this->blogSwitches[] = array(
            'from' => $previousBlogId,
            'to' => $newBlogId,
            'context' => substr($context, 0, 50),
            'offset_ms' => $this->offsetMs(),
            'source' => $this->attribution->fromBacktrace(),
        );
    }

    public function optionAdded(string $option, $value): void
    {
        $this->recordOption('add', 'option', $option, $value);
    }

    public function optionUpdated(string $option, $oldValue, $value): void
    {
        $this->recordOption('update', 'option', $option, $value);
    }

    public function optionDeleted(string $option): void
    {
        $this->recordOption('delete', 'option', $option, null);
    }

    public function siteOptionAdded(string $option, $value, int $networkId = 0): void
    {
        $this->recordNetworkOption('add', $option, $networkId, $value);
    }

    public function siteOptionUpdated(string $option, $value, $oldValue = null, int $networkId = 0): void
    {
        $this->recordNetworkOption('update', $option, $networkId, $value);
    }

    public function siteOptionDeleted(string $option, int $networkId = 0): void
    {
        $this->recordNetworkOption('delete', $option, $networkId, null);
    }

    public function metaAdded(int $metaId, int $objectId, string $metaKey, $value): void
    {
        $type = $this->metaTypeFromHook();
        $this->recordState('add', $type . '-meta', $metaKey, $objectId, $value);
    }

    public function metaUpdated(int $metaId, int $objectId, string $metaKey, $value): void
    {
        $type = $this->metaTypeFromHook();
        $this->recordState('update', $type . '-meta', $metaKey, $objectId, $value);
    }

    public function metaDeleted(array $metaIds, int $objectId, string $metaKey, $value): void
    {
        $type = $this->metaTypeFromHook();
        $this->recordState('delete', $type . '-meta', $metaKey, $objectId, null);
    }

    public function postSaved(int $postId, $post, bool $update): void
    {
        $name = is_object($post) ? (string) ($post->post_type ?? 'post') : 'post';
        $this->recordState($update ? 'update' : 'add', 'post', $name, $postId, null);
    }

    public function postDeleted(int $postId): void
    {
        $this->recordState('delete', 'post', '', $postId, null);
    }

    public function termCreated(int $termId, int $ttId, string $taxonomy): void
    {
        $this->recordState('add', 'term', $taxonomy, $termId, null);
    }

    public function termEdited(int $termId, int $ttId, string $taxonomy): void
    {
        $this->recordState('update', 'term', $taxonomy, $termId, null);
    }

    public function termDeleted(int $termId, int $ttId, string $taxonomy): void
    {
        $this->recordState('delete', 'term', $taxonomy, $termId, null);
    }

    public function transientSet(string $transient, $value, int $expiration): void
    {
        $this->recordState('set', 'transient', $transient, 0, $value);
    }

    public function transientDeleted(string $transient): void
    {
        $this->recordState('delete', 'transient', $transient, 0, null);
    }

    public function siteTransientSet(string $transient, $value, int $expiration): void
    {
        $this->recordState('set', 'site-transient', $transient, 0, $value);
    }

    public function siteTransientDeleted(string $transient): void
    {
        $this->recordState('delete', 'site-transient', $transient, 0, null);
    }

    public function mapMetaCap(array $caps, string $capability, int $userId, array $args): array
    {
        $targets = array_values(array_map('strval', (array) ($this->authorization['config']['capabilities'] ?? array())));
        if ($capability === '' || !in_array($capability, $targets, true) || count($this->capabilityChecks) >= self::MAX_CAPABILITIES) {
            return $caps;
        }

        $isSuperAdmin = function_exists('is_multisite') && is_multisite()
            && function_exists('is_super_admin') && is_super_admin($userId);
        $this->capabilityChecks[] = array(
            'stage' => 'map_meta_cap',
            'capability' => substr($capability, 0, 191),
            'required_caps' => array_values(array_map('strval', array_slice($caps, 0, 20))),
            'granted' => $isSuperAdmin ? !in_array('do_not_allow', $caps, true) : null,
            'user_id' => max(0, $userId),
            'super_admin' => $isSuperAdmin,
            'source' => $this->attribution->fromBacktrace(),
            'offset_ms' => $this->offsetMs(),
        );
        return $caps;
    }

    public function userHasCap(array $allcaps, array $caps, array $args, $user): array
    {
        $requested = (string) ($args[0] ?? '');
        $targets = array_values(array_map('strval', (array) ($this->authorization['config']['capabilities'] ?? array())));
        if ($requested === '' || !in_array($requested, $targets, true) || count($this->capabilityChecks) >= self::MAX_CAPABILITIES) {
            return $allcaps;
        }

        $granted = true;
        foreach ($caps as $cap) {
            if (empty($allcaps[(string) $cap])) {
                $granted = false;
                break;
            }
        }
        $this->capabilityChecks[] = array(
            'stage' => 'user_has_cap',
            'capability' => substr($requested, 0, 191),
            'required_caps' => array_values(array_map('strval', array_slice($caps, 0, 20))),
            'granted' => $granted,
            'user_id' => is_object($user) ? (int) ($user->ID ?? 0) : 0,
            'source' => $this->attribution->fromBacktrace(),
            'offset_ms' => $this->offsetMs(),
        );
        return $allcaps;
    }

    public function targetHookStart($value = null)
    {
        $hook = function_exists('current_filter') ? (string) (current_filter() ?? '') : '';
        if ($hook === '') {
            return $value;
        }
        global $wpdb;
        $this->hookTimingStacks[$hook][] = array(
            'started_at' => microtime(true),
            'queries' => is_object($wpdb) ? max(0, (int) ($wpdb->num_queries ?? 0)) : 0,
            'http' => count($this->httpRequests),
        );
        return $value;
    }

    public function targetHookEnd($value = null)
    {
        $hook = function_exists('current_filter') ? (string) (current_filter() ?? '') : '';
        $stack = (array) ($this->hookTimingStacks[$hook] ?? array());
        if ($hook === '' || $stack === array()) {
            return $value;
        }
        $start = array_pop($stack);
        $this->hookTimingStacks[$hook] = $stack;

        global $wpdb;
        $durationMs = max(0.0, microtime(true) - (float) ($start['started_at'] ?? microtime(true))) * 1000;
        $queries = max(0, (is_object($wpdb) ? (int) ($wpdb->num_queries ?? 0) : 0) - (int) ($start['queries'] ?? 0));
        $http = max(0, count($this->httpRequests) - (int) ($start['http'] ?? 0));
        if (!isset($this->hookTimings[$hook])) {
            $this->hookTimings[$hook] = array('executions' => 0, 'time_ms' => 0.0, 'max_ms' => 0.0, 'queries' => 0, 'http_requests' => 0);
        }
        $this->hookTimings[$hook]['executions']++;
        $this->hookTimings[$hook]['time_ms'] += $durationMs;
        $this->hookTimings[$hook]['max_ms'] = max((float) $this->hookTimings[$hook]['max_ms'], $durationMs);
        $this->hookTimings[$hook]['queries'] += $queries;
        $this->hookTimings[$hook]['http_requests'] += $http;
        return $value;
    }

    public function finish(): void
    {
        if (!$this->active || $this->finished) {
            return;
        }
        $this->finished = true;

        $last = error_get_last();
        if (is_array($last) && in_array((int) ($last['type'] ?? 0), array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
            $this->recordPhpError((int) $last['type'], (string) ($last['message'] ?? ''), (string) ($last['file'] ?? ''), (int) ($last['line'] ?? 0));
        }

        restore_error_handler();

        $capture = $this->capture();
        $diagnosticId = (string) ($this->authorization['diagnostic_id'] ?? '');
        if ($diagnosticId !== '') {
            $this->store->save($diagnosticId, $capture);
        }
        $this->destroyDiagnosticAuthContext();
    }

    private function destroyDiagnosticAuthContext(): void
    {
        foreach ($this->diagnosticCookieNames as $name) {
            unset($_COOKIE[$name]);
        }
        $this->diagnosticCookieNames = array();

        $manager = $this->diagnosticSessionManager;
        $token = $this->diagnosticSessionToken;
        $this->diagnosticSessionManager = null;
        $this->diagnosticSessionToken = '';
        if ($token === '' || !is_object($manager) || !method_exists($manager, 'destroy')) {
            return;
        }
        $this->suppressDiagnosticState = true;
        try {
            $manager->destroy($token);
        } catch (\Throwable) {
        } finally {
            $this->suppressDiagnosticState = false;
        }
    }

    private function registerStateHooks(): void
    {
        add_action('added_option', array($this, 'optionAdded'), 10, 2);
        add_action('updated_option', array($this, 'optionUpdated'), 10, 3);
        add_action('deleted_option', array($this, 'optionDeleted'), 10, 1);
        add_action('add_site_option', array($this, 'siteOptionAdded'), 10, 3);
        add_action('update_site_option', array($this, 'siteOptionUpdated'), 10, 4);
        add_action('delete_site_option', array($this, 'siteOptionDeleted'), 10, 2);

        foreach (array('post', 'term', 'user', 'comment', 'blog') as $metaType) {
            add_action('added_' . $metaType . '_meta', array($this, 'metaAdded'), 10, 4);
            add_action('updated_' . $metaType . '_meta', array($this, 'metaUpdated'), 10, 4);
            add_action('deleted_' . $metaType . '_meta', array($this, 'metaDeleted'), 10, 4);
        }

        add_action('save_post', array($this, 'postSaved'), 10, 3);
        add_action('deleted_post', array($this, 'postDeleted'), 10, 1);
        add_action('created_term', array($this, 'termCreated'), 10, 3);
        add_action('edited_term', array($this, 'termEdited'), 10, 3);
        add_action('delete_term', array($this, 'termDeleted'), 10, 3);
        add_action('set_transient', array($this, 'transientSet'), 10, 3);
        add_action('deleted_transient', array($this, 'transientDeleted'), 10, 1);
        add_action('set_site_transient', array($this, 'siteTransientSet'), 10, 3);
        add_action('deleted_site_transient', array($this, 'siteTransientDeleted'), 10, 1);
    }

    private function registerCapabilityHook(): void
    {
        $targets = (array) ($this->authorization['config']['capabilities'] ?? array());
        if ($targets !== array()) {
            add_filter('map_meta_cap', array($this, 'mapMetaCap'), 9999, 4);
            add_filter('user_has_cap', array($this, 'userHasCap'), 9999, 4);
        }
    }

    private function registerTargetHookTiming(): void
    {
        foreach ((array) ($this->authorization['config']['hooks'] ?? array()) as $hook) {
            $hook = trim((string) $hook);
            if ($hook === '' || strtolower($hook) === 'all') {
                continue;
            }
            add_filter($hook, array($this, 'targetHookStart'), -1000000, 1);
            add_filter($hook, array($this, 'targetHookEnd'), 1000000, 1);
        }
    }

    private function completeHttpRequest($response, array $args, string $url, bool $preempted): void
    {
        $id = (string) ($args['_codi_mcp_diagnostic_id'] ?? '');
        if ($id === '' || !isset($this->httpPending[$id]) || count($this->httpRequests) >= self::MAX_HTTP) {
            return;
        }

        $pending = $this->httpPending[$id];
        unset($this->httpPending[$id]);
        $status = 0;
        $errorCode = '';
        $errorMessage = '';
        if ($response instanceof \WP_Error) {
            $errorCode = (string) $response->get_error_code();
            $errorMessage = $this->safeMessage((string) $response->get_error_message());
        } elseif (is_array($response)) {
            $status = (int) ($response['response']['code'] ?? 0);
        }

        $row = array(
            'method' => (string) $pending['method'],
            'url' => (string) $pending['url'],
            'status' => $status,
            'duration_ms' => round(max(0.0, microtime(true) - (float) $pending['started_at']) * 1000, 3),
            'preempted' => $preempted,
            'error_code' => substr($errorCode, 0, 191),
            'error_message' => $errorMessage,
            'source' => $pending['source'],
            'offset_ms' => round(((float) $pending['started_at'] - $this->startedAt) * 1000, 3),
        );
        $this->httpRequests[] = $row;
        $this->timeline('http', $row['method'] . ' ' . $row['url'], (array) $row['source'], (float) $row['duration_ms']);
    }

    private function recordOption(string $operation, string $kind, string $name, $value): void
    {
        if (str_starts_with($name, '_transient_') || str_starts_with($name, '_site_transient_')) {
            return;
        }
        $this->recordState($operation, $kind, $name, 0, $value);
    }

    private function recordNetworkOption(string $operation, string $name, int $networkId, $value): void
    {
        if (str_starts_with($name, '_site_transient_')) {
            return;
        }
        $this->recordState($operation, 'network-option', $name, $networkId, $value);
    }

    private function recordPhpError(int $severity, string $message, string $file, int $line): void
    {
        if ((error_reporting() & $severity) === 0 || count($this->phpErrors) >= self::MAX_ERRORS) {
            return;
        }
        $source = $file !== '' ? $this->attribution->describeFile($file, $line) : $this->attribution->fromBacktrace();
        $row = array(
            'severity' => $this->severity($severity),
            'message' => $this->safeMessage($message),
            'source' => $source,
            'offset_ms' => $this->offsetMs(),
        );
        $this->phpErrors[] = $row;
        $this->timeline('php_error', $row['severity'] . ': ' . $row['message'], $source);
    }

    private function recordState(string $operation, string $kind, string $name, int $objectId, $value): void
    {
        if ($this->suppressDiagnosticState) {
            return;
        }
        if (count($this->stateChanges) >= self::MAX_STATE) {
            return;
        }
        $source = $this->attribution->fromBacktrace();
        $row = array(
            'operation' => $operation,
            'kind' => $kind,
            'name' => substr($name, 0, 300),
            'object_id' => max(0, $objectId),
            'value_bytes' => $this->valueBytes($value),
            'source' => $source,
            'offset_ms' => $this->offsetMs(),
        );
        $this->stateChanges[] = $row;
        $this->timeline('state', $operation . ' ' . $kind . ($name !== '' ? ' ' . $name : ''), $source);
    }

    private function recordSecurityFailure(string $kind, string $action, int $userId): void
    {
        if (count($this->securityEvents) >= self::MAX_ERRORS) {
            return;
        }
        $source = $this->attribution->fromBacktrace();
        $row = array(
            'kind' => $kind,
            'action' => substr($action, 0, 300),
            'user_id' => max(0, $userId),
            'source' => $source,
            'offset_ms' => $this->offsetMs(),
        );
        $this->securityEvents[] = $row;
        $this->timeline('security', $kind . ($action !== '' ? ': ' . $action : ''), $source);
    }

    private function recordMail(string $status, array $mailData, string $error): void
    {
        if (count($this->mail) >= self::MAX_MAIL) {
            return;
        }
        $to = $mailData['to'] ?? array();
        $recipientCount = is_array($to) ? count($to) : (trim((string) $to) !== '' ? 1 : 0);
        $source = $this->attribution->fromBacktrace();
        $row = array(
            'status' => $status,
            'recipient_count' => $recipientCount,
            'attachment_count' => is_array($mailData['attachments'] ?? null) ? count($mailData['attachments']) : 0,
            'error' => $this->safeMessage($error),
            'source' => $source,
            'offset_ms' => $this->offsetMs(),
        );
        $this->mail[] = $row;
        $this->timeline('mail', $status, $source);
    }

    /** @return array<string,mixed> */
    private function capture(): array
    {
        $database = $this->databaseSection();
        $assets = $this->assetsSection();
        $cache = $this->cacheSection();
        $wordpress = $this->wordpressSection();
        $errors = array(
            'php' => $this->phpErrors,
            'wp_error' => $this->wpErrors,
            'doing_it_wrong' => $this->doingItWrong,
            'wp_die' => $this->wpDie,
            'truncated' => count($this->phpErrors) >= self::MAX_ERRORS || count($this->wpErrors) >= self::MAX_ERRORS,
        );

        $request = array(
            'url' => $this->safeRequestUri((string) ($_SERVER['REQUEST_URI'] ?? '/')),
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'status' => $this->responseStatus(),
            'duration_ms' => round(max(0.0, microtime(true) - $this->startedAt) * 1000, 3),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'type' => $this->requestType(),
            'user_id' => $this->currentUserId(),
            'site_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
        );
        $summary = array(
            'errors' => count($this->phpErrors) + count($this->doingItWrong) + count($this->wpDie),
            'wp_errors' => count($this->wpErrors),
            'queries' => (int) ($database['count'] ?? 0),
            'query_time_ms' => (float) ($database['time_ms'] ?? 0.0),
            'state_changes' => count($this->stateChanges),
            'http_requests' => count($this->httpRequests),
            'redirects' => count($this->redirects),
            'security_failures' => count($this->securityEvents),
            'assets' => (int) ($assets['count'] ?? 0),
        );

        return array(
            'overview' => array('request' => $request, 'summary' => $summary),
            'errors' => $errors,
            'database' => $database,
            'state' => array('items' => $this->stateChanges, 'truncated' => count($this->stateChanges) >= self::MAX_STATE),
            'http' => array('items' => $this->httpRequests, 'truncated' => count($this->httpRequests) >= self::MAX_HTTP),
            'wordpress' => $wordpress,
            'redirects' => array('items' => $this->redirects, 'truncated' => count($this->redirects) >= self::MAX_REDIRECTS),
            'cache' => $cache,
            'assets' => $assets,
            'mail' => array('items' => $this->mail, 'truncated' => count($this->mail) >= self::MAX_MAIL),
            'security' => array('items' => $this->securityEvents, 'truncated' => count($this->securityEvents) >= self::MAX_ERRORS),
            'capabilities' => array('items' => $this->capabilityChecks, 'truncated' => count($this->capabilityChecks) >= self::MAX_CAPABILITIES),
            'hooks' => $this->hooksSection(),
            'timeline' => array('items' => $this->timeline, 'truncated' => count($this->timeline) >= self::MAX_TIMELINE),
        );
    }

    private function databaseSection(): array
    {
        global $wpdb;
        if (!$this->queryCaptureAvailable) {
            return array(
                'available' => false,
                'reason' => 'SAVEQUERIES is explicitly disabled.',
                'capture_scope' => 'after_codi_diagnostics_initialized',
                'queries_before_capture' => $this->queryCountAtStart,
                'count' => 0,
                'time_ms' => 0.0,
                'by_type' => array(),
                'by_component' => array(),
                'by_caller' => array(),
                'patterns' => array(),
                'slowest' => array(),
                'explains' => array(),
            );
        }

        $queries = is_object($wpdb) && is_array($wpdb->queries ?? null)
            ? array_slice($wpdb->queries, $this->queryStartIndex)
            : array();
        $byType = array();
        $byComponent = array();
        $byCaller = array();
        $patterns = array();
        $slowest = array();
        $totalTime = 0.0;
        $capturedCount = 0;
        $internalExcluded = 0;
        $explainCandidates = array();

        foreach ($queries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sql = (string) ($entry[0] ?? '');
            $timeMs = max(0.0, (float) ($entry[1] ?? 0.0) * 1000);
            $queryStart = (float) ($entry[3] ?? 0.0);
            $custom = is_array($entry[4] ?? null) ? $entry[4] : array();
            if (!empty($custom['codi_mcp_diagnostic']['internal'])) {
                $internalExcluded++;
                continue;
            }
            $capturedCount++;
            $source = is_array($custom['codi_mcp_diagnostic']['source'] ?? null)
                ? $custom['codi_mcp_diagnostic']['source']
                : $this->emptySource();
            $shape = $this->querySanitizer->shape($sql);
            $type = $this->queryType($sql);
            if (!empty($this->authorization['config']['explain_slow_queries'])
                && $type === 'select'
                && $timeMs >= self::MIN_EXPLAIN_MS) {
                $explainCandidates[] = array(
                    'sql' => $sql,
                    'query' => $shape,
                    'time_ms' => $timeMs,
                    'source' => $source,
                );
            }
            $component = (string) ($source['component'] ?? '');
            if ($component === '') {
                $component = (string) ($source['component_type'] ?? 'unknown');
            }
            $caller = (string) ($source['callable'] ?? '');
            if ($caller === '') {
                $caller = (string) ($source['file'] ?? 'unknown');
            }

            $totalTime += $timeMs;
            $this->aggregateMetric($byType, $type, $timeMs);
            $this->aggregateMetric($byComponent, $component, $timeMs);
            $this->aggregateMetric($byCaller, $caller, $timeMs);

            $patternKey = hash('sha256', $shape . '|' . $component . '|' . $caller);
            if (!isset($patterns[$patternKey])) {
                $patterns[$patternKey] = array(
                    'query' => $shape,
                    'count' => 0,
                    'time_ms' => 0.0,
                    'source' => $source,
                );
            }
            $patterns[$patternKey]['count']++;
            $patterns[$patternKey]['time_ms'] += $timeMs;

            $slowest[] = array(
                'query' => $shape,
                'time_ms' => round($timeMs, 3),
                'source' => $source,
                'offset_ms' => $queryStart > 0 ? round(($queryStart - $this->startedUnix) * 1000, 3) : 0.0,
            );
        }

        uasort($patterns, static fn (array $a, array $b): int => array($b['count'], $b['time_ms']) <=> array($a['count'], $a['time_ms']));
        $patternRows = array();
        foreach ($patterns as $pattern) {
            if ((int) $pattern['count'] < 2) {
                continue;
            }
            $pattern['time_ms'] = round((float) $pattern['time_ms'], 3);
            $patternRows[] = $pattern;
            if (count($patternRows) >= 30) {
                break;
            }
        }
        usort($slowest, static fn (array $a, array $b): int => $b['time_ms'] <=> $a['time_ms']);

        usort($explainCandidates, static fn (array $a, array $b): int => $b['time_ms'] <=> $a['time_ms']);
        $explains = array();
        foreach (array_slice($explainCandidates, 0, self::MAX_EXPLAINS) as $candidate) {
            $explain = $this->explainQuery($candidate);
            if (is_array($explain)) {
                $explains[] = $explain;
            }
        }

        return array(
            'available' => true,
            'capture_scope' => 'after_codi_diagnostics_initialized',
            'queries_before_capture' => $this->queryCountAtStart,
            'count' => $capturedCount,
            'internal_queries_excluded' => $internalExcluded,
            'time_ms' => round($totalTime, 3),
            'by_type' => $this->metricRows($byType, 20),
            'by_component' => $this->metricRows($byComponent, 30),
            'by_caller' => $this->metricRows($byCaller, 30),
            'patterns' => $patternRows,
            'slowest' => array_slice($slowest, 0, 30),
            'explains' => $explains,
        );
    }

    /** @param array{sql:string,query:string,time_ms:float,source:array<string,mixed>} $candidate */
    private function explainQuery(array $candidate): ?array
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return null;
        }

        $previousSuppression = $this->suppressDiagnosticState;
        $this->suppressDiagnosticState = true;
        try {
            $format = defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A';
            $rows = $wpdb->get_results('EXPLAIN ' . (string) $candidate['sql'], $format);
            $plan = $this->normalizeExplainRows(is_array($rows) ? $rows : array());
            $error = trim((string) ($wpdb->last_error ?? ''));
        } catch (\Throwable $throwable) {
            $plan = array();
            $error = $throwable->getMessage();
        } finally {
            $this->suppressDiagnosticState = $previousSuppression;
        }

        return array(
            'query' => (string) $candidate['query'],
            'time_ms' => round((float) $candidate['time_ms'], 3),
            'source' => (array) $candidate['source'],
            'plan' => $plan,
            'error' => $error !== '' ? 'EXPLAIN failed.' : '',
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeExplainRows(array $rows): array
    {
        $result = array();
        foreach (array_slice($rows, 0, 50) as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }
            if (!is_array($row)) {
                continue;
            }
            $lower = array_change_key_case($row, CASE_LOWER);
            $result[] = array(
                'id' => (int) ($lower['id'] ?? 0),
                'select_type' => substr((string) ($lower['select_type'] ?? ''), 0, 100),
                'table' => substr((string) ($lower['table'] ?? ''), 0, 191),
                'partitions' => substr((string) ($lower['partitions'] ?? ''), 0, 300),
                'type' => substr((string) ($lower['type'] ?? ''), 0, 100),
                'possible_keys' => substr((string) ($lower['possible_keys'] ?? ''), 0, 1000),
                'key' => substr((string) ($lower['key'] ?? ''), 0, 191),
                'key_len' => substr((string) ($lower['key_len'] ?? ''), 0, 100),
                'ref' => substr((string) ($lower['ref'] ?? ''), 0, 500),
                'rows' => max(0, (int) ($lower['rows'] ?? 0)),
                'filtered' => (float) ($lower['filtered'] ?? 0.0),
                'extra' => substr((string) ($lower['extra'] ?? ''), 0, 1000),
            );
        }
        return $result;
    }

    private function wordpressSection(): array
    {
        global $wp, $wp_query;
        $queryVarKeys = array();
        $queriedObjectId = 0;
        $queriedObjectType = '';
        if (is_object($wp_query)) {
            $queryVars = is_array($wp_query->query_vars ?? null) ? $wp_query->query_vars : array();
            $queryVarKeys = array_values(array_map('strval', array_slice(array_keys($queryVars), 0, 100)));
            $queriedObjectId = (int) ($wp_query->queried_object_id ?? 0);
            $queried = $wp_query->queried_object ?? null;
            $queriedObjectType = is_object($queried) ? get_class($queried) : '';
        }

        $blockRows = array();
        foreach ($this->blocks as $name => $count) {
            $row = array('name' => $name, 'count' => $count, 'render_callback' => $this->emptySource());
            if (class_exists('WP_Block_Type_Registry')) {
                try {
                    $registry = \WP_Block_Type_Registry::get_instance();
                    $type = method_exists($registry, 'get_registered') ? $registry->get_registered($name) : null;
                    if (is_object($type) && is_callable($type->render_callback ?? null)) {
                        $row['render_callback'] = $this->attribution->fromCallable($type->render_callback);
                    }
                } catch (\Throwable) {
                }
            }
            $blockRows[] = $row;
        }

        return array(
            'request_type' => $this->requestType(),
            'auth' => $this->authContext(),
            'screen' => $this->screen,
            'rest' => $this->rest,
            'template' => $this->template,
            'rewrite' => array(
                'matched_rule' => is_object($wp) ? substr((string) ($wp->matched_rule ?? ''), 0, 1000) : '',
                'query_var_keys' => $queryVarKeys,
                'queried_object_id' => $queriedObjectId,
                'queried_object_type' => $queriedObjectType,
            ),
            'blocks' => $blockRows,
            'blog_switches' => $this->blogSwitches,
        );
    }

    private function authContext(): array
    {
        $loggedInName = defined('LOGGED_IN_COOKIE') ? (string) LOGGED_IN_COOKIE : '';
        $authName = defined('AUTH_COOKIE') ? (string) AUTH_COOKIE : '';
        $secureAuthName = defined('SECURE_AUTH_COOKIE') ? (string) SECURE_AUTH_COOKIE : '';

        $validate = static function (string $scheme): int {
            if (!function_exists('wp_validate_auth_cookie')) {
                return 0;
            }
            $userId = wp_validate_auth_cookie('', $scheme);
            return is_int($userId) ? max(0, $userId) : 0;
        };

        return array(
            'current_user_id' => $this->currentUserId(),
            'logged_in_cookie_present' => $loggedInName !== '' && isset($_COOKIE[$loggedInName]),
            'auth_cookie_present' => $authName !== '' && isset($_COOKIE[$authName]),
            'secure_auth_cookie_present' => $secureAuthName !== '' && isset($_COOKIE[$secureAuthName]),
            'logged_in_cookie_user_id' => $validate('logged_in'),
            'auth_cookie_user_id' => $validate('auth'),
            'secure_auth_cookie_user_id' => $validate('secure_auth'),
        );
    }

    private function cacheSection(): array
    {
        $after = $this->cacheSnapshot();
        $hits = max(0, (int) ($after['hits'] ?? 0) - (int) ($this->cacheBaseline['hits'] ?? 0));
        $misses = max(0, (int) ($after['misses'] ?? 0) - (int) ($this->cacheBaseline['misses'] ?? 0));
        $total = $hits + $misses;
        return array(
            'available' => (bool) ($after['available'] ?? false),
            'implementation_class' => (string) ($after['implementation_class'] ?? ''),
            'external' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'hits' => $hits,
            'misses' => $misses,
            'hit_ratio' => $total > 0 ? round($hits / $total, 4) : 0.0,
        );
    }

    private function assetsSection(): array
    {
        if (empty($this->authorization['config']['include_assets'])) {
            return array('count' => 0, 'scripts' => array(), 'styles' => array(), 'truncated' => false);
        }

        global $wp_scripts, $wp_styles;
        $scripts = $this->dependencyRows($wp_scripts, 'script');
        $styles = $this->dependencyRows($wp_styles, 'style');
        return array(
            'count' => count($scripts) + count($styles),
            'scripts' => $scripts,
            'styles' => $styles,
            'truncated' => count($scripts) >= self::MAX_ASSETS || count($styles) >= self::MAX_ASSETS,
        );
    }

    private function hooksSection(): array
    {
        $requested = array_values(array_map('strval', (array) ($this->authorization['config']['hooks'] ?? array())));
        if ($requested === array()) {
            return array('items' => array());
        }

        global $wp_filter;
        $rows = array();
        foreach ($requested as $hook) {
            $callbacks = array();
            $hookObject = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
            $byPriority = is_object($hookObject) && is_array($hookObject->callbacks ?? null)
                ? $hookObject->callbacks
                : array();
            foreach ($byPriority as $priority => $registrations) {
                foreach ((array) $registrations as $registration) {
                    $callback = is_array($registration) ? ($registration['function'] ?? null) : null;
                    if ($callback === null || (is_array($callback) && is_object($callback[0] ?? null) && $callback[0] === $this)) {
                        continue;
                    }
                    $callbacks[] = array(
                        'priority' => (int) $priority,
                        'accepted_args' => (int) ($registration['accepted_args'] ?? 1),
                        'source' => $this->attribution->fromCallable($callback),
                    );
                    if (count($callbacks) >= 100) {
                        break 2;
                    }
                }
            }
            $timing = (array) ($this->hookTimings[$hook] ?? array());
            $rows[] = array(
                'hook' => $hook,
                'execution' => array(
                    'executions' => (int) ($timing['executions'] ?? 0),
                    'time_ms' => round((float) ($timing['time_ms'] ?? 0.0), 3),
                    'max_ms' => round((float) ($timing['max_ms'] ?? 0.0), 3),
                    'queries' => (int) ($timing['queries'] ?? 0),
                    'http_requests' => (int) ($timing['http_requests'] ?? 0),
                ),
                'callbacks' => $callbacks,
            );
        }
        return array('items' => $rows);
    }

    private function dependencyRows($dependencies, string $kind): array
    {
        if (!is_object($dependencies) || !is_array($dependencies->queue ?? null)) {
            return array();
        }
        $registered = is_array($dependencies->registered ?? null) ? $dependencies->registered : array();
        $rows = array();
        foreach (array_values(array_unique(array_map('strval', $dependencies->queue))) as $handle) {
            if (count($rows) >= self::MAX_ASSETS) {
                break;
            }
            $item = $registered[$handle] ?? null;
            $src = is_object($item) ? (string) ($item->src ?? '') : '';
            $rows[] = array(
                'handle' => $handle,
                'src' => $this->safeAssetSrc($src),
                'dependencies' => is_object($item) ? array_values(array_map('strval', (array) ($item->deps ?? array()))) : array(),
                'component' => $this->assetComponent($src),
            );
        }
        return $rows;
    }

    private function cacheSnapshot(): array
    {
        global $wp_object_cache;
        if (!is_object($wp_object_cache)) {
            return array('available' => false, 'hits' => 0, 'misses' => 0, 'implementation_class' => '');
        }
        $hits = isset($wp_object_cache->cache_hits) && is_numeric($wp_object_cache->cache_hits) ? (int) $wp_object_cache->cache_hits : null;
        $misses = isset($wp_object_cache->cache_misses) && is_numeric($wp_object_cache->cache_misses) ? (int) $wp_object_cache->cache_misses : null;
        return array(
            'available' => $hits !== null && $misses !== null,
            'hits' => $hits ?? 0,
            'misses' => $misses ?? 0,
            'implementation_class' => get_class($wp_object_cache),
        );
    }

    private function timeline(string $type, string $summary, array $source, float $durationMs = 0.0): void
    {
        if (count($this->timeline) >= self::MAX_TIMELINE) {
            return;
        }
        $this->timeline[] = array(
            'type' => $type,
            'summary' => substr($summary, 0, 500),
            'offset_ms' => $this->offsetMs(),
            'duration_ms' => round(max(0.0, $durationMs), 3),
            'source' => $source,
        );
    }

    private function aggregateMetric(array &$rows, string $name, float $timeMs): void
    {
        $name = $name !== '' ? $name : 'unknown';
        if (!isset($rows[$name])) {
            $rows[$name] = array('name' => $name, 'count' => 0, 'time_ms' => 0.0);
        }
        $rows[$name]['count']++;
        $rows[$name]['time_ms'] += $timeMs;
    }

    private function metricRows(array $rows, int $limit): array
    {
        uasort($rows, static fn (array $a, array $b): int => array($b['time_ms'], $b['count']) <=> array($a['time_ms'], $a['count']));
        $result = array_slice(array_values($rows), 0, $limit);
        foreach ($result as &$row) {
            $row['time_ms'] = round((float) $row['time_ms'], 3);
        }
        return $result;
    }

    private function queryType(string $sql): string
    {
        if (preg_match('/^\s*([A-Za-z]+)/', $sql, $matches)) {
            return strtolower((string) $matches[1]);
        }
        return 'unknown';
    }

    private function currentUserId(): int
    {
        if (function_exists('get_current_user_id')) {
            return max(0, (int) get_current_user_id());
        }
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            return is_object($user) ? max(0, (int) ($user->ID ?? 0)) : 0;
        }
        return $this->usesFixedIdentity() ? max(0, (int) ($this->authorization['user_id'] ?? 0)) : 0;
    }

    private function requestType(): string
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return 'ajax';
        }
        if ((function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('DOING_CRON') && DOING_CRON)) {
            return 'cron';
        }
        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }
        return 'frontend';
    }

    private function responseStatus(): int
    {
        $status = http_response_code();
        return is_int($status) && $status > 0 ? $status : 200;
    }

    private function safeRequestUri(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return '/';
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = $this->queryKeys((string) ($parts['query'] ?? ''));
        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return substr($this->redactor->redactLine($url), 0, 1000);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = $this->queryKeys((string) ($parts['query'] ?? ''));
        $prefix = $host !== '' ? ($scheme !== '' ? $scheme . '://' : '//') . $host . $port : '';
        return substr($prefix . $path . ($query !== '' ? '?' . $query : ''), 0, 1000);
    }

    private function queryKeys(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $keys = array();
        foreach (explode('&', $query) as $part) {
            $key = rawurldecode((string) explode('=', $part, 2)[0]);
            $key = preg_replace('/[^A-Za-z0-9_.\[\]-]/', '', $key) ?? '';
            if ($key !== '') {
                $keys[$key] = true;
            }
            if (count($keys) >= 30) {
                break;
            }
        }
        return implode('&', array_keys($keys));
    }

    private function safeAssetSrc(string $src): string
    {
        if (preg_match('#^https?://#i', $src)) {
            return $this->safeUrl($src);
        }
        $parts = explode('?', $src, 2);
        return substr((string) $parts[0], 0, 1000);
    }

    private function assetComponent(string $src): string
    {
        $path = (string) (parse_url($src, PHP_URL_PATH) ?? $src);
        if (preg_match('#/wp-content/plugins/([^/]+)/#', $path, $matches)) {
            return 'plugin:' . (string) $matches[1];
        }
        if (preg_match('#/wp-content/mu-plugins/([^/]+)/#', $path, $matches)) {
            return 'mu-plugin:' . (string) $matches[1];
        }
        if (preg_match('#/wp-content/themes/([^/]+)/#', $path, $matches)) {
            return 'theme:' . (string) $matches[1];
        }
        if (str_contains($path, '/wp-includes/') || str_contains($path, '/wp-admin/')) {
            return 'wordpress-core';
        }
        return '';
    }

    private function valueBytes($value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_string($value)) {
            return strlen($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return strlen((string) $value);
        }
        return 0;
    }

    private function safeMessage(string $message): string
    {
        return substr($this->redactor->redactLine($message), 0, 1000);
    }

    private function metaTypeFromHook(): string
    {
        $hook = function_exists('current_filter') ? (string) (current_filter() ?? '') : '';
        if (preg_match('/^(?:added|updated|deleted)_([a-z0-9_-]+)_meta$/', $hook, $matches)) {
            return (string) $matches[1];
        }
        return 'object';
    }

    private function offsetMs(): float
    {
        return round(max(0.0, microtime(true) - $this->startedAt) * 1000, 3);
    }

    private function severity(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_PARSE => 'parse',
            E_NOTICE => 'notice',
            E_CORE_ERROR => 'core_error',
            E_CORE_WARNING => 'core_warning',
            E_COMPILE_ERROR => 'compile_error',
            E_COMPILE_WARNING => 'compile_warning',
            E_USER_ERROR => 'user_error',
            E_USER_WARNING => 'user_warning',
            E_USER_NOTICE => 'user_notice',
            E_STRICT => 'strict',
            E_RECOVERABLE_ERROR => 'recoverable_error',
            E_DEPRECATED => 'deprecated',
            E_USER_DEPRECATED => 'user_deprecated',
            default => 'unknown',
        };
    }

    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    private function emptySource(): array
    {
        return array('component_type' => 'unknown', 'component' => '', 'file' => '', 'line' => 0, 'callable' => '', 'hook' => '');
    }
}