<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Diagnostics\DiagnosticComparator;
use CodiMcp\Packages\Diagnostics\DiagnosticRunner;
use CodiMcp\Packages\Diagnostics\DiagnosticRuntime;
use CodiMcp\Packages\Diagnostics\DiagnosticStore;
use CodiMcp\Packages\Diagnostics\Package;
use CodiMcp\Packages\Diagnostics\QuerySanitizer;
use CodiMcp\Core\SourceAttribution;
use CodiMcpTest\Framework\TestCase;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return (bool) ($GLOBALS['codi_mcp_test_is_admin'] ?? false);
    }
}

final class DiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
        $GLOBALS['codi_mcp_test_is_admin'] = false;
        unset($_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN']);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    public function test_query_sanitizer_masks_literals_without_destroying_query_shape(): void
    {
        $shape = (new QuerySanitizer())->shape("SELECT p.ID FROM `wp_posts` p WHERE p.ID = 42 AND p.post_title = 'Private title' AND p.post_status = \"draft\"");

        $this->assertTrue(str_contains($shape, 'SELECT p.ID FROM `wp_posts`'));
        $this->assertTrue(str_contains($shape, 'p.ID = ?'));
        $this->assertFalse(str_contains($shape, 'Private title'));
        $this->assertFalse(str_contains($shape, 'draft'));
    }

    public function test_source_attribution_reports_outer_application_hook_not_observer_hook(): void
    {
        $GLOBALS['wp_current_filter'] = array('admin_init', 'log_query_custom_data');
        $source = (new SourceAttribution())->fromBacktrace();
        $this->assertSame('admin_init', (string) ($source['hook'] ?? ''));
        unset($GLOBALS['wp_current_filter']);
    }

    public function test_diagnostic_authorization_is_target_bound_and_one_time(): void
    {
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/wp-admin/edit.php?post_type=page');
        $this->assertFalse($issued instanceof \WP_Error);

        $mismatch = $store->consume((string) $issued['token'], '/wp-admin/plugins.php', 'GET');
        $this->assertTrue($mismatch instanceof \WP_Error);

        $replay = $store->consume((string) $issued['token'], '/wp-admin/edit.php?post_type=page', 'GET');
        $this->assertTrue($replay instanceof \WP_Error);
    }

    public function test_armed_authorization_is_method_bound_and_wrong_method_consumes_it(): void
    {
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/target', array(), null, 'POST', 'request');
        $this->assertFalse($issued instanceof \WP_Error);

        $mismatch = $store->consume((string) $issued['token'], '/target', 'GET');
        $this->assertTrue($mismatch instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_method_mismatch', $mismatch->get_error_code());

        $replay = $store->consume((string) $issued['token'], '/target', 'POST');
        $this->assertTrue($replay instanceof \WP_Error);
    }

    public function test_diagnostic_authorization_fails_closed_when_one_time_consumption_cannot_be_persisted(): void
    {
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/target');
        $this->assertFalse($issued instanceof \WP_Error);

        $GLOBALS['codi_mcp_test_delete_transient_failures'] = 1;
        $result = $store->consume((string) $issued['token'], '/target', 'GET');

        $this->assertTrue($result instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_token_consume_failed', $result->get_error_code());
    }

    public function test_runtime_establishes_authorized_user_without_auth_cookies(): void
    {
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/target');
        $this->assertFalse($issued instanceof \WP_Error);

        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 0;
        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/target';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $this->assertSame(7, (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0));
        $this->assertSame(7, (int) apply_filters('determine_current_user', 0));
        $runtime->finish();
    }

    public function test_runtime_forces_anonymous_effective_actor(): void
    {
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/anonymous-runtime', array(), 0);
        $this->assertFalse($issued instanceof \WP_Error);

        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/anonymous-runtime';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $this->assertSame(0, (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? -1));
        $this->assertSame(0, (int) apply_filters('determine_current_user', 7));
        $runtime->finish();
    }

    public function test_armed_request_uses_exact_method_and_preserves_request_identity(): void
    {
        $store = new DiagnosticStore();
        $armed = (new DiagnosticRunner($store))->arm(array(
            'url' => '/armed-target?mode=save',
            'method' => 'POST',
            'include_assets' => false,
        ));
        $this->assertFalse($armed instanceof \WP_Error);
        $this->assertSame('POST', (string) $armed['method']);
        $this->assertSame('/armed-target?mode=save', (string) $armed['target']);
        $this->assertSame('X-Codi-Diagnostic-Token', (string) $armed['authorization']['header_name']);

        $GLOBALS['codi_mcp_test_wp_users'][8] = array(
            'user_login' => 'fixture-request-user',
            'sites' => array(1 => array('read' => true)),
        );
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 8;
        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $armed['authorization']['header_value'];
        $_SERVER['REQUEST_URI'] = '/armed-target?mode=save';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();
        $this->assertSame(8, (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0));
        $this->assertSame(8, (int) apply_filters('determine_current_user', 8));
        $runtime->finish();

        $capture = $store->get((string) $armed['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $this->assertSame('POST', (string) ($capture['overview']['request']['method'] ?? ''));
        $this->assertSame(8, (int) ($capture['overview']['request']['user_id'] ?? 0));
    }

    public function test_diagnostic_profiles_expand_hooks_and_fingerprint_normalized_configuration(): void
    {
        $store = new DiagnosticStore();
        $runner = new DiagnosticRunner($store);
        $first = $runner->arm(array(
            'url' => '/profile-a',
            'method' => 'GET',
            'profile' => 'admin',
            'hooks' => array('fixture_second', 'fixture_first'),
            'capabilities' => array('edit_posts', 'manage_options'),
            'callback_timing' => true,
        ));
        $second = $runner->arm(array(
            'url' => '/profile-b',
            'method' => 'GET',
            'profile' => 'admin',
            'hooks' => array('fixture_first', 'fixture_second'),
            'capabilities' => array('manage_options', 'edit_posts'),
            'callback_timing' => true,
        ));
        $this->assertFalse($first instanceof \WP_Error);
        $this->assertFalse($second instanceof \WP_Error);

        $firstAuth = $store->consume((string) $first['authorization']['header_value'], '/profile-a', 'GET');
        $secondAuth = $store->consume((string) $second['authorization']['header_value'], '/profile-b', 'GET');
        $this->assertFalse($firstAuth instanceof \WP_Error);
        $this->assertFalse($secondAuth instanceof \WP_Error);
        $firstConfig = (array) ($firstAuth['config'] ?? array());
        $secondConfig = (array) ($secondAuth['config'] ?? array());

        $this->assertSame('admin', (string) ($firstConfig['profile'] ?? ''));
        $this->assertContains('admin_init', (array) ($firstConfig['hooks'] ?? array()));
        $this->assertContains('fixture_first', (array) ($firstConfig['hooks'] ?? array()));
        $this->assertTrue((bool) ($firstConfig['callback_timing'] ?? false));
        $this->assertSame(
            (string) ($firstConfig['configuration_fingerprint'] ?? ''),
            (string) ($secondConfig['configuration_fingerprint'] ?? '')
        );

        $third = $runner->arm(array(
            'url' => '/profile-c',
            'method' => 'GET',
            'profile' => 'admin',
            'hooks' => array('fixture_first', 'fixture_second'),
            'capabilities' => array('manage_options', 'edit_posts'),
            'callback_timing' => false,
        ));
        $this->assertFalse($third instanceof \WP_Error);
        $thirdAuth = $store->consume((string) $third['authorization']['header_value'], '/profile-c', 'GET');
        $this->assertFalse($thirdAuth instanceof \WP_Error);
        $thirdConfig = (array) ($thirdAuth['config'] ?? array());
        $this->assertFalse(
            (string) ($firstConfig['configuration_fingerprint'] ?? '') === (string) ($thirdConfig['configuration_fingerprint'] ?? '')
        );

        $invalid = $runner->arm(array('url' => '/profile-invalid', 'method' => 'GET', 'profile' => 'unknown'));
        $this->assertTrue($invalid instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_profile_invalid', $invalid->get_error_code());
    }

    public function test_runner_binds_effective_actor_separately_from_authorizing_user(): void
    {
        $GLOBALS['codi_mcp_test_wp_users'][8] = array(
            'user_login' => 'fixture-editor',
            'sites' => array(1 => array('read' => true)),
        );
        $store = new DiagnosticStore();
        $url = 'https://example.test/actor-target';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            $authorization = $store->consume((string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? ''), '/actor-target', 'GET');
            if ($authorization instanceof \WP_Error) {
                return array('response' => array('code' => 403), 'body' => '');
            }
            if ((int) ($authorization['authorized_by_user_id'] ?? 0) !== 7 || (int) ($authorization['user_id'] ?? -1) !== 8) {
                return array('response' => array('code' => 403), 'body' => '');
            }
            $store->save((string) $authorization['diagnostic_id'], array(
                'overview' => array(
                    'request' => array('url' => '/actor-target', 'status' => 200, 'duration_ms' => 1.0, 'peak_memory_bytes' => 1, 'type' => 'frontend', 'user_id' => 8),
                    'summary' => array(),
                ),
            ));
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $result = (new DiagnosticRunner($store))->run(array('url' => '/actor-target', 'actor_user_id' => 8));
        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame(200, (int) $result['request']['status']);
        $this->assertSame(8, (int) $result['request']['effective_user_id']);
    }

    public function test_runner_supports_anonymous_actor_and_rejects_non_member_actor(): void
    {
        $store = new DiagnosticStore();
        $url = 'https://example.test/anonymous-target';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            $authorization = $store->consume((string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? ''), '/anonymous-target', 'GET');
            if ($authorization instanceof \WP_Error || (int) ($authorization['user_id'] ?? -1) !== 0) {
                return array('response' => array('code' => 403), 'body' => '');
            }
            $store->save((string) $authorization['diagnostic_id'], array(
                'overview' => array(
                    'request' => array('url' => '/anonymous-target', 'status' => 200, 'duration_ms' => 1.0, 'peak_memory_bytes' => 1, 'type' => 'frontend'),
                    'summary' => array(),
                ),
            ));
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $anonymous = (new DiagnosticRunner($store))->run(array('url' => '/anonymous-target', 'actor_user_id' => 0));
        $this->assertFalse($anonymous instanceof \WP_Error);
        $this->assertSame(0, (int) $anonymous['request']['effective_user_id']);

        $GLOBALS['codi_mcp_test_wp_users'][9] = array('user_login' => 'outsider', 'sites' => array(2 => array('read' => true)));
        $rejected = (new DiagnosticRunner(new DiagnosticStore()))->run(array('url' => '/anonymous-target', 'actor_user_id' => 9));
        $this->assertTrue($rejected instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_actor_not_member', $rejected->get_error_code());
    }

    public function test_runner_rejects_off_origin_targets_without_requesting_them(): void
    {
        $runner = new DiagnosticRunner(new DiagnosticStore());
        $result = $runner->run(array('url' => 'https://attacker.example/path'));

        $this->assertTrue($result instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_url_off_origin', $result->get_error_code());
        $this->assertCount(0, (array) $GLOBALS['codi_mcp_test_wp_remote_get_calls']);
    }

    public function test_runner_uses_one_time_same_origin_request_and_returns_summary(): void
    {
        $store = new DiagnosticStore();
        $url = 'https://example.test/wp-admin/edit.php?post_type=page';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            $token = (string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? '');
            $authorization = $store->consume($token, '/wp-admin/edit.php?post_type=page', 'GET');
            if ($authorization instanceof \WP_Error) {
                return array('response' => array('code' => 403), 'body' => '');
            }
            $store->save((string) $authorization['diagnostic_id'], array(
                'overview' => array(
                    'request' => array(
                        'url' => '/wp-admin/edit.php?post_type',
                        'status' => 200,
                        'duration_ms' => 123.4,
                        'peak_memory_bytes' => 123456,
                        'type' => 'admin',
                    ),
                    'summary' => array(
                        'errors' => 1,
                        'wp_errors' => 0,
                        'queries' => 42,
                        'query_time_ms' => 12.5,
                        'state_changes' => 2,
                        'http_requests' => 1,
                        'redirects' => 0,
                        'assets' => 8,
                        'correlations' => 3,
                    ),
                ),
                'errors' => array('php' => array()),
            ));
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $result = (new DiagnosticRunner($store))->run(array('url' => '/wp-admin/edit.php?post_type=page'));

        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame(42, (int) $result['summary']['queries']);
        $this->assertSame(3, (int) $result['summary']['correlations']);
        $this->assertSame('admin', (string) $result['request']['type']);
        $this->assertContains('errors', (array) $result['available_sections']);
        $this->assertSame(1, count((array) $GLOBALS['codi_mcp_test_wp_remote_get_calls']));
        $call = $GLOBALS['codi_mcp_test_wp_remote_get_calls'][0];
        $this->assertSame(0, (int) ($call['args']['redirection'] ?? -1));
        $this->assertTrue((string) ($call['args']['headers']['X-Codi-Diagnostic-Token'] ?? '') !== '');
    }

    public function test_runtime_captures_semantic_events_and_redacts_query_literals(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/diagnostic-target?foo=secret', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array(),
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/diagnostic-target?foo=secret';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $this->assertSame(7, (int) apply_filters('determine_current_user', 0));
        do_action('updated_option', 'example_setting', 'old', 'new-value');
        do_action('set_transient', 'example_cache', 'private-cache-value', 60);
        do_action('wp_error_added', 'example_error', 'Failure token=supersecret', null, new \WP_Error('example_error', 'Failure'));
        apply_filters('wp_redirect', 'https://example.test/next?token=secret&ok=yes', 302);

        $httpArgs = apply_filters('http_request_args', array('method' => 'POST'), 'https://api.example.test/v1/items?api_key=secret&page=2');
        do_action('http_api_debug', array('response' => array('code' => 201)), 'response', 'WpOrg\\Requests\\Requests', $httpArgs, 'https://api.example.test/v1/items?api_key=secret&page=2');

        $ajaxUrl = home_url('/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=supersecret');
        $ajaxArgs = apply_filters('http_request_args', array('method' => 'POST'), $ajaxUrl);
        usleep(260000);
        do_action('http_api_debug', array('response' => array('code' => 200)), 'response', 'WpOrg\\Requests\\Requests', $ajaxArgs, $ajaxUrl);

        $offOriginAjaxUrl = 'https://api.example.test/wp-admin/admin-ajax.php?action=external_secret&nonce=secret';
        $offOriginAjaxArgs = apply_filters('http_request_args', array('method' => 'POST'), $offOriginAjaxUrl);
        do_action('http_api_debug', array('response' => array('code' => 200)), 'response', 'WpOrg\\Requests\\Requests', $offOriginAjaxArgs, $offOriginAjaxUrl);

        $licenseUrl = 'https://database.example.test/api/v2/license/verify/3A57jRlRYyNQ2tas';
        $licenseArgs = apply_filters('http_request_args', array('method' => 'GET'), $licenseUrl);
        do_action('http_api_debug', array('response' => array('code' => 200)), 'response', 'WpOrg\\Requests\\Requests', $licenseArgs, $licenseUrl);

        $queryStart = microtime(true);
        $queryData = apply_filters(
            'log_query_custom_data',
            array(),
            "SELECT * FROM wp_posts WHERE ID = 42 AND post_title = 'Private title'",
            0.012,
            'Fixture::query',
            $queryStart
        );
        $wpdb->queries[] = array(
            "SELECT * FROM wp_posts WHERE ID = 42 AND post_title = 'Private title'",
            0.012,
            'Fixture::query',
            $queryStart,
            $queryData,
        );

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);

        $this->assertFalse(false === $capture);
        $this->assertSame(1, (int) $capture['database']['count']);
        $this->assertSame(2, count((array) $capture['state']['items']));
        $this->assertSame(4, count((array) $capture['http']['items']));
        $this->assertSame(1, count((array) $capture['redirects']['items']));
        $this->assertSame(1, count((array) $capture['errors']['wp_error']));
        $slowQuery = (string) ($capture['database']['slowest'][0]['query'] ?? '');
        $this->assertFalse(str_contains($slowQuery, 'Private title'));
        $this->assertFalse(str_contains((string) $capture['errors']['wp_error'][0]['message'], 'supersecret'));
        $this->assertFalse(str_contains((string) $capture['http']['items'][0]['url'], 'secret'));
        $this->assertSame('', (string) ($capture['http']['items'][0]['ajax_action'] ?? ''));
        $this->assertSame('as_async_request_queue_runner', (string) ($capture['http']['items'][1]['ajax_action'] ?? ''));
        $this->assertTrue(str_ends_with((string) $capture['http']['items'][1]['url'], '/wp-admin/admin-ajax.php?action&nonce'));
        $this->assertFalse(str_contains((string) $capture['http']['items'][1]['url'], 'as_async_request_queue_runner'));
        $this->assertFalse(str_contains((string) $capture['http']['items'][1]['url'], 'supersecret'));
        $this->assertSame('', (string) ($capture['http']['items'][2]['ajax_action'] ?? ''));
        $this->assertFalse(str_contains((string) $capture['http']['items'][2]['url'], 'external_secret'));
        $this->assertSame(
            'https://database.example.test/api/v2/license/verify/[redacted]',
            (string) ($capture['http']['items'][3]['url'] ?? '')
        );
        $this->assertFalse(str_contains((string) $capture['http']['items'][3]['url'], '3A57jRlRYyNQ2tas'));
        $httpOpportunity = (array) ($capture['http']['opportunities'][0] ?? array());
        $this->assertSame('http_candidate', (string) ($httpOpportunity['kind'] ?? ''));
        $this->assertTrue((bool) ($httpOpportunity['same_origin'] ?? false));
        $this->assertSame('as_async_request_queue_runner', (string) ($httpOpportunity['ajax_action'] ?? ''));
        $this->assertFalse(str_contains((string) ($httpOpportunity['url'] ?? ''), 'supersecret'));
        $this->assertSame('/diagnostic-target?foo', (string) $capture['overview']['request']['url']);
    }

    public function test_preexisting_savequeries_log_is_included_without_codi_setup_queries(): void
    {
        global $wpdb;
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        $preStart = microtime(true) - 0.050;
        $wpdb = (object) array(
            'queries' => array(
                array(
                    "SELECT option_value FROM wp_options WHERE option_name = 'early_setting'",
                    0.004,
                    'wp_load_alloptions, get_option',
                    $preStart,
                ),
            ),
            'num_queries' => 1,
        );

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/pre-query-target', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array(),
        ));
        $this->assertFalse($issued instanceof \WP_Error);
        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/pre-query-target';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $sql = "SELECT ID FROM wp_posts WHERE post_status = 'publish'";
        $queryStart = microtime(true);
        $queryData = apply_filters('log_query_custom_data', array(), $sql, 0.006, 'Fixture::postBootstrap', $queryStart);
        $wpdb->queries[] = array($sql, 0.006, 'Fixture::postBootstrap', $queryStart, $queryData);
        $wpdb->num_queries = 2;

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $database = (array) ($capture['database'] ?? array());

        $this->assertSame('full_available_savequeries_log', (string) ($database['capture_scope'] ?? ''));
        $this->assertSame(2, (int) ($database['count'] ?? 0));
        $this->assertSame(1, (int) ($database['pre_diagnostics_queries_included'] ?? 0));
        $this->assertSame(1, (int) ($database['phases']['pre_diagnostics']['count'] ?? 0));
        $this->assertSame(1, (int) ($database['phases']['instrumented']['count'] ?? 0));

        $phases = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['capture_phase'] ?? ''),
            (array) ($database['slowest'] ?? array())
        )));
        sort($phases);
        $this->assertSame(array('instrumented', 'pre_diagnostics'), $phases);
        $this->assertTrue(str_contains(
            (string) ($database['slowest'][1]['source']['callable'] ?? $database['slowest'][0]['source']['callable'] ?? ''),
            'get_option'
        ) || str_contains(
            (string) ($database['slowest'][0]['source']['callable'] ?? ''),
            'get_option'
        ));
    }

    public function test_opt_in_slow_query_explain_persists_only_sanitized_shape_and_plan(): void
    {
        global $wpdb;
        $wpdb = new class {
            public array $queries = array();
            public int $num_queries = 0;
            public string $last_error = '';
            public array $explained = array();

            public function get_results(string $sql, mixed $format = null): array
            {
                $this->explained[] = $sql;
                usleep(50000);
                return array(array(
                    'id' => 1,
                    'select_type' => 'SIMPLE',
                    'table' => 'wp_posts',
                    'type' => 'ref',
                    'possible_keys' => 'type_status_date',
                    'key' => 'type_status_date',
                    'key_len' => '164',
                    'ref' => 'const,const',
                    'rows' => 12,
                    'filtered' => 100.0,
                    'Extra' => 'Using where',
                ));
            }
        };

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/explain-target', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array(),
            'explain_slow_queries' => true,
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/explain-target';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $sql = "SELECT ID FROM wp_posts WHERE post_title = 'Private title' AND post_status = 'draft'";
        $queryStart = microtime(true);
        $queryData = apply_filters('log_query_custom_data', array(), $sql, 0.025, 'Fixture::slowQuery', $queryStart);
        $wpdb->queries[] = array($sql, 0.025, 'Fixture::slowQuery', $queryStart, $queryData);
        $wpdb->num_queries = 1;

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $explains = (array) ($capture['database']['explains'] ?? array());
        $this->assertSame(1, count($explains));
        $this->assertFalse(str_contains((string) $explains[0]['query'], 'Private title'));
        $this->assertFalse(str_contains((string) $explains[0]['query'], 'draft'));
        $this->assertSame('type_status_date', (string) ($explains[0]['plan'][0]['key'] ?? ''));
        $this->assertSame(12, (int) ($explains[0]['plan'][0]['rows'] ?? 0));
        $this->assertSame('', (string) ($explains[0]['error'] ?? 'missing'));
        $captureBuildMs = (float) ($capture['overview']['diagnostic']['overhead']['capture_build_ms'] ?? 0.0);
        $targetDurationMs = (float) ($capture['overview']['request']['duration_ms'] ?? 0.0);
        $this->assertTrue($captureBuildMs >= 40.0);
        $this->assertTrue($targetDurationMs < $captureBuildMs);
    }



    public function test_index_opportunity_requires_full_scan_without_any_possible_index(): void
    {
        global $wpdb;
        $wpdb = new class {
            public array $queries = array();
            public int $num_queries = 0;
            public string $last_error = '';

            public function get_results(string $sql, $format = null): array
            {
                if (!str_starts_with($sql, 'EXPLAIN ')) {
                    return array();
                }
                return array(
                    array(
                        'id' => 1,
                        'select_type' => 'SIMPLE',
                        'table' => 'wp_options',
                        'type' => 'ALL',
                        'possible_keys' => 'autoload',
                        'key' => null,
                        'key_len' => null,
                        'ref' => null,
                        'rows' => 9000,
                        'filtered' => 90.0,
                        'Extra' => 'Using where',
                    ),
                    array(
                        'id' => 1,
                        'select_type' => 'SIMPLE',
                        'table' => 'custom_metrics',
                        'type' => 'ALL',
                        'possible_keys' => null,
                        'key' => null,
                        'key_len' => null,
                        'ref' => null,
                        'rows' => 800,
                        'filtered' => 10.0,
                        'Extra' => 'Using where',
                    ),
                );
            }
        };

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/index-opportunity', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array(),
            'explain_slow_queries' => true,
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/index-opportunity';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $sql = "SELECT metric_value FROM custom_metrics WHERE metric_key = 'private-key'";
        $queryStart = microtime(true);
        $queryData = apply_filters('log_query_custom_data', array(), $sql, 0.020, 'Fixture::indexOpportunity', $queryStart);
        $wpdb->queries[] = array($sql, 0.020, 'Fixture::indexOpportunity', $queryStart, $queryData);
        $wpdb->num_queries = 1;

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);

        $opportunities = array_values(array_filter(
            (array) ($capture['database']['opportunities'] ?? array()),
            static fn (array $row): bool => (string) ($row['kind'] ?? '') === 'index_candidate'
        ));
        $this->assertSame(1, count($opportunities));
        $this->assertSame(800, (int) ($opportunities[0]['estimated_scan_rows'] ?? 0));
        $this->assertSame('medium', (string) ($opportunities[0]['confidence'] ?? ''));
        $this->assertFalse(str_contains((string) ($opportunities[0]['query'] ?? ''), 'private-key'));
    }
    public function test_target_hook_timing_sentinels_span_the_integer_priority_range(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/sentinel-target', array(
            'include_assets' => false,
            'hooks' => array('example_hook'),
            'capabilities' => array(),
            'callback_timing' => true,
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/sentinel-target';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $priorities = array();
        foreach ((array) ($GLOBALS['codi_mcp_test_filters']['example_hook'] ?? array()) as $registration) {
            $callback = $registration['callback'] ?? null;
            if (!is_array($callback) || ($callback[0] ?? null) !== $runtime) {
                continue;
            }
            $method = (string) ($callback[1] ?? '');
            if (in_array($method, array('targetHookStart', 'targetHookEnd'), true)) {
                $priorities[$method] = (int) ($registration['priority'] ?? 0);
            }
        }

        $this->assertSame(PHP_INT_MIN, (int) ($priorities['targetHookStart'] ?? 0));
        $this->assertSame(PHP_INT_MAX, (int) ($priorities['targetHookEnd'] ?? 0));
        $runtime->finish();
    }

    public function test_targeted_hook_security_die_and_mail_diagnostics_preserve_privacy(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/target', array(
            'include_assets' => false,
            'hooks' => array('example_hook'),
            'capabilities' => array(),
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/target';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $GLOBALS['codi_mcp_test_current_filter_stack'][] = 'example_hook';
        $this->assertSame('value', $runtime->targetHookStart('value'));
        $wpdb->num_queries = 3;
        $this->assertSame('value', $runtime->targetHookEnd('value'));
        array_pop($GLOBALS['codi_mcp_test_current_filter_stack']);

        do_action('wp_verify_nonce_failed', 'secret-nonce', 'save_item_42', (object) array('ID' => 7), 'secret-session-token');
        $runtime->preWpMail(false, array('to' => array('private@example.test'), 'attachments' => array('/private/file.pdf')));
        $handler = $runtime->wrapDieHandler(static fn ($message, $title, $args): string => 'handled');
        $this->assertSame('handled', $handler('Failure token=die-secret', 'Denied', array('response' => 403)));

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);

        $execution = (array) ($capture['hooks']['items'][0]['execution'] ?? array());
        $this->assertSame(1, (int) ($execution['executions'] ?? 0));
        $this->assertSame(3, (int) ($execution['queries'] ?? 0));
        $this->assertSame('save_item_42', (string) ($capture['security']['items'][0]['action'] ?? ''));
        $this->assertSame('preempted', (string) ($capture['mail']['items'][0]['status'] ?? ''));
        $this->assertSame(1, (int) ($capture['mail']['items'][0]['recipient_count'] ?? 0));
        $this->assertSame(403, (int) ($capture['errors']['wp_die'][0]['response'] ?? 0));

        $encoded = json_encode($capture);
        $this->assertTrue(is_string($encoded));
        $this->assertFalse(str_contains((string) $encoded, 'secret-nonce'));
        $this->assertFalse(str_contains((string) $encoded, 'secret-session-token'));
        $this->assertFalse(str_contains((string) $encoded, 'private@example.test'));
        $this->assertFalse(str_contains((string) $encoded, '/private/file.pdf'));
        $this->assertFalse(str_contains((string) $encoded, 'die-secret'));
    }

    public function test_callback_timing_is_opt_in_bounded_and_preserves_application_attribution(): void
    {
        global $wpdb, $wp_filter;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/callback-timing', array(
            'include_assets' => false,
            'hooks' => array('fixture_hook'),
            'capabilities' => array(),
            'callback_timing' => true,
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/callback-timing';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $applicationCallback = static function (string $value) use ($runtime, &$wpdb): string {
            usleep(60000);
            $sql = "SELECT option_value FROM wp_options WHERE option_name = 'callback-fixture' LIMIT 1";
            $start = microtime(true);
            $previousFilters = $GLOBALS['wp_current_filter'] ?? array();
            $GLOBALS['wp_current_filter'] = array('fixture_hook', 'log_query_custom_data');
            try {
                $custom = apply_filters('log_query_custom_data', array(), $sql, 0.030, 'Fixture::callbackTiming', $start);
            } finally {
                $GLOBALS['wp_current_filter'] = $previousFilters;
            }
            $wpdb->queries[] = array($sql, 0.030, 'Fixture::callbackTiming', $start, $custom);
            $wpdb->num_queries++;
            return $value . ':application';
        };
        $wp_filter['fixture_hook'] = new class($applicationCallback) {
            public array $callbacks;

            public function __construct($callback)
            {
                $this->callbacks = array(
                    10 => array(
                        'fixture' => array('function' => $callback, 'accepted_args' => 1),
                    ),
                );
            }

            public function current_priority(): int
            {
                return -1000000;
            }
        };

        $GLOBALS['codi_mcp_test_current_filter_stack'][] = 'fixture_hook';
        $GLOBALS['wp_current_filter'] = array('fixture_hook');
        $this->assertSame('value', $runtime->targetHookStart('value'));
        $wrapped = $wp_filter['fixture_hook']->callbacks[10]['fixture']['function'];
        $this->assertFalse($wrapped === $applicationCallback);
        $this->assertSame('value:application', $wrapped('value'));
        $this->assertSame('value', $runtime->targetHookEnd('value'));
        array_pop($GLOBALS['codi_mcp_test_current_filter_stack']);
        unset($GLOBALS['wp_current_filter']);

        $this->assertTrue($wp_filter['fixture_hook']->callbacks[10]['fixture']['function'] === $applicationCallback);
        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);

        $this->assertTrue((bool) ($capture['overview']['diagnostic']['callback_timing'] ?? false));
        $profilerOverhead = (array) ($capture['overview']['diagnostic']['overhead']['callback_profiler'] ?? array());
        $this->assertArrayHasKey('measured_overhead_ms', $profilerOverhead);
        $this->assertArrayHasKey('setup_ms', $profilerOverhead);
        $this->assertArrayHasKey('restore_ms', $profilerOverhead);
        $this->assertArrayHasKey('bookkeeping_ms', $profilerOverhead);
        $timing = (array) ($capture['hooks']['items'][0]['callback_timing'] ?? array());
        $this->assertTrue((bool) ($timing['enabled'] ?? false));
        $this->assertSame(1, (int) ($timing['profiled_callbacks'] ?? 0));
        $this->assertSame(1, (int) ($timing['executions'] ?? 0));
        $this->assertTrue((float) ($timing['inclusive_wall_ms_sum'] ?? 0.0) >= 50.0);
        $source = (array) ($timing['items'][0]['source'] ?? array());
        $this->assertSame('{closure}', (string) ($source['callable'] ?? ''));
        $this->assertFalse(str_contains((string) ($source['callable'] ?? ''), 'CallbackProfiler'));
        $callbackCosts = (array) ($capture['database']['callback_costs'] ?? array());
        $this->assertSame(1, (int) ($callbackCosts[0]['queries'] ?? 0));
        $this->assertSame('{closure}', (string) ($callbackCosts[0]['source']['callable'] ?? ''));
        $this->assertSame('fixture_hook', (string) ($callbackCosts[0]['source']['hook'] ?? ''));
        $correlations = (array) ($capture['correlations']['items'] ?? array());
        $this->assertSame('callback_sql_overlap', (string) ($correlations[0]['rule_id'] ?? ''));
    }

    public function test_collector_self_overhead_is_reported_separately_from_profiler_and_capture_build(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/collector-overhead', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array(),
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/collector-overhead';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $runtime->queryCustomData(array(), "SELECT option_value FROM wp_options WHERE option_name = 'fixture'", 0.001, 'Fixture::query', microtime(true));
        $runtime->optionAccess(null, 'fixture_option', false);
        $runtime->finish();

        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $overhead = (array) ($capture['overview']['diagnostic']['overhead'] ?? array());
        $collectors = (array) ($overhead['collectors'] ?? array());

        $this->assertSame('instrumented_collector_self_time', (string) ($collectors['scope'] ?? ''));
        $this->assertTrue((int) ($collectors['calls'] ?? 0) >= 3);
        $this->assertTrue(count((array) ($collectors['items'] ?? array())) <= 30);
        $names = array_values(array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            (array) ($collectors['items'] ?? array())
        ));
        $this->assertTrue(in_array('runtime.setup', $names, true));
        $this->assertTrue(in_array('database.query_custom_data', $names, true));
        $this->assertTrue(in_array('database.option_access', $names, true));
        $this->assertArrayHasKey('callback_profiler', $overhead);
        $this->assertArrayHasKey('capture_build_ms', $overhead);
    }

    public function test_query_binding_cardinality_distinguishes_repetition_without_exposing_values(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/bindings', array('include_assets' => false, 'hooks' => array(), 'capabilities' => array()));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/bindings';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        foreach (array(
            "SELECT option_value FROM wp_options WHERE option_name = 'private-one' LIMIT 1",
            "SELECT option_value FROM wp_options WHERE option_name = 'private-one' LIMIT 1",
            "SELECT option_value FROM wp_options WHERE option_name = 'private-one' LIMIT 1",
            "SELECT option_value FROM wp_options WHERE option_name = 'private-two' LIMIT 1",
        ) as $sql) {
            $start = microtime(true);
            $custom = apply_filters('log_query_custom_data', array(), $sql, 0.003, 'Fixture::binding', $start);
            $wpdb->queries[] = array($sql, 0.003, 'Fixture::binding', $start, $custom);
            $wpdb->num_queries++;
        }

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $pattern = (array) ($capture['database']['patterns'][0] ?? array());
        $this->assertSame(4, (int) ($pattern['count'] ?? 0));
        $this->assertTrue((bool) ($pattern['binding_cardinality_available'] ?? false));
        $this->assertSame(2, (int) ($pattern['distinct_bindings'] ?? 0));
        $this->assertSame(3, (int) ($pattern['max_binding_repetitions'] ?? 0));
        $this->assertSame(2, (int) ($pattern['duplicate_executions'] ?? 0));
        $opportunity = (array) ($capture['database']['opportunities'][0] ?? array());
        $this->assertSame('cache_candidate', (string) ($opportunity['kind'] ?? ''));
        $this->assertSame(2, (int) ($opportunity['duplicate_executions'] ?? 0));
        $encoded = (string) json_encode($capture);
        $this->assertFalse(str_contains($encoded, 'private-one'));
        $this->assertFalse(str_contains($encoded, 'private-two'));
        $this->assertFalse(str_contains($encoded, '_binding_counts'));
    }

    public function test_distinct_query_bindings_surface_batching_evidence_without_cache_claim(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/batching', array('include_assets' => false, 'hooks' => array(), 'capabilities' => array()));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/batching';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        foreach (array('one', 'two', 'three', 'four', 'five') as $binding) {
            $sql = "SELECT option_value FROM wp_options WHERE option_name = 'private-" . $binding . "' LIMIT 1";
            $start = microtime(true);
            $custom = apply_filters('log_query_custom_data', array(), $sql, 0.003, 'Fixture::batching', $start);
            $wpdb->queries[] = array($sql, 0.003, 'Fixture::batching', $start, $custom);
            $wpdb->num_queries++;
        }

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $opportunities = (array) ($capture['database']['opportunities'] ?? array());
        $this->assertSame(1, count($opportunities));
        $this->assertSame('batch_candidate', (string) ($opportunities[0]['kind'] ?? ''));
        $this->assertSame(5, (int) ($opportunities[0]['distinct_bindings'] ?? 0));
        $encoded = (string) json_encode($capture);
        $this->assertFalse(str_contains($encoded, 'private-one'));
        $this->assertFalse(str_contains($encoded, 'private-five'));
    }

    public function test_dense_hook_capture_reports_complete_cardinality_and_source_cost_without_unbounded_callbacks(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        global $wp_filter;
        $wp_filter = array('example_dense_hook' => (object) array('callbacks' => array()));
        for ($i = 0; $i < 105; $i++) {
            $wp_filter['example_dense_hook']->callbacks[10 + $i]['fixture_' . $i] = array(
                'function' => static fn ($value = null) => $value,
                'accepted_args' => 1,
            );
        }

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/dense-hook', array('include_assets' => false, 'hooks' => array('example_dense_hook'), 'capabilities' => array()));
        $this->assertFalse($issued instanceof \WP_Error);
        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/dense-hook';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $runtime = new DiagnosticRuntime($store);
        $runtime->register();

        $GLOBALS['codi_mcp_test_current_filter_stack'][] = 'example_dense_hook';
        $runtime->targetHookStart(null);
        $GLOBALS['wp_current_filter'] = array('example_dense_hook', 'log_query_custom_data');
        $sql = "SELECT ID FROM wp_posts WHERE post_status = 'private-status'";
        $start = microtime(true);
        $custom = apply_filters('log_query_custom_data', array(), $sql, 0.004, 'Fixture::denseHook', $start);
        $GLOBALS['wp_current_filter'] = array();
        $wpdb->queries[] = array($sql, 0.004, 'Fixture::denseHook', $start, $custom);
        $wpdb->num_queries = 1;
        $runtime->targetHookEnd(null);
        array_pop($GLOBALS['codi_mcp_test_current_filter_stack']);

        $runtime->finish();
        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $hook = (array) ($capture['hooks']['items'][0] ?? array());
        $this->assertSame(105, (int) ($hook['callback_count'] ?? 0));
        $this->assertSame(100, (int) ($hook['callbacks_returned'] ?? 0));
        $this->assertTrue((bool) ($hook['callbacks_truncated'] ?? false));
        $costs = (array) ($hook['cost_sources'] ?? array());
        $this->assertTrue($costs !== array());
        $this->assertSame(1, array_sum(array_map(static fn (array $row): int => (int) ($row['queries'] ?? 0), $costs)));
    }

    public function test_inspection_views_are_bounded_filterable_and_surface_top_offenders(): void
    {
        $store = new DiagnosticStore();
        $id = str_repeat('a', 32);
        $capture = array(
            'overview' => array('request' => array('url' => '/target'), 'summary' => array('queries' => 3)),
            'database' => array(
                'available' => true,
                'capture_scope' => 'test',
                'queries_before_capture' => 0,
                'count' => 3,
                'internal_queries_excluded' => 0,
                'time_ms' => 8.0,
                'by_type' => array(array('name' => 'select', 'count' => 3, 'time_ms' => 8.0)),
                'by_component' => array(
                    array('name' => 'component-a', 'count' => 2, 'time_ms' => 7.0),
                    array('name' => 'component-b', 'count' => 1, 'time_ms' => 1.0),
                ),
                'by_caller' => array(),
                'patterns' => array(
                    array('query' => 'SELECT * FROM a WHERE id = ?', 'count' => 2, 'time_ms' => 7.0, 'source' => array('component' => 'component-a', 'hook' => 'init')),
                    array('query' => 'SELECT * FROM b WHERE id = ?', 'count' => 1, 'time_ms' => 1.0, 'source' => array('component' => 'component-b', 'hook' => 'admin_init')),
                ),
                'slowest' => array(),
                'explains' => array(),
            ),
            'hooks' => array('items' => array(
                array(
                    'hook' => 'admin_init',
                    'execution' => array('executions' => 1, 'time_ms' => 12.0, 'max_ms' => 12.0, 'queries' => 2, 'http_requests' => 0),
                    'callback_count' => 150,
                    'callbacks_returned' => 100,
                    'callbacks_truncated' => true,
                    'cost_sources' => array(array('component' => 'component-a', 'callable' => 'A::run', 'queries' => 2, 'query_time_ms' => 7.0, 'http_requests' => 0, 'state_changes' => 0)),
                    'callback_timing' => array(
                        'enabled' => true,
                        'profiled_callbacks' => 1,
                        'skipped_callbacks' => 0,
                        'callbacks_omitted_by_limit' => 0,
                        'executions' => 1,
                        'inclusive_wall_ms_sum' => 11.0,
                        'setup_ms' => 0.1,
                        'restore_ms' => 0.1,
                        'bookkeeping_ms' => 0.05,
                        'registry_changed' => false,
                        'coverage_complete' => true,
                        'items' => array(array(
                            'priority' => 10,
                            'accepted_args' => 1,
                            'source' => array('component_type' => 'plugin', 'component' => 'component-a', 'file' => 'component-a/file.php', 'line' => 20, 'callable' => 'A::run', 'hook' => ''),
                            'status' => 'profiled',
                            'executions' => 1,
                            'wall_ms' => 11.0,
                            'max_ms' => 11.0,
                            'profiled_child_ms' => 0.0,
                            'exclusive_profiled_ms' => 11.0,
                        )),
                    ),
                    'callbacks' => array(array('priority' => 10)),
                ),
            )),
            'http' => array(
                'items' => array(),
                'opportunities' => array(array('kind' => 'http_candidate', 'confidence' => 'medium', 'url' => 'https://example.test/api', 'ajax_action' => '', 'source' => array('component' => 'component-a'))),
                'truncated' => false,
            ),
            'timeline' => array('items' => array(), 'truncated' => false),
        );
        $this->assertTrue($store->save($id, $capture));
        $runner = new DiagnosticRunner($store);

        $items = $runner->inspect(array(
            'diagnostic_id' => $id,
            'section' => 'database',
            'detail' => 'items',
            'collection' => 'patterns',
            'component' => 'component-a',
            'page' => 1,
            'per_page' => 1,
        ));
        $this->assertSame(1, (int) ($items['data']['total'] ?? 0));
        $this->assertSame('component-a', (string) ($items['data']['items'][0]['source']['component'] ?? ''));

        $defaultHooks = $runner->inspect(array(
            'diagnostic_id' => $id,
            'section' => 'hooks',
        ));
        $this->assertSame('admin_init', (string) ($defaultHooks['data']['items'][0]['hook'] ?? ''));
        $this->assertFalse(isset($defaultHooks['data']['items'][0]['callbacks']));

        $hooks = $runner->inspect(array(
            'diagnostic_id' => $id,
            'section' => 'hooks',
            'detail' => 'full',
            'include_callbacks' => false,
        ));
        $this->assertFalse(isset($hooks['data']['items'][0]['callbacks']));
        $this->assertSame(150, (int) ($hooks['data']['items'][0]['callback_count'] ?? 0));

        $callbackTimings = $runner->inspect(array(
            'diagnostic_id' => $id,
            'section' => 'hooks',
            'detail' => 'items',
            'collection' => 'callback_timings',
            'sort' => 'wall_ms',
            'minimum_ms' => 5,
        ));
        $this->assertSame(1, (int) ($callbackTimings['data']['total'] ?? 0));
        $this->assertSame('admin_init', (string) ($callbackTimings['data']['items'][0]['hook'] ?? ''));
        $this->assertSame(11.0, (float) ($callbackTimings['data']['items'][0]['wall_ms'] ?? 0.0));

        $http = $runner->inspect(array(
            'diagnostic_id' => $id,
            'section' => 'http',
            'detail' => 'items',
            'collection' => 'opportunities',
            'page' => 1,
            'per_page' => 10,
        ));
        $this->assertSame('http_candidate', (string) ($http['data']['items'][0]['kind'] ?? ''));

        $overview = $runner->inspect(array('diagnostic_id' => $id, 'section' => 'overview'));
        $this->assertSame('component-a', (string) ($overview['data']['top_offenders']['database_components'][0]['name'] ?? ''));
        $this->assertSame('admin_init', (string) ($overview['data']['top_offenders']['hooks'][0]['hook'] ?? ''));
        $this->assertSame('A::run', (string) ($overview['data']['top_offenders']['hook_callbacks'][0]['source']['callable'] ?? ''));
        $this->assertSame(11.0, (float) ($overview['data']['top_offenders']['hook_callbacks'][0]['wall_ms'] ?? 0.0));
    }

    public function test_run_cache_probe_reports_cross_request_persistence_and_cleans_its_key(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        $store = new DiagnosticStore();
        $url = 'https://example.test/cache-probe';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? '');
            $_SERVER['REQUEST_URI'] = '/cache-probe';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $runtime = new DiagnosticRuntime($store);
            $runtime->register();
            $runtime->finish();
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $runner = new DiagnosticRunner($store);
        $summary = $runner->run(array('url' => '/cache-probe', 'include_assets' => false));
        $this->assertFalse($summary instanceof \WP_Error);
        $cache = $runner->inspect(array('diagnostic_id' => (string) $summary['diagnostic_id'], 'section' => 'cache'));
        $probe = (array) ($cache['data']['persistence_probe'] ?? array());
        $this->assertArrayHasKey('counters_available', (array) ($cache['data'] ?? array()));
        $this->assertFalse(array_key_exists('available', (array) ($cache['data'] ?? array())));
        $this->assertTrue((bool) ($probe['attempted'] ?? false));
        $this->assertTrue((bool) ($probe['supported'] ?? false));
        $this->assertTrue((bool) ($probe['persisted'] ?? false));
        $this->assertSame('persistent', (string) ($probe['classification'] ?? ''));
        $this->assertTrue((bool) ($probe['groups']['diagnostic']['persisted'] ?? false));
        $this->assertTrue((bool) ($probe['groups']['core_options']['persisted'] ?? false));
        $this->assertTrue((bool) ($probe['groups']['diagnostic']['readable_now'] ?? false));
        $this->assertTrue((bool) ($probe['groups']['core_options']['readable_now'] ?? false));
        $this->assertCount(0, (array) ($GLOBALS['codi_mcp_test_wp_cache']['codi_mcp_diagnostics'] ?? array()));
        $this->assertCount(0, (array) ($GLOBALS['codi_mcp_test_wp_cache']['options'] ?? array()));
    }

    public function test_cache_probe_distinguishes_nonpersistent_diagnostic_group_from_core_cache_failure(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        $store = new DiagnosticStore();
        $url = 'https://example.test/cache-probe-groups';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            unset($GLOBALS['codi_mcp_test_wp_cache']['codi_mcp_diagnostics']);
            $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? '');
            $_SERVER['REQUEST_URI'] = '/cache-probe-groups';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $runtime = new DiagnosticRuntime($store);
            $runtime->register();
            $runtime->finish();
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $runner = new DiagnosticRunner($store);
        $summary = $runner->run(array('url' => '/cache-probe-groups', 'include_assets' => false));
        $this->assertFalse($summary instanceof \WP_Error);
        $cache = $runner->inspect(array('diagnostic_id' => (string) $summary['diagnostic_id'], 'section' => 'cache'));
        $probe = (array) ($cache['data']['persistence_probe'] ?? array());

        $this->assertSame('diagnostic_group_non_persistent', (string) ($probe['classification'] ?? ''));
        $this->assertTrue((bool) ($probe['persisted'] ?? false));
        $this->assertFalse((bool) ($probe['groups']['diagnostic']['persisted'] ?? true));
        $this->assertTrue((bool) ($probe['groups']['core_options']['persisted'] ?? false));
    }

    public function test_cross_request_cache_miss_is_reported_without_claiming_persistence_failure(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        wp_using_ext_object_cache(true);
        $store = new DiagnosticStore();
        $url = 'https://example.test/cache-probe-failure';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            unset($GLOBALS['codi_mcp_test_wp_cache']['codi_mcp_diagnostics'], $GLOBALS['codi_mcp_test_wp_cache']['options']);
            $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? '');
            $_SERVER['REQUEST_URI'] = '/cache-probe-failure';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $runtime = new DiagnosticRuntime($store);
            $runtime->register();
            $runtime->finish();
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $runner = new DiagnosticRunner($store);
        $summary = $runner->run(array('url' => '/cache-probe-failure', 'include_assets' => false));
        $this->assertFalse($summary instanceof \WP_Error);
        $cache = $runner->inspect(array('diagnostic_id' => (string) $summary['diagnostic_id'], 'section' => 'cache'));
        $opportunities = (array) ($cache['data']['opportunities'] ?? array());

        $this->assertSame('cross_request_miss', (string) ($cache['data']['persistence_probe']['classification'] ?? ''));
        $this->assertSame('object_cache_cross_request_miss', (string) ($opportunities[0]['kind'] ?? ''));
        $this->assertSame('medium', (string) ($opportunities[0]['confidence'] ?? ''));
        $reason = (string) ($opportunities[0]['reason'] ?? '');
        $this->assertTrue(str_contains($reason, 'proves a cross-request miss'));
        $this->assertFalse(str_contains($reason, 'persistence failure'));
        $this->assertTrue((bool) ($cache['data']['persistence_probe']['groups']['core_options']['readable_now'] ?? false));

        $overview = $runner->inspect(array('diagnostic_id' => (string) $summary['diagnostic_id'], 'section' => 'overview'));
        $this->assertSame(
            'object_cache_cross_request_miss',
            (string) ($overview['data']['top_offenders']['cache_opportunities'][0]['kind'] ?? '')
        );
    }

    public function test_target_request_cache_read_unavailability_is_distinguished_from_cross_request_persistence(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        wp_using_ext_object_cache(true);
        $store = new DiagnosticStore();
        $url = 'https://example.test/cache-read-unavailable';
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function (string $calledUrl, array $args) use ($store): array {
            $GLOBALS['codi_mcp_test_wp_cache_read_unavailable_groups'] = array(
                'codi_mcp_diagnostics' => true,
                'options' => true,
            );
            $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) ($args['headers']['X-Codi-Diagnostic-Token'] ?? '');
            $_SERVER['REQUEST_URI'] = '/cache-read-unavailable';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $runtime = new DiagnosticRuntime($store);
            $runtime->register();
            $runtime->finish();
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $runner = new DiagnosticRunner($store);
        $summary = $runner->run(array('url' => '/cache-read-unavailable', 'include_assets' => false));
        $this->assertFalse($summary instanceof \WP_Error);
        $cache = $runner->inspect(array('diagnostic_id' => (string) $summary['diagnostic_id'], 'section' => 'cache'));
        $probe = (array) ($cache['data']['persistence_probe'] ?? array());
        $opportunities = (array) ($cache['data']['opportunities'] ?? array());

        $this->assertSame('read_unavailable', (string) ($probe['classification'] ?? ''));
        $this->assertFalse((bool) ($probe['groups']['core_options']['readable_now'] ?? true));
        $this->assertSame('object_cache_read_unavailable', (string) ($opportunities[0]['kind'] ?? ''));
        $this->assertSame('high', (string) ($opportunities[0]['confidence'] ?? ''));
        $this->assertTrue(str_contains((string) ($opportunities[0]['reason'] ?? ''), 'does not prove backend persistence loss'));
    }

    public function test_capability_tracing_covers_multisite_super_admin_mapping(): void
    {
        global $wpdb;
        $wpdb = (object) array('queries' => array(), 'num_queries' => 0);
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_super_admins'] = array(7);

        $store = new DiagnosticStore();
        $issued = $store->issue(7, '/target', array(
            'include_assets' => false,
            'hooks' => array(),
            'capabilities' => array('manage_options'),
        ));
        $this->assertFalse($issued instanceof \WP_Error);

        $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
        $_SERVER['REQUEST_URI'] = '/target';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $runtime = new DiagnosticRuntime($store);
        $runtime->register();
        $runtime->mapMetaCap(array('manage_options'), 'manage_options', 7, array());
        $runtime->finish();

        $capture = $store->get((string) $issued['diagnostic_id']);
        $this->assertFalse(false === $capture);
        $item = (array) ($capture['capabilities']['items'][0] ?? array());
        $this->assertSame('map_meta_cap', (string) ($item['stage'] ?? ''));
        $this->assertSame('manage_options', (string) ($item['capability'] ?? ''));
        $this->assertTrue((bool) ($item['granted'] ?? false));
        $this->assertTrue((bool) ($item['super_admin'] ?? false));
    }



    public function test_diagnostics_correlate_autoload_size_with_observed_option_access_without_values(): void
    {
        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = new class {
            public array $queries = array();
            public int $num_queries = 0;
            public string $last_error = '';
            public string $options = 'wp_options';

            public function get_results(string $sql, $format): array
            {
                if (str_contains($sql, 'COUNT(*) AS option_count')) {
                    return array(array('option_count' => 2, 'option_bytes' => 50010));
                }
                if (str_contains($sql, 'OCTET_LENGTH(option_value) AS option_bytes')) {
                    return array(
                        array('option_name' => 'large_unused', 'autoload' => 'on', 'option_bytes' => 50000),
                        array('option_name' => 'small_used', 'autoload' => 'on', 'option_bytes' => 10),
                    );
                }
                return array();
            }

            public function get_var(string $sql): int
            {
                return 2;
            }

            public function prepare(string $query, array $args): string
            {
                $index = 0;
                return (string) preg_replace_callback('/%[sd]/', function (array $match) use ($args, &$index): string {
                    $value = $args[$index++] ?? null;
                    return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
                }, $query);
            }

            public function esc_like(string $value): string
            {
                return addcslashes($value, '_%\\');
            }
        };

        try {
            $store = new DiagnosticStore();
            $issued = $store->issue(7, '/autoload-target', array('include_assets' => false, 'hooks' => array(), 'capabilities' => array()));
            $this->assertFalse($issued instanceof \WP_Error);
            $_SERVER['HTTP_X_CODI_DIAGNOSTIC_TOKEN'] = (string) $issued['token'];
            $_SERVER['REQUEST_URI'] = '/autoload-target';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            $runtime = new DiagnosticRuntime($store);
            $runtime->register();
            apply_filters('pre_option', false, 'small_used', false);
            $runtime->finish();

            $capture = $store->get((string) $issued['diagnostic_id']);
            $this->assertFalse(false === $capture);
            $autoload = (array) ($capture['database']['autoload'] ?? array());
            $this->assertTrue((bool) ($autoload['available'] ?? false));
            $this->assertSame(50010, (int) ($autoload['total_bytes'] ?? 0));
            $this->assertSame(0, (int) ($autoload['items'][0]['accesses'] ?? -1));
            $this->assertSame(1, (int) ($autoload['items'][1]['accesses'] ?? 0));
            $candidate = (array) ($capture['database']['opportunities'][0] ?? array());
            $this->assertSame('autoload_candidate', (string) ($candidate['kind'] ?? ''));
            $this->assertSame('large_unused', (string) ($candidate['option'] ?? ''));
            $encoded = (string) json_encode($capture);
            $this->assertFalse(str_contains($encoded, 'option_value'));
        } finally {
            $wpdb = $previous;
        }
    }
    public function test_diagnostic_compare_requires_matching_identity_and_reports_recurring_evidence(): void
    {
        $store = new DiagnosticStore();
        $source = array('component_type' => 'plugin', 'component' => 'mwf', 'file' => 'mwf/example.php', 'line' => 10, 'callable' => 'Example::load', 'hook' => 'init');
        $base = array(
            'overview' => array(
                'request' => array('url' => '/wp-admin/', 'method' => 'GET', 'duration_ms' => 100.0, 'type' => 'admin', 'user_id' => 2, 'site_id' => 6),
                'summary' => array('queries' => 10, 'query_time_ms' => 20.0, 'state_changes' => 1, 'http_requests' => 1),
                'diagnostic' => array('profile' => 'admin', 'configuration_fingerprint' => str_repeat('f', 64)),
            ),
            'database' => array(
                'by_component' => array(array('name' => 'mwf', 'count' => 5, 'time_ms' => 12.0)),
                'patterns' => array(array('query' => 'SELECT * FROM custom_table WHERE id = ?', 'count' => 5, 'time_ms' => 12.0, 'source' => $source)),
                'opportunities' => array(array('kind' => 'batch_candidate', 'confidence' => 'medium', 'query' => 'SELECT * FROM custom_table WHERE id = ?', 'source' => $source)),
            ),
            'http' => array(
                'items' => array(array('method' => 'POST', 'url' => 'https://example.test/wp-admin/admin-ajax.php?action', 'ajax_action' => 'queue_runner', 'duration_ms' => 300.0, 'status' => 200, 'error_code' => '', 'source' => $source)),
                'opportunities' => array(array('kind' => 'http_candidate', 'confidence' => 'medium', 'url' => 'https://example.test/wp-admin/admin-ajax.php?action', 'ajax_action' => 'queue_runner', 'source' => $source)),
            ),
        );
        $second = $base;
        $second['overview']['request']['duration_ms'] = 300.0;
        $second['overview']['summary']['queries'] = 14;
        $second['overview']['summary']['query_time_ms'] = 40.0;
        $second['database']['by_component'][0]['count'] = 7;
        $second['database']['by_component'][0]['time_ms'] = 18.0;
        $second['http']['items'][] = array(
            'method' => 'POST',
            'url' => 'https://example.test/wp-admin/admin-ajax.php?action',
            'ajax_action' => 'one_off_queue',
            'duration_ms' => 900.0,
            'status' => 200,
            'error_code' => '',
            'source' => $source,
        );
        $second['http']['opportunities'][] = array(
            'kind' => 'http_candidate',
            'confidence' => 'high',
            'url' => 'https://example.test/wp-admin/admin-ajax.php?action',
            'ajax_action' => 'one_off_queue',
            'total_ms' => 900.0,
            'source' => $source,
        );

        $idA = str_repeat('a', 32);
        $idB = str_repeat('b', 32);
        $this->assertTrue($store->save($idA, $base));
        $this->assertTrue($store->save($idB, $second));

        $result = (new DiagnosticComparator($store))->compare(array('diagnostic_ids' => array($idA, $idB)));
        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame(200.0, (float) ($result['metrics']['duration_ms']['median'] ?? 0.0));
        $this->assertSame(2, (int) ($result['recurring']['components'][0]['observed_in'] ?? 0));
        $this->assertSame(2, (int) ($result['recurring']['http_requests'][0]['observed_in'] ?? 0));
        $this->assertSame(2, (int) ($result['recurring']['opportunities'][0]['observed_in'] ?? 0));
        $this->assertSame(array(), (array) ($result['recurring']['query_patterns'] ?? array()));
        $this->assertSame('one_off_queue', (string) ($result['intermittent']['http_requests'][0]['ajax_action'] ?? ''));
        $this->assertSame(1, (int) ($result['intermittent']['http_requests'][0]['observed_in'] ?? 0));
        $this->assertSame('one_off_queue', (string) ($result['intermittent']['opportunities'][0]['ajax_action'] ?? ''));

        $full = (new DiagnosticComparator($store))->compare(array('diagnostic_ids' => array($idA, $idB), 'detail' => 'full'));
        $this->assertFalse($full instanceof \WP_Error);
        $this->assertSame(2, (int) ($full['recurring']['query_patterns'][0]['observed_in'] ?? 0));

        $mismatch = $second;
        $mismatch['overview']['diagnostic']['configuration_fingerprint'] = str_repeat('e', 64);
        $idC = str_repeat('c', 32);
        $this->assertTrue($store->save($idC, $mismatch));
        $error = (new DiagnosticComparator($store))->compare(array('diagnostic_ids' => array($idA, $idC)));
        $this->assertTrue($error instanceof \WP_Error);
        $this->assertSame('codi_mcp_diagnostic_not_comparable', $error->get_error_code());
    }

    public function test_package_exposes_four_bounded_diagnostic_abilities(): void
    {
        $package = new Package();
        $package->registerCategories();
        $package->registerAbilities();

        $this->assertSame(array('codi/diagnostic-run', 'codi/diagnostic-arm', 'codi/diagnostic-inspect', 'codi/diagnostic-compare'), $package->abilityNames());
        $run = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-run'] ?? array());
        $arm = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-arm'] ?? array());
        $inspect = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-inspect'] ?? array());
        $compare = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-compare'] ?? array());
        $this->assertFalse((bool) ($run['meta']['annotations']['readonly'] ?? true));
        $this->assertTrue((bool) ($run['meta']['annotations']['destructive'] ?? false));
        $this->assertTrue((bool) ($run['meta']['annotations']['openWorldHint'] ?? false));
        $this->assertFalse((bool) ($arm['meta']['annotations']['readonly'] ?? true));
        $this->assertFalse((bool) ($arm['meta']['annotations']['destructive'] ?? true));
        $this->assertFalse((bool) ($arm['meta']['annotations']['idempotent'] ?? true));
        $this->assertTrue((bool) ($inspect['meta']['annotations']['readonly'] ?? false));
        $this->assertFalse((bool) ($inspect['meta']['annotations']['destructive'] ?? true));
        $this->assertTrue((bool) ($compare['meta']['annotations']['readonly'] ?? false));
        $this->assertFalse((bool) ($compare['meta']['annotations']['destructive'] ?? true));
        $this->assertSame(array('none', 'admin', 'frontend', 'rest', 'cron'), (array) ($run['input_schema']['properties']['profile']['enum'] ?? array()));
        $this->assertSame(array('none', 'admin', 'frontend', 'rest', 'cron'), (array) ($arm['input_schema']['properties']['profile']['enum'] ?? array()));
        $this->assertSame(array('summary', 'full'), (array) ($compare['input_schema']['properties']['detail']['enum'] ?? array()));
        $this->assertSame(array('diagnostic_ids'), array_values((array) ($compare['input_schema']['required'] ?? array())));
    }
}