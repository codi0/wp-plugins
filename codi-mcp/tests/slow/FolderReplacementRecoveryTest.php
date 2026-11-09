<?php

declare(strict_types=1);

namespace CodiMcpTest\Slow;

require_once __DIR__ . '/FolderReplacementFixture.php';

final class FolderReplacementRecoveryTest extends FolderReplacementTestBase
{
    public function discoverTestMethodNames(): array
    {
        return $this->shardMethods('recovery');
    }
}
