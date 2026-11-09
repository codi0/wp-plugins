<?php

declare(strict_types=1);

namespace CodiMcpTest\Slow;

require_once __DIR__ . '/FolderReplacementFixture.php';

final class FileReplacementTest extends FolderReplacementTestBase
{
    public function discoverTestMethodNames(): array
    {
        return $this->shardMethods('file');
    }
}
