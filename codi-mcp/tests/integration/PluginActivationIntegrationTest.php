<?php

declare(strict_types=1);

use CodiMcp\Packages\Plugins\PluginDeployment;

final class PluginActivationIntegrationTest extends WP_UnitTestCase
{
    private string $fixtureDirectory = '';
    private string $fixturePlugin = '';

    public function set_up(): void
    {
        parent::set_up();
        if (!is_multisite()) {
            $this->markTestSkipped('Run the integration suite with WP_MULTISITE=1.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $userId = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($userId);
        grant_super_admin($userId);

        $slug = 'codi-mcp-network-activation-fixture';
        $this->fixtureDirectory = WP_PLUGIN_DIR . '/' . $slug;
        $this->fixturePlugin = $slug . '/' . $slug . '.php';
        wp_mkdir_p($this->fixtureDirectory);
        $this->writeFixture(false);
    }

    public function tear_down(): void
    {
        if ($this->fixturePlugin !== '') {
            deactivate_plugins($this->fixturePlugin, true, true);
            deactivate_plugins($this->fixturePlugin, true, false);
        }
        if ($this->fixtureDirectory !== '') {
            $path = $this->fixtureDirectory . '/' . basename($this->fixturePlugin);
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($this->fixtureDirectory)) {
                rmdir($this->fixtureDirectory);
            }
        }
        parent::tear_down();
    }

    public function test_failed_network_promotion_keeps_existing_site_activation(): void
    {
        $siteActivation = activate_plugin($this->fixturePlugin, '', false, true);
        $this->assertFalse(is_wp_error($siteActivation));
        $this->assertTrue(is_plugin_active($this->fixturePlugin));
        $this->assertFalse(is_plugin_active_for_network($this->fixturePlugin));

        $this->writeFixture(true);
        wp_clean_plugins_cache(true);

        $result = (new PluginDeployment(16 * 1024 * 1024, 256 * 1024))->activate(array(
            'plugin_file' => $this->fixturePlugin,
            'scope' => 'network',
        ));

        $this->assertTrue(is_wp_error($result), 'The missing dependency fixture must make network activation fail.');
        $this->assertTrue(is_plugin_active($this->fixturePlugin), 'A failed network promotion must preserve the prior site activation.');
        $this->assertFalse(is_plugin_active_for_network($this->fixturePlugin));
    }

    private function writeFixture(bool $missingDependency): void
    {
        $requires = $missingDependency ? " * Requires Plugins: codi-mcp-definitely-missing\n" : '';
        $source = "<?php\n/**\n * Plugin Name: Codi MCP Network Activation Fixture\n" . $requires . " */\n";
        file_put_contents($this->fixtureDirectory . '/' . basename($this->fixturePlugin), $source);
    }
}
