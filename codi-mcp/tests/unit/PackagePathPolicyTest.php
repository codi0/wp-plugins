<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Archives\PackagePathPolicy;
use CodiMcpTest\Framework\TestCase;

final class PackagePathPolicyTest extends TestCase
{
    public function test_package_paths_remain_portable_and_platform_metadata_is_identifiable(): void
    {
        foreach (array('plugin/plugin.php', 'plugin/assets/app.css', 'plugin/empty/') as $path) {
            $this->assertTrue(PackagePathPolicy::isSafeArchiveEntry($path), $path);
        }
        foreach (array('../escape.php', '/absolute.php', 'C:/absolute.php', 'plugin/file.php:stream', 'plugin/file.php:Zone.Identifier') as $path) {
            $this->assertFalse(PackagePathPolicy::isSafeArchiveEntry($path), $path);
        }
        $this->assertTrue(PackagePathPolicy::isIgnorablePlatformMetadata('plugin/file.php:Zone.Identifier'));
        $this->assertTrue(PackagePathPolicy::isIgnorablePlatformMetadata('plugin/file.php:Zone.Identifier:$DATA'));
        $this->assertFalse(PackagePathPolicy::isIgnorablePlatformMetadata('plugin/file.php:other-stream'));
    }
}