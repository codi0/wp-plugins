<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Audit\AuditLog;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Core\PackageRuntime;
use CodiMcp\Exposure\ExposurePolicy;
use CodiMcp\Packages\Multisite\FederationController;
use CodiMcp\Packages\Multisite\FederationClient;
use CodiMcp\Packages\Multisite\FederationSigner;
use CodiMcp\Packages\Multisite\LocalAbilityRuntime;
use CodiMcp\Packages\Multisite\Package;
use CodiMcp\Packages\Multisite\ReplayGuard;
use CodiMcp\Packages\Multisite\SiteDirectory;
use CodiMcpTest\Framework\TestCase;

final class MultisitePackageTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_sites'] ?? array()) as $site) {
            if (is_object($site)) {
                $site->archived = 0;
                $site->spam = 0;
                $site->deleted = 0;
            }
        }
        foreach (array(
            'CODI_MCP_ABILITY_PREFIX' => 'codi',
            'CODI_MCP_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1',
        ) as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }
    }

    public function test_gateway_abilities_register_only_on_the_multisite_main_site_and_receiver_registers_everywhere(): void
    {
        $package = new Package();
        $package->registerRuntime($this->runtime($this->policy()));
        $package->registerCategories();
        $package->registerAbilities();
        do_action('rest_api_init');

        $this->assertSame(
            array('codi/sites', 'codi/site-abilities', 'codi/site-call'),
            array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities'])
        );
        $this->assertArrayHasKey('codi-mcp/v1/federation', (array) $GLOBALS['codi_mcp_test_rest_routes']);

        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 2;
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $package = new Package();
        $package->registerRuntime($this->runtime($this->policy()));
        $package->registerCategories();
        $package->registerAbilities();
        do_action('rest_api_init');

        $this->assertSame(array(), array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']));
        $this->assertArrayHasKey('codi-mcp/v1/federation', (array) $GLOBALS['codi_mcp_test_rest_routes']);

        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_multisite'] = false;
        $package = new Package();
        $package->registerRuntime($this->runtime($this->policy()));
        $package->registerCategories();
        $package->registerAbilities();
        do_action('rest_api_init');

        $this->assertSame(array(), array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']));
        $this->assertSame(array(), (array) $GLOBALS['codi_mcp_test_rest_routes']);
    }

    public function test_managed_exposure_names_exist_only_on_the_gateway_site(): void
    {
        $package = new Package();
        $this->assertSame(
            array('codi/sites', 'codi/site-abilities', 'codi/site-call'),
            $package->managedExposureAbilityNames()
        );

        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 2;
        $package = new Package();
        $this->assertSame(array(), $package->managedExposureAbilityNames());
    }

    public function test_sites_returns_only_sites_accessible_to_the_authenticated_user(): void
    {
        $package = new Package();
        $package->registerRuntime($this->runtime($this->policy()));
        $package->registerAbilities();

        $descriptor = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/sites'] ?? array());
        $callback = $descriptor['execute_callback'] ?? null;
        $permission = $descriptor['permission_callback'] ?? null;

        $this->assertTrue(is_callable($callback));
        $this->assertTrue(is_callable($permission));
        $this->assertTrue((bool) $permission());

        $result = $callback(array());
        $this->assertSame(2, (int) ($result['total'] ?? 0));
        $this->assertSame(array(1, 2), array_map(static fn (array $site): int => (int) $site['site_id'], (array) ($result['items'] ?? array())));
        $this->assertSame('Primary Site', (string) ($result['items'][0]['name'] ?? ''));
        $this->assertSame('Docs Site', (string) ($result['items'][1]['name'] ?? ''));
    }

    public function test_sites_excludes_archived_or_otherwise_inactive_targets(): void
    {
        $GLOBALS['codi_mcp_test_wp_sites'][2]->archived = 1;
        $sites = (new SiteDirectory())->accessibleSites(7);

        $this->assertSame(array(1), array_map(static fn (array $site): int => (int) $site['site_id'], $sites));
        $this->assertFalse((new SiteDirectory())->canAccessSite(7, 2));
    }

    public function test_local_catalog_and_execution_use_live_target_exposure_and_normal_ability_permissions(): void
    {
        wp_register_ability('example/echo', array(
            'label' => 'Echo',
            'description' => 'Echo one value.',
            'category' => 'example',
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array('value' => array('type' => 'string')),
                'required' => array('value'),
            ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array('value' => array('type' => 'string')),
            ),
            'execute_callback' => static fn (array $input): array => array('value' => (string) ($input['value'] ?? '')),
            'permission_callback' => static fn (array $input): bool => current_user_can('read'),
            'meta' => array('public' => true, 'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true)),
        ));

        $policy = $this->policy();
        $policy->saveSelection(array('example/echo'));
        $local = new LocalAbilityRuntime($this->runtime($policy), array('codi/sites', 'codi/site-abilities', 'codi/site-call'));

        $catalog = $local->catalog();
        $this->assertCount(1, $catalog);
        $this->assertSame('example/echo', (string) ($catalog[0]['name'] ?? ''));
        $this->assertSame('object', (string) ($catalog[0]['input_schema']['type'] ?? ''));
        $this->assertTrue((bool) ($catalog[0]['annotations']['readonly'] ?? false));

        $result = $local->execute('example/echo', array('value' => 'hello'));
        $this->assertSame(array('value' => 'hello'), $result);

        $policy->saveSelection(array());
        $blocked = $local->execute('example/echo', array('value' => 'hello'));
        $this->assertTrue(is_wp_error($blocked));
        $this->assertSame('codi_multisite_ability_not_exposed', (string) ($blocked->code ?? ''));
    }

    public function test_local_runtime_normalizes_no_argument_object_input_and_nullable_missing_schema(): void
    {
        wp_register_ability('example/no-input', array(
            'label' => 'No input',
            'description' => 'No-input object ability.',
            'category' => 'example',
            'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'properties' => array()),
            'execute_callback' => static fn (array $input): array => array('count' => count($input)),
            'permission_callback' => static fn (array $input): bool => current_user_can('read'),
            'meta' => array('public' => true),
        ));

        $policy = $this->policy();
        $policy->saveSelection(array('example/no-input'));
        $local = new LocalAbilityRuntime($this->runtime($policy), array('codi/sites', 'codi/site-abilities', 'codi/site-call'));

        $catalog = $local->catalog();
        $this->assertCount(1, $catalog);
        $this->assertSame('object', (string) ($catalog[0]['input_schema']['type'] ?? ''));
        $this->assertSame(null, $catalog[0]['output_schema'] ?? null);
        $this->assertSame(array('count' => 0), $local->execute('example/no-input'));
    }

    public function test_remote_client_sends_non_redirecting_signed_request_to_target_runtime(): void
    {
        $url = 'https://docs.example.test/wp-json/codi-mcp/v1/federation';
        $GLOBALS['codi_mcp_test_wp_remote_post_responses'][$url] = array(
            'response' => array('code' => 200),
            'body' => json_encode(array('site_id' => 2, 'abilities' => array()), JSON_UNESCAPED_SLASHES),
        );

        $sites = new SiteDirectory();
        $local = new LocalAbilityRuntime($this->runtime($this->policy()), array('codi/sites', 'codi/site-abilities', 'codi/site-call'));
        $signer = new FederationSigner($sites, 'test-federation-secret', static fn (): int => 3000, static fn (): string => '00112233445566778899aabbccddeeff');
        $client = new FederationClient($sites, $signer, $local);

        $this->assertSame(array(), $client->abilities(2, 7));
        $calls = (array) $GLOBALS['codi_mcp_test_wp_remote_post_calls'];
        $this->assertCount(1, $calls);
        $this->assertSame($url, (string) ($calls[0]['url'] ?? ''));
        $this->assertSame(0, (int) ($calls[0]['args']['redirection'] ?? -1));
        $this->assertTrue(trim((string) ($calls[0]['args']['headers']['X-Codi-MCP-Federation'] ?? '')) !== '');
    }

    public function test_assertions_are_bound_to_body_site_operation_expiry_and_one_time_nonce(): void
    {
        $sites = new SiteDirectory();
        $clock = static fn (): int => 1000;
        $signer = new FederationSigner($sites, 'test-federation-secret', $clock, static fn (): string => '0123456789abcdef0123456789abcdef');
        $body = '{"operation":"abilities","payload":[]}';
        $token = $signer->issue(1, 7, 'abilities', $body);

        $claims = $signer->verify($token, 1, 'abilities', $body);
        $this->assertFalse(is_wp_error($claims));
        $this->assertSame(7, (int) ($claims['user_id'] ?? 0));

        $this->assertTrue(is_wp_error($signer->verify($token, 2, 'abilities', $body)));
        $this->assertTrue(is_wp_error($signer->verify($token, 1, 'execute', $body)));
        $this->assertTrue(is_wp_error($signer->verify($token, 1, 'abilities', $body . ' ')));

        $replay = new ReplayGuard($sites, $clock);
        $this->assertTrue($replay->consume((string) ($claims['nonce'] ?? ''), (int) ($claims['exp'] ?? 0)));
        $this->assertFalse($replay->consume((string) ($claims['nonce'] ?? ''), (int) ($claims['exp'] ?? 0)));
    }

    public function test_federation_receiver_executes_in_target_context_as_the_asserted_user_and_rejects_replay(): void
    {
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 2;
        wp_set_current_user(0);

        wp_register_ability('example/target', array(
            'label' => 'Target ability',
            'description' => 'Runs in the selected target site.',
            'category' => 'example',
            'input_schema' => array(
                'type' => 'object',
                'properties' => array('value' => array('type' => 'string')),
                'required' => array('value'),
            ),
            'output_schema' => array('type' => 'object'),
            'execute_callback' => static fn (array $input): array => array(
                'value' => (string) ($input['value'] ?? ''),
                'user_id' => (int) (wp_get_current_user()->ID ?? 0),
                'site_id' => get_current_blog_id(),
            ),
            'permission_callback' => static fn (array $input): bool => current_user_can('read'),
            'meta' => array('public' => true),
        ));

        $policy = $this->policy();
        $policy->saveSelection(array('example/target'));
        $runtime = $this->runtime($policy);
        $sites = new SiteDirectory();
        $local = new LocalAbilityRuntime($runtime, array('codi/sites', 'codi/site-abilities', 'codi/site-call'));
        $clock = static fn (): int => 2000;
        $signer = new FederationSigner($sites, 'test-federation-secret', $clock, static fn (): string => 'fedcba9876543210fedcba9876543210');
        $controller = new FederationController($sites, $signer, new ReplayGuard($sites, $clock), $local);

        $body = json_encode(array(
            'operation' => 'execute',
            'payload' => array('ability' => 'example/target', 'arguments' => array('value' => 'remote')),
        ), JSON_UNESCAPED_SLASHES);
        $this->assertTrue(is_string($body));
        $token = $signer->issue(2, 7, 'execute', (string) $body);
        $request = new MultisiteRequestDouble((string) $body, $token);

        $this->assertTrue($controller->authorize($request) === true);
        $response = $controller->handle($request);
        $this->assertFalse(is_wp_error($response));
        $this->assertSame('remote', (string) ($response['result']['value'] ?? ''));
        $this->assertSame(7, (int) ($response['result']['user_id'] ?? 0));
        $this->assertSame(2, (int) ($response['result']['site_id'] ?? 0));

        $replay = $controller->authorize(new MultisiteRequestDouble((string) $body, $token));
        $this->assertTrue(is_wp_error($replay));
        $this->assertSame('codi_multisite_replay', (string) ($replay->code ?? ''));
    }
    private function policy(): ExposurePolicy
    {
        return new ExposurePolicy(new AbilityCatalogue());
    }

    private function runtime(ExposurePolicy $policy): PackageRuntime
    {
        return new PackageRuntime(new AbilityCatalogue(), $policy, new AuditLog());
    }
}

final class MultisiteRequestDouble
{
    public function __construct(private string $body, private string $token)
    {
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_header(string $name): string
    {
        return strtolower($name) === 'x-codi-mcp-federation' ? $this->token : '';
    }
}
