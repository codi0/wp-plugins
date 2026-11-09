<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

use CodiMcp\Core\SourceAttribution;
use CodiMcp\Core\Security\SensitiveDataRedactor;
use CodiMcp\Packages\Database\DatabaseOptimizer;

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
    private float $targetFinishedAt = 0.0;
    private bool $saveQueriesPreexisting = false;
    private int $preDiagnosticQueryEndIndex = 0;
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
    private ?CallbackProfiler $callbackProfiler = null;
    private array $timeline = array();
    private array $screen = array();
    private array $rest = array();
    private array $template = array();
    private array $blocks = array();
    private array $blogSwitches = array();
    private array $cacheBaseline = array();
    private string $queryFingerprintKey = '';
    private array $hookSourceCosts = array();
    private array $optionAccesses = array();
    private mixed $diagnosticSessionManager = null;
    private string $diagnosticSessionToken = '';
    private array $diagnosticCookieNames = array();
    private bool $suppressDiagnosticState = false;
    private array $collectorTimings = array();

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

        global $wpdb;
        $this->saveQueriesPreexisting = defined('SAVEQUERIES') && (bool) SAVEQUERIES;
        $this->preDiagnosticQueryEndIndex = $this->saveQueriesPreexisting
            && is_object($wpdb)
            && is_array($wpdb->queries ?? null)
                ? count($wpdb->queries)
                : 0;

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
        $runtimeSetupStarted = hrtime(true);
        try {
            $this->queryFingerprintKey = random_bytes(32);
        } catch (\Throwable) {
            $this->queryFingerprintKey = '';
        }
        $this->cacheBaseline = $this->cacheSnapshot();
        if (!empty($this->authorization['config']['callback_timing'])) {
            $this->callbackProfiler = new CallbackProfiler($this->attribution);
        }

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
        add_filter('pre_option', array($this, 'optionAccess'), PHP_INT_MAX, 3);
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

        $this->collectorEnd('runtime.setup', $runtimeSetupStarted);

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
        return $this->collectorCall('database.query_custom_data', function () use ($data, $query): array {
            $data['codi_mcp_diagnostic'] = array(
                'source' => $this->attribution->fromBacktrace(),
                'callback_source' => $this->attribution->currentContextSource(),
                'internal' => $this->suppressDiagnosticState,
                'binding_fingerprint' => $this->queryFingerprintKey !== ''
                    ? hash_hmac('sha256', $query, $this->queryFingerprintKey)
                    : '',
            );
            return $data;
        });
    }

    public function optionAccess($pre, string $option, $defaultValue = false)
    {
        return $this->collectorCall('database.option_access', function () use ($pre, $option) {
            if ($this->suppressDiagnosticState || $option === ''
                || str_starts_with($option, '_transient_')
                || str_starts_with($option, '_site_transient_')) {
                return $pre;
            }
            if (isset($this->optionAccesses[$option])) {
                $this->optionAccesses[$option]['count']++;
                return $pre;
            }
            if (count($this->optionAccesses) >= 500) {
                return $pre;
            }

            $source = $this->attribution->fromBacktrace();
            if ((string) ($source['component'] ?? '') === 'codi-mcp') {
                return $pre;
            }
            $this->optionAccesses[$option] = array(
                'count' => 1,
                'source' => $source,
            );
            return $pre;
        });
    }

    public function httpRequestArgs(array $args, string $url): array
    {
        return $this->collectorCall('http.request_start', function () use ($args, $url): array {
            $id = 'http-' . (++$this->httpCounter);
            $args['_codi_mcp_diagnostic_id'] = $id;
            $this->httpPending[$id] = array(
                'started_at' => microtime(true),
                'method' => strtoupper((string) ($args['method'] ?? 'GET')),
                'url' => $this->safeUrl($url),
                'ajax_action' => $this->ajaxAction($url),
                'source' => $this->attribution->fromBacktrace(),
                'callback_source' => $this->attribution->currentContextSource(),
            );
            return $args;
        });
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
        return $this->collectorCall('routing.redirect', function () use ($location, $status): string {
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
        });
    }

    public function templateInclude(string $template): string
    {
        return $this->collectorCall('wordpress.template', function () use ($template): string {
            $this->template = $this->attribution->describeFile($template);
            return $template;
        });
    }

    public function restBeforeCallbacks($response, array $handler, $request)
    {
        return $this->collectorCall('wordpress.rest_context', function () use ($response, $handler, $request) {
            $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
            $method = is_object($request) && method_exists($request, 'get_method') ? (string) $request->get_method() : '';
            $callback = $handler['callback'] ?? null;
            $this->rest = array(
                'route' => substr($route, 0, 500),
                'method' => substr($method, 0, 20),
                'callback_source' => $callback !== null ? $this->attribution->fromCallable($callback) : $this->emptySource(),
            );
            return $response;
        });
    }

    public function renderBlockData(array $parsedBlock, array $sourceBlock = array(), $parentBlock = null): array
    {
        return $this->collectorCall('wordpress.block', function () use ($parsedBlock): array {
            $name = trim((string) ($parsedBlock['blockName'] ?? ''));
            if ($name !== '' && (isset($this->blocks[$name]) || count($this->blocks) < self::MAX_BLOCK_TYPES)) {
                $this->blocks[$name] = (int) ($this->blocks[$name] ?? 0) + 1;
            }
            return $parsedBlock;
        });
    }

    public function currentScreen($screen): void
    {
        $this->collectorCall('wordpress.current_screen', function () use ($screen): void {
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
        });
    }

    public function wpErrorAdded($code, string $message, $data, $wpError): void
    {
        $this->collectorCall('errors.wp_error', function () use ($code, $message): void {
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
        });
    }

    public function doingItWrong(string $functionName, string $message, string $version): void
    {
        $this->collectorCall('errors.doing_it_wrong', function () use ($functionName, $message, $version): void {
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
        });
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
        $this->collectorCall('multisite.switch_blog', function () use ($newBlogId, $previousBlogId, $context): void {
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
        });
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
        return $this->collectorCall('capabilities.map_meta_cap', function () use ($caps, $capability, $userId): array {
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
        });
    }

    public function userHasCap(array $allcaps, array $caps, array $args, $user): array
    {
        return $this->collectorCall('capabilities.user_has_cap', function () use ($allcaps, $caps, $args, $user): array {
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
        });
    }

    public function targetHookStart($value = null)
    {
        $hook = function_exists('current_filter') ? (string) (current_filter() ?? '') : '';
        if ($hook === '') {
            return $value;
        }
        global $wpdb, $wp_filter;
        if ($this->callbackProfiler !== null) {
            $hookObject = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
            if (is_object($hookObject)) {
                $priority = method_exists($hookObject, 'current_priority') ? $hookObject->current_priority() : false;
                $this->callbackProfiler->beginHook($hook, $hookObject, is_int($priority) ? $priority : false, PHP_INT_MAX, $this);
            }
        }
        $sentinelStarted = hrtime(true);
        $this->hookTimingStacks[$hook][] = array(
            'started_at' => microtime(true),
            'queries' => is_object($wpdb) ? max(0, (int) ($wpdb->num_queries ?? 0)) : 0,
            'http' => count($this->httpRequests),
        );
        $this->collectorEnd('hooks.sentinel_start', $sentinelStarted);
        return $value;
    }

    public function targetHookEnd($value = null)
    {
        $hook = function_exists('current_filter') ? (string) (current_filter() ?? '') : '';
        $stack = (array) ($this->hookTimingStacks[$hook] ?? array());
        if ($hook === '' || $stack === array()) {
            return $value;
        }
        $sentinelStarted = hrtime(true);
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
        $this->collectorEnd('hooks.sentinel_end', $sentinelStarted);

        if ($this->callbackProfiler !== null) {
            global $wp_filter;
            $hookObject = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
            if (is_object($hookObject)) {
                $this->callbackProfiler->endHook($hook, $hookObject);
            }
        }
        return $value;
    }

    public function finish(): void
    {
        if (!$this->active || $this->finished) {
            return;
        }
        $this->finished = true;
        $this->targetFinishedAt = microtime(true);
        if ($this->callbackProfiler !== null) {
            global $wp_filter;
            $this->callbackProfiler->restoreAll(is_array($wp_filter ?? null) ? $wp_filter : array());
        }

        $last = error_get_last();
        if (is_array($last) && in_array((int) ($last['type'] ?? 0), array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
            $this->recordPhpError((int) $last['type'], (string) ($last['message'] ?? ''), (string) ($last['file'] ?? ''), (int) ($last['line'] ?? 0));
        }

        restore_error_handler();

        $collectorOverhead = $this->collectorOverhead();

        $captureBuildStartedAt = microtime(true);
        $capture = $this->capture();
        $capture['overview']['diagnostic']['overhead'] = array(
            'capture_build_ms' => round(max(0.0, microtime(true) - $captureBuildStartedAt) * 1000, 3),
            'collectors' => $collectorOverhead,
            'callback_profiler' => $this->callbackProfiler !== null
                ? $this->callbackProfiler->overhead()
                : array(
                    'setup_ms' => 0.0,
                    'restore_ms' => 0.0,
                    'bookkeeping_ms' => 0.0,
                    'measured_overhead_ms' => 0.0,
                    'incomplete_invocations' => 0,
                ),
        );
        $diagnosticId = (string) ($this->authorization['diagnostic_id'] ?? '');
        if ($diagnosticId !== '') {
            $this->store->save($diagnosticId, $capture);
        }
        $this->destroyDiagnosticAuthContext();
    }

    private function collectorCall(string $name, callable $callback)
    {
        $started = hrtime(true);
        try {
            return $callback();
        } finally {
            $this->collectorEnd($name, $started);
        }
    }

    private function collectorEnd(string $name, int $startedNs): void
    {
        $elapsed = max(0, hrtime(true) - $startedNs);
        if (!isset($this->collectorTimings[$name])) {
            $this->collectorTimings[$name] = array('calls' => 0, 'ns' => 0, 'max_ns' => 0);
        }
        $this->collectorTimings[$name]['calls']++;
        $this->collectorTimings[$name]['ns'] += $elapsed;
        $this->collectorTimings[$name]['max_ns'] = max((int) $this->collectorTimings[$name]['max_ns'], $elapsed);
    }

    private function collectorOverhead(): array
    {
        $items = array();
        $totalNs = 0;
        $calls = 0;
        foreach ($this->collectorTimings as $name => $timing) {
            $ns = max(0, (int) ($timing['ns'] ?? 0));
            $totalNs += $ns;
            $calls += max(0, (int) ($timing['calls'] ?? 0));
            $items[] = array(
                'name' => (string) $name,
                'calls' => max(0, (int) ($timing['calls'] ?? 0)),
                'time_ms' => round($ns / 1000000, 3),
                'max_ms' => round(max(0, (int) ($timing['max_ns'] ?? 0)) / 1000000, 3),
            );
        }
        usort($items, static fn (array $a, array $b): int => array((float) ($b['time_ms'] ?? 0.0), (int) ($b['calls'] ?? 0)) <=> array((float) ($a['time_ms'] ?? 0.0), (int) ($a['calls'] ?? 0)));
        return array(
            'measured_ms_sum' => round($totalNs / 1000000, 3),
            'calls' => $calls,
            'items' => array_slice($items, 0, 30),
            'items_truncated' => count($items) > 30,
            'scope' => 'instrumented_collector_self_time',
        );
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
            add_filter($hook, array($this, 'targetHookStart'), PHP_INT_MIN, 1);
            add_filter($hook, array($this, 'targetHookEnd'), PHP_INT_MAX, 1);
        }
    }

    private function completeHttpRequest($response, array $args, string $url, bool $preempted): void
    {
        $this->collectorCall('http.complete', function () use ($response, $args, $url, $preempted): void {
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
                'ajax_action' => (string) ($pending['ajax_action'] ?? ''),
                'status' => $status,
                'duration_ms' => round(max(0.0, microtime(true) - (float) $pending['started_at']) * 1000, 3),
                'preempted' => $preempted,
                'error_code' => substr($errorCode, 0, 191),
                'error_message' => $errorMessage,
                'source' => $pending['source'],
                'callback_source' => is_array($pending['callback_source'] ?? null) ? $pending['callback_source'] : null,
                'offset_ms' => round(((float) $pending['started_at'] - $this->startedAt) * 1000, 3),
            );
            $this->httpRequests[] = $row;
            $this->timeline('http', $row['method'] . ' ' . $row['url'], (array) $row['source'], (float) $row['duration_ms']);
        });
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
        $this->collectorCall('errors.php', function () use ($severity, $message, $file, $line): void {
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
        });
    }

    private function recordState(string $operation, string $kind, string $name, int $objectId, $value): void
    {
        $this->collectorCall('state.change', function () use ($operation, $kind, $name, $objectId, $value): void {
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
        });
    }

    private function recordSecurityFailure(string $kind, string $action, int $userId): void
    {
        $this->collectorCall('security.failure', function () use ($kind, $action, $userId): void {
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
        });
    }

    private function recordMail(string $status, array $mailData, string $error): void
    {
        $this->collectorCall('mail.result', function () use ($status, $mailData, $error): void {
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
        });
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
            'duration_ms' => round(max(0.0, ($this->targetFinishedAt > 0.0 ? $this->targetFinishedAt : microtime(true)) - $this->startedAt) * 1000, 3),
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

        $capture = array(
            'overview' => array(
                'request' => $request,
                'summary' => $summary,
                'diagnostic' => $this->diagnosticMetadata(),
            ),
            'errors' => $errors,
            'database' => $database,
            'state' => array('items' => $this->stateChanges, 'truncated' => count($this->stateChanges) >= self::MAX_STATE),
            'http' => array(
                'items' => $this->httpRequests,
                'opportunities' => $this->httpOpportunities(),
                'truncated' => count($this->httpRequests) >= self::MAX_HTTP,
            ),
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
        $capture['correlations'] = (new DiagnosticCorrelator())->synthesize($capture);
        $capture['overview']['summary']['correlations'] = (int) ($capture['correlations']['count'] ?? 0);
        return $capture;
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

        $allQueries = is_object($wpdb) && is_array($wpdb->queries ?? null) ? $wpdb->queries : array();
        $queries = array();
        if ($this->saveQueriesPreexisting && $this->preDiagnosticQueryEndIndex > 0) {
            foreach (array_slice($allQueries, 0, $this->preDiagnosticQueryEndIndex) as $entry) {
                $queries[] = array('phase' => 'pre_diagnostics', 'entry' => $entry);
            }
        }
        foreach (array_slice($allQueries, $this->queryStartIndex) as $entry) {
            $queries[] = array('phase' => 'instrumented', 'entry' => $entry);
        }
        $byType = array();
        $byComponent = array();
        $byCaller = array();
        $patterns = array();
        $slowest = array();
        $callbackCosts = array();
        $totalTime = 0.0;
        $capturedCount = 0;
        $internalExcluded = 0;
        $phaseMetrics = array(
            'pre_diagnostics' => array('count' => 0, 'time_ms' => 0.0),
            'instrumented' => array('count' => 0, 'time_ms' => 0.0),
        );
        $explainCandidates = array();

        foreach ($queries as $queryRow) {
            $phase = (string) ($queryRow['phase'] ?? 'instrumented');
            $entry = $queryRow['entry'] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $sql = (string) ($entry[0] ?? '');
            $timeMs = max(0.0, (float) ($entry[1] ?? 0.0) * 1000);
            $queryStart = (float) ($entry[3] ?? 0.0);
            $custom = is_array($entry[4] ?? null) ? $entry[4] : array();
            if ($phase === 'instrumented' && !empty($custom['codi_mcp_diagnostic']['internal'])) {
                $internalExcluded++;
                continue;
            }
            $capturedCount++;
            if ($phase === 'pre_diagnostics') {
                $source = $this->emptySource();
                $callerSummary = preg_replace('/\s+/', ' ', trim((string) ($entry[2] ?? ''))) ?? '';
                $source['callable'] = substr($callerSummary, 0, 500);
            } else {
                $source = is_array($custom['codi_mcp_diagnostic']['source'] ?? null)
                    ? $custom['codi_mcp_diagnostic']['source']
                    : $this->emptySource();
            }
            $callbackSource = $phase === 'instrumented' && is_array($custom['codi_mcp_diagnostic']['callback_source'] ?? null)
                ? (array) $custom['codi_mcp_diagnostic']['callback_source']
                : null;
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
                    'capture_phase' => $phase,
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
            $phaseMetrics[$phase]['count']++;
            $phaseMetrics[$phase]['time_ms'] += $timeMs;
            $this->aggregateMetric($byType, $type, $timeMs);
            $this->aggregateMetric($byComponent, $component, $timeMs);
            $this->aggregateMetric($byCaller, $caller, $timeMs);
            if (is_array($callbackSource)) {
                $this->aggregateCallbackQueryCost($callbackCosts, $callbackSource, $timeMs);
            }

            $patternKey = hash('sha256', $phase . '|' . $shape . '|' . $component . '|' . $caller);
            if (!isset($patterns[$patternKey])) {
                $patterns[$patternKey] = array(
                    'query' => $shape,
                    'capture_phase' => $phase,
                    'count' => 0,
                    'time_ms' => 0.0,
                    'source' => $source,
                    '_binding_counts' => array(),
                );
            }
            $patterns[$patternKey]['count']++;
            $patterns[$patternKey]['time_ms'] += $timeMs;
            $bindingFingerprint = (string) ($custom['codi_mcp_diagnostic']['binding_fingerprint'] ?? '');
            if ($bindingFingerprint !== '') {
                $patterns[$patternKey]['_binding_counts'][$bindingFingerprint] = (int) ($patterns[$patternKey]['_binding_counts'][$bindingFingerprint] ?? 0) + 1;
            }
            $this->aggregateHookQueryCost($source, $timeMs);

            $slowest[] = array(
                'query' => $shape,
                'capture_phase' => $phase,
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
            $bindingCounts = (array) ($pattern['_binding_counts'] ?? array());
            unset($pattern['_binding_counts']);
            $pattern['binding_cardinality_available'] = $bindingCounts !== array();
            $pattern['distinct_bindings'] = count($bindingCounts);
            $pattern['max_binding_repetitions'] = $bindingCounts !== array() ? max($bindingCounts) : 0;
            $pattern['duplicate_executions'] = $bindingCounts !== array()
                ? max(0, (int) $pattern['count'] - count($bindingCounts))
                : 0;
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

        $autoload = $this->autoloadEvidence();
        $opportunities = $this->databaseOpportunities($patternRows, $explains, $autoload);
        $callbackCostRows = array_values($callbackCosts);
        usort($callbackCostRows, static fn (array $a, array $b): int => array((float) ($b['query_time_ms'] ?? 0.0), (int) ($b['queries'] ?? 0)) <=> array((float) ($a['query_time_ms'] ?? 0.0), (int) ($a['queries'] ?? 0)));
        foreach ($callbackCostRows as &$callbackCostRow) {
            $callbackCostRow['query_time_ms'] = round((float) ($callbackCostRow['query_time_ms'] ?? 0.0), 3);
        }
        unset($callbackCostRow);

        return array(
            'available' => true,
            'capture_scope' => $this->saveQueriesPreexisting ? 'full_available_savequeries_log' : 'after_codi_diagnostics_initialized',
            'queries_before_capture' => $this->queryCountAtStart,
            'pre_diagnostics_queries_included' => (int) $phaseMetrics['pre_diagnostics']['count'],
            'diagnostic_setup_queries_excluded' => $this->saveQueriesPreexisting
                ? max(0, $this->queryStartIndex - $this->preDiagnosticQueryEndIndex)
                : 0,
            'phases' => array(
                'pre_diagnostics' => array(
                    'count' => (int) $phaseMetrics['pre_diagnostics']['count'],
                    'time_ms' => round((float) $phaseMetrics['pre_diagnostics']['time_ms'], 3),
                ),
                'instrumented' => array(
                    'count' => (int) $phaseMetrics['instrumented']['count'],
                    'time_ms' => round((float) $phaseMetrics['instrumented']['time_ms'], 3),
                ),
            ),
            'count' => $capturedCount,
            'internal_queries_excluded' => $internalExcluded,
            'time_ms' => round($totalTime, 3),
            'by_type' => $this->metricRows($byType, 20),
            'by_component' => $this->metricRows($byComponent, 30),
            'by_caller' => $this->metricRows($byCaller, 30),
            'callback_costs' => array_slice($callbackCostRows, 0, 50),
            'patterns' => $patternRows,
            'slowest' => array_slice($slowest, 0, 30),
            'explains' => $explains,
            'autoload' => $autoload,
            'opportunities' => $opportunities,
        );
    }

    /** @param array{sql:string,query:string,time_ms:float,source:array<string,mixed>,capture_phase:string} $candidate */
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
            'capture_phase' => (string) ($candidate['capture_phase'] ?? 'instrumented'),
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




    /** @return array<int,array<string,mixed>> */
    private function httpOpportunities(): array
    {
        $groups = array();
        foreach ($this->httpRequests as $item) {
            if (!is_array($item)) {
                continue;
            }
            $source = is_array($item['source'] ?? null) ? $item['source'] : $this->emptySource();
            $key = hash('sha256',
                (string) ($item['method'] ?? '') . '|'
                . (string) ($item['url'] ?? '') . '|'
                . (string) ($item['ajax_action'] ?? '') . '|'
                . (string) ($source['component'] ?? '') . '|'
                . (string) ($source['callable'] ?? '')
            );
            $groups[$key] ??= array(
                'method' => (string) ($item['method'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'ajax_action' => (string) ($item['ajax_action'] ?? ''),
                'source' => $source,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'failures' => 0,
                'same_origin' => $this->sameOriginUrl((string) ($item['url'] ?? '')),
            );
            $duration = max(0.0, (float) ($item['duration_ms'] ?? 0.0));
            $groups[$key]['count']++;
            $groups[$key]['total_ms'] += $duration;
            $groups[$key]['max_ms'] = max((float) $groups[$key]['max_ms'], $duration);
            $status = (int) ($item['status'] ?? 0);
            if ((string) ($item['error_code'] ?? '') !== '' || $status >= 500) {
                $groups[$key]['failures']++;
            }
        }

        $rows = array();
        foreach ($groups as $group) {
            $count = max(0, (int) $group['count']);
            $totalMs = max(0.0, (float) $group['total_ms']);
            $maxMs = max(0.0, (float) $group['max_ms']);
            $failures = max(0, (int) $group['failures']);
            $repeated = $count >= 2 && $totalMs >= 100.0;
            $slow = $maxMs >= 250.0;
            if (!$repeated && !$slow && $failures === 0) {
                continue;
            }

            $reasons = array();
            if ($slow) {
                $reasons[] = 'slow';
            }
            if ($repeated) {
                $reasons[] = 'repeated';
            }
            if ($failures > 0) {
                $reasons[] = 'failed';
            }
            if (!empty($group['same_origin'])) {
                $reasons[] = 'same_origin';
            }

            $rows[] = array(
                'kind' => 'http_candidate',
                'confidence' => $failures > 0 || $maxMs >= 1000.0 || ($count >= 3 && $totalMs >= 1000.0) ? 'high' : 'medium',
                'method' => (string) $group['method'],
                'url' => (string) $group['url'],
                'ajax_action' => (string) $group['ajax_action'],
                'count' => $count,
                'total_ms' => round($totalMs, 3),
                'max_ms' => round($maxMs, 3),
                'failures' => $failures,
                'same_origin' => (bool) $group['same_origin'],
                'reasons' => $reasons,
                'source' => (array) $group['source'],
                'reason' => 'Outbound HTTP was slow, repeated, or failed during this request; inspect the owning call site and endpoint behavior before remediation.',
            );
        }

        usort($rows, static fn (array $a, array $b): int =>
            array((int) ($b['failures'] ?? 0), (float) ($b['total_ms'] ?? 0.0), (int) ($b['count'] ?? 0))
            <=>
            array((int) ($a['failures'] ?? 0), (float) ($a['total_ms'] ?? 0.0), (int) ($a['count'] ?? 0))
        );

        return array_slice($rows, 0, 30);
    }

    private function sameOriginUrl(string $url): bool
    {
        $parts = parse_url($url);
        $home = function_exists('home_url') ? parse_url((string) home_url('/')) : false;
        if (!is_array($parts) || !is_array($home)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $homeScheme = strtolower((string) ($home['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $homeHost = strtolower((string) ($home['host'] ?? ''));
        if ($scheme === '' || $host === '' || $scheme !== $homeScheme || $host !== $homeHost) {
            return false;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $homePort = isset($home['port']) ? (int) $home['port'] : ($homeScheme === 'https' ? 443 : 80);
        return $port === $homePort;
    }

    private function autoloadEvidence(): array
    {
        $previousSuppression = $this->suppressDiagnosticState;
        $this->suppressDiagnosticState = true;
        try {
            $result = (new DatabaseOptimizer())->optionsAutoload(array(
                'autoload' => true,
                'min_bytes' => 0,
                'page' => 1,
                'per_page' => 50,
            ));
        } catch (\Throwable) {
            $result = new \WP_Error('codi_mcp_diagnostic_autoload_failed', 'Autoload metadata could not be inspected.');
        } finally {
            $this->suppressDiagnosticState = $previousSuppression;
        }

        if ($result instanceof \WP_Error) {
            return array(
                'available' => false,
                'total_count' => 0,
                'total_bytes' => 0,
                'items' => array(),
                'truncated' => false,
            );
        }

        $items = array();
        foreach ((array) ($result['items'] ?? array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $option = (string) ($item['option'] ?? '');
            $access = is_array($this->optionAccesses[$option] ?? null) ? $this->optionAccesses[$option] : array();
            $items[] = array(
                'option' => $option,
                'bytes' => max(0, (int) ($item['bytes'] ?? 0)),
                'autoload' => (bool) ($item['autoload'] ?? false),
                'raw_autoload' => (string) ($item['raw_autoload'] ?? ''),
                'accesses' => max(0, (int) ($access['count'] ?? 0)),
                'source' => is_array($access['source'] ?? null) ? $access['source'] : $this->emptySource(),
            );
        }

        return array(
            'available' => true,
            'total_count' => max(0, (int) ($result['total_autoload_count'] ?? 0)),
            'total_bytes' => max(0, (int) ($result['total_autoload_bytes'] ?? 0)),
            'items' => $items,
            'truncated' => (int) ($result['total'] ?? 0) > count($items),
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function databaseOpportunities(array $patterns, array $explains, array $autoload): array
    {
        $rows = array();

        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }
            $duplicates = max(0, (int) ($pattern['duplicate_executions'] ?? 0));
            $timeMs = max(0.0, (float) ($pattern['time_ms'] ?? 0.0));
            $executions = max(0, (int) ($pattern['count'] ?? 0));
            $distinctBindings = max(0, (int) ($pattern['distinct_bindings'] ?? 0));

            if ($duplicates >= 2 && $timeMs >= 5.0) {
                $rows[] = array(
                    'kind' => 'cache_candidate',
                    'confidence' => $duplicates >= 5 && $timeMs >= 10.0 ? 'high' : 'medium',
                    'query' => (string) ($pattern['query'] ?? ''),
                    'time_ms' => round($timeMs, 3),
                    'executions' => $executions,
                    'distinct_bindings' => $distinctBindings,
                    'duplicate_executions' => $duplicates,
                    'source' => is_array($pattern['source'] ?? null) ? $pattern['source'] : $this->emptySource(),
                    'reason' => 'The same parameter binding was executed repeatedly during this request; review request-level or persistent caching at the owning call site.',
                );
            }
            if ($executions >= 5 && $distinctBindings >= 5 && $timeMs >= 10.0
                && $distinctBindings / max(1, $executions) >= 0.8) {
                $rows[] = array(
                    'kind' => 'batch_candidate',
                    'confidence' => $executions >= 20 && $timeMs >= 50.0 ? 'high' : 'medium',
                    'query' => (string) ($pattern['query'] ?? ''),
                    'time_ms' => round($timeMs, 3),
                    'executions' => $executions,
                    'distinct_bindings' => $distinctBindings,
                    'source' => is_array($pattern['source'] ?? null) ? $pattern['source'] : $this->emptySource(),
                    'reason' => 'The same query shape ran repeatedly with mostly distinct bindings; review whether the owning call site can batch, prime, or prefetch the lookups.',
                );
            }
        }

        foreach ($explains as $explain) {
            if (!is_array($explain) || (string) ($explain['error'] ?? '') !== '') {
                continue;
            }
            $scanRows = 0;
            foreach ((array) ($explain['plan'] ?? array()) as $plan) {
                if (!is_array($plan)
                    || strtolower((string) ($plan['type'] ?? '')) !== 'all'
                    || (string) ($plan['key'] ?? '') !== ''
                    || trim((string) ($plan['possible_keys'] ?? '')) !== '') {
                    continue;
                }
                $estimatedRows = max(0, (int) ($plan['rows'] ?? 0));
                if ($estimatedRows < 500) {
                    continue;
                }
                $scanRows = max($scanRows, $estimatedRows);
            }
            if ($scanRows === 0) {
                continue;
            }
            $rows[] = array(
                'kind' => 'index_candidate',
                'confidence' => $scanRows >= 5000 ? 'high' : 'medium',
                'query' => (string) ($explain['query'] ?? ''),
                'time_ms' => max(0.0, (float) ($explain['time_ms'] ?? 0.0)),
                'estimated_scan_rows' => $scanRows,
                'source' => is_array($explain['source'] ?? null) ? $explain['source'] : $this->emptySource(),
                'plan' => (array) ($explain['plan'] ?? array()),
                'reason' => 'EXPLAIN shows a material full scan with no selected or possible index; inspect predicates and current indexes before considering an index change.',
            );
        }

        if (!empty($autoload['available'])) {
            foreach ((array) ($autoload['items'] ?? array()) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $bytes = max(0, (int) ($item['bytes'] ?? 0));
                $accesses = max(0, (int) ($item['accesses'] ?? 0));
                if ($bytes < 32768 || $accesses !== 0) {
                    continue;
                }
                $rows[] = array(
                    'kind' => 'autoload_candidate',
                    'confidence' => $bytes >= 150000 ? 'medium' : 'low',
                    'option' => (string) ($item['option'] ?? ''),
                    'bytes' => $bytes,
                    'accesses' => $accesses,
                    'reason' => 'This relatively large autoloaded option was not explicitly requested during this capture; compare repeated representative captures before changing autoload.',
                );
            }
        }

        return array_slice($rows, 0, 50);
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
        $probe = $this->cachePersistenceProbe();
        $external = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        return array(
            'counters_available' => (bool) ($after['available'] ?? false),
            'implementation_class' => (string) ($after['implementation_class'] ?? ''),
            'external' => $external,
            'hits' => $hits,
            'misses' => $misses,
            'hit_ratio' => $total > 0 ? round($hits / $total, 4) : 0.0,
            'persistence_probe' => $probe,
            'opportunities' => $this->cacheOpportunities($external, $probe),
        );
    }

    private function diagnosticMetadata(): array
    {
        $config = is_array($this->authorization['config'] ?? null) ? (array) $this->authorization['config'] : array();
        return array(
            'profile' => (string) ($config['profile'] ?? 'none'),
            'configuration_fingerprint' => (string) ($config['configuration_fingerprint'] ?? ''),
            'include_assets' => (bool) ($config['include_assets'] ?? true),
            'hooks' => array_values(array_map('strval', (array) ($config['hooks'] ?? array()))),
            'capabilities' => array_values(array_map('strval', (array) ($config['capabilities'] ?? array()))),
            'explain_slow_queries' => (bool) ($config['explain_slow_queries'] ?? false),
            'callback_timing' => (bool) ($config['callback_timing'] ?? false),
        );
    }

    private function cacheOpportunities(bool $external, array $probe): array
    {
        if (!$external || empty($probe['attempted'])) {
            return array();
        }
        $classification = (string) ($probe['classification'] ?? '');
        if (!in_array($classification, array('read_unavailable', 'cross_request_miss', 'inconsistent', 'write_failed'), true)) {
            return array();
        }

        if ($classification === 'write_failed') {
            $kind = 'object_cache_probe_write_failed';
            $confidence = 'medium';
            $reason = 'An external object cache is active, but the bounded cache probe could not be written reliably.';
        } elseif ($classification === 'read_unavailable') {
            $kind = 'object_cache_read_unavailable';
            $confidence = 'high';
            $reason = 'An external object cache accepted the probe write, but a fresh set/get could not be read in the target request. This may be an intentional request-context bypass (for example admin cache disabled) or a runtime read failure; it does not prove backend persistence loss.';
        } else {
            $kind = 'object_cache_cross_request_miss';
            $confidence = $classification === 'inconsistent' ? 'high' : 'medium';
            $reason = 'An external object cache accepted the probe write and reads work in the target request, but the prior-request probe was not returned. This proves a cross-request miss, not its cause; investigate group persistence, eviction, configuration, and backend health.';
        }

        return array(array(
            'kind' => $kind,
            'confidence' => $confidence,
            'classification' => $classification,
            'reason' => $reason,
        ));
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
            $callbackCount = 0;
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
                    $callbackCount++;
                    if (count($callbacks) < 100) {
                        $callbacks[] = array(
                            'priority' => (int) $priority,
                            'accepted_args' => (int) ($registration['accepted_args'] ?? 1),
                            'source' => $this->attribution->fromCallable($callback),
                        );
                    }
                }
            }
            $costs = (array) ($this->hookSourceCosts[$hook] ?? array());
            foreach ($this->httpRequests as $http) {
                $source = is_array($http['source'] ?? null) ? (array) $http['source'] : array();
                if ((string) ($source['hook'] ?? '') === $hook) {
                    $this->aggregateHookSourceCost($costs, $source, 0, 0.0, 1, 0);
                }
            }
            foreach ($this->stateChanges as $state) {
                $source = is_array($state['source'] ?? null) ? (array) $state['source'] : array();
                if ((string) ($source['hook'] ?? '') === $hook) {
                    $this->aggregateHookSourceCost($costs, $source, 0, 0.0, 0, 1);
                }
            }
            $costRows = array_values($costs);
            usort($costRows, static fn (array $a, array $b): int => array(
                (int) ($b['queries'] ?? 0),
                (float) ($b['query_time_ms'] ?? 0.0),
                (int) ($b['http_requests'] ?? 0),
                (int) ($b['state_changes'] ?? 0),
            ) <=> array(
                (int) ($a['queries'] ?? 0),
                (float) ($a['query_time_ms'] ?? 0.0),
                (int) ($a['http_requests'] ?? 0),
                (int) ($a['state_changes'] ?? 0),
            ));
            foreach ($costRows as &$costRow) {
                $costRow['query_time_ms'] = round((float) ($costRow['query_time_ms'] ?? 0.0), 3);
            }
            unset($costRow);
            $timing = (array) ($this->hookTimings[$hook] ?? array());
            $row = array(
                'hook' => $hook,
                'execution' => array(
                    'executions' => (int) ($timing['executions'] ?? 0),
                    'time_ms' => round((float) ($timing['time_ms'] ?? 0.0), 3),
                    'max_ms' => round((float) ($timing['max_ms'] ?? 0.0), 3),
                    'queries' => (int) ($timing['queries'] ?? 0),
                    'http_requests' => (int) ($timing['http_requests'] ?? 0),
                ),
                'callback_count' => $callbackCount,
                'callbacks_returned' => count($callbacks),
                'callbacks_truncated' => $callbackCount > count($callbacks),
                'cost_sources' => array_slice($costRows, 0, 50),
                'callbacks' => $callbacks,
            );
            if ($this->callbackProfiler !== null) {
                $row['callback_timing'] = $this->callbackProfiler->report($hook);
            }
            $rows[] = $row;
        }
        return array('items' => $rows);
    }

    private function aggregateHookQueryCost(array $source, float $timeMs): void
    {
        $hook = (string) ($source['hook'] ?? '');
        $requested = array_values(array_map('strval', (array) ($this->authorization['config']['hooks'] ?? array())));
        if ($hook === '' || !in_array($hook, $requested, true)) {
            return;
        }
        $costs = (array) ($this->hookSourceCosts[$hook] ?? array());
        $this->aggregateHookSourceCost($costs, $source, 1, $timeMs, 0, 0);
        $this->hookSourceCosts[$hook] = $costs;
    }

    private function aggregateCallbackQueryCost(array &$costs, array $source, float $queryTimeMs): void
    {
        $key = hash('sha256', implode('|', array(
            (string) ($source['component_type'] ?? ''),
            (string) ($source['component'] ?? ''),
            (string) ($source['file'] ?? ''),
            (string) ($source['line'] ?? 0),
            (string) ($source['callable'] ?? ''),
            (string) ($source['hook'] ?? ''),
        )));
        if (!isset($costs[$key])) {
            $costs[$key] = array(
                'source' => $source,
                'queries' => 0,
                'query_time_ms' => 0.0,
            );
        }
        $costs[$key]['queries']++;
        $costs[$key]['query_time_ms'] += max(0.0, $queryTimeMs);
    }

    private function aggregateHookSourceCost(array &$costs, array $source, int $queries, float $queryTimeMs, int $httpRequests, int $stateChanges): void
    {
        $component = (string) ($source['component'] ?? '');
        $callable = (string) ($source['callable'] ?? '');
        $file = (string) ($source['file'] ?? '');
        $line = (int) ($source['line'] ?? 0);
        $key = hash('sha256', $component . '|' . $callable . '|' . $file . '|' . $line);
        if (!isset($costs[$key])) {
            $costs[$key] = array(
                'component' => $component !== '' ? $component : (string) ($source['component_type'] ?? 'unknown'),
                'callable' => $callable,
                'file' => $file,
                'line' => $line,
                'queries' => 0,
                'query_time_ms' => 0.0,
                'http_requests' => 0,
                'state_changes' => 0,
            );
        }
        $costs[$key]['queries'] += $queries;
        $costs[$key]['query_time_ms'] += $queryTimeMs;
        $costs[$key]['http_requests'] += $httpRequests;
        $costs[$key]['state_changes'] += $stateChanges;
    }

    private function cachePersistenceProbe(): array
    {
        $config = is_array($this->authorization['config'] ?? null) ? (array) $this->authorization['config'] : array();
        $configured = is_array($config['cache_probes'] ?? null) ? (array) $config['cache_probes'] : array();

        // Preserve compatibility with captures authorized by older runners.
        if ($configured === array() && is_array($config['cache_probe'] ?? null)) {
            $legacy = (array) $config['cache_probe'];
            $legacy['group'] = 'codi_mcp_diagnostics';
            $legacy['prepared'] = true;
            $configured = array('diagnostic' => $legacy);
        }

        $supported = function_exists('wp_cache_get') && function_exists('wp_cache_delete');
        if ($configured === array()) {
            return array(
                'attempted' => false,
                'supported' => $supported,
                'persisted' => null,
                'classification' => 'not_attempted',
                'groups' => array(),
            );
        }
        if (!$supported) {
            return array(
                'attempted' => true,
                'supported' => false,
                'persisted' => null,
                'classification' => 'unsupported',
                'groups' => array(),
            );
        }

        $groups = array();
        foreach ($configured as $name => $probe) {
            $probe = is_array($probe) ? $probe : array();
            $group = substr((string) ($probe['group'] ?? ''), 0, 191);
            $prepared = !empty($probe['prepared']);
            $key = (string) ($probe['key'] ?? '');
            $expected = (string) ($probe['value_hash'] ?? '');
            $persisted = null;

            if ($prepared && $group !== '' && $key !== '' && $expected !== '') {
                $found = null;
                try {
                    $value = wp_cache_get($key, $group, false, $found);
                    wp_cache_delete($key, $group);
                    $persisted = $found === true && is_string($value) && hash_equals($expected, hash('sha256', $value));
                } catch (\Throwable) {
                    $persisted = false;
                }
            }

            $groups[(string) $name] = array(
                'group' => $group,
                'set_succeeded' => $prepared,
                'persisted' => $persisted,
                'readable_now' => $this->cacheGroupReadableNow($group),
            );
        }

        $diagnostic = is_array($groups['diagnostic'] ?? null) ? $groups['diagnostic'] : array();
        $core = is_array($groups['core_options'] ?? null) ? $groups['core_options'] : array();
        $corePrepared = !empty($core['set_succeeded']);
        $diagnosticPrepared = !empty($diagnostic['set_succeeded']);
        $corePersisted = $corePrepared ? ($core['persisted'] ?? null) : null;
        $diagnosticPersisted = $diagnosticPrepared ? ($diagnostic['persisted'] ?? null) : null;
        $coreReadableNow = $core['readable_now'] ?? null;
        $diagnosticReadableNow = $diagnostic['readable_now'] ?? null;

        if (!$corePrepared && !$diagnosticPrepared) {
            $classification = 'write_failed';
            $persisted = null;
        } elseif ($corePrepared && $corePersisted === true && (!$diagnosticPrepared || $diagnosticPersisted === true)) {
            $classification = 'persistent';
            $persisted = true;
        } elseif ($corePrepared && $corePersisted === true && $diagnosticPrepared && $diagnosticPersisted === false) {
            $classification = 'diagnostic_group_non_persistent';
            $persisted = true;
        } elseif ($corePrepared && $corePersisted === false) {
            if ($coreReadableNow === false) {
                $classification = 'read_unavailable';
            } else {
                $classification = $diagnosticPersisted === true ? 'inconsistent' : 'cross_request_miss';
            }
            $persisted = false;
        } else {
            if ($diagnosticPersisted === true) {
                $classification = 'persistent';
            } elseif ($diagnosticReadableNow === false) {
                $classification = 'read_unavailable';
            } else {
                $classification = 'cross_request_miss';
            }
            $persisted = $diagnosticPersisted;
        }

        return array(
            'attempted' => true,
            'supported' => true,
            'persisted' => $persisted,
            'classification' => $classification,
            'groups' => $groups,
        );
    }

    private function cacheGroupReadableNow(string $group): ?bool
    {
        if ($group === '' || !function_exists('wp_cache_set') || !function_exists('wp_cache_get') || !function_exists('wp_cache_delete')) {
            return null;
        }

        try {
            $key = 'codi_read_probe_' . bin2hex(random_bytes(12));
            $value = bin2hex(random_bytes(24));
            if (!wp_cache_set($key, $value, $group, 30)) {
                return null;
            }
            $found = null;
            $read = wp_cache_get($key, $group, false, $found);
            wp_cache_delete($key, $group);
            return $found === true && is_string($read) && hash_equals($value, $read);
        } catch (\Throwable) {
            return false;
        }
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
        $path = $this->safePath((string) ($parts['path'] ?? '/'));
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
        $path = $this->safePath((string) ($parts['path'] ?? ''));
        $query = $this->queryKeys((string) ($parts['query'] ?? ''));
        $prefix = $host !== '' ? ($scheme !== '' ? $scheme . '://' : '//') . $host . $port : '';
        return substr($prefix . $path . ($query !== '' ? '?' . $query : ''), 0, 1000);
    }

    private function safePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $markers = array(
            'token',
            'access-token',
            'refresh-token',
            'client-secret',
            'api-key',
            'secret',
            'nonce',
            'session',
            'credential',
            'credentials',
            'license-key',
            'licence-key',
            'private-key',
            'activation-key',
            'consumer-secret',
            'webhook-secret',
            'signing-key',
            'encryption-key',
        );
        $licenseActions = array('verify', 'validate', 'activate', 'activation', 'check');
        $licenseMarkers = array('license', 'licenses', 'licence', 'licences');

        foreach ($segments as $index => $segment) {
            if ($segment === '' || $index === 0) {
                continue;
            }
            $previous = $this->pathMarker((string) ($segments[$index - 1] ?? ''));
            $previousPrevious = $this->pathMarker((string) ($segments[$index - 2] ?? ''));
            $sensitive = in_array($previous, $markers, true)
                || (in_array($previousPrevious, $licenseMarkers, true) && in_array($previous, $licenseActions, true));
            if ($sensitive) {
                $segments[$index] = '[redacted]';
            }
        }

        return implode('/', $segments);
    }

    private function pathMarker(string $segment): string
    {
        $decoded = strtolower(rawurldecode($segment));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $decoded) ?? '';
        return trim($normalized, '-');
    }

    private function ajaxAction(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !str_ends_with((string) ($parts['path'] ?? ''), '/wp-admin/admin-ajax.php')) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $homeParts = function_exists('home_url') ? parse_url((string) home_url('/')) : false;
        $homeHost = is_array($homeParts) ? strtolower((string) ($homeParts['host'] ?? '')) : '';
        if ($host === '' || $homeHost === '' || $host !== $homeHost) {
            return '';
        }

        foreach (explode('&', (string) ($parts['query'] ?? '')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (rawurldecode($key) !== 'action') {
                continue;
            }
            $action = rawurldecode($value);
            return strlen($action) <= 191 && preg_match('/^[a-z0-9_-]+$/', $action) === 1 ? $action : '';
        }
        return '';
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