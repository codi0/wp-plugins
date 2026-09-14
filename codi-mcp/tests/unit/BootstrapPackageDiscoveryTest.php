<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Bootstrap;
use CodiMcpTest\Framework\TestCase;

final class BootstrapPackageDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
    }

    public function test_bootstrap_discovers_all_package_entrypoints_deterministically(): void
    {
        $packageFiles = glob(dirname(__DIR__, 2) . '/src/Packages/*/Package.php') ?: array();
        sort($packageFiles, SORT_STRING);

        $expectedClasses = array_map(
            static fn (string $packageFile): string => 'CodiMcp\\Packages\\' . basename(dirname($packageFile)) . '\\Package',
            $packageFiles
        );

        $plugin = Bootstrap::init();
        $actualClasses = array_map(
            static fn (object $package): string => get_class($package),
            $plugin->packages()
        );

        $this->assertTrue($expectedClasses !== array(), 'At least one bundled package should be discoverable.');
        $this->assertSame($expectedClasses, $actualClasses);
    }
}
