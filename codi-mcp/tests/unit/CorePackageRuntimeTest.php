<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Adapter\CodiServer;
use CodiMcp\Adapter\DirectMcpPresentation;
use CodiMcp\Audit\AuditLog;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\ManagedExposurePackage;
use CodiMcp\Core\PackageAvailabilityPolicy;
use CodiMcp\Core\PackageRuntime;
use CodiMcp\Core\Plugin;
use CodiMcp\Core\RuntimePackage;
use CodiMcp\Exposure\ExposurePolicy;
use CodiMcpTest\Framework\TestCase;

final class CorePackageRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        foreach (array(
            'CODI_MCP_ABILITY_PREFIX' => 'codi',
            'CODI_MCP_SERVER_ID' => 'codi-mcp',
            'CODI_MCP_SERVER_REST_NAMESPACE' => 'mcp',
            'CODI_MCP_SERVER_REST_ROUTE' => 'codi',
            'CODI_MCP_NETWORK_SERVER_ID' => 'codi-mcp-network',
            'CODI_MCP_NETWORK_SERVER_REST_ROUTE' => 'codi-network',
            'CODI_MCP_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1',
            'CODI_MCP_NETWORK_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1/network',
            'CODI_MCP_VERSION' => 'test',
        ) as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }
    }

    public function test_runtime_package_receives_narrow_core_runtime_during_plugin_registration(): void
    {
        $package = new class implements AbilityPackage, RuntimePackage {
            public ?PackageRuntime $runtime = null;

            public function key(): string
            {
                return 'runtime-test';
            }

            public function label(): string
            {
                return 'Runtime test';
            }

            public function abilityNames(): array
            {
                return array();
            }

            public function registerCategories(): void
            {
            }

            public function registerAbilities(): void
            {
            }

            public function registerRuntime(PackageRuntime $runtime): void
            {
                $this->runtime = $runtime;
            }
        };

        $plugin = new Plugin(array(static fn () => $package));
        $plugin->register();

        $this->assertTrue($package->runtime instanceof PackageRuntime);
    }

    public function test_managed_exposure_packages_are_enabled_by_core_and_cannot_be_disabled_by_site_selection(): void
    {
        $package = new class implements AbilityPackage, RuntimePackage, ManagedExposurePackage {
            public ?PackageRuntime $runtime = null;

            public function key(): string { return 'managed-test'; }
            public function label(): string { return 'Managed test'; }
            public function abilityNames(): array { return array('codi/managed-test'); }
            public function managedExposureAbilityNames(): array { return array('codi/managed-test'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/managed-test', array(
                    'label' => 'Managed',
                    'description' => 'Managed infrastructure ability.',
                    'category' => 'site',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('managed-test', true, false, true),
                ));
            }
            public function registerRuntime(PackageRuntime $runtime): void { $this->runtime = $runtime; }
        };

        $plugin = new Plugin(array(static fn () => $package));
        $plugin->register();
        $plugin->registerAbilities();

        $this->assertTrue($package->runtime instanceof PackageRuntime);
        $this->assertTrue($package->runtime->isAbilityExposed('codi/managed-test'));

        $catalogue = new AbilityCatalogue();
        $policy = new ExposurePolicy($catalogue, array('codi/managed-test'));
        wp_register_ability('codi/managed-test', array(
            'label' => 'Managed',
            'description' => 'Managed infrastructure ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn () => array(),
            'permission_callback' => static fn (): bool => true,
            'meta' => AbilityMetadata::owned('managed-test', true, false, true),
        ));
        $policy->saveSelection(array());
        $this->assertTrue($policy->isEnabled('codi/managed-test'));
        $rows = $policy->rows();
        $this->assertTrue((bool) ($rows[0]['managed'] ?? false));
    }

    public function test_network_enabled_package_abilities_are_exposed_without_site_selection(): void
    {
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $availability = new PackageAvailabilityPolicy();
        $availability->saveNetworkStates(
            array('network-test' => PackageAvailabilityPolicy::ENABLED),
            array('network-test')
        );

        $package = new class implements AbilityPackage, RuntimePackage {
            public ?PackageRuntime $runtime = null;
            public function key(): string { return 'network-test'; }
            public function label(): string { return 'Network test'; }
            public function abilityNames(): array { return array('codi/network-managed-test'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/network-managed-test', array(
                    'label' => 'Network managed',
                    'description' => 'Network-managed test ability.',
                    'category' => 'site',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('network-test', true, false, true),
                ));
            }
            public function registerRuntime(PackageRuntime $runtime): void { $this->runtime = $runtime; }
        };

        $plugin = new Plugin(array(static fn () => $package));
        $plugin->register();
        $plugin->registerAbilities();

        $this->assertTrue($package->runtime instanceof PackageRuntime);
        $this->assertTrue($package->runtime->isAbilityExposed('codi/network-managed-test'));
    }

    public function test_delegated_package_abilities_remain_site_controlled(): void
    {
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $availability = new PackageAvailabilityPolicy();
        $availability->saveNetworkStates(
            array('delegated-test' => PackageAvailabilityPolicy::DELEGATE),
            array('delegated-test')
        );

        $package = new class implements AbilityPackage, RuntimePackage {
            public ?PackageRuntime $runtime = null;
            public function key(): string { return 'delegated-test'; }
            public function label(): string { return 'Delegated test'; }
            public function abilityNames(): array { return array('codi/delegated-test'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/delegated-test', array(
                    'label' => 'Delegated',
                    'description' => 'Delegated test ability.',
                    'category' => 'site',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('delegated-test', true, false, true),
                ));
            }
            public function registerRuntime(PackageRuntime $runtime): void { $this->runtime = $runtime; }
        };

        $plugin = new Plugin(array(static fn () => $package));
        $plugin->register();
        $plugin->registerAbilities();

        $this->assertTrue($package->runtime instanceof PackageRuntime);
        $this->assertFalse($package->runtime->isAbilityExposed('codi/delegated-test'));
    }

    public function test_main_site_registers_distinct_site_and_network_catalogues(): void
    {
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
        $availability = new PackageAvailabilityPolicy();
        $availability->saveNetworkStates(
            array(
                'network-test' => PackageAvailabilityPolicy::ENABLED,
                'delegated-test' => PackageAvailabilityPolicy::DELEGATE,
                'multisite' => PackageAvailabilityPolicy::ENABLED,
            ),
            array('network-test', 'delegated-test', 'multisite')
        );

        $networkPackage = new class implements AbilityPackage {
            public function key(): string { return 'network-test'; }
            public function label(): string { return 'Network test'; }
            public function abilityNames(): array { return array('codi/network-managed-test'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/network-managed-test', array(
                    'label' => 'Network managed',
                    'description' => 'Network managed test ability.',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('network-test', true, false, true),
                ));
            }
        };
        $delegatedPackage = new class implements AbilityPackage {
            public function key(): string { return 'delegated-test'; }
            public function label(): string { return 'Delegated test'; }
            public function abilityNames(): array { return array('codi/site-only-test'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/site-only-test', array(
                    'label' => 'Site only',
                    'description' => 'Site-only delegated test ability.',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('delegated-test', true, false, true),
                ));
            }
        };
        $multisitePackage = new class implements AbilityPackage {
            public function key(): string { return 'multisite'; }
            public function label(): string { return 'Multisite'; }
            public function abilityNames(): array { return array('codi/sites'); }
            public function registerCategories(): void {}
            public function registerAbilities(): void {
                wp_register_ability('codi/sites', array(
                    'label' => 'Sites',
                    'description' => 'Gateway sites test ability.',
                    'input_schema' => array('type' => 'object'),
                    'execute_callback' => static fn () => array(),
                    'permission_callback' => static fn (): bool => true,
                    'meta' => AbilityMetadata::owned('multisite', true, false, true),
                ));
            }
        };

        $plugin = new Plugin(array(
            static fn () => $networkPackage,
            static fn () => $delegatedPackage,
            static fn () => $multisitePackage,
        ));
        $plugin->registerAbilities();
        update_option('codi_mcp_exposure_overrides', array('codi/site-only-test' => true), false);

        $adapter = new class {
            public array $servers = array();
            public function get_server(string $id): mixed { return null; }
            public function create_server(...$args): object {
                $this->servers[(string) ($args[0] ?? '')] = $args;
                return (object) array();
            }
        };
        $plugin->registerMcpServer($adapter);

        $this->assertArrayHasKey('codi-mcp', $adapter->servers);
        $this->assertArrayHasKey('codi-mcp-network', $adapter->servers);
        $siteTools = (array) ($adapter->servers['codi-mcp'][9] ?? array());
        $networkTools = (array) ($adapter->servers['codi-mcp-network'][9] ?? array());
        $this->assertTrue(in_array('codi/network-managed-test', $siteTools, true));
        $this->assertTrue(in_array('codi/site-only-test', $siteTools, true));
        $this->assertFalse(in_array('codi/sites', $siteTools, true));
        $this->assertTrue(in_array('codi/network-managed-test', $networkTools, true));
        $this->assertTrue(in_array('codi/sites', $networkTools, true));
        $this->assertFalse(in_array('codi/site-only-test', $networkTools, true));
        $this->assertSame('codi', (string) ($adapter->servers['codi-mcp'][2] ?? ''));
        $this->assertSame('codi-network', (string) ($adapter->servers['codi-mcp-network'][2] ?? ''));
    }

    public function test_package_runtime_uses_the_site_local_exposure_policy(): void
    {
        wp_register_ability('example/remote-tool', array(
            'label' => 'Remote tool',
            'description' => 'Test ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn () => array(),
            'permission_callback' => static fn (): bool => true,
            'meta' => array('public' => true),
        ));

        $catalogue = new AbilityCatalogue();
        $policy = new ExposurePolicy($catalogue);
        $runtime = new PackageRuntime($catalogue, $policy, new AuditLog());

        $this->assertFalse($runtime->isAbilityExposed('example/remote-tool'));
        $policy->saveSelection(array('example/remote-tool'));
        $this->assertTrue($runtime->isAbilityExposed('example/remote-tool'));
        $this->assertFalse($runtime->isAbilityExposed('example/not-registered'));
    }

    public function test_transport_permission_can_extend_site_authorization_but_never_bypass_authentication(): void
    {
        $catalogue = new AbilityCatalogue();
        $policy = new ExposurePolicy($catalogue);
        $server = new CodiServer(new DirectMcpPresentation($catalogue, $policy));

        add_filter(
            'codi_mcp_transport_permission',
            static fn (bool $allowed): bool => true,
            10,
            1
        );

        wp_set_current_user(0);
        $this->assertFalse($server->transportPermission(null));

        $GLOBALS['codi_mcp_test_wp_users'][41] = array(
            'user_login' => 'federated-user',
            'sites' => array(
                2 => array('read' => true),
            ),
        );
        wp_set_current_user(41);

        $this->assertFalse(current_user_can('read'));
        $this->assertTrue($server->transportPermission(null));
    }
}
