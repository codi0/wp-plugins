<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Archives\ZipArchiveGuard;
use CodiMcpTest\Framework\TestCase;

final class ZipArchiveGuardTest extends TestCase
{
    public function test_archive_guard_enforces_entry_and_uncompressed_byte_limits(): void
    {
        $guard = new ZipArchiveGuard(2, 10);
        $this->assertSame(true, $guard->validateEntries(array(
            array('name' => 'demo/a.php', 'size' => 4),
            array('name' => 'demo/b.css', 'size' => 6),
        )));

        $tooMany = $guard->validateEntries(array(
            array('name' => 'demo/a', 'size' => 1),
            array('name' => 'demo/b', 'size' => 1),
            array('name' => 'demo/c', 'size' => 1),
        ));
        $this->assertTrue(is_wp_error($tooMany));
        $this->assertSame('codi_mcp_archive_too_many_entries', (string) ($tooMany->code ?? ''));

        $tooLarge = $guard->validateEntries(array(
            array('name' => 'demo/a', 'size' => 6),
            array('name' => 'demo/b', 'size' => 5),
        ));
        $this->assertTrue(is_wp_error($tooLarge));
        $this->assertSame('codi_mcp_archive_too_large', (string) ($tooLarge->code ?? ''));
    }

    public function test_archive_guard_rejects_unsafe_entry_paths(): void
    {
        $guard = new ZipArchiveGuard();
        foreach (array('../escape.php', '/absolute.php', 'C:/absolute.php', 'demo/../escape.php') as $path) {
            $result = $guard->validateEntries(array(array('name' => $path, 'size' => 1)));
            $this->assertTrue(is_wp_error($result), $path);
            $this->assertSame('codi_mcp_archive_path_invalid', (string) ($result->code ?? ''), $path);
        }
    }
}
