<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcpTest\Framework\TestCase;

final class PresentationV1ReadinessTest extends TestCase
{
    public function test_legacy_design_package_contracts_are_absent(): void
    {
        $root = dirname(__DIR__, 2);
        $legacyPathTokens = array(
            'Design' . '-Old',
            'src/Packages/' . 'Design',
        );
        $legacyContentTokens = array(
            'CodiMcp\\Packages\\' . 'Design\\',
            'Packages/' . 'Design',
            'codi/' . 'design-',
            'CODI_MCP_' . 'DESIGN_',
            'codi_mcp_' . 'design_',
            'codi:' . 'design:v1:',
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            foreach ($legacyPathTokens as $token) {
                $this->assertFalse(str_contains($relative, $token), 'Legacy Presentation predecessor path remains: ' . $relative);
            }

            if (!$entry->isFile() || !preg_match('/\\.(?:php|md)$/i', $entry->getFilename())) {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());
            $this->assertTrue(is_string($contents), 'Unable to read release-readiness file: ' . $relative);
            foreach ($legacyContentTokens as $token) {
                $this->assertFalse(str_contains($contents, $token), 'Legacy Presentation predecessor token remains in ' . $relative);
            }
        }
    }

    public function test_presentation_v1_documentation_and_package_are_present(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertTrue(is_dir($root . '/src/Packages/Presentation'));
        $this->assertFalse(is_dir($root . '/src/Packages/' . 'Design'));
        $readme = (string) file_get_contents($root . '/README.md');
        $architecture = (string) file_get_contents($root . '/ARCHITECTURE.md');
        $this->assertTrue(str_contains($readme, '### Presentation'));
        $this->assertTrue(str_contains($architecture, '## Package: presentation'));
    }
}
