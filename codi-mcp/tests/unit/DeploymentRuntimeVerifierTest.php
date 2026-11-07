<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Deployment\DeploymentRuntimeVerifier;
use CodiMcpTest\Framework\TestCase;

final class DeploymentRuntimeVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
    }

    public function test_verification_visits_each_unique_site_and_restores_origin(): void
    {
        $visited = array();
        $verifier = new DeploymentRuntimeVerifier(function (int $siteId) use (&$visited) {
            $visited[] = array(
                'requested' => $siteId,
                'current' => get_current_blog_id(),
                'home' => home_url('/'),
            );
            return true;
        });

        $this->assertSame(true, $verifier->verify(array(2, 1, 2)));
        $this->assertSame(array(
            array('requested' => 1, 'current' => 1, 'home' => 'https://example.test/'),
            array('requested' => 2, 'current' => 2, 'home' => 'https://docs.example.test/'),
        ), $visited);
        $this->assertSame(1, get_current_blog_id());
        $this->assertSame(array(), $GLOBALS['codi_mcp_test_wp_blog_stack']);
    }

    public function test_verification_fails_closed_and_restores_origin(): void
    {
        $visited = array();
        $verifier = new DeploymentRuntimeVerifier(function (int $siteId) use (&$visited) {
            $visited[] = $siteId;
            return $siteId === 2 ? new \WP_Error('fixture_unhealthy', 'Fixture runtime check failed.') : true;
        });

        $result = $verifier->verify(array(1, 2));
        $this->assertTrue($result instanceof \WP_Error);
        $this->assertSame('codi_mcp_update_health_failed', $result->get_error_code());
        $this->assertSame(array(1, 2), $visited);
        $this->assertSame(1, get_current_blog_id());
        $this->assertSame(array(), $GLOBALS['codi_mcp_test_wp_blog_stack']);
        $data = (array) $result->get_error_data();
        $this->assertSame(2, (int) ($data['site_id'] ?? 0));
        $this->assertSame('fixture_unhealthy', (string) ($data['cause'] ?? ''));
    }
}