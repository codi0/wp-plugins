<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Admin\NetworkAdminPage;
use CodiMcp\Auth\OAuthProfile;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Core\PackageAvailabilityPolicy;
use CodiMcp\Core\PackageRuntime;
use CodiMcp\Core\Plugin;
use CodiMcp\Core\RuntimePackage;
use CodiMcp\Exposure\ExposurePolicy;
use CodiMcpTest\Framework\TestCase;

final class PackageAvailabilityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
    }

    public function test_default_network_policy_delegates_ordinary_packages_and_disables_multisite(): void
    {
        $policy = new PackageAvailabilityPolicy();

        $this->assertSame(PackageAvailabilityPolicy::DELEGATE, $policy->networkState('presentation'));
        $this->assertTrue($policy->isPackageEnabled('presentation'));
        $this->assertFalse($policy->isNetworkManaged('presentation'));
        $this->assertSame(PackageAvailabilityPolicy::DISABLED, $policy->networkState('multisite'));
        $this->assertFalse($policy->isPackageEnabled('multisite'));
        $this->assertFalse($policy->isNetworkManaged('multisite'));
    }

    public function test_network_policy_supports_enabled_disabled_and_delegate_without_multisite_delegation(): void
    {
        $policy = new PackageAvailabilityPolicy();
        $policy->saveNetworkStates(
            array(
                'content' => PackageAvailabilityPolicy::DISABLED,
                'presentation' => PackageAvailabilityPolicy::ENABLED,
                'database' => PackageAvailabilityPolicy::DELEGATE,
                'multisite' => PackageAvailabilityPolicy::DELEGATE,
            ),
            array('content', 'presentation', 'database', 'multisite')
        );

        $this->assertSame(PackageAvailabilityPolicy::DISABLED, $policy->networkState('content'));
        $this->assertFalse($policy->isPackageEnabled('content'));
        $this->assertFalse($policy->isNetworkManaged('content'));
        $this->assertSame(PackageAvailabilityPolicy::ENABLED, $policy->networkState('presentation'));
        $this->assertTrue($policy->isPackageEnabled('presentation'));
        $this->assertTrue($policy->isNetworkManaged('presentation'));
        $this->assertSame(PackageAvailabilityPolicy::DELEGATE, $policy->networkState('database'));
        $this->assertTrue($policy->isPackageEnabled('database'));
        $this->assertFalse($policy->isNetworkManaged('database'));
        $this->assertSame(PackageAvailabilityPolicy::DISABLED, $policy->networkState('multisite'));
        $this->assertFalse($policy->isPackageEnabled('multisite'));
    }

    public function test_delegated_package_uses_site_decision_and_preserves_that_decision_when_network_policy_changes(): void
    {
        $policy = new PackageAvailabilityPolicy();
        $policy->saveNetworkStates(
            array('presentation' => PackageAvailabilityPolicy::DELEGATE),
            array('presentation')
        );
        $policy->saveSiteDelegates(array(), array('presentation'));
        $this->assertFalse($policy->isPackageEnabled('presentation'));

        $policy->saveNetworkStates(
            array('presentation' => PackageAvailabilityPolicy::ENABLED),
            array('presentation')
        );
        $this->assertTrue($policy->isPackageEnabled('presentation'));

        $policy->saveNetworkStates(
            array('presentation' => PackageAvailabilityPolicy::DELEGATE),
            array('presentation')
        );
        $this->assertFalse($policy->isPackageEnabled('presentation'));

        $policy->saveSiteDelegates(array('presentation'), array('presentation'));
        $this->assertTrue($policy->isPackageEnabled('presentation'));
    }

    public function test_site_and_network_admin_pages_are_top_level(): void
    {
        $policy = new PackageAvailabilityPolicy();
        $packages = array(
            new class implements AbilityPackage {
                public function key(): string { return 'presentation'; }
                public function label(): string { return 'Presentation'; }
                public function abilityNames(): array { return array(); }
                public function registerCategories(): void {}
                public function registerAbilities(): void {}
            },
            new class implements AbilityPackage {
                public function key(): string { return 'multisite'; }
                public function label(): string { return 'Multisite'; }
                public function abilityNames(): array { return array(); }
                public function registerCategories(): void {}
                public function registerAbilities(): void {}
            },
        );

        $this->assertSame(PackageAvailabilityPolicy::DISABLED, $policy->networkState('multisite'));

        $plugin = new Plugin(array());
        $plugin->registerAdminMenu();
        $this->assertArrayHasKey('codi-mcp', (array) $GLOBALS['codi_mcp_test_admin_pages']);
        $this->assertSame('manage_options', (string) $GLOBALS['codi_mcp_test_admin_pages']['codi-mcp']['capability']);

        $page = new NetworkAdminPage(
            $policy,
            $packages,
            new OAuthProfile('codi-network', 'codi-mcp/v1/network', true)
        );
        $page->registerMenu();
        $this->assertArrayHasKey('codi-mcp-network', (array) $GLOBALS['codi_mcp_test_admin_pages']);
        $this->assertSame('manage_network_options', (string) $GLOBALS['codi_mcp_test_admin_pages']['codi-mcp-network']['capability']);
    }

    public function test_network_gateway_is_available_only_on_enabled_main_site(): void
    {
        $policy = new PackageAvailabilityPolicy();
        $this->assertTrue($policy->saveNetworkStates(
            array('multisite' => PackageAvailabilityPolicy::ENABLED),
            array('multisite')
        ));

        $method = (new \ReflectionClass(Plugin::class))->getMethod('networkGatewayEnabled');
        $method->setAccessible(true);
        $this->assertTrue((bool) $method->invoke(new Plugin(array())));

        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 2;
        $this->assertFalse((bool) $method->invoke(new Plugin(array())));

        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
        $this->assertTrue($policy->saveNetworkStates(
            array('multisite' => PackageAvailabilityPolicy::DISABLED),
            array('multisite')
        ));
        $this->assertFalse((bool) $method->invoke(new Plugin(array())));
    }

    public function test_core_suppresses_all_package_lifecycle_registration_when_package_is_disabled(): void
    {
        $blocked = new class implements AbilityPackage, RuntimePackage {
            public bool $runtime = false;
            public bool $categories = false;
            public bool $abilities = false;
            public function key(): string { return 'blocked'; }
            public function label(): string { return 'Blocked'; }
            public function abilityNames(): array { return array(); }
            public function registerCategories(): void { $this->categories = true; }
            public function registerAbilities(): void { $this->abilities = true; }
            public function registerRuntime(PackageRuntime $runtime): void { $this->runtime = true; }
        };
        $active = new class implements AbilityPackage, RuntimePackage {
            public bool $runtime = false;
            public bool $categories = false;
            public bool $abilities = false;
            public function key(): string { return 'active'; }
            public function label(): string { return 'Active'; }
            public function abilityNames(): array { return array(); }
            public function registerCategories(): void { $this->categories = true; }
            public function registerAbilities(): void { $this->abilities = true; }
            public function registerRuntime(PackageRuntime $runtime): void { $this->runtime = true; }
        };

        $policy = new PackageAvailabilityPolicy();
        $policy->saveNetworkStates(
            array(
                'blocked' => PackageAvailabilityPolicy::DISABLED,
                'active' => PackageAvailabilityPolicy::ENABLED,
            ),
            array('blocked', 'active')
        );

        $plugin = new Plugin(array(static fn () => $blocked, static fn () => $active));
        $plugin->register();
        $plugin->registerCategories();
        $plugin->registerAbilities();

        $this->assertFalse($blocked->runtime);
        $this->assertFalse($blocked->categories);
        $this->assertFalse($blocked->abilities);
        $this->assertTrue($active->runtime);
        $this->assertTrue($active->categories);
        $this->assertTrue($active->abilities);
    }

    public function test_policy_saves_report_persistence_failures_without_caching_desired_state(): void
    {
        wp_register_ability('example/tool', array(
            'label' => 'Example',
            'description' => 'Example ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn () => array(),
            'permission_callback' => static fn (): bool => true,
            'meta' => array('public' => true),
        ));
        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_exposure_overrides'] = 1;
        $exposure = new ExposurePolicy(new AbilityCatalogue());
        $this->assertFalse($exposure->saveSelection(array('example/tool')));
        $this->assertFalse((new ExposurePolicy(new AbilityCatalogue()))->isEnabled('example/tool'));

        $GLOBALS['codi_mcp_test_wp_site_option_write_failures']['codi_mcp_package_policy'] = 1;
        $network = new PackageAvailabilityPolicy();
        $this->assertFalse($network->saveNetworkStates(array('content' => PackageAvailabilityPolicy::DISABLED), array('content')));
        $this->assertSame(PackageAvailabilityPolicy::DELEGATE, (new PackageAvailabilityPolicy())->networkState('content'));

        $delegated = new PackageAvailabilityPolicy();
        $this->assertTrue($delegated->saveNetworkStates(array('content' => PackageAvailabilityPolicy::DELEGATE), array('content')));
        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_site_packages'] = 1;
        $this->assertFalse($delegated->saveSiteDelegates(array(), array('content')));
        $this->assertTrue((new PackageAvailabilityPolicy())->isPackageEnabled('content'));
    }

    public function test_exposure_selection_prunes_abilities_that_are_no_longer_registered(): void
    {
        wp_register_ability('example/preserved', array(
            'label' => 'Preserved',
            'description' => 'Ability whose package will become unavailable.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn () => array(),
            'permission_callback' => static fn (): bool => true,
            'meta' => array('public' => true),
        ));
        $policy = new ExposurePolicy(new AbilityCatalogue());
        $policy->saveSelection(array('example/preserved'));

        unset($GLOBALS['codi_mcp_test_registered_abilities']['example/preserved']);
        wp_register_ability('example/current', array(
            'label' => 'Current',
            'description' => 'Still registered.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn () => array(),
            'permission_callback' => static fn (): bool => true,
        ));

        $next = new ExposurePolicy(new AbilityCatalogue());
        $next->saveSelection(array());
        $stored = get_option('codi_mcp_exposure_overrides', array());

        $this->assertFalse(array_key_exists('example/preserved', $stored));
    }
}
