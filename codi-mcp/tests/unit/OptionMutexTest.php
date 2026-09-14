<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Storage\OptionMutex;
use CodiMcpTest\Framework\TestCase;

final class OptionMutexTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
    }

    public function test_different_scopes_can_be_nested(): void
    {
        $mutex = new OptionMutex();
        $result = $mutex->synchronized('outer', static function () use ($mutex): string {
            return $mutex->synchronized('inner', static fn (): string => 'ok');
        });

        $this->assertSame('ok', $result);
    }

    public function test_lock_is_released_when_callback_throws(): void
    {
        $mutex = new OptionMutex();
        try {
            $mutex->synchronized('exception-scope', static function (): void {
                throw new \RuntimeException('expected');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('expected', $exception->getMessage());
        }

        $this->assertSame('reacquired', $mutex->synchronized('exception-scope', static fn (): string => 'reacquired'));
    }

    public function test_network_scoped_mutex_uses_network_options(): void
    {
        $scope = 'network-scope';
        $name = 'codi_mcp_mutex_' . substr(hash('sha256', $scope), 0, 32);
        $mutex = new OptionMutex(true);

        $heldInNetworkOptions = $mutex->synchronized($scope, static function () use ($name): bool {
            return is_array(get_site_option($name, null)) && get_option($name, null) === null;
        });

        $this->assertTrue($heldInNetworkOptions);
        $this->assertSame(null, get_site_option($name, null));
        $this->assertSame(null, get_option($name, null));
    }

    public function test_expired_lock_is_reclaimed(): void
    {
        $scope = 'expired-scope';
        $name = 'codi_mcp_mutex_' . substr(hash('sha256', $scope), 0, 32);
        add_option($name, array('token' => 'stale', 'expires_at' => time() - 1), '', false);

        $mutex = new OptionMutex();
        $this->assertSame('reclaimed', $mutex->synchronized($scope, static fn (): string => 'reclaimed'));
        $this->assertSame(null, get_option($name, null));
    }
}
