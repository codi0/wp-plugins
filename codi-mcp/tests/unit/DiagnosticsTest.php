<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

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
                    ),
                ),
                'errors' => array('php' => array()),
            ));
            return array('response' => array('code' => 200), 'body' => 'ok');
        };

        $result = (new DiagnosticRunner($store))->run(array('url' => '/wp-admin/edit.php?post_type=page'));

        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame(42, (int) $result['summary']['queries']);
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
        $this->assertSame(1, count((array) $capture['http']['items']));
        $this->assertSame(1, count((array) $capture['redirects']['items']));
        $this->assertSame(1, count((array) $capture['errors']['wp_error']));
        $slowQuery = (string) ($capture['database']['slowest'][0]['query'] ?? '');
        $this->assertFalse(str_contains($slowQuery, 'Private title'));
        $this->assertFalse(str_contains((string) $capture['errors']['wp_error'][0]['message'], 'supersecret'));
        $this->assertFalse(str_contains((string) $capture['http']['items'][0]['url'], 'secret'));
        $this->assertSame('/diagnostic-target?foo', (string) $capture['overview']['request']['url']);
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

    public function test_package_exposes_three_bounded_diagnostic_abilities(): void
    {
        $package = new Package();
        $package->registerCategories();
        $package->registerAbilities();

        $this->assertSame(array('codi/diagnostic-run', 'codi/diagnostic-arm', 'codi/diagnostic-inspect'), $package->abilityNames());
        $run = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-run'] ?? array());
        $arm = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-arm'] ?? array());
        $inspect = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-inspect'] ?? array());
        $this->assertFalse((bool) ($run['meta']['annotations']['readonly'] ?? true));
        $this->assertTrue((bool) ($run['meta']['annotations']['destructive'] ?? false));
        $this->assertTrue((bool) ($run['meta']['annotations']['openWorldHint'] ?? false));
        $this->assertFalse((bool) ($arm['meta']['annotations']['readonly'] ?? true));
        $this->assertFalse((bool) ($arm['meta']['annotations']['destructive'] ?? true));
        $this->assertFalse((bool) ($arm['meta']['annotations']['idempotent'] ?? true));
        $this->assertTrue((bool) ($inspect['meta']['annotations']['readonly'] ?? false));
        $this->assertFalse((bool) ($inspect['meta']['annotations']['destructive'] ?? true));
    }
}